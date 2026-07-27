<?php
//upload_stage_file.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$stage_id = isset($_POST['stage_id']) ? intval($_POST['stage_id']) : 0;
$client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
$stage_name = isset($_POST['stage_name']) ? trim($_POST['stage_name']) : '';
$label = isset($_POST['label']) ? trim($_POST['label']) : '';

if (!$stage_id || !$client_id || !$stage_name) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Get uploader's role and is_head
$uploaderStmt = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$uploaderStmt->bind_param("i", $admin_id);
$uploaderStmt->execute();
$uploader = $uploaderStmt->get_result()->fetch_assoc();
$uploaderRole = $uploader['role'] ?? '';
$uploaderIsHead = (bool)($uploader['is_head'] ?? false);

// Check role permission for this stage (sales uses per-user table, others use role table)
// Check if user is the accountaid_fk for this client — they bypass role permission checks
$accountFkCheckStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkCheckStmt->bind_param("i", $client_id);
$accountFkCheckStmt->execute();
$accountFkRow = $accountFkCheckStmt->get_result()->fetch_assoc();
$isAccountFkUploader = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

if (!$isAccountFkUploader) {
    // Check if user is the accountaid_fk for this client
$accountFkCheckStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkCheckStmt->bind_param("i", $client_id);
$accountFkCheckStmt->execute();
$accountFkRow = $accountFkCheckStmt->get_result()->fetch_assoc();
$isAccountFkUploader = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

if ($uploaderRole === 'sales') {
    // Sales: only check per-user stage_permissions
    $rolePermStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $rolePermStmt->bind_param("is", $admin_id, $stage_name);
    $rolePermStmt->execute();
    $rolePermRow = $rolePermStmt->get_result()->fetch_assoc();
    $hasRolePermission = $rolePermRow && (bool)$rolePermRow['can_update'];
} elseif ($isAccountFkUploader) {
    // accountaid_fk: check BOTH tables, allow if either grants permission
    $rStmt = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role = ? AND stage_name = ?");
    $rStmt->bind_param("ss", $uploaderRole, $stage_name);
    $rStmt->execute();
    $rRow = $rStmt->get_result()->fetch_assoc();
    $hasRolePerm = $rRow && (bool)$rRow['can_update'];

    $uStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $uStmt->bind_param("is", $admin_id, $stage_name);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $hasUserPerm = $uRow && (bool)$uRow['can_update'];

    $hasRolePermission = $hasRolePerm || $hasUserPerm;
} else {
    // All other roles: only check role_stage_permissions
    $rolePermStmt = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role = ? AND stage_name = ?");
    $rolePermStmt->bind_param("ss", $uploaderRole, $stage_name);
    $rolePermStmt->execute();
    $rolePermRow = $rolePermStmt->get_result()->fetch_assoc();
    $hasRolePermission = $rolePermRow && (bool)$rolePermRow['can_update'];
}

if (!$hasRolePermission) {
    echo json_encode(['success' => false, 'error' => 'Access denied: Your role does not have permission for this stage']);
    exit();
}
}

// Check if assigned to this client
$assignStmt = $conn->prepare("
    SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk
    FROM user_info WHERE id = ?
");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$assignment = $assignStmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit();
}

$assignedIds = array_filter([
    $assignment['designer1_id'],
    $assignment['designer2_id'],
    $assignment['technical_designer_id'],
    $assignment['project_coordinator_id'],
    $assignment['accountaid_fk'],
]);

if (!in_array($admin_id, $assignedIds)) {
    echo json_encode(['success' => false, 'error' => 'Access denied: You are not assigned to this client']);
    exit();
}

if (!isset($_FILES['approval_file']) || $_FILES['approval_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit();
}

$file = $_FILES['approval_file'];
$allowed_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
    'text/csv',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp',
    'video/mp4',
    'video/quicktime',
    'video/x-msvideo',
    'video/x-matroska',
    'video/webm',
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed. Videos are not permitted.']);
    exit();
}

