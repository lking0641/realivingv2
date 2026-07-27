<?php
// chunk_upload.php
session_start();

// Set headers early to prevent caching issues on Hostinger
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Force allow POST — fix for 405 on some Hostinger configs
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: POST, OPTIONS');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => '405 Method Not Allowed']);
    exit();
}

header('Content-Type: application/json');

// FIX 3 — Extend time limit for large file assembly
set_time_limit(600);

include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id   = $_SESSION['admin_id'];
$stage_id   = isset($_POST['stage_id'])    ? intval($_POST['stage_id'])    : 0;
$client_id  = isset($_POST['client_id'])   ? intval($_POST['client_id'])   : 0;
$stage_name = isset($_POST['stage_name'])  ? trim($_POST['stage_name'])    : '';
$label      = isset($_POST['label'])       ? trim($_POST['label'])         : '';
$chunk_index  = isset($_POST['chunk_index'])  ? intval($_POST['chunk_index'])  : 0;
$total_chunks = isset($_POST['total_chunks']) ? intval($_POST['total_chunks']) : -1;
$is_last      = isset($_POST['is_last'])      ? ($_POST['is_last'] === 'true') : false;
$upload_id    = isset($_POST['upload_id'])    ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']) : '';
$original_name = isset($_POST['original_name']) ? basename($_POST['original_name']) : '';

if (!$stage_id || !$client_id || !$stage_name || !$upload_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// FIX 1 — Auto-clean temp chunks older than 24 hours
$doc_root = rtrim(ROOT_PATH, '/');
$tmp_base = $doc_root . '/uploads/tmp_chunks/';
if (!is_dir($tmp_base)) {
    mkdir($tmp_base, 0755, true);
} else {
    foreach (glob($tmp_base . '*', GLOB_ONLYDIR) as $old_dir) {
        if (time() - filemtime($old_dir) > 86400) {
            array_map('unlink', glob($old_dir . '/*'));
            @rmdir($old_dir);
        }
    }
}

// Permission check
$uploaderStmt = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$uploaderStmt->bind_param("i", $admin_id);
$uploaderStmt->execute();
$uploader = $uploaderStmt->get_result()->fetch_assoc();
$uploaderRole = $uploader['role'] ?? '';

// Check if user is the accountaid_fk for this client — they bypass role permission checks
$accountFkCheckStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkCheckStmt->bind_param("i", $client_id);
$accountFkCheckStmt->execute();
$accountFkRow = $accountFkCheckStmt->get_result()->fetch_assoc();
$isAccountFkUploader = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

// Permission check — determine based on role and assignment
if ($uploaderRole === 'sales') {
    // Sales: only per-user stage_permissions table
    $rolePermStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $rolePermStmt->bind_param("is", $admin_id, $stage_name);
    $rolePermStmt->execute();
    $rolePermRow  = $rolePermStmt->get_result()->fetch_assoc();
    $hasPermission = $rolePermRow && (bool)$rolePermRow['can_update'];

} elseif ($isAccountFkUploader) {
    // Primary assigned user: check BOTH tables, allow if either grants permission
    $rStmt = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role = ? AND stage_name = ?");
    $rStmt->bind_param("ss", $uploaderRole, $stage_name);
    $rStmt->execute();
    $rRow        = $rStmt->get_result()->fetch_assoc();
    $hasRolePerm = $rRow && (bool)$rRow['can_update'];

    $uStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $uStmt->bind_param("is", $admin_id, $stage_name);
    $uStmt->execute();
    $uRow        = $uStmt->get_result()->fetch_assoc();
    $hasUserPerm = $uRow && (bool)$uRow['can_update'];

    $hasPermission = $hasRolePerm || $hasUserPerm;

} else {
    // All other roles: only role_stage_permissions
    $rolePermStmt = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role = ? AND stage_name = ?");
    $rolePermStmt->bind_param("ss", $uploaderRole, $stage_name);
    $rolePermStmt->execute();
    $rolePermRow  = $rolePermStmt->get_result()->fetch_assoc();
    $hasPermission = $rolePermRow && (bool)$rolePermRow['can_update'];
}

if (!$hasPermission) {
    echo json_encode(['success' => false, 'error' => 'Access denied: no permission for this stage']);
    exit();
}

// Assignment check
$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk FROM user_info WHERE id = ?");
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
    echo json_encode(['success' => false, 'error' => 'Access denied: not assigned to this client']);
    exit();
}

