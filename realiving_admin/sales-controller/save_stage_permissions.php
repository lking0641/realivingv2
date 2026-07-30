<?php
// save_stage_permissions.php
session_start();
header('Content-Type: application/json');

include $includes['connection'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$admin_id = isset($input['admin_id']) ? intval($input['admin_id']) : 0;
$enabled_stages = isset($input['stages']) ? $input['stages'] : [];

if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid admin ID']);
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

    // Delete existing permissions for this admin
    $deleteStmt = $conn->prepare("DELETE FROM stage_permissions WHERE admin_id = ?");
    $deleteStmt->bind_param("i", $admin_id);
    $deleteStmt->execute();

    // Insert new permissions
    $insertStmt = $conn->prepare("
        INSERT INTO stage_permissions (admin_id, stage_name, can_update)
        VALUES (?, ?, ?)
    ");

    foreach ($all_stages as $stage) {
        $can_update = in_array($stage, $enabled_stages) ? 1 : 0;
        $insertStmt->bind_param("isi", $admin_id, $stage, $can_update);
        $insertStmt->execute();
    }

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Permissions saved successfully'
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