$max_size = 1300 * 1024 * 1024; // 1.3GB
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File size exceeds 1.3GB limit']);
    exit();
}

$upload_dir = ROOT_PATH . 'uploads/stage_approvals/' . $client_id . '/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'stage_' . $stage_id . '_' . time() . '_' . uniqid() . '.' . $ext;
$filepath = $upload_dir . $filename;
$db_path = 'uploads/stage_approvals/' . $client_id . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit();
}

// Determine if this is a file-upload stage (no approval needed) or an approval stage
$fileUploadStages = [
    'Reference',
    'Contract Signing (Quotation and Final Layout)',
    'Handover',
    'Accounting (Order Processing)'
];
$isFileUploadStage = in_array($stage_name, $fileUploadStages);

if ($isFileUploadStage) {
    // FILE-UPLOAD STAGE: Always insert a new record, auto-approved, no review needed
    $stmt = $conn->prepare("
        INSERT INTO stage_approvals 
        (stage_id, client_id, stage_name, file_path, file_name, file_type, file_size, label, uploaded_by, uploaded_at, approval_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'approved')
    ");
    $stmt->bind_param("iissssiss", $stage_id, $client_id, $stage_name, $db_path, $file['name'], $mime_type, $file['size'], $label, $admin_id);
    $stmt->execute();

} else {
    // APPROVAL STAGE: Check if this is a resubmission (rejected OR approved)
    $checkRejectedStmt = $conn->prepare("
        SELECT id FROM stage_approvals 
        WHERE stage_id = ? AND uploaded_by = ? AND approval_status IN ('rejected', 'approved')
        ORDER BY uploaded_at DESC
        LIMIT 1
    ");
    $checkRejectedStmt->bind_param("ii", $stage_id, $admin_id);
    $checkRejectedStmt->execute();
    $existingRejected = $checkRejectedStmt->get_result()->fetch_assoc();

    if ($existingRejected) {
        // RESUBMISSION: Update the existing rejected record instead of inserting a new one
        $existingId = $existingRejected['id'];

        // Get the old file path so we can delete it from the server
        $oldFileStmt = $conn->prepare("SELECT file_path FROM stage_approvals WHERE id = ?");
        $oldFileStmt->bind_param("i", $existingId);
        $oldFileStmt->execute();
        $oldFileRow = $oldFileStmt->get_result()->fetch_assoc();

        // Delete the old physical file from the server
        if ($oldFileRow && $oldFileRow['file_path']) {
            $oldFilePath = ROOT_PATH . $oldFileRow['file_path'];
            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }
        }

        // Delete old reviews for this file so all approvers review fresh
        $clearOldStmt = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
        $clearOldStmt->bind_param("i", $existingId);
        $clearOldStmt->execute();

        // Update the existing record with the new file
        $updateFileStmt = $conn->prepare("
            UPDATE stage_approvals 
            SET file_path = ?, file_name = ?, file_type = ?, file_size = ?, label = ?,
                uploaded_at = NOW(), approval_status = 'pending',
                reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
            WHERE id = ?
        ");
        $updateFileStmt->bind_param("sssisi", $db_path, $file['name'], $mime_type, $file['size'], $label, $existingId);
        $updateFileStmt->execute();

    } else {
        // FRESH UPLOAD: Insert a new record
        $stmt = $conn->prepare("
            INSERT INTO stage_approvals 
            (stage_id, client_id, stage_name, file_path, file_name, file_type, file_size, label, uploaded_by, uploaded_at, approval_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
        ");
        $stmt->bind_param("iissssiss", $stage_id, $client_id, $stage_name, $db_path, $file['name'], $mime_type, $file['size'], $label, $admin_id);
        $stmt->execute();
    }
}

// Set stage status to Ongoing (applies to both stage types)
$updateStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_by = ?, updated_at = NOW() WHERE id = ?");
$updateStmt->bind_param("ii", $admin_id, $stage_id);
$updateStmt->execute();

echo json_encode(['success' => true, 'message' => 'File uploaded successfully']);