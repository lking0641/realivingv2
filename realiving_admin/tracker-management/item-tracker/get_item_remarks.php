<?php
session_start();
header('Content-Type: application/json');
include '../../connection/connection.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$client_id = intval($_GET['client_id'] ?? 0);
$stage = $_GET['stage'] ?? '';
$item_id = intval($_GET['item_id'] ?? 0);
$source = $_GET['source'] ?? '';
$dist_id = isset($_GET['distribution_id']) ? intval($_GET['distribution_id']) : null;

if ($dist_id) {
    $stmt = $conn->prepare("SELECT r.*, a.full_name AS created_by_name FROM item_status_remarks r LEFT JOIN account a ON r.created_by = a.id WHERE r.client_id=? AND r.stage=? AND r.item_id=? AND r.distribution_id=? ORDER BY r.created_at DESC");
    $stmt->bind_param("isii", $client_id, $stage, $item_id, $dist_id);
} else {
    $stmt = $conn->prepare("SELECT r.*, a.full_name AS created_by_name FROM item_status_remarks r LEFT JOIN account a ON r.created_by = a.id WHERE r.client_id=? AND r.stage=? AND r.item_id=? AND r.distribution_id IS NULL ORDER BY r.created_at DESC");
    $stmt->bind_param("isi", $client_id, $stage, $item_id);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Format dates
foreach ($rows as &$r) {
    $r['created_at'] = date('M d, Y g:i A', strtotime($r['created_at']));
}

echo json_encode(['success' => true, 'remarks' => $rows]);