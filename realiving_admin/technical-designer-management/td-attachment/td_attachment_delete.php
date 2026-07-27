<?php
// td_attachment_delete.php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

$admin_id      = $_SESSION['admin_id'];
$attachment_id = intval($_POST['attachment_id'] ?? 0);
$client_id     = intval($_POST['client_id'] ?? 0);
$redirect_url  = trim($_POST['redirect_url'] ?? '');

$fallback = 'td_attachments.php?client_id=' . $client_id;

if (!$attachment_id || !$client_id) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Invalid request."));
    exit();
}

// Verify the logged-in user is the assigned technical_designer for this client
$checkStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$checkStmt->bind_param("i", $client_id);
$checkStmt->execute();
$clientRow = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$clientRow || $clientRow['technical_designer_id'] != $admin_id) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Fetch the attachment — make sure it belongs to this client
$fetchStmt = $conn->prepare("
    SELECT id, file_path, file_name, client_id, uploaded_by
    FROM td_attachments
    WHERE id = ? AND client_id = ?
");
$fetchStmt->bind_param("ii", $attachment_id, $client_id);
$fetchStmt->execute();
$att = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$att) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Attachment not found or already deleted."));
    exit();
}

// Delete physical file from disk
$filePath = ROOT_PATH . $att['file_path'];

if (file_exists($filePath)) {
    if (!unlink($filePath)) {
        error_log("td_attachment_delete.php: Could not delete file {$filePath} for attachment id {$attachment_id}");
    }
}

// Delete from database
$delStmt = $conn->prepare("DELETE FROM td_attachments WHERE id = ? AND client_id = ?");
$delStmt->bind_param("ii", $attachment_id, $client_id);
$delStmt->execute();
$affected = $delStmt->affected_rows;
$delStmt->close();

if ($affected > 0) {
    $successMsg = '"' . $att['file_name'] . '" deleted successfully.';
    $dest = ($redirect_url ?: $fallback) . '&success=' . urlencode($successMsg);
} else {
    $dest = ($redirect_url ?: $fallback) . '&error=' . urlencode("Could not delete the record. Please try again.");
}

header("Location: " . $dest);
exit();