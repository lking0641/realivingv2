<?php
// stage_files.php
include $includes ['mainbody'];



$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$stage_id = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;
$stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';

// Get admin info
$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

// Fetch client
if ($canViewAllClients) {
    $cStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk=a.id WHERE u.id=?");
    $cStmt->bind_param("i", $client_id);
} else {
    $cStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk=a.id WHERE u.id=? AND u.accountaid_fk=?");
    $cStmt->bind_param("ii", $client_id, $admin_id);
}
$cStmt->execute();
$client = $cStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied.");

// Permissions
// Check if this user is the accountaid_fk for this client
$accountFkCheckStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkCheckStmt->bind_param("i", $client_id);
$accountFkCheckStmt->execute();
$accountFkRow = $accountFkCheckStmt->get_result()->fetch_assoc();
$isAccountFk = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

$permissions = [];

if ($admin_role === 'sales') {
    // Sales: only use per-user stage_permissions table
    $pStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id=?");
    $pStmt->bind_param("i", $admin_id);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($p = $pr->fetch_assoc())
        $permissions[$p['stage_name']] = (bool) $p['can_update'];
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;

} elseif ($isAccountFk) {
    // accountaid_fk (non-sales): check BOTH tables, allow if either grants permission
    // First check role_stage_permissions
    $rolePermStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role=?");
    $rolePermStmt->bind_param("s", $admin_role);
    $rolePermStmt->execute();
    $rolePermResult = $rolePermStmt->get_result();
    $rolePermissions = [];
    while ($p = $rolePermResult->fetch_assoc()) {
        $rolePermissions[$p['stage_name']] = (bool) $p['can_update'];
    }

    // Then check individual stage_permissions
    $userPermStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id=?");
    $userPermStmt->bind_param("i", $admin_id);
    $userPermStmt->execute();
    $userPermResult = $userPermStmt->get_result();
    $userPermissions = [];
    while ($p = $userPermResult->fetch_assoc()) {
        $userPermissions[$p['stage_name']] = (bool) $p['can_update'];
    }

    // Merge: true if either role permission OR individual permission allows it
    $allStageNames = array_unique(array_merge(array_keys($rolePermissions), array_keys($userPermissions)));
    foreach ($allStageNames as $sName) {
        $permissions[$sName] = ($rolePermissions[$sName] ?? false) || ($userPermissions[$sName] ?? false);
    }
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;

} else {
    // All other roles: only use role_stage_permissions
    $pStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role=?");
    $pStmt->bind_param("s", $admin_role);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($p = $pr->fetch_assoc())
        $permissions[$p['stage_name']] = (bool) $p['can_update'];
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;
}

// Approval stage roles
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

$approvalStages = array_keys($approvalStageRoles);
$fileUploadStages = ['Reference', 'Internal P.O to Accounting', 'Handover'];
$isApproval = in_array($stage, $approvalStages);
$isFileUpload = in_array($stage, $fileUploadStages);
$isAccounting = ($stage === 'Accounting (Order Processing)');

