<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../connect.php';

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => '', 'data' => null];

if (!isset($input['resident_id'], $input['material'], $input['weight_kg'], $input['points_earned'])) {
    $response['message'] = 'Missing required fields';
    echo json_encode($response);
    exit;
}

$resident_id = (int)$input['resident_id'];
$material = trim($input['material']);
$weight_kg = (float)$input['weight_kg'];
$points_earned = (int)$input['points_earned'];

// Start transaction
$con->begin_transaction();

try {
    // 1. Insert session record
    $stmt = $con->prepare("
        INSERT INTO ecopoint_sessions (resident_id, material, weight_kg, points_earned)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('isdi', $resident_id, $material, $weight_kg, $points_earned);
    $stmt->execute();
    $session_id = $con->insert_id;

    // 2. Update resident's ecopoint balance (max 3000)
    $stmt_balance = $con->prepare("
        UPDATE residents 
        SET ecopoint_balance = LEAST(ecopoint_balance + ?, 3000)
        WHERE id = ?
    ");
    $stmt_balance->bind_param('ii', $points_earned, $resident_id);
    $stmt_balance->execute();

    // 3. Get new balance
    $stmt_new = $con->prepare("
        SELECT ecopoint_balance FROM residents WHERE id = ?
    ");
    $stmt_new->bind_param('i', $resident_id);
    $stmt_new->execute();
    $new_balance = $stmt_new->get_result()->fetch_assoc()['ecopoint_balance'];

    // Commit
    $con->commit();

    $response['success'] = true;
    $response['message'] = 'Session completed successfully';
    $response['data'] = [
        'session_id' => $session_id,
        'new_balance' => (int)$new_balance
    ];

} catch (Exception $e) {
    $con->rollback();
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>