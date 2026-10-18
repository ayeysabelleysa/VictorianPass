<?php
// --- Temporary timing instrumentation (VP_TIMING_PROFILE=1 / define to enable).
// Logs one line per request to the PHP error log; disabled by default.
$GLOBALS['__vp_t0'] = microtime(true);
$GLOBALS['__vp_marks'] = [];
if (!function_exists('vpMark')) {
  function vpMark(string $label): void {
    if (empty(getenv('VP_TIMING_PROFILE')) && !(defined('VP_TIMING_PROFILE') && VP_TIMING_PROFILE === true)) { return; }
    $GLOBALS['__vp_marks'][] = [$label, microtime(true)];
  }
}
if (!function_exists('vpFlush')) {
  function vpFlush(): void {
    if (empty(getenv('VP_TIMING_PROFILE')) && !(defined('VP_TIMING_PROFILE') && VP_TIMING_PROFILE === true)) { return; }
    $t0 = $GLOBALS['__vp_t0'] ?? microtime(true);
    $parts = ['total=' . round((microtime(true) - $t0) * 1000, 1) . 'ms'];
    $prev = $t0;
    foreach ($GLOBALS['__vp_marks'] as $pair) {
      $parts[] = $pair[0] . '=' . round(($pair[1] - $prev) * 1000, 1) . 'ms';
      $prev = $pair[1];
    }
    @error_log('[TIMING] ' . ($_SERVER['REQUEST_URI'] ?? 'reserve') . ' | ' . implode(' ', $parts));
  }
}
register_shutdown_function('vpFlush');
// ---
ob_start(); // Prevents header issues on redirect

// Read-only session for GET page loads: avoids holding the file lock while
// heavy DB queries run, which prevents 504s when concurrent AJAX requests
// from the previous page still hold the lock.
$isReserveApiRequest = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && in_array($_GET['action'], ['booked_dates', 'booked_times', 'check_points', 'expire_overdue'], true);
$resetReservation = isset($_GET['reset_reservation']) && $_GET['reset_reservation'] === '1';
$isGetPageLoad = ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isReserveApiRequest && !$resetReservation);
// reset_reservation GETs must persist the session unset below, so they get a writable session.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$resetReservation) {
  // Logged-in users carry a signed vp_auth cookie -> skip the session lock
  // entirely for GET page loads AND API calls (prevents 502/504 from session
  // lock contention). Everybody else falls back to a read-only (read_and_close)
  // session for page loads; API calls keep the existing write-close behavior.
  if (isset($_COOKIE['vp_auth'])) {
    define('VP_SESSION_COOKIE_AUTH', true);
  } elseif ($isGetPageLoad) {
    define('VP_SESSION_READONLY', true);
  }
}

require_once __DIR__ . '/session_bootstrap.php';
vpMark('session_start');
if ($isReserveApiRequest) {
  define('VP_JSON_ERROR_RESPONSE', true);
}
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';
require_once __DIR__ . '/reservation_expiry.php';
if ($con instanceof mysqli && !$con->connect_errno) {
  vpAmenityHoldsEnsureSchema($con);
}
vpMark('db_connect');
// Expire unpaid bookings whose payment window has passed: this frees their
// slot and refunds any redeemed points, and hands a resident who just lost one
// over to the Profile page with the one-time "Booking expired" popup.
if ($isGetPageLoad && isset($con) && $con instanceof mysqli && function_exists('vpReservationExpirySweepAndRedirect')) {
  $expiryUserId = null;
  $expiryUserType = '';
  if (function_exists('vpResolveIdentity')) {
    $identityParts = vpResolveIdentity();
    $expiryUserId = $identityParts[0] ?? null;
    $expiryUserType = (string)($identityParts[1] ?? '');
  } else {
    $expiryUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $expiryUserType = (string)($_SESSION['user_type'] ?? '');
  }
  if ($expiryUserType !== 'resident') { $expiryUserId = null; }
  $expiryRedirect = vpReservationExpirySweepAndRedirect($con, $expiryUserId);
  if ($expiryRedirect !== null) {
    header('Location: ' . $expiryRedirect);
    exit;
  }
}
$generatedCode = '';
$errorMsg = '';
$canSubmit = true;
if (defined('VP_SESSION_COOKIE_AUTH') && VP_SESSION_COOKIE_AUTH === true && function_exists('vpCsrfGetToken')) {
  // Cookie-auth mode: no session lock held. Use a stateless CSRF token kept in
  // a signed vp_csrf cookie so the form stays CSRF-protected without a session.
  $sessionCsrfToken = vpCsrfGetToken();
} else {
  if (empty($_SESSION['csrf_token'])) {
    // Session may be read-only — reopen in write mode to persist the CSRF token.
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  $sessionCsrfToken = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
}
$refFromQuery = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
if ($resetReservation) {
  $resetPending = $_SESSION['pending_reservation'] ?? null;
  if (is_array($resetPending) && isset($con) && $con instanceof mysqli) {
    [$resetUserId, $_resetUserType, $resetOwnerKey] = vpResolveIdentity();
    $resetHoldToken = trim((string)($resetPending['hold_token'] ?? ''));
    if ($resetHoldToken !== '') {
      vpReleaseHold($con, $resetHoldToken, $resetOwnerKey);
    }
    vpCancelUnpaidReservationForOwner(
      $con,
      (string)($resetPending['ref_code'] ?? ''),
      $resetUserId !== null ? (int)$resetUserId : null,
      (int)($resetPending['entry_pass_id'] ?? 0)
    );
  }
  unset($_SESSION['pending_reservation'], $_SESSION['dp_ref_code'], $_SESSION['flash_ref_code'], $_SESSION['reservation_submitted']);
  unset($_SESSION['reserve_clear_client_draft']);
  $returnTo = isset($_GET['return_to']) ? basename((string)$_GET['return_to']) : '';
  $allowedReturnTargets = ['dashboardvisitor.php', 'profileresident.php', 'mainpage.php'];
  if (in_array($returnTo, $allowedReturnTargets, true)) {
    header('Location: ' . $returnTo);
    exit;
  }
}

// One-shot flag set by the booking success paths (this page for points and
// downpayment.php for the cash hand-off). When present, the next load of this
// page discards any persisted client-side draft so the stepper starts at
// Step 1 instead of reflecting an already-completed reservation.
$clearClientDraft = !empty($_SESSION['reserve_clear_client_draft']);
if ($clearClientDraft) { unset($_SESSION['reserve_clear_client_draft']); }

// Copy every session value this request may need into local variables BEFORE
// releasing the session lock. Nothing below may read $_SESSION after the lock
// is closed unless the session was explicitly reopened (POST booking flow).
// In cookie-auth mode the CSRF token already came from the signed vp_csrf cookie.
if (!(defined('VP_SESSION_COOKIE_AUTH') && VP_SESSION_COOKIE_AUTH === true)) {
  $sessionCsrfToken = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
}
$sessionUserId    = isset($_SESSION['user_id'])    ? $_SESSION['user_id']    : null;
$sessionUserType  = isset($_SESSION['user_type'])  ? $_SESSION['user_type']  : null;

if ($isReserveApiRequest) {
  session_write_close();
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' && $refFromQuery === '') {
  session_write_close();
}

// Unified reservation page for residents and visitors. Schema changes belong in
// setup/migrate_reserve.php and never run during a user request.

/**
 * Emit a JSON-only API response. Discards any accidentally buffered output
 * (e.g. a PHP notice in dev mode) so the payload is always valid JSON and no
 * warning text can leak into the response.
 */
function reserveJsonResponse(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload);
    exit;
}

/**
 * Log an error server-side and return a safe JSON error to the browser.
 * Never echoes the raw exception message or any credentials.
 */
function reserveJsonError(string $logMessage, string $safeMessage = 'Server error. Please try again later.'): void {
    @error_log($logMessage);
    reserveJsonResponse(['error' => $safeMessage], 500);
}

/**
 * Check whether a given column exists on a table, using a cached result
 * to avoid repeated INFORMATION_SCHEMA queries on the same request.
 */
function reserveTableColumnExists(mysqli $con, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $exists = false;
    try {
        $stmt = $con->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $table, $column);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                $exists = $res && $res->num_rows > 0;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('reserveTableColumnExists error: ' . $e->getMessage());
    }
    $cache[$key] = $exists;
    return $exists;
}

function reserveCalcVHEcoBalance(mysqli $con, int $userId): int {
    if ($userId <= 0) return 0;
    // Modern VHEcoPoint rows carry ecopoint_session_id (index-friendly). Legacy
    // rows are matched via description/transaction_type patterns, only when the
    // session id is NULL so no row is double-counted.
    $legacy = "(description LIKE '%VHEcoPoint%' OR description LIKE '%recycling%' OR description LIKE '%Redeemed points%' OR (transaction_type = 'redeem' AND reservation_ref_code IS NOT NULL AND reservation_ref_code <> ''))";
    $sumExpr = "CASE WHEN transaction_type = 'redeem' THEN -amount ELSE amount END";
    $query = "SELECT COALESCE((SELECT SUM(b) FROM (SELECT $sumExpr AS b FROM point_transactions WHERE user_id = ? AND ecopoint_session_id IS NOT NULL UNION ALL SELECT $sumExpr AS b FROM point_transactions WHERE user_id = ? AND (ecopoint_session_id IS NULL) AND $legacy) t), 0) AS balance";
    $stmt = $con->prepare($query);
    if (!$stmt) {
      error_log('reserveCalcVHEcoBalance primary prepare failed: ' . $con->error);
      $query = "SELECT COALESCE(SUM($sumExpr), 0) AS balance FROM point_transactions WHERE user_id = ? AND $legacy";
      $stmt = $con->prepare($query);
      if (!$stmt) {
        error_log('reserveCalcVHEcoBalance fallback prepare failed: ' . $con->error);
        return 0;
      }
      $stmt->bind_param('i', $userId);
    } else {
      $stmt->bind_param('ii', $userId, $userId);
    }
    if (!$stmt->execute()) {
      error_log('reserveCalcVHEcoBalance execute failed: ' . $stmt->error);
      $stmt->close(); return 0;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return max(0, intval($row['balance'] ?? 0));
}

// Reward catalog used for VHEcoPoint amenity redemptions (source of truth for the
// redeemable-rewards badge; must mirror the $allAmenities array in the modal markup).
function reserveAmenityRewardCatalog(): array {
    return [
        ['name' => 'Basketball Court', 'points' => 300],
        ['name' => 'Tennis Court', 'points' => 300],
        ['name' => 'Clubhouse', 'points' => 600],
        ['name' => 'Multi-Purpose Building', 'points' => 750],
    ];
}

// Number of rewards the resident can currently redeem given their VHEcoPoint balance.
function reserveRedeemableRewardCount(int $balance): int {
    $count = 0;
    foreach (reserveAmenityRewardCatalog() as $reward) {
        if ($balance >= $reward['points']) {
            $count++;
        }
    }
    return $count;
}

// Downpayment moved on-page: do not force redirect; users can pay via GCash from the form

function generateUniqueRefCode($con){
  $tries=0; $code='';
  while($tries<6){
    $candidate='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
    $exists=false;
    if($con instanceof mysqli){
      if ($q1 = $con->prepare("SELECT 1 FROM reservations WHERE ref_code=? LIMIT 1")) {
        $q1->bind_param('s',$candidate); $q1->execute(); $r1=$q1->get_result(); $exists = $exists || ($r1 && $r1->num_rows>0); $q1->close();
      }
      if ($q2 = $con->prepare("SELECT 1 FROM guest_forms WHERE ref_code=? LIMIT 1")) {
        $q2->bind_param('s',$candidate); $q2->execute(); $r2=$q2->get_result(); $exists = $exists || ($r2 && $r2->num_rows>0); $q2->close();
      }
    }
    if(!$exists){ $code=$candidate; break; }
    $tries++;
  }
  if($code===''){ $code='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT); }
  return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Server-side expiry on submit: an unpaid booking already past its payment
  // window dies here (slot freed, points refunded) and the resident is sent to
  // the Profile popup instead of stacking a new booking on top of it.
  if (isset($con) && $con instanceof mysqli && function_exists('vpReservationExpirySweepAndRedirect')) {
    $postExpiryUser = null;
    if (function_exists('vpResolveIdentity')) {
      $postIdentity = vpResolveIdentity();
      if ((string)($postIdentity[1] ?? '') === 'resident') { $postExpiryUser = $postIdentity[0] ?? null; }
    } elseif (isset($sessionUserType) && $sessionUserType === 'resident') {
      $postExpiryUser = isset($sessionUserId) ? $sessionUserId : null;
    }
    $postExpiryRedirect = vpReservationExpirySweepAndRedirect($con, $postExpiryUser);
    if ($postExpiryRedirect !== null) {
      header('Location: ' . $postExpiryRedirect);
      exit;
    }
  }
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  $csrfOk = false;
  if (is_string($tokenPosted) && $tokenPosted !== '') {
    if ($sessionCsrfToken !== '' && hash_equals($sessionCsrfToken, $tokenPosted)) {
      $csrfOk = true;
    } elseif (function_exists('vpCsrfVerify') && vpCsrfVerify($tokenPosted)) {
      $csrfOk = true;
    }
  }
  if (!$csrfOk) {
    $errorMsg = 'Invalid form submission.';
  } else {
    $use_points_post = isset($_POST['use_points']) ? intval($_POST['use_points']) : 0;
    $amenity = isset($_POST['amenity']) ? $_POST['amenity'] : '';
    $start   = isset($_POST['startDate']) ? $_POST['startDate'] : '';
    $end     = isset($_POST['endDate']) ? $_POST['endDate'] : '';
    $startTime = isset($_POST['startTime']) ? $_POST['startTime'] : '';
    $endTime   = isset($_POST['endTime']) ? $_POST['endTime'] : '';
    $persons = intval($_POST['persons'] ?? 1);
    $hours = intval($_POST['hours'] ?? 0);
    $downpayment = isset($_POST['downpayment']) ? floatval($_POST['downpayment']) : null;
    $purpose = isset($_POST['purpose']) ? trim($_POST['purpose']) : null;
    $booking_for_post = isset($_POST['booking_for']) ? trim($_POST['booking_for']) : '';
    $guest_id_post = isset($_POST['guest_id']) ? trim($_POST['guest_id']) : '';
    $guest_ref_code_post = isset($_POST['guest_ref_code']) ? trim($_POST['guest_ref_code']) : '';
    $redemption_confirmed_post = isset($_POST['redemption_confirmed']) && $_POST['redemption_confirmed'] === '1';
    $hold_token_post = isset($_POST['hold_token']) ? trim((string)$_POST['hold_token']) : '';
    $waitlist_token_post = isset($_POST['waitlist_token']) ? trim((string)$_POST['waitlist_token']) : '';
    $residentsCount = max(0, intval($_POST['residents_count'] ?? 0));
    $guestsCount = max(0, intval($_POST['guests_count'] ?? 0));
    if ($residentsCount + $guestsCount <= 0) { $guestsCount = max(1, $persons); }
    $entry_pass_id = (isset($_POST['entry_pass_id']) && $_POST['entry_pass_id'] !== '') ? intval($_POST['entry_pass_id']) : ((isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : NULL);
    $ref_code = isset($_POST['ref_code']) ? $_POST['ref_code'] : (isset($_GET['ref_code']) ? $_GET['ref_code'] : '');
    $guestResidentId = null;
    if ($ref_code !== '' && ($con instanceof mysqli)) {
      try {
        if ($stmtG = $con->prepare("SELECT resident_user_id FROM guest_forms WHERE ref_code = ? LIMIT 1")) {
          $stmtG->bind_param('s', $ref_code);
          $stmtG->execute();
          $resG = $stmtG->get_result();
          if ($resG && ($gRow = $resG->fetch_assoc())) {
            $rid = isset($gRow['resident_user_id']) ? intval($gRow['resident_user_id']) : 0;
            if ($rid > 0) { $guestResidentId = $rid; }
          }
          $stmtG->close();
        }
      } catch (Throwable $e) {
        error_log('reserve.php guest link lookup error: ' . $e->getMessage());
      }
    }
    if ($guestResidentId) { $entry_pass_id = NULL; }
    $user_id = $guestResidentId ?: $sessionUserId;
    $acct = ($guestResidentId || ($sessionUserType === 'resident' && (empty($entry_pass_id)))) ? 'resident' : 'visitor';
    if ($use_points_post && $acct === 'resident' && !$redemption_confirmed_post) {
      $errorMsg = 'Please confirm your VHEcoPoint redemption before submitting the reservation.';
    }
    // All session reads needed for validation are complete. Do not hold the
    // session lock while availability and payment queries run.
    session_write_close();
    if ($guestResidentId) {
      $booking_for = 'guest';
    } else if ($acct === 'resident') {
      $booking_for = ($booking_for_post === 'guest') ? 'guest' : 'resident';
    } else {
      $booking_for = 'guest';
    }
    $isResidentRate = ($acct === 'resident');
    if (in_array($amenity, ['Basketball Court','Tennis Court'], true)) {
      $rate = $isResidentRate ? 100 : 150;
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Clubhouse') {
      $rate = $isResidentRate ? 300 : 450;
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Multi-Purpose Building') {
      $rate = $isResidentRate ? 200 : 300;
      $basePrice = max(1, $hours) * $rate;
    } else {
      $basePrice = 0;
    }
    if ($use_points_post && $acct === 'resident') {
      $paidHours = max(0, $hours - 1);
      $price = round($paidHours * $rate, 2);
      $downpayment = 0;
    } else {
      $price = round($basePrice, 2);
      $downpayment = round($price * 0.5, 2);
    }
    $allowedAmenities = ['Clubhouse','Multi-Purpose Building','Basketball Court','Tennis Court'];
    if (!in_array($amenity, $allowedAmenities, true)) { $errorMsg = 'Please select an amenity.'; }

    // Early points-balance validation: must run BEFORE the session-state write
    // block (if (!$errorMsg)) to prevent an insufficient-balance POST from
    // falling through to a cash redirect or creating a pending_reservation.
    if (!$errorMsg && $use_points_post && $acct === 'resident' && $sessionUserId !== null) {
      $epPointsRequired = 0;
      switch ($amenity) {
        case 'Basketball Court': case 'Tennis Court': $epPointsRequired = 300; break;
        case 'Clubhouse': $epPointsRequired = 600; break;
        case 'Multi-Purpose Building': $epPointsRequired = 750; break;
        default: $errorMsg = 'Invalid amenity for point redemption.';
      }
      if (!$errorMsg) {
        $epBalance = reserveCalcVHEcoBalance($con, intval($sessionUserId));
        if ($epBalance < $epPointsRequired) {
          $errorMsg = 'Insufficient VHEcoPoint Balance: You need ' . $epPointsRequired . ' pts to redeem 1 free hour for this amenity, but your current VHEcoPoint ledger balance is ' . $epBalance . ' pts. Earn more points by recycling eligible materials at the VHEcoPoint Smart Waste Segregation Station.';
          // Force cash mode so downstream logic treats this as a normal cash
          // reservation attempt (form re-renders with the error alert).
          $use_points_post = 0;
          $price = round($basePrice, 2);
          $downpayment = round($price * 0.5, 2);
        }
      }
    }
    $sdObj = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    $edObj = $end ? DateTime::createFromFormat('Y-m-d', $end) : false;
    $stObj = $startTime ? DateTime::createFromFormat('H:i', $startTime) : false;
    $etObj = $endTime ? DateTime::createFromFormat('H:i', $endTime) : false;
    // If the client sent a malformed end time (e.g. a stale/cached page that posts a
    // bare hour number or HH:MM:SS which MySQL would mangle into 00:00:NN), derive the
    // end time from the validated start time + selected hours instead of rejecting.
    if (!$etObj && $stObj && $hours > 0) {
      $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
      $endH = (int)$stObj->format('H') + $hours;
      $endM = (int)$stObj->format('i');
      if ($endH > $maxH) { $endH = $maxH; $endM = 0; }
      $endTime = sprintf('%02d:%02d', $endH, $endM);
      $etObj = DateTime::createFromFormat('H:i', $endTime);
    }
    if (!$sdObj || !$edObj) {
      $errorMsg = 'Please select a start and end date.';
    } else if (!$stObj || !$etObj) {
      $errorMsg = 'Please select a start and end time.';
    } else if ($sdObj && $edObj && $sdObj > $edObj) {
      $errorMsg = 'Start date must be before end date.';
    } else if ($start === $end && $stObj && $etObj && $stObj >= $etObj) {
      $errorMsg = 'Start time must be before end time.';
    } else if (($sdObj && $edObj)) {
      $minDate = new DateTime('today');
      $minDate->modify('+1 day');
      if ($sdObj < $minDate || $edObj < $minDate) {
        $errorMsg = 'Reservations must be made at least 1 day in advance.';
      }
    } else {
      $maxPersons = function_exists('vpAmenityMaxPersons') ? vpAmenityMaxPersons($con, (string)$amenity) : 0;
      if ($persons < 1) {
        $errorMsg = 'Persons must be at least 1.';
      } else if ($maxPersons > 0 && $persons > $maxPersons) {
        $errorMsg = 'Maximum is '.$maxPersons.' persons.';
      }
    }
    if (!$errorMsg && $sdObj && $edObj) {
      $diffDays = $sdObj->diff($edObj)->days;
      if ($diffDays > 6) { $errorMsg = 'Cannot book more than 1 week.'; }
    }
    if (!$errorMsg && $stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
      if ((int)$stObj->format('H') < $minH || (int)$etObj->format('H') > $maxH) {
        $errorMsg = 'Selected time is outside operating hours.';
      }
    }
    // A submitted hold token is authoritative: if it is present it must match the
    // selected slot and still be active, otherwise the resident is returned to
    // the calendar instead of silently booking a slot they no longer hold.
    if (!$errorMsg && $hold_token_post !== '') {
      $holdCheck = vpValidateHoldForSlot($con, $hold_token_post, $amenity, $start, $end, $startTime, $endTime);
      if (empty($holdCheck[0])) {
        $errorMsg = $holdCheck[1] . ' This slot is no longer being held for you.';
      }
    }
    $visitorFlow = ($sessionUserType === null || $sessionUserType !== 'resident');
      if (!$errorMsg) {
        $cnt = 0;
        $singleDay = ($start && $end && $start === $end && $startTime && $endTime);
        vpMark('availability_start');
        try {
          if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
          if ($singleDay) {
            if ($check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations r WHERE r.amenity = ? AND (r.approval_status IS NULL OR r.approval_status IN ('pending','approved','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied')) AND " . vpReservationBlocksSlotSql('r') . " AND ? BETWEEN r.start_date AND r.end_date AND (TIME(?) < r.end_time AND TIME(?) > r.start_time)")) {
              $check1->bind_param("ssss", $amenity, $start, $startTime, $endTime);
              $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
            } else {
              error_log('reserve.php single-day reservations prepare failed: ' . $con->error);
            }
            if ($check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations rr WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code) AND ? BETWEEN rr.start_date AND rr.end_date AND (TIME(?) < rr.end_time AND TIME(?) > rr.start_time)")) {
              $check2->bind_param("ssss", $amenity, $start, $startTime, $endTime);
              $check2->execute(); $r2 = $check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0; $check2->close();
            } else if (reserveTableColumnExists($con, 'resident_reservations', 'start_time') && reserveTableColumnExists($con, 'resident_reservations', 'end_time')) {
              error_log('reserve.php single-day resident_reservations prepare failed: ' . $con->error);
            } else if ($check2b = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations rr WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code) AND ? BETWEEN rr.start_date AND rr.end_date")) {
              $check2b->bind_param("sss", $amenity, $start, $end);
              $check2b->execute(); $r2b = $check2b->get_result(); $cnt += ($r2b && ($rw=$r2b->fetch_assoc())) ? intval($rw['c']) : 0; $check2b->close();
            } else {
              error_log('reserve.php single-day resident_reservations fallback prepare failed: ' . $con->error);
            }
            if ($check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)")) {
              $check3->bind_param("ssss", $amenity, $start, $startTime, $endTime);
              $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
            } else if (reserveTableColumnExists($con, 'guest_forms', 'start_time') && reserveTableColumnExists($con, 'guest_forms', 'end_time')) {
              error_log('reserve.php single-day guest_forms prepare failed: ' . $con->error);
            } else if ($check3b = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND approval_status IN ('pending','approved')")) {
              $check3b->bind_param("sss", $amenity, $start, $end);
              $check3b->execute(); $r3b = $check3b->get_result(); $cnt += ($r3b && ($rw=$r3b->fetch_assoc())) ? intval($rw['c']) : 0; $check3b->close();
            } else {
              error_log('reserve.php single-day guest_forms fallback prepare failed: ' . $con->error);
            }
          } else {
            $hourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse','Multi-Purpose Building'], true);
            $minH = 9;
            $maxH = in_array($amenity, ['Clubhouse','Multi-Purpose Building'], true) ? 21 : 18;
            $totalHours = max(0, $maxH - $minH);

            $startDateObj = DateTime::createFromFormat('Y-m-d', $start);
            $endDateObj = DateTime::createFromFormat('Y-m-d', $end);
            if (!$startDateObj || !$endDateObj) { throw new Exception('Invalid date range'); }
            $period = new DatePeriod($startDateObj, new DateInterval('P1D'), (clone $endDateObj)->modify('+1 day'));

            $cnt = 0; // count of fully booked days in range

            // Bounded availability check: one query per table covers the whole
            // requested range (instead of a per-day query per table), then each
            // booking is attributed to every overlapping day in PHP. This marks
            // the same hours as the previous day-by-day checks.
            $periodDays = [];
            foreach ($period as $d) { $periodDays[] = $d->format('Y-m-d'); }
            $periodBlocks = [];
            foreach ($periodDays as $ds) { $periodBlocks[$ds] = []; }
            $markOverlaps = function($res) use (&$periodBlocks, $periodDays, $hourBased, $minH, $maxH) {
              if (!$res || !($res instanceof mysqli_result)) { return; }
              while ($row = $res->fetch_assoc()) {
                $st = !empty($row['start_time']) ? $row['start_time'] : '00:00:00';
                $et = !empty($row['end_time']) ? $row['end_time'] : '23:59:59';
                if ($hourBased && (empty($row['start_time']) || empty($row['end_time']))) { continue; }
                $bS = intval(substr($st, 0, 2));
                $bE = intval(substr($et, 0, 2));
                if ($bE <= $bS) { continue; }
                foreach ($periodDays as $ds) {
                  if ($ds >= $row['start_date'] && $ds <= $row['end_date']) {
                    for ($h = $bS; $h < $bE; $h++) {
                      if ($h >= $minH && $h < $maxH) { $periodBlocks[$ds][$h] = true; }
                    }
                  }
                }
              }
            };

            // reservations (have start_time/end_time)
            $q1 = $con->prepare("SELECT r.start_date, r.end_date, r.start_time, r.end_time FROM reservations r WHERE r.amenity = ? AND (r.approval_status IS NULL OR r.approval_status IN ('pending','approved','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied')) AND " . vpReservationBlocksSlotSql('r') . " AND r.start_date <= ? AND r.end_date >= ?");
            if ($q1) {
              $q1->bind_param('sss', $amenity, $end, $start);
              $q1->execute();
              $markOverlaps($q1->get_result());
              $q1->close();
            }

            // resident_reservations (may not have time columns)
            $q2 = $con->prepare("SELECT rr.start_date, rr.end_date, rr.start_time, rr.end_time FROM resident_reservations rr WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code) AND rr.start_date <= ? AND rr.end_date >= ?");
            if ($q2) {
              $q2->bind_param('sss', $amenity, $end, $start);
              $q2->execute();
              $markOverlaps($q2->get_result());
              $q2->close();
            } else if (!reserveTableColumnExists($con, 'resident_reservations', 'start_time') || !reserveTableColumnExists($con, 'resident_reservations', 'end_time')) {
              $q2b = $con->prepare("SELECT rr.start_date, rr.end_date FROM resident_reservations rr WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code) AND rr.start_date <= ? AND rr.end_date >= ?");
              if ($q2b) {
                $q2b->bind_param('sss', $amenity, $end, $start);
                $q2b->execute();
                $markOverlaps($q2b->get_result());
                $q2b->close();
              }
            } else {
              error_log('reserve.php multi-day resident_reservations prepare failed: ' . $con->error);
            }

            // guest_forms (may not have time columns)
            $q3 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
            if ($q3) {
              $q3->bind_param('sss', $amenity, $end, $start);
              $q3->execute();
              $markOverlaps($q3->get_result());
              $q3->close();
            } else if (!reserveTableColumnExists($con, 'guest_forms', 'start_time') || !reserveTableColumnExists($con, 'guest_forms', 'end_time')) {
              $q3b = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
              if ($q3b) {
                $q3b->bind_param('sss', $amenity, $end, $start);
                $q3b->execute();
                $markOverlaps($q3b->get_result());
                $q3b->close();
              }
            } else {
              error_log('reserve.php multi-day guest_forms prepare failed: ' . $con->error);
            }

            foreach ($periodDays as $ds) {
              if (count($periodBlocks[$ds]) >= $totalHours) { $cnt++; break; }
            }
          }
        } catch (Throwable $e) {
          $conErr = ($con instanceof mysqli) ? ('errno=' . $con->errno . ' ' . $con->error) : 'no-db';
          error_log('reserve.php availability error (booking continued): ' . $e->getMessage() . ' | con: ' . $conErr . ' | amenity=' . $amenity . ' start=' . $start . ' end=' . $end . ' singleDay=' . ($singleDay ? 'yes' : 'no'));
        }
        vpMark('availability_end');
        if (!$errorMsg && $cnt > 0) {
          $errorMsg = 'Selected dates include a fully booked day. Please adjust your range or choose different dates.';
        }
        if (!$errorMsg) {
          $paidOk = false;
          if ($ref_code !== '') {
            try {
              if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
              if ($s1 = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1")) {
                $s1->bind_param('s', $ref_code);
                $s1->execute();
                $g1 = $s1->get_result();
                if ($g1 && ($rr = $g1->fetch_assoc())) {
                  $ps = strtolower(trim($rr['payment_status'] ?? ''));
                  $paidOk = ($ps === 'verified');
                }
                $s1->close();
              }
            } catch (Throwable $e) {
              error_log('reserve.php payment check error: ' . $e->getMessage());
            }
          }
          $newRef = $ref_code !== '' ? $ref_code : generateUniqueRefCode($con);
          if (!$errorMsg) {
            // Check if using points and validate
            $use_points_post = isset($_POST['use_points']) ? intval($_POST['use_points']) : 0;
            $points_required = 0;
            if ($use_points_post && $acct === 'resident' && $sessionUserId !== null) {
              // Points cover 1 free hour; remaining hours are paid in cash.
              // Calculate points required (always for 1 hour regardless of total duration).
              switch ($amenity) {
                case 'Basketball Court':
                case 'Tennis Court':
                  $points_required = 300;
                  break;
                case 'Clubhouse':
                  $points_required = 600;
                  break;
                case 'Multi-Purpose Building':
                  $points_required = 750;
                  break;
                default:
                  $errorMsg = "Invalid amenity for point redemption.";
              }
              if (!$errorMsg) {
                $current_points = reserveCalcVHEcoBalance($con, intval($sessionUserId));
                if ($current_points < $points_required) {
                  $errorMsg = "Insufficient VHEcoPoint Balance: You need " . $points_required . " pts to redeem 1 free hour for this amenity, but your current VHEcoPoint ledger balance is " . $current_points . " pts. Earn more points by recycling eligible materials at the VHEcoPoint Smart Waste Segregation Station.";
                }
              }
              if (!$errorMsg) {
                $paidOk = true;
              }
            }

            // Authoritative end time = start time + selected hours (server-side).
            // The browser-submitted endTime is never trusted here, so a stale/cached
            // client can no longer store a corrupt time (e.g. bare hour number -> 00:00:NN).
            if ($startTime !== '' && $hours > 0 && preg_match('/^(\d{1,2}):(\d{2})/', $startTime, $m)) {
              $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
              $endH = (int)$m[1] + $hours;
              $endM = (int)$m[2];
              if ($endH > $maxH) { $endH = $maxH; $endM = 0; }
              $endTime = sprintf('%02d:%02d', $endH, $endM);
              $etObj = DateTime::createFromFormat('H:i', $endTime);
            }

            // Reopen only for the short state write after all slow validation work.
            if (session_status() !== PHP_SESSION_ACTIVE) {
              session_start();
            }
            // Store reservation info in session for confirmation/debugging if needed
            $_SESSION['pending_reservation'] = [
              'amenity' => $amenity,
              'start_date' => $start,
              'end_date' => $end,
              'start_time' => $startTime,
              'end_time' => $endTime,
              'hours' => $hours,
              'persons' => $persons,
              'price' => $price,
              'downpayment' => $downpayment,
              'user_id' => $user_id,
              'account_type' => $acct,
              'entry_pass_id' => $entry_pass_id,
              'booking_for' => $booking_for,
              'guest_id' => $guest_id_post,
              'guest_ref_code' => $guest_ref_code_post,
              'ref_code' => $newRef,
              'use_points' => $use_points_post,
              'points_used' => $points_required,
              'hold_token' => $hold_token_post,
              'waitlist_token' => $waitlist_token_post
            ];
            $generatedCode = $newRef;
            $canSubmit = true;
            // Prevent double submission
            if (isset($_SESSION['reservation_submitted']) && $_SESSION['reservation_submitted'] === $newRef) {
              $errorMsg = 'This reservation has already been submitted.';
            } else {
              $_SESSION['reservation_submitted'] = $newRef;
              // If using points, deduct the free hour and proceed
              if ($use_points_post && !$errorMsg) {
                try {
                vpEnsureSlotLockRows($con, $amenity, $start, $end);
                @$con->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $con->begin_transaction();

                // Serialize this slot, then re-check for conflicts that appeared
                // while we waited for the resource lock. Our own hold token and
                // our own ref_code are excluded so they don't block us.
                vpLockSlotRows($con, $amenity, $start, $end);
                $slotConflicts = vpFindConflicts($con, $amenity, $start, $end, $startTime, $endTime, $hold_token_post !== '' ? $hold_token_post : null, $newRef !== '' ? $newRef : null);
                $deductOk = empty($slotConflicts);
                if (!$deductOk) {
                  $errorMsg = 'That time slot is no longer available. Please pick another slot.';
                }

                $userLocked = false;
                if ($deductOk && ($stmtLock = $con->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE"))) {
                  $stmtLock->bind_param('i', $sessionUserId);
                  $stmtLock->execute();
                  $lockedUser = $stmtLock->get_result();
                  $userLocked = $lockedUser && $lockedUser->num_rows > 0;
                  $stmtLock->close();
                } else if ($deductOk) {
                  error_log('reserve.php points user lock prepare failed: ' . $con->error);
                }
                if ($deductOk && !$userLocked) {
                  $errorMsg = 'Unable to verify your account for point redemption. Please try again.';
                  $deductOk = false;
                }
                if ($deductOk) {
                  $lockedBalance = reserveCalcVHEcoBalance($con, intval($sessionUserId));
                  if ($lockedBalance < $points_required) {
                    $errorMsg = 'Insufficient VHEcoPoint Balance: You need ' . $points_required . ' pts to redeem 1 free hour for this amenity, but your current VHEcoPoint ledger balance is ' . $lockedBalance . ' pts. Earn more points by recycling eligible materials at the VHEcoPoint Smart Waste Segregation Station.';
                    $deductOk = false;
                  }
                }

                // Deduct points
                if ($deductOk && ($stmtDeduct = $con->prepare("UPDATE users SET points = points - ? WHERE id = ?"))) {
                  $stmtDeduct->bind_param('ii', $points_required, $sessionUserId);
                  $stmtDeduct->execute();
                  $stmtDeduct->close();
                } elseif ($deductOk) {
                  error_log('reserve.php points deduct prepare failed: ' . $con->error);
                  $deductOk = false;
                }
                
                // Record transaction in point_transactions
                if ($deductOk) {
                  $txnDescription = "Redeemed points for 1 free hour of " . htmlspecialchars($amenity) . " booking (Ref: " . $newRef . ")";
                  if ($stmtTxn = $con->prepare("INSERT INTO point_transactions (user_id, transaction_type, amount, description, reservation_ref_code) VALUES (?, 'redeem', ?, ?, ?)")) {
                    $stmtTxn->bind_param('iiss', $sessionUserId, $points_required, $txnDescription, $newRef);
                    $stmtTxn->execute();
                    $stmtTxn->close();
                  } else {
                    error_log('reserve.php point transaction prepare failed: ' . $con->error);
                    $deductOk = false;
                  }
                }
                
                // Insert reservation using only columns that exist.
                // booking_for/use_points/points_used are added by
                // setup/migrate_reserve.php; their absence on a fresh deployment
                // must not break the booking (values are dropped in that case).
                if ($deductOk) {
                  $resCols  = ['ref_code','amenity','start_date','end_date','start_time','end_time','persons','price','downpayment','user_id','entry_pass_id','account_type','payment_status','approval_status','status'];
                  $resPh    = ['?','?','?','?','?','?','?','?','?','?','?','?',"'pending'","'pending'","'pending'"];
                  $resVals  = [$newRef, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $user_id, $entry_pass_id, $acct];
                  $resTypes = 'ssssssiddiis';
                  if (reserveTableColumnExists($con, 'reservations', 'booking_for')) {
                    $resCols[] = 'booking_for'; $resPh[] = '?';
                    $resVals[] = $booking_for; $resTypes .= 's';
                  }
                  if (reserveTableColumnExists($con, 'reservations', 'use_points')) {
                    $resCols[] = 'use_points'; $resPh[] = '?';
                    $resVals[] = $use_points_post; $resTypes .= 'i';
                  }
                  if (reserveTableColumnExists($con, 'reservations', 'points_used')) {
                    $resCols[] = 'points_used'; $resPh[] = '?';
                    $resVals[] = $points_required; $resTypes .= 'i';
                  }
                  // Payment deadline for this booking. Written at insert time so
                  // the window does not shift when the setting changes later.
                  if (reserveTableColumnExists($con, 'reservations', 'expires_at')) {
                    $resCols[] = 'expires_at';
                    $resPh[] = 'DATE_ADD(NOW(), INTERVAL ' . (int)vpDownpaymentHoldHours($con) . ' HOUR)';
                  }
                  $resSql = "INSERT INTO reservations (" . implode(', ', $resCols) . ") VALUES (" . implode(', ', $resPh) . ")";
                  if ($stmt = $con->prepare($resSql)) {
                    $bindArgs = [$resTypes];
                    foreach ($resVals as $k => $v) { $bindArgs[] = &$resVals[$k]; }
                    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                    $stmt->execute();
                    $stmt->close();
                  } else {
                    error_log('reserve.php reservation insert prepare failed: ' . $con->error);
                    $deductOk = false;
                  }
                }

                if (!$deductOk) {
                  $con->rollback();
                  if (!$errorMsg) {
                    $errorMsg = 'An error occurred while processing your booking. Please try again.';
                  }
                } else {
                  if ($price <= 0) {
                    $stmtV = $con->prepare("UPDATE reservations SET payment_status='verified' WHERE ref_code = ?");
                    if (!$stmtV) {
                      throw new RuntimeException('Could not verify the fully redeemed reservation: ' . $con->error);
                    }
                    $stmtV->bind_param('s', $newRef);
                    if (!$stmtV->execute()) {
                      $error = $stmtV->error;
                      $stmtV->close();
                      throw new RuntimeException('Could not verify the fully redeemed reservation: ' . $error);
                    }
                    $stmtV->close();
                    vpNotifyQueuedUsersForReservationReference($con, $newRef);
                  }
                  $con->commit();

                  // Consume the hold now that the reservation row exists, and
                  // close out any waitlist entry the resident held for this slot.
                  if ($hold_token_post !== '') { vpMarkHoldConverted($con, $hold_token_post, $newRef); }
                  if ($waitlist_token_post !== '') { vpWaitlistMarkStatus($con, $waitlist_token_post, 'converted'); }

                  // Store pending reservation in session for downpayment page
                  $_SESSION['pending_reservation']['use_points'] = $use_points_post;
                  $_SESSION['pending_reservation']['points_used'] = $points_required;

                  // The booking is created — invalidate the client-side draft so
                  // the stepper restarts at Step 1 on the next visit.
                  $_SESSION['reserve_clear_client_draft'] = 1;
                  
                  if ($price > 0) {
                    // Remaining balance exists — redirect to downpayment for the paid hours
                    $redir = 'downpayment.php?continue=' . (($acct === 'resident') ? 'reserve_resident' : 'reserve');
                    if (!empty($entry_pass_id)) { $redir .= '&entry_pass_id=' . urlencode((string)$entry_pass_id); }
                    $redir .= '&ref_code=' . urlencode($newRef);
                    header('Location: ' . $redir);
                    exit;
                  } else {
                    // Fully free (1-hour booking with points) — mark verified and redirect
                    // Show the VHEcoPoint redemption confirmation on the profile page
                    $_SESSION['flash_notice'] = 'Your ' . intval($points_required) . ' VHEcoPoint points have been successfully redeemed for 1 Free Hour. Your reservation has been submitted. Please wait for Admin approval. [redemption]';
                    $_SESSION['flash_ref_code'] = $newRef;
                    header('Location: profileresident.php?section=panel-requests&reservation_success=1&points_used=' . $points_required . '&amenity=' . urlencode((string)$amenity));
                    exit;
                  }
                }
              } catch (Throwable $e) {
                $con->rollback();
                error_log('reserve.php point redemption booking error: ' . $e->getMessage());
                $errorMsg = 'An error occurred while processing your points redemption. Please try again.';
              }
              } else {
                // Confirm: the slot must still be free before handing off to the
                // payment page. A paid booking or another guest's live hold taken
                // while this form sat open blocks the hand-off; our own hold never
                // blocks us. Downpayment re-checks under lock before inserting.
                if (!$errorMsg && function_exists('vpFindConflicts') && ($con instanceof mysqli)) {
                  try {
                    vpEnsureSlotLockRows($con, $amenity, $start, $end);
                    @$con->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    $con->begin_transaction();
                    vpLockSlotRows($con, $amenity, $start, $end);
                    $cashConflicts = vpFindConflicts($con, $amenity, $start, $end, $startTime, $endTime, $hold_token_post !== '' ? $hold_token_post : null, null);
                    $con->rollback();
                    if (!empty($cashConflicts)) {
                      $errorMsg = 'That time slot is no longer available. Please pick another slot.';
                    }
                  } catch (Throwable $e) {
                    try { $con->rollback(); } catch (Throwable $ignore) { }
                    error_log('reserve.php confirm slot check error: ' . $e->getMessage());
                  }
                }
                if (!$errorMsg) {
                // Defer guest code notification until after downpayment submission
                // Redirect to downpayment page with role-aware flow and ref_code
                $redir = 'downpayment.php?continue=' . (($acct === 'resident') ? 'reserve_resident' : 'reserve');
                if (!empty($entry_pass_id)) { $redir .= '&entry_pass_id=' . urlencode((string)$entry_pass_id); }
                if (!empty($newRef)) { $redir .= '&ref_code=' . urlencode($newRef); }
                // Reservation handed off to the payment page — invalidate the
                // client-side draft so the stepper restarts at Step 1 later.
                $_SESSION['reserve_clear_client_draft'] = 1;
                header('Location: ' . $redir);
                exit;
                }
              }
            }
          }
        }
      }
    }
  }
// End of POST handler

// Lightweight endpoint to return booked dates for the selected amenity
if (isset($_GET['action']) && $_GET['action'] === 'booked_dates') {
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $dates = [];
  if ($amenity === '') { reserveJsonResponse(['dates' => []]); }
  $collect = function($res) use (&$dates) {
    if (!$res) { return; }
    while ($row = $res->fetch_assoc()) {
      if (empty($row['start_date']) || empty($row['end_date'])) continue;
      $start = new DateTime($row['start_date']);
      $end = new DateTime($row['end_date']);
      $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
      foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }
    }
  };
  try {
    if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
    if ($stmt1 = $con->prepare("SELECT r.start_date, r.end_date FROM reservations r WHERE r.amenity = ? AND (r.approval_status IS NULL OR r.approval_status IN ('pending','approved','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied')) AND " . vpReservationBlocksSlotSql('r') . " AND (r.end_date IS NULL OR r.end_date >= CURDATE()) AND (r.start_date IS NULL OR r.start_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH))")) {
      $stmt1->bind_param("s", $amenity); $stmt1->execute(); $collect($stmt1->get_result()); $stmt1->close();
    } else {
      error_log('reserve.php booked_dates reservations prepare failed: ' . $con->error);
    }
    if ($stmt2 = $con->prepare("SELECT rr.start_date, rr.end_date FROM resident_reservations rr WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code) AND (rr.end_date IS NULL OR rr.end_date >= CURDATE()) AND (rr.start_date IS NULL OR rr.start_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH))")) {
      $stmt2->bind_param("s", $amenity); $stmt2->execute(); $collect($stmt2->get_result()); $stmt2->close();
    } else {
      error_log('reserve.php booked_dates resident_reservations prepare failed: ' . $con->error);
    }
    if ($stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND (end_date IS NULL OR end_date >= CURDATE()) AND (start_date IS NULL OR start_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH))")) {
      $stmt3->bind_param("s", $amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
    } else {
      error_log('reserve.php booked_dates guest_forms prepare failed: ' . $con->error);
    }
  } catch (Throwable $e) {
    reserveJsonError('reserve.php booked_dates error: ' . $e->getMessage());
  }
  reserveJsonResponse(['dates' => array_values(array_unique($dates))]);
}

