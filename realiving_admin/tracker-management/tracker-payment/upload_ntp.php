<?php
session_start();
ob_start();
include $includes ['connection'];
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
$client_id  = isset($_POST['client_id'])  ? intval($_POST['client_id'])  : 0;
$notes      = isset($_POST['notes'])      ? trim($_POST['notes'])        : '';

if (!$payment_id || !$client_id) {
    echo json_encode(['success' => false, 'error' => 'Missing payment or client ID']);
    exit();
}

if (!isset($_FILES['ntp_file']) || $_FILES['ntp_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit();
}

$file = $_FILES['ntp_file'];

// ── Use finfo for reliable MIME detection (fixes garbled PDF bug) ──
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

if (!in_array($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type: ' . $mimeType]);
    exit();
}

$uploadDir = ROOT_PATH . 'uploads/ntp/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'ntp_' . $payment_id . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit();
}

$dbPath = 'uploads/ntp/' . $filename;

// ── Delete old NTP row + file if exists (replace mode) ──
$oldStmt = $conn->prepare("SELECT id, file_path FROM notice_to_proceed WHERE payment_id = ? ORDER BY id DESC LIMIT 1");
$oldStmt->bind_param("i", $payment_id);
$oldStmt->execute();
$oldNTP = $oldStmt->get_result()->fetch_assoc();

if ($oldNTP) {
    // Delete physical file from disk
    $oldFilePath = ROOT_PATH . $oldNTP['file_path'];
    if (file_exists($oldFilePath)) {
        unlink($oldFilePath);
    }
    // Delete old DB row
    $delStmt = $conn->prepare("DELETE FROM notice_to_proceed WHERE id = ?");
    $delStmt->bind_param("i", $oldNTP['id']);
    $delStmt->execute();
}

// ── Insert new NTP row ──
$stmt = $conn->prepare("
    INSERT INTO notice_to_proceed (payment_id, client_id, uploaded_by, file_path, file_name, file_type, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iiissss", $payment_id, $client_id, $admin_id, $dbPath, $file['name'], $mimeType, $notes);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database insert failed: ' . $conn->error]);
}
?>