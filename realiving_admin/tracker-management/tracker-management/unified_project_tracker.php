<?php
// unified_project_tracker.php
include $includes ['mainbody'];

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

// ── Pending layout approvals for this approver on this client ────────────

function getStagePendingApprovalCount($conn, $admin_id, $admin_role, $is_head, $stage_id)
{
    if (!$stage_id)
        return 0;
    // Check if this role can approve
    $approvalStageRoles = [
        'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
        'Quotation' => ['designer', 'general_manager', 'operational_manager'],
        'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
        'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
    ];
    // Find which stage this stage_id belongs to — check all
    $stStmt = $conn->prepare("SELECT stage_name FROM project_tracker WHERE id = ?");
    $stStmt->bind_param("i", $stage_id);
    $stStmt->execute();
    $stRow = $stStmt->get_result()->fetch_assoc();
    if (!$stRow)
        return 0;
    $stageName = $stRow['stage_name'];
    $rolesForStage = $approvalStageRoles[$stageName] ?? [];
    $canApprove = false;
    $allGmOmStages = [
        'Rough Estimation',
        'Samples Submitted TDS/SDS',
        'Quotation',
        'Bill of Materials (BOM)',
        'Purchase Order (Submit to accounting)',
    ];

    if ($admin_role === 'technical_designer') {
        if (in_array('technical_designer', $rolesForStage) && $is_head)
            $canApprove = true;
    } elseif ($admin_role === 'designer') {
        if (in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $is_head)
            $canApprove = true;
    } elseif ($admin_role === 'accounting') {
        if ($stageName === 'Purchase Order (Submit to accounting)')
            $canApprove = true;
    } elseif (in_array($admin_role, ['general_manager', 'operational_manager'])) {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    } else {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    }
    if (!$canApprove)
        return 0;

    // GM/OM: skip if other already approved (all sequential stages)
    if (in_array($stageName, $allGmOmStages) && in_array($admin_role, ['general_manager', 'operational_manager'])) {
        $otherRole = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
        $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        WHERE sa.stage_id = ?
          AND sa.approval_status = 'pending'
          AND NOT EXISTS (
              SELECT 1 FROM stage_approval_reviews sar
              WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
          )
          AND NOT EXISTS (
              SELECT 1 FROM stage_approval_reviews sar2
              WHERE sar2.approval_id = sa.id AND sar2.reviewer_role = ?
              AND sar2.review_status = 'approved'
          )
    ");
        $stmt->bind_param("iss", $stage_id, $admin_role, $otherRole);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_row()[0];
    }

    $stmt = $conn->prepare("
    SELECT COUNT(*) FROM stage_approvals sa
    WHERE sa.stage_id = ?
      AND sa.approval_status = 'pending'
      AND NOT EXISTS (
          SELECT 1 FROM stage_approval_reviews sar
          WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
      )
");
    $stmt->bind_param("is", $stage_id, $admin_role);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getLayoutPendingCount($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM layout_revision_log rl
            WHERE rl.client_id = la.client_id
            AND rl.area = la.area
            AND rl.status = 'pending'
            AND (
                (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                OR rl.room_unit_number = la.room_unit_number
            )
        )
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getStageIcon($stage)
{
    $icons = [
        'Rough Estimation' => 'fa-ruler-combined',
        'Site Visit' => 'fa-map-marker-alt',
        '2D / 3D Layout' => 'fa-drafting-compass',
        'Reference' => 'fa-folder-open',
        'Samples Submitted TDS/SDS' => 'fa-vials',
        'Quotation' => 'fa-file-invoice-dollar',
        'Internal P.O to Accounting' => 'fa-file-signature',
        'Downpayment' => 'fa-coins',
        'Cuttinglist' => 'fa-cut',
        'Bill of Materials (BOM)' => 'fa-calculator',
        'Purchase Order (Submit to accounting)' => 'fa-shopping-cart',
        'Accounting (Order Processing)' => 'fa-receipt',
        'Production Data Submittals' => 'fa-industry',
        'Fabrication' => 'fa-tools',
        'Delivery' => 'fa-truck',
        'Installation' => 'fa-hard-hat',
        'BILLING' => 'fa-file-invoice',
        'Handover' => 'fa-key',
    ];
    return $icons[$stage] ?? 'fa-circle';
}

function getStageTypeBadge($stage, $approvalStages, $fileUploadStages, $autoStages)
{
    if (in_array($stage, $approvalStages))
        return ['label' => 'Approval Required', 'class' => 'badge-approval'];
    if (in_array($stage, $fileUploadStages))
        return ['label' => 'File Upload', 'class' => 'badge-upload'];
    if (in_array($stage, $autoStages))
        return ['label' => 'Auto-Tracked', 'class' => 'badge-auto'];
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #f0ebe4;
            --surface: #faf8f5;
            --border: #e2d9ce;
            --brown-dk: #3b1f0f;
            --brown-md: #7a4528;
            --brown-lt: #c49a78;
            --brown-pale: #ecddd0;
            --text-dk: #1c1007;
            --text-md: #5c4033;
            --text-lt: #9c7b6a;
            --pending: #f59e0b;
            --ongoing: #3b82f6;
            --done: #10b981;
            --pending-bg: #fffbeb;
            --ongoing-bg: #eff6ff;
            --done-bg: #f0fdf4;
            --radius: 10px;
            --shadow: 0 1px 3px rgba(59, 31, 15, .08), 0 4px 16px rgba(59, 31, 15, .06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg);
            font-family: 'DM Sans', sans-serif;
            color: var(--text-dk);
            min-height: 100vh;
        }

        /* ── Layout ── */
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        /* ── Back link ── */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 600;
            color: var(--brown-md);
            text-decoration: none;
            margin-bottom: 24px;
            transition: color .2s;
        }

        .back-link:hover {
            color: var(--brown-dk);
        }

        /* ── Client card ── */
        .client-card {
            background: var(--brown-dk);
            border-radius: 16px;
            padding: 32px;
            color: #fff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }

        .client-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top right, rgba(196, 154, 120, .25) 0%, transparent 65%);
            pointer-events: none;
        }

        .client-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        @media (max-width: 600px) {
            .client-card-top {
                flex-direction: column;
                gap: 12px;
            }

            .client-card-top>div:last-child {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: flex-start;
                gap: 8px;
                width: 100%;
            }

            .viewer-badge {
                width: 100%;
                justify-content: flex-start;
            }

            .meta-pills {
                gap: 8px;
            }

            .meta-pill {
                flex: 1 1 calc(50% - 8px);
                min-width: 120px;
                padding: 8px 12px;
            }

            .client-title {
                font-size: 18px;
            }
        }

        .client-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -.3px;
        }

        .client-sub {
            font-size: 14px;
            opacity: .7;
        }

        .viewer-badge {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .viewer-role {
            background: rgba(255, 255, 255, .15);
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 11px;
            text-transform: capitalize;
        }

        .meta-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }

        .meta-pill {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            min-width: 0;
            word-break: break-all;
        }

        .meta-pill-label {
            opacity: .6;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 3px;
        }

        .meta-pill-value {
            font-weight: 600;
            font-size: 13px;
        }

        /* ── Progress bar card ── */
        .progress-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 26px;
            margin-bottom: 28px;
            box-shadow: var(--shadow);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .progress-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-md);
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .progress-pct {
            font-size: 22px;
            font-weight: 700;
            color: var(--brown-dk);
            font-family: 'DM Mono', monospace;
        }

        .progress-track {
            height: 6px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--brown-dk), var(--brown-lt));
            border-radius: 99px;
            transition: width .6s ease;
        }

        .progress-stats {
            display: flex;
            gap: 20px;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-md);
        }

        .stat-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .stat-dot.pending {
            background: var(--pending);
        }

        .stat-dot.ongoing {
            background: var(--ongoing);
        }

        .stat-dot.done {
            background: var(--done);
        }

        /* ── Timeline ── */
        .timeline {
            position: relative;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 27px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, var(--brown-lt) 0%, var(--border) 100%);
            border-radius: 2px;
        }

        .tl-item {
            display: flex;
            gap: 18px;
            margin-bottom: 12px;
            position: relative;
        }

        /* ── Node ── */
        .tl-node {
            flex-shrink: 0;
            width: 56px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
            position: relative;
            z-index: 1;
        }

        .node-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            border: 2px solid var(--border);
            background: var(--surface);
            transition: all .25s;
            flex-shrink: 0;
        }

        .node-icon.pending {
            background: var(--pending-bg);
            border-color: var(--pending);
            color: var(--pending);
        }

        .node-icon.ongoing {
            background: var(--ongoing-bg);
            border-color: var(--ongoing);
            color: var(--ongoing);
        }

        .node-icon.done {
            background: var(--done-bg);
            border-color: var(--done);
            color: var(--done);
        }

        /* ── Card ── */
        .tl-card {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 18px;
            box-shadow: var(--shadow);
            transition: box-shadow .2s, border-color .2s;
            margin-bottom: 0;
        }

        .tl-card:hover {
            box-shadow: 0 4px 20px rgba(59, 31, 15, .12);
            border-color: var(--brown-pale);
        }

        .tl-card.pending {
            border-left: 3px solid var(--pending);
        }

        .tl-card.ongoing {
            border-left: 3px solid var(--ongoing);
        }

        .tl-card.done {
            border-left: 3px solid var(--done);
        }

        .tl-card.locked {
            opacity: .6;
            filter: grayscale(.4);
        }

        /* card top row */
        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 8px;
        }

        .card-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }

        .stage-num {
            font-family: 'DM Mono', monospace;
            font-size: 10px;
            font-weight: 500;
            color: var(--text-lt);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 2px 6px;
            flex-shrink: 0;
        }

        .stage-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dk);
            line-height: 1.3;
        }

        .stage-name a {
            color: inherit;
            text-decoration: none;
        }

        .stage-name a:hover {
            color: var(--brown-md);
            text-decoration: underline;
        }

        /* status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            flex-shrink: 0;
        }

        .status-badge.pending {
            background: var(--pending-bg);
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-badge.ongoing {
            background: var(--ongoing-bg);
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .status-badge.done {
            background: var(--done-bg);
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        /* type badges */
        .type-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .type-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .badge-approval {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .badge-upload {
            background: #ede9fe;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        .badge-auto {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        /* meta row */
        .card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 11px;
            color: var(--text-lt);
            margin-top: 6px;
        }

        .card-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* assigned */
        .assigned-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }

        .assigned-chip {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 11px;
            color: var(--text-md);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* action row */
        .card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
            text-decoration: none;
        }

        .btn-ghost {
            background: var(--bg);
            color: var(--text-md);
            border: 1px solid var(--border);
        }

        .btn-ghost:hover {
            background: var(--brown-pale);
            border-color: var(--brown-lt);
        }

        .btn-primary {
            background: var(--brown-dk);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--brown-md);
        }

        .btn.active-status {
            box-shadow: 0 0 0 2px currentColor;
        }

        /* lock badge */
        .lock-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 700;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 20px;
            padding: 2px 8px;
        }

        /* Go-to page button */
        .btn-goto {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: var(--brown-dk);
            color: #fff;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all .2s;
            flex-shrink: 0;
        }

        .btn-goto:hover {
            background: var(--brown-md);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(59, 31, 15, .25);
        }

        /* Mark as Done button */
        .btn-mark-done {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            background: var(--done);
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 2px 8px rgba(16, 185, 129, .35);
        }

        .btn-mark-done:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(16, 185, 129, .45);
        }

        /* file count chip */
        .file-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-md);
            text-decoration: none;
            transition: all .2s;
        }

        .file-chip:hover {
            background: var(--brown-pale);
            border-color: var(--brown-lt);
            color: var(--brown-dk);
        }

        .file-chip .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        /* mode badge */
        .mode-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--brown-pale);
            border: 1px solid var(--brown-lt);
            border-radius: 7px;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 700;
            color: var(--brown-md);
            margin-bottom: 16px;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 28px;
            right: 28px;
            background: var(--brown-dk);
            color: #fff;
            padding: 13px 22px;
            border-radius: var(--radius);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 32px rgba(59, 31, 15, .3);
            transform: translateY(80px);
            opacity: 0;
            transition: all .35s cubic-bezier(.34, 1.56, .64, 1);
            z-index: 9999;
            pointer-events: none;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.error {
            background: #dc2626;
        }

        /* ── Client Detail Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 14px;
            padding: 28px;
            max-width: 580px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            animation: modalIn .2s ease;
        }

        @keyframes modalIn {
            from {
                transform: scale(0.95);
                opacity: 0
            }

            to {
                transform: scale(1);
                opacity: 1
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f3f4f6;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #3b1f0f;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            font-size: 20px;
            color: #9ca3af;
            background: none;
            border: none;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
        }

        .modal-close:hover {
            color: #374151;
        }

        .modal-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            align-items: start;
            gap: 10px;
        }

        @media (max-width: 600px) {
            .modal-row {
                grid-template-columns: 1fr;
                gap: 3px;
            }

            .modal-row-value {
                word-break: break-all;
            }

            .modal-box {
                padding: 20px 16px;
            }
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-row-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 13px;
        }

        .modal-row-value {
            color: #111;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .timeline::before {
                left: 19px;
            }

            .tl-node {
                width: 40px;
            }

            .node-icon {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }

            .card-top {
                flex-direction: column;
            }

            .client-card-top {
                flex-direction: column;
            }

            .progress-stats {
                flex-wrap: wrap;
                gap: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="page">

        <!-- Back -->
        <a href="<?= $backUrl ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> <?= $backText ?>
        </a>

        <?php if (isset($_GET['locked'])): ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:14px 20px; margin-bottom:20px; display:flex; align-items:center; gap:12px; font-size:13px; color:#92400e; font-weight:600;">
                <i class="fas fa-lock" style="font-size:16px; color:#d97706;"></i>
                This stage is locked. Complete the previous stage first before accessing its files.
            </div>
        <?php endif; ?>

        <!-- Client header -->
        <div class="client-card">
            <div class="client-card-top">
                <div>
                    <div class="client-title"><?= htmlspecialchars($client['clientname']) ?></div>
                    <div class="client-sub"><?= htmlspecialchars($client['nameproject']) ?></div>
                </div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                    <button onclick="document.getElementById('clientDetailModal').classList.add('open')" style="background:white; color:#3b1f0f; padding:8px 16px; border:none; border-radius:8px;
                       cursor:pointer; font-weight:700; font-size:13px; display:inline-flex;
                       align-items:center; gap:7px; transition:all 0.2s; flex-shrink:0;">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div class="viewer-badge">
                        <i class="fas fa-user-circle"></i>
                        <?= htmlspecialchars($userInfo['full_name']) ?>
                        <span class="viewer-role"><?= str_replace('_', ' ', $admin_role) ?></span>
                    </div>
                </div><!-- /viewer wrapper -->
            </div>
            <div class="meta-pills">
                <div class="meta-pill">
                    <div class="meta-pill-label">Reference</div>
                    <div class="meta-pill-value"><?= htmlspecialchars($client['reference_number']) ?></div>
                </div>
                <div class="meta-pill">
                    <div class="meta-pill-label">Status</div>
                    <div class="meta-pill-value"><?= htmlspecialchars($client['status']) ?></div>
                </div>
                <div class="meta-pill">
                    <div class="meta-pill-label">Type</div>
                    <div class="meta-pill-value"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <?php if ($client['admin_name']): ?>
                    <div class="meta-pill">
                        <div class="meta-pill-label">Assigned To</div>
                        <div class="meta-pill-value"><?= htmlspecialchars($client['admin_name']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="meta-pill">
                    <div class="meta-pill-label">Tracker Mode</div>
                    <div class="meta-pill-value" style="text-transform: capitalize;">
                        <?= str_replace('-', ' ', $tracker_mode) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress -->
        <div class="progress-card">
            <div class="progress-header">
                <span class="progress-title">Overall Progress</span>
                <span class="progress-pct"><?= number_format($completion_percentage, 1) ?>%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width:<?= $completion_percentage ?>%"></div>
            </div>
            <div class="progress-stats">
                <div class="stat"><span class="stat-dot pending"></span><?= $pending_count ?> Pending</div>
                <div class="stat"><span class="stat-dot ongoing"></span><?= $ongoing_count ?> Ongoing</div>
                <div class="stat"><span class="stat-dot done"></span><?= $done_count ?> Done</div>
                <div class="stat" style="margin-left:auto; color:var(--text-lt);"><?= $total_stages ?> total stages
                </div>
            </div>
        </div>

        <?php if ($tracker_mode === 'sequential'): ?>
            <div class="mode-badge"><i class="fas fa-lock"></i> Sequential Mode — stages must be completed in order</div>
        <?php endif; ?>

        <?php
        // ── Payment proof notifications ──────────────────────────────
        
        // For accounting: check if there are pending proofs waiting for review
        if ($isAccountingRole) {
            $pendingProofStmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND par.review_status = 'pending'
          AND ps.accounting_status = 'pending_review'
    ");
            $pendingProofStmt->bind_param("i", $client_id);
            $pendingProofStmt->execute();
            $pendingProofCount = (int) $pendingProofStmt->get_result()->fetch_assoc()['cnt'];

            if ($pendingProofCount > 0):
                ?>
                <div
                    style="background:#fffbeb; border:2px solid #f59e0b; border-radius:10px; padding:14px 20px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <i class="fas fa-file-invoice-dollar" style="color:#d97706; font-size:20px; flex-shrink:0;"></i>
                        <div>
                            <div style="font-weight:700; font-size:14px; color:#92400e;">
                                <?= $pendingProofCount ?> payment proof<?= $pendingProofCount > 1 ? 's' : '' ?> waiting for your
                                review
                            </div>
                            <div style="font-size:12px; color:#b45309; margin-top:2px;">
                                Open the Payment Tracker to approve or reject the submitted proofs.
                            </div>
                        </div>
                    </div>
                    <a href="payment-tracker?client_id=<?= $client_id ?>"
                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                        <i class="fas fa-arrow-right"></i> Review Proofs
                    </a>
                </div>
            <?php endif;
        } ?>

        <?php
        // For assigned user: check if any of their submitted proofs were rejected
        if ($isAssignedToClient && !$isAccountingRole) {
            $rejectedProofStmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND pp.uploaded_by = ?
          AND par.review_status = 'rejected'
          AND ps.accounting_status = 'rejected'
    ");
            $rejectedProofStmt->bind_param("ii", $client_id, $admin_id);
            $rejectedProofStmt->execute();
            $rejectedProofCount = (int) $rejectedProofStmt->get_result()->fetch_assoc()['cnt'];

            if ($rejectedProofCount > 0):
                ?>
                <div
                    style="background:#fee2e2; border:2px solid #ef4444; border-radius:10px; padding:14px 20px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <i class="fas fa-times-circle" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                        <div>
                            <div style="font-weight:700; font-size:14px; color:#991b1b;">
                                <?= $rejectedProofCount ?> payment proof<?= $rejectedProofCount > 1 ? 's' : '' ?> you submitted
                                <?= $rejectedProofCount > 1 ? 'were' : 'was' ?> rejected
                            </div>
                            <div style="font-size:12px; color:#b91c1c; margin-top:2px;">
                                Open the Payment Tracker to view the rejection reason and resubmit.
                            </div>
                        </div>
                    </div>
                    <a href="payment-tracker?client_id=<?= $client_id ?>"
                        style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                        <i class="fas fa-redo"></i> Resubmit Proof
                    </a>
                </div>
            <?php endif;
        } ?>

        <!-- Timeline -->
        <?php $layoutPendingCount = getLayoutPendingCount($conn, $admin_id, $client_id); ?>
        <div class="timeline">
            <?php foreach ($stages as $index => $stage):

                $stageData = $trackerData[$stage] ?? null;
                $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;
                $isApproval = in_array($stage, $approvalStages);
                $isFileUpload = in_array($stage, $fileUploadStages);
                $isAuto = in_array($stage, $autoStages);

                // Sequential lock
                $isLocked = false;
                if ($tracker_mode === 'sequential' && $index > 0) {
                    if ($index >= 6) {
                        $prevStatus = isset($trackerData[$stages[$index - 1]]) ? $trackerData[$stages[$index - 1]]['status'] : 'Pending';

                        // For Delivery and Installation: unlock as soon as at least one item is Done
                        // in the previous stage (item-level check), not waiting for full stage completion
                        $itemDepStages = ['Delivery' => 'Fabrication', 'Installation' => 'Delivery', 'BILLING' => 'Installation'];
                        if (isset($itemDepStages[$stage])) {
                            $prevItemCol = strtolower($itemDepStages[$stage]) . '_status';
                            $itemDepStmt = $conn->prepare("
                SELECT COUNT(*) AS has_done
                FROM (
                    SELECT $prevItemCol FROM quotation_entries WHERE client_id = ? AND $prevItemCol = 'Done'
                    UNION ALL
                    SELECT $prevItemCol FROM quotation_fixed_sizes WHERE client_id = ? AND $prevItemCol = 'Done'
                ) x
            ");
                            $itemDepStmt->bind_param("ii", $client_id, $client_id);
                            $itemDepStmt->execute();
                            $hasDoneItem = (int) $itemDepStmt->get_result()->fetch_assoc()['has_done'];

                            if ($hasDoneItem === 0) {
                                $isLocked = true;
                                $canUpdate = false;
                            }
                            // else: at least one item is Done in prev stage → unlock
                        } else {
                            // Normal stages: lock if previous is Pending
                            if ($prevStatus === 'Pending') {
                                $isLocked = true;
                                $canUpdate = false;
                            }
                        }
                    }
                }

                // ── Compute status ──────────────────────────────────────────────
                $status = $stageData ? $stageData['status'] : 'Pending';

                if ($stage === 'Downpayment') {
                    $dpStmt = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                    $dpStmt->bind_param("i", $client_id);
                    $dpStmt->execute();
                    $dpRow = $dpStmt->get_result()->fetch_assoc();
                    $status = ($dpRow && $dpRow['status'] === 'Paid') ? 'Done' : 'Pending';

                    // Sync computed Downpayment status back to DB so progress counter is accurate
                    if ($stageData && $stageData['status'] !== $status) {
                        $syncDpStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                        $syncDpStmt->bind_param("si", $status, $stageData['id']);
                        $syncDpStmt->execute();
                        $trackerData[$stage]['status'] = $status;
                    }
                } elseif ($stage === 'BILLING') {
                    // Fetch all payment rows
                    $bStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ? AND payment_type NOT LIKE '%Down Payment%'");
                    $bStmt->bind_param("i", $client_id);
                    $bStmt->execute();
                    $bRow = $bStmt->get_result()->fetch_assoc();

                    $dpChk = $conn->prepare("SELECT COUNT(*) AS dp FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down Payment%' AND status='Paid'");
                    $dpChk->bind_param("i", $client_id);
                    $dpChk->execute();
                    $dpPaid = $dpChk->get_result()->fetch_assoc()['dp'] > 0;

                    $hasCollections = $bRow['total'] > 0;
                    $allCollectionsPaid = $hasCollections && $bRow['paid'] == $bRow['total'];

                    if (($client['business_type'] ?? '') === 'Project') {
                        // Project: also require installation to be 100% complete
                        $instStmt = $conn->prepare("
            SELECT CASE
                WHEN COUNT(*) = 0 THEN 0
                WHEN COUNT(*) = SUM(CASE WHEN installation_status = 'Done' THEN 1 ELSE 0 END) THEN 1
                ELSE 0
            END AS all_done
            FROM (
                SELECT installation_status FROM quotation_entries WHERE client_id = ?
                UNION ALL
                SELECT installation_status FROM quotation_fixed_sizes WHERE client_id = ?
            ) x
        ");
                        $instStmt->bind_param("ii", $client_id, $client_id);
                        $instStmt->execute();
                        $instAllDone = (bool) ($instStmt->get_result()->fetch_assoc()['all_done'] ?? false);

                        if ($allCollectionsPaid && $instAllDone)
                            $status = 'Done';
                        elseif ($bRow['paid'] > 0 || $dpPaid)
                            $status = 'Ongoing';
                        else
                            $status = 'Pending';

                    } else {
                        // Non-Project (Individual): check all 3 payments — DP + 40% + 10%
                        $allPayStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ?");
                        $allPayStmt->bind_param("i", $client_id);
                        $allPayStmt->execute();
                        $allPayRow = $allPayStmt->get_result()->fetch_assoc();

                        $anyPaid = $allPayRow['paid'] > 0;
                        $allPaid = $allPayRow['total'] > 0 && $allPayRow['paid'] == $allPayRow['total'];

                        if ($allPaid)
                            $status = 'Done';
                        elseif ($anyPaid)
                            $status = 'Ongoing';
                        else
                            $status = 'Pending';
                    }

                    // Sync computed BILLING status back to DB so progress counter is accurate
                    if ($stageData && $stageData['status'] !== $status) {
                        $syncBillStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                        $syncBillStmt->bind_param("si", $status, $stageData['id']);
                        $syncBillStmt->execute();
                        // Also update the in-memory trackerData so progress counts correctly
                        $trackerData[$stage]['status'] = $status;
                    }
                } elseif ($stage === 'Cuttinglist') {
                    // Auto-set Cuttinglist to Ongoing when Downpayment is Ongoing or Done
                    $dpStmt2 = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                    $dpStmt2->bind_param("i", $client_id);
                    $dpStmt2->execute();
                    $dpRow2 = $dpStmt2->get_result()->fetch_assoc();
                    $downpaymentStatus = ($dpRow2 && $dpRow2['status'] === 'Paid') ? 'Done' : 'Pending';

                    if ($status === 'Done') {
                        $status = 'Done';
                    } elseif (in_array($downpaymentStatus, ['Ongoing', 'Done'])) {
                        $status = 'Ongoing';
                        if ($stageData && $stageData['status'] !== 'Ongoing') {
                            $syncStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW() WHERE id = ?");
                            $syncStmt->bind_param("i", $stageData['id']);
                            $syncStmt->execute();
                        }
                    }
                } elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation'])) {
                    $col = strtolower($stage) . '_status';
                    $distCol = $col; // same column name exists in quotation_room_distribution
            
                    // Count unit-level rows first (from quotation_room_distribution)
                    $distCheckStmt = $conn->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN qrd.$distCol = 'Done' THEN 1 ELSE 0 END) AS done_cnt,
                       SUM(CASE WHEN qrd.$distCol IN ('Ongoing','Incomplete','Punchlist') THEN 1 ELSE 0 END) AS active_cnt
                FROM quotation_room_distribution qrd
                LEFT JOIN quotation_entries qe ON qrd.quotation_entry_id = qe.id AND qe.client_id = ?
                LEFT JOIN quotation_fixed_sizes qfs ON qrd.quotation_fixed_size_id = qfs.id AND qfs.client_id = ?
                WHERE (qe.client_id = ? OR qfs.client_id = ?)
            ");
                    $distCheckStmt->bind_param("iiii", $client_id, $client_id, $client_id, $client_id);
                    $distCheckStmt->execute();
                    $distRow = $distCheckStmt->get_result()->fetch_assoc();

                    if ($distRow && $distRow['total'] > 0) {
                        // Use unit-level distribution counts
                        if ($distRow['total'] == $distRow['done_cnt']) {
                            $status = 'Done';
                        } elseif ($distRow['active_cnt'] > 0 || $distRow['done_cnt'] > 0) {
                            // Any active OR any done (but not all done) = Ongoing
                            $status = 'Ongoing';
                        } else {
                            $status = 'Pending';
                        }
                    } else {
                        // Fallback: use item-level status columns
                        $iStmt = $conn->prepare("
        SELECT CASE
            WHEN COUNT(*) = 0 THEN 'Pending'
            WHEN COUNT(*) = SUM(CASE WHEN $col = 'Done' THEN 1 ELSE 0 END) THEN 'Done'
            WHEN SUM(CASE WHEN $col IN ('Ongoing','Incomplete','Punchlist','Done') THEN 1 ELSE 0 END) > 0 THEN 'Ongoing'
            ELSE 'Pending'
        END AS s
        FROM (
            SELECT $col FROM quotation_entries WHERE client_id = ?
            UNION ALL
            SELECT $col FROM quotation_fixed_sizes WHERE client_id = ?
        ) x
    ");
                        $iStmt->bind_param("ii", $client_id, $client_id);
                        $iStmt->execute();
                        $status = $iStmt->get_result()->fetch_assoc()['s'] ?? 'Pending';
                    }
                }

                // Count files for this stage
                $fileCount = 0;
                if ($stageData && ($isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)')) {
                    $fcStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM stage_approvals WHERE stage_id = ?");
                    $fcStmt->bind_param("i", $stageData['id']);
                    $fcStmt->execute();
                    $fileCount = $fcStmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                }

                $statusClass = strtolower($status);
                $typeBadge = getStageTypeBadge($stage, $approvalStages, $fileUploadStages, $autoStages);
                $icon = getStageIcon($stage);
                $updated_at = $stageData['updated_at'] ?? null;
                $updatedBy = $stageData['updated_by_name'] ?? null;
                $assigned = $stageData['assigned_people'] ?? [];

                // Who can mark this stage as Done
                $assignedDesigner1 = $clientInfo['designer1_id'] ?? null;
                $assignedDesigner2 = $clientInfo['designer2_id'] ?? null;
                $assignedTechDesign = $clientInfo['technical_designer_id'] ?? null;
                $assignedProjCoord = $clientInfo['project_coordinator_id'] ?? null;

                // Special link targets
                $isHead = (bool) ($userInfo['is_head'] ?? false);
                $stageLink = null;
                if ($stage === 'Site Visit' && $isHead && $admin_role !== 'technical_designer') {
                    $stageLink = BASE_URL . "site-visit-manager?client_id={$client_id}";
                } elseif ($stage === '2D / 3D Layout' && (in_array($admin_role, ['general_manager', 'operational_manager', 'sales']) || ($admin_role === 'designer' && $isHead) || ($admin_role === 'technical_designer' && $isHead))) {
                    $stageLink = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
                } elseif (
                    in_array($stage, ['Fabrication', 'Delivery', 'Installation']) && ($canUpdate || (
                        $admin_role === 'technical_designer' && (
                            $userInfo['is_head'] == 1 ||
                            $admin_id == $assignedTechDesignId
                        )
                    ))
                ) {
                    $isTDViewOnly = !$canUpdate && $admin_role === 'technical_designer';
                    $stageLink = BASE_URL . "item-tracker?client_id={$client_id}&stage=" . urlencode($stage)
                        . ($isTDViewOnly ? '&view_only=1' : '');
                } elseif ($stage === 'BILLING' && ((isset($permissions['BILLING']) && $permissions['BILLING']) || $isAssignedToClient || $isAccountingRole)) {
                    $stageLink = BASE_URL . "payment-tracker?client_id={$client_id}";
                } elseif ($stage === 'Downpayment' && ((isset($permissions['BILLING']) && $permissions['BILLING']) || $isAssignedToClient || $isAccountingRole)) {
                    $stageLink = BASE_URL . "payment-tracker?client_id={$client_id}";
                }

                // Files page link
                $filesPageLink = BASE_URL . "stage-files?client_id={$client_id}&stage_id=" . ($stageData['id'] ?? 0) . "&stage=" . urlencode($stage);
                ?>

                <div class="tl-item">
                    <!-- Node -->
                    <div class="tl-node">
                        <div class="node-icon <?= $statusClass ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                    </div>

                    <!-- Card -->
                    <div class="tl-card <?= $statusClass ?> <?= $isLocked ? 'locked' : '' ?>" <?= ($stage === '2D / 3D Layout' && $layoutPendingCount > 0) ? 'style="border-color:#f59e0b; box-shadow:0 0 0 2px #fcd34d55;"' : '' ?>>

                        <!-- Top row -->
                        <div class="card-top">
                            <div class="card-left">
                                <span class="stage-num"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="stage-name">
                                    <?= htmlspecialchars($stage) ?>
                                </span>
                                <?php if ($stageLink && !$isLocked): ?>
                                    <a href="<?= $stageLink ?>" class="btn-goto">
                                        <i class="fas fa-arrow-right"></i> Open
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                <?php if ($isLocked): ?>
                                    <span class="lock-chip"><i class="fas fa-lock"></i> Locked</span>
                                <?php endif; ?>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?php if ($status === 'Done'): ?><i class="fas fa-check"></i>
                                    <?php elseif ($status === 'Ongoing'): ?><i class="fas fa-circle-notch fa-spin"></i>
                                    <?php else: ?><i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?= $status ?>
                                </span>
                            </div>
                        </div>

                        <!-- Type + file badges -->
                        <?php if ($typeBadge || ($stage === '2D / 3D Layout')): ?>
                            <div class="type-badges">
                                <?php if ($typeBadge): ?>
                                    <span class="type-badge <?= $typeBadge['class'] ?>"><?= $typeBadge['label'] ?></span>
                                <?php endif; ?>
                                <?php if ($stage === '2D / 3D Layout'): ?>
                                    <span class="type-badge" style="background:#f3f4f6; color:#374151; border:1px solid #e5e7eb;">
                                        <i class="fas fa-sync-alt"></i> Rev <?= $current_revision ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($isAuto && !in_array($stage, ['Downpayment'])): ?>
                                    <span class="type-badge badge-auto"><i class="fas fa-bolt"></i> Auto-Tracked</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Meta info -->
                        <div class="card-meta">
                            <?php if ($updated_at): ?>
                                <span><i class="fas fa-clock"></i> <?= date('M d, Y · g:i A', strtotime($updated_at)) ?></span>
                            <?php endif; ?>
                            <?php if ($updatedBy): ?>
                                <span><i class="fas fa-user-edit"></i> <?= htmlspecialchars($updatedBy) ?></span>
                            <?php endif; ?>
                            <?php
                            // Show deadline badge for stages that have one set
                            $deadlineStagesForBadge = $isNonProject
                                ? ['2D / 3D Layout', 'Cuttinglist']
                                : ['Samples Submitted TDS/SDS', '2D / 3D Layout', 'Cuttinglist'];
                            if (in_array($stage, $deadlineStagesForBadge)):
                                $dlStmt = $conn->prepare("SELECT start_date, end_date, duration FROM stage_deadlines WHERE client_id = ? AND stage_name = ?");
                                $dlStmt->bind_param("is", $client_id, $stage);
                                $dlStmt->execute();
                                $dlRow = $dlStmt->get_result()->fetch_assoc();
                                if ($dlRow && ($dlRow['start_date'] || $dlRow['end_date'])):
                                    $now = new DateTime();
                                    $endDt = $dlRow['end_date'] ? new DateTime($dlRow['end_date']) : null;
                                    $isOverdue = $endDt && $now > $endDt && $status !== 'Done';
                                    $dlColor = $isOverdue ? '#dc2626' : '#0369a1';
                                    $dlBg = $isOverdue ? '#fee2e2' : '#eff6ff';
                                    $dlBorder = $isOverdue ? '#fca5a5' : '#bfdbfe';
                                    ?>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;background:<?= $dlBg ?>;border:1px solid <?= $dlBorder ?>;color:<?= $dlColor ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">
                                        <i class="fas fa-<?= $isOverdue ? 'exclamation-circle' : 'calendar-alt' ?>"></i>
                                        <?php if ($dlRow['start_date']): ?>
                                            <?= date('M d', strtotime($dlRow['start_date'])) ?> →
                                        <?php endif; ?>
                                        <?php if ($dlRow['end_date']): ?>
                                            <?= date('M d, Y', strtotime($dlRow['end_date'])) ?>
                                        <?php endif; ?>
                                        <?php if ($isOverdue): ?>(Overdue)<?php endif; ?>
                                    </span>
                                <?php endif; endif; ?>
                        </div>

                        <?php if ($stage === 'Site Visit' && $admin_role === 'designer' && $isHead): ?>
                            <?php
                            // Check for any rejected site visits for this client
                            $rejectedVisitStmt = $conn->prepare("
        SELECT sv.id, sv.visit_date, a.full_name as rejected_by_name, sv.approval_comment
        FROM site_visit sv
        LEFT JOIN account a ON sv.approved_by = a.id
        WHERE sv.client_id = ? AND sv.approval_status = 'Rejected'
        ORDER BY sv.visit_date DESC
        LIMIT 1
    ");
                            $rejectedVisitStmt->bind_param("i", $client_id);
                            $rejectedVisitStmt->execute();
                            $rejectedVisit = $rejectedVisitStmt->get_result()->fetch_assoc();
                            ?>
                            <?php if ($rejectedVisit): ?>
                                <div
                                    style="background:#fee2e2; border:2px solid #ef4444; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-times-circle" style="color:#dc2626; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#991b1b;">
                                                Site visit rejected by
                                                <?= htmlspecialchars($rejectedVisit['rejected_by_name'] ?? 'Manager') ?>
                                            </div>
                                            <?php if ($rejectedVisit['approval_comment']): ?>
                                                <div style="font-size:11px; color:#b91c1c; margin-top:3px; font-style:italic;">
                                                    "<?= htmlspecialchars($rejectedVisit['approval_comment']) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:11px; color:#b91c1c; margin-top:3px;">
                                                Open Site Visit Manager to edit and resubmit.
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (isset($stageLink) && $stageLink): ?>
                                        <a href="<?= $stageLink ?>"
                                            style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                            <i class="fas fa-arrow-right"></i> Fix & Resubmit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($stage === '2D / 3D Layout' && $layoutPendingCount > 0): ?>
                            <div
                                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="display:flex; align-items:center; gap:9px;">
                                    <i class="fas fa-bell" style="color:#d97706; font-size:16px; flex-shrink:0;"></i>
                                    <div>
                                        <div style="font-weight:700; font-size:13px; color:#92400e;">
                                            <?= $layoutPendingCount ?> pending approval<?= $layoutPendingCount > 1 ? 's' : '' ?>
                                            waiting for your review
                                        </div>
                                        <div style="font-size:11px; color:#b45309; margin-top:2px;">
                                            Go to the 2D/3D layout page to approve or reject.
                                        </div>
                                    </div>
                                </div>
                                <a href="designer-2d3d-layout?client_id=<?= $client_id ?>"
                                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                    <i class="fas fa-arrow-right"></i> Go to 2D/3D Layout
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php
                        $isHead = (bool) ($userInfo['is_head'] ?? false);
                        $approvalStagesForNotif = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
                        if (in_array($stage, $approvalStagesForNotif) && $stageData):
                            $stagePendingCount = getStagePendingApprovalCount($conn, $admin_id, $admin_role, $isHead, $stageData['id']);
                            if ($stagePendingCount > 0):
                                ?>
                                <div
                                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-bell" style="color:#d97706; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#92400e;">
                                                <?= $stagePendingCount ?> file<?= $stagePendingCount > 1 ? 's' : '' ?> waiting for
                                                your approval
                                            </div>
                                            <div style="font-size:11px; color:#b45309; margin-top:2px;">
                                                Open the files page to review and approve or reject.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesPageLink ?>"
                                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i> Review Files
                                    </a>
                                </div>
                            <?php endif; endif; ?>

                        <!-- Internal P.O to Accounting: stage-level approval notification -->
                        <?php if ($stage === 'Internal P.O to Accounting' && $stageData):
                            $ipoNotifStmt = $conn->prepare("SELECT * FROM internal_po_approvals WHERE stage_id = ? ORDER BY id DESC LIMIT 1");
                            $ipoNotifStmt->bind_param("i", $stageData['id']);
                            $ipoNotifStmt->execute();
                            $ipoNotif = $ipoNotifStmt->get_result()->fetch_assoc();

                            $showIpoNotif = false;
                            $ipoNotifMsg = '';
                            if ($ipoNotif) {
                                if ($admin_role === 'accounting' && $ipoNotif['accounting_status'] === 'pending' && $ipoNotif['overall_status'] === 'pending') {
                                    $showIpoNotif = true;
                                    $ipoNotifMsg = 'All files are ready for your review — please approve or add a remark.';
                                } elseif ($admin_role === 'designer' && !empty($userInfo['is_head']) && $ipoNotif['designer_status'] === 'pending' && $ipoNotif['accounting_status'] === 'approved' && $ipoNotif['overall_status'] === 'pending') {
                                    $showIpoNotif = true;
                                    $ipoNotifMsg = 'Accounting has approved. Please review and approve or add a remark.';
                                }
                            }
                            if ($showIpoNotif): ?>
                                <div
                                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-bell" style="color:#d97706; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#92400e;">Internal P.O needs your
                                                review</div>
                                            <div style="font-size:11px; color:#b45309; margin-top:2px;"><?= $ipoNotifMsg ?></div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesPageLink ?>"
                                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i> Review
                                    </a>
                                </div>
                            <?php endif; endif; ?>

                        <!-- Approved PO not yet ordered notification -->
                        <?php
                        if ($stage === 'Purchase Order (Submit to accounting)' && $stageData):
                            $approvedPoNotOrderedStmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        JOIN project_tracker pt ON sa.stage_id = pt.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
        WHERE pt.client_id = ?
          AND pt.stage_name = 'Purchase Order (Submit to accounting)'
          AND sa.approval_status = 'approved'
          AND (bos.status IS NULL OR bos.status IN ('pending', 'partially_ordered'))
    ");
                            $approvedPoNotOrderedStmt->bind_param("i", $client_id);
                            $approvedPoNotOrderedStmt->execute();
                            $approvedPoNotOrderedCount = (int) $approvedPoNotOrderedStmt->get_result()->fetch_row()[0];
                            if ($approvedPoNotOrderedCount > 0 && ($admin_role === 'project_coordinator' && $admin_id == $assignedProjCoordId)):
                                ?>
                                <div
                                    style="background:#eff6ff; border:2px solid #3b82f6; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-shopping-cart" style="color:#2563eb; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#1e40af;">
                                                <?= $approvedPoNotOrderedCount ?> approved
                                                PO<?= $approvedPoNotOrderedCount > 1 ? 's are' : ' is' ?> not yet fully ordered
                                            </div>
                                            <div style="font-size:11px; color:#3b82f6; margin-top:2px;">
                                                Open the Purchase Order files page to update the order status.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesPageLink ?>"
                                        style="background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i> Update Order Status
                                    </a>
                                </div>
                            <?php endif; endif; ?>
                        <!-- End Approved PO not yet ordered notification -->

                        <!-- PO Missing notification — show when BOM is ordered but no PO submitted yet -->
                        <?php
                        if ($stage === 'Purchase Order (Submit to accounting)' && $stageData):
                            // Find approved BOMs for this client that have no linked PO submitted yet
                            $missingPoStmt = $conn->prepare("
        SELECT sa.id, sa.label, sa.file_name,
               COALESCE(bos.status, 'pending') as order_status
        FROM stage_approvals sa
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.id
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
        AND sa.approval_status = 'approved'
        AND NOT EXISTS (
            SELECT 1 FROM stage_approvals po
            WHERE po.stage_id = ? AND po.linked_bom_id = sa.id
        )
    ");
                            $missingPoStmt->bind_param("ii", $client_id, $stageData['id']);
                            $missingPoStmt->execute();
                            $missingPoResult = $missingPoStmt->get_result();
                            $missingPoBoms = [];
                            while ($mbRow = $missingPoResult->fetch_assoc()) {
                                $missingPoBoms[] = $mbRow;
                            }
                            $missingPoCount = count($missingPoBoms);
                            if ($missingPoCount > 0 && ($canUpdate || $isAssignedToClient)):
                                ?>
                                <div
                                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-bell" style="color:#d97706; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#92400e;">
                                                <?= $missingPoCount ?> approved BOM<?= $missingPoCount > 1 ? 's have' : ' has' ?> no
                                                Purchase Order submitted yet
                                            </div>
                                            <div style="font-size:11px; color:#b45309; margin-top:2px;">
                                                Open the Purchase Order page to submit a PO for
                                                <?= $missingPoCount > 1 ? 'each BOM' : 'this BOM' ?>.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesPageLink ?>"
                                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i> Submit PO
                                    </a>
                                </div>
                            <?php endif; endif; ?>
                        <!-- End PO Missing notification -->

                        <!-- Rejection notification for uploader -->
                        <?php
                        $isMyUpload = false;
                        if ($stageData) {
                            if ($stage === 'Internal P.O to Accounting') {
                                // Para sa Internal PO, check ang internal_po_approvals table
                                $myUploadCheckStmt = $conn->prepare("
                                    SELECT COUNT(*) FROM internal_po_approvals ipa
                                    JOIN stage_approvals sa ON sa.stage_id = ipa.stage_id
                                    WHERE ipa.stage_id = ? 
                                    AND sa.uploaded_by = ?
                                    AND ipa.overall_status = 'rejected'
                                ");
                                $myUploadCheckStmt->bind_param("ii", $stageData['id'], $admin_id);
                            } else {
                                $myUploadCheckStmt = $conn->prepare("SELECT COUNT(*) FROM stage_approvals WHERE stage_id = ? AND uploaded_by = ? AND approval_status = 'rejected'");
                                $myUploadCheckStmt->bind_param("ii", $stageData['id'], $admin_id);
                            }
                            $myUploadCheckStmt->execute();
                            $isMyUpload = (int)$myUploadCheckStmt->get_result()->fetch_row()[0] > 0;
                        }
                        if (($isApproval || $stage === 'Internal P.O to Accounting') && $stageData && ($canUpdate || $isMyUpload)):
                            if ($stage === 'Internal P.O to Accounting') {
                                $rejectedFileStmt = $conn->prepare("
                                    SELECT sa.id, sa.label, sa.file_name, 
                                        COALESCE(ipa.accounting_remark, ipa.designer_remark) as review_note,
                                        COALESCE(ac.full_name, dc.full_name) as reviewer_name,
                                        CASE WHEN ipa.accounting_status = 'rejected' THEN 'accounting' ELSE 'designer' END as reviewer_role
                                    FROM stage_approvals sa
                                    JOIN internal_po_approvals ipa ON ipa.stage_id = sa.stage_id
                                    LEFT JOIN account ac ON ipa.accounting_reviewed_by = ac.id
                                    LEFT JOIN account dc ON ipa.designer_reviewed_by = dc.id
                                    WHERE sa.stage_id = ?
                                    AND sa.uploaded_by = ?
                                    AND ipa.overall_status = 'rejected'
                                    ORDER BY ipa.id DESC
                                    LIMIT 1
                                ");
                            } else {
                                $rejectedFileStmt = $conn->prepare("
                                    SELECT sa.id, sa.label, sa.file_name, sar.review_note, a.full_name as reviewer_name, sar.reviewer_role
                                    FROM stage_approvals sa
                                    JOIN stage_approval_reviews sar ON sar.approval_id = sa.id
                                    LEFT JOIN account a ON sar.reviewed_by = a.id
                                    WHERE sa.stage_id = ?
                                      AND sa.uploaded_by = ?
                                      AND sa.approval_status = 'rejected'
                                      AND sar.review_status = 'rejected'
                                    ORDER BY sar.reviewed_at DESC
                                    LIMIT 1
                                ");
                            }
                            $rejectedFileStmt->bind_param("ii", $stageData['id'], $admin_id);
                            $rejectedFileStmt->execute();
                            $rejectedFile = $rejectedFileStmt->get_result()->fetch_assoc();
                            ?>
                            <?php if (!empty($rejectedFile)): ?>
                                <div
                                    style="background:#fee2e2; border:2px solid #ef4444; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-times-circle" style="color:#dc2626; font-size:16px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#991b1b;">
                                                Your file
                                                "<?= htmlspecialchars($rejectedFile['label'] ?: $rejectedFile['file_name']) ?>" was
                                                rejected
                                                <?php if ($rejectedFile['reviewer_name']): ?>
                                                    by <?= htmlspecialchars($rejectedFile['reviewer_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($rejectedFile['review_note']): ?>
                                                <div style="font-size:11px; color:#b91c1c; margin-top:3px; font-style:italic;">
                                                    "<?= htmlspecialchars($rejectedFile['review_note']) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:11px; color:#b91c1c; margin-top:3px;">
                                                Open the files page to re-submit.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesPageLink ?>"
                                        style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-redo"></i> Re-submit File
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <!-- End rejection notification -->

                        <!-- Assigned people -->
                        <?php if (!empty($assigned)): ?>
                            <div class="assigned-row">
                                <?php foreach ($assigned as $person): ?>
                                    <span class="assigned-chip"><i class="fas fa-user" style="font-size:9px;"></i>
                                        <?= htmlspecialchars($person) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <?php
                        // In sequential mode, hide all action buttons unless this stage is Ongoing or Done
// Always-unlocked stages are accessible even in sequential mode
                        $alwaysUnlockedStages = ['Rough Estimation', 'Site Visit', '2D / 3D Layout', 'Reference', 'Samples Submitted TDS/SDS', 'Quotation'];
                        if ($isNonProject) {
                            $alwaysUnlockedStages = array_values(array_filter($alwaysUnlockedStages, function ($s) {
                                return $s !== 'Samples Submitted TDS/SDS';
                            }));
                        }
                        $isAlwaysUnlocked = in_array($stage, $alwaysUnlockedStages);

                        $sequentialActive = ($tracker_mode !== 'sequential') || ($status === 'Ongoing' || $status === 'Done') || !$isLocked || $isAlwaysUnlocked;
                        $isReferenceAssigned = ($stage === 'Reference') && (
                            $admin_id == $assignedDesigner1Id ||
                            $admin_id == $assignedDesigner2Id ||
                            $admin_id == ($ptAssignedRow['accountaid_fk'] ?? null)
                        );
                        $hasActions = (($isFileUpload && $canUpdate) || $isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)' || $isReferenceAssigned || ($stage === 'Production Data Submittals' && (
                            $canUpdate ||
                            ($admin_role === 'technical_designer' && (
                                $userInfo['is_head'] == 1 ||
                                $admin_id == $assignedTechDesignId
                            ))
                        ))) && $sequentialActive;
                        ?>
                        <?php if ($hasActions): ?>
                            <div class="card-actions">

                                <?php
                                // Determine who can mark this specific stage as Done
                                $canMarkDone = false;
                                $canCancelDone = false;
                                if ($stage === 'Production Data Submittals' && !$isLocked) {
                                    $canActOnPDS = (
                                        $canUpdate ||
                                        ($admin_role === 'technical_designer' && (
                                            $userInfo['is_head'] == 1 ||
                                            $admin_id == $assignedTechDesignId
                                        ))
                                    );
                                    // No action for GM/OM — they view only
                                } elseif ($isFileUpload && $canUpdate && !$isLocked) {
                                    if ($stage === 'Reference') {
                                        $isReferenceUser = (
                                            $admin_id == $assignedDesigner1Id ||
                                            $admin_id == $assignedDesigner2Id ||
                                            $admin_id == ($ptAssignedRow['accountaid_fk'] ?? null)
                                        );
                                        // Can mark done from Pending OR Ongoing (no file requirement)
                                        if ($isReferenceUser && in_array($status, ['Pending', 'Ongoing'])) {
                                            $canMarkDone = true;
                                        }
                                        // Can cancel (revert to Ongoing) when Done
                                        if ($isReferenceUser && $status === 'Done') {
                                            $canCancelDone = true;
                                        }
                                    } elseif ($stage === 'Internal P.O to Accounting') {
                                        // Only show Mark as Done if internal_po_approval is fully approved
                                        $ipoCheckStmt = $conn->prepare("SELECT id FROM internal_po_approvals WHERE stage_id = ? AND overall_status = 'approved' LIMIT 1");
                                        $ipoCheckStmt->bind_param("i", $stageData['id']);
                                        $ipoCheckStmt->execute();
                                        $ipoApprovedRow = $ipoCheckStmt->get_result()->fetch_assoc();
                                        $canMarkDone = !empty($ipoApprovedRow) && ($admin_id == $assignedProjCoordId || $admin_role === 'sales') && $status === 'Ongoing';
                                    } elseif ($stage === 'Handover') {
                                        $canMarkDone = ($admin_id == $assignedTechDesignId || $admin_id == $assignedProjCoordId) && $status === 'Ongoing';
                                    }
                                }
                                ?>
                                <?php if ($stage === 'Production Data Submittals' && $canActOnPDS): ?>
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <span
                                            style="font-size:11px; font-weight:700; color:var(--text-lt); text-transform:uppercase; letter-spacing:.5px;">Set
                                            Status:</span>
                                        <button class="btn <?= $status === 'Pending' ? 'active-status' : '' ?>"
                                            style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; <?= $status === 'Pending' ? 'box-shadow:0 0 0 2px #f59e0b;' : '' ?>"
                                            onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Pending')" <?= $status === 'Pending' ? 'disabled' : '' ?>>
                                            <i class="fas fa-clock"></i> Pending
                                        </button>
                                        <button class="btn <?= $status === 'Ongoing' ? 'active-status' : '' ?>"
                                            style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; <?= $status === 'Ongoing' ? 'box-shadow:0 0 0 2px #3b82f6;' : '' ?>"
                                            onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Ongoing')" <?= $status === 'Ongoing' ? 'disabled' : '' ?>>
                                            <i class="fas fa-circle-notch"></i> Ongoing
                                        </button>
                                        <button class="btn <?= $status === 'Done' ? 'active-status' : '' ?>"
                                            style="background:#f0fdf4; color:#065f46; border:1px solid #6ee7b7; <?= $status === 'Done' ? 'box-shadow:0 0 0 2px #10b981;' : '' ?>"
                                            onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Done')" <?= $status === 'Done' ? 'disabled' : '' ?>>
                                            <i class="fas fa-check-circle"></i> Done
                                        </button>
                                    </div>
                                <?php elseif ($canMarkDone): ?>
                                    <button class="btn btn-mark-done" onclick="markDone(<?= $stageData['id'] ?>)">
                                        <i class="fas fa-check-circle"></i> Mark as Done
                                    </button>
                                <?php endif; ?>
                                <?php if (!($stage === 'Production Data Submittals') && $canCancelDone): ?>
                                    <button class="btn" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;"
                                        onclick="cancelDone(<?= $stageData['id'] ?>)">
                                        <i class="fas fa-undo"></i> Cancel (Revert to Ongoing)
                                    </button>
                                <?php endif; ?>

                                <!-- Files button — only show if stage is active (Ongoing or Done) in sequential mode -->
                                <?php if (($isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)') && $stage !== 'Production Data Submittals' && $stageData && $sequentialActive): ?>
                                    <a href="<?= $filesPageLink ?>" class="file-chip" style="margin-left:auto;">
                                        <?php
                                        $dotColor = $fileCount > 0 ? 'var(--done)' : 'var(--border)';
                                        ?>
                                        <span class="dot" style="background:<?= $dotColor ?>;"></span>
                                        <i class="fas fa-paperclip"></i>
                                        <?= $fileCount ?> file<?= $fileCount !== 1 ? 's' : '' ?>
                                        <i class="fas fa-chevron-right" style="font-size:9px; opacity:.5;"></i>
                                    </a>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endforeach;

            // Recalculate progress counts using synced statuses from DB
            $pending_count = $ongoing_count = $done_count = 0;
            foreach ($trackerData as $data) {
                if ($data['status'] === 'Pending')
                    $pending_count++;
                elseif ($data['status'] === 'Ongoing')
                    $ongoing_count++;
                elseif ($data['status'] === 'Done')
                    $done_count++;
            }
            $completion_percentage = ($done_count / $total_stages) * 100;
            ?>
        </div><!-- /timeline -->

    </div><!-- /page -->

    <!-- Client Detail Modal -->
    <?php
    $house_state = $client['house_state'] ?? '';
    $permit_required = $client['permit_required'] ?? '';
    $target_movein_date = $client['target_movein_date'] ?? '';
    ?>
    <div id="clientDetailModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-user-circle" style="color:#8a5a44;"></i> Client Details
                </div>
                <button class="modal-close"
                    onclick="document.getElementById('clientDetailModal').classList.remove('open')">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-row">
                <div class="modal-row-label">Reference Number</div>
                <div class="modal-row-value" style="color:#3b82f6; font-family:monospace; font-weight:600;">
                    <?= htmlspecialchars($client['reference_number'] ?? '') ?>
                </div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Client Name</div>
                <div class="modal-row-value"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Project Name</div>
                <div class="modal-row-value"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Status</div>
                <div class="modal-row-value">
                    <?php $st = $client['status'] ?? ''; ?>
                    <span style="padding:3px 12px; border-radius:12px; font-size:11px; font-weight:700;
                    background:<?= strtolower($st) === 'new client' ? '#fef3c7' : '#dbeafe' ?>;
                    color:<?= strtolower($st) === 'new client' ? '#92400e' : '#1e40af' ?>;">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Business Type</div>
                <div class="modal-row-value"><?= htmlspecialchars($business_type_label) ?></div>
            </div>
            <?php if (!empty($client['contact'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Phone</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['contact']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['email'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Email</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['email']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['address'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Address</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['address']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['gender'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Gender</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['gender']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['client_class'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Classification</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['client_class']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['client_type'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Client Type</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['client_type']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['project_scope'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Project Scope</div>
                    <div class="modal-row-value"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['scope_of_work'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Scope of Work</div>
                    <div class="modal-row-value"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($house_state): ?>
                <div class="modal-row">
                    <div class="modal-row-label">House State</div>
                    <div class="modal-row-value">
                        <?php
                        $hsBg = '#fef3c7';
                        $hsColor = '#92400e';
                        if ($house_state === 'Bare/Empty Lot') {
                            $hsBg = '#dbeafe';
                            $hsColor = '#1e40af';
                        } elseif ($house_state === 'Construction Started') {
                            $hsBg = '#fee2e2';
                            $hsColor = '#991b1b';
                        } elseif ($house_state === 'Renovation') {
                            $hsBg = '#ede9fe';
                            $hsColor = '#5b21b6';
                        }
                        ?>
                        <span style="padding:3px 12px; border-radius:12px; font-size:12px; font-weight:700;
                             background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
                            <?= htmlspecialchars($house_state) ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($permit_required): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Permit Required</div>
                    <div class="modal-row-value">
                        <?php
                        $prBg = '#fef3c7';
                        $prColor = '#92400e';
                        if ($permit_required === 'Yes') {
                            $prBg = '#fee2e2';
                            $prColor = '#991b1b';
                        } elseif ($permit_required === 'No') {
                            $prBg = '#d1fae5';
                            $prColor = '#065f46';
                        }
                        ?>
                        <span style="padding:3px 12px; border-radius:12px; font-size:12px; font-weight:700;
                             background:<?= $prBg ?>; color:<?= $prColor ?>;">
                            <?= htmlspecialchars($permit_required) ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($target_movein_date): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Target Move-in</div>
                    <div class="modal-row-value" style="font-weight:600;">
                        <i class="fas fa-calendar-check" style="color:#10b981;"></i>
                        <?= date('F d, Y', strtotime($target_movein_date)) ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="modal-row">
                <div class="modal-row-label">Total Project Cost</div>
                <div class="modal-row-value" style="font-weight:700; color:#3b1f0f; font-size:15px;">
                    ₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?>
                </div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Remaining Balance</div>
                <div class="modal-row-value" style="font-weight:700; color:#dc2626; font-size:15px;">
                    ₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMsg">Updated!</span>
    </div>

    <script>
        async function setPDSStatus(stageId, status) {
            const labels = { Pending: 'Pending', Ongoing: 'Ongoing', Done: 'Done' };
            if (!confirm('Set Production Data Submittals to ' + labels[status] + '?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: status })
                });
                const data = await res.json();
                if (data.success) { toast('Status set to ' + status + '!'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function markDone(stageId) {
            if (!confirm('Mark this stage as Done?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: 'Done' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage marked as Done!'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function cancelDone(stageId) {
            if (!confirm('Revert this stage back to Ongoing?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: 'Ongoing' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage reverted to Ongoing.'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        function toast(msg, err = false) {
            const el = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            el.className = 'toast show' + (err ? ' error' : '');
            setTimeout(() => el.classList.remove('show'), 3000);
        }
    </script>
</body>

</html>