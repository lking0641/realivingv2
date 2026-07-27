<?php
session_start();
include "../../connection/connection.php";

if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $conn->query("UPDATE account SET is_online = 1, last_activity = NOW() WHERE id = $admin_id");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}

$conn->close();
?>