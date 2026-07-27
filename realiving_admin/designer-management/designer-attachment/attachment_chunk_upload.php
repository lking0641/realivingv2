<?php
// attachment_chunk_upload.php
session_start();

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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

set_time_limit(300);

$admin_id         = $_SESSION['admin_id'];
$client_id        = isset($_POST['client_id'])        ? intval($_POST['client_id'])        : 0;
$type             = isset($_POST['attachment_type'])   ? trim($_POST['attachment_type'])    : '';
$area             = isset($_POST['area'])              ? trim($_POST['area'])               : '';
$note             = isset($_POST['note'])              ? trim($_POST['note'])               : '';
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
                    ? intval($_POST['room_unit_number']) : null;
$room_unit_name   = isset($_POST['room_unit_name'])    ? trim($_POST['room_unit_name'])     : '';
$chunk_index      = isset($_POST['chunk_index'])       ? intval($_POST['chunk_index'])      : 0;
$total_chunks     = isset($_POST['total_chunks'])      ? intval($_POST['total_chunks'])     : -1;
$is_last          = isset($_POST['is_last'])           ? ($_POST['is_last'] === 'true')     : false;
$upload_id        = isset($_POST['upload_id'])         ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']) : '';
$original_name    = isset($_POST['original_name'])     ? basename($_POST['original_name']) : '';

$allowed_types = ['site_measurement', 'floor_plan', 'rendering'];

if (!$client_id || !in_array($type, $allowed_types) || !$area || !$upload_id || !$original_name) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Extension check — NO videos
$allowed_extensions = [
    'jpg','jpeg','png','gif','webp','bmp',
    'pdf',
    'doc','docx',
    'xls','xlsx',
    'ppt','pptx',
    'txt','csv',
    'zip','rar'
];
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions)) {
    echo json_encode(['success' => false, 'error' => 'File type .' . $ext . ' is not allowed. Videos are not permitted.']);
    exit();
}

// Verify designer is assigned
$checkStmt = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
$checkStmt->bind_param("i", $client_id);
$checkStmt->execute();
$clientRow = $checkStmt->get_result()->fetch_assoc();
if (!$clientRow || ($clientRow['designer1_id'] != $admin_id && $clientRow['designer2_id'] != $admin_id)) {
    echo json_encode(['success' => false, 'error' => 'Access denied: not assigned to this client']);
    exit();
}

// Check max files limit BEFORE starting upload
if ($chunk_index === 0) {
    if ($room_unit_number !== null) {
        $cntStmt = $conn->prepare("SELECT COUNT(*) FROM layout_attachments WHERE client_id=? AND attachment_type=? AND area=? AND room_unit_number=?");
        $cntStmt->bind_param("issi", $client_id, $type, $area, $room_unit_number);
    } else {
        $cntStmt = $conn->prepare("SELECT COUNT(*) FROM layout_attachments WHERE client_id=? AND attachment_type=? AND area=? AND room_unit_number IS NULL");
        $cntStmt->bind_param("iss", $client_id, $type, $area);
    }
    $cntStmt->execute();
    $cntStmt->bind_result($currentCount);
    $cntStmt->fetch();
    $cntStmt->close();

    if ($currentCount >= 10) {
        echo json_encode(['success' => false, 'error' => 'Maximum of 10 files already reached. Delete some files first.']);
        exit();
    }
}

// Use absolute path — safer on Hostinger shared hosting
$tmp_base  = ROOT_PATH . 'uploads/tmp_chunks/';
$uploadDir = ROOT_PATH . 'uploads/layout_attachments/';

// Auto-create tmp_chunks folder
if (!is_dir($tmp_base)) {
    if (!mkdir($tmp_base, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Cannot create tmp dir at: ' . $tmp_base]);
        exit();
    }
}

// Auto-create layout_attachments folder
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Cannot create upload dir at: ' . $uploadDir]);
        exit();
    }
}

// Save this chunk
$tmp_dir = $tmp_base . $upload_id . '/';
if (!is_dir($tmp_dir)) {
    if (!mkdir($tmp_dir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Cannot create upload session dir. Path: ' . $tmp_dir]);
        exit();
    }
}

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

$safeName = uniqid('att_', true) . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($original_name, PATHINFO_FILENAME)) . '.' . $ext;
if (strlen($safeName) > 200) {
    $safeName = uniqid('att_', true) . '.' . $ext;
}
$filepath = $uploadDir . $safeName;

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

// File size check on assembled file (1.3GB max)
$file_size = filesize($filepath);
$max_size  = 1.3 * 1024 * 1024 * 1024; // 1.3GB
if ($file_size > $max_size) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File exceeds 1.3GB limit']);
    exit();
}

// Atomic count check before insert
if ($room_unit_number !== null) {
    $countCheckStmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_attachments 
        WHERE client_id=? AND attachment_type=? AND area=? AND room_unit_number=?
    ");
    $countCheckStmt->bind_param("issi", $client_id, $type, $area, $room_unit_number);
} else {
    $countCheckStmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_attachments 
        WHERE client_id=? AND attachment_type=? AND area=? AND room_unit_number IS NULL
    ");
    $countCheckStmt->bind_param("iss", $client_id, $type, $area);
}
$countCheckStmt->execute();
$countCheckStmt->bind_result($currentFileCount);
$countCheckStmt->fetch();
$countCheckStmt->close();

if ($currentFileCount >= 10) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Maximum of 10 files already reached. Delete some files first.']);
    exit();
}

// MIME check on assembled file — block videos
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

if (strpos($mimeType, 'video/') === 0) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Video files are not allowed']);
    exit();
}

// Allowed MIME types
$allowed_mimes = [
    'image/jpeg','image/png','image/gif','image/webp','image/bmp','image/svg+xml',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain','text/csv',
    'application/zip','application/x-rar-compressed','application/x-zip-compressed',
];
if (!in_array($mimeType, $allowed_mimes)) {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $mimeType]);
    exit();
}

// Insert into database
$insStmt = $conn->prepare("
    INSERT INTO layout_attachments
    (client_id, uploaded_by, attachment_type, area, room_unit_number, room_unit_name,
     file_name, file_path, file_type, file_size, note)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$insStmt->bind_param(
    "iisssississ",
    $client_id,
    $admin_id,
    $type,
    $area,
    $room_unit_number,
    $room_unit_name,
    $original_name,
    $safeName,
    $mimeType,
    $file_size,
    $note
);

if ($insStmt->execute()) {
    echo json_encode(['success' => true, 'done' => true, 'message' => 'File uploaded successfully']);
} else {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}