<?php
//site_visit_status_update.php
session_start();
header('Content-Type: application/json');
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Enforce super_admin only
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$adminRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? '';

if ($adminRole !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$visit_id = isset($data['visit_id']) ? intval($data['visit_id']) : 0;
$status = isset($data['status']) ? trim($data['status']) : '';

if (!$visit_id || !in_array($status, ['Pending', 'Ongoing', 'Done'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Confirm visit exists
$checkStmt = $conn->prepare("SELECT * FROM site_visit WHERE id = ?");
$checkStmt->bind_param("i", $visit_id);
$checkStmt->execute();
$visit = $checkStmt->get_result()->fetch_assoc();
if (!$visit) {
    echo json_encode(['success' => false, 'error' => 'Visit not found']);
    exit();
}

$updateStmt = $conn->prepare("UPDATE site_visit SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
$updateStmt->bind_param("sii", $status, $admin_id, $visit_id);

if ($updateStmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Status updated']);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}