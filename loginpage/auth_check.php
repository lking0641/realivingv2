<?php
// auth_check.php
// I-include ito sa top ng bawat dashboard page

session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
    header("Location: ../../login/index.php");
    exit();
}

// Optional: Check if session is expired (e.g., 8 hours)
$session_timeout = 8 * 60 * 60; // 8 hours in seconds
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: ../../login/index.php");
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Function to check if user has specific role
function checkRole($allowed_roles) {
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    if (!in_array($_SESSION['admin_role'], $allowed_roles)) {
        header("Location: ../../login/unauthorized.php");
        exit();
    }
}

// Get user info for display
function getUserInfo() {
    return [
        'id' => $_SESSION['admin_id'],
        'name' => $_SESSION['admin_name'] ?? 'Admin',
        'email' => $_SESSION['admin_email'],
        'role' => $_SESSION['admin_role']
    ];
}

// Function to format role name for display
function formatRole($role) {
    $roles = [
        'general_manager' => 'General Manager',
        'operational_manager' => 'Operational Manager',
        'sales' => 'Sales',
        'designer' => 'Designer',
        'technical_designer' => 'Technical Designer',
        'accounting' => 'Accounting',
        'project_coordinator' => 'Project Coordinator'
    ];
    
    return isset($roles[$role]) ? $roles[$role] : ucwords(str_replace('_', ' ', $role));
}
?>