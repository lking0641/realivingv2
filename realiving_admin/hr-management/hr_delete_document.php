<?php
// realiving_admin/hr-management/hr_delete_document.php
session_start();
require_once __DIR__ . '/../../config/app_config.php';
include $includes['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'human_resource') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$docId = (int) ($_POST['id'] ?? 0);
if (!$docId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $conn->prepare("SELECT file_path FROM hr_employee_documents WHERE id = ?");
$stmt->bind_param('i', $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found.']);
    exit;
}

$absolutePath = ROOT_PATH . $doc['file_path'];
if (file_exists($absolutePath)) {
    unlink($absolutePath);
}

$delStmt = $conn->prepare("DELETE FROM hr_employee_documents WHERE id = ?");
$delStmt->bind_param('i', $docId);

if ($delStmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}