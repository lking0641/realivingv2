<?php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$client_id  = intval($input['client_id']          ?? 0);
$total_cost = floatval($input['total_project_cost'] ?? 0);
$remaining  = floatval($input['remaining_balance']  ?? 0);

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit();
}

$stmt = $conn->prepare("
    UPDATE user_info 
    SET total_project_cost = ?,
        remaining_balance  = ?
    WHERE id = ?
");
$stmt->bind_param("ddi", $total_cost, $remaining, $client_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>