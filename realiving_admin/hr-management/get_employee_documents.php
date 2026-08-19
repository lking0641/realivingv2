<?php
// realiving_admin/hr-management/get_employee_documents.php
session_start();
require_once __DIR__ . '/../../config/app_config.php';
include $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'human_resource') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$accountId = (int) ($_GET['account_id'] ?? 0);
if (!$accountId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT d.id, d.label, d.file_path, d.file_type, d.file_size, d.uploaded_at, a.full_name AS uploader_name
    FROM hr_employee_documents d
    LEFT JOIN account a ON a.id = d.uploaded_by
    WHERE d.account_id = ?
    ORDER BY d.uploaded_at DESC
");
$stmt->bind_param('i', $accountId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function formatFileSize($bytes) {
    if (!$bytes) return '';
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

$documents = array_map(function ($row) {
    return [
        'id'            => (int) $row['id'],
        'label'         => $row['label'],
        'file_url'      => BASE_URL . $row['file_path'],
        'file_type'     => $row['file_type'],
        'file_size'     => formatFileSize($row['file_size']),
        'uploader_name' => $row['uploader_name'] ?? 'Unknown',
        'uploaded_at'   => date('M j, Y g:i A', strtotime($row['uploaded_at'])),
    ];
}, $rows);

echo json_encode(['success' => true, 'documents' => $documents]);