<?php
// delete_fixed_entry.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$fixed_id = intval($data['fixed_id'] ?? 0);

if (!$fixed_id) {
    echo json_encode(['success' => false, 'error' => 'Missing fixed_id']);
    exit();
}

// Delete will cascade to addons due to foreign key
$stmt = $conn->prepare("DELETE FROM quotation_fixed_sizes WHERE id = ?");
$stmt->bind_param("i", $fixed_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
?>