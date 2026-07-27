<?php
// update_fixed_computation.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$fixed_id = intval($data['fixed_id'] ?? 0);
$field = $data['field'] ?? '';
$value = $data['value'] ?? '';

if (!$fixed_id || !$field) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

// Allowed fields
$allowed = ['base_price', 'quantity'];
if (!in_array($field, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit();
}

$stmt = $conn->prepare("UPDATE quotation_fixed_sizes SET $field = ? WHERE id = ?");
$stmt->bind_param("di", $value, $fixed_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
?>