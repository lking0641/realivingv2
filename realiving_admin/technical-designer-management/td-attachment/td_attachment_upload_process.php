<?php
// td_attachment_upload_process.php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$client_id       = intval($_POST['client_id'] ?? 0);
$area            = trim($_POST['area'] ?? '');
$room_unit_number= isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '' ? intval($_POST['room_unit_number']) : null;
$room_unit_name  = trim($_POST['room_unit_name'] ?? '');
$category_name   = trim($_POST['category_name'] ?? '');
$note            = trim($_POST['note'] ?? '');
$redirect_url    = $_POST['redirect_url'] ?? (BASE_URL . 'td-attachment-upload?client_id=' . $client_id . '&area=' . urlencode($area));

if (!$client_id || !$area || !$category_name) {
    header("Location: {$redirect_url}&error=" . urlencode("Missing required fields."));
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
    header("Location: {$redirect_url}&error=" . urlencode("Access denied."));
    exit();
}
if ($canViewAll && !$isAssigned) {
    header("Location: {$redirect_url}&error=" . urlencode("View only — you cannot upload files."));
    exit();
}

// Count existing files for this area/unit
if ($room_unit_number !== null) {
    $cntStmt = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=? AND room_unit_number=?");
    $cntStmt->bind_param("isi", $client_id, $area, $room_unit_number);
} else {
    $cntStmt = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=? AND room_unit_number IS NULL");
    $cntStmt->bind_param("is", $client_id, $area);
}
$cntStmt->execute();
$existingCount = (int)$cntStmt->get_result()->fetch_row()[0];
$maxFiles      = 20;

if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    header("Location: {$redirect_url}&error=" . urlencode("No files selected."));
    exit();
}

$uploadDir = '../../uploads/td_attachments/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

$allowedMimes = [
    'image/jpeg','image/png','image/gif','image/webp','image/bmp',
    'application/pdf',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip','application/x-zip-compressed',
    'text/plain',
    'application/octet-stream', // for DWG/DXF
];

$errors       = [];
$uploadedCount = 0;

foreach ($_FILES['files']['name'] as $i => $origName) {
    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = $origName . ': upload error.';
        continue;
    }
    if ($existingCount + $uploadedCount >= $maxFiles) {
        $errors[] = "Max file limit ({$maxFiles}) reached. Some files were skipped.";
        break;
    }

    $fileSize = $_FILES['files']['size'][$i];
    if ($fileSize > 10 * 1024 * 1024) { $errors[] = $origName . ': exceeds 10MB.'; continue; }

    $tmpPath  = $_FILES['files']['tmp_name'][$i];
    $mimeType = mime_content_type($tmpPath);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Allow DWG/DXF by extension (binary files)
    $isDrawing = in_array($ext, ['dwg','dxf']);
    if (!$isDrawing && !in_array($mimeType, $allowedMimes)) {
        $errors[] = $origName . ': file type not allowed.';
        continue;
    }

    // Sanitise and build unique filename
    $safeOrig  = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $origName);
    $fileName  = uniqid('td_') . '_' . $safeOrig;
    $destPath  = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        $errors[] = $origName . ': failed to save.';
        continue;
    }

    // Insert record
    $insStmt = $conn->prepare("
        INSERT INTO td_attachments
            (client_id, area, room_unit_number, category_name, file_name, file_path, file_type, file_size, note, uploaded_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $insStmt->bind_param(
        "isissssisi",
        $client_id, $area, $room_unit_number, $category_name,
        $origName, $fileName, $mimeType, $fileSize, $note, $admin_id
    );
    if ($insStmt->execute()) {
        $uploadedCount++;
    } else {
        unlink($destPath);
        $errors[] = $origName . ': database error.';
    }
}

if ($uploadedCount > 0 && empty($errors)) {
    header("Location: {$redirect_url}&success=" . urlencode("{$uploadedCount} file(s) uploaded successfully."));
} elseif ($uploadedCount > 0) {
    header("Location: {$redirect_url}&success=" . urlencode("{$uploadedCount} file(s) uploaded.") . "&error=" . urlencode(implode(' | ', $errors)));
} else {
    header("Location: {$redirect_url}&error=" . urlencode(implode(' | ', $errors) ?: 'Upload failed.'));
}
exit();