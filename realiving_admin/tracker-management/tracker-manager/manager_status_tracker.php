<?php
// manager_status_tracker.php
include $includes ['mainbody'];

// Restrict access to general_manager and operational_manager only
$allowedRoles = ['general_manager', 'operational_manager', 'superadmin', 'sales'];

$admin_id = $_SESSION['admin_id'];

// Check user's role
$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();

if (!in_array($userInfo['role'], $allowedRoles)) {
    die("Access Denied: This page is only accessible by General Manager and Operational Manager.");
}

// Get filter from URL
$business_type_filter = isset($_GET['business_type']) ? $_GET['business_type'] : 'all';

// Fetch all clients with their progress
$query = "
    SELECT 
        u.id,
        u.clientname,
        u.nameproject,
        u.reference_number,
        u.status,
        u.business_type,
        u.total_project_cost,
        u.remaining_balance,
        u.created_at,
        u.account_status,
        a.full_name as admin_name,
        a.role as admin_role
    FROM user_info u
    LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.account_status != 'Finished'
";

if ($business_type_filter !== 'all') {
    $query .= " AND u.business_type = ?";
}

$query .= " ORDER BY u.created_at DESC";

if ($business_type_filter !== 'all') {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $business_type_filter);
} else {
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();

// Fetch finished clients separately
$finishedQuery = "
    SELECT 
        u.id,
        u.clientname,
        u.nameproject,
        u.reference_number,
        u.status,
        u.business_type,
        u.total_project_cost,
        u.remaining_balance,
        u.created_at,
        u.account_status,
        a.full_name as admin_name,
        a.role as admin_role
    FROM user_info u
    LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.account_status = 'Finished'
";
if ($business_type_filter !== 'all') {
    $finishedQuery .= " AND u.business_type = ?";
}
$finishedQuery .= " ORDER BY u.created_at DESC";

if ($business_type_filter !== 'all') {
    $finishedStmt = $conn->prepare($finishedQuery);
    $finishedStmt->bind_param("s", $business_type_filter);
} else {
    $finishedStmt = $conn->prepare($finishedQuery);
}
$finishedStmt->execute();
$finishedResult = $finishedStmt->get_result();
$finishedClients = [];
while ($frow = $finishedResult->fetch_assoc()) {
    // Get tracker progress for finished clients
    $ftStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_stages,
        SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_stages
    FROM project_tracker WHERE client_id = ?
");
    $ftStmt->bind_param("i", $frow['id']);
    $ftStmt->execute();
    $ftData = $ftStmt->get_result()->fetch_assoc();

    // Match the exact stage count used in manager_project_detail.php
    $ftData['total_stages'] = ($frow['business_type'] === 'Non-Project') ? 17 : 18;

    $fpStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_payments,
            SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END) as total_paid_amount
        FROM payment_schedule WHERE client_id = ?
    ");
    $fpStmt->bind_param("i", $frow['id']);
    $fpStmt->execute();
    $fpData = $fpStmt->get_result()->fetch_assoc();

    $frow['tracker_progress'] = $ftData;
    $frow['payment_progress'] = $fpData;
    $frow['completion_percentage'] = ($ftData['total_stages'] > 0)
        ? ($ftData['completed_stages'] / $ftData['total_stages']) * 100 : 0;
    $frow['payment_percentage'] = ($frow['total_project_cost'] > 0)
        ? (($fpData['total_paid_amount'] ?? 0) / $frow['total_project_cost']) * 100 : 0;
    $finishedClients[] = $frow;
}

// Calculate statistics
$total_clients = 0;
$total_project_value = 0;
$total_collected = 0;
$project_count = 0;
$non_project_count = 0;

$isHeadForApprovals = (bool) ($conn->query("SELECT is_head FROM account WHERE id = $admin_id")->fetch_assoc()['is_head'] ?? false);

