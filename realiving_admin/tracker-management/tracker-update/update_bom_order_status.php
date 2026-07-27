<?php
// tracker_step/update_bom_order_status.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input    = json_decode(file_get_contents('php://input'), true);
$bom_id   = intval($input['bom_id']   ?? 0);
$status   = trim($input['status']     ?? '');
$client_id = intval($input['client_id'] ?? 0);

$allowed = ['pending', 'ordered', 'partially_ordered'];
if (!$bom_id || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

// Verify bom belongs to this client
$verifyStmt = $conn->prepare("SELECT id FROM stage_approvals WHERE id = ? AND client_id = ? AND approval_status = 'approved'");
$verifyStmt->bind_param("ii", $bom_id, $client_id);
$verifyStmt->execute();
if (!$verifyStmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'error' => 'BOM not found or not approved']);
    exit();
}

$upsertStmt = $conn->prepare("
    INSERT INTO bom_order_status (bom_approval_id, client_id, status, updated_by, updated_at)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), updated_at = NOW()
");
$upsertStmt->bind_param("iisi", $bom_id, $client_id, $status, $admin_id);
$upsertStmt->execute();

echo json_encode(['success' => true]);