<?php
// designer_submit_td_remark.php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = intval($_POST['client_id'] ?? 0);
$area = trim($_POST['area'] ?? '');
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
    ? intval($_POST['room_unit_number']) : null;
$td_remark = trim($_POST['td_remark'] ?? '');
$redirect_url = trim($_POST['redirect_url'] ?? '');
$fallback = BASE_URL . 'td-attachments?client_id=' . $client_id;

// Must be the assigned technical designer
$ciStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$ciStmt->bind_param("i", $client_id);
$ciStmt->execute();
$clientRow = $ciStmt->get_result()->fetch_assoc();

if (!$clientRow || $clientRow['technical_designer_id'] != $admin_id) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied. Only the assigned Technical Designer can submit a remark."));
    exit();
}

if (empty($td_remark)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Remark cannot be empty."));
    exit();
}

// ── Handle optional PDF upload ────────────────────────────────────────────
$td_remark_file = null;
$td_remark_file_name = null;

if (!empty($_FILES['td_remark_file']['name'])) {
    $file = $_FILES['td_remark_file'];
    $allowed = ['application/pdf'];
    $maxSize = 100 * 1024 * 1024; // 100MB

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("File upload error. Please try again."));
        exit();
    }
    if (!in_array($mimeType, $allowed) || $ext !== 'pdf') {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Only PDF files are allowed for the remark attachment."));
        exit();
    }
    if ($file['size'] > $maxSize) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("PDF file must be 100MB or smaller."));
        exit();
    }

    $uploadDir = ROOT_PATH . 'uploads/td_remarks/';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0755, true);

    $safeName = 'td_rmk_' . $client_id . '_' . preg_replace('/[^a-z0-9]/i', '_', $area)
        . ($room_unit_number !== null ? '_u' . $room_unit_number : '')
        . '_' . time() . '.pdf';

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Failed to save PDF. Please try again."));
        exit();
    }

    // ── Delete old file if one exists ─────────────────────────────────────
    if ($room_unit_number !== null) {
        $oldStmt = $conn->prepare("SELECT td_remark_file FROM layout_approvals WHERE client_id = ? AND area = ? AND room_unit_number = ? LIMIT 1");
        $oldStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $oldStmt = $conn->prepare("SELECT td_remark_file FROM layout_approvals WHERE client_id = ? AND area = ? AND room_unit_number IS NULL LIMIT 1");
        $oldStmt->bind_param("is", $client_id, $area);
    }
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    if (!empty($oldRow['td_remark_file'])) {
        $oldPath = ROOT_PATH . $oldRow['td_remark_file'];
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }
    // ─────────────────────────────────────────────────────────────────────

    $td_remark_file = 'uploads/td_remarks/' . $safeName;
    $td_remark_file_name = $file['name'];
}

// Update layout_approvals rows for this area/unit
if ($room_unit_number !== null) {
    $stmt = $conn->prepare("
        UPDATE layout_approvals
        SET td_remark = ?, td_remark_submitted_at = NOW(),
            td_remark_file = ?, td_remark_file_name = ?
        WHERE client_id = ? AND area = ? AND room_unit_number = ?
    ");
    $stmt->bind_param("sssisi", $td_remark, $td_remark_file, $td_remark_file_name, $client_id, $area, $room_unit_number);
} else {
    $stmt = $conn->prepare("
        UPDATE layout_approvals
        SET td_remark = ?, td_remark_submitted_at = NOW(),
            td_remark_file = ?, td_remark_file_name = ?
        WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
    ");
    $stmt->bind_param("sssis", $td_remark, $td_remark_file, $td_remark_file_name, $client_id, $area);
}

$stmt->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Remark submitted. Approvers can now proceed."));
exit();