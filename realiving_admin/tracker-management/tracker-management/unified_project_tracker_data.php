<?php
$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Get admin's role
$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

// Fetch client information with access control
if ($canViewAllClients) {
    $clientStmt = $conn->prepare("
        SELECT u.*, a.full_name as admin_name, a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.id = ?
    ");
    $clientStmt->bind_param("i", $client_id);
} else {
    $clientStmt = $conn->prepare("
        SELECT u.*, a.full_name as admin_name, a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.id = ? AND u.accountaid_fk = ?
    ");
    $clientStmt->bind_param("ii", $client_id, $admin_id);
}

$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();

if (!$client) {
    die("Access denied: Client not found or you don't have permission to view this client.");
}

$isNonProject = ($client['business_type'] ?? '') === 'Non-Project';
$business_type_label = $isNonProject ? 'Individual' : ($client['business_type'] ?? '');
// Fetch assigned staff IDs for mark-as-done checks
$assignedStaffStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id FROM user_info WHERE id = ?");
$assignedStaffStmt->bind_param("i", $client_id);
$assignedStaffStmt->execute();
$assignedStaffRow = $assignedStaffStmt->get_result()->fetch_assoc();
$assignedDesigner1Id = $assignedStaffRow['designer1_id'] ?? null;
$assignedDesigner2Id = $assignedStaffRow['designer2_id'] ?? null;
$assignedTechDesignId = $assignedStaffRow['technical_designer_id'] ?? null;
$assignedProjCoordId = $assignedStaffRow['project_coordinator_id'] ?? null;
$current_revision = $client['revision_count'] ?? 0;
$tracker_mode = $client['tracker_mode'] ?? 'non-sequential';

// Get permissions
// Check if this user is the accountaid_fk for this client
$isAccountFk = ($admin_id == ($ptAssignedRow['accountaid_fk'] ?? null));

$permissions = [];
if ($admin_role === 'sales' || $isAccountFk) {
    // Use per-user stage_permissions for sales AND accountaid_fk users
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id = ?");
    $permStmt->bind_param("i", $admin_id);
} else {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role = ?");
    $permStmt->bind_param("s", $admin_role);
}
$permStmt->execute();
$permResult = $permStmt->get_result();
while ($perm = $permResult->fetch_assoc()) {
    $permissions[$perm['stage_name']] = (bool) $perm['can_update'];
}

// Fetch tracker statuses
$trackerStmt = $conn->prepare("
    SELECT pt.*, a.full_name as updated_by_name
    FROM project_tracker pt
    LEFT JOIN account a ON pt.updated_by = a.id
    WHERE pt.client_id = ?
");
$trackerStmt->bind_param("i", $client_id);
$trackerStmt->execute();
$trackerResult = $trackerStmt->get_result();
$trackerData = [];

if ($trackerResult->num_rows > 0) {
    while ($row = $trackerResult->fetch_assoc()) {
        $row['assigned_people'] = [];
        $assignStmt = $conn->prepare("SELECT assigned_to FROM stage_assignments WHERE stage_id = ? ORDER BY assigned_at");
        $assignStmt->bind_param("i", $row['id']);
        $assignStmt->execute();
        $assignResult = $assignStmt->get_result();
        while ($assignRow = $assignResult->fetch_assoc()) {
            $row['assigned_people'][] = $assignRow['assigned_to'];
        }
        $trackerData[$row['stage_name']] = $row;
    }
} else {
    $stages_init = [
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
    // For Non-Project clients, remove inapplicable stages
    if ($isNonProject) {
        $stages_init = array_values(array_filter($stages_init, function ($s) {
            return $s !== 'Samples Submitted TDS/SDS';
        }));
    }
    $insertStmt = $conn->prepare("INSERT INTO project_tracker (client_id, stage_name, status, updated_at) VALUES (?, ?, 'Pending', NOW())");
    foreach ($stages_init as $stage) {
        $insertStmt->bind_param("is", $client_id, $stage);
        $insertStmt->execute();
    }
    $trackerStmt->execute();
    $trackerResult = $trackerStmt->get_result();
    while ($row = $trackerResult->fetch_assoc()) {
        $row['assigned_people'] = [];
        $trackerData[$row['stage_name']] = $row;
    }
}

$stages = [
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

// Remove stages not applicable for Non-Project (Individual) clients
if ($isNonProject) {
    $stages = array_values(array_filter($stages, function ($s) {
        return $s !== 'Samples Submitted TDS/SDS';
    }));
}

// Calculate progress — will be recalculated after statuses sync in the loop below
$total_stages = count($stages);
$pending_count = $ongoing_count = $done_count = 0;
foreach ($stages as $stageName) {
    $data = $trackerData[$stageName] ?? null;
    if (!$data) {
        $pending_count++;
        continue;
    }
    if ($data['status'] === 'Pending')
        $pending_count++;
    elseif ($data['status'] === 'Ongoing')
        $ongoing_count++;
    elseif ($data['status'] === 'Done')
        $done_count++;
}
$completion_percentage = ($done_count / $total_stages) * 100;

// Auto-mark client as Finished if all stages are Done
if ($done_count === $total_stages && $client['account_status'] !== 'Finished') {
    $finishStmt = $conn->prepare("UPDATE user_info SET account_status = 'Finished' WHERE id = ?");
    $finishStmt->bind_param("i", $client_id);
    $finishStmt->execute();
    $client['account_status'] = 'Finished';
}

$backUrl = $canViewAllClients ? BASE_URL . 'all-clients-tracker-list' : BASE_URL . 'client-tracker-list';
$backText = $canViewAllClients ? 'All Clients' : 'My Clients';

// Check if current user is assigned to this client (for payment tracker access)
$ptAssignedStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$ptAssignedStmt->bind_param("i", $client_id);
$ptAssignedStmt->execute();
$ptAssignedRow = $ptAssignedStmt->get_result()->fetch_assoc();

$isAssignedToClient = ($admin_id == ($ptAssignedRow['accountaid_fk'] ?? null));

$isAccountingRole = in_array($admin_role, ['accounting', 'general_manager', 'operational_manager', 'superadmin']);

// Stage type helpers
$approvalStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
$fileUploadStages = ['Reference', 'Internal P.O to Accounting', 'Handover'];
$autoStages = ['Fabrication', 'Delivery', 'Installation', 'BILLING', 'Downpayment', 'Cuttinglist', 'Production Data Submittals'];