// Submission gate: require payment before submission for visitors
if ($refFromQuery !== '') {
try {
  if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
  if ($stmtGate = $con->prepare("SELECT payment_status, amenity, start_date FROM reservations WHERE ref_code = ? LIMIT 1")) {
    $stmtGate->bind_param('s', $refFromQuery);
    $stmtGate->execute();
    $resGate = $stmtGate->get_result();
    if ($resGate && ($rw = $resGate->fetch_assoc())) {
      if (!empty($rw['amenity']) && !empty($rw['start_date'])) {
        $_SESSION['flash_notice'] = 'A reservation already exists for this QR reference code. Please wait for email notification.';
        if ($sessionUserType === 'resident') {
          header('Location: profileresident.php');
        } else {
          header('Location: mainpage.php');
        }
        exit;
      }
      $ps = strtolower(trim($rw['payment_status'] ?? ''));
      $canSubmit = ($ps === 'verified');
    } else {
      $canSubmit = false;
    }
    $stmtGate->close();
  } else {
    error_log('reserve.php gate prepare failed: ' . $con->error);
    $canSubmit = false;
  }
} catch (Throwable $e) {
  error_log('reserve.php gate error: ' . $e->getMessage());
  $canSubmit = false;
}
}
// Enforce gate for visitors (no resident session or entry_pass_id provided)
if (($sessionUserType === null || $sessionUserType !== 'resident') && (!isset($_GET['entry_pass_id']) || $_GET['entry_pass_id'] === '')) {
  if ($refFromQuery === '') { $canSubmit = false; }
}

// Endpoint: booked_times for a specific date and amenity
if (isset($_GET['action']) && $_GET['action'] === 'booked_times') {
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $date = $_GET['date'] ?? '';
  $startDate = $_GET['start_date'] ?? $date;
  $endDate = $_GET['end_date'] ?? $date;
  $times = [];
  $personsTotal = 0;
  $capacity = 0;
  $personsByDate = [];
  $slotBookings = [];
  if ($amenity !== '' && ($date || ($startDate && $endDate))) {
    try {
      if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }

      // Filter: bookings whose date range overlaps [startDate, endDate]
      $dateFilter = " AND start_date <= ? AND end_date >= ?";

      // reserved/u: the point-redemption columns exist only after setup/migrate_reserve.php
      $hasUsePoints = reserveTableColumnExists($con, 'reservations', 'use_points');
      vpMark('booked_times_start');

      // Query 1: reservations table (has start_time/end_time)
      $sql1 = "SELECT r.start_date, r.end_date, r.start_time, r.end_time, r.persons, r.approval_status, r.status" . ($hasUsePoints ? ", r.use_points" : "") . " FROM reservations r WHERE r.amenity = ? AND (r.approval_status IS NULL OR r.approval_status IN ('pending','approved','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied')) AND " . vpReservationBlocksSlotSql('r') . " AND r.start_date <= ? AND r.end_date >= ?";
      $stmt1 = $con->prepare($sql1);
      if ($stmt1) {
        $stmt1->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt1->execute();
        $res1 = $stmt1->get_result();
        while ($row = $res1->fetch_assoc()) {
          $hasTime = !empty($row['start_time']) && !empty($row['end_time']);
          $times[] = [
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'start'      => $hasTime ? $row['start_time'] : null,
            'end'        => $hasTime ? $row['end_time'] : null,
            'has_time'   => $hasTime ? 1 : 0,
            'persons'    => intval($row['persons'] ?? 0),
            'use_points' => $hasUsePoints ? intval($row['use_points'] ?? 0) : 0,
          ];
          if (!empty($row['persons'])) {
            $personsTotal += intval($row['persons']);
          }
        }
        $stmt1->close();
      }
      vpMark('booked_times_reservations');

      // Query 2: resident_reservations joined to reservations.
      // The join already supplies start_time/end_time/persons/use_points for
      // the matched reservations row (ref_code is unique), so no per-row
      // subquery is needed.
      $sql2 = "SELECT rr.start_date, rr.end_date, rr.approval_status, rr.ref_code,
              r.start_time, r.end_time, r.persons" . ($hasUsePoints ? ", r.use_points" : "") . "
           FROM resident_reservations rr
           LEFT JOIN reservations r ON r.ref_code = rr.ref_code
           WHERE rr.amenity = ? AND rr.approval_status IN ('pending','approved') AND r.ref_code IS NULL" . $dateFilter;
      $stmt2 = $con->prepare($sql2);
      if ($stmt2) {
        $stmt2->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
          $hasTime = !empty($row['start_time']) && !empty($row['end_time']);
          $times[] = [
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'start'      => $hasTime ? $row['start_time'] : null,
            'end'        => $hasTime ? $row['end_time'] : null,
            'has_time'   => $hasTime ? 1 : 0,
            'persons'    => intval($row['persons'] ?? 0),
            'use_points' => $hasUsePoints ? intval($row['use_points'] ?? 0) : 0,
          ];
          if (!empty($row['persons'])) {
            $personsTotal += intval($row['persons']);
          }
        }
        $stmt2->close();
      }
      vpMark('booked_times_resident');

      // Query 3: guest_forms table (may not have time columns)
      $sql3 = "SELECT start_date, end_date, persons, approval_status FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')" . $dateFilter;
      $stmt3 = $con->prepare($sql3);
      if ($stmt3) {
        $stmt3->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) {
          $times[] = [
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'start'      => null,
            'end'        => null,
            'has_time'   => 0,
            'persons'    => intval($row['persons'] ?? 0),
            'use_points' => 0,
          ];
          if (!empty($row['persons'])) {
            $personsTotal += intval($row['persons']);
          }
        }
        $stmt3->close();
      }
      vpMark('booked_times_guest');

      // Query 4: active temporary holds. Holds block everyone except the actor
      // who owns them, so a resident browsing while holding a slot still sees
      // their own selection as available.
      [$holdUid, $holdAcct, $holdOwner] = vpResolveIdentity();
      $activeHolds = vpHoldListForSlot($con, $amenity, $startDate, $endDate, $holdOwner, $holdUid);
      foreach ($activeHolds as $h) {
        if (!empty($h['mine'])) { continue; }
        $times[] = [
          'start_date' => $h['start_date'],
          'end_date'   => $h['end_date'],
          'start'      => $h['start'],
          'end'        => $h['end'],
          'has_time'   => $h['has_time'] ? 1 : 0,
          'persons'    => 0,
          'use_points' => 0,
          'hold'       => 1,
          'status'     => 'HELD',
        ];
      }
      vpMark('booked_times_holds');

    } catch (Throwable $e) {
      reserveJsonError('reserve.php booked_times error: ' . $e->getMessage());
    }
  }
  reserveJsonResponse(['times' => $times, 'persons_total' => $personsTotal, 'capacity' => $capacity, 'persons_by_date' => $personsByDate, 'slot_bookings' => $slotBookings]);
}

if (isset($_GET['action']) && $_GET['action'] === 'check_points') {
  if ($sessionUserType !== 'resident' || $sessionUserId === null) {
    reserveJsonResponse(['ok' => false, 'balance' => 0, 'error' => 'Not authenticated as resident.'], 401);
  }
  try {
    $uid = intval($sessionUserId);
    $balance = reserveCalcVHEcoBalance($con, $uid);
    reserveJsonResponse(['ok' => true, 'balance' => $balance, 'redeemable' => reserveRedeemableRewardCount($balance)]);
  } catch (Throwable $e) {
    reserveJsonError('reserve.php check_points error: ' . $e->getMessage());
  }
}

// Hit by the booking countdown when it reaches 0: expires any unpaid booking
// of this resident that is past its payment window and, when one was lost,
// returns the Profile URL so the browser can show the popup.
if (isset($_GET['action']) && $_GET['action'] === 'expire_overdue') {
  if ($sessionUserType !== 'resident' || $sessionUserId === null) {
    reserveJsonResponse(['ok' => false, 'redirect' => null], 401);
  }
  try {
    $uid = intval($sessionUserId);
    $expiryResult = vpExpireDueReservations($con, $uid);
    $expiryRedirect = null;
    if (!empty($expiryResult['mine'])) {
      $expiryRedirect = vpReservationExpiryFlash($expiryResult['mine']);
    }
    reserveJsonResponse([
      'ok'       => true,
      'expired'  => (int)$expiryResult['count'],
      'points'   => (int)$expiryResult['points'],
      'redirect' => $expiryRedirect,
    ]);
  } catch (Throwable $e) {
    reserveJsonError('reserve.php expire_overdue error: ' . $e->getMessage());
  }
}
if ($sessionUserType === 'resident') {
  $accountLink = 'profileresident.php';
} elseif ($sessionUserType === 'visitor') {
  $accountLink = 'dashboardvisitor.php';
} else {
  $accountLink = 'mainpage.php';
}

vpMark('pre_html_queries');
$residentGuests = [];
if ($sessionUserType === 'resident' && $sessionUserId !== null && ($con instanceof mysqli)) {
  $rid = intval($sessionUserId);
  if ($stmtRG = $con->prepare("SELECT id, visitor_first_name, visitor_middle_name, visitor_last_name, visitor_email, visitor_contact, ref_code FROM guest_forms WHERE resident_user_id = ? AND approval_status = 'approved' ORDER BY created_at DESC LIMIT 100")) {
    $stmtRG->bind_param('i', $rid);
    $stmtRG->execute();
    $resRG = $stmtRG->get_result();
    while ($row = $resRG->fetch_assoc()) {
      $residentGuests[] = $row;
    }
    $stmtRG->close();
  } else {
    error_log('reserve.php residentGuests prepare failed: ' . $con->error);
  }
}
vpMark('resident_guests');
$currentResident = null;
$residentPoints = 0;
$redeemableRewards = 0;
$householdResidents = [];
if ($sessionUserType === 'resident' && $sessionUserId !== null && ($con instanceof mysqli)) {
  $rid = intval($sessionUserId);
  
  // Get resident info including points (VHEcoPoint-ONLY ledger — matches profileresident.php)
  $stmtU = $con->prepare("SELECT id, first_name, middle_name, last_name, house_number, points FROM users WHERE id = ? LIMIT 1");
  if ($stmtU) {
    $stmtU->bind_param('i', $rid);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    if ($resU && $resU->num_rows) { 
      $currentResident = $resU->fetch_assoc();
      $residentPoints = reserveCalcVHEcoBalance($con, $rid);
      $redeemableRewards = reserveRedeemableRewardCount($residentPoints);
    }
    $stmtU->close();
  }
  vpMark('resident_info_balance');

  // Get resident's booking history
  $bookingCounts = [
    'Clubhouse' => 0,
    'Multi-Purpose Building' => 0,
    'Basketball Court' => 0,
    'Tennis Court' => 0
  ];

  // Check both reservations and resident_reservations tables
  $stmtHistory1 = $con->prepare("SELECT amenity FROM reservations WHERE user_id = ? AND status NOT IN ('cancelled','deleted','moved_to_history') ORDER BY created_at DESC LIMIT 50");
  if ($stmtHistory1) {
    $stmtHistory1->bind_param('i', $rid);
    $stmtHistory1->execute();
    $resHistory1 = $stmtHistory1->get_result();
    while ($row = $resHistory1->fetch_assoc()) {
      if (isset($bookingCounts[$row['amenity']])) {
        $bookingCounts[$row['amenity']]++;
      }
    }
    $stmtHistory1->close();
  }
  vpMark('booking_history1');

  $stmtHistory2 = $con->prepare("SELECT amenity FROM resident_reservations WHERE user_id = ? AND approval_status IN ('pending','approved') ORDER BY created_at DESC LIMIT 50");
  if ($stmtHistory2) {
    $stmtHistory2->bind_param('i', $rid);
    $stmtHistory2->execute();
    $resHistory2 = $stmtHistory2->get_result();
    while ($row = $resHistory2->fetch_assoc()) {
      if (isset($bookingCounts[$row['amenity']])) {
        $bookingCounts[$row['amenity']]++;
      }
    }
    $stmtHistory2->close();
  }
  vpMark('booking_history2');

  if ($currentResident && !empty($currentResident['house_number'])) {
    $hn = $currentResident['house_number'];
    $stmtH = $con->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE user_type = 'resident' AND status = 'active' AND house_number = ? AND id <> ? ORDER BY created_at DESC");
    if ($stmtH) {
      $stmtH->bind_param('si', $hn, $rid);
      $stmtH->execute();
      $resH = $stmtH->get_result();
      while ($row = $resH->fetch_assoc()) { $householdResidents[] = $row; }
      $stmtH->close();
    }
  }
}
vpMark('household');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
  <link rel="stylesheet" href="css/reserve.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/reserve.css') ?: '', 0, 12); ?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/navbar.css') ?: '', 0, 12); ?>">
  <link rel="stylesheet" href="css/ecopoint-brand.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/ecopoint-brand.css') ?: '', 0, 12); ?>">
  <link rel="stylesheet" href="css/reservation-progress.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/reservation-progress.css') ?: '', 0, 12); ?>">
  <?php if ($clearClientDraft): ?>
  <script>try{sessionStorage.setItem('reserve_completed','1');sessionStorage.removeItem('reserve_form');}catch(e){}</script>
  <?php endif; ?>
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <script>try{sessionStorage.removeItem('reserve_submitted');}catch(e){}</script>
  <?php endif; ?>
</head>
<?php
// Flush the <head> HTML now so the browser can start loading CSS/JS while the
// server generates the body. Keeps the upstream connection active on slow
// Hostinger servers and reduces the chance of nginx 504 timeouts.
if (ob_get_level() > 0) { ob_end_flush(); }
?>
<body>
  <div id="notifyLayer" class="toast"></div>
   
<?php include __DIR__ . '/navbar.php'; ?>

<section class="hero">
  <div class="layout">
    <div class="left-panel">
      <div class="top-actions">
        <button type="button" id="accountBackBtn" class="btn-secondary back-account-btn" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      </div>
      
      <?php $isResident = ($sessionUserType === 'resident'); ?>

      <?php if ($isResident): ?>
        <!-- EcoPoints Redemption Modal -->
        <div id="viewRewardsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px;">
          <div class="view-rewards-content" style="background:#fff; border-radius:20px; max-width:800px; width:100%; max-height:90vh; overflow-y:auto; position:relative;">
            <!-- Modal Header -->
            <div class="view-rewards-header" style="padding:24px 24px 0; display:flex; justify-content:space-between; align-items:center;">
              <h2 style="margin:0; color:#23412e; font-size:1.5rem; font-weight:800;"><i class="fa-solid fa-coins" aria-hidden="true"></i> Redeem EcoPoints</h2>
              <button type="button" id="closeRewardsModal" class="close-profile-modal" aria-label="Close">
                &times;
              </button>
            </div>
            
            <!-- Modal Content -->
            <div class="view-rewards-body" style="padding:24px;">

              <!-- Current Points -->
              <?php
                $ecoRewardBalanceDigits = strlen((string)(int)abs((float)$residentPoints));
                $ecoRewardFontSize = (string)max(1.5, 2.2 - (max(0, $ecoRewardBalanceDigits - 4) * 0.1));
              ?>
              <div class="view-rewards-balance" style="background:linear-gradient(135deg,#0f2f27,#1a5240); border:1px solid rgba(212,175,55,0.4); color:#fde886; padding:18px 20px; border-radius:14px; box-shadow:0 4px 16px rgba(10,30,22,0.15); margin-bottom:24px;">
                <div class="view-rewards-balance-label">Your Current Balance</div>
                <div class="view-rewards-balance-row">
                  <div class="view-rewards-balance-amount">
                    <i class="fa-solid fa-star view-rewards-balance-star" aria-hidden="true"></i>
                    <span class="view-rewards-balance-number" style="font-size:<?php echo $ecoRewardFontSize; ?>rem;"><?php echo number_format($residentPoints); ?> pts</span>
                  </div>
                  <a href="profileresident.php?section=panel-points-history" class="view-rewards-dashboard-link">View VHEcoPoint Dashboard &rarr;</a>
                </div>
              </div>

              <!-- Available Amenities -->
              <div class="view-rewards-amenities" style="margin-bottom:24px;">
                <div style="font-size:1.25rem; font-weight:800; color:#23412e; margin-bottom:16px;">All Available Amenities</div>
                <div class="view-rewards-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px;">
                  <?php 
                    $allAmenities = [
                      ['name' => 'Basketball Court', 'points' => 300, 'img' => 'images/basketballcourt.png'],
                      ['name' => 'Tennis Court', 'points' => 300, 'img' => 'images/tenniscourt.png'],
                      ['name' => 'Clubhouse', 'points' => 600, 'img' => 'images/clubhouse.png'],
                      ['name' => 'Multi-Purpose Building', 'points' => 750, 'img' => 'images/multi purpose building.png']
                    ];
                    foreach ($allAmenities as $amenity): 
                      $isEligible = $residentPoints >= $amenity['points'];
                      $remainingPoints = $residentPoints - $amenity['points'];
                  ?>
                    <div class="amenity-reward-card" style="padding:20px; border-radius:16px; border:2px solid #e5e7eb; background:#ffffff; cursor:pointer; transition:all 0.2s;" data-amenity="<?php echo htmlspecialchars($amenity['name']); ?>" data-points="<?php echo htmlspecialchars($amenity['points']); ?>" data-eligible="<?php echo $isEligible ? 'true' : 'false'; ?>">
                      <div style="display:flex; align-items:flex-start; gap:12px; margin-bottom:12px;">
                        <div style="width:60px; height:60px; border-radius:12px; overflow:hidden; flex-shrink:0;">
                          <img src="<?php echo htmlspecialchars($amenity['img']); ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                        <div style="flex:1;">
                          <div style="font-weight:800; font-size:1rem; color:#111827;"><?php echo htmlspecialchars($amenity['name']); ?></div>
                          <div class="view-rewards-card-meta" style="display:flex; gap:8px; align-items:center; font-size:0.8rem; margin-top:4px; flex-wrap:wrap;">
                            <span style="color:#23412e; font-weight:700;"><?php echo number_format($amenity['points']); ?> pts / hour</span>
                            <?php if ($isEligible): ?>
                              <span class="view-rewards-badge view-rewards-badge-ok" style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:10px; font-weight:700; font-size:0.75rem;"><i class="fa-solid fa-check" aria-hidden="true"></i> Eligible</span>
                            <?php else: ?>
                              <span class="view-rewards-badge view-rewards-badge-need" style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-weight:700; font-size:0.75rem; white-space:nowrap;"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Need <?php echo number_format($amenity['points'] - $residentPoints); ?> more</span>
<?php endif; ?>

<div id="formTimeLimit" class="form-time-limit" style="display:none;position:fixed;left:50%;transform:translateX(-50%);bottom:24px;z-index:10000;justify-content:center;align-items:center;gap:10px;padding:14px 24px;border-radius:999px;background:#ffffff;color:#b91c1c;border:1px solid #fecaca;font-size:15px;font-weight:600;box-shadow:0 10px 30px rgba(15,23,42,.14),0 2px 6px rgba(15,23,42,.08);max-width:min(92vw,580px);text-align:center;" role="timer" aria-live="polite">
  <i class="fa-regular fa-clock" aria-hidden="true"></i>
  <span>Please complete this booking within <span id="formTimeLimitCountdown" style="font-variant-numeric:tabular-nums;font-weight:800;">--:--</span></span>
