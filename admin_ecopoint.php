<?php
/**
 * VictorianPass Admin — VHEcoPoint Stations & Sessions Dashboard
 * ================================================================
 * Visible ONLY to admin/staff (same auth as admin.php).
 * Contains:
 *   - KPIs: Stations online, active sessions now, pts awarded today, sessions completed today
 *   - Stations grid: online/offline/heartbeat, status, current active session with resident
 *   - Live sessions list (WAITING / ACTIVE / PROCESSING)
 *   - Recent sessions history (searchable, paginated) with waste items, points, events
 *   - Session detail view (modal) with waste breakdown + event audit
 */

$staffInactivityLimit = 2700;
ini_set('session.gc_maxlifetime', (string)$staffInactivityLimit);
session_start();
include 'connect.php';
require_once __DIR__ . '/ecopoint_core.php';

// -------------------------------------------------------------------------
// Admin authentication (mirrors admin.php exactly for compatibility)
// -------------------------------------------------------------------------
$now = time();
$last = intval($_SESSION['staff_last_activity'] ?? 0);
$timeout = intval($_SESSION['staff_session_timeout'] ?? $staffInactivityLimit);
if ($last > 0 && $timeout > 0 && ($now - $last) > $timeout) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header("Location: admin_login.php");
    exit;
}
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ||
           (isset($_SESSION['staff_id']) && isset($_SESSION['admin_id']));
if (!$isAdmin) {
    header("Location: admin_login.php");
    exit;
}
$_SESSION['staff_last_activity'] = $now;
if (!isset($_SESSION['staff_session_timeout'])) $_SESSION['staff_session_timeout'] = $staffInactivityLimit;

