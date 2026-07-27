<?php
//upload_payment_proof.php
session_start();
ob_start();
include $includes ['connection'];
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id  = $_SESSION['admin_id'];
$payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
$client_id  = isset($_POST['client_id'])  ? intval($_POST['client_id'])  : 0;

if (!$payment_id || !$client_id) {
    echo json_encode(['success' => false, 'error' => 'Missing fields']);
    exit();
}

if (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit();
}

$file     = $_FILES['proof_file'];
$mime     = mime_content_type($file['tmp_name']);
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Block videos
$blockedMimes = ['video/mp4','video/avi','video/mov','video/mkv','video/webm','video/quicktime'];
if (in_array($mime, $blockedMimes) || in_array($ext, ['mp4','avi','mov','mkv','webm'])) {
    echo json_encode(['success' => false, 'error' => 'Video files are not allowed.']);
    exit();
}

$uploadDir = ROOT_PATH . 'uploads/payment_proofs/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Cannot create upload directory: ' . $uploadDir]);
        exit();
    }
}

$isImage = in_array($mime, ['image/jpeg','image/png','image/gif','image/bmp','image/webp']);
$savedName = '';

if ($isImage) {
    // Try WebP conversion only if GD supports it
    $canWebP = function_exists('imagewebp') && (imagetypes() & IMG_WEBP);

    if ($canWebP) {
        $savedName = 'proof_' . $payment_id . '_' . time() . '.webp';
        $destPath  = $uploadDir . $savedName;

        $imgRes = null;
        if ($mime === 'image/jpeg')      $imgRes = @imagecreatefromjpeg($file['tmp_name']);
        elseif ($mime === 'image/png')   $imgRes = @imagecreatefrompng($file['tmp_name']);
        elseif ($mime === 'image/gif')   $imgRes = @imagecreatefromgif($file['tmp_name']);
        elseif ($mime === 'image/webp')  $imgRes = @imagecreatefromwebp($file['tmp_name']);
        elseif ($mime === 'image/bmp')   $imgRes = @imagecreatefrombmp($file['tmp_name']);

        if ($imgRes && imagewebp($imgRes, $destPath, 85)) {
            imagedestroy($imgRes);
            $mime = 'image/webp'; // update mime to reflect actual saved type
        } else {
            // WebP conversion failed — fall back to saving original
            if ($imgRes) imagedestroy($imgRes);
            $savedName = 'proof_' . $payment_id . '_' . time() . '.' . $ext;
            $destPath  = $uploadDir . $savedName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                echo json_encode(['success' => false, 'error' => 'File save failed (conversion fallback).']);
                exit();
            }
        }
    } else {
        // GD has no WebP support — save original directly
        $savedName = 'proof_' . $payment_id . '_' . time() . '.' . $ext;
        $destPath  = $uploadDir . $savedName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'error' => 'File save failed.']);
            exit();
        }
    }
} else {
    // Non-image file (PDF, etc.)
    $savedName = 'proof_' . $payment_id . '_' . time() . '.' . $ext;
    $destPath  = $uploadDir . $savedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'File move failed.']);
        exit();
    }
}

// Step 1 — Delete old proof files from disk and DB BEFORE inserting new one
$oldProofsStmt = $conn->prepare("SELECT file_path FROM payment_proofs WHERE payment_id = ?");
$oldProofsStmt->bind_param("i", $payment_id);
$oldProofsStmt->execute();
$oldProofs = $oldProofsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($oldProofs as $op) {
    $fullPath = ROOT_PATH . $op['file_path'];
    if (file_exists($fullPath)) @unlink($fullPath);
}

$delProofStmt = $conn->prepare("DELETE FROM payment_proofs WHERE payment_id = ?");
$delProofStmt->bind_param("i", $payment_id);
$delProofStmt->execute();

$delRevStmt = $conn->prepare("DELETE FROM payment_accounting_reviews WHERE payment_id = ?");
$delRevStmt->bind_param("i", $payment_id);
$delRevStmt->execute();

// Step 2 — Now save the new proof to DB
$insertStmt = $conn->prepare("
    INSERT INTO payment_proofs (payment_id, client_id, uploaded_by, file_name, file_path, file_type, uploaded_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
$filePath = 'uploads/payment_proofs/' . $savedName;
$insertStmt->bind_param("iiisss", $payment_id, $client_id, $admin_id, $savedName, $filePath, $mime);

if (!$insertStmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'DB insert failed: ' . $insertStmt->error]);
    exit();
}

// Step 3 — Update accounting_status and insert fresh review row
$updStmt = $conn->prepare("UPDATE payment_schedule SET accounting_status = 'pending_review' WHERE id = ?");
$updStmt->bind_param("i", $payment_id);
$updStmt->execute();

$revStmt = $conn->prepare("INSERT INTO payment_accounting_reviews (payment_id, review_status) VALUES (?, 'pending')");
$revStmt->bind_param("i", $payment_id);
$revStmt->execute();

echo json_encode(['success' => true, 'file' => $savedName]);
?>