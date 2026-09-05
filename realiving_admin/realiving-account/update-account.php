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

    $uploadDir = ROOT_PATH . 'uploads/signatures/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $sigRelativePath = 'uploads/signatures/sig_' . $id . '_' . time() . '.png'; // stored in DB, used to build URL
    $sigAbsolutePath = ROOT_PATH . $sigRelativePath;                            // used for actual file I/O

    // Delete old signature before saving new one
    $oldSigStmt = $conn->prepare("SELECT e_signature FROM account WHERE id = ?");
    $oldSigStmt->bind_param('i', $id);
    $oldSigStmt->execute();
    $oldSigRow = $oldSigStmt->get_result()->fetch_assoc();
    if (!empty($oldSigRow['e_signature'])) {
        $oldAbsolutePath = ROOT_PATH . $oldSigRow['e_signature'];
        if (file_exists($oldAbsolutePath)) {
            unlink($oldAbsolutePath);
        }
    }
    $oldSigStmt->close();

    if (!move_uploaded_file($file['tmp_name'], $sigAbsolutePath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload signature.']);
        exit;
    }

    $signaturePath = $sigRelativePath; // this is what gets saved to the DB below
}

// Handle profile picture upload (separate from e-signature)
$profilePicturePath = null;
if (!empty($_FILES['profile_picture']['tmp_name'])) {
    $file = $_FILES['profile_picture'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowedMimes)) {
        echo json_encode(['success' => false, 'message' => 'Profile picture must be PNG, JPG, or WEBP.']);
        exit;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Profile picture must be under 2MB.']);
        exit;
    }

    $avatarUploadDir = ROOT_PATH . 'uploads/avatars/';
    if (!is_dir($avatarUploadDir)) mkdir($avatarUploadDir, 0755, true);

    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $picRelativePath = 'uploads/avatars/avatar_' . $id . '_' . time() . '.' . $ext;
    $picAbsolutePath = ROOT_PATH . $picRelativePath;

    // Delete old profile picture before saving new one
    $oldPicStmt = $conn->prepare("SELECT profile_picture FROM account WHERE id = ?");
    $oldPicStmt->bind_param('i', $id);
    $oldPicStmt->execute();
    $oldPicRow = $oldPicStmt->get_result()->fetch_assoc();
    if (!empty($oldPicRow['profile_picture'])) {
        $oldPicAbsolutePath = ROOT_PATH . $oldPicRow['profile_picture'];
        if (file_exists($oldPicAbsolutePath)) {
            unlink($oldPicAbsolutePath);
        }
    }
    $oldPicStmt->close();

    if (!move_uploaded_file($file['tmp_name'], $picAbsolutePath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload profile picture.']);
        exit;
    }

    $profilePicturePath = $picRelativePath; // this is what gets saved to the DB below
}

// QR code image upload (optional, image OR text — not both required)
$qrImagePath = null;
// Reusable helper for uploading a platform-specific QR image
function handlePlatformQrUpload($fieldName, $id, $conn, $dbColumn) {
    if (empty($_FILES[$fieldName]['tmp_name'])) return null;

    $file = $_FILES[$fieldName];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowedMimes)) {
        echo json_encode(['success' => false, 'message' => ucfirst($fieldName) . ' must be PNG, JPG, or WEBP.']);
        exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => ucfirst($fieldName) . ' must be under 2MB.']);
        exit;
    }

    $uploadDir = ROOT_PATH . 'uploads/qrcodes/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $relativePath = 'uploads/qrcodes/' . $fieldName . '_' . $id . '_' . time() . '.' . $ext;
    $absolutePath = ROOT_PATH . $relativePath;

    $oldStmt = $conn->prepare("SELECT $dbColumn FROM account WHERE id = ?");
    $oldStmt->bind_param('i', $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    if (!empty($oldRow[$dbColumn])) {
        $oldPath = ROOT_PATH . $oldRow[$dbColumn];
        if (file_exists($oldPath)) unlink($oldPath);
    }
    $oldStmt->close();

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload ' . $fieldName . '.']);
        exit;
    }
    return $relativePath;
}

$wechatQrPath = handlePlatformQrUpload('wechat_qr_image', $id, $conn, 'wechat_qr_image');
$viberQrPath  = handlePlatformQrUpload('viber_qr_image', $id, $conn, 'viber_qr_image');

