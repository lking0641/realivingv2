<?php
// loginpage/unlink_google.php
session_start();
require_once __DIR__ . '/../config/app_config.php';
include $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$id = (int) $_SESSION['admin_id'];

$stmt = $conn->prepare("
    UPDATE account 
    SET google_sub = NULL, 
        google_email = NULL, 
        google_picture = NULL,
        avatar_source = IF(avatar_source = 'google', 'custom', avatar_source)
    WHERE id = ?
");
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Google account unlinked.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to unlink. Please try again.']);
}
$stmt->close();