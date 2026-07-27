<?php
//delete_stage_file.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input = json_decode(file_get_contents('php://input'), true);
$approval_id = intval($input['approval_id'] ?? 0);
$stage_id = intval($input['stage_id'] ?? 0);

if (!$approval_id || !$stage_id) {
    echo json_encode(['success' => false, 'error' => 'Missing fields']);
    exit();
}

// Only allow the uploader to delete, and only if not yet approved
$checkStmt = $conn->prepare("SELECT id, file_path, uploaded_by, approval_status, linked_po_id, linked_bom_id FROM stage_approvals WHERE id = ? AND uploaded_by = ?");
$checkStmt->bind_param("ii", $approval_id, $admin_id);
$checkStmt->execute();
$file = $checkStmt->get_result()->fetch_assoc();

if (!$file) {
    echo json_encode(['success' => false, 'error' => 'File not found or no permission']);
    exit();
}

// Check if this is a file-upload stage (no approvers — auto-approved on upload)
$fileUploadStages = ['Reference', 'Contract Signing (Quotation and Final Layout)', 'Handover'];
$stageNameStmt = $conn->prepare("SELECT stage_name FROM project_tracker WHERE id = ?");
$stageNameStmt->bind_param("i", $stage_id);
$stageNameStmt->execute();
$stageNameRow = $stageNameStmt->get_result()->fetch_assoc();
$stageName = $stageNameRow['stage_name'] ?? '';
$isFileUploadStage = in_array($stageName, $fileUploadStages);

// Check if this is a receipt (has linked_po_id) — receipts can always be deleted by uploader
$isReceipt = !empty($file['linked_po_id']);

// Check if this is a PO (has linked_bom_id) — rejected POs can be deleted
$isLinkedPO = !empty($file['linked_bom_id']);

if ($file['approval_status'] === 'approved' && !$isFileUploadStage && !$isReceipt) {
    // Block if it's a BOM that already has linked POs
    $linkedPosStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM stage_approvals WHERE linked_bom_id = ?");
    $linkedPosStmt->bind_param("i", $approval_id);
    $linkedPosStmt->execute();
    $linkedPosCount = $linkedPosStmt->get_result()->fetch_assoc()['cnt'];
    if ($linkedPosCount > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete a BOM that already has linked Purchase Orders']);
        exit();
    }
    echo json_encode(['success' => false, 'error' => 'Cannot delete an already approved file']);
    exit();
}

// Delete physical file
$physicalPath = ROOT_PATH . $file['file_path'];
if (file_exists($physicalPath)) {
    unlink($physicalPath);
}

// Delete DB record
$delStmt = $conn->prepare("DELETE FROM stage_approvals WHERE id = ?");
$delStmt->bind_param("i", $approval_id);
$delStmt->execute();

// If no more files, set stage back to Pending
$countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM stage_approvals WHERE stage_id = ?");
$countStmt->bind_param("i", $stage_id);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();

if ($countRow['cnt'] == 0) {
    $resetStmt = $conn->prepare("UPDATE project_tracker SET status = 'Pending', updated_by = ?, updated_at = NOW() WHERE id = ?");
    $resetStmt->bind_param("ii", $admin_id, $stage_id);
    $resetStmt->execute();
}

echo json_encode(['success' => true]);