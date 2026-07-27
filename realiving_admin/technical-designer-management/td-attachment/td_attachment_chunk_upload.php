<?php
// td_attachment_chunk_upload.php

// Handle OPTIONS before session_start — critical for Hostinger
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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => '405 Method Not Allowed']);
    exit();
}

header('Content-Type: application/json');
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

set_time_limit(360);

$admin_id         = $_SESSION['admin_id'];
$client_id        = isset($_POST['client_id'])        ? intval($_POST['client_id'])        : 0;
$area             = isset($_POST['area'])              ? trim($_POST['area'])               : '';
$category_name    = isset($_POST['category_name'])     ? trim($_POST['category_name'])      : '';
$note             = isset($_POST['note'])              ? trim($_POST['note'])               : '';
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
                    ? intval($_POST['room_unit_number']) : null;
$room_unit_name   = isset($_POST['room_unit_name'])    ? trim($_POST['room_unit_name'])     : '';
$chunk_index      = isset($_POST['chunk_index'])       ? intval($_POST['chunk_index'])      : 0;
$total_chunks     = isset($_POST['total_chunks'])      ? intval($_POST['total_chunks'])     : -1;
$is_last          = isset($_POST['is_last'])           ? ($_POST['is_last'] === 'true')     : false;
$upload_id        = isset($_POST['upload_id'])         ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']) : '';
$original_name    = isset($_POST['original_name'])     ? basename($_POST['original_name']) : '';

if (!$client_id || !$area || !$category_name || !$upload_id || !$original_name) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$allowed_extensions = [
    'jpg','jpeg','png','gif','webp','bmp',
    'pdf',
    'doc','docx',
    'xls','xlsx',
    'ppt','pptx',
    'txt','csv',
    'zip','rar',
    'dwg','dxf'
];
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions)) {
    echo json_encode(['success' => false, 'error' => 'File type .' . $ext . ' is not allowed.']);
    exit();
}

// Access check
$meStmt = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id); $meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$ciStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$ciStmt->bind_param("i", $client_id); $ciStmt->execute();
$ci = $ciStmt->get_result()->fetch_assoc();

$isAssigned = ($ci['technical_designer_id'] == $admin_id);
$canViewAll = in_array($me['role'], ['general_manager','operational_manager'])
           || ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

if (!$isAssigned && !$canViewAll) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}
if ($canViewAll && !$isAssigned) {
    echo json_encode(['success' => false, 'error' => 'View only — cannot upload']);
    exit();
}

// Max files check on first chunk
if ($chunk_index === 0) {
    if ($room_unit_number !== null) {
        $cntStmt = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=? AND room_unit_number=?");
        $cntStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $cntStmt = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=? AND room_unit_number IS NULL");
        $cntStmt->bind_param("is", $client_id, $area);
    }
    $cntStmt->execute();
    $cntStmt->bind_result($currentCount);
    $cntStmt->fetch();
    $cntStmt->close();

    if ($currentCount >= 20) {
        echo json_encode(['success' => false, 'error' => 'Maximum of 20 files already reached.']);
        exit();
    }
}

// Auto-clean temp chunks older than 2 hours
$tmp_base = ROOT_PATH . 'uploads/tmp_chunks/';
if (!is_dir($tmp_base)) {
    mkdir($tmp_base, 0755, true);
} else {
    foreach (glob($tmp_base . '*', GLOB_ONLYDIR) as $old_dir) {
        if (time() - filemtime($old_dir) > 7200) {
            array_map('unlink', glob($old_dir . '/*'));
            @rmdir($old_dir);
        }
    }
}

// Save chunk
$tmp_dir = $tmp_base . $upload_id . '/';
if (!is_dir($tmp_dir)) mkdir($tmp_dir, 0755, true);

if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Chunk upload failed']);
    exit();
}
$chunk_file = $tmp_dir . 'chunk_' . str_pad($chunk_index, 6, '0', STR_PAD_LEFT);
move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_file);

// Dynamic mode: wait for is_last flag
$received = count(glob($tmp_dir . 'chunk_*'));
if (!$is_last) {
    echo json_encode(['success' => true, 'done' => false, 'received' => $received]);
    exit();
}
$total_chunks = $received; // use actual count for assembly

// Assemble file
$uploadDir = ROOT_PATH . 'uploads/td_attachments/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeName = uniqid('td_', true) . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($original_name, PATHINFO_FILENAME)) . '.' . $ext;
if (strlen($safeName) > 200) $safeName = uniqid('td_', true) . '.' . $ext;

$filepath  = $uploadDir . $safeName;
$db_path   = 'uploads/td_attachments/' . $safeName; // relative path for DB
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

// Size check
$file_size = filesize($filepath);
if ($file_size > 1.3 * 1024 * 1024 * 1024) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File exceeds 1.3GB limit']);
    exit();
}

// MIME check — block videos
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

if (strpos($mimeType, 'video/') === 0) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Video files are not allowed']);
    exit();
}

$allowed_mimes = [
    'image/jpeg','image/png','image/gif','image/webp','image/bmp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain','text/csv',
    'application/zip','application/x-rar-compressed','application/x-zip-compressed',
    'application/octet-stream', // DWG/DXF
];
if (!in_array($mimeType, $allowed_mimes) && !in_array($ext, ['dwg','dxf'])) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $mimeType]);
    exit();
}

// Insert into database
$insStmt = $conn->prepare("
    INSERT INTO td_attachments
        (client_id, area, room_unit_number, category_name, file_name, file_path, file_type, file_size, note, uploaded_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)
");
$insStmt->bind_param(
    "isissssisi",
    $client_id, $area, $room_unit_number, $category_name,
    $original_name, $db_path, $mimeType, $file_size, $note, $admin_id
);

if ($insStmt->execute()) {
    echo json_encode(['success' => true, 'done' => true, 'message' => 'File uploaded successfully']);
} else {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}