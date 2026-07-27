<?php
session_start();
require_once $includes['connection']; // adjust path to your DB connection

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$id       = (int) $_SESSION['admin_id'];
$fullName = trim($_POST['full_name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$newPass  = $_POST['new_password'] ?? '';

// Handle e-signature upload
$signaturePath = null;
if (!empty($_FILES['e_signature']['tmp_name'])) {
    $file = $_FILES['e_signature'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mime !== 'image/png') {
        echo json_encode(['success' => false, 'message' => 'E-signature must be a PNG file.']);
        exit;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'E-signature file must be under 2MB.']);
        exit;
    }

    $uploadDir = '../../uploads/signatures/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $signaturePath = $uploadDir . 'sig_' . $id . '_' . time() . '.png';

    // Delete old signature before saving new one
    $oldSigStmt = $conn->prepare("SELECT e_signature FROM account WHERE id = ?");
    $oldSigStmt->bind_param('i', $id);
    $oldSigStmt->execute();
    $oldSigRow = $oldSigStmt->get_result()->fetch_assoc();
    if (!empty($oldSigRow['e_signature']) && file_exists($oldSigRow['e_signature'])) {
        unlink($oldSigRow['e_signature']);
    }
    $oldSigStmt->close();

    if (!move_uploaded_file($file['tmp_name'], $signaturePath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload signature.']);
        exit;
    }
}

if (!$fullName || !$email) {
    echo json_encode(['success' => false, 'message' => 'Name and email are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Check if email is taken by another account
$stmt = $conn->prepare("SELECT id FROM account WHERE email = ? AND id != ?");
$stmt->bind_param('si', $email, $id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email is already in use.']);
    exit;
}

if ($newPass) {
    $currentPass = $_POST['current_password'] ?? '';

    if (!$currentPass) {
        echo json_encode(['success' => false, 'message' => 'Current password is required to set a new password.']);
        exit;
    }

    // Fetch current hashed password from DB
    $checkStmt = $conn->prepare("SELECT password FROM account WHERE id = ?");
    $checkStmt->bind_param('i', $id);
    $checkStmt->execute();
    $checkRow = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$checkRow || !password_verify($currentPass, $checkRow['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    if (strlen($newPass) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }

    $hashed = password_hash($newPass, PASSWORD_DEFAULT);
    if ($signaturePath) {
        $stmt = $conn->prepare("UPDATE account SET full_name = ?, email = ?, password = ?, e_signature = ? WHERE id = ?");
        $stmt->bind_param('ssssi', $fullName, $email, $hashed, $signaturePath, $id);
    } else {
        $stmt = $conn->prepare("UPDATE account SET full_name = ?, email = ?, password = ? WHERE id = ?");
        $stmt->bind_param('sssi', $fullName, $email, $hashed, $id);
    }
} else {
    if ($signaturePath) {
        $stmt = $conn->prepare("UPDATE account SET full_name = ?, email = ?, e_signature = ? WHERE id = ?");
        $stmt->bind_param('sssi', $fullName, $email, $signaturePath, $id);
    } else {
        $stmt = $conn->prepare("UPDATE account SET full_name = ?, email = ? WHERE id = ?");
        $stmt->bind_param('ssi', $fullName, $email, $id);
    }
}

if ($stmt->execute()) {
    $responseData = ['success' => true, 'message' => 'Account updated successfully!'];
    if ($signaturePath) {
        $responseData['e_signature'] = $signaturePath;
    }
    echo json_encode($responseData);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}