// Can approve?
$canApprove = false;
if ($isApproval) {
    $rolesForStage = $approvalStageRoles[$stage] ?? [];
    if ($admin_role === 'technical_designer') {
        if (in_array('technical_designer', $rolesForStage) && !empty($userInfo['is_head']))
            $canApprove = true;
    } elseif ($admin_role === 'designer') {
        if (in_array($stage, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && !empty($userInfo['is_head']))
            $canApprove = true;
    } elseif ($admin_role === 'accounting') {
        if ($stage === 'Purchase Order (Submit to accounting)')
            $canApprove = true;
    } else {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    }
}

// Assigned check
$assignCheckStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk FROM user_info WHERE id=?");
$assignCheckStmt->bind_param("i", $client_id);
$assignCheckStmt->execute();
$assignData = $assignCheckStmt->get_result()->fetch_assoc();

$isAssigned = in_array($admin_id, array_filter([
    $assignData['designer1_id'] ?? null,
    $assignData['designer2_id'] ?? null,
    $assignData['technical_designer_id'] ?? null,
    $assignData['project_coordinator_id'] ?? null,
    $assignData['accountaid_fk'] ?? null,
]));

// Fetch stage status
$stStmt = $conn->prepare("SELECT status FROM project_tracker WHERE id=?");
$stStmt->bind_param("i", $stage_id);
$stStmt->execute();
$stageRow = $stStmt->get_result()->fetch_assoc();
$stageStatus = $stageRow ? $stageRow['status'] : 'Pending';

// Early definition to avoid undefined variable warnings
$canUpload = ($canUpdate && $isAssigned) && $stage_id;

// ── Internal P.O to Accounting: fetch approval record ──────────────
$internalPoApproval = null;
$isInternalPo = ($stage === 'Internal P.O to Accounting');
if ($isInternalPo && $stage_id) {
    $ipaStmt = $conn->prepare("SELECT ipa.*, 
        ac.full_name as accounting_reviewer_name,
        dc.full_name as designer_reviewer_name,
        req.full_name as requested_by_name
        FROM internal_po_approvals ipa
        LEFT JOIN account ac ON ipa.accounting_reviewed_by = ac.id
        LEFT JOIN account dc ON ipa.designer_reviewed_by = dc.id
        LEFT JOIN account req ON ipa.requested_by = req.id
        WHERE ipa.stage_id = ?
        ORDER BY ipa.id DESC LIMIT 1");
    $ipaStmt->bind_param("i", $stage_id);
    $ipaStmt->execute();
    $internalPoApproval = $ipaStmt->get_result()->fetch_assoc();
}

$canRequestInternalPoApproval = false;
$canReviewInternalPoAccounting = false;
$canReviewInternalPoDesigner = false;

if ($isInternalPo) {
    $canRequestInternalPoApproval = ($canUpdate && $isAssigned && $stage_id > 0);
    $canReviewInternalPoAccounting = ($admin_role === 'accounting');
    $canReviewInternalPoDesigner = ($admin_role === 'designer' && !empty($userInfo['is_head']));
}

// Sequential mode lock: block access if previous stage is not done
$tracker_mode_stmt = $conn->prepare("SELECT tracker_mode FROM user_info WHERE id = ?");
$tracker_mode_stmt->bind_param("i", $client_id);
$tracker_mode_stmt->execute();
$tracker_mode_row = $tracker_mode_stmt->get_result()->fetch_assoc();
$sf_tracker_mode = $tracker_mode_row['tracker_mode'] ?? 'non-sequential';

if ($sf_tracker_mode === 'sequential' && $stageStatus === 'Pending') {
    $isNonProjectSF = ($client['business_type'] ?? '') === 'Non-Project';

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

    if ($isNonProjectSF) {
        $all_stages = array_values(array_filter($all_stages, function ($s) {
            return $s !== 'Samples Submitted TDS/SDS';
        }));
    }

    // Always-unlocked stages (first 6 by original index, regardless of list size)
    $alwaysUnlocked = [
        'Rough Estimation',
        'Site Visit',
        '2D / 3D Layout',
        'Reference',
        'Samples Submitted TDS/SDS',
        'Quotation'
    ];
    // For Non-Project, Samples is removed so unlocked list adjusts too
    if ($isNonProjectSF) {
        $alwaysUnlocked = array_values(array_filter($alwaysUnlocked, function ($s) {
            return $s !== 'Samples Submitted TDS/SDS';
        }));
    }

    // If this stage is in the always-unlocked list, never block it
    if (!in_array($stage, $alwaysUnlocked)) {
        $current_idx = array_search($stage, $all_stages);
        if ($current_idx !== false && $current_idx > 0) {
            $prev_stage = $all_stages[$current_idx - 1];
            $prevStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = ?");
            $prevStmt->bind_param("is", $client_id, $prev_stage);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStatus = $prevRow['status'] ?? 'Pending';
            if ($prevStatus === 'Pending') {
                header("Location: " . BASE_URL . "unified-project-tracker?client_id={$client_id}&locked=1");
                exit();
            }
        }
    }
}

// Fetch PO approved files (for Accounting stage)
$poApprovedFiles = [];
if ($isAccounting) {
    $poStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=(SELECT id FROM project_tracker WHERE client_id=? AND stage_name='Purchase Order (Submit to accounting)' LIMIT 1)
          AND sa.approval_status='approved'
        ORDER BY sa.uploaded_at DESC
    ");
    $poStmt->bind_param("i", $client_id);
    $poStmt->execute();
    $poResult = $poStmt->get_result();
    while ($row = $poResult->fetch_assoc())
        $poApprovedFiles[] = $row;
}

// Fetch BOM approved files (for Purchase Order stage)
$bomApprovedFiles = [];
if ($stage === 'Purchase Order (Submit to accounting)') {
    $bomStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name,
               COALESCE(bos.status, 'pending') as order_status
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.id
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
        AND sa.approval_status = 'approved'
        ORDER BY sa.uploaded_at DESC
    ");
    $bomStmt->bind_param("i", $client_id);
    $bomStmt->execute();
    $bomResult = $bomStmt->get_result();
    while ($row = $bomResult->fetch_assoc())
        $bomApprovedFiles[] = $row;
}

// Fetch files
$files = [];
if ($stage_id) {
    $fStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=?
        ORDER BY sa.uploaded_at DESC
    ");
    $fStmt->bind_param("i", $stage_id);
    $fStmt->execute();
    $fResult = $fStmt->get_result();
    while ($row = $fResult->fetch_assoc()) {
        $row['role_reviews'] = [];
        if ($isApproval) {
            $rStmt = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name, sar.reviewed_at FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by=a.id WHERE sar.approval_id=?");
            $rStmt->bind_param("i", $row['id']);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            while ($rev = $rRes->fetch_assoc())
                $row['role_reviews'][$rev['reviewer_role']] = $rev;
        }
        $files[] = $row;
    }
}

// Build unique category list from labels
$categories = [];
foreach ($files as $f) {
    $cat = trim($f['label'] ?? '');
    if ($cat && !in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}

function getRoleDisplayName($role)
{
    $names = ['general_manager' => 'General Manager', 'operational_manager' => 'Operational Manager', 'project_coordinator' => 'Project Coordinator', 'designer' => 'Designer (Head)', 'technical_designer' => 'Technical Designer (Head)', 'accounting' => 'Accounting'];
    return $names[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function fileIcon($ext)
{
    $map = ['pdf' => ['fa-file-pdf', '#ef4444'], 'doc' => ['fa-file-word', '#3b82f6'], 'docx' => ['fa-file-word', '#3b82f6'], 'xls' => ['fa-file-excel', '#10b981'], 'xlsx' => ['fa-file-excel', '#10b981'], 'ppt' => ['fa-file-powerpoint', '#f59e0b'], 'pptx' => ['fa-file-powerpoint', '#f59e0b'], 'png' => ['fa-file-image', '#8b5cf6'], 'jpg' => ['fa-file-image', '#8b5cf6'], 'jpeg' => ['fa-file-image', '#8b5cf6'], 'gif' => ['fa-file-image', '#8b5cf6'], 'txt' => ['fa-file-alt', '#6b7280'], 'csv' => ['fa-file-csv', '#6b7280']];
    return $map[$ext] ?? ['fa-file', '#6b7280'];
}

$stageTypeLabel = $isApproval ? 'Approval Required' : ($isFileUpload ? 'File Upload' : ($isAccounting ? 'Delivery Receipt' : 'Files'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files — <?= htmlspecialchars($stage) ?></title>
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
            max-width: 820px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

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

        /* Header */
        .page-header {
            background: var(--brown-dk);
            border-radius: 14px;
            padding: 28px 30px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .page-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top right, rgba(196, 154, 120, .2) 0%, transparent 60%);
            pointer-events: none;
        }

        .header-inner {
            position: relative;
            z-index: 1;
        }

        .header-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .header-title {
            font-size: 19px;
            font-weight: 700;
            margin-bottom: 3px;
            letter-spacing: -.2px;
        }

        .header-sub {
            font-size: 13px;
            opacity: .7;
        }

        .header-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .hbadge {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 7px;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .hbadge.approval {
            background: rgba(245, 158, 11, .2);
            border-color: rgba(245, 158, 11, .4);
        }

        .hbadge.upload {
            background: rgba(139, 92, 246, .2);
            border-color: rgba(139, 92, 246, .4);
        }

        .hbadge.receipt {
            background: rgba(14, 165, 233, .2);
            border-color: rgba(14, 165, 233, .4);
        }

        /* Upload button */
        .upload-bar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .btn-upload {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--brown-dk);
            color: #fff;
            padding: 10px 20px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all .2s;
            box-shadow: 0 2px 8px rgba(59, 31, 15, .25);
        }

        .btn-upload:hover {
            background: var(--brown-md);
            transform: translateY(-1px);
        }

        /* Section label */
        .section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: var(--text-lt);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            background: var(--surface);
            border: 2px dashed var(--border);
            border-radius: var(--radius);
        }

        .empty-icon {
            font-size: 36px;
            color: var(--border);
            margin-bottom: 12px;
        }

        .empty-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-md);
            margin-bottom: 6px;
        }

        .empty-sub {
            font-size: 13px;
            color: var(--text-lt);
        }

        /* File card */
        .file-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            margin-bottom: 10px;
            box-shadow: var(--shadow);
            transition: box-shadow .2s;
        }

        .file-card:hover {
            box-shadow: 0 4px 20px rgba(59, 31, 15, .12);
        }

        .file-card.approved {
            border-left: 3px solid var(--done);
        }

        .file-card.rejected {
            border-left: 3px solid #ef4444;
        }

        .file-card.pending {
            border-left: 3px solid var(--pending);
        }

        .file-card.po-mirror {
            border-left: 3px solid #0ea5e9;
            background: #f0f9ff;
        }

        .file-row {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .file-icon {
            font-size: 26px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .file-body {
            flex: 1;
            min-width: 0;
        }

        .file-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--brown-md);
            margin-bottom: 3px;
        }

        .file-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dk);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            font-size: 11px;
            color: var(--text-lt);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
        }

        .file-meta span {
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .file-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* Approval badges row */
        .approval-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 8px;
        }

        .apbadge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
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

        .apbadge-date {
            font-size: 9px;
            font-weight: 500;
            opacity: .8;
            margin-left: 3px;
        }

        /* Rejection note */
        .reject-note {
            background: #fee2e2;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 12px;
            color: #991b1b;
            margin-top: 6px;
        }

        /* Status badge */
        .file-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .file-status.approved {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .file-status.rejected {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .file-status.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
            text-decoration: none;
        }

        .btn-view {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .btn-view:hover {
            background: #bfdbfe;
        }

        .btn-approve {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .btn-approve:hover {
            background: #a7f3d0;
        }

        .btn-reject {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .btn-reject:hover {
            background: #fca5a5;
        }

        .btn-delete {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }

        .btn-delete:hover {
            background: #fca5a5;
            color: #991b1b;
            border-color: #fca5a5;
        }

        .btn-resubmit {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .btn-resubmit:hover {
            background: #fde68a;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            animation: popIn .25s ease;
        }

        @keyframes popIn {
            from {
                transform: scale(.94);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .modal-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--brown-dk);
            margin-bottom: 4px;
        }

        .modal-sub {
            font-size: 13px;
            color: var(--text-lt);
            margin-bottom: 22px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-md);
            margin-bottom: 6px;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color .2s;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--brown-lt);
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-hint {
            font-size: 11px;
            color: var(--text-lt);
            margin-top: 5px;
        }

        .form-textarea {
            resize: vertical;
            min-height: 90px;
        }

        .modal-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 6px;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #6b7280;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            font-weight: 700;
            font-size: 13px;
        }

        .btn-submit {
            background: var(--brown-dk);
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            font-weight: 700;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-submit:hover {
            background: var(--brown-md);
        }

        .btn-reject-confirm {
            background: #ef4444;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            font-weight: 700;
            font-size: 13px;
        }

        .form-error {
            display: none;
            font-size: 12px;
            color: #dc2626;
            background: #fee2e2;
            padding: 8px 12px;
            border-radius: 7px;
            margin-bottom: 12px;
        }

        /* Category filter buttons */
        .cat-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--surface);
            color: var(--text-md);
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all .2s;
        }

        .cat-btn:hover {
            background: var(--brown-pale);
            border-color: var(--brown-lt);
        }

        .cat-btn.active {
            background: var(--brown-dk);
            color: #fff;
            border-color: var(--brown-dk);
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

        @keyframes shimmer {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Upload mode toggle */
        .upload-mode-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 8px 14px;
            margin-bottom: 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-md);
            flex-wrap: wrap;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #d1d5db;
            border-radius: 24px;
            transition: .3s;
        }

        .toggle-slider:before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: .3s;
        }

        .toggle-switch input:checked+.toggle-slider {
            background: var(--brown-dk);
        }

        .toggle-switch input:checked+.toggle-slider:before {
            transform: translateX(20px);
        }

        .mode-label {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .mode-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .mode-badge.direct {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .mode-badge.chunked {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
    </style>
</head>

<body>
    <div class="page">

        <!-- Back -->
        <a href="unified-project-tracker?client_id=<?= $client_id ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Tracker
        </a>

        <!-- Header -->
        <div class="page-header">
            <div class="header-inner">
                <div class="header-top">
                    <div style="display:flex; align-items:center; gap:14px;">
                        <div class="header-icon"><i class="fas fa-paperclip"></i></div>
                        <div>
                            <div class="header-title"><?= htmlspecialchars($stage) ?></div>
                            <div class="header-sub"><?= htmlspecialchars($client['clientname']) ?> ·
                                <?= htmlspecialchars($client['nameproject']) ?>
                            </div>
                        </div>
                    </div>
                    <div style="text-align:right; flex-shrink:0;">
                        <div style="font-size:11px; opacity:.6; margin-bottom:3px;">Stage Status</div>
                        <div style="font-size:15px; font-weight:700;">
                            <?php if ($stageStatus === 'Done'): ?><i class="fas fa-check-circle"
                                    style="color:#6ee7b7;"></i>
                            <?php elseif ($stageStatus === 'Ongoing'): ?><i class="fas fa-circle-notch fa-spin"
                                    style="color:#93c5fd;"></i>
                            <?php else: ?><i class="fas fa-clock" style="color:#fde68a;"></i>
                            <?php endif; ?>
                            <?= $stageStatus ?>
                        </div>
                    </div>
                </div>
                <div class="header-badges">
                    <?php if ($isApproval): ?>
                        <span class="hbadge approval"><i class="fas fa-stamp"></i> Approval Required</span>
                        <?php
                        $gmOmStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                        $required = $requiredApproversList[$stage] ?? [];
                        if (in_array($stage, $gmOmStages)):
                            foreach ($required as $role):
                                if (in_array($role, ['general_manager', 'operational_manager']))
                                    continue;
                                ?>
                                <span class="hbadge"><i class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                            <?php endforeach; ?>
                            <span class="hbadge" style="background:rgba(99,102,241,.2);border-color:rgba(99,102,241,.4);">
                                <i class="fas fa-user-check"></i> GM <em
                                    style="opacity:.6;font-size:10px;font-style:normal;">or</em> OM <em
                                    style="opacity:.6;font-size:10px;font-style:normal;">(one required)</em>
                            </span>
                        <?php else:
                            foreach ($required as $role): ?>
                                <span class="hbadge"><i class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                            <?php endforeach; endif; ?>
                    <?php elseif ($isFileUpload): ?>
                        <span class="hbadge upload"><i class="fas fa-file-upload"></i> File Upload Stage</span>
                    <?php elseif ($isAccounting): ?>
                        <span class="hbadge receipt"><i class="fas fa-receipt"></i> Delivery Receipt</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upload button -->
        <?php
        // Check if this user is the accountaid_fk (primary assigned user)
// canUpdate already respects the toggle for both sales and accountaid_fk users
        $canUpload = ($canUpdate && $isAssigned) && $stage_id;

        // For Purchase Order stage, require at least one approved BOM
        if ($stage === 'Purchase Order (Submit to accounting)') {
            $canUpload = ($canUpdate && $isAssigned) && $stage_id && !empty($bomApprovedFiles);
        }

        if ($stage === 'Reference') {
            $isReferenceAssignedSF = (
                $admin_id == ($assignData['designer1_id'] ?? null) ||
                $admin_id == ($assignData['designer2_id'] ?? null) ||
                $admin_id == ($assignData['accountaid_fk'] ?? null)
            );
            // Respect the toggle: also check canUpdate
            $canUpload = $isReferenceAssignedSF && $canUpdate && $stage_id;
        } elseif ($isAccounting) {
            $canUpload = ($canUpdate && $isAssigned) && !empty($poApprovedFiles) && $stageStatus !== 'Done';
        }
        ?>
        <?php if ($canUpload && !$isAccounting && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="upload-bar">
                <button class="btn-upload" onclick="openUploadModal()">
                    <i class="fas fa-plus"></i>
                    <?= $isApproval ? 'Attach File for Approval' : 'Upload File' ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($stage === 'Purchase Order (Submit to accounting)' && $canUpload && false): ?>
            <div class="upload-bar">
                <button class="btn-upload" onclick="openPOUploadModal()">
                    <i class="fas fa-plus"></i> Create Purchase Order
                </button>
            </div>
        <?php elseif ($stage === 'Purchase Order (Submit to accounting)' && empty($bomApprovedFiles)): ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:14px 20px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                <i class="fas fa-hourglass-half" style="color:#d97706; font-size:18px;"></i>
                <div>
                    <div style="font-weight:700; font-size:14px; color:#92400e;">Waiting for Approved BOM</div>
                    <div style="font-size:12px; color:#b45309; margin-top:2px;">A Bill of Materials must be approved before
                        Purchase Orders can be submitted.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- BOM Mirror (Purchase Order stage) — each BOM card shows its own linked POs -->
        <?php if ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <div class="section-label"><i class="fas fa-calculator"></i> Approved Bills of Materials</div>
            <?php if (empty($bomApprovedFiles)): ?>
                <div class="empty-state" style="margin-bottom:24px;">
                    <div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>
                    <div class="empty-title">No Approved BOMs Yet</div>
                    <div class="empty-sub">BOMs will appear here once approved in the Bill of Materials stage.</div>
                </div>
            <?php else: ?>

                <?php
                // Pre-fetch all POs for this stage, grouped by linked_bom_id
                $posByBom = [];
                $allPosStmt = $conn->prepare("
    SELECT sa.*, a.full_name as uploaded_by_name,
           COALESCE(bos.status, 'pending') as order_status
    FROM stage_approvals sa
    LEFT JOIN account a ON sa.uploaded_by = a.id
    LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
    WHERE sa.stage_id = ? AND sa.linked_bom_id IS NOT NULL
    ORDER BY sa.uploaded_at ASC
");
                $allPosStmt->bind_param("i", $stage_id);
                $allPosStmt->execute();
                $allPosResult = $allPosStmt->get_result();
                while ($poRow = $allPosResult->fetch_assoc()) {
                    $poRow['role_reviews'] = [];
                    // Fetch approval reviews for this PO
                    $rStmt = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by = a.id WHERE sar.approval_id = ?");
                    $rStmt->bind_param("i", $poRow['id']);
                    $rStmt->execute();
                    $rRes = $rStmt->get_result();
                    while ($rev = $rRes->fetch_assoc())
                        $poRow['role_reviews'][$rev['reviewer_role']] = $rev;
                    $posByBom[$poRow['linked_bom_id']][] = $poRow;
                }
                ?>

                <?php foreach ($bomApprovedFiles as $bom):
                    $bomExt = strtolower(pathinfo($bom['file_name'], PATHINFO_EXTENSION));
                    [$bomIcon, $bomColor] = fileIcon($bomExt);
                    $linkedPos = $posByBom[$bom['id']] ?? [];
                    $hasPos = !empty($linkedPos);

                    // Order status badge styling
                    $osColors = [
                        'pending' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a', 'label' => 'Not Yet Ordered', 'icon' => 'fa-clock'],
                        'ordered' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#a7f3d0', 'label' => 'Ordered', 'icon' => 'fa-check-circle'],
                        'partially_ordered' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#bfdbfe', 'label' => 'Partially Ordered', 'icon' => 'fa-adjust'],
                    ];
                    $osc = $osColors[$bom['order_status']] ?? $osColors['pending'];
                    ?>
                    <div style="margin-bottom:18px;">
                        <!-- BOM Card -->
                        <div class="file-card"
                            style="background:#f0fdf4; border-left:3px solid #10b981; border-radius:<?= $hasPos ? '10px 10px 0 0' : '10px' ?>; margin-bottom:0; <?= $hasPos ? 'border-bottom:1px dashed #a7f3d0;' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $bomIcon ?> file-icon" style="color:<?= $bomColor ?>;"></i>
                                <div class="file-body">
                                    <?php if ($bom['label']): ?>
                                        <div class="file-label" style="color:#065f46;"><?= htmlspecialchars($bom['label']) ?></div>
                                    <?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($bom['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($bom['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($bom['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($bom['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0;">
                                    <span class="file-status approved"><i class="fas fa-check-circle"></i> Approved BOM</span>
                                    <!-- Order status badge -->
                                    <span
                                        style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:<?= $osc['bg'] ?>;color:<?= $osc['color'] ?>;border:1px solid <?= $osc['border'] ?>;">
                                        <i class="fas <?= $osc['icon'] ?>"></i> <?= $osc['label'] ?>
                                    </span>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <?php
                                        $bomImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                        $bomViewable = in_array($bomExt, $bomImageExts) || $bomExt === 'pdf';
                                        ?>
                                        <?php if ($bomViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $bom['file_path'])) ?: time() ?>"
                                                target="_blank" class="btn btn-view">
                                                <i class="fas fa-eye"></i> View BOM
                                            </a>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>"
                                                download="<?= htmlspecialchars($bom['file_name']) ?>" class="btn btn-view"
                                                style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>"
                                                download="<?= htmlspecialchars($bom['file_name']) ?>" class="btn btn-view"
                                                style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($canUpload): ?>
                                            <button class="btn" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;"
                                                onclick="openPOUploadModal(<?= $bom['id'] ?>, '<?= htmlspecialchars(addslashes($bom['label'] ?: $bom['file_name'])) ?>')">
                                                <i class="fas fa-file-invoice-dollar"></i> Add PO
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <span
                                        style="font-size:11px;font-weight:700;color:<?= $hasPos ? '#065f46' : '#9ca3af' ?>;display:flex;align-items:center;gap:4px;">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <?= count($linkedPos) ?> PO<?= count($linkedPos) !== 1 ? 's' : '' ?> submitted
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- POs nested under this BOM -->
                        <?php if ($hasPos): ?>
                            <div
                                style="background:#f0fdf4;border:1px solid #a7f3d0;border-top:none;border-radius:0 0 10px 10px;padding:10px 16px 14px 16px;">
                                <div
                                    style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#065f46;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                                    <i class="fas fa-file-invoice-dollar"></i> Purchase Orders for this BOM
                                </div>
                                <?php foreach ($linkedPos as $po):
                                    $poExt = strtolower(pathinfo($po['file_name'], PATHINFO_EXTENSION));
                                    [$poIcon, $poColor] = fileIcon($poExt);
                                    $poStatus = $po['approval_status'] ?? 'pending';
                                    $myPoReview = $po['role_reviews'][$admin_role] ?? null;

                                    // GM/OM sequential check for PO stage
                                    $poGmOmCanActNow = true;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $step1Roles = ['accounting', 'technical_designer'];
                                        foreach ($step1Roles as $s1r) {
                                            $s1rev = $po['role_reviews'][$s1r] ?? null;
                                            if (!$s1rev || $s1rev['review_status'] !== 'approved') {
                                                $poGmOmCanActNow = false;
                                                break;
                                            }
                                        }
                                    }
                                    $poGmOmAlreadyActed = false;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $gmRev2 = $po['role_reviews']['general_manager'] ?? null;
                                        $omRev2 = $po['role_reviews']['operational_manager'] ?? null;
                                        if (
                                            ($gmRev2 && in_array($gmRev2['review_status'], ['approved', 'rejected'])) ||
                                            ($omRev2 && in_array($omRev2['review_status'], ['approved', 'rejected']))
                                        ) {
                                            $poGmOmAlreadyActed = true;
                                        }
                                    }
                                    ?>
                                    <div
                                        style="background:#fff;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;margin-bottom:8px;">
                                        <div style="display:flex;gap:12px;align-items:flex-start;">
                                            <i class="fas <?= $poIcon ?>"
                                                style="color:<?= $poColor ?>;font-size:20px;flex-shrink:0;margin-top:2px;"></i>
                                            <div style="flex:1;min-width:0;">
                                                <?php if ($po['label']): ?>
                                                    <div
                                                        style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#065f46;margin-bottom:2px;">
                                                        <?= htmlspecialchars($po['label']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div
                                                    style="font-size:13px;font-weight:600;color:#1c1007;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars($po['file_name']) ?>
                                                </div>
                                                <div
                                                    style="font-size:11px;color:#9c7b6a;display:flex;gap:10px;flex-wrap:wrap;margin-top:3px;">
                                                    <span><i class="fas fa-user"></i>
                                                        <?= htmlspecialchars($po['uploaded_by_name']) ?></span>
                                                    <span><i class="fas fa-calendar"></i>
                                                        <?= date('M d, Y · g:i A', strtotime($po['uploaded_at'])) ?></span>
                                                    <span><i class="fas fa-weight"></i> <?= number_format($po['file_size'] / 1024, 1) ?>
                                                        KB</span>
                                                </div>

                                                <!-- Approval badges for this PO -->
                                                <?php
                                                $reqPoRoles = $requiredApproversList['Purchase Order (Submit to accounting)'] ?? [];
                                                ?>
                                                <div class="approval-badges" style="margin-top:8px;">
                                                    <?php foreach ($reqPoRoles as $role):
                                                        if (in_array($role, ['general_manager', 'operational_manager']))
                                                            continue;
                                                        $rev = $po['role_reviews'][$role] ?? null;
                                                        $bClass = $rev ? $rev['review_status'] : 'pending';
                                                        $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                                        $isMine = ($role === $admin_role);
                                                        ?>
                                                        <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--brown-lt);"' : '' ?>>
                                                            <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                            <?php if ($isMine): ?><em
                                                                    style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                            <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                                <span class="apbadge-date">&middot;
                                                                    <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php
                                                    $gmRev3 = $po['role_reviews']['general_manager'] ?? null;
                                                    $omRev3 = $po['role_reviews']['operational_manager'] ?? null;
                                                    $gmStatus3 = $gmRev3 ? $gmRev3['review_status'] : null;
                                                    $omStatus3 = $omRev3 ? $omRev3['review_status'] : null;
                                                    if ($gmStatus3 === 'approved' || $omStatus3 === 'approved') {
                                                        $cs3 = 'approved';
                                                        $cl3 = 'Approved by ' . ($gmStatus3 === 'approved' ? 'GM' : 'OM');
                                                        $ci3 = 'fa-check-circle';
                                                    } elseif ($gmStatus3 === 'rejected' || $omStatus3 === 'rejected') {
                                                        $cs3 = 'rejected';
                                                        $cl3 = 'Rejected by ' . ($gmStatus3 === 'rejected' ? 'GM' : 'OM');
                                                        $ci3 = 'fa-times-circle';
                                                    } else {
                                                        $cs3 = 'pending';
                                                        $cl3 = 'GM or OM (one required)';
                                                        $ci3 = 'fa-clock';
                                                    }
                                                    $isMineGmOm3 = in_array($admin_role, ['general_manager', 'operational_manager']);
                                                    ?>
                                                    <?php
                                                    $gmOmActedRev3 = null;
                                                    if ($gmStatus3 === 'approved' || $gmStatus3 === 'rejected')
                                                        $gmOmActedRev3 = $gmRev3;
                                                    elseif ($omStatus3 === 'approved' || $omStatus3 === 'rejected')
                                                        $gmOmActedRev3 = $omRev3;
                                                    ?>
                                                    <span class="apbadge <?= $cs3 ?>" <?= $isMineGmOm3 ? 'style="box-shadow:0 0 0 2px var(--brown-lt);"' : '' ?>>
                                                        <i class="fas <?= $ci3 ?>"></i> <?= $cl3 ?>
                                                        <?php if ($gmOmActedRev3 && !empty($gmOmActedRev3['reviewed_at'])): ?>
                                                            <span class="apbadge-date">&middot;
                                                                <?= date('M d, Y g:i A', strtotime($gmOmActedRev3['reviewed_at'])) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>

                                                <!-- Rejection notes for this PO -->
                                                <?php foreach ($po['role_reviews'] as $rKey => $rev):
                                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                                        <div class="reject-note" style="margin-top:6px;">
                                                            <i class="fas fa-comment-alt"></i>
                                                            <strong><?= getRoleDisplayName($rKey) ?>:</strong>
                                                            <?= htmlspecialchars($rev['review_note']) ?>
                                                        </div>
                                                    <?php endif; endforeach; ?>
                                            </div>
                                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                                                <span class="file-status <?= $poStatus ?>">
                                                    <?php if ($poStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                                    <?php elseif ($poStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                                    <?php else: ?><i class="fas fa-clock"></i>
                                                    <?php endif; ?>
                                                    <?= ucfirst($poStatus) ?>
                                                </span>
                                                <?php
                                                $poImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                                $poViewable = in_array($poExt, $poImageExts) || $poExt === 'pdf';
                                                ?>
                                                <?php if ($poViewable): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $po['file_path'])) ?: time() ?>"
                                                        target="_blank" class="btn btn-view">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>"
                                                        download="<?= htmlspecialchars($po['file_name']) ?>" class="btn btn-view"
                                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>"
                                                        download="<?= htmlspecialchars($po['file_name']) ?>" class="btn btn-view"
                                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($canApprove && !$myPoReview && $poGmOmCanActNow && !$poGmOmAlreadyActed && $poStatus === 'pending'): ?>
                                                    <button class="btn btn-approve" onclick="approveFile(<?= $po['id'] ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-reject" onclick="openRejectModal(<?= $po['id'] ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php elseif ($canApprove && ($myPoReview || $poGmOmAlreadyActed)): ?>
                                                    <span
                                                        style="font-size:11px;color:#059669;font-weight:600;display:flex;align-items:center;gap:4px;">
                                                        <i class="fas fa-check-double"></i> You reviewed this
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($po['uploaded_by'] == $admin_id && $poStatus !== 'approved'): ?>
                                                    <?php if ($poStatus === 'rejected'): ?>
                                                        <button class="btn btn-resubmit"
                                                            onclick="openPOUploadModal(<?= $bom['id'] ?>, '<?= htmlspecialchars(addslashes($bom['label'] ?: $bom['file_name'])) ?>', '<?= htmlspecialchars(addslashes($po['label'] ?? '')) ?>')">
                                                            <i class="fas fa-redo"></i> Re-submit
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-delete" onclick="deleteFile(<?= $po['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Update order status for this BOM -->
                                <?php if ($canUpdate && $isAssigned): ?>
                                    <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                        <span style="font-size:12px;font-weight:700;color:#065f46;">Mark order status:</span>
                                        <button class="btn" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;"
                                            onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'pending')">
                                            <i class="fas fa-clock"></i> Not Ordered
                                        </button>
                                        <button class="btn" style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;"
                                            onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'partially_ordered')">
                                            <i class="fas fa-adjust"></i> Partially Ordered
                                        </button>
                                        <button class="btn" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;"
                                            onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'ordered')">
                                            <i class="fas fa-check-circle"></i> Fully Ordered
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        <?php endif; // end Purchase Order stage section ?>

        <!-- PO Mirror (Accounting only) — each PO card shows its own linked receipts -->
        <?php if ($isAccounting): ?>
            <div class="section-label"><i class="fas fa-file-import"></i> Purchase Orders & Receipts</div>
            <?php if (empty($poApprovedFiles)): ?>
                <div class="empty-state" style="margin-bottom:24px;">
                    <div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>
                    <div class="empty-title">Waiting for PO Approval</div>
                    <div class="empty-sub">Purchase Order files will appear here once approved.</div>
                </div>
            <?php else: ?>

                <?php
                // Pre-fetch all receipts for this accounting stage, grouped by linked_po_id
                $receiptsByPo = [];
                $allReceiptsStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ? AND sa.linked_po_id IS NOT NULL
        ORDER BY sa.uploaded_at ASC
    ");
                $allReceiptsStmt->bind_param("i", $stage_id);
                $allReceiptsStmt->execute();
                $allReceiptsResult = $allReceiptsStmt->get_result();
                while ($rc = $allReceiptsResult->fetch_assoc()) {
                    $receiptsByPo[$rc['linked_po_id']][] = $rc;
                }
                ?>

                <?php foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fiIcon, $fiColor] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <!-- PO Card -->
                    <div style="margin-bottom:18px;">
                        <div class="file-card po-mirror"
                            style="margin-bottom:0; border-radius:<?= $hasReceipt ? '10px 10px 0 0' : '10px' ?>; border-bottom:<?= $hasReceipt ? '1px dashed #bae6fd' : '' ?>;">
                            <div class="file-row">
                                <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                                <div class="file-body">
                                    <?php if ($pof['label']): ?>
                                        <div class="file-label"><?= htmlspecialchars($pof['label']) ?></div><?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0;">
                                    <span class="file-status approved"><i class="fas fa-check-circle"></i> Approved PO</span>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <?php
                                        $pofExt2 = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                                        $pofViewable = in_array($pofExt2, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $pofExt2 === 'pdf';
                                        ?>
                                        <?php if ($pofViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $pof['file_path'])) ?: time() ?>"
                                                target="_blank" class="btn btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>"
                                                download="<?= htmlspecialchars($pof['file_name']) ?>" class="btn btn-view"
                                                style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>"
                                                download="<?= htmlspecialchars($pof['file_name']) ?>" class="btn btn-view"
                                                style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($canUpload): ?>
                                            <button class="btn" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;"
                                                onclick="openReceiptModal(<?= $pof['id'] ?>, '<?= htmlspecialchars(addslashes($pof['label'] ?: $pof['file_name'])) ?>')">
                                                <i class="fas fa-upload"></i> Upload Receipt
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Receipt count badge -->
                                    <span
                                        style="font-size:11px; font-weight:700; color:<?= $hasReceipt ? '#0369a1' : '#9ca3af' ?>; display:flex; align-items:center; gap:4px;">
                                        <i class="fas fa-receipt"></i>
                                        <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Receipts nested under this PO -->
                        <?php if ($hasReceipt): ?>
                            <div
                                style="background:#f0f9ff; border:1px solid #bae6fd; border-top:none; border-radius:0 0 10px 10px; padding:10px 16px 14px 16px;">
                                <div
                                    style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#0369a1; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIcon, $rcColor] = fileIcon($rcExt);
                                    ?>
                                    <div
                                        style="background:#fff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; gap:12px; align-items:center;">
                                        <i class="fas <?= $rcIcon ?>" style="color:<?= $rcColor ?>; font-size:20px; flex-shrink:0;"></i>
                                        <div style="flex:1; min-width:0;">
                                            <?php if ($rc['label']): ?>
                                                <div
                                                    style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#0369a1; margin-bottom:2px;">
                                                    <?= htmlspecialchars($rc['label']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div
                                                style="font-size:13px; font-weight:600; color:#1c1007; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?= htmlspecialchars($rc['file_name']) ?>
                                            </div>
                                            <div
                                                style="font-size:11px; color:#9c7b6a; display:flex; gap:10px; flex-wrap:wrap; margin-top:3px;">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i>
                                                    <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?>
                                                    KB</span>
                                            </div>
                                        </div>
                                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex-shrink:0;">
                                            <?php
                                            $rcViewable = in_array($rcExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $rcExt === 'pdf';
                                            ?>
                                            <?php if ($rcViewable): ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $rc['file_path'])) ?: time() ?>"
                                                    target="_blank" class="btn btn-view">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>"
                                                    download="<?= htmlspecialchars($rc['file_name']) ?>" class="btn btn-view"
                                                    style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>"
                                                    download="<?= htmlspecialchars($rc['file_name']) ?>" class="btn btn-view"
                                                    style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($rc['uploaded_by'] == $admin_id && $stageStatus !== 'Done'): ?>
                                                <button class="btn btn-delete" onclick="deleteFile(<?= $rc['id'] ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div><!-- end PO group -->
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Old unlinked receipts (receipts uploaded before this feature, linked_po_id IS NULL) -->
            <?php
            $unlinkedReceiptsStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ? AND sa.linked_po_id IS NULL AND sa.approval_status = 'approved'
        AND sa.id NOT IN (SELECT id FROM stage_approvals WHERE stage_id = ? AND linked_po_id IS NULL
                          AND id IN (SELECT id FROM stage_approvals sa2
                                     WHERE sa2.stage_id = ?
                                     AND sa2.client_id = (SELECT client_id FROM project_tracker WHERE id = ?)))
        ORDER BY sa.uploaded_at DESC
    ");
            // Simpler: just fetch non-PO receipts for this accounting stage_id
            $unlinkedReceiptsStmt2 = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ? AND sa.linked_po_id IS NULL
        ORDER BY sa.uploaded_at DESC
    ");
            $unlinkedReceiptsStmt2->bind_param("i", $stage_id);
            $unlinkedReceiptsStmt2->execute();
            $unlinkedReceipts = $unlinkedReceiptsStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            // Filter out the PO files themselves (which are from the PO stage, not this stage)
            // These are receipts uploaded directly to this accounting stage without a linked PO
            ?>
            <?php if (!empty($unlinkedReceipts)): ?>
                <div class="section-label" style="margin-top:24px;"><i class="fas fa-receipt"></i> Other Receipts (Unlinked)
                </div>
                <?php foreach ($unlinkedReceipts as $f):
                    $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                    [$fiIcon, $fiColor] = fileIcon($ext);
                    ?>
                    <div class="file-card approved" style="margin-bottom:10px;">
                        <div class="file-row">
                            <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                            <div class="file-body">
                                <?php if ($f['label']): ?>
                                    <div class="file-label"><?= htmlspecialchars($f['label']) ?></div><?php endif; ?>
                                <div class="file-name"><?= htmlspecialchars($f['file_name']) ?></div>
                                <div class="file-meta">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['uploaded_by_name']) ?></span>
                                    <span><i class="fas fa-calendar"></i>
                                        <?= date('M d, Y · g:i A', strtotime($f['uploaded_at'])) ?></span>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; flex-shrink:0;">
                                <?php
                                $fExt2 = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                                $fViewable2 = in_array($fExt2, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $fExt2 === 'pdf';
                                ?>
                                <?php if ($fViewable2): ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $f['file_path'])) ?: time() ?>"
                                        target="_blank" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>"
                                        download="<?= htmlspecialchars($f['file_name']) ?>" class="btn btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>"
                                        download="<?= htmlspecialchars($f['file_name']) ?>" class="btn btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php endif; ?>
                                <?php if ($f['uploaded_by'] == $admin_id && $stageStatus !== 'Done'): ?>
                                    <button class="btn btn-delete" onclick="deleteFile(<?= $f['id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div style="height:8px;"></div>
        <?php elseif ($isApproval && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="section-label"><i class="fas fa-folder-open"></i> Submitted Files</div>
        <?php elseif ($stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="section-label"><i class="fas fa-folder-open"></i> Uploaded Files</div>
        <?php endif; ?>

        <!-- Category filter — hidden for Accounting stage and PO stage -->
        <?php if (!empty($categories) && !$isAccounting && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div id="categoryFilter" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                <button class="cat-btn active" onclick="filterCategory('all', this)">
                    <i class="fas fa-th-large"></i> All
                </button>
                <?php foreach ($categories as $cat): ?>
                    <button class="cat-btn" onclick="filterCategory('<?= htmlspecialchars(addslashes($cat)) ?>', this)">
                        <?= htmlspecialchars($cat) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Files list — hidden for Accounting stage -->
        <?php if ($isAccounting): ?>
            <?php /* Receipts are displayed per-PO in the section above */ ?>
        <?php elseif ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php /* POs are displayed per-BOM in the section above, uploaded files hidden */ ?>
        <?php elseif (empty($files) && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-file"></i></div>
                <div class="empty-title">No files yet</div>
                <div class="empty-sub">
                    <?php if ($canUpload): ?>
                        Click the button above to <?= $isApproval ? 'submit a file for approval' : 'upload a file' ?>.
                    <?php elseif (!$canUpdate && !$isApproval): ?>
                        You don't have permission to upload files to this stage.
                    <?php else: ?>
                        No files have been uploaded for this stage yet.
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($stage !== 'Purchase Order (Submit to accounting)'): ?>
            <?php foreach ($files as $f):
                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                [$fiIcon, $fiColor] = fileIcon($ext);
                $fStatus = $f['approval_status'] ?? 'pending';
                $myReview = $f['role_reviews'][$admin_role] ?? null;
                ?>
                <div class="file-card <?= $fStatus ?>" data-category="<?= htmlspecialchars($f['label'] ?? '') ?>">
                    <div class="file-row">
                        <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                        <div class="file-body">
                            <?php if ($f['label']): ?>
                                <div class="file-label"><?= htmlspecialchars($f['label']) ?></div><?php endif; ?>
                            <div class="file-name"><?= htmlspecialchars($f['file_name']) ?></div>
                            <div class="file-meta">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['uploaded_by_name']) ?></span>
                                <span><i class="fas fa-calendar"></i>
                                    <?= date('M d, Y · g:i A', strtotime($f['uploaded_at'])) ?></span>
                                <span><i class="fas fa-weight"></i> <?= number_format($f['file_size'] / 1024, 1) ?> KB</span>
                            </div>

                            <!-- Approval badges for approval stages -->
                            <?php if ($isApproval && !empty($requiredApproversList[$stage])): ?>
                                <?php
                                $gmOmStages2 = [
                                    'Rough Estimation',
                                    'Samples Submitted TDS/SDS',
                                    'Quotation',
                                    'Bill of Materials (BOM)',
                                    'Purchase Order (Submit to accounting)',
                                    'Production Data Submittals'
                                ];
                                $reqRoles = $requiredApproversList[$stage];
                                ?>
                                <div class="approval-badges">
                                    <?php if (in_array($stage, $gmOmStages2)):
                                        // Step 1 roles first (non GM/OM)
                                        foreach ($reqRoles as $role):
                                            if (in_array($role, ['general_manager', 'operational_manager']))
                                                continue;
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bClass = $rev ? $rev['review_status'] : 'pending';
                                            $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--brown-lt);"' : '' ?>>
                                                <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="apbadge-date">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach;

                                        // Combined GM/OM badge
                                        $gmRev = $f['role_reviews']['general_manager'] ?? null;
                                        $omRev = $f['role_reviews']['operational_manager'] ?? null;
                                        $gmStatus = $gmRev ? $gmRev['review_status'] : null;
                                        $omStatus = $omRev ? $omRev['review_status'] : null;

                                        if ($gmStatus === 'approved' || $omStatus === 'approved') {
                                            $combinedStatus = 'approved';
                                            $whoActed = $gmStatus === 'approved'
                                                ? getRoleDisplayName('general_manager')
                                                : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Approved by {$whoActed}";
                                            $combinedIcon = 'fa-check-circle';
                                        } elseif ($gmStatus === 'rejected' || $omStatus === 'rejected') {
                                            $combinedStatus = 'rejected';
                                            $whoActed = $gmStatus === 'rejected'
                                                ? getRoleDisplayName('general_manager')
                                                : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Rejected by {$whoActed}";
                                            $combinedIcon = 'fa-times-circle';
                                        } else {
                                            $combinedStatus = 'pending';
                                            $combinedLabel = 'GM or OM (one required)';
                                            $combinedIcon = 'fa-clock';
                                        }
                                        $isMineGmOm = in_array($admin_role, ['general_manager', 'operational_manager']);
                                        ?>
                                        <?php
                                        $gmOmActedRev = null;
                                        if ($gmStatus === 'approved' || $gmStatus === 'rejected')
                                            $gmOmActedRev = $gmRev;
                                        elseif ($omStatus === 'approved' || $omStatus === 'rejected')
                                            $gmOmActedRev = $omRev;
                                        ?>
                                        <span class="apbadge <?= $combinedStatus ?>" <?= $isMineGmOm ? 'style="box-shadow:0 0 0 2px var(--brown-lt);"' : '' ?>>
                                            <i class="fas <?= $combinedIcon ?>"></i>
                                            <?= $combinedLabel ?>
                                            <?php if ($isMineGmOm && ($gmRev || $omRev)): ?><em
                                                    style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                            <?php if ($gmOmActedRev && !empty($gmOmActedRev['reviewed_at'])): ?>
                                                <span class="apbadge-date">&middot;
                                                    <?= date('M d, Y g:i A', strtotime($gmOmActedRev['reviewed_at'])) ?></span>
                                            <?php endif; ?>
                                        </span>

                                    <?php else:
                                        // Non-sequential stages — simple loop
                                        foreach ($reqRoles as $role):
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bClass = $rev ? $rev['review_status'] : 'pending';
                                            $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--brown-lt);"' : '' ?>>
                                                <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="apbadge-date">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; endif; ?>
                                </div>
                                <!-- Step 1 pending notice for GM/OM -->
                                <?php
                                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages2)):
                                    // Check if all step1 roles have approved
                                    $sequentialStagesInfo = [
                                        'Rough Estimation' => ['designer'],
                                        'Samples Submitted TDS/SDS' => ['technical_designer'],
                                        'Quotation' => ['designer'],
                                        'Bill of Materials (BOM)' => ['technical_designer'],
                                        'Purchase Order (Submit to accounting)' => ['accounting', 'technical_designer'],
                                        'Production Data Submittals' => ['technical_designer'],
                                    ];
                                    $step1Roles = $sequentialStagesInfo[$stage] ?? [];
                                    $step1AllDone = true;
                                    $missingStep1 = [];
                                    foreach ($step1Roles as $s1r) {
                                        $s1rev = $f['role_reviews'][$s1r] ?? null;
                                        if (!$s1rev || $s1rev['review_status'] !== 'approved') {
                                            $step1AllDone = false;
                                            $missingStep1[] = getRoleDisplayName($s1r);
                                        }
                                    }
                                    if (!$step1AllDone && $fStatus === 'pending'):
                                        ?>
                                        <div
                                            style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;font-size:12px;color:#92400e;margin-top:6px;display:flex;align-items:center;gap:6px;">
                                            <i class="fas fa-hourglass-half" style="color:#d97706;flex-shrink:0;"></i>
                                            <span>Waiting for <strong><?= implode(' and ', $missingStep1) ?></strong> to approve first
                                                before you can review this file.</span>
                                        </div>
                                    <?php endif; endif; ?>

                                <!-- Rejection notes -->
                                <?php foreach ($f['role_reviews'] as $rKey => $rev):
                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                        <div class="reject-note">
                                            <i class="fas fa-comment-alt"></i>
                                            <strong><?= getRoleDisplayName($rKey) ?>:</strong>
                                            <?= htmlspecialchars($rev['review_note']) ?>
                                            <?php if ($rev['reviewer_name']): ?> —
                                                <em><?= htmlspecialchars($rev['reviewer_name']) ?></em><?php endif; ?>
                                        </div>
                                    <?php endif; endforeach; ?>
                            <?php endif; ?>

                            <!-- Action row -->
                            <div class="file-actions" style="margin-top:10px;">
                                <?php
                                $imageExts2 = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                $isViewable2 = strpos($f['file_type'] ?? '', 'image/') === 0 || in_array($ext, $imageExts2) || $ext === 'pdf';
                                ?>
                                <?php if ($isViewable2): ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $f['file_path'])) ?: time() ?>"
                                        target="_blank" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>"
                                        download="<?= htmlspecialchars($f['file_name']) ?>" class="btn btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>"
                                        download="<?= htmlspecialchars($f['file_name']) ?>" class="btn btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php endif; ?>

                                <?php
                                // For GM/OM on sequential stages: hide approve/reject if step1 not done yet
                                $gmOmCanActNow = true;
                                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages2)) {
                                    $seqInfo2 = [
                                        'Rough Estimation' => ['designer'],
                                        'Samples Submitted TDS/SDS' => ['technical_designer'],
                                        'Quotation' => ['designer'],
                                        'Bill of Materials (BOM)' => ['technical_designer'],
                                        'Purchase Order (Submit to accounting)' => ['accounting', 'technical_designer'],
                                        'Production Data Submittals' => ['technical_designer'],
                                    ];
                                    $s1Roles2 = $seqInfo2[$stage] ?? [];
                                    foreach ($s1Roles2 as $s1r2) {
                                        $s1rev2 = $f['role_reviews'][$s1r2] ?? null;
                                        if (!$s1rev2 || $s1rev2['review_status'] !== 'approved') {
                                            $gmOmCanActNow = false;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <?php
                                // For GM/OM: if either one has already approved or rejected, hide buttons for the other
                                $gmOmAlreadyActed = false;
                                if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                    $gmRev2 = $f['role_reviews']['general_manager'] ?? null;
                                    $omRev2 = $f['role_reviews']['operational_manager'] ?? null;
                                    if (
                                        ($gmRev2 && in_array($gmRev2['review_status'], ['approved', 'rejected'])) ||
                                        ($omRev2 && in_array($omRev2['review_status'], ['approved', 'rejected']))
                                    ) {
                                        $gmOmAlreadyActed = true;
                                    }
                                }
                                ?>
                                <?php if ($isApproval && $canApprove && !$myReview && $gmOmCanActNow && !$gmOmAlreadyActed): ?>
                                    <button class="btn btn-approve" onclick="approveFile(<?= $f['id'] ?>)">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn btn-reject" onclick="openRejectModal(<?= $f['id'] ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php elseif ($isApproval && $canApprove && ($myReview || $gmOmAlreadyActed)): ?>
                                    <span
                                        style="font-size:11px; color:#059669; font-weight:600; display:flex; align-items:center; gap:4px;">
                                        <i class="fas fa-check-double"></i>
                                        <?= $myReview ? 'You reviewed this' : 'Already reviewed by ' . (isset($f['role_reviews']['general_manager']) ? 'General Manager' : 'Operational Manager') ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($fStatus === 'rejected' && $f['uploaded_by'] == $admin_id): ?>
                                    <button class="btn btn-resubmit"
                                        onclick="openUploadModal('<?= htmlspecialchars(addslashes($f['label'])) ?>')">
                                        <i class="fas fa-redo"></i> Re-submit
                                    </button>
                                <?php endif; ?>

                                <?php if ($f['uploaded_by'] == $admin_id && $stageStatus !== 'Done' && ($fStatus !== 'approved' || $isFileUpload)): ?>
                                    <button class="btn btn-delete" onclick="deleteFile(<?= $f['id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                <?php endif; ?>

                                <span class="file-status <?= $fStatus ?>" style="margin-left:auto;">
                                    <?php if ($fStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                    <?php elseif ($fStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                    <?php else: ?><i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?= ucfirst($fStatus) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; /* end isAccounting check */ ?>

        <!-- Mark as done button (file upload stages) -->
        <?php
        // Fetch assigned staff for this client
        $sfAssignStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id FROM user_info WHERE id = ?");
        $sfAssignStmt->bind_param("i", $client_id);
        $sfAssignStmt->execute();
        $sfAssignRow = $sfAssignStmt->get_result()->fetch_assoc();
        $sfDesigner1Id = $sfAssignRow['designer1_id'] ?? null;
        $sfDesigner2Id = $sfAssignRow['designer2_id'] ?? null;
        $sfTechDesignId = $sfAssignRow['technical_designer_id'] ?? null;
        $sfProjCoordId = $sfAssignRow['project_coordinator_id'] ?? null;

        $sfCanMarkDone = false;
        $sfCanCancelDone = false;

        // accountaid_fk has full control over their assigned client's stages
        $isAccountFkSF = ($admin_id == ($assignData['accountaid_fk'] ?? null));

        if ($stage === 'Reference') {
            $isRefUserSF = (
                $admin_id == $sfDesigner1Id ||
                $admin_id == $sfDesigner2Id ||
                $admin_id == ($assignData['accountaid_fk'] ?? null)
            );
            // Respect the toggle: also check canUpdate
            if ($isRefUserSF && $canUpdate && in_array($stageStatus, ['Pending', 'Ongoing'])) {
                $sfCanMarkDone = true;
            }
            if ($isRefUserSF && $canUpdate && $stageStatus === 'Done') {
                $sfCanCancelDone = true;
            }
        } elseif (($isFileUpload || $isAccounting) && $canUpdate && $stageStatus === 'Ongoing' && !empty($files)) {
            if ($stage === 'Internal P.O to Accounting') {
                // Can only mark Done if the internal_po_approval is fully approved
                $ipoApproved = ($internalPoApproval && $internalPoApproval['overall_status'] === 'approved');
                $sfCanMarkDone = $ipoApproved && ($admin_id == $sfProjCoordId || $admin_role === 'sales' || $isAccountFkSF);
            } elseif ($stage === 'Handover') {
                $sfCanMarkDone = ($admin_id == $sfTechDesignId || $admin_id == $sfProjCoordId || $isAccountFk);
            } elseif ($isAccounting) {
                // Require Purchase Order stage to be Done before Accounting can be marked Done
                $poStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
                $poStatusStmt->bind_param("i", $client_id);
                $poStatusStmt->execute();
                $poStatusRow = $poStatusStmt->get_result()->fetch_assoc();
                if (($poStatusRow['status'] ?? '') === 'Done') {
                    $sfCanMarkDone = true;
                }
            }
        } elseif ($isApproval && $canUpdate && $stageStatus === 'Ongoing' && !empty($files)) {
            $allApproved = true;
            foreach ($files as $f) {
                if (($f['approval_status'] ?? 'pending') !== 'approved') {
                    $allApproved = false;
                    break;
                }
            }
            if ($allApproved) {
                // Purchase Order: require BOM stage to be Done first
                if ($stage === 'Purchase Order (Submit to accounting)') {
                    $bomStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1");
                    $bomStatusStmt->bind_param("i", $client_id);
                    $bomStatusStmt->execute();
                    $bomStatusRow = $bomStatusStmt->get_result()->fetch_assoc();
                    if (($bomStatusRow['status'] ?? '') === 'Done') {
                        $sfCanMarkDone = true;
                    }
                }
                // Accounting: require Purchase Order stage to be Done first
                elseif ($stage === 'Accounting (Order Processing)') {
                    $poStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
                    $poStatusStmt->bind_param("i", $client_id);
                    $poStatusStmt->execute();
                    $poStatusRow = $poStatusStmt->get_result()->fetch_assoc();
                    if (($poStatusRow['status'] ?? '') === 'Done') {
                        $sfCanMarkDone = true;
                    }
                }
                // All other approval stages
                else {
                    $sfCanMarkDone = true;
                }
            }
        }
        ?>

        <?php
        // Show blocker notice for Accounting if PO is not Done
        if ($isAccounting && $stageStatus === 'Ongoing' && !empty($files) && !$sfCanMarkDone && $canUpdate) {
            $poCheckStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
            $poCheckStmt->bind_param("i", $client_id);
            $poCheckStmt->execute();
            $poCheckRow = $poCheckStmt->get_result()->fetch_assoc();
            if (($poCheckRow['status'] ?? '') !== 'Done'):
                ?>
                <div
                    style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:14px 20px; margin-top:20px; display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-lock" style="color:#d97706; font-size:18px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700; font-size:14px; color:#92400e;">Cannot mark as Done yet</div>
                        <div style="font-size:12px; color:#b45309; margin-top:2px;">The <strong>Purchase Order</strong> stage
                            must be marked as Done before Accounting can be completed.</div>
                    </div>
                </div>
            <?php endif;
        } ?>



        <?php if ($sfCanMarkDone || $sfCanCancelDone): ?>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                <?php if ($sfCanMarkDone): ?>
                    <button class="btn-upload" onclick="markDone()" style="background:var(--done);">
                        <i class="fas fa-check-circle"></i> Mark Stage as Done
                    </button>
                <?php endif; ?>
                <?php if ($sfCanCancelDone): ?>
                    <button class="btn-upload" onclick="cancelDone()" style="background:#ef4444;">
                        <i class="fas fa-undo"></i> Cancel (Revert to Ongoing)
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Internal P.O to Accounting — Stage-level Approval Panel -->
    <?php if ($isInternalPo): ?>
        <div
            style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;margin-top:24px;margin-bottom:20px;box-shadow:var(--shadow);max-width:820px;margin-left:auto;margin-right:auto;">
            <div
                style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-lt);margin-bottom:14px;display:flex;align-items:center;gap:7px;">
                <i class="fas fa-stamp"></i> Stage Approval Status
            </div>

            <?php if (!$internalPoApproval): ?>
                <!-- No approval requested yet -->
                <div
                    style="background:#faf8f5;border:2px dashed var(--border);border-radius:10px;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div
                            style="width:40px;height:40px;border-radius:10px;background:var(--brown-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-paper-plane" style="color:var(--brown-md);font-size:16px;"></i>
                        </div>
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--text-dk);">No approval requested yet</div>
                            <div style="font-size:12px;color:var(--text-lt);margin-top:3px;">Upload your files then request
                                approval from Accounting and Head Designer.</div>
                        </div>
                    </div>
                    <?php if ($canRequestInternalPoApproval && !empty($files)): ?>
                        <button class="btn-upload" onclick="requestInternalPoApproval()"
                            style="background:var(--brown-dk);flex-shrink:0;">
                            <i class="fas fa-paper-plane"></i> Request Approval
                        </button>
                    <?php elseif ($canRequestInternalPoApproval && empty($files)): ?>
                        <button class="btn-upload" disabled style="background:#9ca3af;cursor:not-allowed;flex-shrink:0;">
                            <i class="fas fa-paper-plane"></i> Upload files first
                        </button>
                    <?php endif; ?>
                </div>

            <?php else:
                $ipa = $internalPoApproval;
                $overallStatus = $ipa['overall_status'];
                $overallColors = [
                    'pending' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'color' => '#92400e', 'icon' => 'fa-clock'],
                    'approved' => ['bg' => '#f0fdf4', 'border' => '#6ee7b7', 'color' => '#065f46', 'icon' => 'fa-check-circle'],
                    'rejected' => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'color' => '#991b1b', 'icon' => 'fa-times-circle'],
                ];
                $oc = $overallColors[$overallStatus];
                ?>
                <!-- Overall status banner -->
                <div
                    style="background:<?= $oc['bg'] ?>;border:1px solid <?= $oc['border'] ?>;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:9px;">
                    <i class="fas <?= $oc['icon'] ?>" style="color:<?= $oc['color'] ?>;font-size:16px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:<?= $oc['color'] ?>;">
                            <?php if ($overallStatus === 'pending'): ?>Approval in
                                progress<?php elseif ($overallStatus === 'approved'): ?>Fully approved — stage can be marked
                                Done<?php else: ?>Rejected — please fix and re-request<?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:<?= $oc['color'] ?>;opacity:.8;margin-top:2px;">
                            Requested by <?= htmlspecialchars($ipa['requested_by_name']) ?> ·
                            <?= date('M d, Y g:i A', strtotime($ipa['requested_at'])) ?>
                        </div>
                    </div>
                    <?php if ($overallStatus === 'rejected' && $canRequestInternalPoApproval): ?>
                        <button onclick="resetInternalPoApproval(<?= $ipa['id'] ?>)" class="btn"
                            style="margin-left:auto;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;flex-shrink:0;">
                            <i class="fas fa-redo"></i> Re-request
                        </button>
                    <?php endif; ?>
                </div>

                <!-- NTP files linked to this client's payments -->
                <?php
                $ntpStmt = $conn->prepare("
                    SELECT n.*, a.full_name as uploader_name, ps.payment_type
                    FROM notice_to_proceed n
                    LEFT JOIN account a ON a.id = n.uploaded_by
                    LEFT JOIN payment_schedule ps ON ps.id = n.payment_id
                    WHERE n.client_id = ?
                    ORDER BY n.uploaded_at DESC
                ");
                $ntpStmt->bind_param("i", $client_id);
                $ntpStmt->execute();
                $ntpFiles = $ntpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!empty($ntpFiles)): ?>
                    <div style="margin-top:16px;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-lt);margin-bottom:10px;display:flex;align-items:center;gap:7px;">
                            <i class="fas fa-file-signature" style="color:#0369a1;"></i> Notice to Proceed (NTP) Files
                        </div>
                        <?php foreach ($ntpFiles as $ntp):
                            $ntpExt = strtolower(pathinfo($ntp['file_name'], PATHINFO_EXTENSION));
                            $ntpViewable = in_array($ntpExt, ['jpg','jpeg','png','gif','webp','bmp','svg']) || $ntpExt === 'pdf';
                            ?>
                            <div style="background:#f0f9ff;border:1px solid #7dd3fc;border-radius:8px;padding:12px 14px;margin-bottom:8px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                    <div>
                                        <div style="font-size:12px;font-weight:700;color:#0369a1;margin-bottom:3px;">
                                            <i class="fas fa-file-signature"></i>
                                            NTP — <?= htmlspecialchars($ntp['payment_type'] ?? 'Payment') ?>
                                        </div>
                                        <div style="font-size:11px;color:#6b7280;">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($ntp['uploader_name']) ?>
                                            &bull; <?= date('M d, Y g:i A', strtotime($ntp['uploaded_at'])) ?>
                                        </div>
                                        <?php if (!empty($ntp['notes'])): ?>
                                            <div style="font-size:11px;color:#374151;background:#e0f2fe;border-radius:6px;padding:5px 8px;margin-top:5px;">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($ntp['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:6px;">
                                        <?php if ($ntpViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($ntp['file_path']) ?>" target="_blank" class="btn btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($ntp['file_path']) ?>" download="<?= htmlspecialchars($ntp['file_name']) ?>" class="btn btn-view" style="background:#dcfce7;color:#166534;border-color:#86efac;">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Step-by-step reviewer badges -->
                <div style="display:flex;flex-direction:column;gap:10px;">

                    <!-- Step 1: Accounting -->
                    <?php
                    $acStatus = $ipa['accounting_status'];
                    $acColors = ['pending' => ['#f3f4f6', '#9ca3af', '#e5e7eb', 'fa-clock'], 'approved' => ['#d1fae5', '#065f46', '#10b981', 'fa-check-circle'], 'rejected' => ['#fee2e2', '#991b1b', '#ef4444', 'fa-times-circle']];
                    $acc = $acColors[$acStatus];
                    ?>
                    <div style="background:<?= $acc[0] ?>;border:1px solid <?= $acc[2] ?>;border-radius:8px;padding:12px 14px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span
                                    style="background:<?= $acc[2] ?>;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">1</span>
                                <div>
                                    <div
                                        style="font-size:12px;font-weight:700;color:<?= $acc[1] ?>;display:flex;align-items:center;gap:5px;">
                                        <i class="fas <?= $acc[3] ?>"></i> Accounting
                                        <?php if ($acStatus === 'pending' && $ipa['accounting_status'] === 'pending'): ?>
                                            <span
                                                style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:1px 7px;border-radius:10px;font-size:10px;">Waiting</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($ipa['accounting_reviewed_at']): ?>
                                        <div style="font-size:11px;color:var(--text-lt);margin-top:2px;">
                                            <?= htmlspecialchars($ipa['accounting_reviewer_name']) ?> ·
                                            <?= date('M d, Y g:i A', strtotime($ipa['accounting_reviewed_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ipa['accounting_remark']): ?>
                                        <div
                                            style="font-size:12px;color:#991b1b;margin-top:5px;background:#fee2e2;border-radius:6px;padding:6px 10px;font-style:italic;">
                                            <i class="fas fa-comment-alt"></i> "<?= htmlspecialchars($ipa['accounting_remark']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canReviewInternalPoAccounting && $acStatus === 'pending' && $overallStatus === 'pending'): ?>
                                <div style="display:flex;gap:6px;flex-shrink:0;">
                                    <button class="btn btn-approve"
                                        onclick="reviewInternalPo(<?= $ipa['id'] ?>, 'approve', 'accounting')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn btn-reject"
                                        onclick="showInternalPoRejectForm('accounting', <?= $ipa['id'] ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Inline reject form for accounting -->
                        <div id="ipo-reject-form-accounting"
                            style="display:none;margin-top:10px;background:#fff5f5;border:1px solid #fecaca;border-radius:7px;padding:12px;">
                            <div style="font-size:12px;font-weight:700;color:#991b1b;margin-bottom:7px;"><i
                                    class="fas fa-times-circle"></i> Remark / Rejection Note</div>
                            <textarea id="ipo-remark-accounting" class="form-textarea"
                                placeholder="Explain what needs to be fixed..."
                                style="width:100%;min-height:70px;padding:8px 12px;border:1px solid #fca5a5;border-radius:7px;font-size:13px;font-family:inherit;margin-bottom:8px;resize:vertical;"></textarea>
                            <div style="display:flex;gap:7px;justify-content:flex-end;">
                                <button class="btn" style="background:#f3f4f6;color:#6b7280;"
                                    onclick="hideInternalPoRejectForm('accounting')">Cancel</button>
                                <button class="btn" style="background:#ef4444;color:#fff;"
                                    onclick="submitInternalPoReject(<?= $ipa['id'] ?>, 'accounting')"><i
                                        class="fas fa-times"></i> Confirm Reject</button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Head Designer (only active after accounting approves) -->
                    <?php
                    $dsStatus = $ipa['designer_status'];
                    $dsLocked = ($acStatus !== 'approved');
                    $dColors = ['pending' => ['#f3f4f6', '#9ca3af', '#e5e7eb', 'fa-clock'], 'approved' => ['#d1fae5', '#065f46', '#10b981', 'fa-check-circle'], 'rejected' => ['#fee2e2', '#991b1b', '#ef4444', 'fa-times-circle']];
                    $dc = $dColors[$dsStatus];
                    ?>
                    <div
                        style="background:<?= $dc[0] ?>;border:1px solid <?= $dc[2] ?>;border-radius:8px;padding:12px 14px;<?= $dsLocked ? 'opacity:.5;' : '' ?>">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span
                                    style="background:<?= $dc[2] ?>;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">2</span>
                                <div>
                                    <div
                                        style="font-size:12px;font-weight:700;color:<?= $dc[1] ?>;display:flex;align-items:center;gap:5px;">
                                        <i class="fas <?= $dc[3] ?>"></i> Head Designer
                                        <?php if ($dsLocked): ?>
                                            <span
                                                style="background:#e5e7eb;color:#6b7280;border:1px solid #d1d5db;padding:1px 7px;border-radius:10px;font-size:10px;"><i
                                                    class="fas fa-lock"></i> Waiting for Accounting</span>
                                        <?php elseif ($dsStatus === 'pending'): ?>
                                            <span
                                                style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:1px 7px;border-radius:10px;font-size:10px;">Waiting</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($ipa['designer_reviewed_at']): ?>
                                        <div style="font-size:11px;color:var(--text-lt);margin-top:2px;">
                                            <?= htmlspecialchars($ipa['designer_reviewer_name']) ?> ·
                                            <?= date('M d, Y g:i A', strtotime($ipa['designer_reviewed_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ipa['designer_remark']): ?>
                                        <div
                                            style="font-size:12px;color:#991b1b;margin-top:5px;background:#fee2e2;border-radius:6px;padding:6px 10px;font-style:italic;">
                                            <i class="fas fa-comment-alt"></i> "<?= htmlspecialchars($ipa['designer_remark']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canReviewInternalPoDesigner && $dsStatus === 'pending' && !$dsLocked && $overallStatus === 'pending'): ?>
                                <div style="display:flex;gap:6px;flex-shrink:0;">
                                    <button class="btn btn-approve"
                                        onclick="reviewInternalPo(<?= $ipa['id'] ?>, 'approve', 'designer')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn btn-reject"
                                        onclick="showInternalPoRejectForm('designer', <?= $ipa['id'] ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Inline reject form for designer -->
                        <div id="ipo-reject-form-designer"
                            style="display:none;margin-top:10px;background:#fff5f5;border:1px solid #fecaca;border-radius:7px;padding:12px;">
                            <div style="font-size:12px;font-weight:700;color:#991b1b;margin-bottom:7px;"><i
                                    class="fas fa-times-circle"></i> Remark / Rejection Note</div>
                            <textarea id="ipo-remark-designer" class="form-textarea"
                                placeholder="Explain what needs to be fixed..."
                                style="width:100%;min-height:70px;padding:8px 12px;border:1px solid #fca5a5;border-radius:7px;font-size:13px;font-family:inherit;margin-bottom:8px;resize:vertical;"></textarea>
                            <div style="display:flex;gap:7px;justify-content:flex-end;">
                                <button class="btn" style="background:#f3f4f6;color:#6b7280;"
                                    onclick="hideInternalPoRejectForm('designer')">Cancel</button>
                                <button class="btn" style="background:#ef4444;color:#fff;"
                                    onclick="submitInternalPoReject(<?= $ipa['id'] ?>, 'designer')"><i class="fas fa-times"></i>
                                    Confirm Reject</button>
                            </div>
                        </div>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal-overlay">
        <div class="modal-box">
            <!-- Hidden iframe — direct upload response lands here, hindi mag-navigate ang main page -->
            <iframe name="direct_upload_frame" id="direct_upload_frame" style="display:none;"></iframe>

            <form id="directUploadForm" method="POST" action="<?= BASE_URL ?>direct-upload"
                enctype="multipart/form-data" target="direct_upload_frame" style="display:contents;">

                <input type="hidden" name="stage_id" value="<?= $stage_id ?>">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <input type="hidden" name="stage_name" value="<?= htmlspecialchars($stage) ?>">

                <div class="modal-title"><i class="fas fa-file-upload"></i>
                    <?= $isApproval ? 'Submit File for Approval' : 'Upload File' ?></div>
                <div class="modal-sub"><?= htmlspecialchars($stage) ?> · <?= htmlspecialchars($client['clientname']) ?>
                </div>

                <div class="form-group">
                    <label class="form-label">File Label <span style="color:#ef4444">*</span></label>
                    <input type="text" id="uploadLabel" name="label" class="form-input"
                        placeholder="e.g. Material Data Sheet, Quotation v2...">
                    <div class="form-hint">Describe what this file contains so reviewers understand it at a glance.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Select File <span style="color:#ef4444">*</span></label>
                    <input type="file" id="uploadFile" name="file" class="form-input"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp,.mp4,.mov,.avi,.mkv,.webm"
                        onchange="autoSuggestUploadMode(this)">
                    <div class="form-hint" id="uploadFileHint">PDF, Word, Excel, PowerPoint, Images, Video · Max 50MB
                        (Direct) or 1.3GB (Chunked)</div>
                </div>

                <!-- Upload mode toggle -->
                <div class="upload-mode-toggle">
                    <div class="mode-label">
                        <i class="fas fa-bolt" style="color:#1e40af;"></i>
                        <span>Upload Mode:</span>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="uploadModeToggle" onchange="onUploadModeChange()">
                        <span class="toggle-slider"></span>
                    </label>
                    <div id="uploadModeLabel">
                        <span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
                        <span style="font-size:11px; color:var(--text-lt); margin-left:4px;">Best for files under 50MB ·
                            faster, no 405 errors</span>
                    </div>
                </div>

                <!-- Progress bar (hidden until upload starts) -->
                <div id="uploadProgressWrap" style="display:none; margin-bottom:14px;">
                    <div
                        style="display:flex; justify-content:space-between; font-size:12px; color:#5c4033; margin-bottom:6px;">
                        <span id="uploadProgressLabel">Uploading...</span>
                        <span id="uploadProgressPct">0%</span>
                    </div>
                    <div style="height:8px; background:#e2d9ce; border-radius:99px; overflow:hidden;">
                        <div id="uploadProgressBar"
                            style="height:100%; width:0%; background:linear-gradient(90deg,#3b1f0f,#c49a78); border-radius:99px; transition:width .2s;">
                        </div>
                    </div>
                    <div id="uploadProgressSub" style="font-size:11px; color:#9c7b6a; margin-top:5px;"></div>
                </div>

                <div id="uploadError" class="form-error"></div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" id="uploadCancelBtn"
                        onclick="closeUploadModal()">Cancel</button>
                    <button type="button" class="btn-submit" id="uploadSubmitBtn" onclick="submitUpload()">
                        <i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>
                    </button>
                </div>

            </form><!-- end directUploadForm -->
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title" style="color:#dc2626;"><i class="fas fa-times-circle"></i> Reject File</div>
            <div class="modal-sub">Please explain why this file is being rejected. The submitter will be notified.</div>
            <input type="hidden" id="rejectApprovalId">
            <div class="form-group">
                <label class="form-label">Rejection Note <span style="color:#ef4444">*</span></label>
                <textarea id="rejectNote" class="form-textarea"
                    placeholder="e.g. Please revise the material specifications on page 2..."></textarea>
            </div>
            <div id="rejectError" class="form-error"></div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                <button class="btn-reject-confirm" onclick="submitRejection()">
                    <i class="fas fa-times"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </div>

    <!-- PO Upload Modal (linked to a specific BOM) -->
    <div id="poUploadModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title"><i class="fas fa-file-invoice-dollar"></i> Submit Purchase Order</div>
            <div class="modal-sub">Submitting PO for BOM: <strong id="poUploadBomLabel">All BOMs</strong></div>
            <input type="hidden" id="poUploadLinkedBomId">

            <div class="form-group">
                <label class="form-label">PO Label <span style="color:#ef4444">*</span></label>
                <input type="text" id="poUploadLabel" class="form-input"
                    placeholder="e.g. Purchase Order #001, Hardware PO...">
            </div>

            <div class="form-group">
                <label class="form-label">Select File <span style="color:#ef4444">*</span></label>
                <input type="file" id="poUploadFile" class="form-input"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp">
                <div class="form-hint">PDF, Word, Excel, Images · Max 1.3GB</div>
            </div>

            <div id="poUploadProgressWrap" style="display:none; margin-bottom:14px;">
                <div
                    style="display:flex; justify-content:space-between; font-size:12px; color:#5c4033; margin-bottom:6px;">
                    <span id="poUploadProgressLabel">Uploading...</span>
                    <span id="poUploadProgressPct">0%</span>
                </div>
                <div style="height:8px; background:#e2d9ce; border-radius:99px; overflow:hidden;">
                    <div id="poUploadProgressBar"
                        style="height:100%; width:0%; background:linear-gradient(90deg,#065f46,#10b981); border-radius:99px; transition:width .2s;">
                    </div>
                </div>
            </div>

            <div id="poUploadError" class="form-error"></div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closePOUploadModal()">Cancel</button>
                <button type="button" class="btn-submit" id="poUploadSubmitBtn" onclick="submitPOUpload()"
                    style="background:#065f46;">
                    <i class="fas fa-file-invoice-dollar"></i> Submit for Approval
                </button>
            </div>
        </div>
    </div>

    <!-- Receipt Upload Modal (linked to a specific PO) -->
    <div id="receiptModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title"><i class="fas fa-receipt"></i> Upload Receipt</div>
            <div class="modal-sub">Uploading receipt for PO: <strong id="receiptPoLabel"></strong></div>
            <input type="hidden" id="receiptLinkedPoId">

            <div class="form-group">
                <label class="form-label">Receipt Label <span style="color:#ef4444">*</span></label>
                <input type="text" id="receiptLabel" class="form-input"
                    placeholder="e.g. Delivery Receipt #001, Invoice #123...">
            </div>

            <div class="form-group">
                <label class="form-label">Select File <span style="color:#ef4444">*</span></label>
                <input type="file" id="receiptFile" class="form-input"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp,.mp4,.mov,.avi,.mkv,.webm">
                <div class="form-hint">PDF, Word, Excel, Images · Max 1.3GB</div>
            </div>

            <div id="receiptProgressWrap" style="display:none; margin-bottom:14px;">
                <div
                    style="display:flex; justify-content:space-between; font-size:12px; color:#5c4033; margin-bottom:6px;">
                    <span id="receiptProgressLabel">Uploading...</span>
                    <span id="receiptProgressPct">0%</span>
                </div>
                <div style="height:8px; background:#e2d9ce; border-radius:99px; overflow:hidden;">
                    <div id="receiptProgressBar"
                        style="height:100%; width:0%; background:linear-gradient(90deg,#0369a1,#38bdf8); border-radius:99px; transition:width .2s;">
                    </div>
                </div>
            </div>

            <div id="receiptError" class="form-error"></div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeReceiptModal()">Cancel</button>
                <button type="button" class="btn-submit" id="receiptSubmitBtn" onclick="submitReceipt()"
                    style="background:#0369a1;">
                    <i class="fas fa-upload"></i> Upload Receipt
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

    <script>
        const STAGE_ID = <?= $stage_id ?>;

        // ── Upload mode helpers ──────────────────────────────────────────
        function onUploadModeChange() {
            const isChunk = document.getElementById('uploadModeToggle').checked;
            const label = document.getElementById('uploadModeLabel');
            const hint = document.getElementById('uploadFileHint');
            if (isChunk) {
                label.innerHTML = `<span class="mode-badge chunked"><i class="fas fa-layer-group"></i> Chunked</span>
            <span style="font-size:11px;color:var(--text-lt);margin-left:4px;">For large files up to 1.3GB · slower start</span>`;
                hint.textContent = 'PDF, Word, Excel, PowerPoint, Images, Video · Max 1.3GB (Chunked mode)';
            } else {
                label.innerHTML = `<span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
            <span style="font-size:11px;color:var(--text-lt);margin-left:4px;">Best for files under 50MB · faster, no 405 errors</span>`;
                hint.textContent = 'PDF, Word, Excel, PowerPoint, Images, Video · Max 50MB (Direct) or 1.3GB (Chunked)';
            }
        }

        function autoSuggestUploadMode(input) {
            const file = input.files[0];
            if (!file) return;
            const toggle = document.getElementById('uploadModeToggle');
            const DIRECT_LIMIT = 50 * 1024 * 1024; // 50MB
            const wasChunk = toggle.checked;
            toggle.checked = file.size > DIRECT_LIMIT;
            if (toggle.checked !== wasChunk) onUploadModeChange();
        }

        function directUpload(file, label, stageId, clientId, stageName, progressBar, progressPct, progressLabel, progressSub, errEl, btn, btnOrigHTML) {
            if (file.size > 50 * 1024 * 1024) {
                errEl.textContent = 'Direct upload is limited to 50MB. Please switch to Chunked mode for larger files.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = btnOrigHTML;
                return;
            }

            // Shimmer progress
            progressBar.style.width = '100%';
            progressBar.style.transition = 'none';
            progressBar.style.background = 'repeating-linear-gradient(90deg,#3b1f0f 0px,#c49a78 20px,#3b1f0f 40px)';
            progressBar.style.backgroundSize = '200% 100%';
            progressBar.style.animation = 'shimmer 1.5s infinite linear';
            progressPct.textContent = 'Uploading...';
            if (progressLabel) progressLabel.textContent = 'Sending file...';
            if (progressSub) progressSub.textContent = formatBytes(file.size) + ' · Direct upload';

            // Listen sa iframe — dito darating ang JSON response mula sa direct_upload.php
            const iframe = document.getElementById('direct_upload_frame');
            iframe.onload = function () {
                try {
                    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                    const raw = iframeDoc.body ? iframeDoc.body.innerText.trim() : '';
                    if (!raw) return; // initial empty load, ignore
                    const data = JSON.parse(raw);
                    if (data.success) {
                        toast('File uploaded successfully!');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        progressBar.style.animation = 'none';
                        progressBar.style.width = '0%';
                        errEl.textContent = data.error || 'Upload failed.';
                        errEl.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = btnOrigHTML;
                    }
                } catch (e) {
                    progressBar.style.animation = 'none';
                    progressBar.style.width = '0%';
                    errEl.textContent = 'Upload failed. Please try chunked mode.';
                    errEl.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = btnOrigHTML;
                }
            };

            // Pure native <form method="POST" enctype="multipart/form-data"> submit
            // Hindi na fetch, hindi na XHR — browser mismo ang mag-send
            document.getElementById('directUploadForm').submit();
        }

        function formatBytes(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 1024).toFixed(0) + ' KB';
        }
        // ────────────────────────────────────────────────────────────────

        async function uploadChunkWithRetry(fd, maxRetries = 8) {
            const delays = [1000, 2000, 3000, 5000, 8000, 10000, 15000, 20000];

            for (let attempt = 1; attempt <= maxRetries; attempt++) {
                try {
                    const data = await new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '<?= BASE_URL ?>chunk-upload', true);

                        const timeout = setTimeout(() => {
                            xhr.abort();
                            reject(new Error('timeout'));
                        }, 60000);

                        xhr.onload = () => {
                            clearTimeout(timeout);
                            if (xhr.status === 405 || xhr.status === 503 || xhr.status === 502) {
                                reject(new Error('HTTP ' + xhr.status));
                                return;
                            }
                            if (xhr.status !== 200) {
                                reject(new Error('HTTP ' + xhr.status));
                                return;
                            }
                            try {
                                resolve(JSON.parse(xhr.responseText));
                            } catch (e) {
                                reject(new Error('parse_error'));
                            }
                        };

                        xhr.onerror = () => { clearTimeout(timeout); reject(new Error('network')); };
                        xhr.send(fd);
                    });

                    return data;

                } catch (e) {
                    const waitMs = delays[attempt - 1] || 20000;
                    if (attempt < maxRetries) {
                        console.warn(`Chunk attempt ${attempt}/${maxRetries} failed: ${e.message}, retrying in ${waitMs}ms`);
                        await new Promise(r => setTimeout(r, waitMs));
                    } else {
                        throw e;
                    }
                }
            }
        }

        // Upload
        function openUploadModal(prefillLabel = '') {
            _uploadAborted = false;
            document.getElementById('uploadLabel').value = prefillLabel;
            document.getElementById('uploadFile').value = '';
            document.getElementById('uploadError').style.display = 'none';
            document.getElementById('uploadProgressWrap').style.display = 'none';
            document.getElementById('uploadProgressBar').style.width = '0%';
            document.getElementById('uploadProgressPct').textContent = '0%';
            document.getElementById('uploadModal').classList.add('show');
        }
        let _uploadAborted = false;

        function closeUploadModal() {
            _uploadAborted = true;
            document.getElementById('uploadModal').classList.remove('show');
            document.getElementById('uploadProgressWrap').style.display = 'none';
            document.getElementById('uploadProgressBar').style.width = '0%';
            document.getElementById('uploadProgressPct').textContent = '0%';
            document.getElementById('uploadLabel').value = '';
            document.getElementById('uploadFile').value = '';
            document.getElementById('uploadError').style.display = 'none';
            const btn = document.getElementById('uploadSubmitBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>';
        }

        async function submitUpload() {
            const label = document.getElementById('uploadLabel').value.trim();
            const file = document.getElementById('uploadFile').files[0];
            const err = document.getElementById('uploadError');
            err.style.display = 'none';

            if (!label) { err.textContent = 'Please enter a file label.'; err.style.display = 'block'; return; }
            if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
            if (file.size > 1.3 * 1024 * 1024 * 1024) {
                err.textContent = 'File exceeds 1.3GB limit.';
                err.style.display = 'block';
                return;
            }

            _uploadAborted = false;
            const btn = document.getElementById('uploadSubmitBtn');
            const cancelBtn = document.getElementById('uploadCancelBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            cancelBtn.textContent = 'Cancel Upload';

            // Direct upload path
            const toggleEl = document.getElementById('uploadModeToggle');
            const isDirectMode = toggleEl && !toggleEl.checked;
            if (isDirectMode) {
                document.getElementById('uploadProgressWrap').style.display = 'block';
                document.getElementById('uploadProgressLabel').textContent = 'Uploading...';
                document.getElementById('uploadProgressPct').textContent = '0%';
                const btnOrigHTML = '<?= $isApproval ? '<i class="fas fa-upload"></i> Submit for Approval' : '<i class="fas fa-upload"></i> Upload' ?>';
                await directUpload(
                    file, label,
                    <?= $stage_id ?>, <?= $client_id ?>, <?= json_encode($stage) ?>,
                    document.getElementById('uploadProgressBar'),
                    document.getElementById('uploadProgressPct'),
                    document.getElementById('uploadProgressLabel'),
                    document.getElementById('uploadProgressSub'),
                    err, btn, btnOrigHTML
                );
                return;
            }

            const MIN_CHUNK = 512 * 1024;        // 512KB  floor
            const MAX_CHUNK = 32 * 1024 * 1024; // 32MB   ceiling
            const TARGET_MS = 8000;               // aim ~8s per chunk
            const SERVER_OH = 250;                // ~250ms Hostinger overhead

            let CHUNK_SIZE = 2 * 1024 * 1024;    // start at 2MB
            const uploadId = 'uid_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

            function adjustChunkSize(elapsedMs, bytesSent) {
                const netMs = Math.max(elapsedMs - SERVER_OH, 50);
                const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
                const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
                CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
                console.log(`Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
            }

            document.getElementById('uploadProgressWrap').style.display = 'block';
            document.getElementById('uploadProgressLabel').textContent = 'Starting upload...';
            document.getElementById('uploadProgressPct').textContent = '0%';

            try {
                let bytesSent = 0;
                let chunkIndex = 0;

                while (bytesSent < file.size) {
                    if (_uploadAborted) return;

                    const start = bytesSent;
                    const end = Math.min(start + CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);
                    const isLast = end >= file.size;

                    const fd = new FormData();
                    fd.append('chunk', chunk);
                    fd.append('chunk_index', chunkIndex);
                    fd.append('total_chunks', -1);
                    fd.append('is_last', isLast ? 'true' : 'false');
                    fd.append('upload_id', uploadId);
                    fd.append('original_name', file.name);
                    fd.append('stage_id', <?= $stage_id ?>);
                    fd.append('client_id', <?= $client_id ?>);
                    fd.append('stage_name', <?= json_encode($stage) ?>);
                    fd.append('label', label);

                    const t0 = performance.now();
                    let data;
                    try {
                        data = await uploadChunkWithRetry(fd);
                    } catch (retryErr) {
                        if (!_uploadAborted) {
                            const msg = retryErr?.message?.includes('405')
                                ? 'Server rejected the upload (405). Please wait a moment and try again.'
                                : 'Connection error after 5 attempts. Please try again.';
                            err.textContent = msg;
                            err.style.display = 'block';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>';
                        }
                        return;
                    }
                    const elapsed = performance.now() - t0;

                    if (!data.success) {
                        err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                        err.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>';
                        return;
                    }

                    bytesSent += (end - start);
                    chunkIndex++;

                    const pct = Math.round((bytesSent / file.size) * 100);
                    document.getElementById('uploadProgressBar').style.width = pct + '%';
                    document.getElementById('uploadProgressPct').textContent = pct + '%';
                    document.getElementById('uploadProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;
                    document.getElementById('uploadProgressSub').textContent = formatBytes(bytesSent) + ' of ' + formatBytes(file.size) + ' sent';

                    if (!isLast) {
                        adjustChunkSize(elapsed, end - start);
                        await new Promise(r => setTimeout(r, 300));
                    }

                    if (data.done) {
                        toast('File uploaded successfully!');
                        setTimeout(() => location.reload(), 1000);
                        return;
                    }
                }
            } catch (e) {
                if (!_uploadAborted) {
                    err.textContent = 'Connection error. Please try again.';
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>';
                }
            }
        }


        // Reject modal
        function openRejectModal(approvalId) {
            document.getElementById('rejectApprovalId').value = approvalId;
            document.getElementById('rejectNote').value = '';
            document.getElementById('rejectError').style.display = 'none';
            document.getElementById('rejectModal').classList.add('show');
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('show');
        }
        async function submitRejection() {
            const id = document.getElementById('rejectApprovalId').value;
            const note = document.getElementById('rejectNote').value.trim();
            const err = document.getElementById('rejectError');
            if (!note) { err.textContent = 'A rejection note is required.'; err.style.display = 'block'; return; }
            try {
                const res = await fetch('<?= BASE_URL ?>approve-reject-stage', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ approval_id: parseInt(id), action: 'rejected', note })
                });
                const data = await res.json();
                if (data.success) { closeRejectModal(); toast('File rejected.'); setTimeout(() => location.reload(), 1000); }
                else { err.textContent = data.error || 'Failed.'; err.style.display = 'block'; }
            } catch (e) { err.textContent = 'An error occurred.'; err.style.display = 'block'; }
        }

        // Delete
        async function deleteFile(approvalId) {
            if (!confirm('Delete this file? This cannot be undone.')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>delete-stage-file', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ approval_id: approvalId, stage_id: STAGE_ID })
                });
                const data = await res.json();
                if (data.success) { toast('File deleted.'); setTimeout(() => location.reload(), 800); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        // Mark done
        async function markDone() {
            if (!confirm('Mark this stage as Done?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: STAGE_ID, status: 'Done' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage marked as Done!'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function cancelDone() {
            if (!confirm('Revert this stage back to Ongoing?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: STAGE_ID, status: 'Ongoing' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage reverted to Ongoing.'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        // Receipt modal
        let _receiptAborted = false;
        function openReceiptModal(poId, poLabel) {
            _receiptAborted = false;
            document.getElementById('receiptLinkedPoId').value = poId;
            document.getElementById('receiptPoLabel').textContent = poLabel;
            document.getElementById('receiptLabel').value = '';
            document.getElementById('receiptFile').value = '';
            document.getElementById('receiptError').style.display = 'none';
            document.getElementById('receiptProgressWrap').style.display = 'none';
            document.getElementById('receiptProgressBar').style.width = '0%';
            document.getElementById('receiptProgressPct').textContent = '0%';
            document.getElementById('receiptModal').classList.add('show');
        }
        function closeReceiptModal() {
            _receiptAborted = true;
            document.getElementById('receiptModal').classList.remove('show');
        }
        async function submitReceipt() {
            const label = document.getElementById('receiptLabel').value.trim();
            const file = document.getElementById('receiptFile').files[0];
            const linkedPo = document.getElementById('receiptLinkedPoId').value;
            const err = document.getElementById('receiptError');
            err.style.display = 'none';

            if (!label) { err.textContent = 'Please enter a receipt label.'; err.style.display = 'block'; return; }
            if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
            if (file.size > 1.3 * 1024 * 1024 * 1024) {
                err.textContent = 'File exceeds 1.3GB limit.'; err.style.display = 'block'; return;
            }

            _receiptAborted = false;
            const btn = document.getElementById('receiptSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            const MIN_CHUNK = 512 * 1024;
            const MAX_CHUNK = 32 * 1024 * 1024;
            const TARGET_MS = 8000;
            const SERVER_OH = 250;

            let CHUNK_SIZE = 2 * 1024 * 1024;
            const uploadId = 'rcpt_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

            function adjustChunkSize(elapsedMs, bytesSent) {
                const netMs = Math.max(elapsedMs - SERVER_OH, 50);
                const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
                const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
                CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
                console.log(`Receipt Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
            }

            document.getElementById('receiptProgressWrap').style.display = 'block';
            document.getElementById('receiptProgressLabel').textContent = 'Starting upload...';
            document.getElementById('receiptProgressPct').textContent = '0%';

            try {
                let bytesSent = 0;
                let chunkIndex = 0;

                while (bytesSent < file.size) {
                    if (_receiptAborted) return;

                    const start = bytesSent;
                    const end = Math.min(start + CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);
                    const isLast = end >= file.size;

                    const fd = new FormData();
                    fd.append('chunk', chunk);
                    fd.append('chunk_index', chunkIndex);
                    fd.append('total_chunks', -1);
                    fd.append('is_last', isLast ? 'true' : 'false');
                    fd.append('upload_id', uploadId);
                    fd.append('original_name', file.name);
                    fd.append('stage_id', <?= $stage_id ?>);
                    fd.append('client_id', <?= $client_id ?>);
                    fd.append('stage_name', <?= json_encode($stage) ?>);
                    fd.append('label', label);
                    fd.append('linked_po_id', linkedPo);

                    const t0 = performance.now();
                    let data;
                    try {
                        data = await uploadChunkWithRetry(fd);
                    } catch (retryErr) {
                        if (!_receiptAborted) {
                            const msg = retryErr?.message?.includes('405')
                                ? 'Server rejected the upload (405). Please wait a moment and try again.'
                                : 'Connection error after 5 attempts. Please try again.';
                            err.textContent = msg;
                            err.style.display = 'block';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
                        }
                        return;
                    }
                    const elapsed = performance.now() - t0;

                    if (!data.success) {
                        err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                        err.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
                        return;
                    }

                    bytesSent += (end - start);
                    chunkIndex++;

                    const pct = Math.round((bytesSent / file.size) * 100);
                    document.getElementById('receiptProgressBar').style.width = pct + '%';
                    document.getElementById('receiptProgressPct').textContent = pct + '%';
                    document.getElementById('receiptProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;

                    if (!isLast) {
                        adjustChunkSize(elapsed, end - start);
                        await new Promise(r => setTimeout(r, 300));
                    }

                    if (data.done) {
                        closeReceiptModal();
                        toast('Receipt uploaded and linked to PO!');
                        setTimeout(() => location.reload(), 1000);
                        return;
                    }
                }
            } catch (e) {
                if (!_receiptAborted) {
                    err.textContent = 'Connection error. Please try again.';
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
                }
            }
        }

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
        });

        function filterCategory(cat, btn) {
            // Update active button
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Show/hide file cards
            document.querySelectorAll('.file-card[data-category]').forEach(card => {
                if (cat === 'all' || card.dataset.category === cat) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // ── Internal P.O to Accounting approval functions ──────────────────
        async function requestInternalPoApproval() {
            try {
                const res = await fetch('<?= BASE_URL ?>internal-po-review', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'request_approval', client_id: <?= $client_id ?>, stage_id: STAGE_ID })
                });
                const data = await res.json();
                if (data.success) { toast('Approval requested!'); setTimeout(() => location.reload(), 900); }
                else toast(data.error || 'Failed', true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function reviewInternalPo(approvalId, action, reviewer) {
            if (!confirm(action === 'approve' ? 'Approve all files for this stage?' : 'Reject?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>internal-po-review', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: action, approval_id: approvalId, client_id: <?= $client_id ?>, stage_id: STAGE_ID, remark: '' })
                });
                const data = await res.json();
                if (data.success) { toast(action === 'approve' ? 'Approved!' : 'Rejected.'); setTimeout(() => location.reload(), 900); }
                else toast(data.error || 'Failed', true);
            } catch (e) { toast('An error occurred', true); }
        }

        function showInternalPoRejectForm(reviewer, approvalId) {
            document.getElementById('ipo-reject-form-' + reviewer).style.display = 'block';
            document.getElementById('ipo-reject-form-' + reviewer).dataset.approvalId = approvalId;
        }
        function hideInternalPoRejectForm(reviewer) {
            document.getElementById('ipo-reject-form-' + reviewer).style.display = 'none';
            document.getElementById('ipo-remark-' + reviewer).value = '';
        }

        async function submitInternalPoReject(approvalId, reviewer) {
            const remark = document.getElementById('ipo-remark-' + reviewer).value.trim();
            if (!remark) { toast('Please enter a remark before rejecting.', true); return; }
            try {
                const res = await fetch('<?= BASE_URL ?>internal-po-review', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reject', approval_id: approvalId, client_id: <?= $client_id ?>, stage_id: STAGE_ID, remark: remark })
                });
                const data = await res.json();
                if (data.success) { toast('Rejected with remark.'); setTimeout(() => location.reload(), 900); }
                else toast(data.error || 'Failed', true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function resetInternalPoApproval(approvalId) {
            if (!confirm('Reset the rejection and re-request approval?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>internal-po-review', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset', approval_id: approvalId, client_id: <?= $client_id ?>, stage_id: STAGE_ID })
                });
                const data = await res.json();
                if (data.success) { toast('Reset. You can now re-request approval.'); setTimeout(() => location.reload(), 900); }
                else toast(data.error || 'Failed', true);
            } catch (e) { toast('An error occurred', true); }
        }

        function toast(msg, err = false) {
            const el = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            el.className = 'toast show' + (err ? ' error' : '');
            setTimeout(() => el.classList.remove('show'), 3000);
        }

        // PO Upload Modal (linked to BOM)
        let _poUploadAborted = false;
        function openPOUploadModal(bomId, bomLabel, prefillLabel = '') {
            _poUploadAborted = false;
            document.getElementById('poUploadLinkedBomId').value = bomId || '';
            document.getElementById('poUploadBomLabel').textContent = bomLabel || 'All BOMs';
            document.getElementById('poUploadLabel').value = prefillLabel; // ← auto-fills the label
            document.getElementById('poUploadFile').value = '';
            document.getElementById('poUploadError').style.display = 'none';
            document.getElementById('poUploadProgressWrap').style.display = 'none';
            document.getElementById('poUploadProgressBar').style.width = '0%';
            document.getElementById('poUploadProgressPct').textContent = '0%';
            document.getElementById('poUploadModal').classList.add('show');

            // If label was prefilled (resubmit), make it readonly so user can't accidentally change it
            const labelInput = document.getElementById('poUploadLabel');
            if (prefillLabel) {
                labelInput.setAttribute('readonly', true);
                labelInput.style.background = '#f3f4f6';
                labelInput.style.color = '#6b7280';
            } else {
                labelInput.removeAttribute('readonly');
                labelInput.style.background = '';
                labelInput.style.color = '';
            }
        }
        function closePOUploadModal() {
            _poUploadAborted = true;
            document.getElementById('poUploadModal').classList.remove('show');
        }

        async function submitPOUpload() {
            const label = document.getElementById('poUploadLabel').value.trim();
            const file = document.getElementById('poUploadFile').files[0];
            const linkedBom = document.getElementById('poUploadLinkedBomId').value;
            const err = document.getElementById('poUploadError');
            err.style.display = 'none';

            if (!label) { err.textContent = 'Please enter a PO label.'; err.style.display = 'block'; return; }
            if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
            if (file.size > 1.3 * 1024 * 1024 * 1024) {
                err.textContent = 'File exceeds 1.3GB limit.'; err.style.display = 'block'; return;
            }

            _poUploadAborted = false;
            const btn = document.getElementById('poUploadSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            const MIN_CHUNK = 512 * 1024;
            const MAX_CHUNK = 32 * 1024 * 1024;
            const TARGET_MS = 8000;
            const SERVER_OH = 250;

            let CHUNK_SIZE = 2 * 1024 * 1024;
            const uploadId = 'po_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

            function adjustChunkSize(elapsedMs, bytesSent) {
                const netMs = Math.max(elapsedMs - SERVER_OH, 50);
                const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
                const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
                CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
                console.log(`PO Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
            }

            document.getElementById('poUploadProgressWrap').style.display = 'block';
            document.getElementById('poUploadProgressLabel').textContent = 'Starting upload...';
            document.getElementById('poUploadProgressPct').textContent = '0%';

            try {
                let bytesSent = 0;
                let chunkIndex = 0;

                while (bytesSent < file.size) {
                    if (_poUploadAborted) return;

                    const start = bytesSent;
                    const end = Math.min(start + CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);
                    const isLast = end >= file.size;

                    const fd = new FormData();
                    fd.append('chunk', chunk);
                    fd.append('chunk_index', chunkIndex);
                    fd.append('total_chunks', -1);
                    fd.append('is_last', isLast ? 'true' : 'false');
                    fd.append('upload_id', uploadId);
                    fd.append('original_name', file.name);
                    fd.append('stage_id', <?= $stage_id ?>);
                    fd.append('client_id', <?= $client_id ?>);
                    fd.append('stage_name', <?= json_encode($stage) ?>);
                    fd.append('label', label);
                    fd.append('linked_bom_id', linkedBom);

                    const t0 = performance.now();
                    let data;
                    try {
                        data = await uploadChunkWithRetry(fd);
                    } catch (retryErr) {
                        if (!_poUploadAborted) {
                            const msg = retryErr?.message?.includes('405')
                                ? 'Server rejected the upload (405). Please wait a moment and try again.'
                                : 'Connection error after 5 attempts. Please try again.';
                            err.textContent = msg;
                            err.style.display = 'block';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
                        }
                        return;
                    }
                    const elapsed = performance.now() - t0;

                    if (!data.success) {
                        err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                        err.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
                        return;
                    }

                    bytesSent += (end - start);
                    chunkIndex++;

                    const pct = Math.round((bytesSent / file.size) * 100);
                    document.getElementById('poUploadProgressBar').style.width = pct + '%';
                    document.getElementById('poUploadProgressPct').textContent = pct + '%';
                    document.getElementById('poUploadProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;

                    if (!isLast) {
                        adjustChunkSize(elapsed, end - start);
                        await new Promise(r => setTimeout(r, 300));
                    }

                    if (data.done) {
                        closePOUploadModal();
                        toast('Purchase Order submitted for approval!');
                        setTimeout(() => location.reload(), 1000);
                        return;
                    }
                }
            } catch (e) {
                if (!_poUploadAborted) {
                    err.textContent = 'Connection error. Please try again.';
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
                }
            }
        }

        // Update BOM order status
        async function updateBomOrderStatus(bomId, status) {
            const labels = { pending: 'Not Ordered', partially_ordered: 'Partially Ordered', ordered: 'Fully Ordered' };
            if (!confirm('Mark this BOM as: ' + labels[status] + '?')) return;
            try {
                const res = await fetch('<?= BASE_URL ?>update-bom-order-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bom_id: bomId, status: status, client_id: <?= $client_id ?> })
                });
                const data = await res.json();
                if (data.success) { toast('Order status updated!'); setTimeout(() => location.reload(), 800); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }
    </script>
    <?php include $includes ['esign-modal']; ?>
</body>

</html>