</div>

                          </div>
                        </div>
                      </div>
                      <div class="view-rewards-card-bottom" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div class="view-rewards-card-balance" style="display:flex; flex-direction:column; min-width:0;">
                          <span style="color:#6b7280; font-size:0.8rem;">Your balance:</span>
                          <span style="font-weight:800; color:#111827; font-size:0.9rem;"><?php echo number_format($residentPoints); ?> pts</span>
                        </div>
                        <button type="button" class="view-rewards-card-btn" <?php echo $isEligible ? '' : 'disabled'; ?> style="padding:8px 16px; border-radius:10px; border:none; font-weight:700; font-size:0.85rem; cursor:pointer; transition:all 0.2s; white-space:nowrap; <?php echo $isEligible ? 'background:linear-gradient(135deg,#23412e,#1f5a33); color:#fff; box-shadow:0 2px 8px rgba(35,65,46,0.2);' : 'background:#e5e7eb; color:#6b7280; cursor:not-allowed;'; ?>">
                          <?php echo $isEligible ? 'Redeem' : 'Not Enough Points'; ?>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Points Earning Suggestions -->
              <?php 
                $showEarnPoints = false;
                foreach ($allAmenities as $amenity) {
                  if ($residentPoints < $amenity['points']) {
                    $showEarnPoints = true;
                    break;
                  }
                }
                if ($showEarnPoints): 
              ?>
                <div class="view-rewards-contact" style="background:#fffbeb; border:1px solid #fcd34d; border-radius:16px; padding:20px;">
                  <div style="font-weight:800; color:#92400e; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                     <i class="fa-solid fa-lightbulb"></i> Earn More Points
                   </div>
                   <p style="margin:0 0 12px 0; color:#78350f; line-height:1.5;">
                     Earn <strong>0.303 EcoPoints per gram</strong> based on the actual material weight.
                   </p>
                   <ul style="margin:0; padding-left:20px; color:#78350f; line-height:1.7;">
                     <li><i class="fa-regular fa-file" style="margin-right:4px;"></i> <strong>Paper:</strong> Old documents, Long Bond Paper, Short Bond/Letter Paper, and A4 Paper.</li>
                     <li><i class="fa-solid fa-recycle" style="margin-right:4px;"></i> <strong>Plastic Bottles:</strong> 1000ml, 500ml, and 240ml PET bottles, plus 80ml Yakult bottles (polystyrene but still accepted).</li>
                     <li><i class="fa-solid fa-droplet" style="margin-right:4px;"></i> <strong>Aluminum Cans:</strong> Small to medium-sized aluminum and metal tin cans.</li>
                     <li><i class="fa-solid fa-chart-simple" style="margin-right:4px;"></i> <strong>Limits:</strong> 100 EcoPoints daily, up to 3 sessions daily, 250 EcoPoints weekly, and 3,000 EcoPoints maximum balance.</li>
                   </ul>
                  <p style="margin:12px 0 0 0; color:#78350f; font-size:0.95rem; line-height:1.5;">
                    Visit VHEcoPoint Smart Waste Segregation Station and recycle eligible materials to earn points that can be redeemed for free amenity reservations.
                  </p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($sessionUserType === 'resident'): ?>
      <?php
        $ecoPointsDigits = strlen((string)(int)abs((float)$residentPoints));
        $ecoPointsFontSize = (string)max(1.4, 2.0 - (max(0, $ecoPointsDigits - 4) * 0.1));
      ?>
      <div class="points-tracker" id="pointsTracker">
        <div class="points-tracker-header">
          <span class="points-tracker-label"><i class="fa-solid fa-coins" aria-hidden="true"></i> Your Points</span>
          <div class="points-balance-amount" id="current-points-display">
            <i class="fa-solid fa-star points-balance-star" aria-hidden="true"></i>
            <span class="points-balance-number" id="current-points-number" style="font-size:<?php echo $ecoPointsFontSize; ?>rem;"><?php echo number_format($residentPoints); ?></span>
            <span class="points-balance-limit">pts</span>
          </div>
        </div>
        <div class="points-tracker-note">Earn EcoPoints by recycling at the VHEcoPoint Smart Waste Segregation Station.</div>
        <div class="points-tracker-body">
          <button class="view-rewards-btn" id="viewRewardsBtn">Redeem EcoPoints<span class="view-rewards-btn-badge" id="viewRewardsBadge"<?php echo ($redeemableRewards > 0) ? '' : ' style="display:none;"'; ?>><?php echo intval($redeemableRewards); ?></span></button>
        </div>
        <button class="points-tracker-toggle" id="trackerToggleBtn" aria-label="Toggle points tracker">−</button>
      </div>
      <?php endif; ?>

      <div class="section-header" id="amenitiesHeader"><h2>Amenities</h2><p>Choose an amenity below to view availability and reserve your preferred schedule.</p></div>

      <div class="booking-steps" aria-label="Booking steps">
        <div class="booking-steps-header">
          <div class="booking-steps-label">Reservation steps</div>
          <div class="booking-steps-toggle-wrap">
            <span class="booking-steps-chip" aria-hidden="true">
              <span class="res-chip-step" data-n="1">Step 1 of 5</span>
              <span class="res-chip-step" data-n="2">Step 2 of 5</span>
              <span class="res-chip-step" data-n="3">Step 3 of 5</span>
              <span class="res-chip-step" data-n="4">Step 4 of 5</span>
              <span class="res-chip-step" data-n="5">Step 5 of 5</span>
            </span>
            <button type="button" class="booking-steps-toggle" id="bookingStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
          </div>
        </div>
        <div class="res-progress-track" aria-hidden="true"><div class="res-progress-fill"></div></div>
        <div class="booking-steps-body">
          <div class="res-progress" id="resProgress" data-current="1">
            <ol class="res-progress-steps">
              <li class="res-progress-step is-active" id="rp-step-amenity" data-step="1">
                <span class="res-progress-dot" aria-hidden="true">
                  <span class="res-progress-num">1</span>
                  <svg class="res-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <span class="res-progress-text">
                  <span class="res-progress-kicker">Step 1</span>
                  <span class="res-progress-title">Select Amenity</span>
                  <span class="res-progress-sub">Choose the facility you want to reserve</span>
                </span>
              </li>
              <li class="res-progress-step" id="rp-step-datetime" data-step="2">
                <span class="res-progress-dot" aria-hidden="true">
                  <span class="res-progress-num">2</span>
                  <svg class="res-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <span class="res-progress-text">
                  <span class="res-progress-kicker">Step 2</span>
                  <span class="res-progress-title">Date &amp; Time</span>
                  <span class="res-progress-sub">Pick your date and time slot</span>
                </span>
              </li>
              <li class="res-progress-step" id="rp-step-participants" data-step="3">
                <span class="res-progress-dot" aria-hidden="true">
                  <span class="res-progress-num">3</span>
                  <svg class="res-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <span class="res-progress-text">
                  <span class="res-progress-kicker">Step 3</span>
                  <span class="res-progress-title">Participants</span>
                  <span class="res-progress-sub">Enter the number of participants</span>
                </span>
              </li>
              <li class="res-progress-step" id="rp-step-payment" data-step="4">
                <span class="res-progress-dot" aria-hidden="true">
                  <span class="res-progress-num">4</span>
                  <svg class="res-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <span class="res-progress-text">
                  <span class="res-progress-kicker">Step 4</span>
                  <span class="res-progress-title">Payment</span>
                  <span class="res-progress-sub">Choose Points or Cash</span>
                </span>
              </li>
              <li class="res-progress-step" id="rp-step-final" data-step="5">
                <span class="res-progress-dot" aria-hidden="true">
                  <span class="res-progress-num">5</span>
                  <svg class="res-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <span class="res-progress-text">
                  <span class="res-progress-kicker">Step 5</span>
                  <span class="res-progress-title res-step5-cash">Downpayment</span>
                  <span class="res-progress-title res-step5-points">Confirm</span>
                  <span class="res-progress-sub res-step5-cash">Pay the 50% GCash downpayment</span>
                  <span class="res-progress-sub res-step5-points">Review and confirm your points redemption</span>
                </span>
              </li>
            </ol>
          </div>
        </div>
      </div>

      <div class="amenities-wrapper">
        <div class="amenities-right">
          <div class="amenities-list" id="amenitiesList">
            <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="450">
              <div class="amenity-media">
                <img src="images/clubhouse.png" alt="Clubhouse">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Clubhouse</div>
                  <div class="amenity-short">A perfect venue for gatherings, celebrations, and community events within the subdivision.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Multi-Purpose Building" data-key="multipurpose" data-price="300">
              <div class="amenity-media">
                <img src="images/multi purpose building.png" alt="Multi-Purpose Building">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Multi-Purpose Building</div>
                  <div class="amenity-short">A versatile space for various community activities, events, and recreational uses.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Basketball Court" data-key="basketball" data-price="150">
              <div class="amenity-media">
                <img src="images/basketballcourt.png" alt="Basketball Court">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Basketball Court</div>
                  <div class="amenity-short">Our outdoor basketball court provides residents a space for recreation, sports, and fitness activities.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Tennis Court" data-key="tennis" data-price="150">
              <div class="amenity-media">
                <img src="images/tenniscourt.png" alt="Tennis Court">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Tennis Court</div>
                  <div class="amenity-short">Our tennis court offers residents a dedicated space for sports, recreation, and friendly matches.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
          </div>
            <div class="booking-shell">
            <?php if (!empty($errorMsg)) { ?><div class="alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php } ?>
            <form method="POST">
          <input type="hidden" name="purpose" value="Amenity Reservation">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($sessionCsrfToken); ?>">
          <input type="hidden" name="entry_pass_id" value="<?php echo (isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : ''; ?>">
          <input type="hidden" name="ref_code" id="refCodeField" value="<?php echo htmlspecialchars($_GET['ref_code'] ?? ''); ?>">
          <input type="hidden" name="booking_for" id="bookingForField" value="<?php echo $isResident ? 'resident' : 'guest'; ?>">
          <input type="hidden" name="guest_id" id="guestIdField" value="">
          <input type="hidden" name="guest_ref_code" id="guestRefField" value="">
          <input type="hidden" name="hold_token" id="holdTokenField" value="">
          <input type="hidden" name="waitlist_token" id="waitlistTokenField" value="">
          <input type="hidden" name="residents_count" id="residentsCountInput" value="<?php echo ($sessionUserType === 'resident') ? '1' : '0'; ?>">
          <input type="hidden" name="guests_count" id="guestsCountInput" value="<?php echo ($sessionUserType === 'resident') ? '0' : '1'; ?>">
          <input type="hidden" id="submitAllowed" value="1">
          <input type="hidden" name="use_points" id="use-points-input" value="0">
          <input type="hidden" name="redemption_confirmed" id="redemption-confirmed-input" value="0">
            <div class="reservation-card" id="reservationCard" style="display:none;">
            <input type="hidden" name="amenity" id="amenityField" value="">
            <div id="holdBanner" class="hold-banner" style="display:none;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;margin:0 0 12px;border-radius:10px;background:#fff7e6;border:1px solid #f2c94c;color:#7a5b00;font-size:13px;" role="status" aria-live="polite">
              <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
              <span id="holdBannerText" style="flex:1;min-width:180px;">We're holding this slot for you.</span>
              <span id="holdCountdown" style="font-weight:700;font-variant-numeric:tabular-nums;"></span>
              <button type="button" id="holdWaitlistBtn" class="btn-link" style="display:none;font-weight:600;" >Join waitlist</button>
            </div>
            <div id="queueOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(17,24,39,.55);align-items:center;justify-content:center;padding:16px;">
              <div role="dialog" aria-modal="true" aria-labelledby="queueTitle" style="width:100%;max-width:440px;background:#fff;border-radius:16px;padding:22px 22px 18px;box-shadow:0 20px 60px rgba(0,0,0,.25);font-family:inherit;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                  <span id="queueStatusIcon" style="width:38px;height:38px;border-radius:50%;background:#fff7e6;color:#b7791f;display:flex;align-items:center;justify-content:center;flex:0 0 38px;"><i class="fa-solid fa-hourglass-half"></i></span>
                  <div style="flex:1;min-width:0;">
                    <div id="queueTitle" style="font-weight:700;font-size:16px;color:#111827;">You're in the Queue!</div>
                    <div id="queueSlot" style="font-size:12.5px;color:#6b7280;margin-top:2px;">--</div>
                  </div>
                  <span id="queueBadge" style="font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;white-space:nowrap;">In Queue</span>
                </div>
                <p id="queueMessage" style="font-size:13.5px;color:#374151;line-height:1.5;margin:10px 0 6px;">--</p>
                <div id="queuePositionWrap" style="font-size:13px;font-weight:600;color:#111827;margin:6px 0;display:none;">
                  <i class="fa-solid fa-list-ol" aria-hidden="true"></i> <span id="queuePosition">You are #1 in line</span>
                </div>
                <div id="queueCountdownWrap" style="display:none;background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;border-radius:10px;padding:9px 12px;font-weight:700;font-size:13px;margin:8px 0;">
                  <i class="fa-regular fa-clock" aria-hidden="true"></i> Claim within <span id="queueCountdown">05:00</span>
                </div>
                <div id="queueAlternativesWrap" style="display:none;margin:8px 0;">
                  <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Suggested free times on this date:</div>
                  <div id="queueAlternatives" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
                </div>
                <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
                  <button type="button" id="queueClaimBtn" class="btn-main small" style="display:none;">Claim this slot</button>
                  <button type="button" id="queueLeaveBtn" style="background:#fff;border:1px solid #e5e7eb;color:#b91c1c;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer;">Cancel waiting</button>
                  <button type="button" id="queueBackBtn" style="background:#fff;border:1px solid #e5e7eb;color:#374151;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer;">Back to calendar</button>
                </div>
              </div>
            </div>
            <div class="reservation-grid">
              <div class="calendar-shell">
                <div class="calendar-title"><i class="fa-regular fa-calendar-days" aria-hidden="true"></i> Select Date</div>
                <div class="calendar" style="width:100%">
                  <div class="calendar-header">
                  <button type="button" class="calendar-nav" id="prevMonth" aria-label="Previous month">&#8249;</button>
                  <h3 id="monthAndYear"></h3>
                  <button type="button" class="calendar-nav" id="nextMonth" aria-label="Next month">&#8250;</button>
                  </div>
                  <div class="calendar-weekdays" aria-hidden="true">
                  <div class="cal-weekday">Su</div><div class="cal-weekday">Mo</div><div class="cal-weekday">Tu</div><div class="cal-weekday">We</div><div class="cal-weekday">Th</div><div class="cal-weekday">Fr</div><div class="cal-weekday">Sa</div>
                  </div>
                  <div class="calendar-grid" id="calendar-body"></div>
                  <div class="calendar-date-row">
                  <div class="res-item date-item" id="startDateGroup">
                    <div class="res-label"><small>Start Date</small></div>
                    <div class="date-line">
                      <p id="startDate">--</p>
                      <button type="button" class="date-clear-icon" id="clearStartDateBtn" aria-label="Clear selected dates" title="Clear selected dates"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <input type="hidden" name="startDate" id="startDateInput">
                    <div id="startDateError" class="time-error" style="display:none;"></div>
                  </div>
                  <div class="res-item date-item" id="endDateGroup">
                    <div class="res-label"><small>End Date</small></div>
                    <div class="date-line">
                      <p id="endDate">--</p>
                      <button type="button" class="date-clear-icon" id="clearEndDateBtn" aria-label="Clear selected dates" title="Clear selected dates"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <input type="hidden" name="endDate" id="endDateInput">
                    <div id="dateError" class="time-error" style="display:none;"></div>
                    <input type="time" name="endTime" id="endTimeInput" min="08:00" max="23:00" style="display:none;">
                    <div id="timeError" class="time-error" style="display:none;"></div>
                  </div>
                  </div>
                  <div class="calendar-single-day">
                    <div class="booking-options res-item" id="singleDayRow">
                      <div class="single-day-group">
                        <label class="single-day"><input type="checkbox" id="singleDayToggle"><span class="single-day-label-text">Single-day reservation<small class="single-day-description">Select a date first, then check this option if your reservation starts and ends on the same date.</small></span></label>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="amenity-preview-shell" id="amenityPreviewShell" style="display:none;">
                <div class="amenity-title"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Amenity Picked</div>
                <div class="amenity-preview" id="amenityPreview">
                  <img src="" alt="" id="amenityPreviewImg" class="amenity-preview-img">
                  <div class="amenity-preview-header">
                    <div class="amenity-preview-title" id="amenityPreviewTitle">Amenity</div>
                  </div>
                  <div class="amenity-preview-meta" id="amenityPreviewDays"></div>
                  <div class="amenity-preview-meta" id="amenityPreviewHours"></div>
                  <div class="amenity-preview-meta" id="amenityPreviewPrice"></div>
                  <div class="amenity-preview-mode" id="amenityPreviewMode" style="display:none;"></div>
                  <button type="button" id="amenityReturnBtn" class="btn-secondary amenity-return" style="display:none;">
                    <img src="images/change.png" alt="" class="amenity-change-icon"> Change Amenity
                  </button>
                </div>
              </div>
              <div class="reservation-left">
                <div class="reservation-top-options">
                  <div class="res-item persons participant-section">
                    <?php if (!$isResident): ?>
                    <div class="res-label"><small class="participants-title"><i class="fa-solid fa-user" aria-hidden="true"></i> Total Participants</small></div>
                    <div class="counter">
                      <button type="button" onclick="changePersons(-1)">-</button>
                      <input type="number" id="personCount" value="0" min="0" step="1" style="width:70px;text-align:center;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;font-weight:600;">
                      <button type="button" onclick="changePersons(1)">+</button>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="persons" id="personsInput" value="0">
                    <?php if ($isResident): ?>
                    <div id="participantWrap" class="participant-card" data-mode="resident_only" style="display:block;">
                      <div class="pers-total">
                        <div class="res-label"><small class="participants-title"><i class="fa-solid fa-user" aria-hidden="true"></i> Number of Participants</small></div>
                        <div class="counter">
                          <button type="button" class="participant-stepper" id="decreaseParticipants" onclick="changeReserveTotal(-1)" aria-label="Decrease number of participants">−</button>
                          <input type="number" class="participant-count-input" id="reserveTotalCount" value="0" min="0" step="1" aria-label="Number of participants">
                          <button type="button" class="participant-stepper" id="increaseParticipants" onclick="changeReserveTotal(1)" aria-label="Increase number of participants">+</button>
                        </div>
                      </div>
                      <div id="personsMaxNote" class="label-help"></div>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              <div class="res-item time-item">
                    <input type="time" name="startTime" id="startTimeInput" min="08:00" max="23:00" style="display:none;">
                    <div class="res-label" id="hoursLabel" style="margin-top:8px; display:none;"><small>Number of Hours</small></div>
                    <div class="counter" id="hoursCounter" style="display:none;">
                      <button type="button" onclick="changeHours(-1)">-</button>
                      <span id="hoursCount">1</span>
                      <button type="button" onclick="changeHours(1)">+</button>
                    </div>
                    <input type="hidden" name="hours" id="hoursInput">
                    <input type="hidden" id="hoursChosen" value="0">
                    <div class="res-label" id="hoursSectionLabel" style="margin-top:8px; display:none;"><small class="hours-title"><i class="fa-regular fa-clock" aria-hidden="true"></i> Number of Hours</small><small class="res-hint">Select the total number of hours for your reservation. Please select a date from the calendar first to continue.</small></div>
                    <select id="hoursSelect" class="hours-select" style="display:none;" disabled></select>
                    <div id="durationContainer" style="display:none;"></div>
                    <div id="availabilityNotice" class="avail-notice" style="display:none;"></div>
                    <div class="slot-legend" id="slotLegend" style="display:none;">
                      <span class="legend-title">Time Slot Availability:</span>
                      <span class="legend-item"><span class="legend-dot legend-dot-taken"></span> Taken &mdash; already booked by another guest</span>
                      <span class="legend-item"><span class="legend-dot legend-dot-closing"></span> Unavailable &mdash; exceeds the amenity closing time</span>
                      <span class="legend-item"><span class="legend-dot legend-dot-available"></span> Available &mdash; open for reservation</span>
                    </div>
                    <div class="res-label" id="timeSectionLabel" style="margin-top:8px; display:none;"><small>Start Time</small><small class="res-hint">Select an available start time below. The end time is calculated automatically, and the full time range is displayed after you select.</small></div>
                    <div id="timeSlotContainer"></div>
                    <div id="timeSlotError" class="time-slot-error" style="display:none;"></div>
                    <div id="selectedTimeRange" class="selected-time-range" style="display:none;"></div>
                    <div id="selectedTimeNote" class="selected-time-note" style="display:none;">Note: This is the scheduled time. Please vacate the amenity by the closing time.</div>
                  </div>

                    <!-- Points Redemption Toggle -->
<?php if ($sessionUserType === 'resident'): ?>
                    <div class="res-item booking-mode-row">
                      <div class="booking-mode-shell">
                        <div class="booking-mode-title">Book with cash or redeem points</div>
                        <div class="booking-mode-grid">
                          <button type="button" class="booking-mode-card is-active" id="bookingModeCash">
                            <span class="booking-mode-label">Cash</span>
                            <span class="booking-mode-value">Pay with Cash</span>
                            <span class="booking-mode-meta">Pay 50% of the reservation fee as an online downpayment. The remaining 50% must be paid in cash at the Admin Office.</span>
                          </button>
                          <button type="button" class="booking-mode-card" id="bookingModePoints">
                            <span class="booking-mode-label">Redeem Points</span>
                            <span class="booking-mode-value" id="bookingModePointsRequired">750 pts = 1 free hour</span>
                            <span class="booking-mode-meta">Free 1-hour booking when you have enough VHEcoPoint points.</span>
                          </button>
                        </div>
                        <input type="checkbox" id="use-points-toggle" style="display:none;">
                        <div class="booking-mode-guide" id="bookingModeGuide">
                          Choose an amenity to compare the cash amount and the point redemption requirement.
                        </div>
                        <div id="redemption-info" class="redemption-info">
                          <p class="redemption-note">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> <strong>1 free hour will be deducted from your selected duration.</strong>
                          </p>
                          <p class="redemption-line">Current balance: <span id="current-points-guide"><?php echo number_format($residentPoints); ?> pts</span></p>
                          <p class="redemption-line">Points required (1 free hour): <span id="required-points"></span></p>
                          <p class="redemption-line">Remaining balance: <span id="remaining-points"></span></p>
                          <p class="redemption-line">Savings: <span id="savings-guide">Select an amenity first</span></p>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>
                    <div class="res-item price-row">
                      <div class="price-box">
                        <div class="price-label">Total Price</div>
                        <div id="price" class="price-amount">₱0.00</div>
                        <div id="priceBreakdown" class="price-breakdown" style="display:none;"></div>
                      </div>
                    </div>
                    <div class="res-item price-row">
                      <div class="price-box">
                        <div class="price-label">Downpayment (50% Online)</div>
                        <div id="dpAmountText" class="price-amount">₱0.00</div>
                      </div>
                      <input type="hidden" name="downpayment" id="downpaymentInput" value="">
                      <small class="dp-info">This is a partial payment of 50% of the total price. The remaining balance can be paid onsite at the administration office.</small>
                      <small class="nonrefundable">Downpayment is non-refundable.</small>
                      <small class="booking-mode-hint" id="bookingModeHint"></small>
                    </div>
                    <div id="submitWrap" class="res-item submit-wrap">
                      <button id="submitBtn" class="btn-submit disabled" type="submit" disabled>Next</button>
                    </div>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div id="amenityImageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div class="amia-box" style="position:relative; background:#fff; border-radius:12px; padding:12px; max-width:90vw; max-height:90vh;">
      <button type="button" id="amenityImageClose" class="close-profile-modal" aria-label="Close">&times;</button>
      <img id="amenityImageModalImg" src="" alt="Amenity" style="display:block; max-width:85vw; max-height:80vh;">
    </div>
  </div>
</div>
</section>

<div id="verifyModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="close-profile-modal" id="verifyCloseBtn" aria-label="Close">&times;</button>
    <h2>Confirm Details</h2>
    <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
    <div class="verify-actions" style="text-align:center;margin-top:16px;display:flex;justify-content:center;flex-wrap:wrap;gap:10px;flex:0 0 auto;position:relative;z-index:10;">
      <button type="button" class="btn-cancel" id="verifyCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="verifyConfirmBtn">Confirm</button>
    </div>
  </div>
  </div>

<div id="resetReservationConfirmModal" class="modal" style="display:none;">
  <div class="modal-content points-redemption-confirm-content reset-reservation-confirm-content">
    <button type="button" class="close-profile-modal" id="resetReservationCloseBtn" aria-label="Close">&times;</button>
    <h2>Reset Reservation?</h2>
    <p class="points-redemption-confirm-message">Going back will clear your reservation details and release the selected time slot.</p>
    <div class="points-redemption-confirm-actions reset-reservation-confirm-actions">
      <button type="button" class="btn-cancel" id="resetReservationCancelBtn">Stay</button>
      <button type="button" class="btn-confirm" id="resetReservationConfirmBtn">Reset Reservation</button>
    </div>
  </div>
</div>

<div id="pointsRedemptionConfirmModal" class="modal" style="display:none;">
  <div class="modal-content points-redemption-confirm-content">
    <button type="button" class="close-profile-modal" id="pointsRedemptionCloseBtn" aria-label="Close">&times;</button>
    <h2>Confirm VHEcoPoint Redemption</h2>
    <p class="points-redemption-confirm-message" id="pointsRedemptionConfirmMessage">Are you sure you want to redeem your VHEcoPoint points for 1 Free Hour?</p>
    <div class="points-redemption-confirm-summary">
      <div><span>Points to Redeem:</span><strong id="pointsRedemptionConfirmAmount">0 pts</strong></div>
      <div><span>Benefit:</span><strong>1 Free Hour</strong></div>
    </div>
    <div class="points-redemption-confirm-actions">
      <button type="button" class="btn-cancel" id="pointsRedemptionCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="pointsRedemptionConfirmBtn">Confirm Redemption</button>
    </div>
  </div>
</div>

<div id="switchRewardRedemptionModal" class="modal" style="display:none;">
  <div class="modal-content points-redemption-confirm-content">
    <button type="button" class="close-profile-modal" id="switchRewardRedemptionCloseBtn" aria-label="Close">&times;</button>
    <h2>Switch Amenity?</h2>
    <p class="points-redemption-confirm-message" id="switchRewardRedemptionMessage"></p>
    <div class="points-redemption-confirm-actions">
      <button type="button" class="btn-cancel" id="keepRewardRedemptionBtn">Keep Current</button>
      <button type="button" class="btn-confirm" id="confirmRewardRedemptionSwitchBtn">Switch Amenity</button>
    </div>
  </div>
</div>

<div id="switchToCashModal" class="modal" style="display:none;">
  <div class="modal-content points-redemption-confirm-content">
    <button type="button" class="close-profile-modal" id="switchToCashCloseBtn" aria-label="Close">&times;</button>
    <h2>Switch to Cash Payment?</h2>
    <p class="points-redemption-confirm-message">Your VHEcoPoint redemption has already been confirmed. Switching to Cash will cancel this redemption and return the points to your available balance.</p>
    <div class="points-redemption-confirm-actions">
      <button type="button" class="btn-confirm" id="switchToCashKeepBtn">Keep Redemption</button>
      <button type="button" class="btn-cancel" id="switchToCashSwitchBtn">Switch to Cash</button>
    </div>
  </div>
</div>

<!-- Error Modal -->
<div id="errorModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="close-profile-modal" id="errorModalCloseBtn" aria-label="Close">&times;</button>
    <h2 style="color:#dc2626;">Error</h2>
    <p id="errorModalMessage" style="margin-top:15px; text-align:left;"></p>
    <div style="text-align:center;margin-top:20px;">
      <button type="button" class="btn-confirm" id="errorModalOkBtn">Okay</button>
    </div>
  </div>
</div>

<div id="vhecoRedemptionSuccessModal" class="modal" style="display:none;">
  <div class="modal-content vheco-success-content">
    <button type="button" class="close-profile-modal" id="vhecoRedemptionSuccessCloseBtn" aria-label="Close">&times;</button>
    <div class="vheco-success-icon" id="vhecoRedemptionSuccessIcon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
    <h2 id="vhecoRedemptionSuccessHeading">VHEcoPoint Redemption Confirmed</h2>
    <p class="vheco-success-message" id="vhecoRedemptionSuccessMessage">Your VHEcoPoint points have been applied for 1 Free Hour.</p>
    <div class="validation-error-actions" style="margin-top:20px;">
      <button type="button" class="btn-confirm" id="vhecoRedemptionSuccessOkBtn">OK</button>
    </div>
  </div>
</div>

<div id="changeAmenityModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="close-profile-modal" id="changeAmenityCloseBtn" aria-label="Close">&times;</button>
    <h2>Change amenity?</h2>
    <p style="margin:8px 0 16px;color:#4b5563;">Are you sure you want to change amenities? This will reset your current selection.</p>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-cancel" id="changeAmenityCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="changeAmenityConfirmBtn">Yes, change</button>
    </div>
  </div>
</div>
<div id="resetReservationModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="close-profile-modal" id="resetReservationCloseBtn" aria-label="Close">&times;</button>
    <h2>Reservation reset</h2>
    <p style="margin:8px 0 16px;color:#4b5563;">You need to make the reservation again since you clicked back on the downpayment page.</p>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-confirm" id="resetReservationOkBtn">OK</button>
    </div>
  </div>
</div>

<!--
<div id="hintModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h2>Next Step</h2>
    <p>Select date here and fill out the form.</p>
    <div style="text-align:center;margin-top:8px;">
      <button class="close-btn" onclick="closeHint()">OK</button>
    </div>
  </div>
</div>
-->

<script>
  const monthNames=["January","February","March","April","May","June","July","August","September","October","November","December"];
  let today=new Date(),currentMonth=today.getMonth(),currentYear=today.getFullYear();
  const monthAndYear=document.getElementById("monthAndYear"),calendarBody=document.getElementById("calendar-body");
  const todayStr=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
  const minDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
  const minDateStr = `${minDate.getFullYear()}-${String(minDate.getMonth()+1).padStart(2,'0')}-${String(minDate.getDate()).padStart(2,'0')}`;
  const currentUserType="<?php echo $sessionUserType !== null ? htmlspecialchars($sessionUserType, ENT_QUOTES) : ''; ?>";
  const VP_AMENITY_MAX_PERSONS = <?php echo json_encode(function_exists('vpAmenityMaxPersonsMap') ? vpAmenityMaxPersonsMap($con) : [], JSON_UNESCAPED_SLASHES); ?>;
  let residentPoints = <?php echo isset($residentPoints) ? intval($residentPoints) : 0; ?>;
  let selectedStart=null,selectedEnd=null;
  let endDateRangeError=false;
  let bookedDates=new Set();
  let availabilityCache=new Map();
  let bookedTimesCache=new Map();
  let bookedTimesRequests=new Map();
  let availabilityToken=0;
  async function reserveFetchJson(url){
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = setTimeout(function(){ if(controller){ controller.abort(); } }, 10000);
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller ? controller.signal : undefined });
      if(!response.ok){ throw new Error('Reserve request failed (' + response.status + ')'); }
      return await response.json();
    } finally {
      clearTimeout(timer);
    }
  }
  let selectedAmenity=document.getElementById('amenityField').value||'';
  let usePoints = false;
  let redemptionConfirmed = false;
  let paymentModeCheckToken = 0;

  function vpShowModal(el){
    if(!el) return;
    el.classList.remove('closing');
    el.classList.add('open');
    el.style.display='flex';
    document.documentElement.classList.add('modal-open');
    document.body.classList.add('modal-open');
  }
  function vpHideModal(el, done){
    if(!el) return;
    if(el.classList.contains('closing')) { if(done) done(); return; }
    if(el.classList.contains('open') || getComputedStyle(el).display!=='none'){
      el.classList.remove('open');
      el.classList.add('closing');
      setTimeout(function(){
        el.style.display='none';
        el.classList.remove('closing');
        if(done) done();
        vpReleaseModalScroll();
      }, 260);
    } else {
      el.style.display='none';
      if(done) done();
      vpReleaseModalScroll();
    }
  }
  function vpReleaseModalScroll(){
    const mods=document.querySelectorAll('.modal');
    let anyOpen=false;
    for(let i=0;i<mods.length;i++){
      if(getComputedStyle(mods[i]).display!=='none'){ anyOpen=true; break; }
    }
    if(!anyOpen){
      document.documentElement.classList.remove('modal-open');
      document.body.classList.remove('modal-open');
    }
  }

  function getPointsRequired(amenity) {
    switch(amenity) {
      case 'Basketball Court':
      case 'Tennis Court':
        return 300;
      case 'Clubhouse':
        return 600;
      case 'Multi-Purpose Building':
        return 750;
      default:
        return 0;
    }
  }

  function getAmenitySavingsValue(amenity) {
    return amenity ? getHourlyRate(amenity, isResidentSelfBooking()) : 0;
  }

  function updateBookingModeCards() {
    const toggle = document.getElementById('use-points-toggle');
    const cashBtn = document.getElementById('bookingModeCash');
    const pointsBtn = document.getElementById('bookingModePoints');
    const pointsRequiredLabel = document.getElementById('bookingModePointsRequired');
    const guide = document.getElementById('bookingModeGuide');
    const savingsGuide = document.getElementById('savings-guide');
    const currentPointsGuide = document.getElementById('current-points-guide');
    const amenity = document.getElementById('amenityField')?.value || selectedAmenity || '';
    const pointsRequired = getPointsRequired(amenity);
    const savingsValue = getAmenitySavingsValue(amenity);
    if (cashBtn) cashBtn.classList.toggle('is-active', !toggle || !toggle.checked);
    if (pointsBtn) pointsBtn.classList.toggle('is-active', !!(toggle && toggle.checked));
    if (pointsRequiredLabel) {
      pointsRequiredLabel.textContent = amenity ? (pointsRequired.toLocaleString() + ' pts = 1 free hour') : '750 pts = 1 free hour';
    }
    const bookingModeHint = document.getElementById('bookingModeHint');
    if (bookingModeHint) {
      if (!amenity) {
        bookingModeHint.textContent = 'Cash payment is currently selected. You can switch to point redemption if you have enough VHEcoPoint points.';
      } else if (toggle && toggle.checked) {
        bookingModeHint.textContent = 'Point redemption is active. 1 free hour will be deducted from your selected duration.';
      } else {
        bookingModeHint.textContent = 'Cash payment is currently selected. You can switch to point redemption if you have enough VHEcoPoint points.';
      }
    }
    if (guide) {
      if (!amenity) {
        guide.textContent = 'Choose an amenity to compare the cash amount and the point redemption requirement.';
      } else if (toggle && toggle.checked) {
        guide.textContent = '1 free hour redeemed with points. Remaining hours are charged at regular rate.';
      } else {
        guide.textContent = 'Cash payment is currently selected. You can switch to point redemption if you have enough VHEcoPoint points.';
      }
    }
    if (savingsGuide) {
      savingsGuide.textContent = amenity ? ('Saves ₱' + savingsValue.toLocaleString() + ' (1 free hour)') : 'Select an amenity first';
    }
    if (currentPointsGuide) {
      currentPointsGuide.textContent = residentPoints.toLocaleString() + ' pts';
    }
  }

  function setPointsDisplay(value) {
    const el = document.getElementById('current-points-number');
    if (el) el.textContent = Number(value).toLocaleString();
  }

  function showPointsErrorPopup(message, showBookWithCash) {
    let popup = document.getElementById('points-error-popup');
    let overlay = document.getElementById('points-error-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'points-error-overlay';
      overlay.className = 'points-error-overlay';
      overlay.addEventListener('click', () => {
        const p = document.getElementById('points-error-popup');
        if (p) p.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('modal-open');
      });
      document.body.appendChild(overlay);
    }
    if (!popup) {
      popup = document.createElement('div');
      popup.id = 'points-error-popup';
      popup.className = 'points-error-popup';
      document.body.appendChild(popup);
    }

    popup.innerHTML = `
      <div class="points-error-inner">
        <h3 class="points-error-title"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Insufficient Points</h3>
        <p class="points-error-message">${message}</p>
        <div class="points-error-actions">
          ${showBookWithCash ? '<button class="points-error-pay-normal">Book with Cash</button>' : ''}
          <button class="points-error-close">Cancel</button>
        </div>
      </div>
    `;

    const closeBtn = popup.querySelector('.points-error-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        popup.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('modal-open');
      });
    }

    const payNormalBtn = popup.querySelector('.points-error-pay-normal');
    if (payNormalBtn) {
      payNormalBtn.addEventListener('click', () => {
        popup.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('modal-open');
        const toggle = document.getElementById('use-points-toggle');
        if (toggle) toggle.checked = false;
        usePoints = false;
        const redemptionInfo = document.getElementById('redemption-info');
        if (redemptionInfo) redemptionInfo.style.display = 'none';
        const currentPointsEl = document.getElementById('current-points-display');
        if (currentPointsEl) setPointsDisplay(residentPoints);
        updateDisplayedPrice();
        updateDownpaymentSuggestion();
        updateBookingModeCards();
      });
    }

    popup.classList.add('open');
    overlay.classList.add('open');
    document.body.classList.add('modal-open');
  }

  function showPointsRedemptionConfirm(preferredAmenity) {
    const amenity = preferredAmenity || document.getElementById('amenityField')?.value || selectedAmenity || '';
    const pointsRequired = getPointsRequired(amenity);
    const amountEl = document.getElementById('pointsRedemptionConfirmAmount');
    const messageEl = document.getElementById('pointsRedemptionConfirmMessage');
    const modal = document.getElementById('pointsRedemptionConfirmModal');
    if (!pointsRequired || !modal) return false;
    if (amountEl) amountEl.textContent = pointsRequired.toLocaleString() + ' pts';
    if (messageEl) messageEl.textContent = 'Are you sure you want to redeem your VHEcoPoint points for 1 Free Hour' + (amenity ? ' at ' + amenity : '') + '?';
    vpShowModal(modal);
    return true;
  }

  function closePointsRedemptionConfirm() {
    const modal = document.getElementById('pointsRedemptionConfirmModal');
    if (modal) vpHideModal(modal);
  }

  function showSwitchToCashConfirm() {
    const modal = document.getElementById('switchToCashModal');
    if (modal) vpShowModal(modal);
  }

  function closeSwitchToCashConfirm() {
    const modal = document.getElementById('switchToCashModal');
    if (modal) vpHideModal(modal);
  }

  function switchToCashMode() {
    const checkToken = ++paymentModeCheckToken;
    const toggle = document.getElementById('use-points-toggle');
    if (toggle) toggle.checked = false;
    usePoints = false;
    setRedemptionConfirmed(false);
    const usePointsInput = document.getElementById('use-points-input');
    if (usePointsInput) usePointsInput.value = '0';
    updateRedemptionInfo();
    fetchLivePointsBalance().then(function(liveBalance) {
      if (checkToken !== paymentModeCheckToken || (toggle && toggle.checked)) return;
      if (liveBalance !== null) {
        residentPoints = liveBalance;
        setPointsDisplay(residentPoints);
        updateBookingModeCards();
      }
    });
  }

  async function switchToPointsMode() {
    const toggle = document.getElementById('use-points-toggle');
    if (!toggle) return;
    const checkToken = ++paymentModeCheckToken;
    toggle.checked = true;
    usePoints = true;
    setRedemptionConfirmed(false);
    updateBookingModeCards();

    const liveBalance = await fetchLivePointsBalance();
    if (checkToken !== paymentModeCheckToken || !toggle.checked) return;
    if (liveBalance !== null) residentPoints = liveBalance;
    updateRedemptionInfo();
    if (toggle.checked) showPointsRedemptionConfirm();
  }

  function setRedemptionConfirmed(value) {
    redemptionConfirmed = value === true;
    const input = document.getElementById('redemption-confirmed-input');
    if (input) input.value = redemptionConfirmed ? '1' : '0';
    const usePointsInput = document.getElementById('use-points-input');
    const toggle = document.getElementById('use-points-toggle');
    if (usePointsInput) usePointsInput.value = redemptionConfirmed && toggle && toggle.checked ? '1' : '0';
    if (typeof updateResProgress === 'function') { updateResProgress(); }
  }

  function updateRedemptionInfo() {
    const toggle = document.getElementById('use-points-toggle');
    const redemptionInfo = document.getElementById('redemption-info');
    const requiredPointsEl = document.getElementById('required-points');
    const remainingPointsEl = document.getElementById('remaining-points');
    const currentPointsEl = document.getElementById('current-points-display');

    if (!toggle) return;

    usePoints = toggle.checked;

    if (usePoints) {
      if (!selectedAmenity) {
        toggle.checked = false;
        usePoints = false;
        showPointsErrorPopup("Please select an amenity first before redeeming points.");
      } else {
        const pointsRequired = getPointsRequired(selectedAmenity);
        const remainingPoints = residentPoints - pointsRequired;

        if (remainingPoints < 0) {
        toggle.checked = false;
        usePoints = false;
        setRedemptionConfirmed(false);
        const reqP = getPointsRequired(selectedAmenity);
        showPointsErrorPopup("You don\u2019t have enough points to redeem this amenity. You need " + reqP.toLocaleString() + " pts for 1 free hour, but your current balance is " + residentPoints.toLocaleString() + " pts. Please earn more points or choose Book with Cash.", true);
          if (redemptionInfo) redemptionInfo.style.display = 'none';
        } else {
          if (requiredPointsEl) requiredPointsEl.textContent = pointsRequired.toLocaleString() + ' pts';
          if (remainingPointsEl) remainingPointsEl.textContent = remainingPoints.toLocaleString() + ' pts';
          if (redemptionInfo) redemptionInfo.style.display = 'block';
          if (currentPointsEl) {
            setPointsDisplay(remainingPoints);
          }
        }
      }
    } else {
      if (redemptionInfo) redemptionInfo.style.display = 'none';
      setRedemptionConfirmed(false);
      if (currentPointsEl) {
        setPointsDisplay(residentPoints);
      }
    }

    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingModeCards();
  }

  document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('use-points-toggle');
    const cashBtn = document.getElementById('bookingModeCash');
    const pointsBtn = document.getElementById('bookingModePoints');
    if (toggle) {
      toggle.addEventListener('change', updateRedemptionInfo);
    }
    if (cashBtn && toggle) {
      cashBtn.addEventListener('click', function() {
        if (redemptionConfirmed) {
          showSwitchToCashConfirm();
          return;
        }
        switchToCashMode();
      });
    }
    if (pointsBtn && toggle) {
      pointsBtn.addEventListener('click', function() {
        switchToPointsMode();
      });
    }
    updateBookingModeCards();

    // If hours change while points are enabled, recalculate price
    const hoursInputs = [document.getElementById('hoursInput'), document.getElementById('hoursSelect')].filter(el => el);
    hoursInputs.forEach(input => {
      input.addEventListener('change', function() {
        const toggleEl = document.getElementById('use-points-toggle');
        if (toggleEl && toggleEl.checked) {
          updateDisplayedPrice();
          updateDownpaymentSuggestion();
          updateRedemptionInfo();
        }
      });
      input.addEventListener('input', function() {
        const toggleEl = document.getElementById('use-points-toggle');
        if (toggleEl && toggleEl.checked) {
          updateDisplayedPrice();
          updateDownpaymentSuggestion();
          updateRedemptionInfo();
        }
      });
    });
  });
  let hintShown=false;
  const approvedGuestsMax = <?php echo (isset($residentGuests) && is_array($residentGuests)) ? count($residentGuests) : 0; ?>;

  function formatDateToMMDDYYYY(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[1]}/${parts[2]}/${String(parts[0]).slice(-2)}`;
  }
  function formatTimeLabel(t){
    if(!t) return '';
    const parts=String(t).split(':');
    let h=parseInt(parts[0]||'0',10);
    const m=(parts[1]||'00').padStart(2,'0');
    const ampm=h>=12?'PM':'AM';
    if(h===0){ h=12; }
    else if(h>12){ h-=12; }
    return `${h}:${m} ${ampm}`;
  }
  function countWeekdaysInclusive(startStr,endStr){
    if(!startStr || !endStr) return 0;
    const s=new Date(startStr);
    const e=new Date(endStr);
    if(isNaN(s) || isNaN(e)) return 0;
    let count=0;
    const d=new Date(s.getFullYear(),s.getMonth(),s.getDate());
    const end=new Date(e.getFullYear(),e.getMonth(),e.getDate());
    while(d<=end){
      const dow=d.getDay();
      if(dow!==0 && dow!==6){ count++; }
      d.setDate(d.getDate()+1);
    }
    return count;
  }

  async function loadBookedDates(forceRefresh){
    if(!selectedAmenity){ bookedDates=new Set(); renderCalendar(currentMonth,currentYear,forceRefresh); computeAvailability(); return; }
    bookedDates=new Set();
    const availabilityPromise = renderCalendar(currentMonth,currentYear,forceRefresh);
    await availabilityPromise;
    // renderCalendar rebuilt every cell; re-apply the selected range highlight
    // so a background refresh never wipes the user's selection state.
    if(typeof updateSelectedDateRangeHighlight === 'function'){ updateSelectedDateRangeHighlight(); }
    computeAvailability();
  }

  function renderCalendar(month,year,forceRefresh){
    calendarBody.innerHTML="";
    let firstDay=(new Date(year,month)).getDay();
    let daysInMonth=32-new Date(year,month,32).getDate();
    monthAndYear.textContent=monthNames[month]+" "+year;
    const totalCells=firstDay+daysInMonth;
    const gridCells=Math.max(35,Math.ceil(totalCells/7)*7);
    for(let i=0;i<gridCells;i++){
      const dayNum=i-firstDay+1;
      if(dayNum<1||dayNum>daysInMonth){
        const empty=document.createElement("div");
        empty.className="cal-empty";
        calendarBody.appendChild(empty);
        continue;
      }
      const cell=document.createElement("button");
      cell.type="button";
      cell.className="cal-cell";
      cell.textContent=dayNum;
      const ds=`${year}-${String(month+1).padStart(2,'0')}-${String(dayNum).padStart(2,'0')}`;
      cell.setAttribute('data-date', ds);
      if(ds < minDateStr) { cell.classList.add('disabled'); }
      cell.addEventListener('click',()=>handleDateClick(cell,ds));
      if(dayNum===today.getDate()&&year===today.getFullYear()&&month===today.getMonth()) cell.classList.add('today');
      calendarBody.appendChild(cell);
    }
    return evaluateCalendarAvailability(forceRefresh);
  }

  function toDateString(d){ return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }
  function buildDayIndex(allBooked,startDs,endDs){
    const byDate={};
    if(!Array.isArray(allBooked) || allBooked.length===0){ return byDate; }
    const startObj=new Date(startDs);
    const endObj=new Date(endDs);
    for(const t of allBooked){
      if(!t.start_date || !t.end_date) continue;
      if(t.end_date < startDs || t.start_date > endDs) continue;
      let d=new Date(t.start_date < startDs ? startDs : t.start_date);
      const e=new Date(t.end_date > endDs ? endDs : t.end_date);
      while(d<=e){
        const ds=toDateString(d);
        if(!byDate[ds]) byDate[ds]=[];
        byDate[ds].push(t);
        d.setDate(d.getDate()+1);
      }
    }
    return byDate;
  }
  async function evaluateCalendarAvailability(forceRefresh){
    try{
      const amen=document.getElementById('amenityField').value;
      if(!amen){ return; }
      const cells=Array.from(document.querySelectorAll('.calendar .cal-cell')).filter(c=>c.hasAttribute('data-date'));
      if(cells.length === 0) return;
      const startDs = cells[0].getAttribute('data-date');
      const endDs = cells[cells.length-1].getAttribute('data-date');
      const key = `${amen}|${startDs}|${endDs}`;
      const token = ++availabilityToken;
      let cached = availabilityCache.get(key);
      if(!cached || forceRefresh){
        if(forceRefresh){
          Array.from(bookedTimesCache.keys()).forEach(function(cacheKey){
            if(cacheKey.indexOf(`${amen}|`) === 0) bookedTimesCache.delete(cacheKey);
          });
        }
        const data = await reserveFetchJson(`reserve.php?action=booked_times&amenity=${encodeURIComponent(amen)}&start_date=${startDs}&end_date=${endDs}`);
        const byDate = buildDayIndex(data.times||[], startDs, endDs);
        cached = { data, byDate };
        availabilityCache.set(key, cached);
        cells.forEach(function(cell){
          const ds = cell.getAttribute('data-date');
          if(!ds) return;
          bookedTimesCache.set(`${amen}|${ds}`, {
            times: byDate[ds] || [],
            persons_total: data.persons_total || 0,
            capacity: data.capacity || 0
          });
        });
      }
      if(token !== availabilityToken) return;
      const data = cached.data || {};
      const hrsRange=getAmenityHours(amen);
      const minH=parseInt(hrsRange.min.split(':')[0],10);
      const maxH=parseInt(hrsRange.max.split(':')[0],10);
      const totalHours=Math.max(0,maxH-minH);
      const byDate = cached.byDate || {};
      for(const cell of cells){
        const ds=cell.getAttribute('data-date');
        if(!ds) continue;
        if(ds < minDateStr){
          cell.classList.add('disabled');
          cell.title = ds < todayStr ? 'Past dates cannot be booked.' : 'Reservations must be made at least 1 day in advance.';
          continue;
        }
        cell.classList.remove('disabled','partly','available','fully-booked');
        const dayBooked = byDate[ds] || [];
        const reservedHours = getReservedHoursForDay(dayBooked, minH, maxH, ds, amen);
        if(reservedHours>=totalHours){ cell.classList.add('disabled'); cell.classList.add('fully-booked'); cell.title='Fully Booked — no start times are available for this date.'; }
        else if(reservedHours>0){ cell.classList.add('partly'); cell.title='Partially Booked — some start times are unavailable.'; }
        else { cell.classList.add('available'); cell.title=''; }
      }
    }catch(_){ }
  }

  async function handleDateClick(cell,dateString){
    if(cell.classList.contains('disabled')){
      if(cell.classList.contains('fully-booked')){
        showStartDateError('Fully Booked — no start times are available for this date.');
      } else if(dateString && dateString < todayStr){
        showStartDateError('Past date — cannot be booked.');
      } else if(dateString && dateString < minDateStr){
        showStartDateError('Reservations must be made at least 1 day in advance.');
      } else {
        showStartDateError('The selected date is not available for booking.');
      }
      return;
    }
    const singleToggle = document.getElementById('singleDayToggle');
    let singleActive = singleToggle?.checked;
    const isSameAsStart = selectedStart && dateString === selectedStart;
    const isSameAsEnd = selectedEnd && dateString === selectedEnd;
    var forceStart = false;
    if(singleActive){
      if(isSameAsStart && isSameAsEnd){
        clearCalendarSelectionHighlight();
        clearStartDate();

        return;
      }
      if(!isSameAsStart && !isSameAsEnd){
        if(singleToggle){
          singleToggle.checked = false;
          singleToggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
        singleActive = false;
        selectedEnd = null;
        document.getElementById('endDate').textContent='--';
        document.getElementById('endDateInput').value='';
        showDateError('');
        forceStart = true;
      }
    } else {
      if(isSameAsStart && !selectedEnd){
        clearDates();
        return;
      }
      if(isSameAsEnd){
        clearCalendarSelectionHighlight();
        clearEndDate();

        return;
      }
    }
    document.querySelectorAll('.calendar .cal-cell').forEach(td=>{ td.classList.remove('active'); td.classList.remove('active-start'); td.classList.remove('active-end'); });
    cell.classList.add('active');
    function setStart(ds, ignoreEnd){
      const eVal=document.getElementById('endDateInput').value||'';
      if(!ignoreEnd && eVal && ds > eVal){ showStartDateError('Start date cannot be later than end date.'); return false; }
      selectedStart=ds;
      document.getElementById('startDate').textContent=formatDateToMMDDYYYY(selectedStart);
      document.getElementById('startDateInput').value=selectedStart;
      showStartDateError('');
      updateHoursSelectEnabled();
      return true;
    }
    function setEnd(ds){
      const sVal=document.getElementById('startDateInput').value||'';
      if(sVal && ds < sVal){
        showDateError('End date cannot be earlier than start date.');
        showStartDateError('');
        return false;
      }
      if(sVal){ const sD=new Date(sVal); const eD=new Date(ds); const diff=Math.floor((eD - sD)/(1000*60*60*24)); if(diff>6){ endDateRangeError=true; showDateError('Cannot book more than 1 week.'); return false; } }
      endDateRangeError=false;
      selectedEnd=ds;
      document.getElementById('endDate').textContent=formatDateToMMDDYYYY(selectedEnd);
      document.getElementById('endDateInput').value=selectedEnd;
      showDateError('');
      return true;
    }
    if(singleActive){
      setStart(dateString) && setEnd(dateString);
    } else {
      if(forceStart){ selectedStart = null; }
      if(!selectedStart){
        setStart(dateString);
      } else if(!selectedEnd){
        setEnd(dateString);
      } else {
        selectedEnd=null;
        document.getElementById('endDate').textContent='--';
        document.getElementById('endDateInput').value='';
        showDateError('');
        setStart(dateString, true);
      }
    }
    updateSelectedDateRangeHighlight();
    // The user just changed the selected date: refetch rather than trust the
    // cached range, so a slot booked moments ago shows immediately.
    await evaluateCalendarAvailability(true);
    updateSelectedDateRangeHighlight();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
  }

  function updateSelectedDateRangeHighlight(){
    const cells=Array.from(document.querySelectorAll('.calendar .cal-cell[data-date]'));
    cells.forEach(function(td){
      const ds=td.getAttribute('data-date');
      td.classList.remove('active','active-start','active-end','in-range');
      if(selectedStart && ds===selectedStart){
        td.classList.add('active-start');
        td.classList.remove('available');
      } else if(selectedEnd && ds===selectedEnd){
        td.classList.add('active-end');
        td.classList.remove('available');
      } else if(selectedStart && selectedEnd && ds>selectedStart && ds<selectedEnd){
        td.classList.add('in-range');
        td.classList.remove('available');
      }
    });
  }

  function clearCalendarSelectionHighlight(){
    const cells=Array.from(document.querySelectorAll('.calendar .cal-cell'));
    cells.forEach(function(td){
      td.classList.remove('active','active-start','active-end','in-range');
    });
  }

  function clearStartDate(){
    selectedStart=null;
    const sd=document.getElementById('startDate'); if(sd){ sd.textContent='--'; }
    const si=document.getElementById('startDateInput'); if(si){ si.value=''; }
    updateSelectedDateRangeHighlight();
    updateHoursSelectEnabled();
  }

  function clearEndDate(){
    selectedEnd=null;
    const ed=document.getElementById('endDate'); if(ed){ ed.textContent='--'; }
    const ei=document.getElementById('endDateInput'); if(ei){ ei.value=''; }
    updateSelectedDateRangeHighlight();
  }

  function clearDates(){
    resetReservationForm();
    updateSelectedDateRangeHighlight();
    evaluateCalendarAvailability();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    markDirty('endDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
    updateHoursSelectEnabled();
  }

  function updateHoursSelectEnabled(){
    const hs=document.getElementById('hoursSelect');
    if(!hs) return;
    const s=document.getElementById('startDateInput')?.value||'';
    hs.disabled = !s;
  }

  function initSingleDayToggle(){
    const cb=document.getElementById('singleDayToggle');
    if(!cb) return;
    cb.addEventListener('change', function(){
      const s=document.getElementById('startDateInput').value;
      const endInput=document.getElementById('endDateInput');
      const endText=document.getElementById('endDate');
      clearCalendarSelectionHighlight();
      if(this.checked){
        if(s){
          selectedStart=s;
          selectedEnd=s;
          document.getElementById('startDateInput').value=s;
          const sd=document.getElementById('startDate'); if(sd){ sd.textContent=formatDateToMMDDYYYY(s); }
          if(endInput){ endInput.value=s; }
          if(endText){ endText.textContent=formatDateToMMDDYYYY(s); }
        } else {
          selectedStart=null;
          selectedEnd=null;
        }
      } else {
        selectedEnd=null;
        if(endInput){ endInput.value=''; }
        if(endText){ endText.textContent='--'; }
      }
      renderCalendar(currentMonth, currentYear);
      updateSelectedDateRangeHighlight();
      computeAvailability();
      renderTimeSlotButtons();
      updateActionStates();
      showIncompleteWarnings(false);
      updateSelectedTimeRange();
      updateBookingSummary();
    });
  }

  const amenityData={
    clubhouse:{
      title:'Clubhouse',
      value:'Clubhouse',
      img:'images/clubhouse.png',
      desc:'A flexible indoor venue for birthdays, meetings, and celebrations. Air‑conditioned function hall with tables, chairs, and sound‑ready space so you can focus on your event while we provide the venue.',
      days:'Available Monday – Sunday',
      priceLabel:'₱450 visitor | ₱300 resident per hour',
      capacity:200
    },
    multipurpose:{
      title:'Multi-Purpose Building',
      value:'Multi-Purpose Building',
      img:'images/multi purpose building.png',
      desc:'A versatile space for various community activities, events, and recreational uses.',
      days:'Available Monday – Sunday',
      priceLabel:'₱300 visitor | ₱200 resident per hour',
      capacity:50
    },
    basketball:{
      title:'Basketball Court',
      value:'Basketball Court',
      img:'images/basketballcourt.png',
      desc:'Full outdoor court for pick‑up games, team practice, and training sessions. Ideal for leagues or friendly matches, with clear markings and lighting for late‑afternoon play.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 visitor | ₱100 resident per hour',
      capacity:30
    },
    tennis:{
      title:'Tennis Court',
      value:'Tennis Court',
      img:'images/tenniscourt.png',
      desc:'Reserve a dedicated court time for casual rallies or competitive singles and doubles. Well‑maintained surface suitable for all skill levels, from beginners to regular players.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 visitor | ₱100 resident per hour',
      capacity:60
    }
  };

  function updateAmenityDescription(key){
    const info=amenityData[key]||amenityData.clubhouse;
    const titleEl=document.getElementById('amenityDescTitle');
    if(titleEl){ titleEl.textContent=info.title; }
    const descEl=document.getElementById('amenityDescText');
    if(descEl){
      if(info.desc){ descEl.textContent=info.desc; descEl.style.display='block'; }
      else { descEl.textContent=''; descEl.style.display='none'; }
    }
    document.querySelectorAll('.amenity-desc .desc-img').forEach(function(img){ img.style.display='none'; });
    const imgEl=document.querySelector('.amenity-desc .desc-img[data-key="'+key+'"]');
    if(imgEl){ imgEl.style.display='block'; }
    const hn=document.getElementById('hoursNotice');
    if(hn){
      const hrs=getAmenityHours(info.value);
      const minH=parseInt(hrs.min.split(':')[0],10);
      const maxH=parseInt(hrs.max.split(':')[0],10);
      hn.textContent=`Bookable hours: ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}`;
      hn.style.display='block';
    }
    const daysEl=document.getElementById('amenityDescDays');
    if(daysEl){
      if(info.days){ daysEl.textContent=info.days; daysEl.style.display='block'; }
      else { daysEl.textContent=''; daysEl.style.display='none'; }
    }
    const priceEl=document.getElementById('amenityDescPrice');
    if(priceEl){
      const label=getAmenityPriceLabel(info.value);
      if(label){ priceEl.textContent=label; priceEl.style.display='block'; }
      else { priceEl.textContent=''; priceEl.style.display='none'; }
    }
    const capEl=document.getElementById('amenityDescCapacity');
    if(capEl){
      const cap=Number.isFinite(info.capacity)?info.capacity:getAmenityMaxPersons(info.value);
      if(cap && cap!==Infinity){ capEl.textContent=`Capacity: ${cap} max`; capEl.style.display='block'; }
      else { capEl.textContent=''; capEl.style.display='none'; }
    }
    const pTitle=document.getElementById('amenityPreviewTitle');
    if(pTitle){ pTitle.textContent=info.title; }
    const pDays=document.getElementById('amenityPreviewDays');
    if(pDays){
      if(info.days){ pDays.textContent=info.days; pDays.style.display='block'; }
      else { pDays.textContent=''; pDays.style.display='none'; }
    }
    const pHours=document.getElementById('amenityPreviewHours');
    if(pHours){
      try{
        const hrs=getAmenityHours(info.value);
        if(hrs){
          const minH=parseInt(hrs.min.split(':')[0],10);
          const maxH=parseInt(hrs.max.split(':')[0],10);
          pHours.textContent=`Hours: ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}`;
          pHours.style.display='block';
        }else{
          pHours.textContent='';
          pHours.style.display='none';
        }
      }catch(_){
        pHours.textContent='';
        pHours.style.display='none';
      }
    }
    const pPrice=document.getElementById('amenityPreviewPrice');
    if(pPrice){
      const label=getAmenityPriceLabel(info.value);
      if(label){ pPrice.textContent=label; pPrice.style.display='block'; }
      else { pPrice.textContent=''; pPrice.style.display='none'; }
    }
    const pMode=document.getElementById('amenityPreviewMode');
    if(pMode){
      const bookingNote=getAmenityBookingNote(info.value);
      if(bookingNote){ pMode.textContent=bookingNote; pMode.style.display='block'; }
      else { pMode.textContent=''; pMode.style.display='none'; }
    }
    const pImg=document.getElementById('amenityPreviewImg');
    if(pImg){ pImg.src=info.img; pImg.alt=info.title; }
    const pWrap=document.getElementById('amenityPreview');
    if(pWrap){ pWrap.style.display='flex'; }
    const pShell=document.getElementById('amenityPreviewShell');
    if(pShell){ pShell.style.display='flex'; }
  }

  function showInlineAmenityDetails(key){
    const info=amenityData[key]||amenityData.clubhouse;
    document.querySelectorAll('.schedule-panel').forEach(function(p){
      p.style.display='none';
      p.innerHTML='';
    });
    document.querySelectorAll('.amenity-card').forEach(function(c){
      c.removeAttribute('data-details-visible');
    });
    const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
    if(!card) return;
    card.setAttribute('data-details-visible','true');
    const panel=card.querySelector('[data-schedule-panel]');
    if(!panel) return;
    panel.innerHTML='';
    const body=document.createElement('div');
    body.className='inline-amenity-details';
    let head='';
    head+=`<div class="inline-details-head"><div class="inline-title">${info.title}</div><button type="button" class="close-profile-modal" aria-label="Close">&times;</button></div>`;
    let rows='';
    try{
      const hrs=getAmenityHours(info.value);
      if(hrs){
        const minH=parseInt(hrs.min.split(':')[0],10);
        const maxH=parseInt(hrs.max.split(':')[0],10);
        rows+=`<p class="inline-meta"><strong>Hours:</strong> ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}</p>`;
      }
    }catch(_){ }
    if(info.days){ rows+=`<p class="inline-meta"><strong>Availability:</strong> ${info.days}</p>`; }
    const rateLabel=getAmenityPriceLabel(info.value);
    if(rateLabel){ rows+=`<p class="inline-meta"><strong>Rate:</strong> ${rateLabel}</p>`; }
    const bookingNote=getAmenityBookingNote(info.value);
    if(bookingNote){ rows+=`<p class="inline-note">${bookingNote}</p>`; }
    if(Number.isFinite(info.capacity)){ rows+=`<p class="inline-meta"><strong>Capacity:</strong> ${info.capacity} guests</p>`; }
    const headEl=document.createElement('div');
    headEl.innerHTML=head;
    const bodyWrap=document.createElement('div');
    bodyWrap.className='inline-details-body';
    bodyWrap.innerHTML=rows;
    const bookBtn=document.createElement('button');
    bookBtn.type='button';
    bookBtn.className='btn-main inline-book-now';
    bookBtn.textContent='Book Now';
    body.appendChild(headEl.firstChild);
    body.appendChild(bodyWrap);
    body.appendChild(bookBtn);
    panel.appendChild(body);
    panel.style.display='block';
    const closeBtn=body.querySelector('.close-profile-modal');
    if(closeBtn){ closeBtn.addEventListener('click',function(e){ e.stopPropagation(); closeAmenityDetails(body, panel); }); }
    bookBtn.addEventListener('click',function(e){
      e.stopPropagation();
      runBookNowFlow(bookBtn);
    });
  }

  function closeAmenityDetails(sheet, panel){
    if(!sheet) return;
    if(sheet.classList){ sheet.classList.add('closing'); }
    setTimeout(function(){
      resetAmenitySelection();
    },220);
  }

  function runBookNowFlow(btn){
    if(!btn) return;
    const card=btn.closest('.amenity-card');
    if(!card) return;
    btn.style.display='none';
    const key=card.getAttribute('data-key');
    selectAmenityByKey(key);
    try{
      updateAmenityDescription(key);
      const descBox=document.getElementById('amenityDescBox');
      if(descBox){ descBox.style.display='flex'; }
      const descText=document.getElementById('amenityDescText');
      if(descText){ descText.textContent=''; descText.style.display='none'; }
    }catch(_){}
    const viewBtn=card.querySelector('button[data-action="view-desc"]');
    if(viewBtn){ viewBtn.style.display='none'; }
    document.querySelectorAll('.amenity-card').forEach(function(c){
      c.style.display='none';
    });
    const amenitiesHeader=document.getElementById('amenitiesHeader');
    if(amenitiesHeader){ amenitiesHeader.style.display='none'; }
    const ret=document.getElementById('amenityReturnBtn');
    if(ret){ ret.style.display='inline-flex'; }
    try{
      const rc=document.getElementById('reservationCard');
      if(rc){
        rc.style.display='flex';
        document.getElementById('reservationTitle').textContent='Reservation';
        document.getElementById('reservationHint').textContent='Select date, time, and persons';
        refreshAvailabilityFromServer();
        rc.scrollIntoView({behavior:'smooth',block:'start'});
      }
    }catch(_){}
  }

  function openAmenityImageModal(key){
    try{
      const info=amenityData[key]||amenityData.clubhouse;
      const modal=document.getElementById('amenityImageModal');
      const img=document.getElementById('amenityImageModalImg');
      if(!modal||!img) return;
      img.src=info.img;
      img.alt=info.title;
      vpShowModal(modal);
    }catch(_){ }
  }
  (function initAmenityImageModal(){
    const modal=document.getElementById('amenityImageModal');
    const close=document.getElementById('amenityImageClose');
    if(close){ close.onclick=function(){ vpHideModal(modal); }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ vpHideModal(modal); } }); }
  })();

  function showErrorModal(message) {
    const modal = document.getElementById('errorModal');
    const messageEl = document.getElementById('errorModalMessage');
    if (modal && messageEl) {
      messageEl.textContent = message;
      vpShowModal(modal);
    }
  }

  function showNextValidationMessage(missing) {
    const items = (Array.isArray(missing) && missing.length) ? missing : [];
    const msg = items.length
      ? 'Cannot continue to the next step yet. Complete the highlighted fields below to enable the Next button.\n\nPlease complete:\n' + items.map(function (i) { return '  \u2022 ' + i; }).join('\n')
      : 'Some required fields are still missing or invalid. Complete the highlighted fields below before clicking Next.';
    if (typeof showErrorModal === 'function') { showErrorModal(msg); return true; }
    return false;
  }

  function showVhecoRedemptionSuccess(config) {
    const modal = document.getElementById('vhecoRedemptionSuccessModal');
    if (!modal) return;
    const cfg = (config && typeof config === 'object') ? config : {};
    const headingEl = document.getElementById('vhecoRedemptionSuccessHeading');
    const msgEl = document.getElementById('vhecoRedemptionSuccessMessage');
    const iconEl = document.getElementById('vhecoRedemptionSuccessIcon');
    const iconI = iconEl ? iconEl.querySelector('i') : null;
    if (headingEl) headingEl.textContent = cfg.heading || 'VHEcoPoint Redemption Confirmed';
    if (msgEl) msgEl.textContent = cfg.message || 'Your VHEcoPoint points have been applied for 1 Free Hour.';
    if (iconEl) {
      if (cfg.icon === 'cancel') {
        iconEl.className = 'vheco-success-icon vheco-success-icon-cancel';
        if (iconI) iconI.className = 'fa-solid fa-circle-xmark';
      } else {
        iconEl.className = 'vheco-success-icon';
        if (iconI) iconI.className = 'fa-solid fa-circle-check';
      }
    }
    vpShowModal(modal);
  }

  (function initErrorModal(){
    const modal=document.getElementById('errorModal');
    const closeBtn=document.getElementById('errorModalCloseBtn');
    const okBtn=document.getElementById('errorModalOkBtn');
    if(closeBtn){ closeBtn.onclick=function(){ vpHideModal(modal); }; }
    if(okBtn){ okBtn.onclick=function(){ vpHideModal(modal); }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ vpHideModal(modal); } }); }
    <?php if (!empty($errorMsg)): ?>
      // Show error from PHP
      showErrorModal(<?php echo json_encode($errorMsg); ?>);
    <?php endif; ?>
  })();

  (function initVhecoRedemptionSuccess(){
    const modal=document.getElementById('vhecoRedemptionSuccessModal');
    const closeBtn=document.getElementById('vhecoRedemptionSuccessCloseBtn');
    const okBtn=document.getElementById('vhecoRedemptionSuccessOkBtn');
    if(closeBtn){ closeBtn.onclick=function(){ vpHideModal(modal); }; }
    if(okBtn){ okBtn.onclick=function(){ vpHideModal(modal); }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ vpHideModal(modal); } }); }
  })();

  function selectAmenityByKey(key){
    const info=amenityData[key]||amenityData.clubhouse;
    selectedAmenity=info.value;
    document.getElementById('amenityField').value=info.value;
    availabilityCache.clear();
    bookedTimesCache.clear();
    bookedTimesRequests.clear();
    
    document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
    const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
    if(card) card.classList.add('selected');
    const rc=document.querySelector('.reservation-card');
    // if(!hintShown){ hintShown=true; const hm=document.getElementById('hintModal'); if(hm){ hm.style.display='flex'; } }
    resetReservationForm();
    document.querySelectorAll('.schedule-panel').forEach(p=>p.style.display='none');
    loadBookedDates();
    configureFieldsForAmenity(selectedAmenity);
    renderHoursDropdownForAmenity();
    renderTimeSlotButtons();
    updateRedemptionInfo();
    try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){}
    if(typeof updateResProgress==='function'){ updateResProgress(); }
  }

  function resetReservationForm(){
    try{
      selectedStart=null; selectedEnd=null;
      document.querySelectorAll('.calendar .cal-cell').forEach(function(td){ td.classList.remove('active','active-start','active-end','in-range'); });
      const ids=['startDateInput','endDateInput','startTimeInput','endTimeInput'];
      ids.forEach(function(id){ const el=document.getElementById(id); if(el){ el.value=''; } });
      const sd=document.getElementById('startDate'); if(sd){ sd.textContent='--'; }
      const ed=document.getElementById('endDate'); if(ed){ ed.textContent='--'; }
      const pc=document.getElementById('personCount'); if(pc){ if('value' in pc){ pc.value='0'; } else { pc.textContent='0'; } }
      const pi=document.getElementById('personsInput'); if(pi){ pi.value='0'; }
      const rtc=document.getElementById('reserveTotalCount'); if(rtc){ if('value' in rtc){ rtc.value='0'; } else { rtc.textContent='0'; } }
      const rc=document.getElementById('residentsCountInput'); if(rc){ rc.value = currentUserType === 'resident' ? '1' : '0'; }
      const gc=document.getElementById('guestsCountInput'); if(gc){ gc.value = currentUserType === 'resident' ? '0' : '0'; }
      const rText=document.getElementById('residentsCountText'); if(rText){ rText.textContent = currentUserType === 'resident' ? '1' : '0'; }
      const gText=document.getElementById('guestsCountText'); if(gText){ gText.textContent = currentUserType === 'resident' ? '0' : '0'; }
      const pWrap=document.getElementById('participantWrap'); if(pWrap){ pWrap.style.display='none'; }
      document.querySelectorAll('.resident-check').forEach(function(cb, idx){ cb.checked = idx === 0; });
      document.querySelectorAll('.guest-check').forEach(function(cb){ cb.checked = false; });
      const hi=document.getElementById('hoursInput'); if(hi){ hi.value=''; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.value=''; }
      const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='0'; }
      const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=''; tr.style.display='none'; }
      const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='none'; }
      const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='none'; }
      const tCont=document.getElementById('timeSlotContainer'); if(tCont){ tCont.innerHTML=''; tCont.style.display='none'; }
      const avail=document.getElementById('availabilityNotice'); if(avail){ avail.style.display='none'; avail.textContent=''; avail.classList.remove('notice-available','notice-partly','notice-disabled'); }
      const toggle = document.getElementById('use-points-toggle'); if(toggle){ toggle.checked = false; }
      usePoints = false;
      setRedemptionConfirmed(false);
      showStartDateError(''); showDateError(''); showTimeError(''); setFieldWarning('startTimeInput',''); setFieldWarning('endTimeInput',''); clearParticipantsWarnings(); setFieldWarning('hoursInput',''); setFieldWarning('downpaymentInput','');
      updateDisplayedPrice(); updateDownpaymentSuggestion();
      updateActionStates();
      updateParticipantVisibility();
    }catch(_){ }
  }

  document.querySelectorAll('.amenity-card img').forEach(function(img){
    img.addEventListener('click',function(e){
      e.stopPropagation();
      const card=img.closest('.amenity-card');
      if(card){
        const key=card.getAttribute('data-key');
        openAmenityImageModal(key);
      }
    });
  });

  const amenitiesList=document.getElementById('amenitiesList');

  function resetAmenitySelection(){
    document.querySelectorAll('.amenity-card').forEach(function(c){
      c.style.display='';
      c.classList.remove('selected');
      c.removeAttribute('data-details-visible');
    });
    document.querySelectorAll('.schedule-panel').forEach(function(p){
      p.style.display='none';
      p.innerHTML='';
    });
    document.querySelectorAll('button[data-action="view-desc"]').forEach(function(btn){
      btn.style.display='';
    });
    document.querySelectorAll('button[data-action="book-now"]').forEach(function(btn){
      btn.classList.remove('visible');
    });
    const rc=document.getElementById('reservationCard');
    if(rc){ rc.style.display='none'; }
    const descBox=document.getElementById('amenityDescBox');
    if(descBox){ descBox.style.display='flex'; }
    const amenitiesHeader=document.getElementById('amenitiesHeader');
    if(amenitiesHeader){ amenitiesHeader.style.display=''; }
    const t=document.getElementById('reservationTitle');
    if(t){ t.textContent='Reserve an Amenity'; }
    const h=document.getElementById('reservationHint');
    if(h){ h.textContent='Select an amenity to continue'; }
    const prev=document.getElementById('amenityPreview');
    if(prev){ prev.style.display='none'; }
    const prevShell=document.getElementById('amenityPreviewShell');
    if(prevShell){ prevShell.style.display='none'; }
    const btn=document.getElementById('amenityReturnBtn');
    if(btn){ btn.style.display='none'; }
  }

  function clearBookingFormState(){
    try{ sessionStorage.removeItem('reserve_form'); }catch(_){}
    selectedAmenity='';
    const amenField=document.getElementById('amenityField'); if(amenField){ amenField.value=''; }
    const bookingForField=document.getElementById('bookingForField'); if(bookingForField){ bookingForField.value=currentUserType==='resident'?'resident':'guest'; }
    const guestIdField=document.getElementById('guestIdField'); if(guestIdField){ guestIdField.value=''; }
    const guestRefField=document.getElementById('guestRefField'); if(guestRefField){ guestRefField.value=''; }
    resetReservationForm();
    resetAmenitySelection();
    document.querySelectorAll('input[name="guest_choice"], input[name="initial_guest_choice"]').forEach(function(r){ r.checked=false; });
    document.querySelectorAll('.guest-option').forEach(function(go){ go.style.borderColor='#e5e7eb'; });
    const confirmGuestBtn=document.getElementById('confirmGuestBtn'); if(confirmGuestBtn){ confirmGuestBtn.disabled=true; }
    updateBookingSummary();
  }

  const amenityReturnBtn=document.getElementById('amenityReturnBtn');
  if(amenityReturnBtn){
    amenityReturnBtn.addEventListener('click',function(){
      const modal=document.getElementById('changeAmenityModal');
      if(modal){ vpShowModal(modal); }
    });
  }

  async function setPersonsCount(desired){
    const pcEl=document.getElementById('personCount');
    const amen=document.getElementById('amenityField').value;
    const desiredCount=Math.max(0, parseInt(desired||'0',10) || 0);
    let max=getAmenityMaxPersons(amen);
    if(!Number.isFinite(max) || max < 1){ max = 200; }
    if(pcEl){ pcEl.max = String(max); }
    const minAllowed=0;
    const count=Math.min(max,Math.max(minAllowed,desiredCount));
    if(pcEl){ if('value' in pcEl){ pcEl.value=String(count); } else { pcEl.textContent=String(count); } }
    document.getElementById('personsInput').value=count;
    if(currentUserType !== 'resident'){
      const rInput=document.getElementById('residentsCountInput'); if(rInput){ rInput.value='0'; }
      const rText=document.getElementById('residentsCountText'); if(rText){ rText.textContent='0'; }
      const gInput=document.getElementById('guestsCountInput'); if(gInput){ gInput.value=String(count); }
      const gText=document.getElementById('guestsCountText'); if(gText){ gText.textContent=String(count); }
    }
    const note=document.getElementById('personsMaxNote');
    if(note){ note.textContent = max?(`Maximum: ${max} persons`):''; }
    if(count>=max){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); } else { clearParticipantsWarnings(); }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
  }
  async function setReserveTotalCount(desired){
    const rcEl=document.getElementById('reserveTotalCount');
    if(!rcEl) return;
    const amen=document.getElementById('amenityField') ? document.getElementById('amenityField').value : '';
    let max=typeof getAmenityMaxPersons==='function' ? getAmenityMaxPersons(amen) : 200;
    if(!Number.isFinite(max) || max < 1){ max = 200; }
    rcEl.max = String(max);
    const parsedCount=parseInt(desired,10);
    const count=Math.min(max,Math.max(0,Number.isFinite(parsedCount) ? parsedCount : 0));
    if('value' in rcEl){ rcEl.value=String(count); } else { rcEl.textContent=String(count); }
    const pInput=document.getElementById('personsInput'); if(pInput){ pInput.value=String(count); }
    const personEl=document.getElementById('participantTotal'); if(personEl){ if('value' in personEl){ personEl.value=String(count); } else { personEl.textContent=String(count); } }
    const note=document.getElementById('personsMaxNote'); if(note){ note.textContent = `Maximum: ${max} persons`; }
    updateParticipantStepperState(count, max);
    if(count>=max){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); } else { clearParticipantsWarnings(); }
    if(typeof updateDisplayedPrice==='function') updateDisplayedPrice();
    if(typeof updateDownpaymentSuggestion==='function') updateDownpaymentSuggestion();
    if(typeof updateBookingSummary==='function') updateBookingSummary();
    if(typeof updateActionStates==='function') updateActionStates();
    if(typeof persistForm==='function') persistForm();
  }
  function updateParticipantStepperState(count, max){
    const decrease=document.getElementById('decreaseParticipants');
    const increase=document.getElementById('increaseParticipants');
    if(decrease){ decrease.disabled=count<=0; }
    if(increase){ increase.disabled=count>=max; }
  }
  async function changeReserveTotal(delta){
    const rcEl=document.getElementById('reserveTotalCount');
    if(!rcEl) return;
    let count=parseInt((('value' in rcEl) ? rcEl.value : rcEl.textContent)||'0',10);
    if(!Number.isFinite(count)){ count = 0; }
    await setReserveTotalCount(count + delta);
  }
async function changePersons(val){
    const pcEl=document.getElementById('personCount');
    let count=parseInt(((pcEl && (pcEl.value||pcEl.textContent))||'0'),10);
    if(!Number.isFinite(count)){ count = 0; }
    await setPersonsCount(count + val);
  }
  function updateParticipantVisibility(){
    const wrap=document.getElementById('participantWrap');
    if(!wrap) return;
    wrap.style.display='block';
  }
  function requireDateBeforeHours(){
    const s=document.getElementById('startDateInput')?.value||'';
    if(!s){
      setFieldWarning('hoursInput','Please select a reservation date before choosing the number of hours.');
      return false;
    }
    setFieldWarning('hoursInput','');
    return true;
  }
  function changeHours(val){
    if(!requireDateBeforeHours()) return;
    const hoursSpan=document.getElementById('hoursCount');
    if(!hoursSpan) return;
    let hrs=parseInt(hoursSpan.textContent||'1');
    hrs=Math.max(1,hrs+val);
    hoursSpan.textContent=hrs;
    const hid=document.getElementById('hoursInput'); if(hid){ hid.value=hrs; }
    const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='1'; }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
    updateBookingSummary();
    showIncompleteWarnings(false);
  }

  function selectDuration(hours){
    if(!requireDateBeforeHours()) return;
    const hoursInput=document.getElementById('hoursInput');
    const hoursCount=document.getElementById('hoursCount');
    if(hoursInput){ hoursInput.value=String(Math.max(1,parseInt(hours,10)||1)); }
    if(hoursCount){ hoursCount.textContent=String(Math.max(1,parseInt(hours,10)||1)); }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
    const st=document.getElementById('startTimeInput').value;
    if(st){ computeEndTimeFromHours(); const sh=parseInt(st.split(':')[0],10); const eh=sh+parseInt(hoursInput.value||'0',10); const tr=document.getElementById('selectedTimeRange'); if(tr && hoursInput.value){ tr.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeSlot(sh)+' - '+formatTimeSlot(eh); tr.style.display='block'; } }
    const dc=document.getElementById('durationContainer'); if(dc){ Array.from(dc.children).forEach(b=>b.classList.remove('selected')); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(hoursInput.value)); if(sel){ sel.classList.add('selected'); } }
    updateActionStates();
    const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='1'; }
    updateBookingSummary();
    showIncompleteWarnings(false);
  }

  async function fetchBookedTimesFor(date){
    if(!document.getElementById('amenityField').value) return {times:[], persons_total:0, capacity:0};
    const key = `${selectedAmenity}|${date}`;
    if(bookedTimesCache.has(key)) return bookedTimesCache.get(key);
    if(bookedTimesRequests.has(key)) return bookedTimesRequests.get(key);
    const request = reserveFetchJson(`reserve.php?action=booked_times&amenity=${encodeURIComponent(selectedAmenity)}&date=${encodeURIComponent(date)}`)
      .then(data=>{ const result=data||{times:[], persons_total:0, capacity:0}; bookedTimesCache.set(key,result); return result; })
      .catch(_=>({times:[], persons_total:0, capacity:0}))
      .finally(()=>bookedTimesRequests.delete(key));
    bookedTimesRequests.set(key, request);
    return request;
  }

  function updateRewardsBadge(count){
    const badge = document.getElementById('viewRewardsBadge');
    if (!badge) return;
    const n = parseInt(count, 10);
    if (!Number.isFinite(n) || n <= 0) {
      badge.style.display = 'none';
      badge.textContent = '0';
      return;
    }
    badge.textContent = String(n);
    badge.style.display = '';
  }

  async function fetchLivePointsBalance() {
    try {
      const data = await reserveFetchJson('reserve.php?action=check_points');
      if (data.ok && typeof data.balance === 'number') {
        if (typeof data.redeemable === 'number') updateRewardsBadge(data.redeemable);
        return data.balance;
      }
    } catch (_) {}
    return null;
  }

  function isHourBasedAmenity(amen){ return amen==='Basketball Court' || amen==='Tennis Court' || amen==='Clubhouse' || amen==='Multi-Purpose Building'; }
  function isPersonBasedAmenity(){ return false; }
  function isResidentSelfBooking(){
    return currentUserType === 'resident';
  }
  function getHourlyRate(amen, residentBooking){
    const useResidentRate = typeof residentBooking === 'boolean' ? residentBooking : isResidentSelfBooking();
    if(amen==='Basketball Court' || amen==='Tennis Court'){ return useResidentRate ? 100 : 150; }
    if(amen==='Multi-Purpose Building'){ return useResidentRate ? 200 : 300; }
    if(amen==='Clubhouse'){ return useResidentRate ? 300 : 450; }
    return 0;
  }
  function computeDynamicPrice(amen, residentsCount, guestsCount, hours){
    const h = parseInt(hours||'0',10);
    const hoursCount = Number.isFinite(h) && h > 0 ? h : 0;
    if(!isHourBasedAmenity(amen) || hoursCount <= 0){
      return 0;
    }
    return hoursCount * getHourlyRate(amen, isResidentSelfBooking());
  }
  function formatPesoAmount(val){
    const rounded=Math.round(val*100)/100;
    return Number.isInteger(rounded) ? rounded.toFixed(0) : rounded.toFixed(2);
  }
  function getAmenityPriceLabel(amen){
    if(!amen) return '';
    if(amen==='Clubhouse'){
      const rate=isResidentSelfBooking()?300:450;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    if(amen==='Multi-Purpose Building'){
      const rate=isResidentSelfBooking()?200:300;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    if(amen==='Basketball Court' || amen==='Tennis Court'){
      const rate=isResidentSelfBooking()?100:150;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    return '';
  }
  function getAmenityBookingNote(amen){
    if(!amen) return '';
    if(currentUserType === 'resident'){
      const requiredPoints=getPointsRequired(amen);
      if(requiredPoints > 0){
        return `Cash payment is available — or redeem ${requiredPoints.toLocaleString()} pts for 1 free hour when you have enough VHEcoPoint points.`;
      }
    }
    return 'Cash payment is available for this amenity.';
  }
  function refreshPricingForBookingFor(){
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    const amen=document.getElementById('amenityField')?.value||'';
    const key = amen==='Clubhouse' ? 'clubhouse' : amen==='Multi-Purpose Building' ? 'multipurpose' : amen==='Basketball Court' ? 'basketball' : amen==='Tennis Court' ? 'tennis' : '';
    if(key){
      updateAmenityDescription(key);
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
      if(card && card.getAttribute('data-details-visible')==='true'){
        showInlineAmenityDetails(key);
      }
    }
  }
  function updateDisplayedPrice(){
    const usePoints = document.getElementById('use-points-toggle')?.checked || false;
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const fullBase = computeDynamicPrice(amen, residents, guests, hours);
    const paidHours = usePoints ? Math.max(0, hours - 1) : hours;
    const base = usePoints ? (paidHours * getHourlyRate(amen, isResidentSelfBooking())) : fullBase;
    const dpPercent=0.5;
    const downpayment = (usePoints && paidHours <= 0) ? 0 : (base*dpPercent);
    const priceEl=document.getElementById('price'); if(priceEl){ priceEl.textContent = '₱' + base.toFixed(2); }
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    const bd=document.getElementById('priceBreakdown');
    if(bd){
      if(amen){
        const requiredPoints = getPointsRequired(amen);
        const hourlyRate = getHourlyRate(amen, isResidentSelfBooking());
        bd.style.display='block';
        if(hours > 0){
          let rows = '';
          rows += '<div class="bd-row"><span>Original Duration: ' + hours + ' hour' + (hours !== 1 ? 's' : '') + '</span><span>₱' + fullBase.toFixed(2) + '</span></div>';
          if(usePoints){
            const discountVal = hourlyRate;
            rows += '<div class="bd-row bd-reward"><span>EcoPoints Used: ' + requiredPoints.toLocaleString() + ' pts (1 Free Hour)</span><span>-₱' + discountVal.toFixed(2) + '</span></div>';
          }else if(currentUserType === 'resident'){
            rows += '<div class="bd-row"><span>EcoPoints Used: 0</span><span>₱0.00</span></div>';
          }
          rows += '<div class="bd-row"><span>Paid Duration: ' + paidHours + ' hour' + (paidHours !== 1 ? 's' : '') + '</span><span>₱' + base.toFixed(2) + '</span></div>';
          rows += '<div class="bd-row bd-total"><span>Final Amount</span><span>₱' + base.toFixed(2) + '</span></div>';
          bd.innerHTML = rows;
        }else{
          bd.innerHTML =
            '<div class="bd-row"><span>Original Duration: 0 hours</span><span>₱0.00</span></div>' +
            (currentUserType === 'resident' ? '<div class="bd-row"><span>EcoPoints Used: 0</span><span>₱0.00</span></div>' : '') +
            '<div class="bd-row"><span>Paid Duration: 0 hours</span><span>₱0.00</span></div>' +
            '<div class="bd-row bd-total"><span>Final Amount</span><span>₱0.00</span></div>';
        }
      }else{
        bd.style.display='none';
        bd.innerHTML='';
      }
    }
    updateBookingModeCards();
    updateBookingSummary();
  }
  function updateDownpaymentSuggestion(){
    const usePoints = document.getElementById('use-points-toggle')?.checked || false;
    const dp=document.getElementById('downpaymentInput'); if(!dp) return;
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const paidHours = usePoints ? Math.max(0, hours - 1) : hours;
    const base = usePoints ? (paidHours * getHourlyRate(amen, isResidentSelfBooking())) : computeDynamicPrice(amen, residents, guests, hours);
    const dpPercent=0.5;
    const downpayment = (usePoints && paidHours <= 0) ? 0 : (base*dpPercent);
    dp.value = downpayment.toFixed(2);
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    updateBookingSummary();
  }

  function configureFieldsForAmenity(amen){
    if(!amen){
      try{
        document.getElementById('reservationCard').style.display='none';
        document.getElementById('reservationTitle').textContent='Reserve an Amenity';
        document.getElementById('reservationHint').textContent='Select an amenity to continue';
        const prev=document.getElementById('amenityPreview'); if(prev){ prev.style.display='none'; }
        updateBookingModeCards();
      }catch(_){}
      return;
    }
    const personsWrap=document.getElementById('personsInput')?.closest('.res-item');
    const hoursLabel=document.getElementById('hoursLabel');
    const hoursInput=document.getElementById('hoursInput');
    const hoursCounter=document.getElementById('hoursCounter');
    const endTimeInput=document.getElementById('endTimeInput');
    const startTimeInput=document.getElementById('startTimeInput');
    const hrs=getAmenityHours(amen);
    if(startTimeInput){ startTimeInput.min=hrs.min; startTimeInput.max=hrs.max; }
    if(endTimeInput){ endTimeInput.min=hrs.min; endTimeInput.max=hrs.max; }
    const hn=document.getElementById('hoursNotice'); if(hn){ hn.style.display='none'; hn.textContent=''; }
    const priceEl=document.getElementById('price');
    if(isHourBasedAmenity(amen)){
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='inline-block'; }
      const hsl=document.getElementById('hoursSectionLabel'); if(hsl){ hsl.style.display='block'; }
      const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='block'; }
      if(hoursInput){ if(!hoursInput.value) hoursInput.value=''; }
      if(endTimeInput){ endTimeInput.readOnly=true; }
      if(startTimeInput && hoursInput){ computeEndTimeFromHours(); }
      if(priceEl){ priceEl.style.display='block'; }
      const note=document.getElementById('personsMaxNote'); if(note){ const max=getAmenityMaxPersons(amen); note.textContent = max?(`Maximum: ${max} persons`):''; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      renderHoursDropdownForAmenity();
      renderTimeSlotButtons();
    } else {
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='none'; }
      if(endTimeInput){ endTimeInput.readOnly=false; }
      if(priceEl){ priceEl.style.display='block'; }
      const note=document.getElementById('personsMaxNote'); if(note){ const max=getAmenityMaxPersons(amen); note.textContent = max?(`Maximum: ${max} persons`):''; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      document.getElementById('hoursSectionLabel').style.display='none';
      document.getElementById('timeSectionLabel').style.display='block';
      renderTimeSlotButtons();
    }
    const pmCap=getAmenityMaxPersons(amen);
    const pmCapVal=(Number.isFinite(pmCap)&&pmCap>0)?pmCap:200;
    const pcI=document.getElementById('personCount'); if(pcI){ pcI.max=String(pmCapVal); }
    const rtcI=document.getElementById('reserveTotalCount'); if(rtcI){ rtcI.max=String(pmCapVal); }
    updateBookingModeCards();
    updateHoursSelectEnabled();
  }

  function getAmenityMaxPersons(amen){
    if(typeof VP_AMENITY_MAX_PERSONS!=='undefined' && VP_AMENITY_MAX_PERSONS && Object.prototype.hasOwnProperty.call(VP_AMENITY_MAX_PERSONS,amen)){
      const n=parseInt(VP_AMENITY_MAX_PERSONS[amen],10);
      return (isNaN(n)||n<=0)?Infinity:n;
    }
    return Infinity;
  }

  function clampToRange(timeStr){
    if(!timeStr) return '';
    const amen=document.getElementById('amenityField').value;
    const hrs=getAmenityHours(amen);
    const [h,m]=(timeStr||'').split(':');
    let hh=parseInt(h||'0',10); let mm=parseInt(m||'0',10);
    const [minH]=hrs.min.split(':'), [maxH]=hrs.max.split(':');
    const minHour=parseInt(minH,10), maxHour=parseInt(maxH,10);
    if(hh<minHour){ hh=minHour; mm=0; }
    if(hh>maxHour){ hh=maxHour; mm=0; }
    return `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
  }

  function getAmenityHours(amen){
    if(amen==='Clubhouse' || amen==='Multi-Purpose Building') return {min:'09:00', max:'21:00'};
    return {min:'09:00', max:'18:00'};
  }

  function computeEndTimeFromHours(){
    const amen=document.getElementById('amenityField').value;
    const st=document.getElementById('startTimeInput').value;
    const hrs=parseInt(document.getElementById('hoursInput').value||'0',10);
    if(!st) return;
    if(!isHourBasedAmenity(amen)){
      const units=Math.max(1,hrs||1);
      const [sh,sm]=(st||'').split(':');
      let endH=parseInt(sh||'0',10)+units; let endM=parseInt(sm||'0',10);
      const allowed=getAmenityHours(amen);
      const maxHour=parseInt(allowed.max.split(':')[0],10);
      if(endH>maxHour){ endH=maxHour; endM=0; }
      const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
      document.getElementById('endTimeInput').value=et;
      checkTimeAvailability(); updateActionStates(); return;
    }
    if(!hrs||hrs<1) return;
    const [sh,sm]=(clampToRange(st)||'').split(':');
    let h=parseInt(sh||'0',10), m=parseInt(sm||'0',10);
    let endH=h+hrs; let endM=m;
    const allowed=getAmenityHours(amen);
    const maxHour=parseInt(allowed.max.split(':')[0],10);
    if(endH>maxHour){ endH=maxHour; endM=0; }
    const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
    document.getElementById('startTimeInput').value = clampToRange(st);
    document.getElementById('endTimeInput').value = et;
    checkTimeAvailability();
    updateActionStates();
    updateDisplayedPrice();
    updateSelectedTimeRange();
    updateBookingSummary();
  }

  function updateSelectedTimeRange(){
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const el=document.getElementById('selectedTimeRange');
    if(!el) return;
    const tn=document.getElementById('selectedTimeNote');
    if(!st || !et){ el.style.display='none'; el.textContent=''; if(tn){ tn.style.display='none'; } return; }
    const sh=parseInt(st.split(':')[0],10); const sm=parseInt(st.split(':')[1]||'0',10);
    const eh=parseInt(et.split(':')[0],10); const em=parseInt(et.split(':')[1]||'0',10);
    el.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeHM(sh,sm)+' - '+formatTimeHM(eh,em);
    el.style.display='block';
    if(tn){ tn.style.display = 'none'; }
  }

  function updateBookingSummary(){
    const amenity=document.getElementById('amenityField')?.value||'';
    const start=document.getElementById('startDateInput')?.value||'';
    const end=document.getElementById('endDateInput')?.value||'';
    const hours=document.getElementById('hoursInput')?.value||'';
    const startTime=document.getElementById('startTimeInput')?.value||'';
    const endTime=document.getElementById('endTimeInput')?.value||'';
    const persons=document.getElementById('personsInput')?.value||'';
    const priceText=document.getElementById('price')?.textContent||'';
    const dpInput=document.getElementById('downpaymentInput');
    const dpRaw=dpInput && dpInput.value ? dpInput.value : '';
    const summaryWrapper=document.getElementById('reservationSummary');
    if(summaryWrapper){ summaryWrapper.style.display = amenity ? 'block' : 'none'; }
    const amEl=document.getElementById('summaryAmenity');
    if(amEl){ amEl.textContent = amenity || '--'; }
    const sdEl=document.getElementById('summaryStartDate');
    if(sdEl){ sdEl.textContent = formatDateToMMDDYYYY(start) || '--'; }
    const edEl=document.getElementById('summaryEndDate');
    if(edEl){ edEl.textContent = formatDateToMMDDYYYY(end) || '--'; }
    const hrsEl=document.getElementById('summaryHours');
    if(hrsEl){ hrsEl.textContent = hours ? `${hours} hr${parseInt(hours,10)===1 ? '' : 's'}` : '--'; }
    const stEl=document.getElementById('summaryStartTime');
    if(stEl){ stEl.textContent = startTime ? `${formatTimeLabel(startTime)}${endTime ? ' – ' + formatTimeLabel(endTime) : ''}` : '--'; }
    const modeEl=document.getElementById('summaryBookingMode');
    const usePointsActive=document.getElementById('use-points-toggle')?.checked || false;
    if(modeEl){ modeEl.textContent = usePointsActive ? 'Redeem points' : 'Cash'; }
    const pEl=document.getElementById('summaryPersons');
    if(pEl){ pEl.textContent = persons || '--'; }
    const priceEl=document.getElementById('summaryPrice');
    if(priceEl){ priceEl.textContent = priceText || '₱0.00'; }
    const dpEl=document.getElementById('summaryDownpayment');
    if(dpEl){
      const n=parseFloat(dpRaw||'0');
      const val=isNaN(n)?0:n;
      dpEl.textContent='₱'+val.toFixed(2);
    }
    const pointsEl=document.getElementById('summaryPointsUsed');
    if(pointsEl){
      if(usePointsActive){
        const pointsNeeded = getPointsRequired(amenity);
        pointsEl.textContent = pointsNeeded > 0 ? pointsNeeded.toLocaleString() + ' pts' : '—';
        pointsEl.parentElement.style.display = 'flex';
      } else {
        pointsEl.textContent = '—';
        if(pointsEl.parentElement){ pointsEl.parentElement.style.display = 'none'; }
      }
    }
  }

  function getReservedHoursForDay(booked, minH, maxH, ds, amen){
    let reservedHours=0; const marked={};
    (booked||[]).forEach(t=>{
      if(t.start_date && t.end_date && (t.end_date < ds || t.start_date > ds)) return;
      let bS=minH, bE=maxH;
      if (t.has_time && t.start && t.end) {
        bS=parseInt(String(t.start).split(':')[0],10);
        bE=parseInt(String(t.end).split(':')[0],10);
      }
      if (t.start_date && t.end_date && t.start_date !== t.end_date) {
        if (ds === t.start_date) {
          bE = 24;
        } else if (ds === t.end_date) {
          bS = 0;
        } else {
          bS = 0;
          bE = 24;
        }
      }
      for(let h=bS; h<bE; h++){
        if(h>=minH && h<maxH){ if(!marked[h]){ marked[h]=true; reservedHours++; } }
      }
    });
    return reservedHours;
  }

  async function computeAvailability(){
    const amenSel=document.getElementById('amenityField').value;
    if(!amenSel){ const card=document.querySelector('.amenity-card.selected'); if(card){ const pill=card.querySelector('.status-pill'); if(pill){ pill.textContent='Select amenity'; pill.className='status-pill neutral'; } } return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const card=document.querySelector('.amenity-card.selected');
    if(!card) return;
    const pill=card.querySelector('.status-pill');
    if(!pill) return;
    if(!s||!e){pill.textContent='Select dates';pill.className='status-pill neutral';return}
    const sd=new Date(s),ed=new Date(e);
    pill.textContent='Checking availability…'; pill.className='status-pill neutral';
    const hrsRange=getAmenityHours(amenSel);
    const minH=parseInt(hrsRange.min.split(':')[0],10);
    const maxH=parseInt(hrsRange.max.split(':')[0],10);
    const totalHours=Math.max(0,maxH-minH);
    let fullyBookedFound=false;
    let partiallyBookedFound=false;
    for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){
      const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      try{
        const data=await fetchBookedTimesFor(ds);
        const booked=data.times||[];
        const reservedHours = getReservedHoursForDay(booked, minH, maxH, ds, amenSel);
        if(reservedHours>=totalHours){ fullyBookedFound=true; break; }
        if(reservedHours>0){ partiallyBookedFound=true; }
      }catch(_){ /* ignore and treat as not fully booked */ }
    }
    if(fullyBookedFound){ pill.textContent='Fully Booked'; pill.className='status-pill unavailable'; }
    else if(partiallyBookedFound){ pill.textContent='Partially Booked'; pill.className='status-pill partly'; }
    else { pill.textContent='Available'; pill.className='status-pill available'; }
  }

  function toMinutes(t){
    try{
      if(!t) return 0;
      const s = String(t).trim();
      const m = s.match(/^(\d{1,2})(?::(\d{2})(?::(\d{2}))?)?\s*(AM|PM)?$/i);
      if(!m) return 0;
      let h = parseInt(m[1] || '0', 10);
      const min = parseInt(m[2] || '0', 10);
      const ap = (m[4] || '').toUpperCase();
      if(ap === 'PM' && h < 12) h += 12;
      if(ap === 'AM' && h === 12) h = 0;
      const total = (h * 60) + min;
      return Math.max(0, Math.min(24 * 60, total));
    } catch(_) { return 0; }
  }
  async function checkTimeAvailability(){
    const amenSel=document.getElementById('amenityField').value; if(!amenSel){ showTimeError(''); computeAvailability(); return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const card=document.querySelector('.amenity-card.selected');
    // Every early exit below means "not enough data to judge the time right now".
    // Any previous time complaint is therefore stale, so clear it instead of
    // leaving the old message on screen.
    if(!card) { showTimeError(''); return; }
    const pill=card.querySelector('.status-pill');
    if(!pill) { showTimeError(''); return; }
    if(!s||!e||!st||!et){ showTimeError(''); computeAvailability(); return; }
    if(s!==e){ showTimeError(''); computeAvailability(); return; }
    if(st && et){
      const sMin=toMinutes(st);
      const eMin=toMinutes(et);
      const amen=document.getElementById('amenityField').value;
      const allowed=getAmenityHours(amen);
      const minHour=parseInt(allowed.min.split(':')[0],10);
      const maxHour=parseInt(allowed.max.split(':')[0],10);
      const shH=Math.floor(sMin/60); const ehH=Math.floor(eMin/60);
      if(eMin<=sMin || shH<minHour || ehH>maxHour){
        pill.textContent='Invalid time'; pill.className='status-pill unavailable';
        showTimeError('Selected time is outside operating hours.');
        return;
      }
    }
    const data=await fetchBookedTimesFor(s);
    const times=data.times||[];
    const sMin2=toMinutes(st); const eMin2=toMinutes(et);
    const amen=document.getElementById('amenityField').value;
    const overlap=times.some(function(t){ if(!t.has_time){ return true; } const ts=toMinutes(t.start); const te=toMinutes(t.end); return !(sMin2>=te || eMin2<=ts); });
    if(overlap){pill.textContent='Unavailable';pill.className='status-pill unavailable'}
    else{
      const hrsRange=getAmenityHours(amen);
      const minH=parseInt(hrsRange.min.split(':')[0],10);
      const maxH=parseInt(hrsRange.max.split(':')[0],10);
      const totalHours=Math.max(0,maxH-minH);
      const reservedHours = getReservedHoursForDay(times, minH, maxH, s, amen);
      if(reservedHours>=totalHours){ pill.textContent='Fully Booked'; pill.className='status-pill unavailable'; }
      else if(reservedHours>0){ pill.textContent='Partially Booked'; pill.className='status-pill partly'; }
      else { pill.textContent='Available'; pill.className='status-pill available'; }
    }
    // Route through showTimeError() rather than writing textContent directly:
    // a raw write wipes the .msg span and × button, which then stops the × from
    // working and leaves orphaned text behind on the next update.
    if(overlap){ showTimeError('Time slot is already booked. Please choose a different time.'); }
    else { showTimeError(''); }
  }

  function isDateBooked(ds){ try { return bookedDates && bookedDates.has(ds); } catch(e){ return false; } }
  // Hides an error banner and wipes every scrap of message text inside it,
  // including any bare text node written straight onto the element (older code
  // paths did that, which left a stale message behind after "clearing").
  function clearErrorBanner(el){
    if(!el) return;
    el.style.display='none';
    Array.prototype.forEach.call(el.childNodes,function(n){ if(n.nodeType===3){ n.nodeValue=''; } });
    const m=el.querySelector('.msg'); if(m){ m.textContent=''; }
  }
  function showStartDateError(msg){ const el=document.getElementById('startDateError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ clearErrorBanner(el); }); } } else { clearErrorBanner(el); } }
  function showDateError(msg){ const el=document.getElementById('dateError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ clearErrorBanner(el); }); } } else { clearErrorBanner(el); } }
  function showTimeError(msg){ const el=document.getElementById('timeError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ clearErrorBanner(el); }); } } else { clearErrorBanner(el); } }
  function validateDates(){
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const amen=document.getElementById('amenityField').value;
    if(!s||!e){ showStartDateError(''); showDateError(''); return false; }
    if(s < minDateStr || e < minDateStr){ showStartDateError('Reservations must be made at least 1 day in advance.'); showDateError(''); return false; }
    if(e < s){ showDateError('End date cannot be earlier than start date.'); showStartDateError(''); return false; }
    if(s > e){ showStartDateError('Start date cannot be later than end date.'); showDateError(''); return false; }
    const sD=new Date(s), eD=new Date(e); const diff=Math.floor((eD - sD)/(1000*60*60*24)); if(diff>6){ showDateError('Cannot book more than 1 week.'); return false; }
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    if(s===e){
      if(st){
        if(!et){ computeEndTimeFromHours(); }
        showStartDateError(''); showDateError('');
        return true;
      }
      showDateError(''); showStartDateError('Start time is required.');
      return false;
    }
    showStartDateError(''); showDateError(''); return true;
  }

  function setFieldWarning(id,msg){
    const container=(id==='startDateInput')?document.getElementById('startDateGroup'):(id==='endDateInput')?document.getElementById('endDateGroup'):(id==='amenityField')?document.querySelector('.amenities-list'):document.getElementById(id)?.closest('.res-item');
    if(!container)return;
    let w=container.querySelector('.field-warning[data-for="'+id+'"]');
    if(msg){
      if(!w){ w=document.createElement('div'); w.className='field-warning'; w.setAttribute('data-for',id); container.appendChild(w);} 
      if(id==='personsInput'){
        const note=document.getElementById('personsMaxNote');
        if(note && note.parentElement===container){
          container.insertBefore(w, note.nextSibling);
        }
      }
      let icon=w.querySelector('.warn-icon'); if(!icon){ icon=document.createElement('span'); icon.className='warn-icon'; icon.textContent='!'; w.appendChild(icon);} 
      let m=w.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; w.appendChild(m);} m.textContent=msg;
      let close=w.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; w.appendChild(close); close.addEventListener('click',function(){ w.remove(); }); }
    } else { if(w) w.remove(); }
  }

  // The Participants block can raise its error under two different ids: the
  // hidden mirror 'personsInput' (used by the submit handler, the incomplete-
  // warning pass and the resident/guest checkbox sync) and the visible stepper
  // 'reserveTotalCount' (used by setReserveTotalCount). Both resolve to the
  // same .res-item container, so each one produced its own independent
  // .field-warning node. A message raised under one id was therefore never
  // removed when the user corrected the count through the other, leaving a
  // stale error behind. Every participants correction now clears both.
  function clearParticipantsWarnings(){
    setFieldWarning('personsInput','');
    setFieldWarning('reserveTotalCount','');
  }

  let __dirtyFields = {};
  function markDirty(id){ try{ __dirtyFields[id] = true; }catch(_){} }
  function isDirty(id){ try{ return !!__dirtyFields[id]; }catch(_){ return false; } }
  // Downpayment warnings are only raised on submit, so nothing else ever took
  // them away. Drop the warning once the amount is inside the valid range.
  // The rules themselves are unchanged: blank/non-numeric is left alone (no
  // warning exists for it) and a still-out-of-range amount keeps its message.
  function clearDownpaymentWarning(){
    const dpRaw=(document.getElementById('downpaymentInput')?.value||'').trim();
    if(dpRaw==='' || isNaN(Number(dpRaw))) return;
    const dpNum=Number(dpRaw);
    if(dpNum<0) return;
    const rCount=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const gCount=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hrs=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const basePrice=computeDynamicPrice(document.getElementById('amenityField')?.value||'', rCount, gCount, hrs);
    if(!isFinite(basePrice) || dpNum>basePrice) return;
    setFieldWarning('downpaymentInput','');
  }
  function showIncompleteWarnings(force){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen){ if(force||isDirty('amenityField')) setFieldWarning('amenityField','Please select an amenity.'); } else { setFieldWarning('amenityField',''); }
    if(!s){ if(force||isDirty('startDateInput')) showStartDateError('Start date is required.'); } else { showStartDateError(''); }
    if(!eD){
      if(force||isDirty('endDateInput')){
        if(endDateRangeError){ showDateError('Cannot book more than 1 week.'); }
        else { showDateError('End date is required.'); }
      }
    }
    else {
      const sDVal=s; const eDVal=eD;
      if(sDVal){
        if(sDVal < minDateStr || eDVal < minDateStr){
          showStartDateError('Reservations must be made at least 1 day in advance.');
          endDateRangeError=false;
          showDateError('');
        } else if(eDVal < sDVal){
          endDateRangeError=false;
          showDateError('End date cannot be earlier than start date.');
        } else {
          const sDate=new Date(sDVal); const eDate=new Date(eDVal); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6){ endDateRangeError=true; showDateError('Cannot book more than 1 week.'); } else { endDateRangeError=false; showDateError(''); }
        }
      } else { endDateRangeError=false; showDateError(''); }
    }
    if(!st){ if(force||isDirty('startTimeInput')) setFieldWarning('startTimeInput','Start time is required.'); } else { setFieldWarning('startTimeInput',''); }
    // End time is auto-computed from start time + hours; no manual warning
    if(st && !et){ computeEndTimeFromHours(); }
    // ...but the submit handler can flag it ("End time must be after start
    // time"). Clear that as soon as the computed range is valid again, so the
    // message doesn't outlive the mistake that caused it.
    if(st && et){
      const [sh,sm]=(st||'').split(':');
      const [eh,em]=(et||'').split(':');
      const sMinE=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10);
      const eMinE=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10);
      if(eMinE>sMinE){ setFieldWarning('endTimeInput',''); }
    }
    clearDownpaymentWarning();
    if(isHourBasedAmenity(amen)){
      if(hours<1){ if(force||isDirty('hoursInput')) setFieldWarning('hoursInput','Number of hours must be at least 1.'); } else { setFieldWarning('hoursInput',''); }
    }
    const max=getAmenityMaxPersons(amen);
    if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); }
    else if(persons>max && max!==Infinity){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
    else { clearParticipantsWarnings(); }
  }

  function formIsComplete(){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen||!s||!eD) return false;
    if(s < minDateStr) return false;
    if(eD < s) return false;
    if(Math.floor((new Date(eD) - new Date(s))/(1000*60*60*24)) > 6) return false;
    if(!st) return false;
    if(st){ if(!et){ computeEndTimeFromHours(); } const [sh,sm]=(st||'').split(':'), [eh,em]=(document.getElementById('endTimeInput').value||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) return false; }
    if(isHourBasedAmenity(amen)){ if(hours<1) return false; }
    if(persons<1) return false;
    const max=getAmenityMaxPersons(amen);
    if(max!==Infinity && persons>max) return false;
    return true;
  }

  function getNextButtonMissingFields(){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    const missing=[];
    const max=getAmenityMaxPersons(amen);
    if(!amen) missing.push('an amenity');
    if(!s) missing.push('a start date');
    if(!eD) missing.push('an end date');
    if(!st || !et) missing.push('a start time');
    if(s && eD){
      if(s < minDateStr){ missing.push('a start date at least 1 day ahead'); }
      else if(eD < s){ missing.push('an end date on or after the start date'); }
      else if(Math.floor((new Date(eD) - new Date(s))/(1000*60*60*24)) > 6){ missing.push('a booking within 1 week'); }
    }
    if(st && et){ const [sh,sm]=st.split(':'), [eh,em]=et.split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) missing.push('an end time after the start time'); }
    if(isHourBasedAmenity(amen)){ if(hours<1) missing.push('at least 1 hour'); }
    if(persons<1) missing.push('at least 1 person');
    if(max!==Infinity && persons>max) missing.push('a maximum of '+max+' persons');
    return missing;
  }

  document.getElementById("prevMonth").onclick=()=>{currentMonth=currentMonth===0?11:currentMonth-1;currentYear=currentMonth===11?currentYear-1:currentYear;renderCalendar(currentMonth,currentYear)};
  document.getElementById("nextMonth").onclick=()=>{currentMonth=currentMonth===11?0:currentMonth+1;currentYear=currentMonth===0?currentYear+1:currentYear;renderCalendar(currentMonth,currentYear)};
  loadBookedDates();

  document.querySelectorAll('[data-action="book-now"]').forEach(btn=>{
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      runBookNowFlow(this);
    });
  });
  
  document.querySelectorAll('button[data-action="view-desc"]').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      const card=btn.closest('.amenity-card');
      if(card){
        document.querySelectorAll('button[data-action="view-desc"]').forEach(function(b){
          b.style.display='';
        });
        document.querySelectorAll('.amenity-card').forEach(function(c){
          c.removeAttribute('data-details-visible');
        });
        document.querySelectorAll('button[data-action="book-now"]').forEach(function(b){
          b.classList.remove('visible');
        });
        const bookNowBtn=card.querySelector('button[data-action="book-now"]');
        if(bookNowBtn){ bookNowBtn.classList.add('visible'); }
        const key=card.getAttribute('data-key');
        showInlineAmenityDetails(key);
        try{
          updateAmenityDescription(key);
          const descBox=document.getElementById('amenityDescBox');
          if(descBox){ descBox.style.display='flex'; }
        }catch(_){}
        btn.style.display='none';
      }
    });
  });
  

  ['startTimeInput','endTimeInput'].forEach(id=>{const el=document.getElementById(id);if(el){el.addEventListener('input',function(){ if(isHourBasedAmenity(document.getElementById('amenityField').value) && id==='startTimeInput'){ computeEndTimeFromHours(); } else { checkTimeAvailability(); } })}});
  const hoursEl=document.getElementById('hoursInput'); if(hoursEl){ hoursEl.addEventListener('input',function(){ computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); }); }
  const participantInput=document.getElementById('reserveTotalCount');
  if(participantInput){
    participantInput.addEventListener('input',function(){ setReserveTotalCount(this.value); });
    participantInput.addEventListener('blur',function(){ setReserveTotalCount(this.value); });
    const pMaxOff=getAmenityMaxPersons(document.getElementById('amenityField').value);
    updateParticipantStepperState(parseInt(participantInput.value,10) || 0, Number.isFinite(pMaxOff)&&pMaxOff>0 ? pMaxOff : 200);
  }
  const hoursSelect=document.getElementById('hoursSelect'); if(hoursSelect){ hoursSelect.addEventListener('focus',function(){ requireDateBeforeHours(); }); hoursSelect.addEventListener('change',function(){ if(!requireDateBeforeHours()){ hoursSelect.value=''; const hcChoice=document.getElementById('hoursChosen'); if(hcChoice) hcChoice.value='0'; return; } const val=parseInt(hoursSelect.value||'0',10); if(!val) return; const hid=document.getElementById('hoursInput'); if(hid){ hid.value=String(val); const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(val); } }
    const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='block'; }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    const chosen=document.getElementById('hoursChosen'); if(chosen) chosen.value='1';
    renderTimeSlotButtons();
    showIncompleteWarnings(false);
  }); }
  document.addEventListener('DOMContentLoaded',function(){
    renderHoursDropdownForAmenity();
    renderTimeSlotButtons();

    const hs=document.getElementById('hoursSelect');
    if(hs){
      hs.addEventListener('mousedown',function(e){
        const s=document.getElementById('startDateInput')?.value||'';
        if(!s){
          e.preventDefault();
          setFieldWarning('hoursInput','Please select a reservation date first.');
        }
      });
    }
    const personInput=document.getElementById('personCount');
    if(personInput){
      personInput.addEventListener('input', async function(){
        const desired=parseInt(personInput.value||'0',10);
        await setPersonsCount(desired);
      });
    }
    const reserveTotalInput=document.getElementById('reserveTotalCount');
    if(reserveTotalInput){
      reserveTotalInput.addEventListener('input', async function(){
        const desired=parseInt(reserveTotalInput.value||'0',10);
        await setReserveTotalCount(desired);
      });
    }
  });
  ['clearStartDateBtn','clearEndDateBtn'].forEach(function(id){
    const clearButton=document.getElementById(id);
    if(clearButton){ clearButton.addEventListener('click',clearDates); }
  });
  const formEl=document.querySelector('form');
  if(formEl){
    let submitting = false;
    formEl.addEventListener('submit', async function(e){
      e.preventDefault();
      if(submitting){ return; }
      persistForm();
      if(typeof formIsComplete==='function' && !formIsComplete()){
        showIncompleteWarnings(true);
        const miss=(typeof getNextButtonMissingFields==='function')?getNextButtonMissingFields():[];
        if(typeof showNextValidationMessage==='function'){ showNextValidationMessage(miss); }
        return;
      }
      if(window.__vpHoldExpired){
        if(typeof showNextValidationMessage==='function'){ showNextValidationMessage([]); }
        showToast('Your time to complete this booking expired. Please select the time slot again.','warning');
        return;
      }
      let verifyAllowed=true;
      const gateEl=document.getElementById('submitAllowed');
      if(gateEl && gateEl.value==='0'){ verifyAllowed=false; setFieldWarning('amenityField','Payment pending. Complete downpayment to continue.'); }
      const amen=document.getElementById('amenityField').value;
      const s=document.getElementById('startDateInput').value;
      const eD=document.getElementById('endDateInput').value;
      const st=document.getElementById('startTimeInput').value;
      const et=document.getElementById('endTimeInput').value;
      const dpVal=document.getElementById('downpaymentInput')?document.getElementById('downpaymentInput').value:'';
      const persons=parseInt(document.getElementById('personsInput').value||'0');
      const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
      showIncompleteWarnings(true);
      
      // Client-side + server-side points validation
      const usePointsToggle = document.getElementById('use-points-toggle');
      if (usePointsToggle && usePointsToggle.checked && !redemptionConfirmed) {
        showPointsRedemptionConfirm();
        return;
      }
      if (usePointsToggle && usePointsToggle.checked && typeof residentPoints !== 'undefined') {
        let reqPoints = 0;
        switch (amen) {
          case 'Basketball Court':
          case 'Tennis Court':
            reqPoints = 300;
            break;
          case 'Clubhouse':
            reqPoints = 600;
            break;
          case 'Multi-Purpose Building':
            reqPoints = 750;
            break;
        }
        // First check the cached balance
        if (residentPoints < reqPoints) {
          showPointsErrorPopup("You don\u2019t have enough points to redeem this amenity. You need " + reqPoints.toLocaleString() + " pts for 1 free hour, but your current balance is " + residentPoints.toLocaleString() + " pts. Please earn more points or choose Book with Cash.", true);
          return;
        }
        // Then do a live server check to catch race conditions or stale data
        const liveBalance = await fetchLivePointsBalance();
        if (liveBalance !== null && liveBalance < reqPoints) {
          // Update the cached balance so UI reflects reality
          window.__residentPoints = liveBalance;
          showPointsErrorPopup("You don\u2019t have enough points to redeem this amenity. You need " + reqPoints.toLocaleString() + " pts for 1 free hour, but your current balance is " + liveBalance.toLocaleString() + " pts. Please earn more points or choose Book with Cash.", true);
          return;
        }
      }

      if(s && eD && (s < minDateStr || eD < minDateStr)){
        showStartDateError('Reservations must be made at least 1 day in advance.');
        showDateError('');
        return;
      }
      if(s && eD){ const sDate=new Date(s); const eDate=new Date(eD); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6){ showDateError('Cannot book more than 1 week.'); return; } }
      if(s && eD && s===eD && st && et){
        const [sh,sm]=(st||'').split(':');
        const [eh,em]=(et||'').split(':');
        const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10);
        const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10);
        if(eMin<=sMin){ setFieldWarning('endTimeInput','End time must be after start time (24-hour).'); verifyAllowed=false; }
      }
      if(dpVal!=='' && !isNaN(Number(dpVal))){ if(Number(dpVal)<0){ setFieldWarning('downpaymentInput','Downpayment cannot be negative.'); verifyAllowed=false; } }
      if(dpVal!=='' && !isNaN(Number(dpVal))){
        const dpNum=Number(dpVal);
        const rCount=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
        const gCount=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
        let basePrice=computeDynamicPrice(amen, rCount, gCount, hours);
        if(dpNum>basePrice){ setFieldWarning('downpaymentInput','Downpayment cannot exceed total price.'); verifyAllowed=false; }
      }
      if(isPersonBasedAmenity(amen) && persons<1){ setFieldWarning('personsInput','Persons must be at least 1.'); verifyAllowed=false; }
      if(s && eD && s===eD && st && et){
        const data=await fetchBookedTimesFor(s);
        const times=(data && data.times) ? data.times : [];
        const sM=toMinutes(st), eM=toMinutes(et);
        const overlap=times.some(function(t){ if(!t.has_time){ return true; } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); });
        if(overlap){
          if(typeof displaySlotError==='function'){ displaySlotError('Selected time overlaps an existing booking. Please choose a different time.'); }
          else { showTimeError('Selected time overlaps an existing booking. Please choose a different time.'); }
          return;
        }
      }
      const amenVal=document.getElementById('amenityField').value;
      if(!amenVal || !s || !eD){ verifyAllowed=false; }
      if(isHourBasedAmenity(amenVal)){
        if(!st || !et){ verifyAllowed=false; }
        if(hours<1) verifyAllowed=false;
      } else {
        if(persons<1) verifyAllowed=false;
      }
      if(!verifyAllowed){ if(typeof showNextValidationMessage==='function'){ showNextValidationMessage([]); } return; }
      if(!window.__verifyConfirmed){
        const rCount=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
        const gCount=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
        let basePriceForSummary=computeDynamicPrice(amenVal, rCount, gCount, hours);
        const priceTxt = '₱'+basePriceForSummary.toFixed(2);
        const hoursRaw = document.getElementById('hoursInput').value||'';
        const hoursVal = hoursRaw ? parseInt(hoursRaw,10) : null;
        const personsVal = parseInt(document.getElementById('personsInput').value||'1', 10);
        function formatTimeLabel(t){
          if(!t) return '';
          const parts=String(t).split(':');
          let h=parseInt(parts[0]||'0',10);
          const m=(parts[1]||'00').padStart(2,'0');
          const ampm=h>=12?'PM':'AM';
          if(h===0){ h=12; }
          else if(h>12){ h=h-12; }
          return h+':'+m+' '+ampm;
        }
        const startTimeLabel=formatTimeLabel(st);
        const endTimeLabel=formatTimeLabel(et);
        let timeDisplay='';
        if(startTimeLabel){
          timeDisplay=startTimeLabel;
          if(endTimeLabel){
            timeDisplay+=' to '+endTimeLabel;
          }
        }
        if(hoursVal){
          const hoursText=hoursVal+' hour'+(hoursVal>1?'s':'');
          timeDisplay=hoursText+(timeDisplay ? ' — '+timeDisplay : '');
        }
        let durationDisplay='-';
        if(s && eD){
          const sDate=new Date(s);
          const eDate=new Date(eD);
          const diffDays = Math.floor((eDate - sDate)/(1000*60*60*24));
          const days = isNaN(diffDays) ? 0 : (diffDays + 1);
          if(days > 0){
            if(days === 1 && hoursVal){
              durationDisplay = hoursVal + ' hour' + (hoursVal > 1 ? 's' : '');
            } else {
              durationDisplay = days + ' day' + (days > 1 ? 's' : '');
            }
          }
        }
        const displayPrice = usePoints ? '₱0.00' : priceTxt;
        let displayDownpayment = (dpVal!==''?('₱'+Number(dpVal).toFixed(2)):'—');

        function vsRow(label, value, extraClass){
          return '<div class="vs-row'+(extraClass?' '+extraClass:'')+'"><span class="vs-lbl">'+label+'</span><span class="vs-val">'+value+'</span></div>';
        }

        let summaryHTML = '';

        if (usePoints) {
          const pointsNeeded = getPointsRequired(amenVal);
          const hourlyRate = getHourlyRate(amenVal, isResidentSelfBooking());
          const fullBase = computeDynamicPrice(amenVal, rCount, gCount, hoursVal);
          const paidHours = Math.max(0, hoursVal - 1);
          const discountVal = hourlyRate;
          const chargedAmount = paidHours * hourlyRate;
          displayDownpayment = paidHours > 0 ? '₱' + (chargedAmount * 0.5).toFixed(2) : '₱0.00';

          const redemptionLabel = hoursVal > 1 ? 'Discounted Redemption' : 'Fully Redeemed';
          summaryHTML += '<div class="vs-banner"><i class="fa-solid fa-circle-check"></i> VHEcoPoint Redemption: ' + redemptionLabel + '</div>';

          summaryHTML += '<div class="vs-section">'
            + '<div class="vs-section-title">Reservation Details</div>'
            + vsRow('Amenity', amenVal||'-')
            + vsRow('Start Date', formatDateToMMDDYYYY(s) || '-')
            + vsRow('End Date', formatDateToMMDDYYYY(eD) || '-')
            + vsRow('Time', timeDisplay || '-')
            + vsRow('Persons', String(personsVal))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-reward">'
            + '<div class="vs-section-title">VHEcoPoint Redemption: ' + redemptionLabel + '</div>'
            + vsRow('Original Duration', hoursVal + ' hour' + (hoursVal > 1 ? 's' : ''))
            + vsRow('EcoPoints Used', pointsNeeded.toLocaleString() + ' pts', 'vs-good')
            + vsRow('Benefit', '1 Free Hour', 'vs-good')
            + vsRow('Paid Duration', paidHours + ' hour' + (paidHours !== 1 ? 's' : ''))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-payment">'
            + '<div class="vs-section-title">Payment Summary</div>'
            + vsRow('Original Amount', '₱' + fullBase.toFixed(2))
            + vsRow('VHEcoPoint Discount', '-₱' + discountVal.toFixed(2) + ' (' + pointsNeeded.toLocaleString() + ' pts)', 'vs-good')
            + vsRow('Final Amount', '₱' + chargedAmount.toFixed(2), 'vs-final-row')
            + vsRow('Downpayment', displayDownpayment, 'vs-dp-row')
            + '</div>';
        } else {
          summaryHTML += '<div class="vs-section">'
            + '<div class="vs-section-title">Reservation Details</div>'
            + vsRow('Amenity', amenVal||'-')
            + vsRow('Mode', usePoints ? 'Redeem Points' : 'Cash')
            + vsRow('Start Date', formatDateToMMDDYYYY(s) || '-')
            + vsRow('End Date', formatDateToMMDDYYYY(eD) || '-')
            + vsRow('Duration', durationDisplay)
            + vsRow('Time', timeDisplay || '-')
            + vsRow('Persons', String(personsVal))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-payment">'
            + '<div class="vs-section-title">Payment Summary</div>'
            + vsRow('Total Price', displayPrice)
            + vsRow('Downpayment', displayDownpayment, 'vs-dp-row')
            + '</div>';
        }

        const sumEl=document.getElementById('verifySummary'); if(sumEl){ sumEl.innerHTML = summaryHTML; }
        const vm=document.getElementById('verifyModal'); if(vm){ vpShowModal(vm); }
        return;
      } else {
        window.__verifyConfirmed=false;
        window.__vpSubmitting = true;
        try{ sessionStorage.setItem('reserve_submitted','1'); }catch(_){}
        submitting=true;
        formEl.submit();
        return;
      }
    });
    formEl.addEventListener('keydown',function(e){
      if(e.key==='Enter'){
        const target=e.target||e.srcElement;
        const tag=(target && target.tagName)?String(target.tagName).toUpperCase():'';
        const type=(target && target.getAttribute)?String(target.getAttribute('type')||'').toLowerCase():'';
        if(tag!=='TEXTAREA' && tag!=='BUTTON' && type!=='button' && type!=='submit'){
          e.preventDefault();
        }
      }
    });
  }
  (function(){
    const vm=document.getElementById('verifyModal');
    const cBtn=document.getElementById('verifyCancelBtn');
    const xBtn=document.getElementById('verifyCloseBtn');
    const pBtn=document.getElementById('verifyConfirmBtn');
    window.__verifyConfirmed=false;
    function closeVerify(){
      if(vm){ vpHideModal(vm); }
    }
    if(cBtn){ cBtn.addEventListener('click', closeVerify); }
    if(xBtn){ xBtn.addEventListener('click', closeVerify); }
    if(pBtn){
      pBtn.addEventListener('click', function(){
        showIncompleteWarnings(true);
        if(typeof formIsComplete==='function' && !formIsComplete()){
          const miss=(typeof getNextButtonMissingFields==='function')?getNextButtonMissingFields():[];
          if(typeof showNextValidationMessage==='function'){ showNextValidationMessage(miss); }
          return;
        }
        var bookingForField = document.getElementById('bookingForField');
        var userType = "<?php echo $sessionUserType !== null ? htmlspecialchars($sessionUserType, ENT_QUOTES) : ''; ?>";
        if (bookingForField) {
          if (userType === 'resident') {
            var wrap = document.getElementById('participantWrap');
            var mode = wrap ? (wrap.getAttribute('data-mode') || '') : '';
            bookingForField.value = mode === 'guest_only' ? 'guest' : 'resident';
          } else {
            bookingForField.value = 'guest';
          }
        }
        refreshPricingForBookingFor();
    window.__verifyConfirmed=true;
    try{ document.getElementById('clientConfirmed').value='1'; }catch(_){}
    // Set use points input
    const usePointsInput = document.getElementById('use-points-input');
    const usePointsToggle = document.getElementById('use-points-toggle');
    usePoints = !!(usePointsToggle && usePointsToggle.checked && redemptionConfirmed);
    if (usePointsInput) {
      usePointsInput.value = usePoints ? '1' : '0';
    }
        closeVerify();
        showToast('Details confirmed.','success');
        window.__vpSubmitting = true;
        try{ sessionStorage.setItem('reserve_submitted','1'); }catch(_){}
        const f = (typeof formEl !== 'undefined' && formEl) ? formEl : document.querySelector('form');
        if(f){
          if(typeof f.requestSubmit === 'function'){
            f.requestSubmit();
          } else {
            f.submit();
          }
        }
      });
    }
  })();

  (function(){
    const modal = document.getElementById('pointsRedemptionConfirmModal');
    const cancelBtn = document.getElementById('pointsRedemptionCancelBtn');
    const closeBtn = document.getElementById('pointsRedemptionCloseBtn');
    const confirmBtn = document.getElementById('pointsRedemptionConfirmBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', function(){
      window.__rewardRedemptionProceed = false;
      window.__rewardRedemptionAmenity = '';
      window.__confirmedRewardAmenity = '';
      switchToCashMode();
      closePointsRedemptionConfirm();
    });
    if (closeBtn) closeBtn.addEventListener('click', function(){
      window.__rewardRedemptionProceed = false;
      window.__rewardRedemptionAmenity = '';
      window.__confirmedRewardAmenity = '';
      switchToCashMode();
      closePointsRedemptionConfirm();
    });
    if (confirmBtn) confirmBtn.addEventListener('click', function(){
      setRedemptionConfirmed(true);
      window.__confirmedRewardAmenity = window.__rewardRedemptionAmenity || window.__confirmedRewardAmenity || '';
      closePointsRedemptionConfirm();
      if (typeof showVhecoRedemptionSuccess === 'function'){ showVhecoRedemptionSuccess(); }
      if (window.__rewardRedemptionProceed) {
        window.__rewardRedemptionProceed = false;
        if (typeof window.rewardRedemptionProceedToAmenity === 'function') {
          window.rewardRedemptionProceedToAmenity();
        }
      }
    });
  })();

  (function(){
    const modal = document.getElementById('switchToCashModal');
    const closeBtn = document.getElementById('switchToCashCloseBtn');
    const keepBtn = document.getElementById('switchToCashKeepBtn');
    const switchBtn = document.getElementById('switchToCashSwitchBtn');
    if (closeBtn) closeBtn.addEventListener('click', function(){
      closeSwitchToCashConfirm();
    });
    if (keepBtn) keepBtn.addEventListener('click', function(){
      closeSwitchToCashConfirm();
    });
    if (switchBtn) switchBtn.addEventListener('click', function(){
      setRedemptionConfirmed(false);
      closeSwitchToCashConfirm();
      switchToCashMode();
      if (typeof showVhecoRedemptionSuccess === 'function'){
        showVhecoRedemptionSuccess({
          heading: 'VHEcoPoint Redemption Cancelled',
          message: 'Redemption cancelled. Returning points to your balance.',
          icon: 'cancel'
        });
      }
    });
  })();

  (function(){
    if(currentUserType !== 'resident') return;
    const selectors=document.querySelectorAll('.participant-selector');
    if(!selectors.length) return;
    const residentsCountInput=document.getElementById('residentsCountInput');
    const guestsCountInput=document.getElementById('guestsCountInput');
    function updateCounts(){
      const amen=document.getElementById('amenityField')?.value||'';
      let rSel=0, gSel=0;
      const wrap=document.getElementById('participantWrap');
      const mode=wrap ? (wrap.getAttribute('data-mode')||'') : '';
      selectors.forEach(function(sel){
        const rGroup=sel.querySelector('.resident-group');
        const gGroup=sel.querySelector('.guest-group');
        if(rGroup && rGroup.style.display!=='none'){ rSel += sel.querySelectorAll('.resident-check:checked').length; }
        if(gGroup && gGroup.style.display!=='none'){
          if(mode==='resident_guest'){
            const gInput=document.getElementById('guestsCountInput');
            const val=Math.max(0, parseInt(gInput?.value||'0',10));
            gSel = Math.min(val, approvedGuestsMax>=0 ? approvedGuestsMax : val);
            sel.querySelectorAll('.guest-check').forEach(function(cb){ cb.checked=false; cb.disabled=true; });
          } else {
            const checks=sel.querySelectorAll('.guest-check');
            const selected=Array.from(checks).filter(cb=>cb.checked);
            if(approvedGuestsMax>=0 && selected.length>approvedGuestsMax){
              selected.slice(approvedGuestsMax).forEach(function(cb){ cb.checked=false; });
            }
            gSel = Math.min(selected.length, approvedGuestsMax>=0 ? approvedGuestsMax : selected.length);
            Array.from(checks).forEach(function(cb){
              cb.disabled = (!cb.checked && approvedGuestsMax>=0 && gSel>=approvedGuestsMax);
            });
          }
        }
      });
      if(residentsCountInput) residentsCountInput.value=String(rSel);
      if(guestsCountInput) guestsCountInput.value=String(gSel);
      const total=rSel+gSel;
      const pInput=document.getElementById('personsInput'); if(pInput){ pInput.value=String(total); }
      const pText=document.getElementById('personCount'); if(pText){ if('value' in pText){ pText.value=String(total); } else { pText.textContent=String(total); } }
      const max=getAmenityMaxPersons(amen);
      let hasPersonsError=false;
      if(total < 1){
        setFieldWarning('personsInput','At least 1 participant is required.');
        hasPersonsError=true;
      } else if(max!==Infinity && total>max){
        setFieldWarning('personsInput',`Maximum is ${max} persons.`);
        hasPersonsError=true;
      } else {
        clearParticipantsWarnings();
      }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      if(typeof persistForm === 'function') persistForm();
    }
    function applyModeTo(sel, m){
      const rGroup=sel.querySelector('.resident-group');
      const gGroup=sel.querySelector('.guest-group');
      if(!rGroup || !gGroup) return;
      const wrap=document.getElementById('participantWrap');
      if(wrap){ wrap.setAttribute('data-mode', m); }
      sel.querySelectorAll('.mode-radio-input').forEach(function(r){ r.checked = (r.value === m); });
      if(m==='resident_only'){
        rGroup.style.display='block';
        gGroup.style.display='none';
        const me=sel.querySelector('.resident-check')||null;
        if(me){ me.checked=true; }
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.checked=false; });
      } else if(m==='resident_guest'){
        rGroup.style.display='block';
        gGroup.style.display='block';
        const me=sel.querySelector('.resident-check')||null;
        if(me){ me.checked=true; }
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.checked=false; x.disabled=true; });
      } else {
        rGroup.style.display='none';
        gGroup.style.display='block';
        sel.querySelectorAll('.resident-check').forEach(function(x){ x.checked=false; });
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.disabled=false; });
      }
      updateCounts();
    }
    selectors.forEach(function(sel){
      sel.querySelectorAll('.mode-options [data-mode]').forEach(function(btn){
        btn.addEventListener('click',function(){
          applyModeTo(sel, btn.getAttribute('data-mode')||'resident_only');
        });
      });
      sel.querySelectorAll('.mode-radio-input').forEach(function(radio){
        radio.addEventListener('change',function(){
          if(radio.checked){
            const rb=sel.querySelector('.mode-options [data-mode="'+radio.value+'"]')||null;
            applyModeTo(sel, radio.value||'resident_only');
            if(rb){ rb.classList.add('is-active'); }
          }
        });
      });
      sel.addEventListener('change',function(e){
        if(e.target.classList.contains('resident-check')||e.target.classList.contains('guest-check')){
          updateCounts();
        }
      });
      applyModeTo(sel, 'resident_only');
    });
    updateCounts();
    updateParticipantVisibility();
  })();

  (function(){
    if(currentUserType === 'resident') return;
    const wrap=document.getElementById('participantWrap');
    if(!wrap) return;
    wrap.style.display='none';
    wrap.setAttribute('data-mode','guest_only');
    const bookingForField=document.getElementById('bookingForField');
    if(bookingForField) bookingForField.value='guest';
    const rInput=document.getElementById('residentsCountInput');
    const rText=document.getElementById('residentsCountText');
    const gInput=document.getElementById('guestsCountInput');
    const gText=document.getElementById('guestsCountText');
    const pInput=document.getElementById('personsInput');
    const pText=document.getElementById('personCount');
    if(rInput) rInput.value='0';
    if(rText) rText.textContent='0';
    const baseCount=Math.max(0, parseInt((pInput && pInput.value) || '0',10) || 0);
    if(gInput) gInput.value=String(baseCount);
    if(gText) gText.textContent=String(baseCount);
    if(pInput) pInput.value=String(baseCount);
    if(pText) pText.textContent=String(baseCount);
    const btn=wrap.querySelector('.mode-options [data-mode="guest_only"]');
    if(btn){
      btn.addEventListener('click',function(){
        wrap.setAttribute('data-mode','guest_only');
      });
    }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(typeof persistForm === 'function') persistForm();
    updateParticipantVisibility();
  })();

  (function(){
    const modal=document.getElementById('changeAmenityModal');
    if(!modal) return;
    const cancelBtn=document.getElementById('changeAmenityCancelBtn');
    const closeBtn=document.getElementById('changeAmenityCloseBtn');
    const confirmBtn=document.getElementById('changeAmenityConfirmBtn');
    if(cancelBtn){
      cancelBtn.addEventListener('click',function(){
        vpHideModal(modal);
      });
    }
    if(closeBtn){
      closeBtn.addEventListener('click',function(){
        vpHideModal(modal);
      });
    }
    if(confirmBtn){
      confirmBtn.addEventListener('click',function(){
        vpHideModal(modal);
        resetAmenitySelection();
      });
    }
  })();
  function showToast(message,type){
    const nl=document.getElementById('notifyLayer'); if(!nl) return;
    nl.innerHTML = '<span class="msg">'+message+'</span><button type="button" class="toast-close" aria-label="Close">\u00d7</button>';
    nl.style.background= type==='warning' ? '#8a2a2a' : type==='success' ? '#23412e' : '#345c40';
    nl.style.display='block';
    const btn=nl.querySelector('.toast-close'); if(btn){ btn.onclick=function(){ nl.style.display='none'; if(nl.__t){ clearTimeout(nl.__t); } }; }
    clearTimeout(nl.__t);
    nl.__t=setTimeout(function(){ nl.style.display='none'; }, 4000);
  }
  function updateActionStates(){
    const submitBtn=document.getElementById('submitBtn');
    const gate=document.getElementById('submitAllowed');
    const missing=(typeof getNextButtonMissingFields==='function')?getNextButtonMissingFields():[];
    const allowed=missing.length===0;
    if(submitBtn){
      if(allowed){
        submitBtn.classList.remove('disabled');
        submitBtn.removeAttribute('aria-disabled');
        submitBtn.removeAttribute('disabled');
        submitBtn.title='';
      } else {
        submitBtn.classList.add('disabled');
        submitBtn.setAttribute('aria-disabled','true');
        submitBtn.removeAttribute('disabled');
        submitBtn.title='Next is blocked - complete the highlighted fields to continue: ' + missing.join(', ');
      }
    }
    const sw=document.getElementById('submitWrap'); if(sw){ sw.style.display = 'flex'; }
    updateResProgress();
  }
  // Horizontal reservation progress tracker. Step 1 = amenity chosen,
  // Step 2 = schedule complete (same gate as the Next button), Step 3 = review/pay.
  // Horizontal reservation progress tracker (5 steps).
  // 1 Select Amenity, 2 Date & Time, 3 Participants, 4 Payment, 5 Downpayment / Confirm.
  function updateResProgress(){
    const root=document.getElementById('resProgress');
    if(!root) return;
    const get=function(id){ const el=document.getElementById(id); return el?String(el.value||''):''; };
    const amen=get('amenityField').trim();
    const s=get('startDateInput'), eD=get('endDateInput'), st=get('startTimeInput'), et=get('endTimeInput');
    const persons=parseInt(get('personsInput')||'0',10)||0;
    const hours=parseInt(get('hoursInput')||'0',10)||0;
    const max=(typeof getAmenityMaxPersons==='function')?getAmenityMaxPersons(amen):Infinity;

    const amenityDone=amen!=='';

    let scheduleDone=false;
    if(amenityDone && s && eD && st && et){
      const dateOk=(typeof minDateStr==='undefined' || s>=minDateStr) && eD>=s && Math.floor((new Date(eD)-new Date(s))/86400000)<=6;
      const sm=parseInt(st.split(':')[0],10)*60+parseInt(st.split(':')[1]||'0',10);
      const em=parseInt(et.split(':')[0],10)*60+parseInt(et.split(':')[1]||'0',10);
      const timeOk=em>sm;
      const hourOk=(typeof isHourBasedAmenity!=='function' || !isHourBasedAmenity(amen)) || hours>=1;
      scheduleDone=dateOk && timeOk && hourOk;
    }

    const guestsDone=scheduleDone && persons>=1 && (max===Infinity || persons<=max);

    const pointsToggle=document.getElementById('use-points-toggle');
    const pointsActive=!!(pointsToggle && pointsToggle.checked);
    const confirmed=(typeof redemptionConfirmed!=='undefined') ? !!redemptionConfirmed : true;
    const missing=(typeof getNextButtonMissingFields==='function')?getNextButtonMissingFields():[];
    const formReady=missing.length===0 && (!pointsActive || confirmed);
    const paymentDone=guestsDone && formReady;

    const states={1:'is-active',2:'',3:'',4:'',5:''};
    if(amenityDone){ states[1]='is-complete'; states[2]='is-active'; }
    if(scheduleDone){ states[2]='is-complete'; states[3]='is-active'; }
    if(guestsDone){ states[3]='is-complete'; states[4]='is-active'; }
    if(paymentDone){ states[4]='is-complete'; states[5]='is-active'; }

    const map={1:'rp-step-amenity',2:'rp-step-datetime',3:'rp-step-participants',4:'rp-step-payment',5:'rp-step-final'};
    for(let n=1;n<=5;n++){
      const el=document.getElementById(map[n]);
      if(!el) continue;
      el.classList.remove('is-active','is-complete');
      if(states[n]) el.classList.add(states[n]);
    }
    let activeStep=1;
    for(let n=1;n<=5;n++){ if(states[n]==='is-active'){ activeStep=n; break; } }
    root.setAttribute('data-current',String(activeStep));
    root.classList.toggle('is-points',pointsActive);
  }
  function persistForm(){
    try{
      const data={
        amenity:document.getElementById('amenityField').value||'',
        start_date:document.getElementById('startDateInput').value||'',
        end_date:document.getElementById('endDateInput').value||'',
        start_time:document.getElementById('startTimeInput').value||'',
        end_time:document.getElementById('endTimeInput').value||'',
        persons:document.getElementById('personsInput').value||'0',
        hours:document.getElementById('hoursInput')?.value||'',
        downpayment:document.getElementById('downpaymentInput')?.value||'',
        booking_for:document.getElementById('bookingForField')?.value||'',
        guest_id:document.getElementById('guestIdField')?.value||'',
        guest_ref:document.getElementById('guestRefField')?.value||'',
        residents_count:document.getElementById('residentsCountInput')?.value||'',
        guests_count:document.getElementById('guestsCountInput')?.value||''
      };
      sessionStorage.setItem('reserve_form', JSON.stringify(data));
    }catch(_){}
  }
  function restoreFormFromSession(){
    try{
      if(sessionStorage.getItem('reserve_completed')==='1' || sessionStorage.getItem('reserve_submitted')==='1'){ return; }
      const raw=sessionStorage.getItem('reserve_form'); if(!raw) return;
      const data=JSON.parse(raw||'{}');
      if(data.amenity){ document.getElementById('amenityField').value=data.amenity; }
      if(data.start_date){ document.getElementById('startDateInput').value=data.start_date; }
      if(data.end_date){ document.getElementById('endDateInput').value=data.end_date; }
      if(data.start_time){ document.getElementById('startTimeInput').value=data.start_time; }
      if(data.end_time){ document.getElementById('endTimeInput').value=data.end_time; }
      if(data.persons){
        document.getElementById('personsInput').value=data.persons;
        const pcR=document.getElementById('personCount'); if(pcR){ if('value' in pcR){ pcR.value=String(data.persons); } else { pcR.textContent=String(data.persons); } }
        const rtcR=document.getElementById('reserveTotalCount'); if(rtcR){ if('value' in rtcR){ rtcR.value=String(data.persons); } else { rtcR.textContent=String(data.persons); } }
      }
      if(data.booking_for){
        const bookingForField=document.getElementById('bookingForField');
        if(bookingForField) bookingForField.value=data.booking_for;
      }
      if(data.guest_id){
        const guestIdField=document.getElementById('guestIdField');
        if(guestIdField) guestIdField.value=data.guest_id;
      }
      if(data.guest_ref){
        const guestRefField=document.getElementById('guestRefField');
        if(guestRefField) guestRefField.value=data.guest_ref;
      }
      if(data.residents_count && document.getElementById('residentsCountInput')){
        document.getElementById('residentsCountInput').value=data.residents_count;
        const rt=document.getElementById('residentsCountText'); if(rt){ rt.textContent=String(data.residents_count); }
      }
      if(data.guests_count && document.getElementById('guestsCountInput')){
        document.getElementById('guestsCountInput').value=data.guests_count;
        const gt=document.getElementById('guestsCountText'); if(gt){ gt.textContent=String(data.guests_count); }
      }
      if(data.hours && document.getElementById('hoursInput')){ document.getElementById('hoursInput').value=data.hours; const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(data.hours); } }
      if(data.downpayment && document.getElementById('downpaymentInput')){ document.getElementById('downpaymentInput').value=data.downpayment; }
      configureFieldsForAmenity(document.getElementById('amenityField').value);
      const amenName=(document.getElementById('amenityField').value||'');
      const key = amenName==='Clubhouse' ? 'clubhouse' : amenName==='Multi-Purpose Building' ? 'multipurpose' : amenName==='Basketball Court' ? 'basketball' : amenName==='Tennis Court' ? 'tennis' : '';
      document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card){ card.classList.add('selected'); }
      refreshPricingForBookingFor();
      updateParticipantVisibility();
      selectedAmenity = document.getElementById('amenityField').value || '';
      refreshAvailabilityFromServer();
    }catch(_){}
  }
  // Once a reservation is completed (the user reached the downpayment /
  // success page), drop the persisted form and reset the progress steps so the
  // next visit starts clean instead of showing the previous booking as done.
  // Post-booking pages set sessionStorage 'reserve_completed' = '1'.
  function vpConsumeReservationCompletion(){
    let completed=false;
    try{ completed = sessionStorage.getItem('reserve_completed') === '1' || sessionStorage.getItem('reserve_submitted') === '1'; }catch(_){}
    if(!completed) return false;
    try{ sessionStorage.removeItem('reserve_completed'); sessionStorage.removeItem('reserve_submitted'); sessionStorage.removeItem('reserve_form'); }catch(_){}
    if(typeof clearBookingFormState === 'function'){ clearBookingFormState(); }
    else {
      if(typeof resetReservationForm === 'function'){ resetReservationForm(); }
      if(typeof updateActionStates === 'function'){ updateActionStates(); }
      if(typeof updateBookingSummary === 'function'){ updateBookingSummary(); }
    }
    return true;
  }
  document.addEventListener('DOMContentLoaded',function(){
    vpConsumeReservationCompletion();
  });
  ['amenityField','startDateInput','endDateInput','startTimeInput','endTimeInput','personsInput','hoursInput','downpaymentInput'].forEach(id=>{const el=document.getElementById(id); if(el){ el.addEventListener('input',function(){ markDirty(id); persistForm(); updateActionStates(); showIncompleteWarnings(false); if(typeof updateBookingSummary === 'function'){ updateBookingSummary(); } }); }});
  async function refreshAvailabilityFromServer(){
    selectedAmenity = document.getElementById('amenityField').value || '';
    availabilityCache.clear();
    await loadBookedDates(true);
    computeAvailability();
    renderTimeSlotButtons();
    if(document.getElementById('startDateInput').value){ checkTimeAvailability(); }
  }
  document.addEventListener('DOMContentLoaded',function(){ restoreFormFromSession(); if(currentUserType === 'resident'){ const participantField=document.getElementById('reserveTotalCount'); setReserveTotalCount(participantField ? participantField.value : 0); } updateActionStates(); updateDisplayedPrice(); updateDownpaymentSuggestion(); updateBookingSummary(); initSingleDayToggle(); updateHoursSelectEnabled(); try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){} });
  let lastAvailabilityRefresh=0;
  function refreshAvailabilityDebounced(force){
    const now=Date.now();
    if(force || (now - lastAvailabilityRefresh) > 30000){
      lastAvailabilityRefresh=now;
      refreshAvailabilityFromServer();
    }
  }
  window.addEventListener('pageshow',function(e){
    vpConsumeReservationCompletion();
    if(typeof updateActionStates === 'function'){ updateActionStates(); }
    refreshAvailabilityDebounced(e && e.persisted);
  });
  window.addEventListener('focus',function(){ refreshAvailabilityDebounced(false); });
  // Availability goes stale while the page sits open: other guests book, and
  // holds expire at their 10-minute mark. Refetch dates + time slots on a
  // fixed cadence (only while the tab is visible) and whenever the tab returns
  // to the foreground, so the calendar and slot buttons update in place.
  document.addEventListener('visibilitychange',function(){
    if(document.visibilityState === 'visible'){ refreshAvailabilityFromServer(); }
  });
  setInterval(function(){
    if(document.visibilityState === 'visible'){ refreshAvailabilityFromServer(); }
  }, 20000);
  // Lets the hold timer / verify-modal close paths refresh availability right
  // after a hold is released or expires (defined before the hold IIFE runs).
  window.__vpRefreshAvailability = function(){
    try{ refreshAvailabilityFromServer(); }catch(_){ }
  };
  window.addEventListener('storage',function(e){
    try{
      if(!e || !e.key) return;
      if(String(e.key).indexOf('cancelled:')===0){
        refreshAvailabilityFromServer();
      }
    }catch(_){}
  });
  document.addEventListener('DOMContentLoaded',function(){ const s=document.getElementById('startTimeInput'); const e=document.getElementById('endTimeInput'); if(s){ s.value=''; } if(e){ e.value=''; } });
  document.addEventListener('DOMContentLoaded',function(){
     const hs=document.getElementById('hoursSelect');
     if(hs){
       const check=function(e){
         if(!requireDateBeforeHours()){
           e.preventDefault();
           e.stopPropagation();
           this.blur();
           return false;
         }
       };
       hs.addEventListener('mousedown', check);
       hs.addEventListener('click', check);
     }
     ['startDateInput','endDateInput'].forEach(function(id){
       const el=document.getElementById(id);
       if(el){
         el.addEventListener('input', function(){
            const s=document.getElementById('startDateInput').value;
            const e=document.getElementById('endDateInput').value;
            if(s && e){
              setFieldWarning('hoursInput','');
            }
         });
       }
     });
   });
  document.addEventListener('DOMContentLoaded',function(){
    var panel=document.querySelector('.booking-steps');
    var toggle=document.getElementById('bookingStepsToggle');
    if(panel&&toggle){
      toggle.addEventListener('click',function(){
        var collapsed=panel.classList.toggle('is-collapsed');
        toggle.textContent=collapsed?'+':'−';
        toggle.setAttribute('aria-expanded',collapsed?'false':'true');
      });
    }
  });
  function goBack(){ persistForm(); if(document.referrer){ window.history.back(); } else { window.location.href = 'mainpage.php'; } }
  function closeModal(){document.getElementById('refModal').style.display='none'}
  function closeHint(){document.getElementById('hintModal').style.display='none'}
