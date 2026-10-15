<?php
/**
 * amenity_hold_api.php
 * ---------------------------------------------------------------------------
 * JSON API for temporary slot holds and the waitlist.
 *
 * POST action=hold_slot        amenity,start_date,end_date,start_time,end_time
 * POST action=release_hold     hold_token
 * POST action=join_waitlist    amenity,start_date,end_date,start_time,end_time
 * POST action=waitlist_status  waitlist_token
 * POST action=waitlist_decline waitlist_token
 * GET  action=hold_status      (optional hold_token / amenity+slot)
 *
 * All responses are JSON: { success: bool, ... }.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('VP_SESSION_COOKIE_AUTH', true);
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';

header('Content-Type: application/json; charset=utf-8');

function vpAhJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function vpAhPost(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * Never leak another resident's identity, ref codes or hold tokens. Reduce raw
 * conflict rows to a coarse, non-identifying summary.
 */
function vpAhConflictSummary(array $conflicts): array
{
    $hasHold = false;
    $hasBooking = false;
    foreach ($conflicts as $c) {
        if (($c['source'] ?? '') === 'hold') { $hasHold = true; } else { $hasBooking = true; }
    }
    return ['hold' => $hasHold, 'booking' => $hasBooking, 'count' => count($conflicts)];
}

if (!($con instanceof mysqli) || $con->connect_errno) {
    vpAhJson(['success' => false, 'message' => 'Database unavailable'], 503);
}

vpAmenityHoldsEnsureSchema($con);

