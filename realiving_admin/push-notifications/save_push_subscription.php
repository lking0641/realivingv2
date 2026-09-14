<?php
session_start();
include $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit();
}

$endpoint = $data['endpoint'];
$p256dh = $data['keys']['p256dh'];
$auth = $data['keys']['auth'];

$stmt = $conn->prepare("
    INSERT INTO push_subscriptions (admin_id, endpoint, p256dh, auth)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), p256dh = VALUES(p256dh), auth = VALUES(auth)
");
$stmt->bind_param("isss", $admin_id, $endpoint, $p256dh, $auth);
$stmt->execute();

echo json_encode(['success' => true]);