</script>
<?php if ($resetReservation) { ?>
<script>
  (function(){
    try{ sessionStorage.removeItem('reserve_form'); }catch(_){}
    if(typeof clearBookingFormState === 'function'){
      document.addEventListener('DOMContentLoaded', function(){
        clearBookingFormState();
        if(typeof updateBookingSummary === 'function'){ updateBookingSummary(); }
        if(typeof updateActionStates === 'function'){ updateActionStates(); }
      });
    }
    document.addEventListener('DOMContentLoaded', function(){
      var modal = document.getElementById('resetReservationModal');
      var okBtn = document.getElementById('resetReservationOkBtn');
      var closeBtn = document.getElementById('resetReservationCloseBtn');
      if(modal){ vpShowModal(modal); }
      if(okBtn){ okBtn.addEventListener('click', function(){ if(modal) vpHideModal(modal); }); }
      if(closeBtn){ closeBtn.addEventListener('click', function(){ if(modal) vpHideModal(modal); }); }
      if(modal){ modal.addEventListener('click', function(e){ if(e.target === modal){ vpHideModal(modal); } }); }
    });
  })();
</script>
<?php } ?>

<script>
  function formatTimeSlot(h){ const ampm = h>=12 ? 'PM' : 'AM'; let hh=h%12; if(hh===0) hh=12; return `${hh}:00 ${ampm}`; }
  function formatTimeHM(h,m){ const ap=h>=12?'PM':'AM'; let hh=h%12; if(hh===0) hh=12; const mm=String(m).padStart(2,'0'); return `${hh}:${mm} ${ap}`; }
  function generateTimeSlots(amenity){ const hrs=getAmenityHours(amenity); const min=parseInt(hrs.min.split(':')[0],10); const max=parseInt(hrs.max.split(':')[0],10); const out=[]; for(let h=min; h<max; h++){ out.push({ label: formatTimeSlot(h), value: `${String(h).padStart(2,'0')}:00` }); } return out; }
  function computeMaxDuration(amenity,startHour,booked,selectedDate){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); let max=0; for(let h=1; startHour+h<=maxHour; h++){ const thisStart=`${String(startHour).padStart(2,'0')}:00`; const thisEnd=`${String(startHour+h).padStart(2,'0')}:00`; const sM=toMinutes(thisStart), eM=toMinutes(thisEnd); const overlaps=(booked||[]).some(function(t){ if(selectedDate && t.start_date && t.end_date && (t.end_date < selectedDate || t.start_date > selectedDate)) return false; if(!t.has_time){ const bS=parseInt(hrs.min.split(':')[0],10); return !(eM<=bS || sM>=maxHour); } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); if(overlaps) break; max=h; } return max; }
  function isSlotTakenByBooking(amenity,startHour,requestedHours,booked,selectedDate){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); const wHours=Math.max(1,requestedHours||1); const sM=toMinutes(`${String(startHour).padStart(2,'0')}:00`); const eM=toMinutes(`${String(startHour+wHours).padStart(2,'0')}:00`); return (booked||[]).some(function(t){ if(selectedDate && t.start_date && t.end_date && (t.end_date < selectedDate || t.start_date > selectedDate)) return false; if(!t.has_time){ const bS=parseInt(hrs.min.split(':')[0],10); return !(eM<=bS || sM>=maxHour); } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); }
  function isHoldEntry(t){ return !!(t && (t.hold === 1 || t.hold === true || t.status === 'HELD')); }
  function slotOverlapSome(amenity,startHour,requestedHours,booked,selectedDate,pred){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); const wHours=Math.max(1,requestedHours||1); const sM=toMinutes(`${String(startHour).padStart(2,'0')}:00`); const eM=toMinutes(`${String(startHour+wHours).padStart(2,'0')}:00`); return (booked||[]).some(function(t){ if(!pred(t)) return false; if(selectedDate && t.start_date && t.end_date && (t.end_date < selectedDate || t.start_date > selectedDate)) return false; if(!t.has_time){ const bS=parseInt(hrs.min.split(':')[0],10); return !(eM<=bS || sM>=maxHour); } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); }
  // True only when the overlap comes EXCLUSIVELY from other guests' live holds
  // (own hold entries are never returned by the API) - a real paid/pending
  // booking wins the message when both overlap.
  function isSlotHeldByOthers(amenity,startHour,requestedHours,booked,selectedDate){ return slotOverlapSome(amenity,startHour,requestedHours,booked,selectedDate,isHoldEntry) && !slotOverlapSome(amenity,startHour,requestedHours,booked,selectedDate,function(t){ return !isHoldEntry(t); }); }

  function renderHoursChipsForAmenity(){ const amen=document.getElementById('amenityField').value; const dc=document.getElementById('durationContainer'); const lbl=document.getElementById('hoursSectionLabel'); if(!dc) return; dc.innerHTML=''; if(!isHourBasedAmenity(amen)){ dc.style.display='none'; if(lbl) lbl.style.display='none'; return; } dc.style.display='flex'; if(lbl) lbl.style.display='block'; dc.style.flexWrap='wrap'; dc.style.gap='8px'; dc.style.margin='8px 0 0 0'; const maxH=(amen==='Clubhouse' || amen==='Multi-Purpose Building')?12:9; for(let h=1; h<=maxH; h++){ const b=document.createElement('button'); b.type='button'; b.className='dur-btn'; b.textContent=`${h}h`; b.dataset.hours=String(h); b.onclick=function(){ selectDuration(h); }; dc.appendChild(b); } const currentH=parseInt(document.getElementById('hoursInput').value||'',10); if(currentH){ const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(currentH)); if(sel) sel.classList.add('selected'); } }

  function renderTimeSlotButtons(){
    const amen=document.getElementById('amenityField').value;
    const container=document.getElementById('timeSlotContainer');
    const tLbl=document.getElementById('timeSectionLabel');
    const notice=document.getElementById('availabilityNotice');
    if(!container) return;
    container.innerHTML='';
    if(notice){ notice.style.display='none'; notice.textContent=''; notice.classList.remove('notice-available','notice-partly','notice-disabled'); }
    const slotErrReset=document.getElementById('timeSlotError'); if(slotErrReset){ slotErrReset.style.display='none'; slotErrReset.textContent=''; }

    if(!isHourBasedAmenity(amen)){
      container.style.display='none';
      if(tLbl) tLbl.style.display='none';
      return;
    }
    const slots=generateTimeSlots(amen);
    const date=document.getElementById('startDateInput').value;
    const hours=parseInt(document.getElementById('hoursInput').value||'0',10);
    const hoursChosenEl=document.getElementById('hoursChosen');
    const hasChosenHours=hoursChosenEl && hoursChosenEl.value==='1';
    if(!date){
      container.style.display='none';
      if(tLbl) tLbl.style.display='none';
      return;
    }
    container.style.display='grid';
    try{
      const w = window.innerWidth || document.documentElement.clientWidth || 1366;
      let cols = 5;
      if(w < 1280) cols = 4;
      if(w < 1100) cols = 3;
      if(w < 860) cols = 2;
      container.style.gridTemplateColumns = `repeat(${cols},minmax(0,1fr))`;
    }catch(_){
      container.style.gridTemplateColumns='repeat(5,minmax(0,1fr))';
    }
    container.style.gap='8px';
    container.style.margin='8px 0 0 0';
    if(tLbl) tLbl.style.display='block';
    if(!hasChosenHours){
      slots.forEach(function(slot){
        const btn=document.createElement('button');
        btn.type='button';
        btn.className='slot-btn unavailable';
        btn.textContent=slot.label;
        btn.onclick=function(){
displaySlotError('Please select the number of hours before choosing a start time.');
        };
        container.appendChild(btn);
      });
      return;
    }
    window.__slotRenderTokenCounter=(window.__slotRenderTokenCounter||0)+1; const __token=window.__slotRenderTokenCounter; window.__activeSlotRenderToken=__token; if(!date){ container.innerHTML=''; if(notice){ notice.style.display='none'; notice.textContent=''; notice.classList.remove('notice-available','notice-partly','notice-disabled'); } const sErr2=document.getElementById('timeSlotError'); if(sErr2){ sErr2.style.display='none'; sErr2.textContent=''; } return; } fetchBookedTimesFor(date).then(data=>{ if(window.__activeSlotRenderToken!==__token) return; const booked=data.times||[]; window.__bookedTimesForDate=booked||[]; let anyEnabled=false; let disabledCount=0; slots.forEach(slot=>{ const startHour=parseInt(slot.value.split(':')[0],10); const maxPossible=computeMaxDuration(amen,startHour,booked,date); const serverTaken=slotOverlapSome(amen,startHour,hours,booked,date,function(t){ return !isHoldEntry(t); }); const eventBooked=serverTaken && (window.__vpWaitlistBookedSlots||[]).some(function(item){ if(item.amenity!==amen || date<item.start_date || date>item.end_date) return false; if(!item.start_time || !item.end_time) return true; const slotStart=startHour*60; const slotEnd=(startHour+Math.max(1,hours))*60; return slotStart<toMinutes(item.end_time) && slotEnd>toMinutes(item.start_time); }); const valid=(maxPossible>=hours); const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb'; btn.textContent=eventBooked ? slot.label+' \u2014 Booked' : slot.label; btn.dataset.slot=slot.value; if(!valid){ disabledCount++; const taken=eventBooked || isSlotTakenByBooking(amen,startHour,hours,booked,date); const held=!eventBooked && taken && isSlotHeldByOthers(amen,startHour,hours,booked,date); btn.classList.add(taken?'taken':'unavailable'); btn.setAttribute('aria-disabled','true'); if(eventBooked){ btn.disabled=true; btn.dataset.status='booked'; btn.title='Booked \u2014 this queued slot has been taken.'; } else if(held){ btn.dataset.status='held'; btn.title='On hold \u2014 another guest is holding this slot while their timer runs.'; } btn.onclick=function(){ showToast(eventBooked ? 'This slot is booked. Please choose another time.' : held ? 'This start time is on hold for another guest while their timer runs. Please select a different start time or date.' : taken ? 'This start time has already been booked by another guest. Please select a different start time or date.' : 'This start time cannot accommodate the selected duration. Please choose a different start time or duration.','warning'); }; } else { anyEnabled=true; btn.classList.add('available'); btn.onclick=function(){ selectTimeSlot(slot.value); }; } container.appendChild(btn); }); let hasBookedHours=false; (booked||[]).forEach(function(t){ if(!t.has_time){ hasBookedHours=true; return; } const bS=parseInt(String(t.start).split(':')[0],10); const bE=parseInt(String(t.end).split(':')[0],10); if(bE>bS){ hasBookedHours=true; } }); if(notice){ notice.classList.remove('notice-available','notice-partly','notice-disabled'); if(hasBookedHours && !anyEnabled){ notice.style.display='block'; notice.textContent='Fully Booked — no start times are available for this date.'; notice.classList.add('notice-disabled'); } else if(hasBookedHours && disabledCount>0){ notice.style.display='block'; notice.textContent='Partially Booked — some start times are unavailable.'; notice.classList.add('notice-partly'); } else { notice.style.display='block'; notice.textContent='All start times are available — please select your preferred start time.'; notice.classList.add('notice-available'); } } if(!anyEnabled){ showTimeError('No start times are available for the selected duration. Please adjust the number of hours.'); } else { showTimeError(''); } const st=document.getElementById('startTimeInput').value; if(st){ const selBtn=Array.from(container.children).find(b=>b.tagName==='BUTTON' && b.dataset.slot===st); if(selBtn && selBtn.classList.contains('available')){ selBtn.classList.add('selected'); window.__vpLastClearedSlot=null; } else if(selBtn){ document.getElementById('startTimeInput').value=''; const et=document.getElementById('endTimeInput'); if(et){ et.value=''; } const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.style.display='none'; tr.innerHTML=''; } const tsn=document.getElementById('selectedTimeNote'); if(tsn){ tsn.style.display='none'; } displaySlotError('Your previously selected start time is no longer available. Please choose another start time.'); if(window.__vpLastClearedSlot!==st){ window.__vpLastClearedSlot=st; showToast('<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Your previously selected start time is no longer available. Please choose another start time.','warning'); } if(typeof markDirty==='function'){ markDirty('startTimeInput'); markDirty('endTimeInput'); } if(typeof persistForm==='function'){ persistForm(); } if(typeof updateSelectedTimeRange==='function'){ updateSelectedTimeRange(); } if(typeof updateBookingSummary==='function'){ updateBookingSummary(); } } } updateActionStates(); }); }

  function selectTimeSlot(start){ const hInput=document.getElementById('hoursInput'); const hrs=parseInt(hInput?.value||'0',10); if(!hrs || hrs<1){ showTimeError('Please select the number of hours before choosing a start time.'); return; } const amen=document.getElementById('amenityField').value; const booked=window.__bookedTimesForDate||[]; const startHour=parseInt(start.split(':')[0],10); const selDate=document.getElementById('startDateInput')?.value||''; if(computeMaxDuration(amen,startHour,booked,selDate) < Math.max(1,hrs)){ displaySlotError('This start time cannot accommodate the selected duration. Please choose a different start time or duration.'); showToast(`<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Not enough consecutive open hours remain from this start time to accommodate ${hrs} hour${hrs>1?'s':''}.`,'warning'); return; } document.getElementById('startTimeInput').value=start; computeEndTimeFromHours(); const sh=startHour, eh=sh+hrs; const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeSlot(sh)+' - '+formatTimeSlot(eh); tr.style.display='block'; } const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='none'; } const cont=document.getElementById('timeSlotContainer'); if(cont){ Array.from(cont.querySelectorAll('.slot-btn')).forEach(function(b){ b.classList.remove('selected'); }); const sel=Array.from(cont.querySelectorAll('.slot-btn')).find(function(b){ return b.dataset.slot===start; }); if(sel){ sel.classList.add('selected'); } } displaySlotError(''); showTimeError(''); updateActionStates(); showIncompleteWarnings(false); }
  function renderHoursDropdownForAmenity(){
    const amen=document.getElementById('amenityField').value;
    const sel=document.getElementById('hoursSelect');
    const lbl=document.getElementById('hoursSectionLabel');
    if(!sel) return;
    sel.innerHTML='';
    if(!isHourBasedAmenity(amen)){ sel.style.display='none'; if(lbl) lbl.style.display='none'; return; }
    sel.style.display='inline-block'; if(lbl) lbl.style.display='block';
    const blankOpt=document.createElement('option'); blankOpt.value=''; blankOpt.textContent='Select hours'; blankOpt.disabled=true; blankOpt.selected=true; sel.appendChild(blankOpt);
    const maxH=(amen==='Clubhouse' || amen==='Multi-Purpose Building')?12:9;
    for(let h=1; h<=maxH; h++){ const opt=document.createElement('option'); opt.value=String(h); opt.textContent=`${h} hour${h>1?'s':''}`; sel.appendChild(opt); }
    const currentH=parseInt(document.getElementById('hoursInput').value||'',10);
    if(currentH) sel.value=String(currentH);
  }

  function decorateSlotButtons(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const amen=document.getElementById('amenityField').value;
    const hours=parseInt(document.getElementById('hoursInput').value||'0',10);
    const booked=window.__bookedTimesForDate||[];
    const selectedDate=document.getElementById('startDateInput').value||'';
    const now = new Date();
    const todayStrLocal = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    const currentHour=now.getHours();
    const currentMinute=now.getMinutes();
    const hrs=getAmenityHours(amen);
    const closingSlot=formatTimeSlot(parseInt((hrs.max||'18:00').split(':')[0],10));
    Array.from(container.querySelectorAll('.slot-btn')).forEach(function(btn){
      const ds=btn.dataset.slot; if(!ds) return;
      const sh=parseInt(ds.split(':')[0],10);
      const maxPossible=computeMaxDuration(amen,sh,booked,selectedDate);
      const isPastOnToday = (selectedDate===todayStrLocal) && (sh<currentHour || (sh===currentHour && currentMinute>0));
      btn.classList.remove('available','unavailable','taken');
      if(btn.disabled || isPastOnToday || hours<1 || maxPossible<Math.max(1,hours)){
        btn.disabled=true;
        btn.dataset.past = isPastOnToday ? '1' : '0';
        if(isPastOnToday){ btn.classList.add('unavailable'); btn.title='This time slot has already passed for today.'; }
        else if(hours<1){ btn.classList.add('unavailable'); btn.title='Please select the number of hours before choosing a start time.'; }
        else if(isSlotTakenByBooking(amen,sh,Math.max(1,hours),booked,selectedDate)){ btn.classList.add('taken'); btn.title='Already booked by another guest — please select a different start time or date.'; }
        else { btn.classList.add('unavailable'); btn.title='This amenity closes at '+closingSlot+'. A '+hours+'-hour reservation would exceed the closing time. Please select an earlier start time or reduce the number of hours.'; }
      } else {
        btn.classList.add('available');
        btn.title='';
      }
    });
    const legendEl=document.getElementById('slotLegend');
    if(legendEl){
      const hasTaken=Array.from(container.querySelectorAll('.slot-btn')).some(function(bb){ return bb.classList.contains('taken'); });
      legendEl.style.display = hasTaken ? 'flex' : 'none';
    }
  }

  function hideSlotError(){
    const el=document.getElementById('timeSlotError');
    if(!el) return;
    el.style.display='none';
  }

  function displaySlotError(msg){
    const el=document.getElementById('timeSlotError');
    if(!el) return;
    if(msg){
      el.innerHTML='';
      const m=document.createElement('span');
      m.className='msg';
      m.textContent=msg;
      el.appendChild(m);
      const close=document.createElement('button');
      close.className='close-warn';
      close.type='button';
      close.textContent='\u00d7';
      close.setAttribute('aria-label','Dismiss');
      close.addEventListener('click',function(){ hideSlotError(); });
      el.appendChild(close);
      el.style.display='flex';
    } else {
      el.innerHTML='';
      el.style.display='none';
    }
  }

  (function observeSlotContainer(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const obs=new MutationObserver(function(){ decorateSlotButtons(); });
    obs.observe(container,{childList:true});
    container.addEventListener('pointerdown',function(e){ const b=e.target.closest('.slot-btn'); if(!b) return; if(b.disabled || b.classList.contains('unavailable') || b.classList.contains('taken')){ const isPast=(b.dataset.past==='1'); const msg = isPast ? 'This time slot has already passed for today and cannot be booked.' : (b.classList.contains('taken') ? 'Already booked by another guest — please select a different start time or date.' : (b.title || 'This start time cannot accommodate the selected duration. Please choose an earlier start time or reduce the number of hours.')); displaySlotError(msg); e.preventDefault(); } });
  })();

</script>

<style>
/* Your Points card: VHEcoPoint branding, matches the dashboard Current Point Balance card */
.points-tracker {
  width: 100%;
  max-width: 860px;
  margin: 0 auto;
  background: linear-gradient(135deg, #0f2f27 0%, #1a5240 100%);
  border: 1px solid rgba(212,175,55,0.4);
  color: #fde886;
  padding: 14px 18px;
  border-radius: 14px;
  box-shadow: 0 4px 16px rgba(10,30,22,0.15);
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  align-items: center;
  gap: 6px 18px;
  font-family: 'Poppins', sans-serif;
}

.points-tracker-header {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  min-width: 0;
}

.points-tracker-label {
  font-size: .78rem;
  font-weight: 700;
  opacity: 0.95;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: rgba(253,232,134,0.8);
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.points-tracker-label i {
  font-size: .9em;
  opacity: 0.7;
}

.points-balance-amount {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-top: 4px;
  line-height: 1.15;
  min-width: 0;
}

.points-balance-star {
  font-size: 0.55em;
  color: #fde886;
  opacity: 0.75;
  flex-shrink: 0;
  transform: translateY(-0.05em);
}

.points-balance-number {
  font-weight: 800;
  color: #fde886;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: clip;
  max-width: 100%;
  flex: 0 1 auto;
}

.points-balance-limit {
  font-size: 0.95rem;
  font-weight: 600;
  color: rgba(253,232,134,0.5);
  white-space: nowrap;
  flex-shrink: 0;
}

.points-tracker-note {
  font-size: 0.8rem;
  line-height: 1.4;
  font-weight: 500;
  color: rgba(240,235,226,0.7);
  min-width: 0;
  padding: 4px 2px;
}

.points-tracker-body {
  min-width: 0;
}

.points-tracker-toggle {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 1px solid rgba(253,232,134,0.35);
  background: rgba(253,232,134,0.14);
  color: #fde886;
  font-size: .95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  line-height: 1;
  flex-shrink: 0;
  transition: all 0.2s;
}

.points-tracker-toggle:hover {
  background: rgba(253,232,134,0.3);
  color: #fff;
}

.points-tracker.is-collapsed .points-tracker-body,
.points-tracker.is-collapsed .points-tracker-note {
  display: none;
}

.view-rewards-btn {
  position: relative;
  padding: 8px 16px;
  border-radius: 10px;
  background: rgba(253,232,134,0.12);
  border: 1px solid rgba(212,175,55,0.6);
  color: #fde886;
  font-size: .8rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}

.view-rewards-btn:hover {
  background: rgba(253,232,134,0.22);
  border-color: #fde886;
  color: #fff;
}

.view-rewards-btn:focus-visible {
  outline: 2px solid #fde886;
  outline-offset: 2px;
}

.view-rewards-btn-badge {
  position: absolute;
  top: -8px;
  right: -8px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  box-sizing: border-box;
  border-radius: 999px;
  background: #dc2626;
  color: #fff;
  font-size: 0.65rem;
  font-weight: 800;
  line-height: 14px;
  text-align: center;
  border: 2px solid #0f2f27;
  box-shadow: 0 1px 3px rgba(0,0,0,0.35);
  pointer-events: none;
  z-index: 2;
}

@media (max-width: 1023px) {
  .points-tracker {
    grid-template-columns: 1fr auto;
    gap: 10px 14px;
    max-width: 720px;
  }
  .points-tracker-header { grid-column: 1; grid-row: 1; }
  .points-tracker-toggle { grid-column: 2; grid-row: 1; }
  .points-tracker-note { grid-column: 1 / -1; grid-row: 2; width: 100%; }
  .points-tracker-body { grid-column: 1 / -1; grid-row: 3; width: 100%; }
  .view-rewards-btn { width: 100%; }
}

/* Points redemption sidebar (center modal) */
.points-redemption-sidebar {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.8);
  width: 90%;
  max-width: 420px;
  max-height: 85vh;
  background: white;
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  z-index: 1001;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s ease;
  overflow-y: auto;
}
.points-redemption-sidebar.open {
  transform: translate(-50%, -50%) scale(1);
  opacity: 1;
  visibility: visible;
}
.sidebar-header {
  background: #23412e;
  color: white;
  padding: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.sidebar-header h3 {
  margin: 0;
}
.sidebar-close-btn {
  background: none;
  border: none;
  color: white;
  font-size: 24px;
  cursor: pointer;
}
.sidebar-content {
  padding: 20px;
}
.redemption-amenity-card {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
  display: flex;
  gap: 16px;
  cursor: pointer;
  transition: all 0.2s;
}
.redemption-amenity-card:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.redemption-amenity-card img {
  width: 100px;
  height: 75px;
  object-fit: cover;
  border-radius: 8px;
}
.redemption-amenity-info h4 {
  margin: 0 0 8px 0;
}
.redemption-amenity-info .points-cost {
  color: #23412e;
  font-weight: 700;
}

/* Overlay when sidebar is open */
.sidebar-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 1000;
  display: none;
}
.sidebar-overlay.open {
  display: block;
}
/* Points error popup (responsive) */
.points-error-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 10000;
  display: none;
}
.points-error-overlay.open {
  display: block;
}
.points-error-popup {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.95);
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.25);
  z-index: 10001;
  max-width: 92vw;
  width: 420px;
  padding: 0;
  opacity: 0;
  visibility: hidden;
  transition: all 0.18s ease;
  overflow: hidden;
}
.points-error-popup.open {
  transform: translate(-50%, -50%) scale(1);
  opacity: 1;
  visibility: visible;
}
.points-error-inner { padding: 18px; }
.points-error-title { margin: 0 0 10px 0; color: #dc2626; font-size: 1.05rem; }
.points-error-message { margin: 0; color: #374151; line-height: 1.45; }
.points-error-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 16px;
}
.points-error-pay-normal {
  padding: 10px 20px;
  border-radius: 8px;
  border: 2px solid #16a34a;
  background: #d1fae5;
  color: #065f46;
  font-weight: 700;
  cursor: pointer;
  width: 100%;
  font-family: 'Poppins', sans-serif;
  font-size: 0.9rem;
}
.points-error-close {
  margin-top: 0;
  width: 100%;
  padding: 10px 20px;
  border-radius: 10px;
  border: 2px solid #dc2626;
  background: #fee2e2;
  color: #991b1b;
  font-weight: 700;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  font-size: 0.9rem;
}
@media (max-width: 480px) {
  .points-error-popup { width: 94vw; padding: 0; border-radius: 12px; }
  .points-error-inner { padding: 14px; }
  .points-error-title { font-size: 1rem; }
  .points-error-actions { flex-direction: column; }
  .points-error-pay-normal, .points-error-close { width: 100%; }
}
</style>


<script>
// Tracker toggle functionality
const tracker = document.getElementById('pointsTracker');
const trackerToggleBtn = document.getElementById('trackerToggleBtn');
const bookingStepsPanel = document.querySelector('.booking-steps');

function updateBookingStepsOffset() {
  if (!tracker || !bookingStepsPanel) return;
  const trackerRect = tracker.getBoundingClientRect();
  const offset = trackerRect.bottom + 16;
  bookingStepsPanel.style.top = `${Math.max(offset, 150)}px`;
}

if (trackerToggleBtn) {
  trackerToggleBtn.addEventListener('click', () => {
    if (tracker.classList.contains('is-collapsed')) {
      tracker.classList.remove('is-collapsed');
      trackerToggleBtn.textContent = '−';
    } else {
      tracker.classList.add('is-collapsed');
      trackerToggleBtn.textContent = '+';
    }
    updateBookingStepsOffset();
  });
}

window.addEventListener('resize', updateBookingStepsOffset);
window.addEventListener('load', updateBookingStepsOffset);


// EcoPoints Redemption Modal and Amenity Selection
document.addEventListener('DOMContentLoaded', function() {
  // Modal elements
  const viewRewardsBtn = document.getElementById('viewRewardsBtn');
  const viewRewardsModal = document.getElementById('viewRewardsModal');
  const closeRewardsModal = document.getElementById('closeRewardsModal');
  const usePointsToggle = document.getElementById('use-points-toggle');

  // Helper function: map amenity name (e.g., "Basketball Court") to key (e.g., "basketball")
  function getAmenityKeyFromName(amenityName) {
    for (const [key, info] of Object.entries(amenityData)) {
      if (info.value === amenityName) return key;
    }
    return 'clubhouse'; // default fallback
  }

  // Open modal
  if (viewRewardsBtn && viewRewardsModal) {
    viewRewardsBtn.addEventListener('click', function() {
      viewRewardsModal.style.display = 'flex';
    });
  }

  // Close modal with X button
  if (closeRewardsModal && viewRewardsModal) {
    closeRewardsModal.addEventListener('click', function() {
      viewRewardsModal.style.display = 'none';
    });
  }

  // Close modal when clicking outside
  if (viewRewardsModal) {
    viewRewardsModal.addEventListener('click', function(e) {
      if (e.target === viewRewardsModal) {
        viewRewardsModal.style.display = 'none';
      }
    });
  }

  // Amenity cards are browse-only. While the EcoPoints popup is open, the
  // resident's amenity selection and the Amenity Picking page stay untouched.
  // The "Redeem" button opens the Confirm Redemption dialog first; the resident
  // moves to the selected amenity's reservation page only after clicking
  // Confirm Redemption. The amenity always comes from the card that was
  // clicked, so it can never fall back to a default clubhouse value.
  function proceedWithRewardRedemption(amenityName) {
    if (!amenityName) {
      showToast('Please select an amenity first, then use Redeem.', 'warning');
      return;
    }
    if (viewRewardsModal) {
      viewRewardsModal.style.display = 'none';
    }
    window.__rewardRedemptionAmenity = amenityName;
    window.__rewardRedemptionProceed = true;
    if (typeof showPointsRedemptionConfirm === 'function') {
      showPointsRedemptionConfirm(amenityName);
    } else {
      window.__rewardRedemptionProceed = false;
      window.__rewardRedemptionAmenity = '';
    }
  }

  function showRewardSwitchConfirmation(currentAmenity, nextAmenity) {
    const modal = document.getElementById('switchRewardRedemptionModal');
    const message = document.getElementById('switchRewardRedemptionMessage');
    if (!modal) return;
    window.__pendingRewardAmenity = nextAmenity;
    if (message) message.textContent = 'You already confirmed ' + currentAmenity + '. Switch your selection to ' + nextAmenity + '?';
    vpShowModal(modal);
  }

  (function initRewardSwitchConfirmation(){
    const modal = document.getElementById('switchRewardRedemptionModal');
    const closeBtn = document.getElementById('switchRewardRedemptionCloseBtn');
    const keepBtn = document.getElementById('keepRewardRedemptionBtn');
    const switchBtn = document.getElementById('confirmRewardRedemptionSwitchBtn');
    function close(){
      if (modal) vpHideModal(modal);
      window.__pendingRewardAmenity = '';
    }
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (keepBtn) keepBtn.addEventListener('click', close);
    if (modal) modal.addEventListener('click', function(event){ if (event.target === modal) close(); });
    if (switchBtn) switchBtn.addEventListener('click', function(){
      const nextAmenity = window.__pendingRewardAmenity || '';
      close();
      if (!nextAmenity) return;
      window.__confirmedRewardAmenity = '';
      proceedWithRewardRedemption(nextAmenity);
    });
  })();

  // Each confirmed reward starts a clean reservation instead of continuing a
  // saved form or attaching to a reservation reference from an earlier flow.
  window.rewardRedemptionProceedToAmenity = function(){
    const amenityName = window.__rewardRedemptionAmenity || '';
    if (!amenityName) return;
    const amenityCard = document.querySelector(`.amenity-card[data-amenity="${amenityName}"]`);
    const bookNowBtn = amenityCard ? amenityCard.querySelector('button[data-action="book-now"]') : null;
    if (!bookNowBtn) return;
    clearBookingFormState();
    const refCodeField = document.getElementById('refCodeField');
    if (refCodeField) refCodeField.value = '';
    runBookNowFlow(bookNowBtn);
    const toggle = document.getElementById('use-points-toggle');
    if (toggle) {
      toggle.checked = true;
      setRedemptionConfirmed(true);
      updateRedemptionInfo();
    }
  };

  // Attach handlers to all amenity-reward-card elements
  const amenityRewardCards = document.querySelectorAll('.amenity-reward-card');
  amenityRewardCards.forEach(card => {
    // Clicking anywhere on the card must not close the popup or change the
    // current amenity selection.
    card.addEventListener('click', function(e) {
      // Intentionally a no-op while the resident browses rewards.
    });
    // Only the "Redeem" button opens the Confirm Redemption dialog; the move to
    // the redeemed amenity happens after the resident confirms.
    const btn = card.querySelector('button');
    if (btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const eligible = card.getAttribute('data-eligible') === 'true';
        if (eligible) { // only allow clicking if eligible!
          const amenityName = card.getAttribute('data-amenity');
          const currentAmenity = window.__confirmedRewardAmenity || '';
          if (currentAmenity && currentAmenity !== amenityName) {
            showRewardSwitchConfirmation(currentAmenity, amenityName);
          } else {
            proceedWithRewardRedemption(amenityName);
          }
        }
      });
    }
  });

  // Handle old AI recommendation cards (just in case)
  const aiCards = document.querySelectorAll('.ai-recommendation-card');
  aiCards.forEach(card => {
    card.addEventListener('click', function() {
      const amenityName = this.getAttribute('data-amenity');
      const eligible = this.getAttribute('data-eligible') === 'true';
      const key = getAmenityKeyFromName(amenityName);
      const amenityCard = document.querySelector(`.amenity-card[data-key="${key}"]`);

      // Close modal
      if (viewRewardsModal) {
        viewRewardsModal.style.display = 'none';
      }

      if (amenityCard) {
        const bookNowBtn = amenityCard.querySelector('button[data-action="book-now"]');
        if (bookNowBtn) bookNowBtn.style.display = 'none';
        selectAmenityByKey(key);
        try{
          updateAmenityDescription(key);
          const descBox=document.getElementById('amenityDescBox');
          if(descBox){ descBox.style.display='flex'; }
          const descText=document.getElementById('amenityDescText');
          if(descText){ descText.textContent=''; descText.style.display='none'; }
        }catch(_){}
        const viewBtn=amenityCard.querySelector('button[data-action="view-desc"]');
        if(viewBtn){ viewBtn.style.display='none'; }
        document.querySelectorAll('.amenity-card').forEach(function(c){
          c.style.display='none';
        });
        const amenitiesHeader=document.getElementById('amenitiesHeader');
        if(amenitiesHeader){ amenitiesHeader.style.display='none'; }
        const ret=document.getElementById('amenityReturnBtn');
        if(ret){ ret.style.display='inline-flex'; }
        try{
          const rc=document.getElementById('reservationCard');
          if(rc){
            rc.style.display='flex';
            document.getElementById('reservationTitle').textContent='Reservation';
            document.getElementById('reservationHint').textContent='Select date, time, and persons';
            refreshAvailabilityFromServer();
            rc.scrollIntoView({behavior:'smooth',block:'start'});
          }
        }catch(_){}
      }

      if (usePointsToggle && eligible) {
        usePointsToggle.checked = true;
        usePoints = true;
        updateRedemptionInfo();
      } else if (usePointsToggle) {
        usePointsToggle.checked = false;
        usePoints = false;
      }
    });
  });
});
</script>

