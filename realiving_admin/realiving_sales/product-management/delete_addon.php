<?php
// delete_addon.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

// Allow only admin1, superadmin, sales
require_role(['admin1','superadmin', 'sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: " . BASE_URL . "designer-layout-list");
        exit();
    }
}

// Must have a valid ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid addon ID.";
    header("Location: " . BASE_URL . "view-addons");
    exit();
}

$addon_id = (int) $_GET['id'];

// Fetch the addon so we can delete its image file too
$stmt = $conn->prepare("SELECT addon_name, addon_image_path FROM product_addons WHERE id = ?");
$stmt->bind_param("i", $addon_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    $_SESSION['error_message'] = "Addon not found.";
    header("Location: " . BASE_URL . "view-addons");
    exit();
}

$addon = $result->fetch_assoc();
$stmt->close();

// Delete the image file from disk if it exists
if (!empty($addon['addon_image_path'])) {
    $image_path = ROOT_PATH . 'realiving_user/images/product_addons/' . $addon['addon_image_path'];
    if (file_exists($image_path)) {
        unlink($image_path);
    }
}

// Delete the record from the database
$del_stmt = $conn->prepare("DELETE FROM product_addons WHERE id = ?");
$del_stmt->bind_param("i", $addon_id);

if ($del_stmt->execute()) {
    $_SESSION['success_message'] = "Addon \"" . htmlspecialchars($addon['addon_name']) . "\" has been deleted successfully.";
} else {
    $_SESSION['error_message'] = "Error deleting addon: " . $del_stmt->error;
}

$del_stmt->close();
$conn->close();

header("Location: " . BASE_URL . "view-addons");
exit();
?>