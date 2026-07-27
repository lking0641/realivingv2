<?php
// save_role_permissions.php
session_start();
header('Content-Type: application/json');

include '../../connection/connection.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Get admin name for audit trail
$adminStmt = $conn->prepare("SELECT full_name FROM account WHERE id = ?");
$adminStmt->bind_param("i", $admin_id);
$adminStmt->execute();
$adminResult = $adminStmt->get_result()->fetch_assoc();
$admin_name = $adminResult['full_name'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$role = isset($input['role']) ? $input['role'] : '';
$enabled_stages = isset($input['stages']) ? $input['stages'] : [];

if (empty($role)) {
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit();
}

// All possible stages
$all_stages = [
    'Rough Estimation',
    'Site Visit',
    '2D / 3D Layout',
    'Reference',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Internal P.O to Accounting',
    'Downpayment',
    'Cuttinglist',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
    'Accounting (Order Processing)',
    'Production Data Submittals',
    'Fabrication',
    'Delivery',
    'Installation',
    'BILLING',
    'Handover'
];

try {
    // Start transaction
    $conn->begin_transaction();

    // Delete existing permissions for this role
    $deleteStmt = $conn->prepare("DELETE FROM role_stage_permissions WHERE role = ?");
    $deleteStmt->bind_param("s", $role);
    $deleteStmt->execute();

    // Insert new permissions with audit trail
    $insertStmt = $conn->prepare("
        INSERT INTO role_stage_permissions (role, stage_name, can_update, updated_by, updated_by_name)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($all_stages as $stage) {
        $can_update = in_array($stage, $enabled_stages) ? 1 : 0;
        $insertStmt->bind_param("ssiis", $role, $stage, $can_update, $admin_id, $admin_name);
        $insertStmt->execute();
    }

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role permissions saved successfully'
    ]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();

    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>