<?php if ($isResident): ?>
<div id="vhecopointProTipPopup" class="vhecopoint-popup-overlay" role="dialog" aria-modal="true" aria-labelledby="vhecopointProTipTitle" aria-describedby="vhecopointProTipText" style="display:none;">
  <div class="vhecopoint-popup-card">
    <button type="button" class="vhecopoint-popup-close" id="vhecopointProTipClose" aria-label="Close announcement">&times;</button>
    <span class="vhecopoint-popup-icon" aria-hidden="true"><i class="fa-solid fa-lightbulb"></i></span>
    <div class="vhecopoint-popup-title" id="vhecopointProTipTitle">Pro Tip!</div>
    <p class="vhecopoint-popup-text" id="vhecopointProTipText">You can use the points you have collected in the VHEcoPoint Smart Waste Segregation Station when booking an amenity for a FREE 1 hour. Click on an amenity to see if you're eligible!</p>
  </div>
</div>
<script>
(function(){
  var overlay = document.getElementById('vhecopointProTipPopup');
  if(!overlay) return;
  var closeBtn = document.getElementById('vhecopointProTipClose');
  function hide(){
    overlay.style.display = 'none';
    overlay.classList.remove('vhecopoint-popup-open');
  }
  closeBtn.addEventListener('click', hide);
  overlay.addEventListener('click', function(e){
    if(e.target === overlay) hide();
  });
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && overlay.style.display !== 'none') hide();
  });
  setTimeout(function(){
    overlay.style.display = 'flex';
    requestAnimationFrame(function(){ overlay.classList.add('vhecopoint-popup-open'); });
    setTimeout(function(){ closeBtn.focus(); }, 350);
  }, 600);
})();
</script>
<?php endif; ?>