$clients = [];
while ($row = $result->fetch_assoc()) {
    $row['pending_approvals'] = getClientPendingApprovalsForManager(
        $conn,
        $admin_id,
        $userInfo['role'],
        $isHeadForApprovals,
        $row['id']
    );
    // Get project tracker progress
    $trackerStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_stages,
        SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_stages,
        SUM(CASE WHEN status = 'Ongoing' THEN 1 ELSE 0 END) as ongoing_stages,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_stages
    FROM project_tracker
    WHERE client_id = ?
");
    $trackerStmt->bind_param("i", $row['id']);
    $trackerStmt->execute();
    $trackerData = $trackerStmt->get_result()->fetch_assoc();

    // Match the exact stage count used in manager_project_detail.php
// Project = 18 stages, Non-Project/Individual = 17 (excludes 'Samples Submitted TDS/SDS')
    $correctTotalStages = ($row['business_type'] === 'Non-Project') ? 17 : 18;
    $trackerData['total_stages'] = $correctTotalStages;

    // Get payment information
    $paymentStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_payments,
            SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END) as total_paid_amount
        FROM payment_schedule
        WHERE client_id = ?
    ");
    $paymentStmt->bind_param("i", $row['id']);
    $paymentStmt->execute();
    $paymentData = $paymentStmt->get_result()->fetch_assoc();

    $row['tracker_progress'] = $trackerData;
    $row['payment_progress'] = $paymentData;
    $row['completion_percentage'] = ($trackerData['total_stages'] > 0)
        ? ($trackerData['completed_stages'] / $trackerData['total_stages']) * 100
        : 0;
    $row['payment_percentage'] = ($row['total_project_cost'] > 0)
        ? (($paymentData['total_paid_amount'] ?? 0) / $row['total_project_cost']) * 100
        : 0;

    $clients[] = $row;

    // Update statistics
    $total_clients++;
    $total_project_value += $row['total_project_cost'] ?? 0;
    $total_collected += $paymentData['total_paid_amount'] ?? 0;

    if ($row['business_type'] === 'Project') {
        $project_count++;
    } else {
        $non_project_count++;
    }
}

// Finished tab stats
$finished_project_value = 0;
$finished_collected = 0;
$finished_project_count = 0;
$finished_non_project_count = 0;
foreach ($finishedClients as $fc) {
    $finished_project_value += $fc['total_project_cost'] ?? 0;
    $finished_collected += $fc['payment_progress']['total_paid_amount'] ?? 0;
    if ($fc['business_type'] === 'Project')
        $finished_project_count++;
    else
        $finished_non_project_count++;
}

// Finished stats (calculated separately so they show when Finished tab is active)
$finished_total_clients = count($finishedClients);
$finished_project_value = 0;
$finished_collected = 0;
$finished_project_count = 0;
$finished_non_project_count = 0;
foreach ($finishedClients as $fc) {
    $finished_project_value += $fc['total_project_cost'] ?? 0;
    $finished_collected += $fc['payment_progress']['total_paid_amount'] ?? 0;
    if ($fc['business_type'] === 'Project')
        $finished_project_count++;
    else
        $finished_non_project_count++;
}
// dummy closing brace removal — delete the lone } that was closing the while loop
// (the while loop's closing brace is already above, this replacement added a new one)
if (false) {
}

