<?php
// logout.php
session_start();
require_once __DIR__ . '/../config/app_config.php';
include $includes['connection'];

// Set user as offline before logging out
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("UPDATE account SET is_online = 0, remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $stmt->close();
}

// Clear session and cookies
session_unset();
session_destroy();
setcookie('remember_token', '', time() - 3600, "/");

$conn->close();

header("Location: " . BASE_URL . "login");
exit();
?>