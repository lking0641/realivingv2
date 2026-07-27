<?php
// delete_fixed_addon.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$addon_id = intval($data['addon_id'] ?? 0);

if (!$addon_id) {
    echo json_encode(['success' => false, 'error' => 'Missing addon_id']);
    exit();
}

$stmt = $conn->prepare("DELETE FROM quotation_fixed_size_addons WHERE id = ?");
$stmt->bind_param("i", $addon_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
?>