function getClientPendingApprovalsForManager($conn, $admin_id, $admin_role, $is_head, $client_id)
{
    $approvalStageRoles = [
        'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
        'Samples Submitted TDS/SDS' => ['technical_designer', 'general_manager', 'operational_manager'],
        'Quotation' => ['designer', 'general_manager', 'operational_manager'],
        'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
        'Purchase Order (Submit to accounting)' => ['accounting', 'technical_designer', 'general_manager', 'operational_manager'],
    ];
    $gmOmSequentialAll = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
    $total = 0;
    foreach ($approvalStageRoles as $stageName => $rolesAllowed) {
        $canApprove = false;
        if ($admin_role === 'technical_designer') {
            if (in_array('technical_designer', $rolesAllowed) && $is_head)
                $canApprove = true;
        } elseif ($admin_role === 'designer') {
            if (in_array($stageName, ['Rough Estimation', 'Quotation']) && $is_head)
                $canApprove = true;
        } elseif ($admin_role === 'accounting') {
            if ($stageName === 'Purchase Order (Submit to accounting)')
                $canApprove = true;
        } else {
            if (in_array($admin_role, $rolesAllowed))
                $canApprove = true;
        }
        if (!$canApprove)
            continue;

        if (in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stageName, $gmOmSequentialAll)) {
            $otherRole = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
            $step1Map = [
                'Rough Estimation' => ['designer'],
                'Samples Submitted TDS/SDS' => ['technical_designer'],
                'Quotation' => ['designer'],
                'Bill of Materials (BOM)' => ['technical_designer'],
                'Purchase Order (Submit to accounting)' => ['accounting', 'technical_designer'],
            ];
            $step1Roles = $step1Map[$stageName] ?? [];

            // Build step1 EXISTS clauses — only notify after step1 approved
            $step1Clauses = '';
            foreach ($step1Roles as $s1r) {
                $step1Clauses .= "
                  AND EXISTS (
                      SELECT 1 FROM stage_approval_reviews sar_s1
                      WHERE sar_s1.approval_id = sa.id
                        AND sar_s1.reviewer_role = '{$s1r}'
                        AND sar_s1.review_status = 'approved'
                  )";
            }

            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM stage_approvals sa
                INNER JOIN project_tracker pt ON sa.stage_id = pt.id
                WHERE pt.client_id = ?
                  AND pt.stage_name = ?
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
                  {$step1Clauses}
            ");
            $stmt->bind_param("isss", $client_id, $stageName, $admin_role, $otherRole);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM stage_approvals sa
                INNER JOIN project_tracker pt ON sa.stage_id = pt.id
                WHERE pt.client_id = ?
                  AND pt.stage_name = ?
                  AND sa.approval_status = 'pending'
                  AND NOT EXISTS (
                      SELECT 1 FROM stage_approval_reviews sar
                      WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
                  )
            ");
            $stmt->bind_param("iss", $client_id, $stageName, $admin_role);
        }
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
            WHERE rl.client_id = la.client_id AND rl.area = la.area AND rl.status = 'pending'
            AND (
                (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                OR rl.room_unit_number = la.room_unit_number
            )
        )
    ");
    $layoutStmt->bind_param("ii", $client_id, $admin_id);
    $layoutStmt->execute();
    $total += (int) $layoutStmt->get_result()->fetch_row()[0];

    // Also count pending site visit approvals
    if (in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin'])) {
        $svStmt = $conn->prepare("
            SELECT COUNT(*) FROM site_visit
            WHERE client_id = ? AND approval_status = 'Pending'
        ");
        $svStmt->bind_param("i", $client_id);
        $svStmt->execute();
        $total += (int) $svStmt->get_result()->fetch_row()[0];
    }

    return $total;
}

// Smart format function for displaying amounts
function formatAmount($amount)
{
    if ($amount >= 1000000) {
        // Show in millions if >= 1M
        return '₱' . number_format($amount / 1000000, 2) . 'M';
    } elseif ($amount >= 1000) {
        // Show in thousands if >= 1K
        return '₱' . number_format($amount / 1000, 2) . 'K';
    } else {
        // Show actual amount if < 1K
        return '₱' . number_format($amount, 2);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Status Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-header {
            background: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            font-size: 36px;
            color: #1a202c;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header h1 i {
            color: #8a5a44;
        }

        .page-header p {
            color: #718096;
            font-size: 16px;
        }

        .user-info-badge {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Executive Dashboard Stats */
        .executive-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #3b1f0f 0%, #8a5a44 100%);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .stat-subtext {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 8px;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .filter-tabs {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 12px 30px;
            border: 2px solid #e2e8f0;
            border-radius: 25px;
            background: white;
            color: #4a5568;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .filter-tab:hover {
            border-color: #8a5a44;
            color: #8a5a44;
            transform: translateY(-2px);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            border-color: #8a5a44;
            color: white;
        }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 25px;
        }

        .project-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .project-card-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 25px;
            color: white;
        }

        .project-card-header h3 {
            font-size: 20px;
            margin-bottom: 8px;
        }

        .project-card-header .reference {
            font-size: 13px;
            opacity: 0.9;
            font-family: monospace;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .project-card-body {
            padding: 25px;
        }

        .project-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f7fafc;
        }

        .project-info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            font-size: 13px;
            color: #718096;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: #1a202c;
            font-weight: 600;
        }

        /* Progress Bars */
        .progress-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #f7fafc;
        }

        .progress-item {
            margin-bottom: 15px;
        }

        .progress-item:last-child {
            margin-bottom: 0;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .progress-title {
            font-size: 12px;
            color: #4a5568;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .progress-percentage {
            font-size: 14px;
            font-weight: 700;
            color: #8a5a44;
        }

        .progress-bar-container {
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b1f0f 0%, #8a5a44 100%);
            transition: width 0.5s ease;
            border-radius: 10px;
        }

        .progress-bar-fill.green {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.new {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.old {
            background: #dbeafe;
            color: #1e40af;
        }

        .business-type-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .business-type-badge.project {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
        }

        .business-type-badge.non-project {
            background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
            color: white;
        }

        /* Financial Summary */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #f7fafc;
        }

        .financial-item {
            text-align: center;
        }

        .financial-label {
            font-size: 10px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .financial-value {
            font-size: 16px;
            font-weight: 700;
        }

        .financial-value.total {
            color: #3b82f6;
        }

        .financial-value.collected {
            color: #10b981;
        }

        .financial-value.remaining {
            color: #f59e0b;
        }

        .btn-view-details {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-view-details:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(138, 90, 68, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .empty-state i {
            font-size: 80px;
            color: #cbd5e0;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: #4a5568;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #a0aec0;
            font-size: 16px;
        }

        /* Toggle buttons */
        .toggle-btn {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            color: #718096;
            font-size: 16px;
            transition: all 0.2s;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            border-color: #3b1f0f;
            color: white;
        }

        /* List view overrides */
        .projects-grid.list-view {
            display: flex !important;
            flex-direction: column !important;
        }

        .projects-grid.list-view .project-card {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch;
        }

        .projects-grid.list-view .project-card-header {
            min-width: 300px;
            max-width: 300px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: visible;
        }

        .projects-grid.list-view .project-card-body {
            flex: 1;
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 20px;
            padding: 15px 20px;
        }

        .projects-grid.list-view .project-card-body .project-info-row {
            flex: 1 1 180px;
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .projects-grid.list-view .financial-summary {
            flex: 1 1 250px;
            border-top: none;
            margin-top: 0;
            padding-top: 0;
        }

        .projects-grid.list-view .progress-section {
            flex: 1 1 300px;
            border-top: none;
            margin-top: 0;
            padding-top: 0;
        }

        .projects-grid.list-view .btn-view-details {
            min-width: 160px;
            max-width: 160px;
            align-self: center;
            margin-top: 0;
        }

        /* Fix finished badge overflow in list view */
        .projects-grid.list-view .project-card-header h3 {
            font-size: 15px;
            word-break: break-word;
        }

        .projects-grid.list-view .project-card-header .reference {
            font-size: 11px;
            word-break: break-all;
        }

        .projects-grid.list-view .project-card-header>div {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .projects-grid.list-view .project-card-header>div>div:first-child {
            min-width: 0;
            flex: 1;
            overflow: hidden;
        }

        .projects-grid.list-view .project-card-header>div>div:last-child {
            flex-shrink: 0;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Executive Status Tracker
            </h1>
            <p>Comprehensive overview of all projects and their progress</p>

            <div class="user-info-badge">
                <i class="fas fa-user-shield"></i>
                <span><?= htmlspecialchars($userInfo['full_name']) ?></span>
                <span style="opacity: 0.7;">•</span>
                <span style="text-transform: capitalize;"><?= str_replace('_', ' ', $userInfo['role']) ?></span>
            </div>
        </div>

        <!-- Executive Statistics -->
        <div class="executive-stats" id="statsRow">
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-briefcase"></i></div>
                <div class="stat-value" id="stat-total"><?= $total_clients ?></div>
                <div class="stat-label">Total Projects</div>
                <div class="stat-subtext" id="stat-breakdown">
                    <?= $project_count ?> Project • <?= $non_project_count ?> Individual
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-value" id="stat-value"><?= formatAmount($total_project_value) ?></div>
                <div class="stat-label">Total Project Value</div>
                <div class="stat-subtext">Across all active projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-value" id="stat-collected"><?= formatAmount($total_collected) ?></div>
                <div class="stat-label">Total Collected</div>
                <div class="stat-subtext" id="stat-collected-pct">
                    <?= $total_project_value > 0 ? number_format(($total_collected / $total_project_value) * 100, 1) : 0 ?>%
                    of total value
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value" id="stat-balance"><?= formatAmount($total_project_value - $total_collected) ?>
                </div>
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-subtext">Pending collections</div>
            </div>
            <div class="stat-card" style="border-top-color:#10b981 !important;">
                <div class="stat-icon green"><i class="fas fa-check-double"></i></div>
                <div class="stat-value" style="color:#065f46;"><?= count($finishedClients) ?></div>
                <div class="stat-label">Finished Projects</div>
                <div class="stat-subtext">All stages completed</div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-section">
            <!-- Active / Finished tabs -->
            <div style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; align-items:center;">
                <button id="tabActive" onclick="setTab('active')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #3b1f0f; background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-tasks"></i> Active
                    <span
                        style="background:rgba(255,255,255,.25); border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($clients) ?></span>
                </button>
                <button id="tabFinished" onclick="setTab('finished')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #e2e8f0; background:white; color:#4a5568; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-check-double"></i> Finished
                    <span
                        style="background:#e2e8f0; border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($finishedClients) ?></span>
                </button>
                <div style="width:1px; height:28px; background:#e2e8f0; margin:0 6px;"></div>
                <a href="?business_type=all" class="filter-tab <?= $business_type_filter === 'all' ? 'active' : '' ?>">
                    <i class="fas fa-globe"></i> All
                </a>
                <a href="?business_type=Project"
                    class="filter-tab <?= $business_type_filter === 'Project' ? 'active' : '' ?>">
                    <i class="fas fa-building"></i> Project
                </a>
                <a href="?business_type=Non-Project"
                    class="filter-tab <?= $business_type_filter === 'Non-Project' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Individual
                </a>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button class="toggle-btn" id="gridBtn" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="toggle-btn active" id="listBtn" onclick="setView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <div class="active-content">
            <?php
            $totalPending = array_sum(array_column($clients, 'pending_approvals'));
            if ($totalPending > 0):
                ?>
                <div
                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:16px; padding:16px 22px; margin-bottom:24px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                    <i class="fas fa-bell" style="color:#d97706; font-size:22px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#92400e;">
                            You have <?= $totalPending ?> pending approval<?= $totalPending > 1 ? 's' : '' ?> across your
                            projects
                        </div>
                        <div style="font-size:12px; color:#b45309; margin-top:3px;">
                            Look for the <strong>bell badge</strong> on each project card below to find which ones need your
                            attention.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Projects Grid -->
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Projects Found</h3>
                    <p>No projects match the selected filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="projects-grid" id="projectsGrid">
                    <?php foreach ($clients as $client): ?>
                        <div class="project-card"
                            onclick="window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'">
                            <div class="project-card-header"
                                style="<?= $client['pending_approvals'] > 0 ? 'background:linear-gradient(135deg,#92400e,#d97706);' : '' ?>">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                    <div>
                                        <h3><?= htmlspecialchars($client['clientname']) ?></h3>
                                        <div class="reference">
                                            <i class="fas fa-hashtag"></i>
                                            <?= htmlspecialchars($client['reference_number']) ?>
                                        </div>
                                    </div>
                                    <?php if ($client['pending_approvals'] > 0): ?>
                                        <div
                                            style="background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:4px 10px; display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; white-space:nowrap; flex-shrink:0;">
                                            <i class="fas fa-bell" style="flex-shrink:0;"></i>
                                            <span><?= $client['pending_approvals'] ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="project-card-body">
                                <div class="project-info-row">
                                    <span class="info-label">Project Name</span>
                                    <span class="info-value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>

                                <div class="project-info-row">
                                    <span class="info-label">Type</span>
                                    <span
                                        class="business-type-badge <?= strtolower(str_replace('-', '-', $client['business_type'])) ?>">
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                </div>

                                <div class="project-info-row">
                                    <span class="info-label">Status</span>
                                    <span class="status-badge <?= $client['status'] === 'New Client' ? 'new' : 'old' ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                </div>

                                <div class="project-info-row">
                                    <span class="info-label">Sale/Designer Representative</span>
                                    <span class="info-value">
                                        <?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?>
                                    </span>
                                </div>

                                <!-- Financial Summary -->
                                <div class="financial-summary">
                                    <div class="financial-item">
                                        <div class="financial-label">Total Value</div>
                                        <div class="financial-value total">
                                            ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="financial-item">
                                        <div class="financial-label">Collected</div>
                                        <div class="financial-value collected">
                                            ₱<?= number_format($client['payment_progress']['total_paid_amount'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="financial-item">
                                        <div class="financial-label">Balance</div>
                                        <div class="financial-value remaining">
                                            ₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Progress Tracking -->
                                <div class="progress-section">
                                    <div class="progress-item">
                                        <div class="progress-header">
                                            <span class="progress-title">
                                                <i class="fas fa-tasks"></i> Project Completion
                                            </span>
                                            <span class="progress-percentage">
                                                <?= number_format($client['completion_percentage'], 1) ?>%
                                            </span>
                                        </div>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill"
                                                style="width: <?= $client['completion_percentage'] ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="progress-item">
                                        <div class="progress-header">
                                            <span class="progress-title">
                                                <i class="fas fa-money-check-alt"></i> Payment Progress
                                            </span>
                                            <span class="progress-percentage">
                                                <?= number_format($client['payment_percentage'], 1) ?>%
                                            </span>
                                        </div>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill green"
                                                style="width: <?= $client['payment_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </div>

                                <button class="btn-view-details"
                                    onclick="event.stopPropagation(); window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'">
                                    <i class="fas fa-eye"></i>
                                    View Detailed Status
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div><!-- end active-content -->

        <!-- Finished projects grid (hidden by default) -->
        <div id="finishedGridWrapper" style="display:none;">
            <?php if (empty($finishedClients)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-double"></i>
                    <h3>No Finished Projects</h3>
                    <p>No projects have been marked as finished yet.</p>
                </div>
            <?php else: ?>
                <div class="projects-grid" id="finishedProjectsGrid">
                    <?php foreach ($finishedClients as $client): ?>
                        <div class="project-card"
                            onclick="window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'"
                            style="border:2px solid #6ee7b7;">
                            <div class="project-card-header" style="background:linear-gradient(135deg,#065f46,#10b981);">
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
                            <div class="project-card-body">
                                <div class="project-info-row">
                                    <span class="info-label">Project Name</span>
                                    <span class="info-value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>
                                <div class="project-info-row">
                                    <span class="info-label">Type</span>
                                    <span
                                        class="business-type-badge <?= $client['business_type'] === 'Non-Project' ? 'non-project' : 'project' ?>">
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                </div>
                                <div class="project-info-row">
                                    <span class="info-label">Project Manager</span>
                                    <span
                                        class="info-value"><?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?></span>
                                </div>
                                <div class="financial-summary">
                                    <div class="financial-item">
                                        <div class="financial-label">Total Value</div>
                                        <div class="financial-value total">
                                            ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?></div>
                                    </div>
                                    <div class="financial-item">
                                        <div class="financial-label">Collected</div>
                                        <div class="financial-value collected">
                                            ₱<?= number_format($client['payment_progress']['total_paid_amount'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="financial-item">
                                        <div class="financial-label">Balance</div>
                                        <div class="financial-value remaining">
                                            ₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?></div>
                                    </div>
                                </div>
                                <div class="progress-section">
                                    <div class="progress-item">
                                        <div class="progress-header">
                                            <span class="progress-title"><i class="fas fa-tasks"></i> Project Completion</span>
                                            <span class="progress-percentage"
                                                style="color:#059669;"><?= number_format($client['completion_percentage'], 1) ?>%</span>
                                        </div>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill"
                                                style="width:<?= $client['completion_percentage'] ?>%; background:linear-gradient(90deg,#065f46,#10b981);">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="progress-item">
                                        <div class="progress-header">
                                            <span class="progress-title"><i class="fas fa-money-check-alt"></i> Payment
                                                Progress</span>
                                            <span class="progress-percentage"
                                                style="color:#059669;"><?= number_format($client['payment_percentage'], 1) ?>%</span>
                                        </div>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill green"
                                                style="width:<?= $client['payment_percentage'] ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn-view-details" style="background:linear-gradient(135deg,#065f46,#10b981);"
                                    onclick="event.stopPropagation(); window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'">
                                    <i class="fas fa-eye"></i> View Detailed Status
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <script>
        // Stat data from PHP
        const statsData = {
            active: {
                total: <?= $total_clients ?>,
                breakdown: '<?= $project_count ?> Project • <?= $non_project_count ?> Individual',
                value: '<?= formatAmount($total_project_value) ?>',
                collected: '<?= formatAmount($total_collected) ?>',
                collectedPct: '<?= $total_project_value > 0 ? number_format(($total_collected / $total_project_value) * 100, 1) : 0 ?>% of total value',
                balance: '<?= formatAmount($total_project_value - $total_collected) ?>'
            },
            finished: {
                total: <?= count($finishedClients) ?>,
                breakdown: '<?= $finished_project_count ?> Project • <?= $finished_non_project_count ?> Individual',
                value: '<?= formatAmount($finished_project_value) ?>',
                collected: '<?= formatAmount($finished_collected) ?>',
                collectedPct: '<?= $finished_project_value > 0 ? number_format(($finished_collected / $finished_project_value) * 100, 1) : 0 ?>% of total value',
                balance: '<?= formatAmount($finished_project_value - $finished_collected) ?>'
            }
        };

        function updateStats(tab) {
            const d = statsData[tab];
            document.getElementById('stat-total').textContent = d.total;
            document.getElementById('stat-breakdown').textContent = d.breakdown;
            document.getElementById('stat-value').textContent = d.value;
            document.getElementById('stat-collected').textContent = d.collected;
            document.getElementById('stat-collected-pct').textContent = d.collectedPct;
            document.getElementById('stat-balance').textContent = d.balance;
        }

        let currentTab = 'active';

        function setTab(tab) {
            currentTab = tab;
            const finishedWrapper = document.getElementById('finishedGridWrapper');
            const tabActive = document.getElementById('tabActive');
            const tabFinished = document.getElementById('tabFinished');

            document.querySelectorAll('.active-content').forEach(el => {
                el.style.display = tab === 'active' ? '' : 'none';
            });
            if (finishedWrapper) finishedWrapper.style.display = tab === 'finished' ? '' : 'none';

            if (tab === 'active') {
                tabActive.style.background = 'linear-gradient(135deg,#3b1f0f,#8a5a44)';
                tabActive.style.color = 'white';
                tabActive.style.borderColor = '#3b1f0f';
                tabFinished.style.background = 'white';
                tabFinished.style.color = '#4a5568';
                tabFinished.style.borderColor = '#e2e8f0';
            } else {
                tabFinished.style.background = 'linear-gradient(135deg,#065f46,#10b981)';
                tabFinished.style.color = 'white';
                tabFinished.style.borderColor = '#065f46';
                tabActive.style.background = 'white';
                tabActive.style.color = '#4a5568';
                tabActive.style.borderColor = '#e2e8f0';
            }

            updateStats(tab);
        }

        function setView(type) {
            const grids = [document.getElementById('projectsGrid'), document.getElementById('finishedProjectsGrid')];
            const gridBtn = document.getElementById('gridBtn');
            const listBtn = document.getElementById('listBtn');

            grids.forEach(grid => {
                if (!grid) return;
                if (type === 'list') grid.classList.add('list-view');
                else grid.classList.remove('list-view');
            });

            if (type === 'list') {
                listBtn.classList.add('active');
                gridBtn.classList.remove('active');
            } else {
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            setView('list');
            setTab('active');
        });
    </script>
</body>

</html>