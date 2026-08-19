<?php
// realiving_admin/hr-management/hr_upload_document.php
session_start();
require_once __DIR__ . '/../../config/app_config.php';
include $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'] ?? '', ['human_resource', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$accountId = (int) ($_POST['account_id'] ?? 0);
$label     = trim($_POST['label'] ?? '');

if (!$accountId || !$label) {
    echo json_encode(['success' => false, 'message' => 'Account and label are required.']);
    exit;
}

// Block uploading documents onto a super_admin's record, mirroring hr-admin-edit.php's guard
$roleCheck = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleCheck->bind_param('i', $accountId);
$roleCheck->execute();
$roleRow = $roleCheck->get_result()->fetch_assoc();
if (!$roleRow || $roleRow['role'] === 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Not permitted for this account.']);
    exit;
}

if (empty($_FILES['document']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['document'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = [
    'application/pdf' => ['ext' => 'pdf', 'type' => 'pdf'],
    'image/png'        => ['ext' => 'png', 'type' => 'image'],
    'image/jpeg'       => ['ext' => 'jpg', 'type' => 'image'],
    'image/webp'       => ['ext' => 'webp', 'type' => 'image'],
];

if (!isset($allowedMimes[$mime])) {
    echo json_encode(['success' => false, 'message' => 'Only PDF, PNG, JPG, or WEBP files are allowed.']);
    exit;
}

if ($file['size'] > 50 * 1024 * 1024) { // 50MB cap
    echo json_encode(['success' => false, 'message' => 'File must be under 50MB.']);
    exit;
}

$fileType = $allowedMimes[$mime]['type'];
$ext      = $allowedMimes[$mime]['ext'];

$uploadDir = ROOT_PATH . 'uploads/hr_documents/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$relativePath = 'uploads/hr_documents/doc_' . $accountId . '_' . time() . '_' . uniqid() . '.' . $ext;
$absolutePath = ROOT_PATH . $relativePath;

if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
    exit;
}

$fileSize = $file['size'];
$stmt = $conn->prepare("INSERT INTO hr_employee_documents (account_id, label, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param('isssii', $accountId, $label, $relativePath, $fileType, $fileSize, $_SESSION['admin_id']);

if ($stmt->execute()) {
    echo json_encode([
        'success'   => true,
        'message'   => 'Document uploaded successfully.',
        'document'  => [
            'id'          => $stmt->insert_id,
            'label'       => $label,
            'file_url'    => BASE_URL . $relativePath,
            'file_type'   => $fileType,
            'uploaded_at' => date('M j, Y g:i A'),
        ],
    ]);
} else {
    // Roll back the file if the DB insert failed
    if (file_exists($absolutePath)) unlink($absolutePath);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}