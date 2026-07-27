<?php
// attachment_upload.php
session_start();
include $includes ['connnection'];
date_default_timezone_set('Asia/Manila');

$admin_id        = $_SESSION['admin_id'];
$client_id       = intval($_POST['client_id'] ?? 0);
$type            = trim($_POST['attachment_type'] ?? '');
$area            = trim($_POST['area'] ?? '');
$note            = trim($_POST['note'] ?? '');
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
                    ? intval($_POST['room_unit_number'])
                    : null;
$room_unit_name  = trim($_POST['room_unit_name'] ?? '');

// Validate required fields
$allowed_types = ['site_measurement', 'floor_plan', 'rendering'];

$room_param = $room_unit_number !== null
    ? '&room_unit_number=' . $room_unit_number . '&room_unit_name=' . urlencode($room_unit_name)
    : '';
$redirect_base = BASE_URL . 'designer-attachment-upload?client_id=' . $client_id
               . '&area=' . urlencode($area)
               . $room_param
               . '&tab=' . $type;

if (!$client_id || !in_array($type, $allowed_types) || !$area) {
    header("Location: " . $redirect_base . "&error=" . urlencode("Invalid parameters."));
    exit();
}

// Verify designer is assigned to this client
$checkStmt = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
$checkStmt->bind_param("i", $client_id);
$checkStmt->execute();
$clientRow = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$clientRow || ($clientRow['designer1_id'] != $admin_id && $clientRow['designer2_id'] != $admin_id)) {
    header("Location: " . $redirect_base . "&error=" . urlencode("Access denied."));
    exit();
}

// Check current file count for this slot
if ($room_unit_number !== null) {
    $cntStmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_attachments
        WHERE client_id = ? AND attachment_type = ? AND area = ? AND room_unit_number = ?
    ");
    $cntStmt->bind_param("issi", $client_id, $type, $area, $room_unit_number);
} else {
    $cntStmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_attachments
        WHERE client_id = ? AND attachment_type = ? AND area = ? AND room_unit_number IS NULL
    ");
    $cntStmt->bind_param("iss", $client_id, $type, $area);
}
$cntStmt->execute();
$cntStmt->bind_result($currentCount);
$cntStmt->fetch();
$cntStmt->close();

$maxFiles = 10;

if ($currentCount >= $maxFiles) {
    header("Location: " . $redirect_base . "&error=" . urlencode("Maximum of {$maxFiles} files already reached. Delete some files first."));
    exit();
}

// Check files were submitted
$files = $_FILES['files'] ?? null;
if (!$files || empty($files['name'][0]) || $files['name'][0] === '') {
    header("Location: " . $redirect_base . "&error=" . urlencode("No files selected."));
    exit();
}

// Upload directory
$uploadDir = ROOT_PATH . 'uploads/layout_attachments/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        header("Location: " . $redirect_base . "&error=" . urlencode("Upload directory could not be created. Contact your administrator."));
        exit();
    }
}

// Forbidden MIME prefixes (no videos)
$forbidden_mime_prefixes = ['video/'];

// Allowed extensions as extra safety check
$allowed_extensions = [
    'jpg','jpeg','png','gif','webp','bmp','svg',   // images
    'pdf',                                          // pdf
    'doc','docx',                                   // word
    'xls','xlsx',                                   // excel
    'ppt','pptx',                                   // powerpoint
    'txt','csv',                                    // text
    'zip','rar'                                     // archives
];

$max_size_bytes = 1.3 * 1024 * 1024 * 1024; // 1.3GB per file

$uploaded = 0;
$errors   = [];
$total    = count($files['name']);

for ($i = 0; $i < $total; $i++) {
    // Skip empty slots (multiple file input can have gaps)
    if (empty($files['name'][$i])) continue;

    // PHP upload error check
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $phpErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file.',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension.',
        ];
        $errMsg = $phpErrors[$files['error'][$i]] ?? 'Unknown upload error.';
        $errors[] = htmlspecialchars($files['name'][$i]) . ': ' . $errMsg;
        continue;
    }

    // Max files limit check
    if ($currentCount + $uploaded >= $maxFiles) {
        $errors[] = 'Max ' . $maxFiles . ' files limit reached. Remaining files skipped.';
        break;
    }

    // File size check
    if ($files['size'][$i] > $max_size_bytes) {
        $errors[] = htmlspecialchars($files['name'][$i]) . ': Exceeds 1.3GB size limit.';
        continue;
    }

    // MIME type check (detect video)
    $mimeType = mime_content_type($files['tmp_name'][$i]);
    $isVideo  = false;
    foreach ($forbidden_mime_prefixes as $prefix) {
        if (strpos($mimeType, $prefix) === 0) {
            $isVideo = true;
            break;
        }
    }
    if ($isVideo) {
        $errors[] = htmlspecialchars($files['name'][$i]) . ': Video files are not allowed.';
        continue;
    }

    // Extension check
    $originalName = basename($files['name'][$i]);
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions)) {
        $errors[] = htmlspecialchars($originalName) . ': File type .' . $ext . ' is not allowed.';
        continue;
    }

    // Generate unique safe filename
    $safeName = uniqid('att_', true) . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $ext;
    // Truncate if too long
    if (strlen($safeName) > 200) {
        $safeName = uniqid('att_', true) . '.' . $ext;
    }

    // Move uploaded file
    if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . $safeName)) {
        $errors[] = htmlspecialchars($originalName) . ': Failed to save file. Check server permissions.';
        continue;
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
        $originalName,
        $safeName,
        $mimeType,
        $files['size'][$i],
        $note
    );

    if ($insStmt->execute()) {
        $uploaded++;
    } else {
        // DB insert failed — clean up the file we just saved
        if (file_exists($uploadDir . $safeName)) {
            unlink($uploadDir . $safeName);
        }
        $errors[] = htmlspecialchars($originalName) . ': Database error. Please try again.';
    }
    $insStmt->close();
}

// Build redirect message
if ($uploaded > 0 && empty($errors)) {
    $msg = $uploaded . ' file' . ($uploaded > 1 ? 's' : '') . ' uploaded successfully.';
    header("Location: " . $redirect_base . "&success=" . urlencode($msg));
} elseif ($uploaded > 0 && !empty($errors)) {
    $msg = $uploaded . ' file' . ($uploaded > 1 ? 's' : '') . ' uploaded. Some issues: ' . implode(' | ', $errors);
    header("Location: " . $redirect_base . "&success=" . urlencode($msg));
} else {
    $errMsg = !empty($errors) ? implode(' | ', $errors) : 'No files were uploaded.';
    header("Location: " . $redirect_base . "&error=" . urlencode($errMsg));
}
exit();