// Temp folder for chunks
$tmp_dir = $tmp_base . $upload_id . '/';
if (!is_dir($tmp_dir)) {
    mkdir($tmp_dir, 0755, true);
}

// Save this chunk
if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Chunk upload failed']);
    exit();
}
$chunk_file = $tmp_dir . 'chunk_' . str_pad($chunk_index, 6, '0', STR_PAD_LEFT);
move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_file);

// Dynamic mode: wait for is_last flag instead of fixed total_chunks
$received = count(glob($tmp_dir . 'chunk_*'));
if (!$is_last) {
    echo json_encode(['success' => true, 'done' => false, 'received' => $received]);
    exit();
}
$total_chunks = $received; // use actual count for assembly

// All chunks received — assemble the file
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
$final_dir = $doc_root . '/uploads/stage_approvals/' . $client_id . '/';
if (!is_dir($final_dir)) {
    mkdir($final_dir, 0755, true);
}
$filename = 'stage_' . $stage_id . '_' . time() . '_' . uniqid() . '.' . $ext;
$filepath = $final_dir . $filename;
$db_path  = 'uploads/stage_approvals/' . $client_id . '/' . $filename;

$out = fopen($filepath, 'wb');
if (!$out) {
    echo json_encode(['success' => false, 'error' => 'Could not create output file']);
    exit();
}

// Lock the file during assembly to prevent concurrent write conflicts
if (!flock($out, LOCK_EX)) {
    fclose($out);
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Could not lock file for assembly. Please try again.']);
    exit();
}

for ($i = 0; $i < $total_chunks; $i++) {
    $chunk_path = $tmp_dir . 'chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
    $in = fopen($chunk_path, 'rb');
    if (!$in) {
        flock($out, LOCK_UN);
        fclose($out);
        unlink($filepath);
        echo json_encode(['success' => false, 'error' => 'Missing chunk ' . $i . '. Please try again.']);
        exit();
    }
    while (!feof($in)) {
        fwrite($out, fread($in, 1024 * 1024));
    }
    fclose($in);
    unlink($chunk_path);
}

// Release lock and close
flock($out, LOCK_UN);
fclose($out);
rmdir($tmp_dir);

// MIME check on assembled file
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $filepath);
finfo_close($finfo);

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

if (!in_array($mime_type, $allowed_types)) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit();
}

$file_size = filesize($filepath);

// Atomic count check before insert
$countCheckStmt = $conn->prepare("
    SELECT COUNT(*) FROM stage_approvals 
    WHERE stage_id = ? AND approval_status != 'rejected'
");
$countCheckStmt->bind_param("i", $stage_id);
$countCheckStmt->execute();
$countCheckStmt->bind_result($currentFileCount);
$countCheckStmt->fetch();
$countCheckStmt->close();

if ($currentFileCount >= 20) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Maximum file limit reached. Please delete some files first.']);
    exit();
}

// FIX 2 — Server-side file size enforcement
$max_allowed = 1.4 * 1024 * 1024 * 1024; // 1.4GB
if ($file_size > $max_allowed) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File exceeds maximum allowed size of 1.4GB']);
    exit();
}

// DB insert
$fileUploadStages = ['Reference','Contract Signing (Quotation and Final Layout)','Handover','Accounting (Order Processing)'];
$isFileUploadStage = in_array($stage_name, $fileUploadStages);

