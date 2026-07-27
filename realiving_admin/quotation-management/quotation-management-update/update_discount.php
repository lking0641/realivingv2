<?php
// update_discount.php
$data = json_decode(file_get_contents('php://input'), true);
$client_id = intval($data['client_id'] ?? 0);
$disc      = floatval($data['discount'] ?? 0);

if (!$client_id) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing client_id']));
}

include $includes ['connection'];

$stmt = $conn->prepare("
  UPDATE user_info
     SET discount = ?
   WHERE id = ?
");
if (!$stmt) {
    http_response_code(500);
    exit(json_encode(['error' => 'Prepare failed: ' . $conn->error]));
}
$stmt->bind_param("di", $disc, $client_id);
if (!$stmt->execute()) {
    http_response_code(500);
    exit(json_encode(['error' => 'Execute failed: ' . $stmt->error]));
}

// return success JSON
header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit();
