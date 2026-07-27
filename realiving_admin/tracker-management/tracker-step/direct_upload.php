<?php
// direct_upload.php — single-shot upload for files ≤50MB
session_start();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: POST, OPTIONS');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    header('Content-Type: text/html; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

header('Content-Type: text/html; charset=utf-8');

set_time_limit(120);
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id   = $_SESSION['admin_id'];
$stage_id   = isset($_POST['stage_id'])   ? intval($_POST['stage_id'])   : 0;
$client_id  = isset($_POST['client_id'])  ? intval($_POST['client_id'])  : 0;
$stage_name = isset($_POST['stage_name']) ? trim($_POST['stage_name'])   : '';
$label      = isset($_POST['label'])      ? trim($_POST['label'])        : '';

if (!$stage_id || !$client_id || !$stage_name || !$label) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File too large.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'No temp directory.',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write file.',
    ];
    $code = $_FILES['file']['error'] ?? -1;
    echo json_encode(['success' => false, 'error' => $phpErrors[$code] ?? 'Upload error ' . $code]);
    exit();
}

$file     = $_FILES['file'];
$max_size = 50 * 1024 * 1024; // 50MB hard limit
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File exceeds 50MB direct upload limit. Use chunked mode.']);
    exit();
}

// ── Permission check (mirrors chunk_upload.php) ──────────────────
$uploaderStmt = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$uploaderStmt->bind_param("i", $admin_id);
$uploaderStmt->execute();
$uploader     = $uploaderStmt->get_result()->fetch_assoc();
$uploaderRole = $uploader['role'] ?? '';

$accountFkStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkStmt->bind_param("i", $client_id);
$accountFkStmt->execute();
$accountFkRow  = $accountFkStmt->get_result()->fetch_assoc();
$isAccountFk   = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

if ($uploaderRole === 'sales') {
    $p = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id=? AND stage_name=?");
    $p->bind_param("is", $admin_id, $stage_name); $p->execute();
    $pr = $p->get_result()->fetch_assoc();
    $hasPermission = $pr && (bool)$pr['can_update'];
} elseif ($isAccountFk) {
    $r = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role=? AND stage_name=?");
    $r->bind_param("ss", $uploaderRole, $stage_name); $r->execute();
    $rr = $r->get_result()->fetch_assoc();
    $u = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id=? AND stage_name=?");
    $u->bind_param("is", $admin_id, $stage_name); $u->execute();
    $ur = $u->get_result()->fetch_assoc();
    $hasPermission = ($rr && (bool)$rr['can_update']) || ($ur && (bool)$ur['can_update']);
} else {
    $p = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role=? AND stage_name=?");
    $p->bind_param("ss", $uploaderRole, $stage_name); $p->execute();
    $pr = $p->get_result()->fetch_assoc();
    $hasPermission = $pr && (bool)$pr['can_update'];
}

if (!$hasPermission) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Assignment check
$aStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk FROM user_info WHERE id=?");
$aStmt->bind_param("i", $client_id); $aStmt->execute();
$assignment = $aStmt->get_result()->fetch_assoc();
$assignedIds = array_filter([
    $assignment['designer1_id'], $assignment['designer2_id'],
    $assignment['technical_designer_id'], $assignment['project_coordinator_id'],
    $assignment['accountaid_fk'],
]);
if (!in_array($admin_id, $assignedIds)) {
    echo json_encode(['success' => false, 'error' => 'Not assigned to this client']);
    exit();
}

// ── MIME check ──────────────────────────────────────────────────
$finfo     = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
    'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm',
];
if (!in_array($mime_type, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit();
}

// ── Save file ──────────────────────────────────────────────────
$doc_root  = rtrim(ROOT_PATH, '/');
$final_dir = $doc_root . '/uploads/stage_approvals/' . $client_id . '/';
if (!is_dir($final_dir)) mkdir($final_dir, 0755, true);

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'stage_' . $stage_id . '_' . time() . '_' . uniqid() . '.' . $ext;
$filepath = $final_dir . $filename;
$db_path  = 'uploads/stage_approvals/' . $client_id . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Could not save file']);
    exit();
}

$file_size     = filesize($filepath);
$original_name = basename($file['name']);

// File limit check
$cStmt = $conn->prepare("SELECT COUNT(*) FROM stage_approvals WHERE stage_id=? AND approval_status != 'rejected'");
$cStmt->bind_param("i", $stage_id); $cStmt->execute();
$cStmt->bind_result($cnt); $cStmt->fetch(); $cStmt->close();
if ($cnt >= 20) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Maximum file limit (20) reached']);
    exit();
}

// ── DB insert (mirrors chunk_upload.php logic) ──────────────────
$linked_po_id  = isset($_POST['linked_po_id'])  && intval($_POST['linked_po_id'])  > 0 ? intval($_POST['linked_po_id'])  : null;
$linked_bom_id = isset($_POST['linked_bom_id']) && intval($_POST['linked_bom_id']) > 0 ? intval($_POST['linked_bom_id']) : null;

$fileUploadStages = ['Reference','Contract Signing (Quotation and Final Layout)','Handover','Accounting (Order Processing)'];
$isFileUploadStage = in_array($stage_name, $fileUploadStages);