// Team card fields — all optional, empty string is fine
$showTeamCard  = isset($_POST['show_team_card']) ? 1 : 0;
$position      = trim($_POST['position'] ?? '');
$contactNumber = trim($_POST['contact_number'] ?? '');
$socialGmail   = trim($_POST['social_gmail'] ?? '');
$socialWechat  = trim($_POST['social_wechat'] ?? '');
$socialViber   = trim($_POST['social_viber'] ?? '');

// Team card fields — all optional, empty string is fine
$showTeamCard  = isset($_POST['show_team_card']) ? 1 : 0;
$position      = trim($_POST['position'] ?? '');
$contactNumber = trim($_POST['contact_number'] ?? '');
$socialGmail   = trim($_POST['social_gmail'] ?? '');
$socialWechat  = trim($_POST['social_wechat'] ?? '');
$socialViber   = trim($_POST['social_viber'] ?? '');
$qrCodeText    = trim($_POST['qr_code_text'] ?? '');

// Which avatar the user wants to display: 'google' or 'custom'
$avatarSource = null;
if (isset($_POST['avatar_source']) && in_array($_POST['avatar_source'], ['google', 'custom'])) {
    $avatarSource = $_POST['avatar_source'];
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

// ── Build the UPDATE query dynamically based on what was submitted ──
$fields = ['full_name = ?', 'email = ?'];
$params = [$fullName, $email];
$types  = 'ss';

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
    $fields[] = 'password = ?';
    $params[] = $hashed;
    $types   .= 's';
}

if ($signaturePath) {
    $fields[] = 'e_signature = ?';
    $params[] = $signaturePath;
    $types   .= 's';
}

if ($profilePicturePath) {
    $fields[] = 'profile_picture = ?';
    $params[] = $profilePicturePath;
    $types   .= 's';
}

if ($avatarSource) {
    $fields[] = 'avatar_source = ?';
    $params[] = $avatarSource;
    $types   .= 's';
}

$fields[] = 'show_team_card = ?';
$params[] = $showTeamCard;
$types   .= 'i';

$fields[] = 'position = ?';
$params[] = $position;
$types   .= 's';

$fields[] = 'contact_number = ?';
$params[] = $contactNumber;
$types   .= 's';

$fields[] = 'social_gmail = ?';
$params[] = $socialGmail;
$types   .= 's';

$fields[] = 'social_wechat = ?';
$params[] = $socialWechat;
$types   .= 's';

$fields[] = 'social_viber = ?';
$params[] = $socialViber;
$types   .= 's';

if ($wechatQrPath) {
    $fields[] = 'wechat_qr_image = ?';
    $params[] = $wechatQrPath;
    $types   .= 's';
}

if ($viberQrPath) {
    $fields[] = 'viber_qr_image = ?';
    $params[] = $viberQrPath;
    $types   .= 's';
}

$params[] = $id;
$types   .= 'i';

$sql  = "UPDATE account SET " . implode(', ', $fields) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    $responseData = ['success' => true, 'message' => 'Account updated successfully!'];
    if ($signaturePath) {
        $responseData['e_signature'] = BASE_URL . $signaturePath;
    }
    if ($profilePicturePath) {
        $responseData['profile_picture'] = BASE_URL . $profilePicturePath;
    }
    if ($wechatQrPath) {
        $responseData['wechat_qr_image'] = BASE_URL . $wechatQrPath;
    }
    if ($viberQrPath) {
        $responseData['viber_qr_image'] = BASE_URL . $viberQrPath;
    }

    // Re-fetch the freshest avatar state so the response always reflects
    // whichever source (google/custom) is now active — even if this save
    // only switched avatar_source and didn't upload a new file.
    $avatarStmt = $conn->prepare("SELECT profile_picture, google_picture, avatar_source FROM account WHERE id = ?");
    $avatarStmt->bind_param('i', $id);
    $avatarStmt->execute();
    $avatarRow = $avatarStmt->get_result()->fetch_assoc();
    $avatarStmt->close();

    $resolvedAvatarUrl = null;
    if (($avatarRow['avatar_source'] ?? 'custom') === 'google' && !empty($avatarRow['google_picture'])) {
        $resolvedAvatarUrl = $avatarRow['google_picture'];
    } elseif (!empty($avatarRow['profile_picture'])) {
        $resolvedAvatarUrl = BASE_URL . $avatarRow['profile_picture'];
    }
    $responseData['avatar_url'] = $resolvedAvatarUrl;

    echo json_encode($responseData);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}