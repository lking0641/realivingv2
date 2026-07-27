<?php
// update_tracker_status.php
session_start();
header('Content-Type: application/json');

include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$stage_id = isset($input['stage_id']) ? intval($input['stage_id']) : 0;
$new_status = isset($input['status']) ? $input['status'] : '';

// Validate status
$valid_statuses = ['Pending', 'Ongoing', 'Done'];
if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit();
}

// Verify this admin has permission to update this tracker
$verifyStmt = $conn->prepare("
    SELECT pt.client_id, pt.stage_name, ui.accountaid_fk, a.role
    FROM project_tracker pt
    JOIN user_info ui ON pt.client_id = ui.id
    LEFT JOIN account a ON a.id = ?
    WHERE pt.id = ?
");
$verifyStmt->bind_param("ii", $admin_id, $stage_id);
$verifyStmt->execute();
$verifyResult = $verifyStmt->get_result()->fetch_assoc();

if (!$verifyResult) {
    echo json_encode(['success' => false, 'error' => 'Tracker not found']);
    exit();
}

// Get admin's role
$admin_role = $verifyResult['role'];

// Define roles that can access all clients (matching all_clients_project_tracker.php)
$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];

// Check access: either assigned to this admin OR admin has role that can view all clients
$hasAccess = ($verifyResult['accountaid_fk'] == $admin_id) || in_array($admin_role, $allowedRolesForAllClients);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Check permission based on role — allow if EITHER individual OR role permission grants can_update
$canUpdate = false;
$stageName = $verifyResult['stage_name'];

if ($admin_role === 'sales') {
    // Sales: only individual stage_permissions apply
    $permStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $permStmt->bind_param("is", $admin_id, $stageName);
    $permStmt->execute();
    $permResult = $permStmt->get_result()->fetch_assoc();
    $canUpdate = $permResult && (bool) $permResult['can_update'];
} else {
    // All other roles: check role_stage_permissions first
    $rolePermStmt = $conn->prepare("SELECT can_update FROM role_stage_permissions WHERE role = ? AND stage_name = ?");
    $rolePermStmt->bind_param("ss", $admin_role, $stageName);
    $rolePermStmt->execute();
    $rolePermResult = $rolePermStmt->get_result()->fetch_assoc();
    $roleCanUpdate = $rolePermResult && (bool) $rolePermResult['can_update'];

    // Also check individual stage_permissions (granted per-user overrides)
    $indivPermStmt = $conn->prepare("SELECT can_update FROM stage_permissions WHERE admin_id = ? AND stage_name = ?");
    $indivPermStmt->bind_param("is", $admin_id, $stageName);
    $indivPermStmt->execute();
    $indivPermResult = $indivPermStmt->get_result()->fetch_assoc();
    $indivCanUpdate = $indivPermResult && (bool) $indivPermResult['can_update'];

    // Allow if EITHER grants permission
    $canUpdate = $roleCanUpdate || $indivCanUpdate;
}

// Special bypass for Production Data Submittals — TD, GM, OM can always update
if (!$canUpdate) {
    $isPDSBypass = (
        $stageName === 'Production Data Submittals' &&
        in_array($admin_role, ['technical_designer', 'superadmin'])
    );
    if (!$isPDSBypass) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to update this stage']);
        exit();
    }
}

// Update the status
$updateStmt = $conn->prepare("
    UPDATE project_tracker 
    SET status = ?, 
        updated_by = ?, 
        updated_at = NOW()
    WHERE id = ?
");
$updateStmt->bind_param("sii", $new_status, $admin_id, $stage_id);

if ($updateStmt->execute()) {

    // Cascade revert: if BOM goes back to Ongoing/Pending, revert PO and Accounting too
    // If PO goes back to Ongoing/Pending, revert Accounting too
    if (in_array($new_status, ['Ongoing', 'Pending'])) {
        $client_id_cascade = $verifyResult['client_id'];

        if ($stageName === 'Bill of Materials (BOM)') {
            $cascadeStmt = $conn->prepare("
                UPDATE project_tracker
                SET status = 'Ongoing', updated_at = NOW()
                WHERE client_id = ?
                  AND stage_name IN ('Purchase Order (Submit to accounting)', 'Accounting (Order Processing)')
                  AND status = 'Done'
            ");
            $cascadeStmt->bind_param("i", $client_id_cascade);
            $cascadeStmt->execute();

        } elseif ($stageName === 'Purchase Order (Submit to accounting)') {
            $cascadeStmt = $conn->prepare("
                UPDATE project_tracker
                SET status = 'Ongoing', updated_at = NOW()
                WHERE client_id = ?
                  AND stage_name = 'Accounting (Order Processing)'
                  AND status = 'Done'
            ");
            $cascadeStmt->bind_param("i", $client_id_cascade);
            $cascadeStmt->execute();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $conn->error
    ]);
}
?>