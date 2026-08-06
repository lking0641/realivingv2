<?php
// loginpage/redirect_helper.php
// Shared by index.php (password login) and google_callback.php (Google login)
// so both send a user to the correct dashboard based on their role.

function getRedirectUrl($role, $conn = null, $user_id = null) {
    // Check is_head for designer and technical_designer
    $is_head = false;
    if ($conn && $user_id && in_array($role, ['designer', 'technical_designer'])) {
        $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
        $headCheck->bind_param("i", $user_id);
        $headCheck->execute();
        $headRow = $headCheck->get_result()->fetch_assoc();
        $is_head = !empty($headRow['is_head']);
    }

    if ($role === 'designer') {
        return $is_head
            ? BASE_URL . 'all-clients-tracker-list'
            : BASE_URL . 'designer-clients-list';
    }

    if ($role === 'technical_designer') {
        return $is_head
            ? BASE_URL . 'all-clients-tracker-list'
            : BASE_URL . 'td-layout-list';
    }

    $redirects = [
        'general_manager' => BASE_URL . 'manager-status-tracker',
        'operational_manager' => BASE_URL . 'manager-status-tracker',
        'sales' => BASE_URL . 'sales-dashboard',
        'accounting' => BASE_URL . 'all-clients-tracker-list',
        'project_coordinator' => BASE_URL . 'all-clients-tracker-list',
        'admin1' => BASE_URL . 'admin-mainpage'
    ];

    return isset($redirects[$role]) ? $redirects[$role] : BASE_URL . 'admin-mainpage';
}