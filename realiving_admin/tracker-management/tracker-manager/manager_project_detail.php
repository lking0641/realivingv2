<?php
// manager_project_detail.php
include $includes ['mainbody'];

$allowedRoles = ['general_manager', 'operational_manager', 'superadmin', 'sales'];

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];
$isHead = (bool) ($userInfo['is_head'] ?? false);

if (!in_array($admin_role, $allowedRoles)) {
    die("Access Denied.");
}

// Fetch client
$cStmt = $conn->prepare("
    SELECT u.*, a.full_name as admin_name, a.role as admin_role
    FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.id = ?
");
$cStmt->bind_param("i", $client_id);
$cStmt->execute();
$client = $cStmt->get_result()->fetch_assoc();
if (!$client)
    die("Client not found.");

$business_type_label = ($client['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($client['business_type'] ?? '');
$current_revision = $client['revision_count'] ?? 0;
$isNonProject = ($client['business_type'] ?? '') === 'Non-Project';

// Fetch tracker stages
$tStmt = $conn->prepare("
    SELECT pt.*, a.full_name as updated_by_name
    FROM project_tracker pt LEFT JOIN account a ON pt.updated_by = a.id
    WHERE pt.client_id = ?
");
$tStmt->bind_param("i", $client_id);
$tStmt->execute();
$tRes = $tStmt->get_result();
$trackerData = [];
while ($row = $tRes->fetch_assoc()) {
    $row['assigned_people'] = [];
    $aS = $conn->prepare("SELECT assigned_to FROM stage_assignments WHERE stage_id = ? ORDER BY assigned_at");
    $aS->bind_param("i", $row['id']);
    $aS->execute();
    $aR = $aS->get_result();
    while ($ar = $aR->fetch_assoc())
        $row['assigned_people'][] = $ar['assigned_to'];
    $trackerData[$row['stage_name']] = $row;
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

// Progress counts
$pending_count = $ongoing_count = $done_count = 0;
foreach ($trackerData as $d) {
    if ($d['status'] === 'Pending')
        $pending_count++;
    elseif ($d['status'] === 'Ongoing')
        $ongoing_count++;
    elseif ($d['status'] === 'Done')
        $done_count++;
}
$completion_pct = (count($stages) > 0) ? ($done_count / count($stages)) * 100 : 0;

// Payments
$pStmt = $conn->prepare("SELECT * FROM payment_schedule WHERE client_id = ? ORDER BY id");
$pStmt->bind_param("i", $client_id);
$pStmt->execute();
$payments = $pStmt->get_result();
$total_paid = 0;
$payments->data_seek(0);
while ($p = $payments->fetch_assoc()) {
    if ($p['status'] === 'Paid')
        $total_paid += $p['amount'];
}
$pay_pct = ($client['total_project_cost'] > 0) ? ($total_paid / $client['total_project_cost']) * 100 : 0;

// Stage classification
$approvalStages = [
    'Rough Estimation',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
];

// Remove stages not applicable for Non-Project (Individual) clients
if ($isNonProject) {
    $approvalStages = array_values(array_filter($approvalStages, function ($s) {
        return $s !== 'Samples Submitted TDS/SDS';
    }));
}
$approvalStageRoles = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];
$requiredApproversList = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];
$fileUploadStages = ['Reference', 'Internal P.O to Accounting', 'Handover'];
$autoStages = ['Fabrication', 'Delivery', 'Installation', 'BILLING', 'Downpayment', 'Cuttinglist', 'Production Data Submittals'];

// ── Pending layout approvals for this approver ───────────────────────────

function getStagePendingApprovalCount($conn, $admin_id, $admin_role, $is_head, $stage_id)
{
    if (!$stage_id)
        return 0;
    $approvalStageRoles = [
        'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
        'Samples Submitted TDS/SDS' => ['designer', 'general_manager', 'technical_designer', 'operational_manager'],
        'Quotation' => ['designer', 'general_manager', 'operational_manager'],
        'Bill of Materials (BOM)' => ['operational_manager', 'general_manager', 'technical_designer'],
        'Purchase Order (Submit to accounting)' => ['operational_manager', 'general_manager', 'accounting'],
    ];
    $stStmt = $conn->prepare("SELECT stage_name FROM project_tracker WHERE id = ?");
    $stStmt->bind_param("i", $stage_id);
    $stStmt->execute();
    $stRow = $stStmt->get_result()->fetch_assoc();
    if (!$stRow)
        return 0;
    $stageName = $stRow['stage_name'];
    $rolesForStage = $approvalStageRoles[$stageName] ?? [];
    $canApprove = false;

    if ($admin_role === 'technical_designer') {
        if (in_array('technical_designer', $rolesForStage) && $is_head)
            $canApprove = true;
    } elseif ($admin_role === 'designer') {
        if (in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $is_head)
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

    $allGmOmStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
    $step1Map = [
        'Rough Estimation' => ['designer'],
        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
        'Quotation' => ['designer'],
        'Bill of Materials (BOM)' => ['technical_designer'],
        'Purchase Order (Submit to accounting)' => ['accounting'],
    ];

    if (in_array($stageName, $allGmOmStages) && in_array($admin_role, ['general_manager', 'operational_manager'])) {
        $otherRole = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
        $step1Roles = $step1Map[$stageName] ?? [];

        // Only notify GM/OM if all step1 roles have approved
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
              {$step1Clauses}
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

function getRoleDisplayName($role)
{
    $n = ['general_manager' => 'General Manager', 'operational_manager' => 'Operational Manager', 'technical_designer' => 'Technical Designer (Head)', 'designer' => 'Designer (Head)', 'accounting' => 'Accounting'];
    return $n[$role] ?? ucwords(str_replace('_', ' ', $role));
}
function canApprove($stageName, $adminRole, $isHead, $approvalStageRoles)
{
    $allowed = $approvalStageRoles[$stageName] ?? [];
    if ($adminRole === 'technical_designer')
        return in_array('technical_designer', $allowed) && $isHead;
    if ($adminRole === 'designer')
        return in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $isHead;
    if ($adminRole === 'accounting')
        return $stageName === 'Purchase Order (Submit to accounting)';
    return in_array($adminRole, $allowed);
}
function getStageIcon($stage)
{
    $m = [
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
    return $m[$stage] ?? 'fa-circle';
}
function getFileCount($conn, $stageId)
{
    $s = $conn->prepare("SELECT COUNT(*) AS cnt FROM stage_approvals WHERE stage_id = ?");
    $s->bind_param("i", $stageId);
    $s->execute();
    return $s->get_result()->fetch_assoc()['cnt'] ?? 0;
}

// Fetch latest approval file per approval stage (for summary preview)
$approvalPreviews = [];
foreach ($approvalStages as $aSN) {
    $sRow = $trackerData[$aSN] ?? null;
    if (!$sRow)
        continue;
    $afS = $conn->prepare("
        SELECT sa.*, a1.full_name as uploaded_by_name
        FROM stage_approvals sa LEFT JOIN account a1 ON sa.uploaded_by = a1.id
        WHERE sa.stage_id = ? ORDER BY sa.uploaded_at DESC LIMIT 1
    ");
    $afS->bind_param("i", $sRow['id']);
    $afS->execute();
    $afRow = $afS->get_result()->fetch_assoc();
    if (!$afRow) {
        $approvalPreviews[$aSN] = null;
        continue;
    }
    $rS = $conn->prepare("SELECT sar.reviewer_role, sar.review_status FROM stage_approval_reviews sar WHERE sar.approval_id = ?");
    $rS->bind_param("i", $afRow['id']);
    $rS->execute();
    $rR = $rS->get_result();
    $rr = [];
    while ($rev = $rR->fetch_assoc())
        $rr[$rev['reviewer_role']] = $rev['review_status'];
    $afRow['role_reviews'] = $rr;
    $approvalPreviews[$aSN] = $afRow;
}

// Pending approval counts
$pendingApprovalCounts = [];
foreach ($approvalStages as $aSN) {
    $sRow = $trackerData[$aSN] ?? null;
    if (!$sRow) {
        $pendingApprovalCounts[$aSN] = 0;
        continue;
    }
    $cS = $conn->prepare("SELECT COUNT(*) AS cnt FROM stage_approvals WHERE stage_id = ? AND approval_status = 'pending'");
    $cS->bind_param("i", $sRow['id']);
    $cS->execute();
    $pendingApprovalCounts[$aSN] = $cS->get_result()->fetch_assoc()['cnt'] ?? 0;
}

// Designers
$dS = $conn->prepare("SELECT a1.full_name as d1, a2.full_name as d2 FROM user_info ui LEFT JOIN account a1 ON ui.designer1_id=a1.id LEFT JOIN account a2 ON ui.designer2_id=a2.id WHERE ui.id=?");
$dS->bind_param("i", $client_id);
$dS->execute();
$dRow = $dS->get_result()->fetch_assoc();
$designer1 = $dRow['d1'] ?? null;
$designer2 = $dRow['d2'] ?? null;

// Total pending approvals needing THIS manager's action (excluding already reviewed)
$myPendingTotal = 0;
$gmOmSequentialAll = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
foreach ($approvalStages as $aSN) {
    if (canApprove($aSN, $admin_role, $isHead, $approvalStageRoles)) {
        $sRow = $trackerData[$aSN] ?? null;
        if (!$sRow)
            continue;
        $stageId = $sRow['id'];

        if (in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($aSN, $gmOmSequentialAll)) {
            // Step 1 roles that must approve before GM/OM is notified
            $step1Map = [
                'Rough Estimation' => ['designer'],
                'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
                'Quotation' => ['designer'],
                'Bill of Materials (BOM)' => ['technical_designer'],
                'Purchase Order (Submit to accounting)' => ['accounting'],
            ];
            $step1Roles = $step1Map[$aSN] ?? [];
            $otherRole = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';

            // Build EXISTS clauses for each step1 role
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

            $countStmt = $conn->prepare("
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
                  {$step1Clauses}
            ");
            $countStmt->bind_param("iss", $stageId, $admin_role, $otherRole);
        } else {
            $countStmt = $conn->prepare("
                SELECT COUNT(*) FROM stage_approvals sa
                WHERE sa.stage_id = ?
                  AND sa.approval_status = 'pending'
                  AND NOT EXISTS (
                      SELECT 1 FROM stage_approval_reviews sar
                      WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
                  )
            ");
            $countStmt->bind_param("is", $stageId, $admin_role);
        }
        $countStmt->execute();
        $myPendingTotal += (int) $countStmt->get_result()->fetch_row()[0];
    }
}

// 2D/3D Layout pending approvals (layout_approvals table)
$layoutPendingCount = getLayoutPendingCount($conn, $admin_id, $client_id);

// Site Visit pending approvals
$siteVisitPendingCount = 0;
if (in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin'])) {
    $svStmt = $conn->prepare("
        SELECT COUNT(*) FROM site_visit 
        WHERE client_id = ? AND approval_status = 'Pending'
    ");
    $svStmt->bind_param("i", $client_id);
    $svStmt->execute();
    $siteVisitPendingCount = (int) $svStmt->get_result()->fetch_row()[0];
    $myPendingTotal += $siteVisitPendingCount;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Dashboard — <?= htmlspecialchars($client['clientname']) ?></title>
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

        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        /* Back */
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

        /* ── Client hero card ── */
        .hero {
            background: var(--brown-dk);
            border-radius: 16px;
            padding: 30px 32px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top right, rgba(196, 154, 120, .22) 0%, transparent 60%);
            pointer-events: none;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 14px;
            position: relative;
            z-index: 1;
        }

        .hero-name {
            font-size: 21px;
            font-weight: 700;
            letter-spacing: -.3px;
            margin-bottom: 3px;
        }

        .hero-project {
            font-size: 13px;
            opacity: .7;
        }

        .viewer-chip {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .role-pill {
            background: rgba(255, 255, 255, .15);
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 10px;
            text-transform: capitalize;
            letter-spacing: .3px;
        }

        .hero-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            position: relative;
            z-index: 1;
        }

        .hpill {
            background: rgba(255, 255, 255, .09);
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 8px;
            padding: 8px 14px;
        }

        .hpill-label {
            font-size: 9px;
            opacity: .55;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 3px;
        }

        .hpill-value {
            font-size: 13px;
            font-weight: 600;
        }

        /* ── Action banner (pending approvals) ── */
        .action-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius);
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .action-banner i {
            color: #f59e0b;
            font-size: 18px;
            flex-shrink: 0;
        }

        .action-banner-text {
            font-size: 13px;
            font-weight: 600;
            color: #92400e;
        }

        .action-banner-text span {
            font-size: 12px;
            font-weight: 400;
            color: #b45309;
            display: block;
            margin-top: 2px;
        }

        /* ── Stats row ── */
        .stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            box-shadow: var(--shadow);
        }

        .stat-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-lt);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-pct {
            font-size: 26px;
            font-weight: 700;
            color: var(--brown-dk);
            font-family: 'DM Mono', monospace;
        }

        .prog-track {
            height: 6px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
            margin: 10px 0 12px;
        }

        .prog-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .6s ease;
        }

        .fill-brown {
            background: linear-gradient(90deg, var(--brown-dk), var(--brown-lt));
        }

        .fill-green {
            background: linear-gradient(90deg, #059669, #10b981);
        }

        .mini-stats {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .mini-stat {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-md);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .dot-p {
            background: var(--pending);
        }

        .dot-o {
            background: var(--ongoing);
        }

        .dot-d {
            background: var(--done);
        }

        .dot-g {
            background: #10b981;
        }

        .dot-a {
            background: #f59e0b;
        }

        /* ── Section header ── */
        .sec-hdr {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .sec-hdr h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--brown-dk);
            white-space: nowrap;
        }

        .sec-hdr::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
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
            background: linear-gradient(to bottom, var(--brown-lt), var(--border));
            border-radius: 2px;
        }

        .tl-row {
            display: flex;
            gap: 16px;
            margin-bottom: 10px;
            position: relative;
        }

        .tl-node {
            flex-shrink: 0;
            width: 56px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 10px;
            position: relative;
            z-index: 1;
        }

        .node {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            border: 2px solid var(--border);
            background: var(--surface);
            flex-shrink: 0;
        }

        .node.pending {
            background: #fffbeb;
            border-color: var(--pending);
            color: var(--pending);
        }

        .node.ongoing {
            background: #eff6ff;
            border-color: var(--ongoing);
            color: var(--ongoing);
        }

        .node.done {
            background: #f0fdf4;
            border-color: var(--done);
            color: var(--done);
        }

        /* ── Stage card ── */
        .tl-card {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow);
            transition: box-shadow .2s;
        }

        .tl-card:hover {
            box-shadow: 0 4px 20px rgba(59, 31, 15, .11);
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

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 7px;
        }

        .card-left {
            display: flex;
            align-items: center;
            gap: 9px;
            flex: 1;
            min-width: 0;
            flex-wrap: wrap;
        }

        .snum {
            font-family: 'DM Mono', monospace;
            font-size: 10px;
            color: var(--text-lt);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 2px 6px;
            flex-shrink: 0;
        }

        .sname {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dk);
            line-height: 1.3;
        }

        .card-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        /* status badge */
        .sbadge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .sbadge.pending {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .sbadge.ongoing {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .sbadge.done {
            background: #f0fdf4;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        /* type badge */
        .tbadge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .tb-approval {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .tb-upload {
            background: #ede9fe;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        .tb-auto {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        /* card meta */
        .cmeta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 11px;
            color: var(--text-lt);
            margin-top: 5px;
        }

        .cmeta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* assigned chips */
        .chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 7px;
        }

        .chip {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 9px;
            font-size: 11px;
            color: var(--text-md);
            font-weight: 500;
        }

        /* approval preview inside card */
        .ap-preview {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        .ap-preview-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-lt);
            margin-bottom: 5px;
        }

        .apbadge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .apbadge.approved {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .apbadge.rejected {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .apbadge.pending {
            background: #f3f4f6;
            color: #9ca3af;
            border: 1px solid #e5e7eb;
        }

        /* file chip link */
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

        .file-chip .needs-review {
            background: #f59e0b;
            color: #fff;
            border-radius: 99px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
        }

        /* open button */
        .btn-open {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            background: var(--brown-dk);
            color: #fff;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all .2s;
        }

        .btn-open:hover {
            background: var(--brown-md);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(59, 31, 15, .22);
        }

        /* ── Payment cards ── */
        .pay-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 15px 18px;
            margin-bottom: 10px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            transition: box-shadow .2s;
        }

        .pay-card:hover {
            box-shadow: 0 4px 16px rgba(59, 31, 15, .1);
        }

        .pay-card.paid {
            border-left: 3px solid var(--done);
        }

        .pay-card.pending {
            border-left: 3px solid var(--pending);
        }

        .pay-type {
            font-size: 14px;
            font-weight: 600;
        }

        .pay-pct {
            font-size: 11px;
            color: var(--text-lt);
            margin-top: 2px;
        }

        .pay-date {
            font-size: 11px;
            color: var(--text-lt);
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pay-amt {
            font-size: 17px;
            font-weight: 700;
            color: var(--brown-dk);
            font-family: 'DM Mono', monospace;
            flex-shrink: 0;
        }

        .pstatus {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .pstatus.paid {
            background: #f0fdf4;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .pstatus.pending {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
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

        @media(max-width:640px) {
            .stats-row {
                grid-template-columns: 1fr;
            }

            .timeline::before {
                left: 19px;
            }

            .tl-node {
                width: 40px;
            }

            .node {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }
        }
    </style>
</head>

<body>
    <div class="page">

        <a href="manager-status-tracker" class="back-link"><i class="fas fa-arrow-left"></i> Back to Status
            Tracker</a>

        <!-- Hero -->
        <div class="hero">
            <div class="hero-top">
                <div>
                    <div class="hero-name"><?= htmlspecialchars($client['clientname']) ?></div>
                    <div class="hero-project"><?= htmlspecialchars($client['nameproject']) ?></div>
                </div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                    <button onclick="document.getElementById('clientDetailModal').classList.add('open')" style="background:white; color:#3b1f0f; padding:8px 16px; border:none; border-radius:8px;
           cursor:pointer; font-weight:700; font-size:13px; display:inline-flex;
           align-items:center; gap:7px; transition:all 0.2s; flex-shrink:0;">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div class="viewer-chip">
                        <i class="fas fa-user-shield"></i>
                        <?= htmlspecialchars($userInfo['full_name']) ?>
                        <span class="role-pill"><?= str_replace('_', ' ', $admin_role) ?></span>
                    </div>
                </div><!-- /viewer wrapper -->
            </div><!-- closes hero-top -->
            <div class="hero-pills">
                <div class="hpill">
                    <div class="hpill-label">Reference</div>
                    <div class="hpill-value"><?= htmlspecialchars($client['reference_number']) ?></div>
                </div>
                <div class="hpill">
                    <div class="hpill-label">Type</div>
                    <div class="hpill-value"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <div class="hpill">
                    <div class="hpill-label">Assigned To</div>
                    <div class="hpill-value"><?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?></div>
                </div>
                <div class="hpill">
                    <div class="hpill-label">Total Cost</div>
                    <div class="hpill-value">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Action banner — approval stages -->
        <?php if ($myPendingTotal > 0): ?>
            <div class="action-banner">
                <i class="fas fa-exclamation-circle"></i>
                <div class="action-banner-text">
                    You have <?= $myPendingTotal ?> file<?= $myPendingTotal !== 1 ? 's' : '' ?> waiting for your approval.
                    <span>Click the <strong>"Files"</strong> chip on the relevant stage below to review and approve or
                        reject.</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action banner — 2D/3D Layout pending -->
        <?php if ($layoutPendingCount > 0): ?>
            <div
                style="background:#fef3c7; border:1px solid #fde68a; border-radius:var(--radius); padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-bell" style="color:#d97706; font-size:18px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:#92400e;">
                            <?= $layoutPendingCount ?> pending 2D/3D Layout
                            approval<?= $layoutPendingCount > 1 ? 's' : '' ?> waiting for your review
                        </div>
                        <div style="font-size:12px; color:#b45309; margin-top:2px;">
                            The designer has requested your approval on layout attachments.
                        </div>
                    </div>
                </div>
                <a href="designer-2d3d-layout?client_id=<?= $client_id ?>&back=manager_detail"
                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:8px 16px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
                    <i class="fas fa-arrow-right"></i> Go to 2D/3D Layout
                </a>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-tasks"></i> Project Completion</div>
                <div class="stat-pct"><?= number_format($completion_pct, 1) ?>%</div>
                <div class="prog-track">
                    <div class="prog-fill fill-brown" style="width:<?= $completion_pct ?>%"></div>
                </div>
                <div class="mini-stats">
                    <span class="mini-stat"><span class="dot dot-p"></span><?= $pending_count ?> Pending</span>
                    <span class="mini-stat"><span class="dot dot-o"></span><?= $ongoing_count ?> Ongoing</span>
                    <span class="mini-stat"><span class="dot dot-d"></span><?= $done_count ?> Done</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-money-bill-wave"></i> Payment Progress</div>
                <div class="stat-pct"><?= number_format($pay_pct, 1) ?>%</div>
                <div class="prog-track">
                    <div class="prog-fill fill-green" style="width:<?= $pay_pct ?>%"></div>
                </div>
                <div class="mini-stats">
                    <span class="mini-stat"><span class="dot dot-g"></span>₱<?= number_format($total_paid, 0) ?>
                        Collected</span>
                    <span class="mini-stat"><span
                            class="dot dot-a"></span>₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?>
                        Balance</span>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="sec-hdr">
            <h2><i class="fas fa-stream"></i> Project Stages</h2>
        </div>
        <div class="timeline">
            <?php foreach ($stages as $idx => $stage):
                $stageData = $trackerData[$stage] ?? null;
                $isApproval = in_array($stage, $approvalStages);
                $isFileUpload = in_array($stage, $fileUploadStages);
                $isAuto = in_array($stage, $autoStages);
                $isAccounting = ($stage === 'Accounting (Order Processing)');
                $updated_at = $stageData['updated_at'] ?? null;
                $updatedBy = $stageData['updated_by_name'] ?? null;
                $assigned = $stageData['assigned_people'] ?? [];
                $status = $stageData ? $stageData['status'] : 'Pending';

                // Auto-tracked overrides
                if ($stage === 'Downpayment') {
                    $dpS = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id=? AND payment_type LIKE '%Down%' LIMIT 1");
                    $dpS->bind_param("i", $client_id);
                    $dpS->execute();
                    $dpR = $dpS->get_result()->fetch_assoc();
                    $status = ($dpR && $dpR['status'] === 'Paid') ? 'Done' : 'Pending';
                    $dpPct = ($client['business_type'] === 'Non-Project') ? 50 : 30;
                    $dpAmt = ($client['total_project_cost'] ?? 0) * ($dpPct / 100);
                } elseif ($stage === 'BILLING') {
                    $bS = $conn->prepare("SELECT COUNT(*) AS total,SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id=? AND payment_type NOT LIKE '%Down Payment%'");
                    $bS->bind_param("i", $client_id);
                    $bS->execute();
                    $bR = $bS->get_result()->fetch_assoc();
                    $dpC = $conn->prepare("SELECT COUNT(*) AS dp FROM payment_schedule WHERE client_id=? AND payment_type LIKE '%Down Payment%' AND status='Paid'");
                    $dpC->bind_param("i", $client_id);
                    $dpC->execute();
                    $dpPaid = $dpC->get_result()->fetch_assoc()['dp'] > 0;
                    $hasCollections = $bR['total'] > 0;
                    $allCollectionsPaid = $hasCollections && $bR['paid'] == $bR['total'];
                    // For Project type: also require installation to be 100% complete before marking Done
                    $instAllDone = true;
                    if (($client['business_type'] ?? '') === 'Project') {
                        $instStmt = $conn->prepare("SELECT CASE WHEN COUNT(*)=0 THEN 0 WHEN COUNT(*)=SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END) THEN 1 ELSE 0 END AS all_done FROM (SELECT installation_status FROM quotation_entries WHERE client_id=? UNION ALL SELECT installation_status FROM quotation_fixed_sizes WHERE client_id=?) x");
                        $instStmt->bind_param("ii", $client_id, $client_id);
                        $instStmt->execute();
                        $instAllDone = (bool) ($instStmt->get_result()->fetch_assoc()['all_done'] ?? false);
                    }
                    if ($allCollectionsPaid && $instAllDone)
                        $status = 'Done';
                    elseif ($bR['paid'] > 0 || $dpPaid)
                        $status = 'Ongoing';
                    else
                        $status = 'Pending';
                } elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation'])) {
                    $col = strtolower($stage) . '_status';
                    $iS = $conn->prepare("SELECT CASE WHEN COUNT(*)=0 THEN 'Pending' WHEN COUNT(*)=SUM(CASE WHEN $col='Done' THEN 1 ELSE 0 END) THEN 'Done' WHEN SUM(CASE WHEN $col IN('Ongoing','Incomplete','Punchlist') THEN 1 ELSE 0 END)>0 THEN 'Ongoing' ELSE 'Pending' END AS s FROM (SELECT $col FROM quotation_entries WHERE client_id=? UNION ALL SELECT $col FROM quotation_fixed_sizes WHERE client_id=?) x");
                    $iS->bind_param("ii", $client_id, $client_id);
                    $iS->execute();
                    $status = $iS->get_result()->fetch_assoc()['s'] ?? 'Pending';
                }

                $sc = strtolower($status);
                $icon = getStageIcon($stage);
                $preview = $isApproval ? ($approvalPreviews[$stage] ?? null) : null;
                // For GM/OM: only count files where step1 is done AND other GM/OM hasn't already approved
                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && isset($trackerData[$stage])) {
                    $gmOmPreviewStages2 = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                    $step1MapInline = [
                        'Rough Estimation' => ['designer'],
                        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
                        'Quotation' => ['designer'],
                        'Bill of Materials (BOM)' => ['technical_designer'],
                        'Purchase Order (Submit to accounting)' => ['accounting'],
                        'Production Data Submittals' => ['technical_designer'],
                    ];
                    if (in_array($stage, $gmOmPreviewStages2)) {
                        $otherRoleInline = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
                        $s1RolesInline = $step1MapInline[$stage] ?? [];
                        $s1ClausesInline = '';
                        foreach ($s1RolesInline as $s1ri) {
                            $s1ClausesInline .= "
                  AND EXISTS (
                      SELECT 1 FROM stage_approval_reviews sar_s1
                      WHERE sar_s1.approval_id = sa.id
                        AND sar_s1.reviewer_role = '{$s1ri}'
                        AND sar_s1.review_status = 'approved'
                  )";
                        }
                        $pfc = $conn->prepare("
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
                  {$s1ClausesInline}
            ");
                        $pfcStageId = $stageData['id'] ?? 0;
                        $pfc->bind_param("iss", $pfcStageId, $admin_role, $otherRoleInline);
                        $pfc->execute();
                        $pendingFiles = (int) $pfc->get_result()->fetch_row()[0];
                    } else {
                        $pendingFiles = $isApproval ? ($pendingApprovalCounts[$stage] ?? 0) : 0;
                    }
                } else {
                    $pendingFiles = $isApproval ? ($pendingApprovalCounts[$stage] ?? 0) : 0;
                }
                $canApproveThis = $isApproval ? canApprove($stage, $admin_role, $isHead, $approvalStageRoles) : false;
                $fileCount = ($stageData && ($isApproval || $isFileUpload || $isAccounting)) ? getFileCount($conn, $stageData['id']) : 0;
                $filesLink = "manager-stage-files?client_id={$client_id}&stage_id=" . ($stageData['id'] ?? 0) . "&stage=" . urlencode($stage);

                // Navigation links
                $openLink = null;
                if ($stage === '2D / 3D Layout')
                    $openLink = BASE_URL . "designer-2d3d-layout?client_id={$client_id}&back=manager_detail";
                elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation']))
                    $openLink = BASE_URL . "item-tracker?client_id={$client_id}&stage=" . urlencode($stage) . "&view_only=1&came_from=manager";
                elseif ($stage === 'BILLING' || $stage === 'Downpayment')
                    $openLink = BASE_URL . "payment-tracker?client_id={$client_id}&view_only=1";
                ?>
                <div class="tl-row">
                    <div class="tl-node">
                        <div class="node <?= $sc ?>"><i class="fas <?= $icon ?>"></i></div>
                    </div>
                    <div class="tl-card <?= $sc ?>" <?= ($stage === '2D / 3D Layout' && $layoutPendingCount > 0) ? 'style="border-color:#f59e0b; box-shadow:0 0 0 2px #fcd34d55;"' : '' ?>     <?= ($stage === 'Site Visit' && $siteVisitPendingCount > 0 && in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin'])) ? 'style="border-color:#f59e0b; box-shadow:0 0 0 2px #fcd34d55;"' : '' ?>>

                        <!-- Top -->
                        <div class="card-top">
                            <div class="card-left">
                                <span class="snum"><?= str_pad($idx + 1, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="sname"><?= htmlspecialchars($stage) ?></span>
                                <?php if ($stage === '2D / 3D Layout'): ?>
                                    <span
                                        style="background:var(--bg);border:1px solid var(--border);border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600;color:var(--text-md);">
                                        <i class="fas fa-sync-alt"></i> Rev <?= $current_revision ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-right">
                                <span class="sbadge <?= $sc ?>">
                                    <?php if ($status === 'Done'): ?><i
                                            class="fas fa-check"></i><?php elseif ($status === 'Ongoing'): ?><i
                                            class="fas fa-circle-notch fa-spin"></i><?php else: ?><i
                                            class="fas fa-clock"></i><?php endif; ?>
                                    <?= $status ?>
                                </span>
                                <?php if ($openLink): ?>
                                    <a href="<?= $openLink ?>" class="btn-open"><i class="fas fa-arrow-right"></i> Open</a>
                                <?php endif; ?>
                                <?php if ($stage === 'Site Visit'): ?>
                                    <a href="manager-site-visit-approval?client_id=<?= $client_id ?>"
                                        class="btn-open">
                                        <i class="fas fa-clipboard-check"></i> Review
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Type badges -->
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:7px;">
                            <?php if ($stage === 'Production Data Submittals'): ?>
                                <span class="tbadge tb-auto"><i class="fas fa-bolt"></i> Auto-Tracked</span>
                            <?php endif; ?>
                            <?php if ($isApproval): ?>
                                <span class="tbadge tb-approval">
                                    <i class="fas fa-stamp"></i> Approval Required
                                    <?php if ($pendingFiles > 0): ?><span
                                            style="background:#f59e0b;color:#fff;border-radius:99px;padding:1px 6px;margin-left:4px;font-size:9px;"><?= $pendingFiles ?>
                                            pending</span><?php endif; ?>
                                </span>
                            <?php elseif ($isFileUpload): ?><span class="tbadge tb-upload"><i
                                        class="fas fa-file-upload"></i>
                                    File Upload</span>
                            <?php elseif ($isAuto): ?><span class="tbadge tb-auto"><i class="fas fa-bolt"></i>
                                    Auto-Tracked</span>
                            <?php elseif ($isAccounting): ?><span class="tbadge"
                                    style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;"><i
                                        class="fas fa-receipt"></i> Delivery Receipt</span>
                            <?php endif; ?>
                        </div>

                        <!-- Meta -->
                        <div class="cmeta">
                            <?php if ($updated_at): ?><span><i class="fas fa-clock"></i>
                                    <?= date('M d, Y · g:i A', strtotime($updated_at)) ?></span><?php endif; ?>
                            <?php if ($updatedBy): ?><span><i class="fas fa-user-edit"></i>
                                    <?= htmlspecialchars($updatedBy) ?></span><?php endif; ?>
                            <?php if ($stage === 'Downpayment'): ?><span><i class="fas fa-coins"></i> <?= $dpPct ?>% —
                                    ₱<?= number_format($dpAmt, 2) ?></span><?php endif; ?>
                            <?php if ($stage === 'Site Visit' && $designer1): ?>
                                <span><i class="fas fa-pencil-ruler"></i>
                                    <?= htmlspecialchars($designer1) ?>
                                    <?= $designer2 ? ' & ' . htmlspecialchars($designer2) : '' ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($stage === 'Site Visit' && $siteVisitPendingCount > 0 && in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin'])): ?>
                            <div
                                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="display:flex; align-items:center; gap:9px;">
                                    <i class="fas fa-bell" style="color:#d97706; font-size:15px; flex-shrink:0;"></i>
                                    <div>
                                        <div style="font-weight:700; font-size:13px; color:#92400e;">
                                            <?= $siteVisitPendingCount ?> site visit<?= $siteVisitPendingCount > 1 ? 's' : '' ?>
                                            waiting for your approval
                                        </div>
                                        <div style="font-size:11px; color:#b45309; margin-top:1px;">
                                            Click Review to approve or reject the submitted site visit.
                                        </div>
                                    </div>
                                </div>
                                <a href="manager-site-visit-approval?client_id=<?= $client_id ?>"
                                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:6px 13px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; flex-shrink:0;">
                                    <i class="fas fa-clipboard-check"></i> Review
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($stage === '2D / 3D Layout' && $layoutPendingCount > 0): ?>
                            <div
                                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="display:flex; align-items:center; gap:9px;">
                                    <i class="fas fa-bell" style="color:#d97706; font-size:15px; flex-shrink:0;"></i>
                                    <div>
                                        <div style="font-weight:700; font-size:13px; color:#92400e;">
                                            <?= $layoutPendingCount ?> pending approval<?= $layoutPendingCount > 1 ? 's' : '' ?>
                                            need your review
                                        </div>
                                        <div style="font-size:11px; color:#b45309; margin-top:1px;">
                                            Designer has uploaded attachments awaiting your approval.
                                        </div>
                                    </div>
                                </div>
                                <a href="designer-2d3d-layout?client_id=<?= $client_id ?>&back=manager_detail"
                                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:6px 13px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; flex-shrink:0;">
                                    <i class="fas fa-arrow-right"></i> Review
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php
                        $approvalStagesForNotif = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
                        if (in_array($stage, $approvalStagesForNotif) && $stageData):
                            $stagePendingCount = getStagePendingApprovalCount($conn, $admin_id, $admin_role, $isHead, $stageData['id']);
                            if ($stagePendingCount > 0):
                                ?>
                                <div
                                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:10px 14px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:9px;">
                                        <i class="fas fa-bell" style="color:#d97706; font-size:15px; flex-shrink:0;"></i>
                                        <div>
                                            <div style="font-weight:700; font-size:13px; color:#92400e;">
                                                <?= $stagePendingCount ?> file<?= $stagePendingCount > 1 ? 's' : '' ?> waiting for
                                                your approval
                                            </div>
                                            <div style="font-size:11px; color:#b45309; margin-top:1px;">
                                                Open the files page to review and approve or reject.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= $filesLink ?>"
                                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:6px 13px; border-radius:7px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i> Review Files
                                    </a>
                                </div>
                            <?php endif; endif; ?>

                        <!-- Assigned -->
                        <?php if (!empty($assigned)): ?>
                            <div class="chip-row">
                                <?php foreach ($assigned as $p): ?><span class="chip"><i class="fas fa-user"
                                            style="font-size:9px;opacity:.6;"></i>
                                        <?= htmlspecialchars($p) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Approval preview (latest file) -->
                        <?php if ($isApproval && $preview):
                            $required = $requiredApproversList[$stage] ?? [];
                            $gmOmPreviewStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                            $isGmOmStage = in_array($stage, $gmOmPreviewStages);
                            $gmOmSlotShown = false;
                            ?>
                            <div class="ap-preview">
                                <div class="ap-preview-label">Latest File — Approval Status</div>
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span
                                        style="font-size:12px;font-weight:600;color:var(--text-dk);"><?= htmlspecialchars($preview['label'] ?: $preview['file_name']) ?></span>
                                    <?php foreach ($required as $role):
                                        // For GM/OM stages: skip individual GM/OM badges, show one combined badge instead
                                        if ($isGmOmStage && in_array($role, ['general_manager', 'operational_manager'])) {
                                            if ($gmOmSlotShown)
                                                continue;
                                            $gmOmSlotShown = true;

                                            $gmRs = $preview['role_reviews']['general_manager'] ?? null;
                                            $omRs = $preview['role_reviews']['operational_manager'] ?? null;

                                            if ($gmRs === 'approved' || $omRs === 'approved') {
                                                $combinedBc = 'approved';
                                                $combinedBi = 'fa-check-circle';
                                                $whoActed = $gmRs === 'approved'
                                                    ? getRoleDisplayName('general_manager')
                                                    : getRoleDisplayName('operational_manager');
                                                $combinedLabel = "Approved by {$whoActed}";
                                            } elseif ($gmRs === 'rejected' || $omRs === 'rejected') {
                                                $combinedBc = 'rejected';
                                                $combinedBi = 'fa-times-circle';
                                                $whoActed = $gmRs === 'rejected'
                                                    ? getRoleDisplayName('general_manager')
                                                    : getRoleDisplayName('operational_manager');
                                                $combinedLabel = "Rejected by {$whoActed}";
                                            } else {
                                                $combinedBc = 'pending';
                                                $combinedBi = 'fa-clock';
                                                $combinedLabel = 'GM or OM (one required)';
                                            }
                                            ?>
                                            <span class="apbadge <?= $combinedBc ?>">
                                                <i class="fas <?= $combinedBi ?>"></i> <?= $combinedLabel ?>
                                            </span>
                                            <?php
                                            continue;
                                        }
                                        // All other roles — show individually as before
                                        $rs = $preview['role_reviews'][$role] ?? null;
                                        $bc = $rs ?? 'pending';
                                        $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                        ?>
                                        <span class="apbadge <?= $bc ?>"><i class="fas <?= $bi ?>"></i>
                                            <?= getRoleDisplayName($role) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Files chip -->
                        <?php if (($isApproval || $isFileUpload || $isAccounting) && $stageData): ?>
                            <div
                                style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;">
                                <a href="<?= $filesLink ?>" class="file-chip">
                                    <span class="dot"
                                        style="background:<?= $fileCount > 0 ? 'var(--done)' : 'var(--border)' ?>;width:7px;height:7px;border-radius:50%;flex-shrink:0;"></span>
                                    <i class="fas fa-paperclip"></i>
                                    <?= $fileCount ?> file<?= $fileCount !== 1 ? 's' : '' ?>
                                    <?php if ($canApproveThis && $pendingFiles > 0): ?>
                                        <span class="needs-review"><?= $pendingFiles ?> to review</span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right" style="font-size:9px;opacity:.4;"></i>
                                </a>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Payments -->
        <div class="sec-hdr" style="margin-top:32px;">
            <h2><i class="fas fa-money-check-alt"></i> Payment Schedule</h2>
        </div>
        <?php $payments->data_seek(0);
        while ($pay = $payments->fetch_assoc()):
            $pc = strtolower($pay['status']); ?>
            <div class="pay-card <?= $pc ?>">
                <div>
                    <div class="pay-type"><?= htmlspecialchars($pay['payment_type']) ?></div>
                    <div class="pay-pct"><?= number_format($pay['percentage'], 1) ?>% of project total</div>
                    <?php if ($pay['payment_date']): ?>
                        <div class="pay-date"><i class="fas fa-check-circle" style="color:var(--done);"></i> Paid
                            <?= date('M d, Y', strtotime($pay['payment_date'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                    <div class="pay-amt">₱<?= number_format($pay['amount'], 2) ?></div>
                    <span
                        class="pstatus <?= $pc ?>"><?= $pc === 'paid' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-clock"></i>' ?>
                        <?= htmlspecialchars($pay['status']) ?></span>
                </div>
            </div>
        <?php endwhile; ?>

    </div>

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

    <script>
        function closeModal() {
            document.getElementById('clientDetailModal').classList.remove('open');
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>

</body>

</html>