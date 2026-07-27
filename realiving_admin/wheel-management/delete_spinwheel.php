<?php
// delete_spinwheel.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

require_role(['sales']);

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $conn->query("DELETE FROM spinwheel_registrations WHERE id = $id");
}

header('Location: ' . BASE_URL . 'spinwheel-registrations-dashboard');
exit;
?>