<?php
// all_clients_tracker_list.php
include $includes ['mainbody'];

// Exclude sales from this page
$allowedRoles = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];

$admin_id = $_SESSION['admin_id'];

// Check user's role
$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();

if (!in_array($userInfo['role'], $allowedRoles)) {
    die("Access Denied: This page is only for General Manager, Operational Manager, Designer, Technical Designer, and Accounting roles.");
}

// Get current user's role and is_head
$currentRole = $userInfo['role'];
$isHeadUser = false;
if (in_array($currentRole, ['designer', 'technical_designer'])) {
    $headCheckStmt = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheckStmt->bind_param("i", $admin_id);
    $headCheckStmt->execute();
    $headCheckRow = $headCheckStmt->get_result()->fetch_assoc();
    $isHeadUser = (bool) ($headCheckRow['is_head'] ?? false);
}

// Roles that need assignment filtering
$needsAssignmentFilter = (
    $currentRole === 'project_coordinator' ||
    ($currentRole === 'designer' && !$isHeadUser) ||
    ($currentRole === 'technical_designer' && !$isHeadUser)
);

if ($needsAssignmentFilter) {
    // Only show clients assigned to this user
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.clientname,
            u.nameproject,
            u.reference_number,
            u.status,
            u.business_type,
            u.contact,
            u.email,
            u.address,
            u.created_at,
            u.account_status,
            a.full_name as admin_name,
            a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.account_status != 'Finished'
          AND (
            u.designer1_id = ?
            OR u.designer2_id = ?
            OR u.technical_designer_id = ?
            OR u.project_coordinator_id = ?
          )
        ORDER BY u.created_at DESC
    ");
    $stmt->bind_param("iiii", $admin_id, $admin_id, $admin_id, $admin_id);
} else {
    // Fetch ALL clients (general_manager, operational_manager, accounting, head designer, head technical_designer, etc.)
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.clientname,
            u.nameproject,
            u.reference_number,
            u.status,
            u.business_type,
            u.contact,
            u.email,
            u.address,
            u.created_at,
            u.account_status,
            a.full_name as admin_name,
            a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.account_status != 'Finished'
        ORDER BY u.created_at DESC
    ");
}