if ($isFileUploadStage) {
    $s = $conn->prepare("INSERT INTO stage_approvals (stage_id,client_id,stage_name,file_path,file_name,file_type,file_size,label,uploaded_by,uploaded_at,approval_status,linked_po_id) VALUES (?,?,?,?,?,?,?,?,?,NOW(),'approved',?)");
    $s->bind_param("iissssissi", $stage_id,$client_id,$stage_name,$db_path,$original_name,$mime_type,$file_size,$label,$admin_id,$linked_po_id);
    $s->execute();
} elseif ($stage_name === 'Purchase Order (Submit to accounting)') {
    $conn->begin_transaction();
    $cr = $conn->prepare("SELECT id FROM stage_approvals WHERE stage_id=? AND uploaded_by=? AND approval_status='rejected' AND label=? ORDER BY uploaded_at DESC LIMIT 1 FOR UPDATE");
    $cr->bind_param("iis", $stage_id,$admin_id,$label); $cr->execute();
    $existing = $cr->get_result()->fetch_assoc();
    if ($existing) {
        $eid = $existing['id'];
        $of = $conn->prepare("SELECT file_path FROM stage_approvals WHERE id=?");
        $of->bind_param("i",$eid); $of->execute();
        $ofr = $of->get_result()->fetch_assoc();
        if ($ofr && $ofr['file_path'] && file_exists($doc_root.'/'.$ofr['file_path'])) unlink($doc_root.'/'.$ofr['file_path']);
        $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id=?")->execute() || null;
        $clr = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id=?");
        $clr->bind_param("i",$eid); $clr->execute();
        $upd = $conn->prepare("UPDATE stage_approvals SET file_path=?,file_name=?,file_type=?,file_size=?,label=?,uploaded_at=NOW(),approval_status='pending',reviewed_by=NULL,reviewed_at=NULL,review_note=NULL,linked_bom_id=? WHERE id=?");
        $upd->bind_param("sssissi",$db_path,$original_name,$mime_type,$file_size,$label,$linked_bom_id,$eid);
        $upd->execute();
    } else {
        $s = $conn->prepare("INSERT INTO stage_approvals (stage_id,client_id,stage_name,file_path,file_name,file_type,file_size,label,uploaded_by,uploaded_at,approval_status,linked_bom_id) VALUES (?,?,?,?,?,?,?,?,?,NOW(),'pending',?)");
        $s->bind_param("iissssissi",$stage_id,$client_id,$stage_name,$db_path,$original_name,$mime_type,$file_size,$label,$admin_id,$linked_bom_id);
        $s->execute();
    }
    $conn->commit();
} else {
    $conn->begin_transaction();
    $cr = $conn->prepare("SELECT id FROM stage_approvals WHERE stage_id=? AND uploaded_by=? AND approval_status='rejected' AND label=? ORDER BY uploaded_at DESC LIMIT 1 FOR UPDATE");
    $cr->bind_param("iis",$stage_id,$admin_id,$label); $cr->execute();
    $existing = $cr->get_result()->fetch_assoc();
    if ($existing) {
        $eid = $existing['id'];
        $of = $conn->prepare("SELECT file_path FROM stage_approvals WHERE id=?");
        $of->bind_param("i",$eid); $of->execute();
        $ofr = $of->get_result()->fetch_assoc();
        if ($ofr && $ofr['file_path'] && file_exists($doc_root.'/'.$ofr['file_path'])) unlink($doc_root.'/'.$ofr['file_path']);
        $clr = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id=?");
        $clr->bind_param("i",$eid); $clr->execute();
        $upd = $conn->prepare("UPDATE stage_approvals SET file_path=?,file_name=?,file_type=?,file_size=?,label=?,uploaded_at=NOW(),approval_status='pending',reviewed_by=NULL,reviewed_at=NULL,review_note=NULL WHERE id=?");
        $upd->bind_param("sssisi",$db_path,$original_name,$mime_type,$file_size,$label,$eid);
        $upd->execute();
    } else {
        $s = $conn->prepare("INSERT INTO stage_approvals (stage_id,client_id,stage_name,file_path,file_name,file_type,file_size,label,uploaded_by,uploaded_at,approval_status) VALUES (?,?,?,?,?,?,?,?,?,NOW(),'pending')");
        $s->bind_param("iissssiss",$stage_id,$client_id,$stage_name,$db_path,$original_name,$mime_type,$file_size,$label,$admin_id);
        $s->execute();
    }
    $conn->commit();
}

// If Internal P.O to Accounting: reset approval when new file is uploaded
if ($stage_name === 'Internal P.O to Accounting') {
    $resetIpoStmt = $conn->prepare("DELETE FROM internal_po_approvals WHERE stage_id = ? AND overall_status IN ('approved', 'pending')");
    $resetIpoStmt->bind_param("i", $stage_id);
    $resetIpoStmt->execute();

    $revertFilesStmt = $conn->prepare("UPDATE stage_approvals SET approval_status = 'pending' WHERE stage_id = ? AND approval_status = 'approved'");
    $revertFilesStmt->bind_param("i", $stage_id);
    $revertFilesStmt->execute();

    $revertStageStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW() WHERE id = ? AND status = 'Done'");
    $revertStageStmt->bind_param("i", $stage_id);
    $revertStageStmt->execute();
}

// Update stage to Ongoing + cascades
$u = $conn->prepare("UPDATE project_tracker SET status='Ongoing',updated_by=?,updated_at=NOW() WHERE id=?");
$u->bind_param("ii",$admin_id,$stage_id); $u->execute();

if ($stage_name === 'Bill of Materials (BOM)') {
    $c = $conn->prepare("UPDATE project_tracker SET status='Ongoing',updated_at=NOW() WHERE client_id=? AND stage_name IN ('Purchase Order (Submit to accounting)','Accounting (Order Processing)') AND status='Done'");
    $c->bind_param("i",$client_id); $c->execute();
} elseif ($stage_name === 'Purchase Order (Submit to accounting)') {
    $c = $conn->prepare("UPDATE project_tracker SET status='Ongoing',updated_at=NOW() WHERE client_id=? AND stage_name='Accounting (Order Processing)' AND status='Done'");
    $c->bind_param("i",$client_id); $c->execute();
}

echo json_encode(['success' => true, 'done' => true, 'message' => 'File uploaded successfully']);