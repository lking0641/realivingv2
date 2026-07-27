<?php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) { header("Location: ../login.php"); exit(); }

$admin_id    = $_SESSION['admin_id'];
$client_id   = intval($_POST['client_id'] ?? 0);
$redirect_url = trim($_POST['redirect_url'] ?? '');
$fallback     = BASE_URL . 'designer-2d3d-layout?client_id=' . $client_id;

// Verify role — only assigned designer
$meStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
if (!in_array($me['role'], ['designer', 'technical_designer'])) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Verify assignment
$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientRow = $assignStmt->get_result()->fetch_assoc();
if (!$clientRow || ($clientRow['designer1_id'] != $admin_id && $clientRow['designer2_id'] != $admin_id)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Mark the 2D / 3D Layout stage as Done
$updStmt = $conn->prepare("
    UPDATE project_tracker
    SET status = 'Done', updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = '2D / 3D Layout'
");
$updStmt->bind_param("ii", $admin_id, $client_id);
$updStmt->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("2D / 3D Layout marked as Done."));
exit();