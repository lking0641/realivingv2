<?php
//connection.php

require_once __DIR__ . '/../config/app_config.php';

// Set PHP timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

// Auto-detect environment based on server
$is_localhost = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_ADDR'] === '127.0.0.1' || $_SERVER['SERVER_ADDR'] === '::1');

if ($is_localhost) {
    // LOCALHOST Configuration
    $host = "localhost:3306";
    $username = "root";
    $password = "";
    $database = "realivingdata";
} else {
    // PRODUCTION Configuration
    $host = "localhost";
    $username = "u565655483_realivingv2";
    $password = "Realiving01";
    $database = "u565655483_realivingv2";
}

$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set MySQL timezone to Philippine Time (+08:00)
$conn->query("SET time_zone = '+08:00'");

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];

    // ============================================
    // SESSION TIMEOUT SETTINGS
    // ============================================
    // FOR TESTING: 10 seconds (uncomment this line)
    //  $session_timeout = 10; // 10 seconds for testing

    // FOR PRODUCTION: 9 hours (uncomment this line)
    $session_timeout = 9 * 60 * 60; // 9 hours in seconds
    // ============================================

    // Check if session has expired
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];

        if ($inactive_time > $session_timeout) {
            // Session expired - set offline and logout
            $stmt_offline = $conn->prepare("UPDATE account SET is_online = 0 WHERE id = ?");
            $stmt_offline->bind_param("i", $admin_id);
            $stmt_offline->execute();
            $stmt_offline->close();

            // Destroy session
            session_unset();
            session_destroy();

            // Redirect to login with timeout message
            header("Location: " . BASE_URL . "login?timeout=1");
            exit();
        }
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();

    // Update online status in database
    $stmt_online = $conn->prepare("UPDATE account SET is_online = 1, last_activity = NOW() WHERE id = ?");
    $stmt_online->bind_param("i", $admin_id);
    $stmt_online->execute();
    $stmt_online->close();
}
?>