<script>
(function(){
  if (window.__vpHoldInit) { return; }
  window.__vpHoldInit = true;

  var API = 'amenity_hold_api.php';
  var holdField = document.getElementById('holdTokenField');
  var waitlistField = document.getElementById('waitlistTokenField');
  var banner = document.getElementById('holdBanner');
  var bannerText = document.getElementById('holdBannerText');
  var countdownEl = document.getElementById('holdCountdown');
  var waitlistBtn = document.getElementById('holdWaitlistBtn');

  // Queue modal elements
  var qOverlay = document.getElementById('queueOverlay');
  var qTitle = document.getElementById('queueTitle');
  var qSlot = document.getElementById('queueSlot');
  var qBadge = document.getElementById('queueBadge');
  var qMessage = document.getElementById('queueMessage');
  var qPosWrap = document.getElementById('queuePositionWrap');
  var qPos = document.getElementById('queuePosition');
  var qCountWrap = document.getElementById('queueCountdownWrap');
  var qCount = document.getElementById('queueCountdown');
  var qAltWrap = document.getElementById('queueAlternativesWrap');
  var qAlt = document.getElementById('queueAlternatives');
  var qClaim = document.getElementById('queueClaimBtn');
  var qLeave = document.getElementById('queueLeaveBtn');
  var qBack = document.getElementById('queueBackBtn');
  var qIcon = document.getElementById('queueStatusIcon');

  // Form time-limit indicator (mirrors the active slot hold)
  var formLimit = document.getElementById('formTimeLimit');
  var formLimitCount = document.getElementById('formTimeLimitCountdown');
  if (formLimit && formLimit.parentNode !== document.body) { document.body.appendChild(formLimit); }

  if (!holdField || !banner) { return; }

  var hold = null;          // {token, expiresAt, amenity, startDate, endDate, startTime, endTime}
  var timer = null;
  var lastState = null;     // { sig, complete }
  var queue = null;         // { token, sig, poll, lastStatus }
  var qCountdownTimer = null;
  var seenQueueNotificationIds = [];
  var seenQueueNotificationUserId = '';
  var queueNotificationLookupActive = false;
  var queueAvailabilityRefreshTimer = null;
  var queueBookingToastTimes = Object.create(null);
  window.__vpWaitlistBookedSlots = window.__vpWaitlistBookedSlots || [];

  function val(id){ var el = document.getElementById(id); return el ? (el.value || '') : ''; }
  function norm(t){ t = String(t || ''); return t.length === 5 ? (t + ':00') : t; }

  function api(body){
    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body).toString()
    }).then(function(r){ return r.json().catch(function(){ return null; }); })
      .catch(function(){ return null; });
  }

  function note(msg, type){
    if (typeof showToast === 'function') {
      try { showToast('<i class="fa-solid fa-hourglass-half"></i> ' + msg, type || 'success'); } catch (e) {}
    }
  }

  function fmt(sec){
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60), s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function showBanner(text, timerText, showWaitlist){
    banner.style.display = 'flex';
    if (bannerText) { bannerText.textContent = text; }
    if (countdownEl) {
      countdownEl.textContent = timerText || '';
      countdownEl.style.display = timerText ? '' : 'none';
    }
    if (waitlistBtn) { waitlistBtn.style.display = showWaitlist ? '' : 'none'; }
  }
  function hideBanner(){ banner.style.display = 'none'; }

  function showFormLimit(sec){
    if (!formLimit) { return; }
    if (formLimitCount) { formLimitCount.textContent = fmt(sec); }
    window.__vpHoldExpired = false;
    formLimit.style.display = 'flex';
    document.body.style.paddingBottom = '104px';
  }
  function hideFormLimit(){
    if (formLimit) { formLimit.style.display = 'none'; }
    document.body.style.paddingBottom = '';
  }

  function stopTimer(){ if (timer) { clearInterval(timer); timer = null; } }
  function tick(){
    if (!hold) { return; }
    var remain = Math.floor((hold.expiresAt - Date.now()) / 1000);
    if (remain <= 0) { onExpired(); return; }
    var t = fmt(remain);
    if (countdownEl) { countdownEl.textContent = t; }
    if (formLimitCount) { formLimitCount.textContent = t; }
  }
  function startTimer(){ stopTimer(); timer = setInterval(tick, 250); tick(); }

  function onExpired(){
    stopTimer();
    // Capture the token BEFORE clearing the fields - the release request must
    // carry it, otherwise the server can never free this slot.
    var expiredToken = (hold && hold.token) || (holdField && holdField.value) || '';
    hold = null;
    holdField.value = '';
    hideFormLimit();
    window.__vpHoldExpired = true;
    try {
      fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'release_hold', hold_token: expiredToken }).toString()
      }).catch(function(){});
    } catch (e) {}
    try { if (window.__vpRefreshAvailability) { window.__vpRefreshAvailability(); } } catch (e) {}
    try {
      window.location.href = <?php echo json_encode($sessionUserType === 'visitor' ? 'dashboardvisitor.php?expired=1' : 'profileresident.php?expired=1'); ?>;
    } catch (e) {}
  }

  function setHold(data){
    var exp = Date.parse(data.expires_at);
    hold = {
      token: data.hold_token,
      expiresAt: isNaN(exp) ? (Date.now() + 10 * 60 * 1000) : exp,
      amenity: data.amenity || '',
      startDate: data.start_date || '',
      endDate: data.end_date || '',
      startTime: data.start_time || '',
      endTime: data.end_time || ''
    };
    holdField.value = data.hold_token;
    showBanner(data.reused ? 'We are still holding this slot for you.' : 'We are holding this slot for you.',
               fmt((hold.expiresAt - Date.now()) / 1000), false);
    showFormLimit((hold.expiresAt - Date.now()) / 1000);
    startTimer();
    // A hold for a different slot means we no longer need the old queue entry.
    if (queue && queue.sig && queue.sig !== signature()) { leaveQueue({ silent: true }); }
  }

  function slot(){
    return {
      amenity: val('amenityField'),
      start_date: val('startDateInput'),
      end_date: val('endDateInput') || val('startDateInput'),
      start_time: val('startTimeInput'),
      end_time: val('endTimeInput'),
      entry_pass_id: val('entry_pass_id'),
      ref_code: val('refCodeField')
    };
  }

  function acquireHold(){
    var s = slot();
    if (!s.amenity || !s.start_date || !s.start_time) { return; }
    if (hold &&
        hold.amenity === s.amenity &&
        hold.startDate === s.start_date &&
        norm(hold.startTime) === norm(s.start_time) &&
        norm(hold.endTime) === norm(s.end_time)) {
      return; // same slot, keep countdown (must not reset/extend)
    }
    var previous = hold;
    if (previous && previous.token) { api({ action: 'release_hold', hold_token: previous.token }); }
    hold = null;
    stopTimer();
    showBanner('Holding this slot...', '', false);
    api({
      action: 'hold_slot',
      amenity: s.amenity,
      start_date: s.start_date,
      end_date: s.end_date,
      start_time: s.start_time,
      end_time: s.end_time,
      entry_pass_id: s.entry_pass_id,
      ref_code: s.ref_code
    }).then(function(data){
      if (data && data.success) { setHold(data); return; }
      if (data && data.conflict) {
        holdField.value = '';
        stopTimer();
        hideBanner();
        hideFormLimit();
        // Per the reservation policy, a conflicting click adds the resident to
        // the waiting line instead of letting them book the slot directly.
        joinQueue();
        return;
      }
      hideBanner();
      if (data && data.message) { note(data.message, 'warning'); }
    });
  }

  function releaseHold(){
    if (!hold || !hold.token) { hideBanner(); hideFormLimit(); holdField.value = ''; return; }
    var token = hold.token;
    hold = null; stopTimer(); hideBanner(); hideFormLimit(); holdField.value = '';
    api({ action: 'release_hold', hold_token: token });
    // The slot is free again: refetch so it shows as available immediately.
    try { if (window.__vpRefreshAvailability) { window.__vpRefreshAvailability(); } } catch (e) {}
  }

  var accountBackBtn = document.getElementById('accountBackBtn');
  var resetReservationModal = document.getElementById('resetReservationConfirmModal');
  var resetReservationConfirmBtn = document.getElementById('resetReservationConfirmBtn');
  var resetReservationCancelBtn = document.getElementById('resetReservationCancelBtn');
  var resetReservationCloseBtn = document.getElementById('resetReservationCloseBtn');
  if (accountBackBtn) {
    accountBackBtn.addEventListener('click', function(e){
      e.preventDefault();
      vpShowModal(resetReservationModal);
    });
  }
  function closeResetReservationModal(){
    vpHideModal(resetReservationModal);
  }
  if (resetReservationCancelBtn) {
    resetReservationCancelBtn.addEventListener('click', closeResetReservationModal);
  }
  if (resetReservationCloseBtn) {
    resetReservationCloseBtn.addEventListener('click', closeResetReservationModal);
  }
  if (resetReservationModal) {
    resetReservationModal.addEventListener('click', function(e){
      if (e.target === resetReservationModal) { closeResetReservationModal(); }
    });
  }
  if (resetReservationConfirmBtn) {
    resetReservationConfirmBtn.addEventListener('click', function(){
      resetReservationConfirmBtn.disabled = true;
      if (resetReservationCancelBtn) { resetReservationCancelBtn.disabled = true; }
      accountBackBtn.disabled = true;
      var token = (hold && hold.token) || (holdField && holdField.value) || '';
      var released = token ? api({ action: 'release_hold', hold_token: token }) : Promise.resolve({ success: true });
      released.then(function(data){
        if (!data || !data.success) {
          accountBackBtn.disabled = false;
          resetReservationConfirmBtn.disabled = false;
          if (resetReservationCancelBtn) { resetReservationCancelBtn.disabled = false; }
          closeResetReservationModal();
          note('Could not reset your reservation. Please try again.', 'warning');
          return;
        }
        hold = null;
        stopTimer();
        hideBanner();
        hideFormLimit();
        if (holdField) { holdField.value = ''; }
        try {
          sessionStorage.removeItem('reserve_form');
          sessionStorage.removeItem('reserve_completed');
          sessionStorage.removeItem('reserve_submitted');
        } catch (_) {}
        var target = <?php echo json_encode('reserve.php?reset_reservation=1&return_to=' . rawurlencode($accountLink)); ?>;
        window.location.href = target;
      });
    });
  }

  // ---- Waiting line / queue --------------------------------------------
  function rangeLabel(st, et){
    var t = function(x){ if(!x){return '';} x=String(x); var p=x.split(':'); var h=parseInt(p[0],10); var m=p[1]||'00'; var ap=h>=12?'PM':'AM'; h=h%12; if(h===0){h=12;} return h+':'+m+' '+ap; };
    return t(st) + (et ? (' \u2013 ' + t(et)) : '');
  }

  function stopQueueCountdown(){ if (qCountdownTimer) { clearInterval(qCountdownTimer); qCountdownTimer = null; } }
  function startQueueCountdown(sec){
    stopQueueCountdown();
    var remain = Math.max(0, parseInt(sec, 10) || 0);
    if (qCount) { qCount.textContent = fmt(remain); }
    qCountdownTimer = setInterval(function(){
      remain--;
      if (remain <= 0) { stopQueueCountdown(); if (qCount) { qCount.textContent = '00:00'; } return; }
      if (qCount) { qCount.textContent = fmt(remain); }
    }, 1000);
  }

  function stopQueuePoll(){ if (queue && queue.poll) { clearInterval(queue.poll); queue.poll = null; } }

  function renderQueue(data){
    if (!qOverlay) { return; }
    var status = data.status || 'waiting';
    if (qSlot) { qSlot.textContent = (data.amenity || '') + (data.start_date ? (' \u2022 ' + data.start_date) : '') + (data.start_time ? (' \u2022 ' + rangeLabel(data.start_time, data.end_time)) : ''); }
    if (qMessage) { qMessage.textContent = data.message || ''; }

    var badge = 'In Queue', title = "You're in the Queue!", bg = '#fef3c7', fg = '#92400e',
        icon = 'fa-hourglass-half', icbg = '#fff7e6', icfg = '#b7791f';
    if (status === 'offered') { badge = 'Slot available'; title = "It's Open Now!"; bg = '#dcfce7'; fg = '#166534'; icon = 'fa-bell'; icbg = '#dcfce7'; icfg = '#166534'; }
    else if (status === 'booked') { badge = 'Booked'; title = 'Booked'; bg = '#dcfce7'; fg = '#166534'; icon = 'fa-check'; icbg = '#dcfce7'; icfg = '#166534'; }
    else if (status === 'unavailable') { badge = 'Unavailable'; title = 'Slot No Longer Available'; bg = '#fee2e2'; fg = '#991b1b'; icon = 'fa-circle-xmark'; icbg = '#fee2e2'; icfg = '#991b1b'; }
    else if (status === 'expired') { badge = 'Offer expired'; title = 'Offer Expired'; bg = '#f3f4f6'; fg = '#374151'; icon = 'fa-clock'; icbg = '#f3f4f6'; icfg = '#374151'; }
    else if (status === 'converted') { badge = 'Submitted'; title = 'Request Submitted'; bg = '#dcfce7'; fg = '#166534'; icon = 'fa-check'; icbg = '#dcfce7'; icfg = '#166534'; }
    else if (status === 'declined' || status === 'cancelled') { badge = 'Left queue'; title = 'Waiting Cancelled'; bg = '#f3f4f6'; fg = '#374151'; icon = 'fa-door-open'; icbg = '#f3f4f6'; icfg = '#374151'; }
    if (qBadge) { qBadge.textContent = badge; qBadge.style.background = bg; qBadge.style.color = fg; }
    if (qTitle) { qTitle.textContent = title; }
    if (qIcon) { qIcon.style.background = icbg; qIcon.style.color = icfg; qIcon.innerHTML = '<i class="fa-solid ' + icon + '"></i>'; }

    if (qPosWrap) {
      if (status === 'waiting' && parseInt(data.position, 10) > 0) {
        if (qPos) { qPos.textContent = 'You are #' + data.position + ' in line' + (parseInt(data.total, 10) > 1 ? (' of ' + data.total) : ''); }
        qPosWrap.style.display = '';
      } else {
        qPosWrap.style.display = 'none';
      }
    }

    if (status === 'offered' && data.seconds_remaining != null) {
      if (qCountWrap) { qCountWrap.style.display = ''; }
      startQueueCountdown(data.seconds_remaining);
    } else {
      if (qCountWrap) { qCountWrap.style.display = 'none'; }
      stopQueueCountdown();
    }

    if (qClaim) { qClaim.style.display = (status === 'offered') ? '' : 'none'; }

    if (qAlt && qAltWrap) {
      var alts = data.alternatives || [];
      if (alts.length) {
        qAlt.innerHTML = '';
        alts.forEach(function(a){
          var chip = document.createElement('span');
          chip.style.cssText = 'font-size:12px;background:#eef2ff;color:#3730a3;border-radius:999px;padding:4px 10px;font-weight:600;';
          chip.textContent = rangeLabel(a.start_time, a.end_time);
          qAlt.appendChild(chip);
        });
        qAltWrap.style.display = '';
      } else {
        qAltWrap.style.display = 'none';
      }
    }
    // When the resident dismisses the panel with "Back to calendar", keep it
    // hidden on routine polls, but always re-surface it when the slot is
    // offered or becomes unavailable.
    if (queue && status !== 'offered' && status !== 'unavailable') {
      if (!queue.dismissed) { qOverlay.style.display = 'flex'; }
    } else {
      if (queue) { queue.dismissed = false; }
      qOverlay.style.display = 'flex';
    }
  }

  function queueSlotKey(slot){
    return [slot.amenity || '', slot.start_date || '', slot.end_date || slot.start_date || '',
            norm(slot.start_time), norm(slot.end_time)].join('|');
  }

  function queueSlotsOverlap(a, b){
    if (!a || !b || a.amenity !== b.amenity) { return false; }
    var aStart = a.start_date || '', aEnd = a.end_date || aStart;
    var bStart = b.start_date || '', bEnd = b.end_date || bStart;
    if (!aStart || !bStart || aEnd < bStart || aStart > bEnd) { return false; }
    if (!a.start_time || !a.end_time || !b.start_time || !b.end_time) { return true; }
    var toMin = function(value){
      var p = String(value).split(':');
      return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
    };
    return toMin(a.start_time) < toMin(b.end_time) && toMin(a.end_time) > toMin(b.start_time);
  }

  function rememberBookedSlot(slot){
    var key = queueSlotKey(slot);
    if (!window.__vpWaitlistBookedSlots.some(function(item){ return queueSlotKey(item) === key; })) {
      window.__vpWaitlistBookedSlots.push(slot);
    }
  }

  function scheduleQueueAvailabilityRefresh(){
    if (queueAvailabilityRefreshTimer) { return; }
    queueAvailabilityRefreshTimer = setTimeout(function(){
      queueAvailabilityRefreshTimer = null;
      if (window.__vpRefreshAvailability) { window.__vpRefreshAvailability(); }
    }, 150);
  }

  function applyBookedQueueSlot(slot, message, notificationId, toast){
    if (!slot || !slot.amenity || !slot.start_date) { return; }
    rememberBookedSlot(slot);
    if (queue && queue.slot && queueSlotsOverlap(queue.slot, slot)) {
      queue.lastStatus = 'booked';
      queue.token = '';
      queue.message = message || 'The slot you queued for has been booked by another user. Please choose another time.';
      stopQueuePoll();
      if (waitlistField) { waitlistField.value = ''; }
      renderQueue({
        status: 'booked',
        amenity: queue.slot.amenity,
        start_date: queue.slot.start_date,
        start_time: queue.slot.start_time,
        end_time: queue.slot.end_time,
        message: queue.message
      });
    }
    var key = queueSlotKey(slot);
    var lastToast = queueBookingToastTimes[key] || 0;
    if (toast && Date.now() - lastToast > 5000) {
      queueBookingToastTimes[key] = Date.now();
      note(message || 'The slot you queued for has been booked by another user. Please choose another time.', 'warning');
    }
    scheduleQueueAvailabilityRefresh();
  }

  function handleQueueNotifications(snapshot){
    if (!snapshot || !Array.isArray(snapshot.notifications)) { return; }
    var userId = String(snapshot.user_id || '');
    if (userId && userId !== seenQueueNotificationUserId) {
      seenQueueNotificationUserId = userId;
      try {
        seenQueueNotificationIds = JSON.parse(localStorage.getItem('vp_queue_booked_notices_' + userId) || '[]');
        if (!Array.isArray(seenQueueNotificationIds)) { seenQueueNotificationIds = []; }
      } catch (e) { seenQueueNotificationIds = []; }
    }
    snapshot.notifications.forEach(function(item){
      var parts = String(item.title || '').split(' | ');
      if (parts.length !== 6 || parts[0] !== 'Queued slot booked') { return; }
      var slot = {
        amenity: parts[1],
        start_date: parts[2],
        end_date: parts[3],
        start_time: parts[4] === '*' ? '' : parts[4],
        end_time: parts[5] === '*' ? '' : parts[5]
      };
      var id = String(item.id || '');
      var seen = id && seenQueueNotificationIds.indexOf(id) !== -1;
      if (seen) {
        rememberBookedSlot(slot);
        return;
      }
      applyBookedQueueSlot(slot, item.message, id, !seen);
      if (id && !seen) {
        seenQueueNotificationIds.push(id);
        if (seenQueueNotificationIds.length > 100) { seenQueueNotificationIds = seenQueueNotificationIds.slice(-100); }
        try {
          localStorage.setItem('vp_queue_booked_notices_' + String(snapshot.user_id || ''), JSON.stringify(seenQueueNotificationIds));
        } catch (e) {}
      }
    });
  }

  function checkQueueBookingNotifications(){
    if (queueNotificationLookupActive) { return; }
    queueNotificationLookupActive = true;
    api({ action: 'queue_booking_notifications' }).then(function(snapshot){
      if (snapshot && snapshot.success) { handleQueueNotifications(snapshot); }
    }).finally(function(){
      queueNotificationLookupActive = false;
    });
  }

  function startNotificationStream(){
    if (!window.EventSource) { return; }
    try {
      var stream = new EventSource('api/notifications_sse.php', { withCredentials: true });
      stream.addEventListener('snapshot', function(event){
        try { handleQueueNotifications(JSON.parse(event.data)); } catch (e) {}
      });
    } catch (e) {}
  }

  function setQueueToken(token, sig, slotInfo){
    if (waitlistField) { waitlistField.value = token || ''; }
    var data = slotInfo || {};
    var slot = {
      amenity: data.amenity || val('amenityField'),
      start_date: data.start_date || val('startDateInput'),
      end_date: data.end_date || data.start_date || val('endDateInput'),
      start_time: data.start_time || val('startTimeInput'),
      end_time: data.end_time || val('endTimeInput')
    };
    if (!queue) { queue = { token: token, sig: sig, poll: null, lastStatus: 'waiting', slot: slot }; }
    else { queue.token = token; queue.sig = sig; queue.slot = slot; }
  }

  function startQueuePoll(){
    if (!queue || !queue.token) { return; }
    stopQueuePoll();
    queue.poll = setInterval(function(){ pollQueue(); }, 5000);
  }

  function pollQueue(){
    if (!queue || !queue.token) { return; }
    api({ action: 'waitlist_status', waitlist_token: queue.token }).then(function(data){
      if (!data || !data.success) { return; }
      if (data.active === false) { leaveQueue({ silent: true }); return; }
      var prev = queue.lastStatus || 'waiting';
      queue.lastStatus = data.status;
      if (data.status === 'booked') {
        applyBookedQueueSlot({
          amenity: data.amenity, start_date: data.start_date, end_date: data.end_date,
          start_time: data.start_time, end_time: data.end_time
        }, data.message, '', prev !== 'booked');
      }
      renderQueue(data);
      if (data.status === 'offered' && prev !== 'offered') { note("It's open now! Claim your slot before the timer expires.", 'success'); }
      if (data.status === 'unavailable' && prev !== 'unavailable') { note('That slot was confirmed for another resident. Please choose another time.', 'warning'); }
      if (data.status === 'expired' && prev !== 'expired') { note('Your claim window expired. Please pick another time.', 'warning'); }
      if (data.status === 'booked' || data.status === 'converted' || data.status === 'declined' || data.status === 'cancelled' || data.status === 'expired' || data.status === 'unavailable') {
        stopQueuePoll();
      }
    });
  }

  function joinQueue(){
    var s = slot();
    if (!s.amenity || !s.start_date || !s.start_time) { return; }
    // Only keep one active queue entry at a time.
    if (queue && queue.sig && queue.sig !== signature()) { leaveQueue({ silent: true }); }
    var sig = signature();
    api({
      action: 'join_waitlist',
      amenity: s.amenity, start_date: s.start_date, end_date: s.end_date,
      start_time: s.start_time, end_time: s.end_time, entry_pass_id: s.entry_pass_id
    }).then(function(data){
      if (data && data.success) {
        setQueueToken(data.waitlist_token, sig, s);
        queue.lastStatus = data.status || 'waiting';
        renderQueue(data);
        startQueuePoll();
        if (!data.duplicate) { note("You're in the Queue! We'll hold your place.", 'info'); }
      } else if (data && data.message) {
        note(data.message, 'warning');
      }
    });
  }

  function leaveQueue(opts){
    opts = opts || {};
    var token = queue && queue.token;
    stopQueuePoll();
    stopQueueCountdown();
    queue = null;
    if (waitlistField) { waitlistField.value = ''; }
    if (qOverlay) { qOverlay.style.display = 'none'; }
    if (token) {
      api({ action: 'waitlist_leave', waitlist_token: token });
      if (!opts.silent) { note('You left the waiting line.', 'success'); }
    }
  }

  function claimQueue(){
    stopQueuePoll();
    stopQueueCountdown();
    if (qOverlay) { qOverlay.style.display = 'none'; }
    // The server already placed a fresh claim hold on this slot for us; fetch
    // it so the booking form can proceed. Never assume based on the notification.
    api({ action: 'hold_status' }).then(function(data){
      if (data && data.success && data.active && data.hold_token) {
        setHold(data);
        note('Slot claimed. Please complete your booking.', 'success');
      } else {
        acquireHold();
      }
    });
  }

  if (qClaim) { qClaim.addEventListener('click', function(e){ e.preventDefault(); claimQueue(); }); }
  if (qLeave) { qLeave.addEventListener('click', function(e){ e.preventDefault(); leaveQueue(); }); }
  if (qBack)  { qBack.addEventListener('click', function(e){ e.preventDefault(); if (queue) { queue.dismissed = true; } if (qOverlay) { qOverlay.style.display = 'none'; } }); }
  if (waitlistBtn) { waitlistBtn.addEventListener('click', function(e){ e.preventDefault(); if (queue) { renderQueue(queue); } else { joinQueue(); } }); }

  function signature(){
    return [val('amenityField'), val('startDateInput'), val('endDateInput'),
            val('startTimeInput'), val('endTimeInput')].join('|');
  }

  function restoreState(){
    startNotificationStream();
    checkQueueBookingNotifications();
    api({ action: 'hold_status' }).then(function(data){
      if (data && data.success && data.active && data.hold_token) { setHold(data); return; }
      api({ action: 'waitlist_status' }).then(function(qd){
        if (qd && qd.success && qd.active && (qd.status === 'waiting' || qd.status === 'offered' || qd.status === 'booked')) {
          setQueueToken(qd.waitlist_token, signature(), qd);
          queue.lastStatus = qd.status;
          renderQueue(qd);
          if (qd.status === 'booked') {
            applyBookedQueueSlot(qd, qd.message, '', true);
          } else {
            startQueuePoll();
          }
        }
      });
    });
  }

  window.addEventListener('pageshow', function(event){
    if (!event.persisted) { return; }
    api({ action: 'hold_status' }).then(function(data){
      if (data && data.success && data.active && data.hold_token) {
        setHold(data);
        return;
      }
      hold = null;
      stopTimer();
      holdField.value = '';
      hideBanner();
      hideFormLimit();
      try { if (window.__vpRefreshAvailability) { window.__vpRefreshAvailability(); } } catch (e) {}
    });
  });

  window.addEventListener('focus', function(){ pollQueue(); checkQueueBookingNotifications(); });
  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'visible') { pollQueue(); checkQueueBookingNotifications(); }
  });
  setInterval(function(){
    if (document.visibilityState === 'visible') { checkQueueBookingNotifications(); }
  }, 5000);

  // Watch for slot changes made anywhere in the page (selectTimeSlot etc. set
  // hidden fields via JS, which does not fire change events), then acquire or
  // release the hold accordingly.
  setInterval(function(){
    var sig = signature();
    if (lastState && sig === lastState.sig) { return; }
    var s = slot();
    var complete = !!(s.amenity && s.start_date && s.start_time && s.end_time);
    var prev = lastState;
    lastState = { sig: sig, complete: complete };
    if (complete) {
      // If we are already queued for this exact slot, don't hammer hold_slot.
      if (queue && queue.sig === sig) { return; }
      acquireHold();
    } else if (prev && prev.complete && hold) {
      releaseHold();
    }
  }, 600);

  // Best-effort release when leaving the page. Skipped for a legitimate form
  // submission, otherwise the release races the booking POST and the server
  // rejects the booking as an expired hold.
  window.addEventListener('beforeunload', function(){
    if (window.__vpSubmitting) { return; }
    if (hold && hold.token && navigator.sendBeacon) {
      var fd = new URLSearchParams({ action: 'release_hold', hold_token: hold.token });
      navigator.sendBeacon(API, fd);
    }
  });

  // Closing the Confirm Details modal without submitting abandons the hold:
  // release it immediately so other residents see the slot as available again.
  // The Confirm path is excluded via __vpSubmitting (set right after closeVerify).
  function onVerifyModalClosed(){
    if (window.__vpSubmitting) { return; }
    if (!hold || !hold.token) { return; }
    releaseHold();
  }
  var verifyModalEl = document.getElementById('verifyModal');
  ['verifyCancelBtn', 'verifyCloseBtn'].forEach(function(id){
    var b = document.getElementById(id);
    if (b) { b.addEventListener('click', onVerifyModalClosed); }
  });
  if (verifyModalEl) {
    verifyModalEl.addEventListener('click', function(e){
      if (e.target === verifyModalEl) {
        if (typeof vpHideModal === 'function') { vpHideModal(verifyModalEl); }
        onVerifyModalClosed();
      }
    });
  }
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape' || !verifyModalEl || verifyModalEl.style.display === 'none' || verifyModalEl.style.display === '') { return; }
    if (typeof vpHideModal === 'function') { vpHideModal(verifyModalEl); }
    onVerifyModalClosed();
  });

  function boot(){ setTimeout(restoreState, 500); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>

</body>
</html>