$action = (string)vpAhPost('action', '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$mutating = ['hold_slot', 'release_hold', 'join_waitlist', 'waitlist_decline', 'waitlist_leave'];
if (in_array($action, $mutating, true)) {
    if ($method !== 'POST') {
        vpAhJson(['success' => false, 'message' => 'POST required'], 405);
    }
    [$_uid, $_ut, $_owner] = vpResolveIdentity();
    if ($_owner === 's:' . substr(hash('sha256', ''), 0, 40)) {
        // No session and no auth cookie -> refuse to create anonymous orphan holds.
        vpAhJson(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 400);
    }
}

switch ($action) {
    // ---------------------------------------------------------------------
    case 'hold_slot': {
        $res = vpAcquireHold($con, [
            'amenity'       => (string)vpAhPost('amenity', ''),
            'start_date'    => (string)vpAhPost('start_date', ''),
            'end_date'      => (string)vpAhPost('end_date', ''),
            'start_time'    => vpAhPost('start_time'),
            'end_time'      => vpAhPost('end_time'),
            'entry_pass_id' => vpAhPost('entry_pass_id'),
            'ref_code'      => vpAhPost('ref_code'),
        ]);
        if (empty($res['ok'])) {
            vpAhJson([
                'success'   => false,
                'conflict'  => !empty($res['conflict']),
                'message'   => $res['error'] ?? 'Could not hold the slot.',
                'blocking'  => vpAhConflictSummary($res['conflicts'] ?? []),
            ], !empty($res['conflict']) ? 409 : 400);
        }
        $hold = $res['hold'];
        $expiresTs = strtotime((string)$hold['expires_at']);
        vpAhJson([
            'success'           => true,
            'hold_token'        => (string)$hold['hold_token'],
            'expires_at'        => date('c', $expiresTs),
            'expires_at_mysql'  => (string)$hold['expires_at'],
            'seconds_remaining' => max(0, $expiresTs - time()),
            'reused'            => !empty($res['reused']),
            'amenity'           => (string)$hold['amenity'],
            'start_date'        => (string)$hold['start_date'],
            'end_date'          => (string)$hold['end_date'],
            'start_time'        => $hold['start_time'],
            'end_time'          => $hold['end_time'],
        ]);
    }

    // ---------------------------------------------------------------------
    case 'release_hold': {
        $token = (string)vpAhPost('hold_token', '');
        [$_uid, $_ut, $_owner] = vpResolveIdentity();
        $ok = vpReleaseHold($con, $token, $_owner);
        vpAhJson(['success' => true, 'released' => $ok]);
    }

    // ---------------------------------------------------------------------
    case 'hold_status': {
        $token = (string)vpAhPost('hold_token', '');
        [$_uid, $_ut, $_owner] = vpResolveIdentity();
        $hold = null;
        if ($token !== '') {
            $hold = vpFindHoldByToken($con, $token);
        } else {
            $amenity = (string)vpAhPost('amenity', '');
            if ($amenity !== '') {
                $hold = vpFindActiveHoldForOwner(
                    $con,
                    $_owner,
                    $_uid,
                    $amenity,
                    (string)vpAhPost('start_date', ''),
                    (string)vpAhPost('end_date', ''),
                    vpAhPost('start_time'),
                    vpAhPost('end_time')
                );
            } else {
                // No slot given: recover the owner's most recent active hold so
                // the booking page can restore its countdown after a reload.
                $hold = vpFindAnyActiveHoldForOwner($con, $_owner, $_uid);
            }
        }
        if (!$hold || $hold['status'] !== 'held' || strtotime((string)$hold['expires_at']) <= time()) {
            vpAhJson(['success' => true, 'active' => false]);
        }
        $mine = ((string)$hold['owner_key'] === $_owner) || ($_uid && (int)$hold['user_id'] === (int)$_uid);
        $expiresTs = strtotime((string)$hold['expires_at']);
        vpAhJson([
            'success'           => true,
            'active'            => $mine,
            'hold_token'        => (string)$hold['hold_token'],
            'expires_at'        => date('c', $expiresTs),
            'expires_at_mysql'  => (string)$hold['expires_at'],
            'seconds_remaining' => max(0, $expiresTs - time()),
            'amenity'           => (string)$hold['amenity'],
            'start_date'        => (string)$hold['start_date'],
            'end_date'          => (string)$hold['end_date'],
            'start_time'        => $hold['start_time'],
            'end_time'          => $hold['end_time'],
        ]);
    }

    // ---------------------------------------------------------------------
    case 'join_waitlist': {
        $res = vpRecordWaitlist($con, [
            'amenity'       => (string)vpAhPost('amenity', ''),
            'start_date'    => (string)vpAhPost('start_date', ''),
            'end_date'      => (string)vpAhPost('end_date', ''),
            'start_time'    => vpAhPost('start_time'),
            'end_time'      => vpAhPost('end_time'),
            'entry_pass_id' => vpAhPost('entry_pass_id'),
        ]);
        if (empty($res['ok'])) {
            vpAhJson(['success' => false, 'message' => $res['error'] ?? 'Could not join the queue.'], 400);
        }
        $entry = $res['entry'];
        [$pos, $total] = vpWaitlistRank($con, $entry);
        vpAhJson([
            'success'        => true,
            'waitlist_id'    => (int)$entry['id'],
            'waitlist_token' => (string)$entry['offer_token'],
            'position'       => $pos,
            'total'          => $total,
            'status'         => (string)$entry['status'],
            'duplicate'      => !empty($res['duplicate']),
            'message'        => vpWaitlistMessage($entry, $pos, $total),
            'amenity'        => (string)$entry['amenity'],
            'start_date'     => (string)$entry['start_date'],
            'start_time'     => $entry['start_time'],
            'end_time'       => $entry['end_time'],
        ]);
    }

    // ---------------------------------------------------------------------
    case 'waitlist_status': {
        $token = (string)vpAhPost('waitlist_token', '');
        if ($token === '') {
            // No token: recover the owner's most recent active queue entry so
            // the page can restore the queue panel after a reload.
            [$_uid, $_ut, $_owner] = vpResolveIdentity();
            $entry = vpFindAnyActiveWaitlistForOwner($con, $_owner, $_uid);
            if (!$entry) {
                vpAhJson(['success' => true, 'active' => false]);
            }
            $token = (string)$entry['offer_token'];
        }
        $res = vpWaitlistOfferStatus($con, $token);
        if (empty($res['ok'])) {
            vpAhJson(['success' => false, 'message' => $res['error'] ?? 'Lookup failed.'], 404);
        }
        $entry = $res['entry'];
        $offerExpTs = $entry['offer_expires_at'] ? strtotime((string)$entry['offer_expires_at']) : null;
        vpAhJson([
            'success'           => true,
            'active'            => true,
            'status'            => (string)$entry['status'],
            'terminal'          => !empty($res['terminal']),
            'position'          => (int)($res['position'] ?? 0),
            'total'             => (int)($res['total'] ?? 0),
            'slot_free'         => !empty($res['slot_free']),
            'is_head'           => !empty($res['is_head']),
            'message'           => (string)($res['message'] ?? ''),
            'alternatives'      => $res['alternatives'] ?? [],
            'waitlist_token'    => (string)$entry['offer_token'],
            'offer_hold_token'  => $entry['offer_hold_token'] ?? null,
            'offer_expires_at'  => $offerExpTs ? date('c', $offerExpTs) : null,
            'seconds_remaining' => $offerExpTs ? max(0, $offerExpTs - time()) : null,
            'amenity'           => (string)$entry['amenity'],
            'start_date'        => (string)$entry['start_date'],
            'start_time'        => $entry['start_time'],
            'end_time'          => $entry['end_time'],
        ]);
    }

    // ---------------------------------------------------------------------
    case 'waitlist_decline':
    case 'waitlist_leave': {
        $token = (string)vpAhPost('waitlist_token', '');
        [$_uid, $_ut, $_owner] = vpResolveIdentity();
        $entry = vpFindAnyActiveWaitlistForOwner($con, $_owner, $_uid);
        $owned = false;
        if ($token !== '') {
            $check = $con->prepare("SELECT owner_key, user_id FROM amenity_waitlist WHERE offer_token = ? LIMIT 1");
            if ($check) {
                $check->bind_param('s', $token);
                $check->execute();
                $row = $check->get_result()->fetch_assoc();
                $check->close();
                if ($row) {
                    $owned = ((string)$row['owner_key'] === $_owner) || ($_uid && (int)$row['user_id'] === (int)$_uid);
                }
            }
        } elseif ($entry) {
            $token = (string)$entry['offer_token'];
            $owned = true;
        }
        if (!$owned) {
            vpAhJson(['success' => false, 'message' => 'That waiting request was not found.'], 404);
        }
        $ok = vpWaitlistMarkStatus($con, $token, 'declined');
        vpAhJson(['success' => true, 'declined' => $ok]);
    }

    default:
        vpAhJson(['success' => false, 'message' => 'Unknown action.'], 400);
}
