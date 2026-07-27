<?php
session_start();
require_once $includes['connection']; // adjust path to your DB connection

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$id = (int) $_SESSION['admin_id'];
$result = $conn->query("SELECT full_name, email, role, e_signature FROM account WHERE id = $id LIMIT 1");

if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, ...$row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Account not found']);
}