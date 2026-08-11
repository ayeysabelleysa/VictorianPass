<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../connect.php';

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => ''];

if (!isset($input['resident_id']) || !isset($input['weight_kg'])) {
    $response['message'] = 'resident_id and weight_kg required';
    echo json_encode($response);
    exit;
}

$resident_id = (int)$input['resident_id'];
$weight_kg = (float)$input['weight_kg'];
$material = isset($input['material']) ? trim($input['material']) : null;

// Simple points calculation (replace with your real rates)
$rates = [
    'Plastic' => 55,
    'Aluminum' => 140,
    'Paper' => 30,
    'Cardboard' => 30
];
$rate = isset($rates[$material]) ? $rates[$material] : 50;
$points = (int) floor($weight_kg * $rate);

// Log event
$stmt = $con->prepare("INSERT INTO ecopoint_session_events (resident_id, event_type, meta) VALUES (?, 'weight_update', ?)");
$meta = json_encode(['weight_kg' => $weight_kg, 'points' => $points, 'material' => $material]);
$stmt->bind_param('is', $resident_id, $meta);
$stmt->execute();

$response['success'] = true;
$response['data'] = ['points' => $points, 'weight_kg' => $weight_kg];

echo json_encode($response);
exit;
?>