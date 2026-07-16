<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app_config.php';
include $includes['connection'];

function require_role($allowed_roles) {
    global $conn;
    
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
        header("Location: " . BASE_URL . "login");
        exit();
    }

    if (!in_array($_SESSION['admin_role'], $allowed_roles)) {
        $_SESSION['noti'] = 'Access Denied: You do not have permission to view this page.';

        switch ($_SESSION['admin_role']) {
            case 'general_manager':
                header("Location: " . BASE_URL . "realiving_admin/manager_tracker/manager_status_tracker.php");
                break;
            case 'operational_manager':
                header("Location: " . BASE_URL . "realiving_admin/manager_tracker/manager_status_tracker.php");
                break;
            case 'sales':
                header("Location: " . BASE_URL . "realiving_admin/sales/sales_dashboard.php");
                break;
            case 'designer':
                $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
                $headCheck->bind_param("i", $_SESSION['admin_id']);
                $headCheck->execute();
                $headRow = $headCheck->get_result()->fetch_assoc();
                if (!empty($headRow['is_head'])) {
                    header("Location: " . BASE_URL . "realiving_admin/tracker_management/all_clients_tracker_list.php");
                } else {
                    header("Location: " . BASE_URL . "realiving_admin/tracker_site_visit/designer_layout_list.php");
                }
                break;
            case 'technical_designer':
                $headCheck2 = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
                $headCheck2->bind_param("i", $_SESSION['admin_id']);
                $headCheck2->execute();
                $headRow2 = $headCheck2->get_result()->fetch_assoc();
                if (!empty($headRow2['is_head'])) {
                    header("Location: " . BASE_URL . "realiving_admin/tracker_management/all_clients_tracker_list.php");
                } else {
                    header("Location: " . BASE_URL . "realiving_admin/tracker_technical/td_layout_list.php");
                }
                break;
            case 'accounting':
                header("Location: " . BASE_URL . "realiving_admin/admin_mainpage/mainpage.php");
                break;
            case 'project_coordinator':
                header("Location: " . BASE_URL . "realiving_admin/admin_mainpage/mainpage.php");
                break;
            case 'superadmin':
                header("Location: " . BASE_URL . "realiving_admin/admin_mainpage/mainpage.php");
                break;
            default:
                header("Location: " . BASE_URL . "login");
                break;
        }
        exit();
    }
}
?>