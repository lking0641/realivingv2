<?php
//site_visit_reset.php
session_start();
header('Content-Type: application/json');
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Enforce super_admin only
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$adminRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? '';

if ($adminRole !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$visit_id = isset($data['visit_id']) ? intval($data['visit_id']) : 0;
$action = isset($data['action']) ? trim($data['action']) : '';

if (!$visit_id || !in_array($action, ['reset_designer1_report', 'reset_designer2_report'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Confirm visit exists
$checkStmt = $conn->prepare("SELECT * FROM site_visit WHERE id = ?");
$checkStmt->bind_param("i", $visit_id);
$checkStmt->execute();
$visit = $checkStmt->get_result()->fetch_assoc();
if (!$visit) {
    echo json_encode(['success' => false, 'error' => 'Visit not found']);
    exit();
}

if ($action === 'reset_designer1_report') {
    // Delete photo if exists
    if (!empty($visit['designer1_photo'])) {
        $photoPath = ROOT_PATH . 'uploads/site_visit_photos/' . $visit['designer1_photo'];
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
    }

    // Reset designer1 fields
    $resetStmt = $conn->prepare("
        UPDATE site_visit
        SET designer1_finished = 0,
            designer1_finished_at = NULL,
            designer1_report = NULL,
            designer1_photo = NULL
        WHERE id = ?
    ");
    $resetStmt->bind_param("i", $visit_id);
    $resetStmt->execute();

    echo json_encode(['success' => true, 'message' => 'Designer 1 report reset']);
    exit();
}

if ($action === 'reset_designer2_report') {
    // Delete photo if exists
    if (!empty($visit['designer2_photo'])) {
        $photoPath = ROOT_PATH . 'uploads/site_visit_photos/' . $visit['designer2_photo'];
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
    }

    // Reset designer2 fields
    $resetStmt = $conn->prepare("
        UPDATE site_visit
        SET designer2_finished = 0,
            designer2_finished_at = NULL,
            designer2_report = NULL,
            designer2_photo = NULL
        WHERE id = ?
    ");
    $resetStmt->bind_param("i", $visit_id);
    $resetStmt->execute();

    echo json_encode(['success' => true, 'message' => 'Designer 2 report reset']);
    exit();
}