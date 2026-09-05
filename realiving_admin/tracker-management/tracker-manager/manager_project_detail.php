<?php
// manager_project_detail.php
include $includes['mainbody'];

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
$total_stages = count($stages);

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
$completion_pct = ($total_stages > 0) ? ($done_count / $total_stages) * 100 : 0;

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

// ── Reusable Tailwind button classes (same design system as unified_project_tracker.php
//    and manager_status_tracker.php) ──
$BTN_PRIMARY = "inline-flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-neutral-800 hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_PRIMARY_SM = "inline-flex items-center gap-1.5 bg-black text-white px-3 py-1.5 rounded-md text-[11px] font-bold shadow-sm hover:bg-neutral-800 hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_AMBER_SM = "inline-flex items-center gap-1.5 bg-gradient-to-br from-amber-600 to-amber-500 text-white px-3 py-1.5 rounded-md text-[11px] font-bold shadow-sm hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_GHOST_SM = "inline-flex items-center gap-1.5 bg-white border-2 border-neutral-200 text-neutral-600 px-3 py-1.5 rounded-md text-[11px] font-bold hover:border-black hover:text-black transition-all";
$BTN_WHITE_ON_DARK = "inline-flex items-center gap-2 bg-white text-black px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-neutral-100 hover:-translate-y-0.5 active:translate-y-0 transition-all";

