<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';

session_write_close();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
  echo '<div style="color:red;padding:20px;">Error: Report ID is required.</div>';
  exit;
}

$row = null;
$proofs = [];
if ($con instanceof mysqli) {
  $stmt = $con->prepare("SELECT ir.id, ir.complainant, ir.subject, ir.address, ir.nature, ir.other_concern, ir.user_id, ir.report_date, ir.status, ir.created_at, ir.updated_at, ir.escalated_to_admin, ir.escalated_at, ir.handled_by_guard_id, ir.handled_at, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id WHERE ir.id = ? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
  }
  $stmt->close();
  $stmtP = $con->prepare("SELECT id, file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
  $stmtP->bind_param('i', $id);
  $stmtP->execute();
  $resP = $stmtP->get_result();
  while ($resP && ($p = $resP->fetch_assoc())) { $proofs[] = $p; }
  $stmtP->close();
}

if (!$row) {
  echo '<div style="color:red;padding:20px;">Report not found.</div>';
  exit;
}

function fmt_dt($d){
  if (!$d) return '';
  $ts = strtotime($d);
  if (!$ts) return htmlspecialchars($d);
  return date('m/d/y g:i A', $ts);
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body {
      margin: 0;
      padding: 18px 20px;
      font-family: 'Poppins', Arial, sans-serif;
      color: #111827;
      line-height: 1.5;
      background: transparent;
    }
    * { font-family: 'Poppins', Arial, sans-serif; }
    .details-header { display:flex; align-items:center; gap:10px; padding:10px 0 0 0; }
    .details-header img { width:24px; height:24px; }
    .title { font-weight:700; color:#23412e; font-size:1.15rem; }
    .section-title { font-weight:700; color:#23412e; margin:16px 0 10px; }
    .info-grid { display:flex; flex-direction:column; gap:8px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; }
    .info-row { display:flex; justify-content:space-between; gap:12px; font-size:0.93rem; }
    .info-label { color:#6b7280; font-weight:600; }
    .info-value { color:#111827; }
    .timeline { position:relative; margin:10px 0; padding-left:18px; }
    .timeline-item { position:relative; padding:8px 0 8px 14px; }
    .timeline-item::before { content:''; position:absolute; left:0; top:10px; width:8px; height:8px; border-radius:50%; background:#23412e; }
    .timeline-time { color:#6b7280; font-size:0.85rem; margin-top:2px; }
    .proofs { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .proofs img { max-width:160px; height:auto; border:1px solid #e5e7eb; border-radius:10px; }
    .proofs a { color:#23412e; text-decoration:underline; font-weight:600; }
  </style>
</head>
<body>
  <div class="details-header">
    <img src="images/logo.svg" alt="Logo">
  </div>

  

  <div class="section-title">Report Information</div>
  <div class="info-grid">
    <?php $resident = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); ?>
    <div class="info-row"><span class="info-label">Report ID</span><span class="info-value"><?php echo intval($row['id']); ?></span></div>
    <div class="info-row"><span class="info-label">Resident</span><span class="info-value"><?php echo htmlspecialchars($resident ?: ($row['complainant'] ?? 'Resident')); ?></span></div>
    <?php if (!empty($row['subject'])): ?><div class="info-row"><span class="info-label">Complainee</span><span class="info-value"><?php echo htmlspecialchars($row['subject']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['address'])): ?><div class="info-row"><span class="info-label">Address</span><span class="info-value"><?php echo htmlspecialchars($row['address']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['report_date'])): ?><div class="info-row"><span class="info-label">Report Date</span><span class="info-value"><?php echo htmlspecialchars(date('m/d/y', strtotime($row['report_date']))); ?></span></div><?php endif; ?>
    <?php if (!empty($row['nature'])): ?><div class="info-row"><span class="info-label">Nature</span><span class="info-value"><?php echo htmlspecialchars($row['nature']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['other_concern'])): ?><div class="info-row"><span class="info-label">Details</span><span class="info-value"><?php echo htmlspecialchars($row['other_concern']); ?></span></div><?php endif; ?>
    <?php $cur = strtolower((string)$row['status']); $disp = ($cur === 'new') ? 'Pending' : ucwords(str_replace('_',' ', $row['status'])); ?>
    <div class="info-row"><span class="info-label">Current Status</span><span class="info-value"><?php echo htmlspecialchars($disp); ?></span></div>
    <?php if (!empty($row['escalated_to_admin'])): ?><div class="info-row"><span class="info-label">Escalated to Admin</span><span class="info-value">Yes</span></div><?php endif; ?>
    <?php if (!empty($row['escalated_at'])): ?><div class="info-row"><span class="info-label">Escalated At</span><span class="info-value"><?php echo fmt_dt($row['escalated_at']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['handled_by_guard_id'])): ?><div class="info-row"><span class="info-label">Handled by Guard</span><span class="info-value"><?php echo intval($row['handled_by_guard_id']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['handled_at'])): ?><div class="info-row"><span class="info-label">Handled At</span><span class="info-value"><?php echo fmt_dt($row['handled_at']); ?></span></div><?php endif; ?>
    <?php if (!empty($row['updated_at'])): ?><div class="info-row"><span class="info-label">Last Updated</span><span class="info-value"><?php echo fmt_dt($row['updated_at']); ?></span></div><?php endif; ?>
  </div>

  <?php if (!empty($proofs)): ?>
  <div class="section-title">Submitted Proof</div>
  <div class="proofs">
    <?php foreach ($proofs as $p): ?>
      <?php $fp = $p['file_path']; $pid = intval($p['id']); ?>
      <?php if (preg_match('/\.(png|jpg|jpeg)$/i', $fp)): ?>
        <div style="display:inline-flex; flex-direction:column; gap:8px; align-items:center; margin:8px;">
          <img src="<?php echo htmlspecialchars($fp); ?>" alt="Proof">
          <div style="display:flex; gap:8px;">
            <a href="<?php echo htmlspecialchars($fp); ?>" target="_blank" style="color:#23412e;text-decoration:underline;">View</a>
            <button onclick="replaceProof(<?php echo $id; ?>, <?php echo $pid; ?>)" style="border:1px solid #e5e7eb; background:#f3f4f6; color:#111827; padding:6px 10px; border-radius:8px; cursor:pointer;">Replace</button>
            <button onclick="removeProof(<?php echo $id; ?>, <?php echo $pid; ?>)" style="border:1px solid #e5e7eb; background:#fee2e2; color:#b91c1c; padding:6px 10px; border-radius:8px; cursor:pointer;">Remove</button>
          </div>
        </div>
      <?php else: ?>
        <div style="display:flex; align-items:center; gap:10px; margin:8px;">
          <a href="<?php echo htmlspecialchars($fp); ?>" target="_blank" style="color:#23412e;text-decoration:underline;"><?php echo htmlspecialchars(basename($fp)); ?></a>
          <button onclick="replaceProof(<?php echo $id; ?>, <?php echo $pid; ?>)" style="border:1px solid #e5e7eb; background:#f3f4f6; color:#111827; padding:6px 10px; border-radius:8px; cursor:pointer;">Replace</button>
          <button onclick="removeProof(<?php echo $id; ?>, <?php echo $pid; ?>)" style="border:1px solid #e5e7eb; background:#fee2e2; color:#b91c1c; padding:6px 10px; border-radius:8px; cursor:pointer;">Remove</button>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="section-title">Attach Proof</div>
  <div style="padding:10px;">
    <input type="file" id="addProofInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" multiple style="margin-bottom:8px;">
    <button onclick="addProofs(<?php echo $id; ?>)" style="border:1px solid #23412e; background:#23412e; color:#fff; padding:8px 14px; border-radius:8px; cursor:pointer;">Upload</button>
    <div style="margin-top:8px; font-size:0.85rem; color:#6b7280;">Uploading proof is optional. You can add, replace, or remove files anytime.</div>
  </div>
</body>
</html>