function getClientRejectedSiteVisits($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM site_visit 
        WHERE client_id = ? AND approval_status = 'Rejected'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientPendingPaymentProofs($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND par.review_status = 'pending'
          AND ps.accounting_status = 'pending_review'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientMissingPoCount($conn, $client_id)
{
    // Count approved BOMs that have no linked PO submitted yet
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
        AND sa.approval_status = 'approved'
        AND NOT EXISTS (
            SELECT 1 FROM stage_approvals po
            WHERE po.client_id = ?
              AND po.stage_id = (
                  SELECT id FROM project_tracker 
                  WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1
              )
              AND po.linked_bom_id = sa.id
        )
    ");
    $stmt->bind_param("iii", $client_id, $client_id, $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientApprovedPoNotOrderedCount($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        JOIN project_tracker pt ON sa.stage_id = pt.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
        WHERE pt.client_id = ?
          AND pt.stage_name = 'Purchase Order (Submit to accounting)'
          AND sa.approval_status = 'approved'
          AND (bos.status IS NULL OR bos.status IN ('pending', 'partially_ordered'))
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientPendingInternalPO($conn, $admin_id, $admin_role, $is_head, $client_id)
{
    if (!in_array($admin_role, ['accounting', 'designer'])) return 0;
    if ($admin_role === 'designer' && !$is_head) return 0;

    if ($admin_role === 'accounting') {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM internal_po_approvals ipa
            JOIN project_tracker pt ON ipa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND ipa.overall_status = 'pending'
              AND ipa.accounting_status = 'pending'
        ");
        $stmt->bind_param("i", $client_id);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM internal_po_approvals ipa
            JOIN project_tracker pt ON ipa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND ipa.overall_status = 'pending'
              AND ipa.accounting_status = 'approved'
              AND ipa.designer_status = 'pending'
        ");
        $stmt->bind_param("i", $client_id);
    }

    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientRejectedFilesForUploader($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        INNER JOIN project_tracker pt ON sa.stage_id = pt.id
        WHERE pt.client_id = ?
          AND sa.uploaded_by = ?
          AND sa.approval_status = 'rejected'
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientPendingApprovalsForUser($conn, $admin_id, $admin_role, $is_head, $client_id)
{
    $approvalStageRoles = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];

    $total = 0;
    foreach ($approvalStageRoles as $stageName => $rolesAllowed) {
        // Check if this user can approve this stage
        $canApprove = false;
        if ($admin_role === 'technical_designer') {
            if (in_array('technical_designer', $rolesAllowed) && $is_head)
                $canApprove = true;
        } elseif ($admin_role === 'designer') {
            if (in_array($stageName, ['Quotation', 'Rough Estimation', 'Samples Submitted TDS/SDS']) && $is_head)
                $canApprove = true;
        } else {
            if (in_array($admin_role, $rolesAllowed))
                $canApprove = true;
        }
        if (!$canApprove)
            continue;

        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM stage_approvals sa
            INNER JOIN project_tracker pt ON sa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND pt.stage_name = ?
              AND sa.approval_status = 'pending'
              AND NOT EXISTS (
                  SELECT 1 FROM stage_approval_reviews sar
                  WHERE sar.approval_id = sa.id
                    AND sar.reviewer_role = ?
              )
        ");
        $stmt->bind_param("iss", $client_id, $stageName, $admin_role);
        $stmt->execute();
        $total += (int) $stmt->get_result()->fetch_row()[0];
    }

    // Also count pending 2D/3D layout approvals
    $layoutStmt = $conn->prepare("
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
    $layoutStmt->bind_param("ii", $client_id, $admin_id);
    $layoutStmt->execute();
    $total += (int) $layoutStmt->get_result()->fetch_row()[0];

    return $total;
}

$stmt->execute();
$result = $stmt->get_result();

// Fetch finished clients separately
if ($needsAssignmentFilter) {
    $finishedStmt = $conn->prepare("
        SELECT u.id, u.clientname, u.nameproject, u.reference_number, u.status,
               u.business_type, u.contact, u.created_at, u.account_status,
               a.full_name as admin_name, a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.account_status = 'Finished'
          AND (u.designer1_id = ? OR u.designer2_id = ? OR u.technical_designer_id = ? OR u.project_coordinator_id = ?)
        ORDER BY u.created_at DESC
    ");
    $finishedStmt->bind_param("iiii", $admin_id, $admin_id, $admin_id, $admin_id);
} else {
    $finishedStmt = $conn->prepare("
        SELECT u.id, u.clientname, u.nameproject, u.reference_number, u.status,
               u.business_type, u.contact, u.created_at, u.account_status,
               a.full_name as admin_name, a.role as admin_role
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id
        WHERE u.account_status = 'Finished'
        ORDER BY u.created_at DESC
    ");
}
$finishedStmt->execute();
$finishedResult = $finishedStmt->get_result();
$finishedClients = [];
while ($row = $finishedResult->fetch_assoc()) {
    $finishedClients[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Clients - Project Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --adm-bg: #F5F5F5;
            --adm-surface: #FFFFFF;
            --adm-ink: #0B0B0B;
            --adm-soft: #6B6B6B;
            --adm-muted: #9A9A9A;
            --adm-line: #E2E2E2;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--adm-bg);
            font-family: 'Inter', sans-serif;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: var(--adm-ink);
            padding: 40px;
            border-radius: 16px;
            color: white;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .page-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 16px;
            position: relative;
            z-index: 1;
        }

        .user-info-badge {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .stat-icon {
                width: 38px;
                height: 38px;
                font-size: 18px;
                margin-bottom: 10px;
            }

            .stat-card .stat-value {
                font-size: 22px;
            }

            .stat-card .stat-label {
                font-size: 11px;
            }
        }

        .stat-card {
            background: var(--adm-surface);
            padding: 25px;
            border-radius: 12px;
            border: 1px solid var(--adm-line);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
        }

        .stat-card .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--adm-ink);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--adm-ink);
        }

        .filters-section {
            background: var(--adm-surface);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid var(--adm-line);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filters-grid .filter-group:first-child {
                grid-column: 1 / -1;
            }
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--adm-line);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--adm-ink);
        }

        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .client-card {
            background: var(--adm-surface);
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--adm-line);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .client-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.3);
            border-color: var(--adm-ink);
        }

        .client-card-header {
            background: var(--adm-ink);
            padding: 20px;
            color: white;
        }

        .client-card-header h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .client-card-header .reference {
            font-size: 12px;
            opacity: 0.9;
            font-family: monospace;
        }

        .client-card-body {
            padding: 20px;
        }

        .client-info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .client-info-row i {
            color: var(--adm-soft);
            width: 20px;
        }

        .client-info-row .label {
            color: #666;
            min-width: 100px;
        }

        .client-info-row .value {
            color: #111;
            font-weight: 500;
            flex: 1;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-new {
            background: #fef3c7;
            color: #92400e;
        }

        .status-old {
            background: #dbeafe;
            color: #1e40af;
        }

        .assigned-to-badge {
            background: #f0fdf4;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .client-card-footer {
            padding: 15px 20px;
            background: #f9f9f9;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .view-tracker-btn {
            background: var(--adm-ink);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .view-tracker-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--adm-surface);
            border-radius: 12px;
            border: 1px solid var(--adm-line);
        }

        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #666;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #999;
        }

        .toggle-btn {
            background: var(--adm-surface);
            border: 2px solid var(--adm-line);
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
            color: var(--adm-soft);
            font-size: 16px;
            transition: all 0.2s;
        }

        .toggle-btn.active {
            background: var(--adm-ink);
            border-color: var(--adm-ink);
            color: white;
        }

        /* List view overrides */
        .clients-grid.list-view {
            grid-template-columns: 1fr !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .clients-grid.list-view .client-card {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch;
            width: 100%;
            min-height: unset;
        }

        @media (max-width: 600px) {
            .clients-grid.list-view .client-card {
                flex-direction: column !important;
            }

            .clients-grid.list-view .client-card-header {
                min-width: unset !important;
                max-width: unset !important;
            }

            .clients-grid.list-view .client-card-footer {
                flex-direction: row !important;
                justify-content: space-between !important;
                min-width: unset !important;
                max-width: unset !important;
            }
        }

        .clients-grid.list-view .client-card-header {
            min-width: 260px;
            max-width: 260px;
            border-radius: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex-shrink: 0;
        }

        .clients-grid.list-view .client-card-body {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap;
            align-items: center;
            padding: 10px 20px;
            flex: 1;
            gap: 5px 20px;
        }

        .clients-grid.list-view .client-card-body .client-info-row {
            flex: 1 1 200px;
            margin-bottom: 0;
        }

        .clients-grid.list-view .client-card-footer {
            flex-direction: column !important;
            justify-content: center;
            align-items: center;
            gap: 10px;
            min-width: 140px;
            max-width: 140px;
            flex-shrink: 0;
        }

        .role-badge {
            background: var(--adm-bg);
            color: var(--adm-soft);
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-building"></i> All Clients Overview</h1>
            <p>View and track all client projects across the organization</p>
            <div class="user-info-badge">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars($userInfo['full_name']) ?></span>
                <span style="opacity: 0.7;">•</span>
                <span style="text-transform: capitalize;"><?= str_replace('_', ' ', $userInfo['role']) ?></span>
            </div>
        </div>

        <?php
        // Calculate statistics
        $total_clients = $result->num_rows;
        $new_clients = 0;
        $active_projects = 0;
        $assigned_managers = [];

        $isHeadForApprovals = false;
        if (in_array($currentRole, ['designer', 'technical_designer'])) {
            $hStmt = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
            $hStmt->bind_param("i", $admin_id);
            $hStmt->execute();
            $isHeadForApprovals = (bool) ($hStmt->get_result()->fetch_assoc()['is_head'] ?? false);
        } else {
            $isHeadForApprovals = true; // gm, om, accounting etc. always can approve
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $row['pending_approvals'] = getClientPendingApprovalsForUser($conn, $admin_id, $currentRole, $isHeadForApprovals, $row['id']);
            $row['rejected_site_visits'] = ($currentRole === 'designer' && $isHeadUser)
                ? getClientRejectedSiteVisits($conn, $row['id'])
                : 0;
            $row['rejected_uploads'] = getClientRejectedFilesForUploader($conn, $admin_id, $row['id']);
            $row['pending_payment_proofs'] = in_array($currentRole, ['accounting', 'general_manager', 'operational_manager', 'superadmin'])
                ? getClientPendingPaymentProofs($conn, $row['id'])
                : 0;
            $row['missing_po_count'] = in_array($currentRole, ['project_coordinator', 'sales', 'general_manager', 'operational_manager', 'superadmin'])
                ? getClientMissingPoCount($conn, $row['id'])
                : 0;
            $row['po_not_ordered_count'] = in_array($currentRole, ['project_coordinator'])
                ? getClientApprovedPoNotOrderedCount($conn, $row['id'])
                : 0;
                $row['pending_internal_po'] = getClientPendingInternalPO($conn, $admin_id, $currentRole, $isHeadUser, $row['id']);
            $clients[] = $row;
            if ($row['status'] === 'New Client')
                $new_clients++;
            $active_projects++;
            if ($row['admin_name']) {
                $assigned_managers[$row['admin_name']] = true;
            }
        }
        ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Total Clients</div>
                <div class="stat-value"><?= $total_clients ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-label">New Clients</div>
                <div class="stat-value"><?= $new_clients ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-label">Active Projects</div>
                <div class="stat-value"><?= $active_projects ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stat-label">Assigned Managers</div>
                <div class="stat-value"><?= count($assigned_managers) ?></div>
            </div>
        </div>

        <div class="filters-section">
            <!-- Active / Finished tabs -->
            <div style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
                <button id="tabActive" onclick="setTab('active')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #0B0B0B; background:#0B0B0B; color:white; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-tasks"></i> Active
                    <span id="activeCount"
                        style="background:rgba(255,255,255,.2); border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($clients) ?></span>
                </button>
                <button id="tabFinished" onclick="setTab('finished')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #E2E2E2; background:white; color:#6B6B6B; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-check-double"></i> Finished
                    <span id="finishedCount"
                        style="background:#e2d9ce; border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($finishedClients) ?></span>
                </button>
            </div>
            <div class="filters-grid">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="searchInput" placeholder="Search by client name, project, or reference...">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Status</label>
                    <select id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="New Client">New Client</option>
                        <option value="Old Client">Old Client</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Business Type</label>
                    <select id="businessFilter">
                        <option value="">All Types</option>
                        <option value="Project">Project</option>
                        <option value="Non-Project">Individual</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="toggle-btn active" id="gridBtn" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="toggle-btn" id="listBtn" onclick="setView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <div id="banner-payment-proofs"
            style="display:none; background:#fffbeb; border:2px solid #f59e0b; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-file-invoice-dollar" style="color:#d97706; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#92400e;">
                        <span id="count-payment-proofs"></span> waiting for your review across clients
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:3px;">
                        Look for the <strong>orange badge</strong> on each client card below. Open the tracker to
                        review.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-pending-approvals"
            style="display:none; background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-bell" style="color:#d97706; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#92400e;">
                        You have <span id="count-pending-approvals"></span> across your clients
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:3px;">
                        Look for the <strong>yellow badge</strong> on each client card below to find which ones need
                        your attention.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-rejected-visits"
            style="display:none; background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-times-circle" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#991b1b;">
                        <span id="count-rejected-visits"></span> rejected across your clients
                    </div>
                    <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                        Look for the <strong>red badge</strong> on each client card below. Open the tracker to edit and
                        resubmit.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-missing-po"
            style="display:none; background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-file-invoice-dollar" style="color:#d97706; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#92400e;">
                        <span id="count-missing-po"></span> no Purchase Order submitted yet
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:3px;">
                        Look for the <strong>yellow badge</strong> on each client card below. Open the tracker to submit
                        a PO.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-po-not-ordered"
            style="display:none; background:#eff6ff; border:2px solid #3b82f6; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-shopping-cart" style="color:#2563eb; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#1e40af;">
                        <span id="count-po-not-ordered"></span> not yet fully ordered across clients
                    </div>
                    <div style="font-size:12px; color:#3b82f6; margin-top:3px;">
                        Look for the <strong>blue badge</strong> on each client card below. Open the tracker to update
                        the order status.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-internal-po"
            style="display:none; background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-file-signature" style="color:#d97706; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#92400e;">
                        <span id="count-internal-po"></span> waiting for your review
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:3px;">
                        Look for the <strong>yellow badge</strong> on each client card below. Open the tracker to review the Internal P.O.
                    </div>
                </div>
            </div>
        </div>

        <div id="banner-rejected-uploads"
            style="display:none; background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-times-circle" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:15px; color:#991b1b;">
                        <span id="count-rejected-uploads"></span> you submitted have been rejected
                    </div>
                    <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                        Look for the <strong>red badge</strong> on each client card below. Open the tracker to
                        re-submit.
                    </div>
                </div>
            </div>
        </div>

        <div class="active-content">
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Clients Found</h3>
                    <p>There are no clients in the system yet.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid" id="clientsGrid">
                    <?php foreach ($clients as $client): ?>
                        <div class="client-card"
                            data-search="<?= strtolower($client['clientname'] . ' ' . $client['nameproject'] . ' ' . $client['reference_number']) ?>"
                            data-status="<?= htmlspecialchars($client['status']) ?>"
                            data-business="<?= htmlspecialchars($client['business_type']) ?>"
                            onclick="viewTracker(<?= $client['id'] ?>)">
                            <div class="client-card-header" data-client-id="<?= $client['id'] ?>">
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                        <div style="min-width:0; flex:1;">
                                            <h3 style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                <?= htmlspecialchars($client['clientname']) ?>
                                            </h3>
                                            <div class="reference">
                                                <i class="fas fa-hashtag"></i>
                                                <?= htmlspecialchars($client['reference_number']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="badges-<?= $client['id'] ?>"
                                        style="display:flex; flex-wrap:wrap; gap:5px; margin-top:5px;">
                                        <?php if ($client['pending_approvals'] > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-bell"></i>
                                                <span><?= $client['pending_approvals'] ?> pending</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['pending_payment_proofs'] > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                                <span><?= $client['pending_payment_proofs'] ?>
                                                    proof<?= $client['pending_payment_proofs'] > 1 ? 's' : '' ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['rejected_site_visits'] > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-times-circle"></i>
                                                <span><?= $client['rejected_site_visits'] ?> rejected
                                                    visit<?= $client['rejected_site_visits'] > 1 ? 's' : '' ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['rejected_uploads'] > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-file-times"></i>
                                                <span><?= $client['rejected_uploads'] ?> rejected
                                                    file<?= $client['rejected_uploads'] > 1 ? 's' : '' ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (($client['missing_po_count'] ?? 0) > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                                <span><?= $client['missing_po_count'] ?>
                                                    BOM<?= $client['missing_po_count'] > 1 ? 's' : '' ?> missing PO</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (($client['pending_internal_po'] ?? 0) > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-file-signature"></i>
                                                <span>Internal P.O pending</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (($client['po_not_ordered_count'] ?? 0) > 0): ?>
                                            <div
                                                style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                <i class="fas fa-shopping-cart"></i>
                                                <span><?= $client['po_not_ordered_count'] ?> approved
                                                    PO<?= $client['po_not_ordered_count'] > 1 ? 's' : '' ?> not yet ordered</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="client-card-body">
                                <div class="client-info-row">
                                    <i class="fas fa-project-diagram"></i>
                                    <span class="label">Project:</span>
                                    <span class="value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>

                                <div class="client-info-row">
                                    <i class="fas fa-tag"></i>
                                    <span class="label">Status:</span>
                                    <span class="status-badge status-<?= $client['status'] === 'New Client' ? 'new' : 'old' ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                </div>

                                <div class="client-info-row">
                                    <i class="fas fa-building"></i>
                                    <span class="label">Type:</span>
                                    <span
                                        class="value"><?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?></span>
                                </div>

                                <?php if ($client['admin_name']): ?>
                                    <div class="client-info-row">
                                        <i class="fas fa-user-tie"></i>
                                        <span class="label">Assigned to:</span>
                                        <span class="assigned-to-badge">
                                            <?= htmlspecialchars($client['admin_name']) ?>
                                            <span class="role-badge"><?= htmlspecialchars($client['admin_role']) ?></span>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($client['contact']): ?>
                                    <div class="client-info-row">
                                        <i class="fas fa-phone"></i>
                                        <span class="label">Contact:</span>
                                        <span class="value"><?= htmlspecialchars($client['contact']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="client-info-row">
                                    <i class="fas fa-calendar"></i>
                                    <span class="label">Created:</span>
                                    <span class="value"><?= date('M d, Y', strtotime($client['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="client-card-footer">
                                <small style="color: #666;">
                                    <i class="fas fa-clock"></i>
                                    <?= date('g:i A', strtotime($client['created_at'])) ?>
                                </small>
                                <button class="view-tracker-btn"
                                    onclick="viewTracker(<?= $client['id'] ?>); event.stopPropagation();">
                                    <i class="fas fa-chart-line"></i>
                                    View Tracker
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div><!-- end active-content -->

        <!-- Finished clients grid (hidden by default) -->
        <div id="finishedGridWrapper" style="display:none;">
            <?php if (empty($finishedClients)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-double"></i>
                    <h3>No Finished Projects</h3>
                    <p>No projects have been marked as finished yet.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid" id="finishedClientsGrid">
                    <?php foreach ($finishedClients as $client): ?>
                        <div class="client-card"
                            data-search="<?= strtolower($client['clientname'] . ' ' . $client['nameproject'] . ' ' . $client['reference_number']) ?>"
                            data-status="<?= htmlspecialchars($client['status']) ?>"
                            data-business="<?= htmlspecialchars($client['business_type']) ?>"
                            onclick="viewTracker(<?= $client['id'] ?>)" style="border:2px solid #6ee7b7;">
                            <div class="client-card-header" style="background:linear-gradient(135deg,#065f46,#10b981);">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                    <div>
                                        <h3><?= htmlspecialchars($client['clientname']) ?></h3>
                                        <div class="reference">
                                            <i class="fas fa-hashtag"></i> <?= htmlspecialchars($client['reference_number']) ?>
                                        </div>
                                    </div>
                                    <div
                                        style="background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:4px 10px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; flex-shrink:0;">
                                        <i class="fas fa-check-double"></i> Finished
                                    </div>
                                </div>
                            </div>
                            <div class="client-card-body">
                                <div class="client-info-row">
                                    <i class="fas fa-project-diagram"></i>
                                    <span class="label">Project:</span>
                                    <span class="value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>
                                <div class="client-info-row">
                                    <i class="fas fa-building"></i>
                                    <span class="label">Type:</span>
                                    <span
                                        class="value"><?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?></span>
                                </div>
                                <?php if ($client['admin_name']): ?>
                                    <div class="client-info-row">
                                        <i class="fas fa-user-tie"></i>
                                        <span class="label">Assigned to:</span>
                                        <span class="assigned-to-badge">
                                            <?= htmlspecialchars($client['admin_name']) ?>
                                            <span class="role-badge"><?= htmlspecialchars($client['admin_role']) ?></span>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="client-info-row">
                                    <i class="fas fa-calendar"></i>
                                    <span class="label">Created:</span>
                                    <span class="value"><?= date('M d, Y', strtotime($client['created_at'])) ?></span>
                                </div>
                            </div>
                            <div class="client-card-footer">
                                <small style="color:#059669; font-weight:600;"><i class="fas fa-check-circle"></i> All stages
                                    complete</small>
                                <button class="view-tracker-btn" style="background:linear-gradient(135deg,#065f46,#10b981);"
                                    onclick="viewTracker(<?= $client['id'] ?>); event.stopPropagation();">
                                    <i class="fas fa-chart-line"></i> View Tracker
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        let currentTab = 'active';

        function setTab(tab) {
            currentTab = tab;
            const finishedWrapper = document.getElementById('finishedGridWrapper');
            const tabActive = document.getElementById('tabActive');
            const tabFinished = document.getElementById('tabFinished');

            // Hide/show active content (grid or empty-state)
            document.querySelectorAll('.active-content').forEach(el => {
                el.style.display = tab === 'active' ? '' : 'none';
            });

            // Hide/show finished wrapper
            if (finishedWrapper) finishedWrapper.style.display = tab === 'finished' ? '' : 'none';

            // Style active tab
            if (tab === 'active') {
                tabActive.style.background = '#0B0B0B';
                tabActive.style.color = 'white';
                tabActive.style.borderColor = '#0B0B0B';
                tabFinished.style.background = 'white';
                tabFinished.style.color = '#6B6B6B';
                tabFinished.style.borderColor = '#E2E2E2';
            } else {
                tabFinished.style.background = 'linear-gradient(135deg,#065f46,#10b981)';
                tabFinished.style.color = 'white';
                tabFinished.style.borderColor = '#065f46';
                tabActive.style.background = 'white';
                tabActive.style.color = '#6B6B6B';
                tabActive.style.borderColor = '#E2E2E2';
            }
            applyFilters();
        }

        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const businessFilter = document.getElementById('businessFilter').value;

            const gridId = currentTab === 'active' ? 'clientsGrid' : 'finishedClientsGrid';
            const grid = document.getElementById(gridId);
            if (!grid) return;

            const isListView = grid.classList.contains('list-view');
            const cards = grid.querySelectorAll('.client-card');

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                const cardStatus = card.getAttribute('data-status');
                const cardBusiness = card.getAttribute('data-business');

                const matchesSearch = searchData.includes(searchTerm);
                const matchesStatus = !statusFilter || cardStatus === statusFilter;
                const matchesBusiness = !businessFilter || cardBusiness === businessFilter;

                card.style.setProperty('display', (matchesSearch && matchesStatus && matchesBusiness) ? (isListView ? 'flex' : 'block') : 'none', 'important');
            });
        }

        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        document.getElementById('businessFilter').addEventListener('change', applyFilters);

        function setView(type) {
            const grids = [document.getElementById('clientsGrid'), document.getElementById('finishedClientsGrid')];
            const gridBtn = document.getElementById('gridBtn');
            const listBtn = document.getElementById('listBtn');

            grids.forEach(grid => {
                if (!grid) return;
                if (type === 'list') {
                    grid.classList.add('list-view');
                } else {
                    grid.classList.remove('list-view');
                }
            });

            if (type === 'list') {
                listBtn.classList.add('active');
                gridBtn.classList.remove('active');
            } else {
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            }
            applyFilters();
        }

        function viewTracker(clientId) {
            window.location.href = `<?= BASE_URL ?>unified-project-tracker?client_id=${clientId}`;
        }

        document.addEventListener('DOMContentLoaded', function () {
            setView('list');
            setTab('active');
        });

        // ─── Real-time notification polling ───────────────────────────────────────
        function updateBanner(id, countId, count, singular, plural) {
            const banner = document.getElementById(id);
            const countEl = document.getElementById(countId);
            if (!banner) return;
            if (count > 0) {
                if (countEl) countEl.textContent = count + ' ' + (count > 1 ? plural : singular);
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
        }

        function updateCardHeader(client) {
            const header = document.querySelector(`.client-card-header[data-client-id="${client.id}"]`);
            const badgesDiv = document.getElementById('badges-' + client.id);
            if (!header) return;

            // Update header color
            const hasRed = client.rejected_site_visits > 0 || client.rejected_uploads > 0;
            const hasBlue = client.po_not_ordered_count > 0;
            const hasYellow = client.pending_approvals > 0 || client.pending_payment_proofs > 0 || client.missing_po_count > 0 || client.pending_internal_po > 0;

            if (hasRed) {
                header.style.background = 'linear-gradient(135deg,#991b1b,#ef4444)';
            } else if (hasBlue) {
                header.style.background = 'linear-gradient(135deg,#1e40af,#3b82f6)';
            } else if (hasYellow) {
                header.style.background = 'linear-gradient(135deg,#92400e,#d97706)';
            } else {
                header.style.background = '#0B0B0B';
            }

            // Rebuild badges
            if (!badgesDiv) return;
            badgesDiv.innerHTML = '';

            const badge = (icon, text) => `
        <div style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
            <i class="fas ${icon}"></i><span>${text}</span>
        </div>`;

            if (client.pending_approvals > 0)
                badgesDiv.innerHTML += badge('fa-bell', client.pending_approvals + ' pending');
            if (client.pending_payment_proofs > 0)
                badgesDiv.innerHTML += badge('fa-file-invoice-dollar', client.pending_payment_proofs + ' proof' + (client.pending_payment_proofs > 1 ? 's' : ''));
            if (client.rejected_site_visits > 0)
                badgesDiv.innerHTML += badge('fa-times-circle', client.rejected_site_visits + ' rejected visit' + (client.rejected_site_visits > 1 ? 's' : ''));
            if (client.rejected_uploads > 0)
                badgesDiv.innerHTML += badge('fa-file', client.rejected_uploads + ' rejected file' + (client.rejected_uploads > 1 ? 's' : ''));
            if (client.missing_po_count > 0)
                badgesDiv.innerHTML += badge('fa-file-invoice-dollar', client.missing_po_count + ' BOM' + (client.missing_po_count > 1 ? 's' : '') + ' missing PO');
            if (client.po_not_ordered_count > 0)
                badgesDiv.innerHTML += badge('fa-shopping-cart', client.po_not_ordered_count + ' approved PO' + (client.po_not_ordered_count > 1 ? 's' : '') + ' not yet ordered');
            if (client.pending_internal_po > 0)
                badgesDiv.innerHTML += badge('fa-file-signature', 'Internal P.O pending');
        }

        function pollNotifications() {
            fetch('<?= BASE_URL ?>get-notifications')
                .then(r => r.json())
                .then(data => {
                    // Update top banners
                    updateBanner('banner-payment-proofs', 'count-payment-proofs', data.totals.pending_payment_proofs, 'payment proof', 'payment proofs');
                    updateBanner('banner-pending-approvals', 'count-pending-approvals', data.totals.pending_approvals, 'pending approval', 'pending approvals');
                    updateBanner('banner-rejected-visits', 'count-rejected-visits', data.totals.rejected_site_visits, 'site visit rejected', 'site visits rejected');
                    updateBanner('banner-missing-po', 'count-missing-po', data.totals.missing_po_count, 'approved BOM has', 'approved BOMs have');
                    updateBanner('banner-po-not-ordered', 'count-po-not-ordered', data.totals.po_not_ordered_count, 'approved PO is', 'approved POs are');
                    updateBanner('banner-rejected-uploads', 'count-rejected-uploads', data.totals.rejected_uploads, 'file rejected', 'files rejected');
                    updateBanner('banner-internal-po', 'count-internal-po', data.totals.pending_internal_po, 'Internal P.O needs your review', 'Internal P.Os need your review');

                    // Update each client card
                    data.clients.forEach(client => updateCardHeader(client));
                })
                .catch(err => console.warn('Notification poll failed:', err));
        }

        // Poll every 30 seconds
        pollNotifications(); // run once immediately on load
        setInterval(pollNotifications, 15000);
        // ──────────────────────────────────────────────────────────────────────────
    </script>
</body>

</html>