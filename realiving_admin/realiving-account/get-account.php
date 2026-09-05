<?php
session_start();
require_once $includes['connection']; // adjust path to your DB connection

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$id = (int) $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT full_name, email, role, e_signature, google_sub, google_email, google_picture, profile_picture, avatar_source, show_team_card, position, contact_number, social_gmail, social_wechat, social_viber, wechat_qr_image, viber_qr_image FROM account WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if (!empty($row['e_signature'])) {
        $row['e_signature'] = BASE_URL . $row['e_signature'];
    }
    // Don't leak the raw google_sub to the frontend — just whether it's linked
    $row['google_linked'] = !empty($row['google_sub']);
    unset($row['google_sub']);

        if (!empty($row['profile_picture'])) {
        $row['profile_picture'] = BASE_URL . $row['profile_picture'];
    }
    if (!empty($row['wechat_qr_image'])) {
        $row['wechat_qr_image'] = BASE_URL . $row['wechat_qr_image'];
    }
    if (!empty($row['viber_qr_image'])) {
        $row['viber_qr_image'] = BASE_URL . $row['viber_qr_image'];
    }

    // Resolve which picture is actually the active avatar
    $row['avatar_url'] = null;
    if ($row['avatar_source'] === 'google' && !empty($row['google_picture'])) {
        $row['avatar_url'] = $row['google_picture'];
    } elseif (!empty($row['profile_picture'])) {
        $row['avatar_url'] = $row['profile_picture'];
    }

    echo json_encode(array_merge(['success' => true], $row));
} else {
    echo json_encode(['success' => false, 'message' => 'Account not found']);
}
$stmt->close();