// ══════════════════════════════════════════════════════════════════════
// Single-pass per-stage computation. Everything the old vertical timeline
// computed inside its foreach is computed ONCE here and stored in
// $stageRender[$idx], then rendered twice below (master list + detail
// panel) — same pattern unified_project_tracker.php uses for its split
// master/detail view, but without re-running every query a second time.
// ══════════════════════════════════════════════════════════════════════
$stageRender = [];
foreach ($stages as $idx => $stage) {
    $stageData = $trackerData[$stage] ?? null;
    $isApproval = in_array($stage, $approvalStages);
    $isFileUpload = in_array($stage, $fileUploadStages);
    $isAuto = in_array($stage, $autoStages);
    $isAccounting = ($stage === 'Accounting (Order Processing)');
    $updated_at = $stageData['updated_at'] ?? null;
    $updatedBy = $stageData['updated_by_name'] ?? null;
    $assigned = $stageData['assigned_people'] ?? [];
    $status = $stageData ? $stageData['status'] : 'Pending';
    $dpPct = null;
    $dpAmt = null;

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

    // Tailwind status color sets — same convention as unified_project_tracker.php
    $statusColors = [
        'pending' => [
            'node' => 'bg-white text-neutral-300 border-2 border-neutral-200',
            'left' => 'border-l-neutral-200',
            'chip' => 'bg-neutral-100 text-neutral-500 border-neutral-300',
            'text' => 'text-neutral-400',
        ],
        'ongoing' => [
            'node' => 'bg-blue-600 text-white shadow-md ring-4 ring-blue-100',
            'left' => 'border-l-blue-500',
            'chip' => 'bg-blue-600 text-white border-blue-600',
            'text' => 'text-blue-600',
        ],
        'done' => [
            'node' => 'bg-emerald-500 text-white shadow-sm',
            'left' => 'border-l-emerald-300',
            'chip' => 'bg-emerald-50 text-emerald-600 border-emerald-200',
            'text' => 'text-emerald-500',
        ],
    ];
    $scSet = $statusColors[$sc] ?? $statusColors['pending'];

    $highlightRing = ($stage === '2D / 3D Layout' && $layoutPendingCount > 0)
        || ($stage === 'Site Visit' && $siteVisitPendingCount > 0 && in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin']));

    $stageRender[$idx] = [
        'stage' => $stage,
        'stageData' => $stageData,
        'isApproval' => $isApproval,
        'isFileUpload' => $isFileUpload,
        'isAuto' => $isAuto,
        'isAccounting' => $isAccounting,
        'updated_at' => $updated_at,
        'updatedBy' => $updatedBy,
        'assigned' => $assigned,
        'status' => $status,
        'sc' => $sc,
        'icon' => $icon,
        'preview' => $preview,
        'pendingFiles' => $pendingFiles,
        'canApproveThis' => $canApproveThis,
        'fileCount' => $fileCount,
        'filesLink' => $filesLink,
        'openLink' => $openLink,
        'scSet' => $scSet,
        'highlightRing' => $highlightRing,
        'dpPct' => $dpPct,
        'dpAmt' => $dpAmt,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Dashboard — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- Tailwind is compiled via your npm build (output.css) — same design system as
         unified_project_tracker.php and manager_status_tracker.php. -->
    <style>
        /* Thin styled scrollbar for the master list panel — same as unified_project_tracker.php */
        #masterListScroll::-webkit-scrollbar { width: 6px; }
        #masterListScroll::-webkit-scrollbar-track { background: transparent; }
        #masterListScroll::-webkit-scrollbar-thumb { background: #d4d4d4; border-radius: 999px; }
        #masterListScroll::-webkit-scrollbar-thumb:hover { background: #a3a3a3; }

        @keyframes fadeInPanel {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stage-detail-panel:not(.hidden) { animation: fadeInPanel .18s ease-out; }
    </style>
</head>

<body class="bg-neutral-100 font-['Inter'] text-black min-h-screen">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 pb-16">

        <a href="manager-status-tracker"
            class="inline-flex items-center gap-2 text-sm font-semibold text-neutral-500 hover:text-black transition mb-6">
            <i class="fas fa-arrow-left"></i> Back to Status Tracker
        </a>

        <!-- Hero -->
        <div class="bg-black rounded-2xl p-7 sm:p-8 text-white mb-6 relative overflow-hidden">
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(255,255,255,.08)_0%,transparent_65%)] pointer-events-none">
            </div>
            <div class="flex justify-between items-start flex-wrap gap-4 relative z-10">
                <div>
                    <div class="text-xl sm:text-2xl font-bold tracking-tight"><?= htmlspecialchars($client['clientname']) ?></div>
                    <div class="text-sm text-white/70 mt-0.5"><?= htmlspecialchars($client['nameproject']) ?></div>
                </div>
                <div class="flex items-center gap-2.5 flex-wrap justify-end">
                    <button onclick="document.getElementById('clientDetailModal').classList.remove('hidden'); document.getElementById('clientDetailModal').classList.add('flex');"
                        class="<?= $BTN_WHITE_ON_DARK ?>">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div
                        class="bg-white/10 border border-white/20 rounded-full px-3.5 py-1.5 text-xs font-semibold flex items-center gap-2 flex-shrink-0">
                        <i class="fas fa-user-shield"></i>
                        <?= htmlspecialchars($userInfo['full_name']) ?>
                        <span
                            class="bg-white/15 rounded px-2 py-0.5 text-[11px] capitalize"><?= str_replace('_', ' ', $admin_role) ?></span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2.5 mt-6 relative z-10">
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Reference</div>
                    <div class="font-semibold break-all"><?= htmlspecialchars($client['reference_number']) ?></div>
                </div>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Type</div>
                    <div class="font-semibold"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Assigned To</div>
                    <div class="font-semibold"><?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?></div>
                </div>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Total Cost</div>
                    <div class="font-semibold">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Action banner — approval stages -->
        <?php if ($myPendingTotal > 0): ?>
            <div
                class="bg-amber-50 border-2 border-amber-400 rounded-xl px-5 py-3.5 mb-4 flex items-center gap-3.5 flex-wrap">
                <i class="fas fa-exclamation-circle text-amber-600 text-xl flex-shrink-0"></i>
                <div>
                    <div class="font-bold text-sm text-amber-800">
                        You have <?= $myPendingTotal ?> file<?= $myPendingTotal !== 1 ? 's' : '' ?> waiting for your approval.
                    </div>
                    <div class="text-xs text-amber-600 mt-0.5">
                        Select the relevant stage below and click <strong>"Files"</strong> to review and approve or reject.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action banner — 2D/3D Layout pending -->
        <?php if ($layoutPendingCount > 0): ?>
            <div
                class="bg-amber-50 border-2 border-amber-400 rounded-xl px-5 py-3.5 mb-4 flex items-center justify-between gap-3.5 flex-wrap">
                <div class="flex items-center gap-3">
                    <i class="fas fa-bell text-amber-600 text-xl flex-shrink-0"></i>
                    <div>
                        <div class="font-bold text-sm text-amber-800">
                            <?= $layoutPendingCount ?> pending 2D/3D Layout approval<?= $layoutPendingCount > 1 ? 's' : '' ?>
                            waiting for your review
                        </div>
                        <div class="text-xs text-amber-600 mt-0.5">
                            The designer has requested your approval on layout attachments.
                        </div>
                    </div>
                </div>
                <a href="designer-2d3d-layout?client_id=<?= $client_id ?>&back=manager_detail"
                    class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                    <i class="fas fa-arrow-right"></i> Go to 2D/3D Layout
                </a>
            </div>
        <?php endif; ?>

        <!-- ══════════════ Split Master–Detail Stage Viewer ══════════════ -->
        <div class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-5 items-start">

            <!-- ── MASTER: stage list ── -->
            <div class="bg-white border border-neutral-200 rounded-2xl shadow-sm lg:sticky lg:top-6 overflow-hidden">
                <div class="px-4 sm:px-5 py-4 border-b border-neutral-100 flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wide text-neutral-500">Stages</span>
                    <span class="text-[11px] font-mono font-bold text-neutral-400"><?= $total_stages ?></span>
                </div>
                <div id="masterListScroll" class="max-h-[70vh] overflow-y-auto p-2.5 flex flex-col gap-1.5">
                    <?php foreach ($stageRender as $idx => $r): ?>
                        <div id="master-item-<?= $idx ?>" data-idx="<?= $idx ?>" data-status="<?= htmlspecialchars($r['status']) ?>"
                            onclick="selectStage(<?= $idx ?>)"
                            class="master-item flex items-center gap-3 px-3 py-2.5 rounded-xl border border-neutral-200 bg-white cursor-pointer transition-all hover:border-neutral-300">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-[11px] flex-shrink-0 <?= $r['scSet']['node'] ?>">
                                <?php if ($r['status'] === 'Done'): ?>
                                    <i class="fas fa-check"></i>
                                <?php elseif ($r['status'] === 'Ongoing'): ?>
                                    <i class="fas fa-circle-notch fa-spin"></i>
                                <?php else: ?>
                                    <i class="fas <?= $r['icon'] ?> text-[10px]"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="master-label text-[13px] font-bold text-black truncate flex items-center gap-1.5">
                                    <?= htmlspecialchars($r['stage']) ?>
                                    <?php if ($r['highlightRing']): ?>
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                                    <?php endif; ?>
                                    <?php if ($r['isApproval'] && $r['pendingFiles'] > 0): ?>
                                        <span class="bg-amber-500 text-white rounded-full px-1.5 text-[9px] font-bold flex-shrink-0"><?= $r['pendingFiles'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="master-sub text-[10px] font-semibold uppercase tracking-wide <?= $r['scSet']['text'] ?>">
                                    <?= htmlspecialchars($r['status']) ?>
                                </div>
                            </div>
                            <i class="master-chevron fas fa-chevron-right text-[9px] text-neutral-300 flex-shrink-0"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── DETAIL: selected stage ── -->
            <div id="detailPanelWrap">

                <!-- Project overview -->
                <div class="bg-white border border-neutral-200 rounded-2xl shadow-sm overflow-hidden mb-3">
                    <div class="flex items-center flex-wrap gap-y-4 gap-x-6 px-5 sm:px-7 py-5">
                        <div class="flex items-center gap-2.5 pr-6 mr-2 border-r border-neutral-100 flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-neutral-900 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-layer-group text-white text-[11px]"></i>
                            </div>
                            <div>
                                <div class="text-[13px] font-bold text-black leading-tight">Project overview</div>
                                <div class="text-[10px] text-neutral-400 font-semibold uppercase tracking-wide"><?= $total_stages ?> stages total</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 sm:gap-8 flex-1 justify-between sm:justify-start">
                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-neutral-50 border border-neutral-200 flex items-center justify-center text-[16px] font-bold text-neutral-700 transition-transform group-hover:scale-105">
                                    <?= $pending_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Pending</div>
                                    <div class="text-[10px] text-neutral-400">Not started</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-blue-50 border border-blue-200 flex items-center justify-center text-[16px] font-bold text-blue-600 transition-transform group-hover:scale-105">
                                    <?= $ongoing_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Ongoing</div>
                                    <div class="text-[10px] text-neutral-400">In progress</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center text-[16px] font-bold text-emerald-600 transition-transform group-hover:scale-105">
                                    <?= $done_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Done</div>
                                    <div class="text-[10px] text-neutral-400">Completed</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 flex-shrink-0 ml-auto">
                            <span class="text-[18px] font-bold text-black font-mono"><?= number_format($completion_pct, 0) ?>%</span>
                            <div class="w-20 h-1.5 bg-neutral-100 rounded-full overflow-hidden">
                                <div class="h-full bg-neutral-900 rounded-full transition-all duration-500" style="width:<?= $completion_pct ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment overview -->
                <div class="bg-white border border-neutral-200 rounded-2xl shadow-sm overflow-hidden mb-5">
                    <div class="flex items-center flex-wrap gap-y-4 gap-x-6 px-5 sm:px-7 py-5">
                        <div class="flex items-center gap-2.5 pr-6 mr-2 border-r border-neutral-100 flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-emerald-600 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-money-bill-wave text-white text-[11px]"></i>
                            </div>
                            <div>
                                <div class="text-[13px] font-bold text-black leading-tight">Payment overview</div>
                                <div class="text-[10px] text-neutral-400 font-semibold uppercase tracking-wide">Total ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?></div>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 sm:gap-8 flex-1 justify-between sm:justify-start">
                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center transition-transform group-hover:scale-105">
                                    <i class="fas fa-check text-emerald-600 text-[15px]"></i>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">₱<?= number_format($total_paid, 0) ?></div>
                                    <div class="text-[10px] text-neutral-400">Collected</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-amber-50 border border-amber-200 flex items-center justify-center transition-transform group-hover:scale-105">
                                    <i class="fas fa-clock text-amber-600 text-[15px]"></i>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?></div>
                                    <div class="text-[10px] text-neutral-400">Balance</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 flex-shrink-0 ml-auto">
                            <span class="text-[18px] font-bold text-black font-mono"><?= number_format($pay_pct, 0) ?>%</span>
                            <div class="w-20 h-1.5 bg-neutral-100 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width:<?= $pay_pct ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php foreach ($stageRender as $idx => $r):
                    $stage = $r['stage'];
                    $stageData = $r['stageData'];
                    $status = $r['status'];
                    $scSet = $r['scSet'];
                    ?>
                    <div id="stage-detail-<?= $idx ?>" class="stage-detail-panel <?= $idx === 0 ? '' : 'hidden' ?>">
                        <div class="bg-white border border-neutral-200 rounded-2xl p-5 sm:p-7 shadow-sm border-l-4 <?= $scSet['left'] ?> <?= $r['highlightRing'] ? 'ring-2 ring-amber-300' : '' ?>">

                            <!-- Top row -->
                            <div class="flex justify-between items-start gap-3 mb-3 flex-wrap">
                                <div class="flex items-center gap-3 flex-1 min-w-0 flex-wrap">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm flex-shrink-0 <?= $scSet['node'] ?>">
                                        <?php if ($status === 'Done'): ?>
                                            <i class="fas fa-check"></i>
                                        <?php elseif ($status === 'Ongoing'): ?>
                                            <i class="fas fa-circle-notch fa-spin text-[13px]"></i>
                                        <?php else: ?>
                                            <i class="fas <?= $r['icon'] ?> text-[13px]"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-mono text-[10px] font-semibold text-neutral-400 bg-neutral-100 border border-neutral-200 rounded px-1.5 py-0.5">
                                                <?= str_pad($idx + 1, 2, '0', STR_PAD_LEFT) ?>
                                            </span>
                                            <span class="text-base sm:text-lg font-bold text-black"><?= htmlspecialchars($stage) ?></span>
                                            <?php if ($stage === '2D / 3D Layout'): ?>
                                                <span class="text-[10px] font-bold uppercase tracking-wide bg-neutral-100 text-neutral-600 border border-neutral-300 rounded-full px-2 py-0.5">
                                                    <i class="fas fa-sync-alt"></i> Rev <?= $current_revision ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1.5 border rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide <?= $scSet['chip'] ?> flex-shrink-0">
                                    <?php if ($status === 'Done'): ?><i class="fas fa-check"></i>
                                    <?php elseif ($status === 'Ongoing'): ?><i class="fas fa-circle-notch fa-spin"></i>
                                    <?php else: ?><i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?= $status ?>
                                </span>
                            </div>

                            <?php if ($r['openLink']): ?>
                                <a href="<?= $r['openLink'] ?>"
                                    class="w-full flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[13px] rounded-lg px-4 py-3 mb-3 transition-all">
                                    <i class="fas fa-arrow-right"></i> Open <?= htmlspecialchars($stage) ?> Page
                                </a>
                            <?php endif; ?>

                            <?php if ($stage === 'Site Visit'): ?>
                                <a href="manager-site-visit-approval?client_id=<?= $client_id ?>"
                                    class="w-full flex items-center justify-center gap-2 bg-neutral-50 hover:bg-neutral-100 border border-neutral-200 text-black font-bold text-[13px] rounded-lg px-4 py-3 mb-3 transition-all">
                                    <i class="fas fa-clipboard-check"></i> Review Site Visit
                                </a>
                            <?php endif; ?>

                            <!-- Type badges -->
                            <div class="flex gap-1.5 flex-wrap mb-3">
                                <?php if ($stage === 'Production Data Submittals'): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wide bg-sky-100 text-sky-800 border border-sky-300 rounded-full px-2 py-0.5">
                                        <i class="fas fa-bolt"></i> Auto-Tracked
                                    </span>
                                <?php endif; ?>
                                <?php if ($r['isApproval']): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wide bg-amber-100 text-amber-800 border border-amber-300 rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-stamp"></i> Approval Required
                                        <?php if ($r['pendingFiles'] > 0): ?>
                                            <span class="bg-amber-500 text-white rounded-full px-1.5 py-0 text-[9px] ml-0.5"><?= $r['pendingFiles'] ?> pending</span>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ($r['isFileUpload']): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wide bg-violet-100 text-violet-800 border border-violet-300 rounded-full px-2 py-0.5">
                                        <i class="fas fa-file-upload"></i> File Upload
                                    </span>
                                <?php elseif ($r['isAuto']): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wide bg-sky-100 text-sky-800 border border-sky-300 rounded-full px-2 py-0.5">
                                        <i class="fas fa-bolt"></i> Auto-Tracked
                                    </span>
                                <?php elseif ($r['isAccounting']): ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wide bg-sky-100 text-sky-800 border border-sky-300 rounded-full px-2 py-0.5">
                                        <i class="fas fa-receipt"></i> Delivery Receipt
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Meta -->
                            <div class="flex flex-wrap gap-3.5 text-[11px] text-neutral-400 pb-3 border-b border-neutral-100">
                                <?php if ($r['updated_at']): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-clock"></i> <?= date('M d, Y · g:i A', strtotime($r['updated_at'])) ?></span>
                                <?php endif; ?>
                                <?php if ($r['updatedBy']): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-user-edit"></i> <?= htmlspecialchars($r['updatedBy']) ?></span>
                                <?php endif; ?>
                                <?php if ($stage === 'Downpayment'): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-coins"></i> <?= $r['dpPct'] ?>% — ₱<?= number_format($r['dpAmt'], 2) ?></span>
                                <?php endif; ?>
                                <?php if ($stage === 'Site Visit' && $designer1): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-pencil-ruler"></i>
                                        <?= htmlspecialchars($designer1) ?><?= $designer2 ? ' & ' . htmlspecialchars($designer2) : '' ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Payment Schedule — shown right on the payment stage it belongs to,
                                 instead of one long list at the bottom of the page. -->
                            <?php if (in_array($stage, ['Downpayment', 'BILLING'])): ?>
                                <div class="mt-4 pt-4 border-t border-neutral-100">
                                    <div class="text-[10px] font-bold uppercase tracking-wide text-neutral-400 mb-2.5 flex items-center gap-1.5">
                                        <i class="fas fa-money-check-alt"></i> Payment Schedule
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <?php
                                        $payments->data_seek(0);
                                        $rowsShown = 0;
                                        while ($pay = $payments->fetch_assoc()):
                                            $isDownRow = stripos($pay['payment_type'], 'Down') !== false;
                                            if ($stage === 'Downpayment' && !$isDownRow)
                                                continue;
                                            if ($stage === 'BILLING' && $isDownRow)
                                                continue;
                                            $rowsShown++;
                                            $pc = strtolower($pay['status']);
                                            $isPaid = $pc === 'paid';
                                            ?>
                                            <div class="flex justify-between items-center gap-3 flex-wrap bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-3">
                                                <div>
                                                    <div class="text-[13px] font-semibold text-black"><?= htmlspecialchars($pay['payment_type']) ?></div>
                                                    <div class="text-[10px] text-neutral-400 mt-0.5"><?= number_format($pay['percentage'], 1) ?>% of project total</div>
                                                    <?php if ($pay['payment_date']): ?>
                                                        <div class="text-[10px] text-neutral-400 mt-1 flex items-center gap-1">
                                                            <i class="fas fa-check-circle text-emerald-500"></i> Paid <?= date('M d, Y', strtotime($pay['payment_date'])) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex flex-col items-end gap-1">
                                                    <div class="text-sm font-bold text-black font-mono">₱<?= number_format($pay['amount'], 2) ?></div>
                                                    <span class="inline-flex items-center gap-1 border rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= $isPaid ? 'bg-emerald-50 text-emerald-700 border-emerald-300' : 'bg-amber-50 text-amber-700 border-amber-300' ?>">
                                                        <?= $isPaid ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-clock"></i>' ?>
                                                        <?= htmlspecialchars($pay['status']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                        <?php if ($rowsShown === 0): ?>
                                            <div class="text-[12px] text-neutral-400 italic">No payment rows for this stage yet.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($stage === 'Site Visit' && $siteVisitPendingCount > 0 && in_array($admin_role, ['general_manager', 'operational_manager', 'superadmin'])): ?>
                                <div class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                    <div class="flex items-center gap-2.5">
                                        <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                        <div>
                                            <div class="font-bold text-[13px] text-amber-800">
                                                <?= $siteVisitPendingCount ?> site visit<?= $siteVisitPendingCount > 1 ? 's' : '' ?> waiting for your approval
                                            </div>
                                            <div class="text-[11px] text-amber-600 mt-0.5">
                                                Click Review to approve or reject the submitted site visit.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="manager-site-visit-approval?client_id=<?= $client_id ?>" class="<?= $BTN_AMBER_SM ?> flex-shrink-0">
                                        <i class="fas fa-clipboard-check"></i> Review
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if ($stage === '2D / 3D Layout' && $layoutPendingCount > 0): ?>
                                <div class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                    <div class="flex items-center gap-2.5">
                                        <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                        <div>
                                            <div class="font-bold text-[13px] text-amber-800">
                                                <?= $layoutPendingCount ?> pending approval<?= $layoutPendingCount > 1 ? 's' : '' ?> need your review
                                            </div>
                                            <div class="text-[11px] text-amber-600 mt-0.5">
                                                Designer has uploaded attachments awaiting your approval.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="designer-2d3d-layout?client_id=<?= $client_id ?>&back=manager_detail" class="<?= $BTN_AMBER_SM ?> flex-shrink-0">
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
                                    <div class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-amber-800">
                                                    <?= $stagePendingCount ?> file<?= $stagePendingCount > 1 ? 's' : '' ?> waiting for your approval
                                                </div>
                                                <div class="text-[11px] text-amber-600 mt-0.5">
                                                    Open the files page to review and approve or reject.
                                                </div>
                                            </div>
                                        </div>
                                        <a href="<?= $r['filesLink'] ?>" class="<?= $BTN_AMBER_SM ?> flex-shrink-0">
                                            <i class="fas fa-arrow-right"></i> Review Files
                                        </a>
                                    </div>
                                <?php endif; endif; ?>

                            <!-- Assigned -->
                            <?php if (!empty($r['assigned'])): ?>
                                <div class="flex flex-wrap gap-1.5 mt-3">
                                    <?php foreach ($r['assigned'] as $p): ?>
                                        <span class="inline-flex items-center gap-1 bg-neutral-100 border border-neutral-200 rounded-full px-2.5 py-1 text-[11px] font-semibold text-neutral-600">
                                            <i class="fas fa-user text-[9px]"></i> <?= htmlspecialchars($p) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Approval preview (latest file) -->
                            <?php if ($r['isApproval'] && $r['preview']):
                                $preview = $r['preview'];
                                $required = $requiredApproversList[$stage] ?? [];
                                $gmOmPreviewStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                                $isGmOmStage = in_array($stage, $gmOmPreviewStages);
                                $gmOmSlotShown = false;
                                ?>
                                <div class="mt-3 pt-3 border-t border-neutral-100">
                                    <div class="text-[10px] font-bold uppercase tracking-wide text-neutral-400 mb-1.5">Latest File — Approval Status</div>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-xs font-semibold text-black"><?= htmlspecialchars($preview['label'] ?: $preview['file_name']) ?></span>
                                        <?php foreach ($required as $role):
                                            if ($isGmOmStage && in_array($role, ['general_manager', 'operational_manager'])) {
                                                if ($gmOmSlotShown)
                                                    continue;
                                                $gmOmSlotShown = true;

                                                $gmRs = $preview['role_reviews']['general_manager'] ?? null;
                                                $omRs = $preview['role_reviews']['operational_manager'] ?? null;

                                                if ($gmRs === 'approved' || $omRs === 'approved') {
                                                    $combinedBc = 'bg-emerald-50 text-emerald-700 border-emerald-300';
                                                    $combinedBi = 'fa-check-circle';
                                                    $whoActed = $gmRs === 'approved'
                                                        ? getRoleDisplayName('general_manager')
                                                        : getRoleDisplayName('operational_manager');
                                                    $combinedLabel = "Approved by {$whoActed}";
                                                } elseif ($gmRs === 'rejected' || $omRs === 'rejected') {
                                                    $combinedBc = 'bg-red-50 text-red-700 border-red-300';
                                                    $combinedBi = 'fa-times-circle';
                                                    $whoActed = $gmRs === 'rejected'
                                                        ? getRoleDisplayName('general_manager')
                                                        : getRoleDisplayName('operational_manager');
                                                    $combinedLabel = "Rejected by {$whoActed}";
                                                } else {
                                                    $combinedBc = 'bg-neutral-100 text-neutral-500 border-neutral-300';
                                                    $combinedBi = 'fa-clock';
                                                    $combinedLabel = 'GM or OM (one required)';
                                                }
                                                ?>
                                                <span class="inline-flex items-center gap-1 border rounded-full px-2 py-0.5 text-[10px] font-bold uppercase <?= $combinedBc ?>">
                                                    <i class="fas <?= $combinedBi ?>"></i> <?= $combinedLabel ?>
                                                </span>
                                                <?php
                                                continue;
                                            }
                                            $rs = $preview['role_reviews'][$role] ?? null;
                                            $bc = $rs === 'approved'
                                                ? 'bg-emerald-50 text-emerald-700 border-emerald-300'
                                                : ($rs === 'rejected' ? 'bg-red-50 text-red-700 border-red-300' : 'bg-neutral-100 text-neutral-500 border-neutral-300');
                                            $bi = $rs === 'approved' ? 'fa-check-circle' : ($rs === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            ?>
                                            <span class="inline-flex items-center gap-1 border rounded-full px-2 py-0.5 text-[10px] font-bold uppercase <?= $bc ?>">
                                                <i class="fas <?= $bi ?>"></i> <?= getRoleDisplayName($role) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Files chip -->
                            <?php if (($r['isApproval'] || $r['isFileUpload'] || $r['isAccounting']) && $stageData): ?>
                                <div class="mt-4 pt-4 border-t border-neutral-200 flex justify-end">
                                    <a href="<?= $r['filesLink'] ?>" class="<?= $BTN_GHOST_SM ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $r['fileCount'] > 0 ? 'bg-emerald-500' : 'bg-neutral-300' ?>"></span>
                                        <i class="fas fa-paperclip"></i>
                                        <?= $r['fileCount'] ?> file<?= $r['fileCount'] !== 1 ? 's' : '' ?>
                                        <?php if ($r['canApproveThis'] && $r['pendingFiles'] > 0): ?>
                                            <span class="bg-amber-500 text-white rounded-full px-2 py-0.5 text-[10px] font-bold"><?= $r['pendingFiles'] ?> to review</span>
                                        <?php endif; ?>
                                        <i class="fas fa-chevron-right text-[9px] opacity-40"></i>
                                    </a>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div><!-- /detailPanelWrap -->

        </div><!-- /split master-detail -->

    </div>

    <!-- Client Detail Modal -->
    <?php
    $house_state = $client['house_state'] ?? '';
    $permit_required = $client['permit_required'] ?? '';
    $target_movein_date = $client['target_movein_date'] ?? '';
    ?>
    <div id="clientDetailModal"
        class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center p-5"
        onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
        <div class="bg-white rounded-2xl p-7 max-w-xl w-full max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="flex justify-between items-center mb-4 pb-3.5 border-b-2 border-neutral-100">
                <div class="text-lg font-bold text-black flex items-center gap-2">
                    <i class="fas fa-user-circle text-neutral-500"></i> Client Details
                </div>
                <button class="text-xl text-neutral-400 hover:text-neutral-700 p-1 leading-none"
                    onclick="document.getElementById('clientDetailModal').classList.add('hidden'); document.getElementById('clientDetailModal').classList.remove('flex');">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <?php
            function detailModalRow($label, $valueHtml)
            {
                echo '<div class="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-1.5 sm:gap-2.5 py-2.5 border-b border-neutral-100 items-start last:border-b-0">';
                echo '<div class="font-semibold text-neutral-500 text-[13px]">' . $label . '</div>';
                echo '<div class="text-black text-[13px] break-words">' . $valueHtml . '</div>';
                echo '</div>';
            }
            detailModalRow('Reference Number', '<span class="text-blue-600 font-mono font-semibold">' . htmlspecialchars($client['reference_number'] ?? '') . '</span>');
            detailModalRow('Client Name', htmlspecialchars($client['clientname']));
            detailModalRow('Project Name', htmlspecialchars($client['nameproject']));

            $st = $client['status'] ?? '';
            $stClasses = strtolower($st) === 'new client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800';
            detailModalRow('Status', '<span class="inline-block px-3 py-0.5 rounded-full text-[11px] font-bold ' . $stClasses . '">' . htmlspecialchars($st) . '</span>');

            detailModalRow('Business Type', htmlspecialchars($business_type_label));

            if (!empty($client['contact']))
                detailModalRow('Phone', htmlspecialchars($client['contact']));
            if (!empty($client['email']))
                detailModalRow('Email', htmlspecialchars($client['email']));
            if (!empty($client['address']))
                detailModalRow('Address', htmlspecialchars($client['address']));
            if (!empty($client['gender']))
                detailModalRow('Gender', htmlspecialchars($client['gender']));
            if (!empty($client['client_class']))
                detailModalRow('Classification', htmlspecialchars($client['client_class']));
            if (!empty($client['client_type']))
                detailModalRow('Client Type', htmlspecialchars($client['client_type']));
            if (!empty($client['project_scope']))
                detailModalRow('Project Scope', nl2br(htmlspecialchars($client['project_scope'])));
            if (!empty($client['scope_of_work']))
                detailModalRow('Scope of Work', nl2br(htmlspecialchars($client['scope_of_work'])));

            if ($house_state) {
                $hsClasses = 'bg-amber-100 text-amber-800';
                if ($house_state === 'Bare/Empty Lot')
                    $hsClasses = 'bg-blue-100 text-blue-800';
                elseif ($house_state === 'Construction Started')
                    $hsClasses = 'bg-red-100 text-red-800';
                elseif ($house_state === 'Renovation')
                    $hsClasses = 'bg-violet-100 text-violet-800';
                detailModalRow('House State', '<span class="inline-block px-3 py-0.5 rounded-full text-xs font-bold ' . $hsClasses . '">' . htmlspecialchars($house_state) . '</span>');
            }

            if ($permit_required) {
                $prClasses = 'bg-amber-100 text-amber-800';
                if ($permit_required === 'Yes')
                    $prClasses = 'bg-red-100 text-red-800';
                elseif ($permit_required === 'No')
                    $prClasses = 'bg-emerald-100 text-emerald-800';
                detailModalRow('Permit Required', '<span class="inline-block px-3 py-0.5 rounded-full text-xs font-bold ' . $prClasses . '">' . htmlspecialchars($permit_required) . '</span>');
            }

            if ($target_movein_date) {
                detailModalRow('Target Move-in', '<span class="font-semibold"><i class="fas fa-calendar-check text-emerald-500"></i> ' . date('F d, Y', strtotime($target_movein_date)) . '</span>');
            }

            detailModalRow('Total Project Cost', '<span class="font-bold text-black text-[15px]">₱' . number_format($client['total_project_cost'] ?? 0, 2) . '</span>');
            detailModalRow('Remaining Balance', '<span class="font-bold text-red-600 text-[15px]">₱' . number_format($client['remaining_balance'] ?? 0, 2) . '</span>');
            ?>
        </div>
    </div>

    <!-- Master–detail panel switching (same pattern as unified_project_tracker.php) -->
    <script>
        function selectStage(idx) {
            history.replaceState(null, '', '#stage-' + idx);

            document.querySelectorAll('.stage-detail-panel').forEach(function (p) {
                p.classList.add('hidden');
            });
            var panel = document.getElementById('stage-detail-' + idx);
            if (panel) panel.classList.remove('hidden');

            document.querySelectorAll('.master-item').forEach(function (it) {
                it.classList.remove('bg-black', 'text-white', 'border-black', 'shadow-md');
                it.classList.add('border-neutral-200', 'bg-white');
                var label = it.querySelector('.master-label');
                if (label) { label.classList.remove('text-white'); label.classList.add('text-black'); }
                var sub = it.querySelector('.master-sub');
                if (sub) { sub.classList.remove('text-white/70'); }
                var chev = it.querySelector('.master-chevron');
                if (chev) { chev.classList.remove('text-white/50'); chev.classList.add('text-neutral-300'); }
            });

            var active = document.getElementById('master-item-' + idx);
            if (active) {
                active.classList.remove('border-neutral-200', 'bg-white');
                active.classList.add('bg-black', 'text-white', 'border-black', 'shadow-md');
                var label = active.querySelector('.master-label');
                if (label) { label.classList.add('text-white'); label.classList.remove('text-black'); }
                var sub = active.querySelector('.master-sub');
                if (sub) { sub.classList.add('text-white/70'); }
                var chev = active.querySelector('.master-chevron');
                if (chev) { chev.classList.add('text-white/50'); chev.classList.remove('text-neutral-300'); }
            }

            if (window.innerWidth < 1024) {
                var wrap = document.getElementById('detailPanelWrap');
                if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var items = document.querySelectorAll('.master-item');
            var target = null;

            var hashMatch = window.location.hash.match(/^#stage-(\d+)$/);
            if (hashMatch) {
                target = document.getElementById('master-item-' + hashMatch[1]);
            }

            if (!target) {
                items.forEach(function (it) {
                    if (!target && it.dataset.status === 'Ongoing') target = it;
                });
            }

            if (!target && items.length) target = items[0];

            if (target) selectStage(parseInt(target.dataset.idx, 10));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('clientDetailModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        });
    </script>

</body>

</html>