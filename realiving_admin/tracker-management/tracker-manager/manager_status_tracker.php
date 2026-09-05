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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!--
        This page now uses Tailwind CSS utility classes only (matching the design
        system of all_clients_tracker_list.php: --adm-ink:#0B0B0B, --adm-bg:#F5F5F5,
        --adm-surface:#FFFFFF, --adm-soft:#6B6B6B, --adm-muted:#9A9A9A, --adm-line:#E2E2E2).
        Make sure this file's path is included in your tailwind.config.js "content" array
        so the JIT compiler picks up the classes below, including the ones referenced
        only inside the <script> block (setTab/setView toggle literal class strings).
    -->
</head>

<body class="bg-[#F5F5F5] font-['Inter',sans-serif] text-[#0B0B0B]">
    <div class="max-w-[1600px] mx-auto py-8 px-5">

        <!-- Page header -->
        <div class="bg-[#0B0B0B] p-10 rounded-2xl text-white mb-8 relative overflow-hidden">
            <h1 class="text-[32px] font-bold mb-2.5 flex items-center gap-3.5 relative z-10">
                <i class="fas fa-chart-line"></i>
                Executive Status Tracker
            </h1>
            <p class="opacity-90 text-base relative z-10">Comprehensive overview of all projects and their progress
            </p>

            <div
                class="inline-flex items-center gap-2.5 bg-white/[0.08] border border-white/[0.18] px-5 py-2.5 rounded-full mt-4 relative z-10">
                <i class="fas fa-user-shield"></i>
                <span><?= htmlspecialchars($userInfo['full_name']) ?></span>
                <span class="opacity-70">•</span>
                <span class="capitalize"><?= str_replace('_', ' ', $userInfo['role']) ?></span>
            </div>
        </div>

        <!-- Executive Statistics -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-5 mb-8" id="statsRow">
            <div
                class="bg-white p-6 rounded-xl border border-[#E2E2E2] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)]">
                <div class="w-[50px] h-[50px] bg-[#0B0B0B] rounded-xl flex items-center justify-center text-white text-2xl mb-4">
                    <i class="fas fa-briefcase"></i>
                </div>
                <div class="text-[28px] font-bold text-[#0B0B0B]" id="stat-total"><?= $total_clients ?></div>
                <div class="text-xs text-[#666] uppercase tracking-wide">Total Projects</div>
                <div class="text-xs text-[#9A9A9A] mt-2" id="stat-breakdown">
                    <?= $project_count ?> Project • <?= $non_project_count ?> Individual
                </div>
            </div>

            <div
                class="bg-white p-6 rounded-xl border border-[#E2E2E2] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)]">
                <div class="w-[50px] h-[50px] bg-[#0B0B0B] rounded-xl flex items-center justify-center text-white text-2xl mb-4">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="text-[28px] font-bold text-[#0B0B0B]" id="stat-value"><?= formatAmount($total_project_value) ?></div>
                <div class="text-xs text-[#666] uppercase tracking-wide">Total Project Value</div>
                <div class="text-xs text-[#9A9A9A] mt-2">Across all active projects</div>
            </div>

            <div
                class="bg-white p-6 rounded-xl border border-[#E2E2E2] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)]">
                <div class="w-[50px] h-[50px] bg-[#0B0B0B] rounded-xl flex items-center justify-center text-white text-2xl mb-4">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="text-[28px] font-bold text-[#0B0B0B]" id="stat-collected"><?= formatAmount($total_collected) ?></div>
                <div class="text-xs text-[#666] uppercase tracking-wide">Total Collected</div>
                <div class="text-xs text-[#9A9A9A] mt-2" id="stat-collected-pct">
                    <?= $total_project_value > 0 ? number_format(($total_collected / $total_project_value) * 100, 1) : 0 ?>%
                    of total value
                </div>
            </div>

            <div
                class="bg-white p-6 rounded-xl border border-[#E2E2E2] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)]">
                <div class="w-[50px] h-[50px] bg-[#0B0B0B] rounded-xl flex items-center justify-center text-white text-2xl mb-4">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="text-[28px] font-bold text-[#0B0B0B]" id="stat-balance">
                    <?= formatAmount($total_project_value - $total_collected) ?>
                </div>
                <div class="text-xs text-[#666] uppercase tracking-wide">Outstanding Balance</div>
                <div class="text-xs text-[#9A9A9A] mt-2">Pending collections</div>
            </div>

            <div
                class="bg-white p-6 rounded-xl border border-[#E2E2E2] transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)]">
                <div class="w-[50px] h-[50px] bg-emerald-700 rounded-xl flex items-center justify-center text-white text-2xl mb-4">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="text-[28px] font-bold text-emerald-800"><?= count($finishedClients) ?></div>
                <div class="text-xs text-[#666] uppercase tracking-wide">Finished Projects</div>
                <div class="text-xs text-[#9A9A9A] mt-2">All stages completed</div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="bg-white p-5 rounded-xl mb-5 border border-[#E2E2E2]">
            <!-- Active / Finished tabs -->
            <div class="flex gap-2.5 mb-4 flex-wrap items-center">
                <button id="tabActive" onclick="setTab('active')"
                    class="px-6 py-2.5 rounded-full border-2 border-[#0B0B0B] bg-[#0B0B0B] text-white font-bold text-[13px] cursor-pointer inline-flex items-center gap-2 transition-all">
                    <i class="fas fa-tasks"></i> Active
                    <span class="bg-white/20 rounded-full px-2 py-0.5 text-[11px]"><?= count($clients) ?></span>
                </button>
                <button id="tabFinished" onclick="setTab('finished')"
                    class="px-6 py-2.5 rounded-full border-2 border-[#E2E2E2] bg-white text-[#6B6B6B] font-bold text-[13px] cursor-pointer inline-flex items-center gap-2 transition-all">
                    <i class="fas fa-check-double"></i> Finished
                    <span class="bg-[#E2E2E2] rounded-full px-2 py-0.5 text-[11px]"><?= count($finishedClients) ?></span>
                </button>
                <div class="w-px h-7 bg-[#E2E2E2] mx-1.5"></div>
                <a href="?business_type=all"
                    class="px-6 py-2.5 rounded-full border-2 font-semibold text-sm inline-flex items-center gap-2 transition-all <?= $business_type_filter === 'all' ? 'bg-[#0B0B0B] border-[#0B0B0B] text-white' : 'bg-white border-[#E2E2E2] text-[#4a5568] hover:border-[#0B0B0B] hover:text-[#0B0B0B] hover:-translate-y-0.5' ?>">
                    <i class="fas fa-globe"></i> All
                </a>
                <a href="?business_type=Project"
                    class="px-6 py-2.5 rounded-full border-2 font-semibold text-sm inline-flex items-center gap-2 transition-all <?= $business_type_filter === 'Project' ? 'bg-[#0B0B0B] border-[#0B0B0B] text-white' : 'bg-white border-[#E2E2E2] text-[#4a5568] hover:border-[#0B0B0B] hover:text-[#0B0B0B] hover:-translate-y-0.5' ?>">
                    <i class="fas fa-building"></i> Project
                </a>
                <a href="?business_type=Non-Project"
                    class="px-6 py-2.5 rounded-full border-2 font-semibold text-sm inline-flex items-center gap-2 transition-all <?= $business_type_filter === 'Non-Project' ? 'bg-[#0B0B0B] border-[#0B0B0B] text-white' : 'bg-white border-[#E2E2E2] text-[#4a5568] hover:border-[#0B0B0B] hover:text-[#0B0B0B] hover:-translate-y-0.5' ?>">
                    <i class="fas fa-home"></i> Individual
                </a>
            </div>
            <div class="flex justify-end gap-2.5">
                <button
                    class="toggle-btn bg-white border-2 border-[#E2E2E2] px-3.5 py-2.5 rounded-lg cursor-pointer text-[#6B6B6B] text-base transition-all"
                    id="gridBtn" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button
                    class="toggle-btn active bg-[#0B0B0B] border-2 border-[#0B0B0B] px-3.5 py-2.5 rounded-lg cursor-pointer text-white text-base transition-all"
                    id="listBtn" onclick="setView('list')" title="List View">
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
                    class="bg-amber-50 border-2 border-amber-500 rounded-xl px-6 py-4 mb-6 flex items-center gap-3.5 flex-wrap">
                    <i class="fas fa-bell text-amber-600 text-[22px] flex-shrink-0"></i>
                    <div>
                        <div class="font-bold text-[15px] text-amber-800">
                            You have <?= $totalPending ?> pending approval<?= $totalPending > 1 ? 's' : '' ?> across
                            your projects
                        </div>
                        <div class="text-xs text-amber-700 mt-1">
                            Look for the <strong>bell badge</strong> on each project card below to find which ones
                            need your attention.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Projects Grid -->
            <?php if (empty($clients)): ?>
                <div class="text-center py-16 px-5 bg-white rounded-xl border border-[#E2E2E2]">
                    <i class="fas fa-folder-open text-[64px] text-[#d1d5db] mb-5"></i>
                    <h3 class="text-xl text-[#666] mb-2.5">No Projects Found</h3>
                    <p class="text-[#999]">No projects match the selected filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid grid grid-cols-1 gap-5" id="projectsGrid">
                    <?php foreach ($clients as $client): ?>
                        <div class="client-card bg-white rounded-xl overflow-hidden border-2 border-[#E2E2E2] transition-all cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.3)] hover:border-[#0B0B0B] flex flex-col"
                            onclick="window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'">

                            <div class="card-header p-5 text-white flex-shrink-0 <?= $client['pending_approvals'] > 0 ? 'bg-gradient-to-br from-amber-800 to-amber-600' : 'bg-[#0B0B0B]' ?>">
                                <div class="flex justify-between items-start gap-2.5">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-lg font-semibold truncate"><?= htmlspecialchars($client['clientname']) ?></h3>
                                        <div class="text-xs opacity-90 font-mono flex items-center gap-1 mt-1">
                                            <i class="fas fa-hashtag"></i>
                                            <?= htmlspecialchars($client['reference_number']) ?>
                                        </div>
                                    </div>
                                    <?php if ($client['pending_approvals'] > 0): ?>
                                        <div
                                            class="bg-black/20 border border-white/40 rounded-full px-2.5 py-1 inline-flex items-center gap-1 text-[11px] font-bold whitespace-nowrap flex-shrink-0">
                                            <i class="fas fa-bell flex-shrink-0"></i>
                                            <span><?= $client['pending_approvals'] ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-body p-5 flex flex-col gap-3">
                                <div class="flex justify-between items-center pb-3 border-b border-[#f7fafc]">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Project Name</span>
                                    <span class="text-[15px] text-[#0B0B0B] font-semibold"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>

                                <div class="flex justify-between items-center pb-3 border-b border-[#f7fafc]">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Type</span>
                                    <span
                                        class="px-3.5 py-1.5 rounded-full text-[11px] font-bold uppercase tracking-wide <?= $client['business_type'] === 'Non-Project' ? 'bg-pink-100 text-pink-700' : 'bg-[#0B0B0B] text-white' ?>">
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                </div>

                                <div class="flex justify-between items-center pb-3 border-b border-[#f7fafc]">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Status</span>
                                    <span
                                        class="px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wide <?= $client['status'] === 'New Client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                </div>

                                <div class="flex justify-between items-center">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Sale/Designer Rep.</span>
                                    <span class="text-[15px] text-[#0B0B0B] font-semibold">
                                        <?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?>
                                    </span>
                                </div>

                                <!-- Financial Summary -->
                                <div class="grid grid-cols-3 gap-3.5 pt-3.5 border-t-2 border-[#f7fafc]">
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Total Value</div>
                                        <div class="text-base font-bold text-blue-600">
                                            ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Collected</div>
                                        <div class="text-base font-bold text-emerald-600">
                                            ₱<?= number_format($client['payment_progress']['total_paid_amount'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Balance</div>
                                        <div class="text-base font-bold text-amber-500">
                                            ₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Progress Tracking -->
                                <div class="pt-3.5 border-t-2 border-[#f7fafc] flex flex-col gap-3.5">
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-[#4a5568] font-semibold uppercase tracking-wide">
                                                <i class="fas fa-tasks"></i> Project Completion
                                            </span>
                                            <span class="text-sm font-bold text-[#0B0B0B]">
                                                <?= number_format($client['completion_percentage'], 1) ?>%
                                            </span>
                                        </div>
                                        <div class="h-2 bg-[#E2E2E2] rounded-full overflow-hidden">
                                            <div class="h-full bg-[#0B0B0B] rounded-full transition-all"
                                                style="width: <?= $client['completion_percentage'] ?>%"></div>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-[#4a5568] font-semibold uppercase tracking-wide">
                                                <i class="fas fa-money-check-alt"></i> Payment Progress
                                            </span>
                                            <span class="text-sm font-bold text-emerald-600">
                                                <?= number_format($client['payment_percentage'], 1) ?>%
                                            </span>
                                        </div>
                                        <div class="h-2 bg-[#E2E2E2] rounded-full overflow-hidden">
                                            <div class="h-full bg-emerald-500 rounded-full transition-all"
                                                style="width: <?= $client['payment_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer px-4 py-4 bg-[#f9f9f9] border-t border-[#E2E2E2] flex-shrink-0">
                                <button
                                    class="w-full py-3 px-3 bg-[#0B0B0B] text-white border-none rounded-lg font-bold text-[13px] cursor-pointer transition-all flex items-center justify-center gap-1.5 whitespace-nowrap hover:-translate-y-0.5 hover:shadow-lg"
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
        <div id="finishedGridWrapper" class="hidden">
            <?php if (empty($finishedClients)): ?>
                <div class="text-center py-16 px-5 bg-white rounded-xl border border-[#E2E2E2]">
                    <i class="fas fa-check-double text-[64px] text-[#d1d5db] mb-5"></i>
                    <h3 class="text-xl text-[#666] mb-2.5">No Finished Projects</h3>
                    <p class="text-[#999]">No projects have been marked as finished yet.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid grid grid-cols-1 gap-5" id="finishedProjectsGrid">
                    <?php foreach ($finishedClients as $client): ?>
                        <div class="client-card bg-white rounded-xl overflow-hidden border-2 border-emerald-300 transition-all cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.3)] flex flex-col"
                            onclick="window.location.href='<?= BASE_URL ?>manager-project-detail?client_id=<?= $client['id'] ?>'">
                            <div class="card-header p-5 text-white flex-shrink-0 bg-gradient-to-br from-emerald-800 to-emerald-500">
                                <div class="flex justify-between items-start gap-2.5">
                                    <div>
                                        <h3 class="text-lg font-semibold"><?= htmlspecialchars($client['clientname']) ?></h3>
                                        <div class="text-xs opacity-90 font-mono flex items-center gap-1 mt-1">
                                            <i class="fas fa-hashtag"></i> <?= htmlspecialchars($client['reference_number']) ?>
                                        </div>
                                    </div>
                                    <div
                                        class="bg-white/20 border border-white/40 rounded-full px-2.5 py-1 text-[11px] font-bold inline-flex items-center gap-1 flex-shrink-0">
                                        <i class="fas fa-check-double"></i> Finished
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-5 flex flex-col gap-3">
                                <div class="flex justify-between items-center pb-3 border-b border-[#f7fafc]">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Project Name</span>
                                    <span class="text-[15px] text-[#0B0B0B] font-semibold"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>
                                <div class="flex justify-between items-center pb-3 border-b border-[#f7fafc]">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Type</span>
                                    <span
                                        class="px-3.5 py-1.5 rounded-full text-[11px] font-bold uppercase tracking-wide <?= $client['business_type'] === 'Non-Project' ? 'bg-pink-100 text-pink-700' : 'bg-[#0B0B0B] text-white' ?>">
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-[13px] text-[#718096] font-semibold uppercase tracking-wide">Project Manager</span>
                                    <span
                                        class="text-[15px] text-[#0B0B0B] font-semibold"><?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?></span>
                                </div>
                                <div class="grid grid-cols-3 gap-3.5 pt-3.5 border-t-2 border-[#f7fafc]">
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Total Value</div>
                                        <div class="text-base font-bold text-blue-600">
                                            ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?></div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Collected</div>
                                        <div class="text-base font-bold text-emerald-600">
                                            ₱<?= number_format($client['payment_progress']['total_paid_amount'] ?? 0, 0) ?>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-[10px] text-[#718096] uppercase tracking-wide mb-1">Balance</div>
                                        <div class="text-base font-bold text-amber-500">
                                            ₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?></div>
                                    </div>
                                </div>
                                <div class="pt-3.5 border-t-2 border-[#f7fafc] flex flex-col gap-3.5">
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-[#4a5568] font-semibold uppercase tracking-wide"><i
                                                    class="fas fa-tasks"></i> Project Completion</span>
                                            <span
                                                class="text-sm font-bold text-emerald-600"><?= number_format($client['completion_percentage'], 1) ?>%</span>
                                        </div>
                                        <div class="h-2 bg-[#E2E2E2] rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-emerald-800 to-emerald-500 rounded-full transition-all"
                                                style="width:<?= $client['completion_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-[#4a5568] font-semibold uppercase tracking-wide"><i
                                                    class="fas fa-money-check-alt"></i> Payment Progress</span>
                                            <span
                                                class="text-sm font-bold text-emerald-600"><?= number_format($client['payment_percentage'], 1) ?>%</span>
                                        </div>
                                        <div class="h-2 bg-[#E2E2E2] rounded-full overflow-hidden">
                                            <div class="h-full bg-emerald-500 rounded-full transition-all"
                                                style="width:<?= $client['payment_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer px-4 py-4 bg-[#f9f9f9] border-t border-emerald-200 flex-shrink-0">
                                <button
                                    class="w-full py-3 px-3 bg-gradient-to-br from-emerald-800 to-emerald-500 text-white border-none rounded-lg font-bold text-[13px] cursor-pointer transition-all flex items-center justify-center gap-1.5 whitespace-nowrap hover:-translate-y-0.5 hover:shadow-lg"
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

    <style>
        /* List view overrides — kept as plain CSS since Tailwind's arbitrary
           selectors can't easily express ":not(.list-view) .foo" combinators.
           Everything else on this page is Tailwind utility classes. */
        .clients-grid.list-view {
            display: flex !important;
            flex-direction: column !important;
        }

        .clients-grid.list-view .client-card {
            flex-direction: row !important;
            align-items: stretch;
        }

        .clients-grid.list-view .card-header {
            min-width: 300px;
            max-width: 300px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .clients-grid.list-view .card-body {
            flex: 1;
            flex-direction: row !important;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 20px;
        }

        .clients-grid.list-view .card-body>div:not(.grid):not(.border-t-2) {
            flex: 1 1 180px;
            border-bottom: none !important;
            padding-bottom: 0 !important;
        }

        .clients-grid.list-view .card-body .grid.grid-cols-3 {
            flex: 1 1 250px;
            border-top: none !important;
            padding-top: 0 !important;
        }

        .clients-grid.list-view .card-body>div.border-t-2:last-of-type {
            flex: 1 1 300px;
            border-top: none !important;
            padding-top: 0 !important;
        }

        .clients-grid.list-view .card-footer {
            min-width: 230px;
            max-width: 230px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-top: none;
            border-left: 1px solid #E2E2E2;
        }

        @media (max-width: 768px) {
            .clients-grid.list-view .client-card {
                flex-direction: column !important;
            }

            .clients-grid.list-view .card-header {
                min-width: unset !important;
                max-width: unset !important;
            }

            .clients-grid.list-view .card-footer {
                min-width: unset !important;
                max-width: unset !important;
                border-left: none;
                border-top: 1px solid #E2E2E2;
            }
        }
    </style>

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

        const TAB_ACTIVE_ON = ['bg-[#0B0B0B]', 'border-[#0B0B0B]', 'text-white'];
        const TAB_ACTIVE_OFF = ['bg-white', 'border-[#E2E2E2]', 'text-[#6B6B6B]'];
        const TAB_FINISHED_ON = ['bg-gradient-to-br', 'from-emerald-800', 'to-emerald-500', 'border-emerald-800', 'text-white'];
        const TAB_FINISHED_OFF = ['bg-white', 'border-[#E2E2E2]', 'text-[#6B6B6B]'];

        function setTab(tab) {
            currentTab = tab;
            const finishedWrapper = document.getElementById('finishedGridWrapper');
            const tabActive = document.getElementById('tabActive');
            const tabFinished = document.getElementById('tabFinished');

            document.querySelectorAll('.active-content').forEach(el => {
                el.style.display = tab === 'active' ? '' : 'none';
            });
            if (finishedWrapper) finishedWrapper.classList.toggle('hidden', tab !== 'finished');

            if (tab === 'active') {
                tabActive.classList.remove(...TAB_ACTIVE_OFF);
                tabActive.classList.add(...TAB_ACTIVE_ON);
                tabFinished.classList.remove(...TAB_FINISHED_ON);
                tabFinished.classList.add(...TAB_FINISHED_OFF);
            } else {
                tabFinished.classList.remove(...TAB_FINISHED_OFF);
                tabFinished.classList.add(...TAB_FINISHED_ON);
                tabActive.classList.remove(...TAB_ACTIVE_ON);
                tabActive.classList.add(...TAB_ACTIVE_OFF);
            }

            updateStats(tab);
        }

        const TOGGLE_ON = ['bg-[#0B0B0B]', 'border-[#0B0B0B]', 'text-white'];
        const TOGGLE_OFF = ['bg-white', 'border-[#E2E2E2]', 'text-[#6B6B6B]'];

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
                listBtn.classList.remove(...TOGGLE_OFF);
                listBtn.classList.add(...TOGGLE_ON);
                gridBtn.classList.remove(...TOGGLE_ON);
                gridBtn.classList.add(...TOGGLE_OFF);
            } else {
                gridBtn.classList.remove(...TOGGLE_OFF);
                gridBtn.classList.add(...TOGGLE_ON);
                listBtn.classList.remove(...TOGGLE_ON);
                listBtn.classList.add(...TOGGLE_OFF);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            setView('list');
            setTab('active');
        });
    </script>
</body>

</html>