<?php
//heartbeat.php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
session_start();
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Not logged in.']);
  exit;
}

$admin_id = $_SESSION['admin_id'];

$stmt = $conn->prepare("UPDATE account SET is_online = 1, last_activity = NOW() WHERE id = ?");
$stmt->bind_param('i', $admin_id);

if ($stmt->execute()) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'Database error.']);
}

$stmt->close();
$conn->close();