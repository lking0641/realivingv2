<?php
// td_attachment_direct_upload.php — direct upload for files ≤50MB

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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

header('Content-Type: text/html; charset=utf-8');

set_time_limit(120);
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id         = $_SESSION['admin_id'];
$client_id        = isset($_POST['client_id'])      ? intval($_POST['client_id'])      : 0;
$area             = isset($_POST['area'])            ? trim($_POST['area'])             : '';
$category_name    = isset($_POST['category_name'])   ? trim($_POST['category_name'])    : '';
$note             = isset($_POST['note'])            ? trim($_POST['note'])             : '';
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
                    ? intval($_POST['room_unit_number']) : null;
$room_unit_name   = isset($_POST['room_unit_name'])  ? trim($_POST['room_unit_name'])   : '';

if (!$client_id || !$area || !$category_name) {
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
$max_size = 50 * 1024 * 1024;
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File exceeds 50MB. Use chunked mode for larger files.']);
    exit();
}

if (strpos($file['type'], 'video/') === 0) {
    echo json_encode(['success' => false, 'error' => 'Video files are not allowed.']);
    exit();
}

$allowed_extensions = [
    'jpg','jpeg','png','gif','webp','bmp',
    'pdf','doc','docx','xls','xlsx','ppt','pptx',
    'txt','csv','zip','rar','dwg','dxf'
];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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

// File count check
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
    echo json_encode(['success' => false, 'error' => 'Maximum of 20 files reached.']);
    exit();
}

// MIME check
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (strpos($mimeType, 'video/') === 0) {
    echo json_encode(['success' => false, 'error' => 'Video files are not allowed.']);
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
    'application/octet-stream',
];
if (!in_array($mimeType, $allowed_mimes) && !in_array($ext, ['dwg','dxf'])) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $mimeType]);
    exit();
}

// Save file
$uploadDir = ROOT_PATH . 'uploads/td_attachments/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeName = uniqid('td_', true) . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($file['name'], PATHINFO_FILENAME)) . '.' . $ext;
if (strlen($safeName) > 200) $safeName = uniqid('td_', true) . '.' . $ext;
$filepath = $uploadDir . $safeName;
$db_path  = 'uploads/td_attachments/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Could not save file.']);
    exit();
}

$file_size     = filesize($filepath);
$original_name = basename($file['name']);

// DB insert
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
    echo json_encode(['success' => true, 'message' => 'File uploaded successfully']);
} else {
    unlink($filepath);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}