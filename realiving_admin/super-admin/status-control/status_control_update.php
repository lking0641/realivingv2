<?php
// status_control_update.php
session_start();
header('Content-Type: application/json');
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Enforce super_admin only — direct DB check, hindi dependent sa mainbody.php helper
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$adminRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? '';

if ($adminRole !== 'super_admin') { // palitan kung 'superadmin' ang tama sa DB mo
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$stage_id = isset($data['stage_id']) ? intval($data['stage_id']) : 0;
$status = isset($data['status']) ? trim($data['status']) : '';

if (!$stage_id || !in_array($status, ['Pending', 'Ongoing', 'Done'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

$stmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
$stmt->bind_param("sii", $status, $admin_id, $stage_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}