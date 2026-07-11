<?php
// cron_offline_check.php
// Run this every 5 minutes via cron job or task scheduler
include 'connection/connection.php';

// Mark users as offline if they haven't been active for more than 5 minutes
$conn->query("UPDATE account SET is_online = 0 WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND is_online = 1");

$conn->close();
?>