// AJAX: session detail (returns full session + waste items + events)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'session_detail' && !empty($_GET['id'])) {
    header('Content-Type: application/json');
    $sid = (int)$_GET['id'];
    $out = ['success' => false];
    $s = $con->query("
        SELECT ws.*, st.station_code, st.station_name, st.location AS station_location,
               u.first_name, u.last_name, u.email, u.house_number, u.ref_code
        FROM   ecopoint_waste_sessions ws
        LEFT JOIN ecopoint_stations st ON st.id = ws.station_id
        LEFT JOIN users u              ON u.id  = ws.user_id
        WHERE  ws.id = $sid
        LIMIT  1
    ")->fetch_assoc();
    if ($s) {
        $items = [];
        $q = $con->query("SELECT * FROM ecopoint_waste_items WHERE session_id = $sid ORDER BY id ASC");
        while ($q && $r = $q->fetch_assoc()) $items[] = $r;
        $events = [];
        $q = $con->query("SELECT * FROM ecopoint_session_events WHERE session_id = $sid ORDER BY id ASC");
        while ($q && $r = $q->fetch_assoc()) {
            $events[] = $r + ['payload_decoded' => json_decode((string)$r['event_payload'], true)];
        }
        $out = ['success' => true, 'session' => $s, 'items' => $items, 'events' => $events];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// KPIs
// -------------------------------------------------------------------------
$today = date('Y-m-d');
$kpi = [
    'stations_total'   => 0,
    'stations_online'  => 0,
    'sessions_active'  => 0,
    'pts_today'        => 0,
    'sessions_today'   => 0,
    'pts_week'         => 0,
    'sessions_week'    => 0,
];
$r = $con->query("SELECT COUNT(*) c FROM ecopoint_stations")->fetch_assoc();
$kpi['stations_total'] = (int)($r['c'] ?? 0);

$threshold = date('Y-m-d H:i:s', time() - 120); // <2 min since heartbeat = ONLINE
$r = $con->query("SELECT COUNT(*) c FROM ecopoint_stations WHERE status = 'ACTIVE' AND last_heartbeat_at >= '$threshold'")->fetch_assoc();
$kpi['stations_online'] = (int)($r['c'] ?? 0);

$inListOpen = implode("','", ECO_SESSION_STATUSES_OPEN);
$r = $con->query("SELECT COUNT(*) c FROM ecopoint_waste_sessions WHERE status IN ('$inListOpen')")->fetch_assoc();
$kpi['sessions_active'] = (int)($r['c'] ?? 0);

$week = eco_week_bounds();
$r = $con->query("
    SELECT
      SUM(CASE WHEN DATE(completed_at) = '$today' THEN points_awarded ELSE 0 END) AS pt_today,
      SUM(CASE WHEN DATE(completed_at) = '$today' THEN 1 ELSE 0 END) AS sess_today,
      SUM(CASE WHEN completed_at BETWEEN '{$week['week_start_mysql']}' AND '{$week['week_end_mysql']}' THEN points_awarded ELSE 0 END) AS pt_week,
      SUM(CASE WHEN status = 'COMPLETED' AND completed_at BETWEEN '{$week['week_start_mysql']}' AND '{$week['week_end_mysql']}' THEN 1 ELSE 0 END) AS sess_week
    FROM ecopoint_waste_sessions
")->fetch_assoc();
$kpi['pts_today']      = (int)($r['pt_today'] ?? 0);
$kpi['sessions_today'] = (int)($r['sess_today'] ?? 0);
$kpi['pts_week']       = (int)($r['pt_week'] ?? 0);
$kpi['sessions_week']  = (int)($r['sess_week'] ?? 0);

// -------------------------------------------------------------------------
// Stations list
// -------------------------------------------------------------------------
$stations = [];
$q = $con->query("
    SELECT s.*,
      (SELECT CONCAT(ws.status,'|',ws.id,'|',u.first_name,' ',u.last_name,'|',u.house_number)
       FROM   ecopoint_waste_sessions ws
       LEFT JOIN users u ON u.id = ws.user_id
       WHERE  ws.station_id = s.id AND ws.status IN ('$inListOpen') ORDER BY ws.id DESC LIMIT 1
      ) AS active_packed
    FROM ecopoint_stations s
    ORDER BY s.station_code ASC
");
while ($q && $row = $q->fetch_assoc()) {
    $hb = $row['last_heartbeat_at'] ?? null;
    $online = ($row['status'] === 'ACTIVE' && $hb !== null && strtotime($hb) >= (time() - 120));
    $active = [
        'has' => false, 'status' => '-', 'id' => null, 'resident' => '-', 'house' => '-'
    ];
    if (!empty($row['active_packed'])) {
        [$asStatus, $asId, $asRes, $asHouse] = array_pad(explode('|', (string)$row['active_packed'], 4), 4, '');
        $active = ['has'=>true, 'status'=>$asStatus, 'id'=>(int)$asId, 'resident'=>$asRes, 'house'=>$asHouse];
    }
    $stations[] = $row + ['online' => $online, 'active' => $active];
}

// -------------------------------------------------------------------------
// Active / recent sessions
// -------------------------------------------------------------------------
$activeSessions = [];
$q = $con->query("
    SELECT ws.*, st.station_code, st.station_name,
           u.first_name, u.last_name, u.house_number, u.email
    FROM   ecopoint_waste_sessions ws
    LEFT JOIN ecopoint_stations st ON st.id = ws.station_id
    LEFT JOIN users u              ON u.id  = ws.user_id
    WHERE  ws.status IN ('$inListOpen')
    ORDER BY ws.id DESC
");
while ($q && $r = $q->fetch_assoc()) $activeSessions[] = $r;

$recent = [];
$qLimit = 50;
$searchQ = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$types  = '';
if ($searchQ !== '') {
    $where = " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.house_number LIKE ? OR st.station_code LIKE ? OR ws.qr_ref_code LIKE ? OR CAST(ws.id AS CHAR) LIKE ?) ";
    $like = '%' . $searchQ . '%';
    $params = [$like,$like,$like,$like,$like,$like];
    $types  = 'ssssss';
}
$stmt = $con->prepare("
    SELECT ws.*, st.station_code, st.station_name,
           u.first_name, u.last_name, u.house_number, u.email
    FROM   ecopoint_waste_sessions ws
    LEFT JOIN ecopoint_stations st ON st.id = ws.station_id
    LEFT JOIN users u              ON u.id  = ws.user_id
    WHERE  1=1 $where
    ORDER BY ws.id DESC
    LIMIT  $qLimit
");
if ($stmt) {
    if ($where !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $recent[] = $r;
    $stmt->close();
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>VictorianPass Admin | VHEcoPoint Dashboard</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --accent: #23412e; --accent-2: #2f573d; --cream: #f4efe6;
    --danger: #c0392b; --warn: #e67e22; --ok: #27ae60; --muted: #6b7280;
    --card: #ffffff; --border: #e5e7eb; --bg: #f7f5f0;
  }
  * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
  body { margin:0; background: var(--bg); color:#1f2937; }
  .topbar { background: var(--accent); color:#fff; padding:14px 24px; display:flex; justify-content:space-between; align-items:center; }
  .topbar h1 { margin:0; font-size:1.2rem; }
  .topbar a { color:#fff; text-decoration:none; padding:6px 10px; border-radius:6px; font-size:0.9rem; background: rgba(255,255,255,.12); }
  .topbar a:hover { background: rgba(255,255,255,.22); }
  main { max-width:1400px; margin:0 auto; padding:24px; }
  .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
  .kpi  { background:var(--card); border-radius:12px; padding:16px 18px; border:1px solid var(--border); box-shadow:0 2px 6px rgba(0,0,0,.04); }
  .kpi .label { font-size:0.8rem; color:var(--muted); font-weight:500; }
  .kpi .value { font-size:1.8rem; font-weight:700; margin-top:6px; color:var(--accent); }
  .kpi .sub   { font-size:0.75rem; color:var(--muted); margin-top:2px; }
  h2.section { margin:28px 0 12px; font-size:1.1rem; color:#111; display:flex; align-items:center; gap:10px; }
  h2.section i { color: var(--accent); }

  table { width:100%; border-collapse: collapse; background:var(--card); border-radius:12px; overflow:hidden; border:1px solid var(--border); box-shadow:0 2px 6px rgba(0,0,0,.04); }
  th, td { text-align:left; padding:12px 14px; border-bottom:1px solid var(--border); font-size:0.9rem; vertical-align:middle; }
  th { background:#faf9f6; font-weight:600; color:#374151; font-size:0.8rem; text-transform:uppercase; letter-spacing:.03em; }
  tr:last-child td { border-bottom: none; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.75rem; font-weight:600; }
  .pill.waiting    { background:#fef3c7; color:#92400e; }
  .pill.active     { background:#dbeafe; color:#1e40af; }
  .pill.processing { background:#cffafe; color:#0e7490; }
  .pill.completed  { background:#dcfce7; color:#166534; }
  .pill.cancelled  { background:#fee2e2; color:#991b1b; }
  .pill.error      { background:#fecaca; color:#7f1d1d; }
  .pill.online     { background:#dcfce7; color:#166534; }
  .pill.offline    { background:#e5e7eb; color:#4b5563; }
  .pill.maintenance{ background:#ffedd5; color:#9a3412; }
  .stations-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-bottom:8px; }
  .station-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px 18px; box-shadow:0 2px 6px rgba(0,0,0,.04); }
  .station-card h3 { margin:0; display:flex; align-items:center; gap:10px; font-size:1rem; }
  .station-card .loc { color:var(--muted); font-size:0.82rem; margin:4px 0 12px; }
  .station-card .meta { display:grid; grid-template-columns: 1fr 1fr; gap:6px 12px; font-size:0.85rem; }
  .station-card .meta b { color:#111; font-weight:600; }
  .station-card .active-block { margin-top:12px; padding:10px 12px; border-radius:8px; background:#f7faf8; border:1px dashed #cfd9d0; }
  .station-card .active-block.none { color:var(--muted); text-align:center; font-size:0.85rem; }

  .search-row { display:flex; gap:10px; align-items:center; margin:6px 0 14px; }
  .search-row input { flex:1; padding:10px 12px; border-radius:8px; border:1px solid var(--border); font-size:0.9rem; }
  .search-row button { padding:10px 16px; background:var(--accent); color:#fff; border:0; border-radius:8px; font-weight:600; cursor:pointer; }
  .search-row button:hover { background:var(--accent-2); }

  /* Session detail modal */
  .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; z-index:1000; padding:20px; }
  .modal-bg.open { display:flex; }
  .modal { background:#fff; border-radius:14px; max-width:820px; width:100%; max-height:90vh; overflow-y:auto; padding:22px; }
  .modal header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
  .modal header h2 { margin:0; font-size:1.1rem; }
  .modal header button { background:transparent; border:0; font-size:1.3rem; cursor:pointer; color:#6b7280; }
  .detail-grid { display:grid; grid-template-columns: 1fr 1fr; gap:10px 22px; font-size:0.9rem; margin:8px 0 18px; }
  .detail-grid b { color:#111; display:block; font-size:0.78rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px; }
  .breakdown { border:1px solid var(--border); border-radius:10px; overflow:hidden; margin:10px 0 18px; }
  .event-log { background:#fafafa; border-radius:10px; padding:10px 14px; max-height:280px; overflow-y:auto; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:0.8rem; }
  .event-log .evt + .evt { margin-top:8px; padding-top:8px; border-top:1px dashed #e5e7eb; }
  .event-log .ts { color:var(--muted); }
  .event-log .src { display:inline-block; padding:1px 6px; border-radius:4px; font-size:0.72rem; margin-right:6px; background:#eef2ff; color:#3730a3; }
  .event-log .src.BACKEND  { background:#ecfccb; color:#4d7c0f; }
  .event-log .src.ADMIN    { background:#ffedd5; color:#9a3412; }
  .btn-link { background:none; border:0; color:var(--accent); cursor:pointer; text-decoration:underline; font-weight:500; padding:0; }
</style>
</head>
<body>
<div class="topbar">
  <h1><i class="fa-solid fa-leaf" style="margin-right:8px;color:#a7f3d0"></i> VictorianPass Admin — VHEcoPoint Dashboard</h1>
  <a href="admin.php"><i class="fa-solid fa-arrow-left"></i> Back to Admin</a>
</div>

<main>
  <!-- KPIs -->
  <div class="kpis">
    <div class="kpi"><div class="label">Stations Online</div>
      <div class="value"><i class="fa-solid fa-tower-broadcast" style="font-size:1rem;margin-right:4px"></i><?php echo (int)$kpi['stations_online']; ?><span style="font-size:1rem;color:var(--muted);font-weight:500"> / <?php echo (int)$kpi['stations_total']; ?></span></div>
      <div class="sub">Online = heartbeat in last 2 minutes</div>
    </div>
    <div class="kpi"><div class="label">Active Sessions Now</div>
      <div class="value"><i class="fa-solid fa-spinner fa-spin" style="font-size:1rem;margin-right:4px"></i><?php echo (int)$kpi['sessions_active']; ?></div>
      <div class="sub">WAITING / ACTIVE / PROCESSING</div>
    </div>
    <div class="kpi"><div class="label">Points Awarded Today</div>
      <div class="value" style="color:#166534"><?php echo (int)$kpi['pts_today']; ?><span style="font-size:1rem;color:var(--muted);font-weight:500"> / 100 cap per resident</span></div>
      <div class="sub">Sessions completed today: <b><?php echo (int)$kpi['sessions_today']; ?></b></div>
    </div>
    <div class="kpi"><div class="label">This Week (Mon → Sun)</div>
      <div class="value" style="color:#9a3412"><?php echo (int)$kpi['pts_week']; ?><span style="font-size:1rem;color:var(--muted);font-weight:500"> pts</span></div>
      <div class="sub">Sessions: <b><?php echo (int)$kpi['sessions_week']; ?></b> · Resets Monday 12:00 AM</div>
    </div>
  </div>

  <!-- Stations -->
  <h2 class="section"><i class="fa-solid fa-satellite-dish"></i> VHEcoPoint Stations (LAN)</h2>
  <div class="stations-grid">
    <?php foreach ($stations as $s): ?>
      <div class="station-card">
        <h3>
          <?php $onlineClass = $s['online'] ? 'online' : (strtolower($s['status']) !== 'active' ? strtolower($s['status']) : 'offline'); ?>
          <span class="pill <?php echo $onlineClass; ?>">
            <i class="fa-solid <?php echo $s['online'] ? 'fa-wifi' : 'fa-plug-circle-xmark'; ?>" style="margin-right:4px"></i>
            <?php echo $s['online'] ? 'ONLINE' : strtoupper($s['status'] === 'ACTIVE' ? 'OFFLINE' : $s['status']); ?>
          </span>
          <span style="margin-left:6px"><?php echo htmlspecialchars($s['station_code']); ?></span>
        </h3>
        <div class="loc"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($s['station_name'] . ($s['location'] ? ' — ' . $s['location'] : '')); ?></div>
        <div class="meta">
          <div><span>Last heartbeat</span><br><b><?php echo $s['last_heartbeat_at'] ? date('M j, g:i A', strtotime($s['last_heartbeat_at'])) : '—'; ?></b></div>
          <div><span>Created</span><br><b><?php echo date('M j, Y', strtotime($s['created_at'])); ?></b></div>
          <div><span>Key last 4</span><br><b><?php echo htmlspecialchars($s['api_key_last4'] ?? '—'); ?></b></div>
          <div><span>Active sess</span><br><b><?php echo $s['active']['has'] ? '<span class="pill '.strtolower($s['active']['status']).'">'.$s['active']['status'].' #'.$s['active']['id'].'</span>' : 'none'; ?></b></div>
        </div>
        <?php if ($s['active']['has']): ?>
          <div class="active-block">
            <div style="font-weight:600;margin-bottom:2px"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($s['active']['resident']); ?></div>
            <div style="font-size:0.82rem;color:var(--muted)"><i class="fa-solid fa-house"></i> <?php echo htmlspecialchars($s['active']['house']); ?> · <button class="btn-link" onclick="openDetail(<?php echo (int)$s['active']['id']; ?>)">View session →</button></div>
          </div>
        <?php else: ?>
          <div class="active-block none">— No active session —</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (count($stations) === 0): ?>
      <div style="padding:40px;text-align:center;color:var(--muted);grid-column:1/-1;background:#fff;border-radius:12px;border:1px dashed var(--border)">
        No stations in DB yet. Run <code>DATABASE/ecopoint_waste_system.sql</code> to seed <code>VH-ECO-001</code>.
      </div>
    <?php endif; ?>
  </div>

  <!-- Active sessions -->
  <h2 class="section"><i class="fa-solid fa-bolt"></i> Live Active Sessions</h2>
  <?php if (count($activeSessions) > 0): ?>
  <table>
    <thead><tr>
      <th>ID</th><th>Station</th><th>Resident</th><th>House</th><th>Status</th>
      <th>Material</th><th>Weight</th><th>Points (calc / award)</th><th>Started</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($activeSessions as $s): ?>
      <tr>
        <td><b>#<?php echo (int)$s['id']; ?></b></td>
        <td><?php echo htmlspecialchars($s['station_code'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars(trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''))); ?></td>
        <td><?php echo htmlspecialchars($s['house_number'] ?? ''); ?></td>
        <td><span class="pill <?php echo strtolower($s['status']); ?>"><?php echo $s['status']; ?></span></td>
        <td><?php echo htmlspecialchars($s['material_type'] ?? '—'); ?></td>
        <td><?php echo number_format((float)($s['weight_kg'] ?? 0), 2); ?> kg</td>
        <td><?php echo (int)($s['points_calculated'] ?? 0); ?> / <?php echo (int)($s['points_awarded'] ?? 0); ?></td>
        <td><?php echo $s['started_at'] ? date('g:i A', strtotime($s['started_at'])) : ($s['created_at'] ? date('g:i A', strtotime($s['created_at'])) : ''); ?></td>
        <td><button class="btn-link" onclick="openDetail(<?php echo (int)$s['id']; ?>)">Details</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <div style="padding:40px;text-align:center;color:var(--muted);background:#fff;border-radius:12px;border:1px dashed var(--border)">No active sessions right now.</div>
  <?php endif; ?>

  <!-- Recent sessions history -->
  <h2 class="section" style="margin-top:30px"><i class="fa-solid fa-clock-rotate-left"></i> Recent Sessions (last 50)</h2>
  <form class="search-row" method="GET" action="">
    <input type="text" name="q" placeholder="Search by resident name, house #, station, QR ref, or session ID…" value="<?php echo htmlspecialchars($searchQ); ?>">
    <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    <?php if ($searchQ !== ''): ?><a class="btn-link" href="admin_ecopoint.php" style="margin-left:6px">Clear</a><?php endif; ?>
  </form>
  <table>
    <thead><tr>
      <th>ID</th><th>Date</th><th>Station</th><th>Resident</th><th>House</th><th>Status</th>
      <th>Material</th><th>Weight</th><th>Pts Awarded</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (count($recent) === 0): ?>
      <tr><td colspan="10" style="padding:40px;text-align:center;color:var(--muted)">No sessions yet.</td></tr>
    <?php else: foreach ($recent as $s): ?>
      <tr>
        <td><b>#<?php echo (int)$s['id']; ?></b></td>
        <td><?php echo date('M j, g:i A', strtotime($s['created_at'])); ?></td>
        <td><?php echo htmlspecialchars($s['station_code'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars(trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''))); ?></td>
        <td><?php echo htmlspecialchars($s['house_number'] ?? ''); ?></td>
        <td><span class="pill <?php echo strtolower($s['status']); ?>"><?php echo $s['status']; ?></span></td>
        <td><?php echo htmlspecialchars($s['material_type'] ?? '—'); ?></td>
        <td><?php echo number_format((float)($s['weight_kg'] ?? 0), 2); ?> kg</td>
        <td><b style="color:#166534"><?php echo (int)($s['points_awarded'] ?? 0); ?></b></td>
        <td><button class="btn-link" onclick="openDetail(<?php echo (int)$s['id']; ?>)">View</button></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>

<!-- Session detail modal -->
<div class="modal-bg" id="detailBg" onclick="if(event.target===this)closeDetail()">
  <div class="modal" role="dialog" aria-modal="true">
    <header>
      <h2><i class="fa-solid fa-receipt" style="color:var(--accent);margin-right:8px"></i> VHEcoPoint Session Detail</h2>
      <button onclick="closeDetail()" aria-label="Close">✕</button>
    </header>
    <div id="detailBody" style="min-height:80px;display:flex;align-items:center;justify-content:center;color:var(--muted)">
      Loading…
    </div>
  </div>
</div>

<script>
function openDetail(id){
  var bg = document.getElementById('detailBg');
  var body = document.getElementById('detailBody');
  body.innerHTML = 'Loading session #' + id + '…';
  bg.classList.add('open');
  fetch('admin_ecopoint.php?ajax=session_detail&id=' + encodeURIComponent(id))
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d || !d.success) { body.innerHTML = 'Session not found'; return; }
      renderDetail(d);
    })
    .catch(function(){ body.innerHTML = 'Failed to load session detail'; });
}
function closeDetail(){ document.getElementById('detailBg').classList.remove('open'); }
function escCloser(e){ if (e.key === 'Escape') closeDetail(); }
document.addEventListener('keydown', escCloser);
function fmtKg(v){ v=parseFloat(v||0); if(isNaN(v))v=0; return v.toFixed(2)+' kg'; }
function renderDetail(d){
  var s = d.session;
  var items = d.items || [];
  var events = d.events || [];
  var resident = (s.first_name||'') + ' ' + (s.last_name||'');
  var house = s.house_number || '';
  var statusClass = String(s.status||'').toLowerCase();
  var totalsByType = {};
  items.forEach(function(it){
    var k = it.waste_type;
    if (!totalsByType[k]) totalsByType[k] = {label: it.material_label || k, weight_kg:0, calc:0, awarded:0};
    totalsByType[k].weight_kg    += parseFloat(it.weight_kg||0);
    totalsByType[k].calc         += parseInt(it.points_calculated||0,10);
    totalsByType[k].awarded      += parseInt(it.points_awarded||0,10);
  });
  // Aggregate totals
  var html = '';
  html += '<div style="margin-bottom:8px">'
        + '<span class="pill ' + statusClass + '">' + (s.status||'') + '</span>'
        + ' <span style="margin-left:8px;font-weight:600">#' + s.id + '</span>'
        + ' at <b>' + (s.station_code || '') + '</b> (' + (s.station_name || '') + ')'
        + (s.station_location ? ' — ' + s.station_location : '')
        + '</div>';

  html += '<div class="detail-grid">';
  html += '<div><b>Resident</b>' + escapeHtml(resident.trim() || '-') + '</div>';
  html += '<div><b>House</b>' + escapeHtml(house || '-') + '</div>';
  html += '<div><b>Email</b>' + escapeHtml(s.email || '-') + '</div>';
  html += '<div><b>QR ref (scan)</b><code>' + escapeHtml(s.qr_ref_code || '') + '</code></div>';
  html += '<div><b>Scanned at</b>' + (s.created_at ? fmtDT(s.created_at) : '-') + '</div>';
  html += '<div><b>Started at</b>' + (s.started_at ? fmtDT(s.started_at) : (s.waiting_at ? fmtDT(s.waiting_at) : '-')) + '</div>';
  html += '<div><b>Finalized at</b>' + ((s.completed_at||s.cancelled_at||s.error_at) ? fmtDT(s.completed_at||s.cancelled_at||s.error_at) : '—') + '</div>';
  html += '<div><b>User ref_code</b>' + escapeHtml(s.ref_code || '') + '</div>';
  html += '<div><b>Total weight</b>' + fmtKg(s.total_weight_kg || s.weight_kg || 0) + '</div>';
  html += '<div><b>Points (calculated / awarded)</b><span style="color:#166534;font-weight:600">' + parseInt(s.points_calculated||0,10) + ' / ' + parseInt(s.points_awarded||0,10) + '</span></div>';
  if (s.error_message) {
    html += '<div style="grid-column:1/-1"><b>Error message</b><div style="color:#991b1b">' + escapeHtml(s.error_message) + '</div></div>';
  }
  html += '</div>';

  // Waste items breakdown
  html += '<h3 style="font-size:0.95rem;margin:10px 0 6px;color:#111"><i class="fa-solid fa-weight-hanging" style="color:var(--accent)"></i> Waste Breakdown</h3>';
  html += '<div class="breakdown"><table style="margin:0;border:0;box-shadow:none;border-radius:0"><thead><tr>'
        + '<th>Category</th><th>Label</th><th>Rate (pts/kg)</th><th>Weight (kg)</th><th>Points calculated</th><th>Points awarded</th>'
        + '</tr></thead><tbody>';
  if (items.length === 0) {
    // Legacy case: no waste_items, use session-level totals
    html += '<tr><td colspan="6" style="padding:16px;text-align:center;color:var(--muted)">'
          + (s.material_type ? 'Session-level data only (' + escapeHtml(s.material_type) + ' · ' + fmtKg(s.weight_kg) + ')' : 'No waste-item rows for this session.')
          + '</td></tr>';
  } else {
    items.forEach(function(it){
      html += '<tr>'
            + '<td><code>'+escapeHtml(it.waste_type||'')+'</code></td>'
            + '<td>'+escapeHtml(it.material_label||'')+'</td>'
            + '<td>'+parseInt(it.rate_pts_per_kg||0,10)+'</td>'
            + '<td>'+fmtKg(it.weight_kg||0)+'</td>'
            + '<td>'+parseInt(it.points_calculated||0,10)+'</td>'
            + '<td><b style="color:#166534">'+parseInt(it.points_awarded||0,10)+'</b></td>'
            + '</tr>';
    });
    // Totals row
    html += '<tr style="background:#f9fafb;font-weight:600">'
          + '<td colspan="3" style="text-align:right">Totals</td>'
          + '<td>' + fmtKg(Object.values(totalsByType).reduce(function(a,b){return a+parseFloat(b.weight_kg||0);},0)) + '</td>'
          + '<td>' + Object.values(totalsByType).reduce(function(a,b){return a+parseInt(b.calc||0);},0) + '</td>'
          + '<td style="color:#166534">' + Object.values(totalsByType).reduce(function(a,b){return a+parseInt(b.awarded||0);},0) + '</td>'
          + '</tr>';
  }
  html += '</tbody></table></div>';

  // Event audit log
  html += '<h3 style="font-size:0.95rem;margin:18px 0 6px;color:#111"><i class="fa-solid fa-list-check" style="color:var(--accent)"></i> Immutable Audit Event Log</h3>';
  html += '<div class="event-log" id="evLog">';
  if (events.length === 0) html += '<div style="color:var(--muted)">No events recorded.</div>';
  events.forEach(function(e){
    html += '<div class="evt"><span class="ts">' + fmtDT(e.created_at) + '</span> '
          + '<span class="src '+escapeHtml(e.source||'HARDWARE')+'">' + escapeHtml(e.source||'HARDWARE') + '</span>'
          + '<b style="margin-right:6px">' + escapeHtml(e.event_type||'') + '</b>';
    if (e.payload_decoded) {
      try { html += '<pre style="margin:4px 0 0;padding:6px 8px;background:#fff;border:1px solid var(--border);border-radius:6px;white-space:pre-wrap">' + escapeHtml(JSON.stringify(e.payload_decoded, null, 2)) + '</pre>'; }
      catch(err) { html += '<span> - ' + escapeHtml('' + (e.event_payload||'')) + '</span>'; }
    }
    html += '</div>';
  });
  html += '</div>';

  document.getElementById('detailBody').innerHTML = html;
}
function fmtDT(s){ if(!s) return ''; var d=new Date(s.replace(' ','T')); if(isNaN(d.getTime())) return String(s); return d.toLocaleString(); }
function escapeHtml(str){
  return (String(str ?? '')).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
// Auto-refresh every 20 seconds to keep KPI/stations/live tables fresh
setTimeout(function(){ location.reload(); }, 20000);
</script>
</body>
</html>