// Accept optional linked_po_id for receipt uploads (Accounting stage)
$linked_po_id = isset($_POST['linked_po_id']) && intval($_POST['linked_po_id']) > 0
    ? intval($_POST['linked_po_id'])
    : null;

// Accept optional linked_bom_id for PO uploads (Purchase Order stage)
$linked_bom_id = isset($_POST['linked_bom_id']) && intval($_POST['linked_bom_id']) > 0
    ? intval($_POST['linked_bom_id'])
    : null;

if ($isFileUploadStage) {
    $stmt = $conn->prepare("INSERT INTO stage_approvals (stage_id, client_id, stage_name, file_path, file_name, file_type, file_size, label, uploaded_by, uploaded_at, approval_status, linked_po_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'approved', ?)");
    $stmt->bind_param("iissssissi", $stage_id, $client_id, $stage_name, $db_path, $original_name, $mime_type, $file_size, $label, $admin_id, $linked_po_id);
    $stmt->execute();
} elseif ($stage_name === 'Purchase Order (Submit to accounting)') {
    // PO stage: insert with linked_bom_id, goes through approval flow
    $conn->begin_transaction();
$checkRejectedStmt = $conn->prepare("
    SELECT id FROM stage_approvals 
    WHERE stage_id = ? AND uploaded_by = ? AND approval_status = 'rejected' AND label = ?
    ORDER BY uploaded_at DESC LIMIT 1
    FOR UPDATE
");
$checkRejectedStmt->bind_param("iis", $stage_id, $admin_id, $label);
$checkRejectedStmt->execute();
$existingRejected = $checkRejectedStmt->get_result()->fetch_assoc();

if ($existingRejected) {
    $existingId = $existingRejected['id'];
        $oldFileStmt = $conn->prepare("SELECT file_path FROM stage_approvals WHERE id = ?");
        $oldFileStmt->bind_param("i", $existingId);
        $oldFileStmt->execute();
        $oldFileRow = $oldFileStmt->get_result()->fetch_assoc();
        if ($oldFileRow && $oldFileRow['file_path']) {
            $old = ROOT_PATH . $oldFileRow['file_path'];
            if (file_exists($old)) unlink($old);
        }
        $clearOldStmt = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
        $clearOldStmt->bind_param("i", $existingId);
        $clearOldStmt->execute();
        $updateFileStmt = $conn->prepare("UPDATE stage_approvals SET file_path=?, file_name=?, file_type=?, file_size=?, label=?, uploaded_at=NOW(), approval_status='pending', reviewed_by=NULL, reviewed_at=NULL, review_note=NULL, linked_bom_id=? WHERE id=?");
        $updateFileStmt->bind_param("sssissi", $db_path, $original_name, $mime_type, $file_size, $label, $linked_bom_id, $existingId);
        $updateFileStmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO stage_approvals (stage_id, client_id, stage_name, file_path, file_name, file_type, file_size, label, uploaded_by, uploaded_at, approval_status, linked_bom_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)");
        $stmt->bind_param("iissssissi", $stage_id, $client_id, $stage_name, $db_path, $original_name, $mime_type, $file_size, $label, $admin_id, $linked_bom_id);
        $stmt->execute();
    }
    $conn->commit();
} else {
    // Only overwrite if there is a REJECTED file with the EXACT same label by the same user
    // Never overwrite approved files — always insert fresh
    $conn->begin_transaction();
$checkRejectedStmt = $conn->prepare("
    SELECT id FROM stage_approvals 
    WHERE stage_id = ? AND uploaded_by = ? AND approval_status = 'rejected' AND label = ?
    ORDER BY uploaded_at DESC LIMIT 1
    FOR UPDATE
");
$checkRejectedStmt->bind_param("iis", $stage_id, $admin_id, $label);
$checkRejectedStmt->execute();
$existingRejected = $checkRejectedStmt->get_result()->fetch_assoc();

if ($existingRejected) {
    $existingId = $existingRejected['id'];
        $oldFileStmt = $conn->prepare("SELECT file_path FROM stage_approvals WHERE id = ?");
        $oldFileStmt->bind_param("i", $existingId);
        $oldFileStmt->execute();
        $oldFileRow = $oldFileStmt->get_result()->fetch_assoc();
        if ($oldFileRow && $oldFileRow['file_path']) {
            $old = ROOT_PATH . $oldFileRow['file_path'];
            if (file_exists($old)) unlink($old);
        }
        $clearOldStmt = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
        $clearOldStmt->bind_param("i", $existingId);
        $clearOldStmt->execute();
        $updateFileStmt = $conn->prepare("UPDATE stage_approvals SET file_path=?, file_name=?, file_type=?, file_size=?, label=?, uploaded_at=NOW(), approval_status='pending', reviewed_by=NULL, reviewed_at=NULL, review_note=NULL WHERE id=?");
        $updateFileStmt->bind_param("sssisi", $db_path, $original_name, $mime_type, $file_size, $label, $existingId);
        $updateFileStmt->execute();
    } else {
        // Fresh upload — always insert a new record
        $stmt = $conn->prepare("INSERT INTO stage_approvals (stage_id, client_id, stage_name, file_path, file_name, file_type, file_size, label, uploaded_by, uploaded_at, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')");
        $stmt->bind_param("iissssiss", $stage_id, $client_id, $stage_name, $db_path, $original_name, $mime_type, $file_size, $label, $admin_id);
        $stmt->execute();
    }
    $conn->commit();
}

// If Internal P.O to Accounting: reset approval when new file is uploaded
if ($stage_name === 'Internal P.O to Accounting') {
    // Delete any existing pending or approved internal_po_approval record
    $resetIpoStmt = $conn->prepare("DELETE FROM internal_po_approvals WHERE stage_id = ? AND overall_status IN ('approved', 'pending')");
    $resetIpoStmt->bind_param("i", $stage_id);
    $resetIpoStmt->execute();

    // Revert all previously approved files back to pending
    $revertFilesStmt = $conn->prepare("UPDATE stage_approvals SET approval_status = 'pending' WHERE stage_id = ? AND approval_status = 'approved'");
    $revertFilesStmt->bind_param("i", $stage_id);
    $revertFilesStmt->execute();

    // Revert stage back to Ongoing if it was Done
    $revertStageStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW() WHERE id = ? AND status = 'Done'");
    $revertStageStmt->bind_param("i", $stage_id);
    $revertStageStmt->execute();
}

// Update stage status to Ongoing
$updateStmt = $conn->prepare("UPDATE project_tracker SET status='Ongoing', updated_by=?, updated_at=NOW() WHERE id=?");
$updateStmt->bind_param("ii", $admin_id, $stage_id);
$updateStmt->execute();

// Cascade: if BOM gets a new file (goes Ongoing), revert PO and Accounting to Ongoing if they were Done
// If PO gets a new file (goes Ongoing), revert Accounting to Ongoing if it was Done
if ($stage_name === 'Bill of Materials (BOM)') {
    $cascadeUploadStmt = $conn->prepare("
        UPDATE project_tracker
        SET status = 'Ongoing', updated_at = NOW()
        WHERE client_id = ?
          AND stage_name IN ('Purchase Order (Submit to accounting)', 'Accounting (Order Processing)')
          AND status = 'Done'
    ");
    $cascadeUploadStmt->bind_param("i", $client_id);
    $cascadeUploadStmt->execute();
} elseif ($stage_name === 'Purchase Order (Submit to accounting)') {
    $cascadeUploadStmt = $conn->prepare("
        UPDATE project_tracker
        SET status = 'Ongoing', updated_at = NOW()
        WHERE client_id = ?
          AND stage_name = 'Accounting (Order Processing)'
          AND status = 'Done'
    ");
    $cascadeUploadStmt->bind_param("i", $client_id);
    $cascadeUploadStmt->execute();
}

echo json_encode(['success' => true, 'done' => true, 'message' => 'File uploaded successfully']);