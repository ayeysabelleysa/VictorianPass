<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../connect.php';

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => ''];

if (!isset($input['resident_id'])) {
    $response['message'] = 'resident_id required';
    echo json_encode($response);
    exit;
}

$resident_id = (int)$input['resident_id'];
$material = isset($input['material']) ? trim($input['material']) : null;

// Optionally update a current_sessions table or log the event
$stmt = $con->prepare("INSERT INTO ecopoint_session_events (resident_id, event_type, meta) VALUES (?, 'start', ?)");
$meta = json_encode(['material' => $material]);
$stmt->bind_param('is', $resident_id, $meta);
$stmt->execute();

$response['success'] = true;
$response['message'] = 'session started recorded';

echo json_encode($response);
exit;
?>