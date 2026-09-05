<?php
session_start();
require_once $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$id = (int) $_SESSION['admin_id'];
$platform = $_POST['platform'] ?? '';

$allowed = ['wechat' => 'wechat_qr_image', 'viber' => 'viber_qr_image'];
if (!isset($allowed[$platform])) {
    echo json_encode(['success' => false, 'message' => 'Invalid platform.']);
    exit;
}

$column = $allowed[$platform];

// Fetch current path so we can delete the physical file
$stmt = $conn->prepare("SELECT $column FROM account WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!empty($row[$column])) {
    $absolutePath = ROOT_PATH . $row[$column];
    if (file_exists($absolutePath)) {
        unlink($absolutePath);
    }
}

$updateStmt = $conn->prepare("UPDATE account SET $column = NULL WHERE id = ?");
$updateStmt->bind_param('i', $id);

if ($updateStmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$updateStmt->close();