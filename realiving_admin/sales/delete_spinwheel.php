<?php
// delete_spinwheel.php
session_start();
include '../../connection/connection.php';
include '../checkrole/checkrole.php';

require_role(['sales']);

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $conn->query("DELETE FROM spinwheel_registrations WHERE id = $id");
}

header('Location: spinwheel_registrations_dashboard.php');
exit;
?>