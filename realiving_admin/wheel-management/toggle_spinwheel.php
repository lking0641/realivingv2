<?php
// toggle_spinwheel.php (admin only)
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

require_role(['sales']); // adjust roles as needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = isset($_POST['is_active']) ? 1 : 0;
    $conn->query("UPDATE spinwheel_settings SET is_active = $new_status WHERE id = 1");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}