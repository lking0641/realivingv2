<?php
// manager_stage_files.php
include $includes ['mainbody'];

$allowedRoles = ['general_manager', 'operational_manager', 'superadmin'];

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$stage_id = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;
$stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';

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
$cStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk=a.id WHERE u.id=?");
$cStmt->bind_param("i", $client_id);
$cStmt->execute();
$client = $cStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied.");

// Stage classification
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

// Stage status
$stStmt = $conn->prepare("SELECT status FROM project_tracker WHERE id=?");
$stStmt->bind_param("i", $stage_id);
$stStmt->execute();
$stRow = $stStmt->get_result()->fetch_assoc();
$stageStatus = $stRow ? $stRow['status'] : 'Pending';

// PO approved files (Accounting only)
$poApprovedFiles = [];
$receiptsByPo = [];
if ($isAccounting) {
    $poS = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=(SELECT id FROM project_tracker WHERE client_id=? AND stage_name='Purchase Order (Submit to accounting)' LIMIT 1)
          AND sa.approval_status='approved'
        ORDER BY sa.uploaded_at DESC
    ");
    $poS->bind_param("i", $client_id);
    $poS->execute();
    $poR = $poS->get_result();
    while ($r = $poR->fetch_assoc())
        $poApprovedFiles[] = $r;

    // Fetch receipts grouped by linked_po_id
    if ($stage_id) {
        $rcS = $conn->prepare("
            SELECT sa.*, a.full_name as uploaded_by_name FROM stage_approvals sa
            LEFT JOIN account a ON sa.uploaded_by=a.id
            WHERE sa.stage_id=? AND sa.linked_po_id IS NOT NULL
            ORDER BY sa.uploaded_at ASC
        ");
        $rcS->bind_param("i", $stage_id);
        $rcS->execute();
        $rcR = $rcS->get_result();
        while ($rc = $rcR->fetch_assoc()) {
            $receiptsByPo[$rc['linked_po_id']][] = $rc;
        }
    }
}

// BOM approved files (Purchase Order stage)
$bomApprovedFiles = [];
$posByBom = [];
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

    // Pre-fetch all POs for this stage grouped by linked_bom_id
    if ($stage_id) {
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
            $rStmt = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by = a.id WHERE sar.approval_id = ?");
            $rStmt->bind_param("i", $poRow['id']);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            while ($rev = $rRes->fetch_assoc())
                $poRow['role_reviews'][$rev['reviewer_role']] = $rev;
            $posByBom[$poRow['linked_bom_id']][] = $poRow;
        }
    }
}

// Fetch files
$files = [];
if ($stage_id) {
    $fStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=? ORDER BY sa.uploaded_at DESC
    ");
    $fStmt->bind_param("i", $stage_id);
    $fStmt->execute();
    $fRes = $fStmt->get_result();
    while ($row = $fRes->fetch_assoc()) {
        $row['role_reviews'] = [];
        if ($isApproval) {
            $rS = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by=a.id WHERE sar.approval_id=?");
            $rS->bind_param("i", $row['id']);
            $rS->execute();
            $rR = $rS->get_result();
            while ($rev = $rR->fetch_assoc())
                $row['role_reviews'][$rev['reviewer_role']] = $rev;
        }
        $files[] = $row;
    }
}

// ── Internal P.O to Accounting ──────────────────────────────────────
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

// Pending counts
$pendingCount = count(array_filter($files, fn($f) => $f['approval_status'] === 'pending'));
$approvedCount = count(array_filter($files, fn($f) => $f['approval_status'] === 'approved'));
$rejectedCount = count(array_filter($files, fn($f) => $f['approval_status'] === 'rejected'));

// My review status per file
function myReviewDone($file, $adminRole)
{
    return isset($file['role_reviews'][$adminRole]);
}

// Categories
$categories = [];
foreach ($files as $f) {
    $cat = trim($f['label'] ?? '');
    if ($cat && !in_array($cat, $categories))
        $categories[] = $cat;
}

function getRoleDisplayName($role)
{
    $n = ['general_manager' => 'General Manager', 'operational_manager' => 'Operational Manager', 'technical_designer' => 'Technical Designer (Head)', 'designer' => 'Designer (Head)', 'accounting' => 'Accounting'];
    return $n[$role] ?? ucwords(str_replace('_', ' ', $role));
}
function fileIcon($ext)
{
    $m = ['pdf' => ['fa-file-pdf', '#ef4444'], 'doc' => ['fa-file-word', '#3b82f6'], 'docx' => ['fa-file-word', '#3b82f6'], 'xls' => ['fa-file-excel', '#10b981'], 'xlsx' => ['fa-file-excel', '#10b981'], 'ppt' => ['fa-file-powerpoint', '#f59e0b'], 'pptx' => ['fa-file-powerpoint', '#f59e0b'], 'png' => ['fa-file-image', '#8b5cf6'], 'jpg' => ['fa-file-image', '#8b5cf6'], 'jpeg' => ['fa-file-image', '#8b5cf6'], 'gif' => ['fa-file-image', '#8b5cf6'], 'txt' => ['fa-file-alt', '#6b7280'], 'csv' => ['fa-file-csv', '#6b7280']];
    return $m[$ext] ?? ['fa-file', '#6b7280'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review — <?= htmlspecialchars($stage) ?></title>
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
            max-width: 860px;
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

        /* Hero header */
        .hero {
            background: var(--brown-dk);
            border-radius: 14px;
            padding: 26px 28px;
            color: #fff;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top right, rgba(196, 154, 120, .2) 0%, transparent 60%);
            pointer-events: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
        }

        .hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .hero-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 2px;
            letter-spacing: -.2px;
        }

        .hero-sub {
            font-size: 12px;
            opacity: .65;
        }

        .hero-status {
            text-align: right;
            flex-shrink: 0;
        }

        .hero-status-label {
            font-size: 10px;
            opacity: .55;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 3px;
        }

        .hero-status-value {
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            justify-content: flex-end;
        }

        .hero-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .hbadge {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 7px;
            padding: 4px 11px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
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

        /* Summary row */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .sum-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .sum-num {
            font-size: 24px;
            font-weight: 700;
            font-family: 'DM Mono', monospace;
        }

        .sum-num.p {
            color: var(--pending);
        }

        .sum-num.a {
            color: var(--done);
        }

        .sum-num.r {
            color: #ef4444;
        }

        .sum-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-lt);
            margin-top: 3px;
        }

        /* My action banner */
        .my-action {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius);
            padding: 13px 16px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .my-action i {
            color: #f59e0b;
            font-size: 16px;
            flex-shrink: 0;
        }

        .my-action-text {
            font-size: 13px;
            font-weight: 600;
            color: #92400e;
        }

        /* Section label */
        .sec-label {
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

        .sec-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* Empty state */
        .empty {
            text-align: center;
            padding: 44px 20px;
            background: var(--surface);
            border: 2px dashed var(--border);
            border-radius: var(--radius);
        }

        .empty i {
            font-size: 32px;
            color: var(--border);
            margin-bottom: 10px;
            display: block;
        }

        .empty-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-md);
            margin-bottom: 5px;
        }

        .empty-sub {
            font-size: 12px;
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
            box-shadow: 0 4px 20px rgba(59, 31, 15, .11);
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

        /* Already-reviewed banner */
        .reviewed-banner {
            background: #f0fdf4;
            border: 1px solid #a7f3d0;
            border-radius: 7px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }

        .reviewed-banner.rejected-banner {
            background: #fff5f5;
            border-color: #fca5a5;
            color: #991b1b;
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

        .file-label-tag {
            font-size: 10px;
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

        .apbadge.mine {
            box-shadow: 0 0 0 2px var(--brown-lt);
        }

        .apbadge-date {
            font-size: 9px;
            font-weight: 500;
            opacity: .8;
            margin-left: 3px;
        }

        /* Rejection notes */
        .reject-note {
            background: #fee2e2;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 12px;
            color: #991b1b;
            margin-top: 6px;
        }

        /* File status pill */
        .fstatus {
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

        .fstatus.approved {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .fstatus.rejected {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .fstatus.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Action row */
        .file-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 10px;
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
            font-size: 13px;
            padding: 7px 16px;
        }

        .btn-approve:hover {
            background: #a7f3d0;
        }

        .btn-reject {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            font-size: 13px;
            padding: 7px 16px;
        }

        .btn-reject:hover {
            background: #fca5a5;
        }

        /* Reject inline form */
        .reject-form {
            display: none;
            margin-top: 12px;
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 14px;
        }

        .reject-form textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #fca5a5;
            border-radius: 7px;
            font-size: 13px;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            margin-bottom: 10px;
        }

        .reject-form textarea:focus {
            outline: none;
            border-color: #ef4444;
        }

        .reject-form-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn-cancel-reject {
            background: #f3f4f6;
            color: #6b7280;
            padding: 6px 14px;
            border-radius: 7px;
            cursor: pointer;
            border: none;
            font-weight: 600;
            font-size: 12px;
        }

        .btn-confirm-reject {
            background: #ef4444;
            color: #fff;
            padding: 6px 14px;
            border-radius: 7px;
            cursor: pointer;
            border: none;
            font-weight: 700;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-form-error {
            font-size: 12px;
            color: #dc2626;
            margin-bottom: 8px;
            display: none;
        }

        /* Category filter */
        .cat-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .cat-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 13px;
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
    </style>
</head>

<body>
    <div class="page">

        <a href="manager-project-detail?client_id=<?= $client_id ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Project Dashboard
        </a>

        <!-- Hero -->
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-top">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <div class="hero-icon"><i
                                class="fas fa-<?= $isApproval ? 'stamp' : ($isAccounting ? 'receipt' : 'file-upload') ?>"></i>
                        </div>
                        <div>
                            <div class="hero-title"><?= htmlspecialchars($stage) ?></div>
                            <div class="hero-sub"><?= htmlspecialchars($client['clientname']) ?> ·
                                <?= htmlspecialchars($client['nameproject']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="hero-status">
                        <div class="hero-status-label">Stage Status</div>
                        <div class="hero-status-value">
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
                <div class="hero-badges">
                    <span class="hbadge"><?= htmlspecialchars(str_replace('_', ' ', $admin_role)) ?></span>
                    <?php if ($isApproval): ?>
                        <span class="hbadge approval"><i class="fas fa-stamp"></i> Approval Required</span>
                        <?php
                        $gmOmStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                        $required = $requiredApproversList[$stage] ?? [];
                        if (in_array($stage, $gmOmStages)):
                            // Show non-GM/OM roles individually, then GM/OM as one combined badge
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
                            foreach ($required as $role):
                                ?>
                                <span class="hbadge"><i class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                            <?php endforeach; endif; ?>
                    <?php elseif ($isFileUpload): ?><span class="hbadge upload"><i class="fas fa-file-upload"></i> File
                            Upload Stage</span>
                    <?php elseif ($isAccounting): ?><span class="hbadge receipt"><i class="fas fa-receipt"></i> Delivery
                            Receipt</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary counts -->
        <?php if ($isApproval && !empty($files)): ?>
            <div class="summary-row">
                <div class="sum-card">
                    <div class="sum-num p"><?= $pendingCount ?></div>
                    <div class="sum-label"><i class="fas fa-clock"></i> Pending</div>
                </div>
                <div class="sum-card">
                    <div class="sum-num a"><?= $approvedCount ?></div>
                    <div class="sum-label"><i class="fas fa-check-circle"></i> Approved</div>
                </div>
                <div class="sum-card">
                    <div class="sum-num r"><?= $rejectedCount ?></div>
                    <div class="sum-label"><i class="fas fa-times-circle"></i> Rejected</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- My action banner -->
        <?php
        $myPendingFiles = array_filter($files, fn($f) => $f['approval_status'] === 'pending' && !myReviewDone($f, $admin_role));
        if ($canApprove && count($myPendingFiles) > 0):
            ?>
            <div class="my-action">
                <i class="fas fa-exclamation-circle"></i>
                <div class="my-action-text">
                    <?= count($myPendingFiles) ?> file<?= count($myPendingFiles) !== 1 ? 's' : '' ?>
                    need<?= count($myPendingFiles) === 1 ? 's' : '' ?> your review. Use the Approve or Reject buttons below.
                </div>
            </div>
        <?php endif; ?>

        <!-- PO Mirror (Accounting) — grouped with receipts -->
        <?php if ($isAccounting): ?>
            <div class="sec-label"><i class="fas fa-file-import"></i> Purchase Orders & Receipts</div>
            <?php if (empty($poApprovedFiles)): ?>
                <div class="empty" style="margin-bottom:24px;">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="empty-title">Waiting for PO Approval</div>
                    <div class="empty-sub">Purchase Order files will appear here once approved.</div>
                </div>
            <?php else: ?>
                <?php foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fi, $fc] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <div style="margin-bottom:18px;">
                        <div class="file-card po-mirror"
                            style="margin-bottom:0;border-radius:<?= $hasReceipt ? '10px 10px 0 0' : '10px' ?>;<?= $hasReceipt ? 'border-bottom:1px dashed #bae6fd;' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $fi ?> file-icon" style="color:<?= $fc ?>;"></i>
                                <div class="file-body">
                                    <?php if ($pof['label']): ?>
                                        <div class="file-label-tag"><?= htmlspecialchars($pof['label']) ?></div><?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
                                    <span class="fstatus approved"><i class="fas fa-check-circle"></i> Approved PO</span>
                                    <a href="../../<?= htmlspecialchars($pof['file_path']) ?>?v=<?= filemtime(realpath('../../' . $pof['file_path'])) ?: time() ?>"
                                        target="_blank" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <span
                                        style="font-size:11px;font-weight:700;color:<?= $hasReceipt ? '#0369a1' : '#9ca3af' ?>;display:flex;align-items:center;gap:4px;">
                                        <i class="fas fa-receipt"></i>
                                        <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($hasReceipt): ?>
                            <div
                                style="background:#f0f9ff;border:1px solid #bae6fd;border-top:none;border-radius:0 0 10px 10px;padding:10px 16px 14px;">
                                <div
                                    style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#0369a1;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIc, $rcCo] = fileIcon($rcExt);
                                    ?>
                                    <div
                                        style="background:#fff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;gap:12px;align-items:center;">
                                        <i class="fas <?= $rcIc ?>" style="color:<?= $rcCo ?>;font-size:20px;flex-shrink:0;"></i>
                                        <div style="flex:1;min-width:0;">
                                            <?php if ($rc['label']): ?>
                                                <div
                                                    style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0369a1;margin-bottom:2px;">
                                                    <?= htmlspecialchars($rc['label']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div
                                                style="font-size:13px;font-weight:600;color:#1c1007;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?= htmlspecialchars($rc['file_name']) ?>
                                            </div>
                                            <div style="font-size:11px;color:#9c7b6a;display:flex;gap:10px;flex-wrap:wrap;margin-top:3px;">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i>
                                                    <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?>
                                                    KB</span>
                                            </div>
                                        </div>
                                        <a href="../../<?= htmlspecialchars($rc['file_path']) ?>?v=<?= filemtime(realpath('../../' . $rc['file_path'])) ?: time() ?>"
                                            target="_blank" class="btn btn-view" style="flex-shrink:0;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php if (!empty($poApprovedFiles)): ?>
                <div class="sec-label"><i class="fas fa-calculator"></i> Approved Bills of Materials</div>
            <?php endif; ?>
            <?php if (!empty($poApprovedFiles)):
                foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fi, $fc] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <div style="margin-bottom:18px;">
                        <!-- PO Card -->
                        <div class="file-card po-mirror"
                            style="margin-bottom:0;border-radius:<?= $hasReceipt ? '10px 10px 0 0' : '10px' ?>;<?= $hasReceipt ? 'border-bottom:1px dashed #bae6fd;' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $fi ?> file-icon" style="color:<?= $fc ?>;"></i>
                                <div class="file-body">
                                    <?php if ($pof['label']): ?>
                                        <div class="file-label-tag"><?= htmlspecialchars($pof['label']) ?></div><?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
                                    <span class="fstatus approved"><i class="fas fa-check-circle"></i> Approved PO</span>
                                    <a href="../../<?= htmlspecialchars($pof['file_path']) ?>" target="_blank" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <span
                                        style="font-size:11px;font-weight:700;color:<?= $hasReceipt ? '#0369a1' : '#9ca3af' ?>;display:flex;align-items:center;gap:4px;">
                                        <i class="fas fa-receipt"></i>
                                        <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Receipts nested under this PO -->
                        <?php if ($hasReceipt): ?>
                            <div
                                style="background:#f0f9ff;border:1px solid #bae6fd;border-top:none;border-radius:0 0 10px 10px;padding:10px 16px 14px;">
                                <div
                                    style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#0369a1;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIc, $rcCo] = fileIcon($rcExt);
                                    ?>
                                    <div
                                        style="background:#fff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;gap:12px;align-items:center;">
                                        <i class="fas <?= $rcIc ?>" style="color:<?= $rcCo ?>;font-size:20px;flex-shrink:0;"></i>
                                        <div style="flex:1;min-width:0;">
                                            <?php if ($rc['label']): ?>
                                                <div
                                                    style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0369a1;margin-bottom:2px;">
                                                    <?= htmlspecialchars($rc['label']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div
                                                style="font-size:13px;font-weight:600;color:#1c1007;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?= htmlspecialchars($rc['file_name']) ?>
                                            </div>
                                            <div style="font-size:11px;color:#9c7b6a;display:flex;gap:10px;flex-wrap:wrap;margin-top:3px;">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i>
                                                    <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?>
                                                    KB</span>
                                            </div>
                                        </div>
                                        <a href="../../<?= htmlspecialchars($rc['file_path']) ?>" target="_blank" class="btn btn-view"
                                            style="flex-shrink:0;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
        <?php elseif ($isApproval): ?>
            <div class="sec-label"><i class="fas fa-folder-open"></i> Submitted Files for Approval</div>
        <?php else: ?>
            <div class="sec-label"><i class="fas fa-folder-open"></i> Uploaded Files</div>
        <?php endif; ?>

        <!-- PO Stage: BOM-grouped view -->
        <?php if ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php if (empty($bomApprovedFiles)): ?>
                <div class="empty" style="margin-bottom:24px;">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="empty-title">No Approved BOMs Yet</div>
                    <div class="empty-sub">BOMs will appear here once approved in the Bill of Materials stage.</div>
                </div>
            <?php else: ?>

                <?php
                $osColors = [
                    'pending' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a', 'label' => 'Not Yet Ordered', 'icon' => 'fa-clock'],
                    'ordered' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#a7f3d0', 'label' => 'Ordered', 'icon' => 'fa-check-circle'],
                    'partially_ordered' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#bfdbfe', 'label' => 'Partially Ordered', 'icon' => 'fa-adjust'],
                ];
                ?>

                <?php foreach ($bomApprovedFiles as $bom):
                    $bomExt = strtolower(pathinfo($bom['file_name'], PATHINFO_EXTENSION));
                    [$bomIcon, $bomColor] = fileIcon($bomExt);
                    $linkedPos = $posByBom[$bom['id']] ?? [];
                    $hasPos = !empty($linkedPos);
                    $osc = $osColors[$bom['order_status']] ?? $osColors['pending'];
                    ?>
                    <div style="margin-bottom:18px;">
                        <!-- BOM Card -->
                        <div class="file-card"
                            style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:<?= $hasPos ? '10px 10px 0 0' : '10px' ?>;margin-bottom:0;<?= $hasPos ? 'border-bottom:1px dashed #a7f3d0;' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $bomIcon ?> file-icon" style="color:<?= $bomColor ?>;"></i>
                                <div class="file-body">
                                    <?php if ($bom['label']): ?>
                                        <div class="file-label-tag" style="color:#065f46;"><?= htmlspecialchars($bom['label']) ?></div>
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
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
                                    <span class="fstatus approved"><i class="fas fa-check-circle"></i> Approved BOM</span>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:<?= $osc['bg'] ?>;color:<?= $osc['color'] ?>;border:1px solid <?= $osc['border'] ?>;">
                                        <i class="fas <?= $osc['icon'] ?>"></i> <?= $osc['label'] ?>
                                    </span>
                                    <div style="display:flex;gap:6px;align-items:center;">
                                        <a href="../../<?= htmlspecialchars($bom['file_path']) ?>?v=<?= filemtime(realpath('../../' . $bom['file_path'])) ?: time() ?>"
                                            target="_blank" class="btn btn-view">
                                            <i class="fas fa-eye"></i> View BOM
                                        </a>
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
                                    $myPoStatus = $myPoReview ? $myPoReview['review_status'] : null;

                                    // GM/OM: check if other one already handled it
                                    $poGmOmAlreadyHandled = false;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $otherGmOm2 = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
                                        $otherGmOmRev2 = $po['role_reviews'][$otherGmOm2] ?? null;
                                        if ($otherGmOmRev2 && $otherGmOmRev2['review_status'] === 'approved') {
                                            $poGmOmAlreadyHandled = true;
                                        }
                                    }

                                    // GM/OM: check step 1 roles approved first
                                    $poGmOmCanActNow = true;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $step1Roles = ['accounting'];
                                        foreach ($step1Roles as $s1r) {
                                            $s1rev = $po['role_reviews'][$s1r] ?? null;
                                            if (!$s1rev || $s1rev['review_status'] !== 'approved') {
                                                $poGmOmCanActNow = false;
                                                break;
                                            }
                                        }
                                    }

                                    $reqPoRoles = $requiredApproversList['Purchase Order (Submit to accounting)'] ?? [];
                                    ?>
                                    <div
                                        style="background:#fff;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;margin-bottom:8px;">

                                        <!-- My review banner for this PO -->
                                        <?php if ($canApprove && $myPoReview): ?>
                                            <div class="reviewed-banner <?= $myPoStatus === 'rejected' ? 'rejected-banner' : '' ?>"
                                                style="margin-bottom:10px;">
                                                <i class="fas <?= $myPoStatus === 'approved' ? 'fa-check-double' : 'fa-times-circle' ?>"></i>
                                                You <?= $myPoStatus === 'approved' ? 'approved' : 'rejected' ?> this PO.
                                                <?php if ($myPoStatus === 'rejected' && $myPoReview['review_note']): ?>
                                                    Your note: "<?= htmlspecialchars($myPoReview['review_note']) ?>"
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($canApprove && $poGmOmAlreadyHandled): ?>
                                            <div class="reviewed-banner" style="margin-bottom:10px;">
                                                <i class="fas fa-check-double"></i>
                                                Already approved by <?= getRoleDisplayName($otherGmOm2) ?>. No further action needed.
                                            </div>
                                        <?php endif; ?>

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

                                                <!-- Approval badges for PO -->
                                                <div class="approval-badges" style="margin-top:8px;">
                                                    <?php foreach ($reqPoRoles as $role):
                                                        if (in_array($role, ['general_manager', 'operational_manager']))
                                                            continue;
                                                        $rev = $po['role_reviews'][$role] ?? null;
                                                        $bc = $rev ? $rev['review_status'] : 'pending';
                                                        $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                                        $isMine = ($role === $admin_role);
                                                        ?>
                                                        <span class="apbadge <?= $bc ?> <?= $isMine ? 'mine' : '' ?>">
                                                            <i class="fas <?= $bi ?>"></i> <?= getRoleDisplayName($role) ?>
                                                            <?php if ($isMine): ?><em
                                                                    style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                            <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                                <span class="apbadge-date">&middot;
                                                                    <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach;
                                                    $gmRev3 = $po['role_reviews']['general_manager'] ?? null;
                                                    $omRev3 = $po['role_reviews']['operational_manager'] ?? null;
                                                    $gmStatus3 = $gmRev3 ? $gmRev3['review_status'] : null;
                                                    $omStatus3 = $omRev3 ? $omRev3['review_status'] : null;
                                                    if ($gmStatus3 === 'approved' || $omStatus3 === 'approved') {
                                                        $cs3 = 'approved';
                                                        $cl3 = 'Approved by ' . ($gmStatus3 === 'approved' ? getRoleDisplayName('general_manager') : getRoleDisplayName('operational_manager'));
                                                        $ci3 = 'fa-check-circle';
                                                    } elseif ($gmStatus3 === 'rejected' || $omStatus3 === 'rejected') {
                                                        $cs3 = 'rejected';
                                                        $cl3 = 'Rejected by ' . ($gmStatus3 === 'rejected' ? getRoleDisplayName('general_manager') : getRoleDisplayName('operational_manager'));
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
                                                    <span class="apbadge <?= $cs3 ?> <?= $isMineGmOm3 ? 'mine' : '' ?>">
                                                        <i class="fas <?= $ci3 ?>"></i> <?= $cl3 ?>
                                                        <?php if ($isMineGmOm3 && ($gmRev3 || $omRev3)): ?><em
                                                                style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
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
                                                            <?php if ($rev['reviewer_name']): ?> —
                                                                <em><?= htmlspecialchars($rev['reviewer_name']) ?></em><?php endif; ?>
                                                        </div>
                                                    <?php endif; endforeach; ?>

                                                <!-- Step 1 pending notice -->
                                                <?php if ($canApprove && in_array($admin_role, ['general_manager', 'operational_manager']) && !$poGmOmCanActNow && $poStatus === 'pending'): ?>
                                                    <div
                                                        style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;font-size:12px;color:#92400e;margin-top:6px;display:flex;align-items:center;gap:6px;">
                                                        <i class="fas fa-hourglass-half" style="color:#d97706;flex-shrink:0;"></i>
                                                        <span>Waiting for <strong>Accounting</strong> to approve first.</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                                                <span class="fstatus <?= $poStatus ?>">
                                                    <?php if ($poStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                                    <?php elseif ($poStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                                    <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
                                                    <?= ucfirst($poStatus) ?>
                                                </span>
                                                <a href="../../<?= htmlspecialchars($po['file_path']) ?>?v=<?= filemtime(realpath('../../' . $po['file_path'])) ?: time() ?>"
                                                    target="_blank" class="btn btn-view">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <?php if ($canApprove && !$myPoReview && $poGmOmCanActNow && !$poGmOmAlreadyHandled && $poStatus === 'pending'): ?>
                                                    <button class="btn btn-approve" onclick="approveFile(<?= $po['id'] ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-reject" onclick="showRejectForm('po-<?= $po['id'] ?>')">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Inline reject form for this PO -->
                                        <div class="reject-form" id="reject-form-po-<?= $po['id'] ?>">
                                            <div style="font-size:13px;font-weight:700;color:#991b1b;margin-bottom:8px;"><i
                                                    class="fas fa-times-circle"></i> Rejection Note</div>
                                            <div class="btn-form-error" id="reject-err-po-<?= $po['id'] ?>">Please enter a rejection reason.
                                            </div>
                                            <textarea id="reject-note-po-<?= $po['id'] ?>"
                                                placeholder="Explain why this PO is being rejected..."></textarea>
                                            <div class="reject-form-actions">
                                                <button class="btn-cancel-reject"
                                                    onclick="cancelReject('po-<?= $po['id'] ?>')">Cancel</button>
                                                <button class="btn-confirm-reject" onclick="submitReject(<?= $po['id'] ?>)"><i
                                                        class="fas fa-times"></i> Confirm Rejection</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; // end PO stage section ?>

        <!-- Category filter — hidden for Accounting and PO stage -->
        <?php if (!empty($categories) && !$isAccounting && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="cat-bar">
                <button class="cat-btn active" onclick="filterCategory('all',this)"><i class="fas fa-th-large"></i>
                    All</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="cat-btn"
                        onclick="filterCategory('<?= htmlspecialchars(addslashes($cat)) ?>',this)"><?= htmlspecialchars($cat) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Files — hidden for Accounting (receipts shown per-PO above) -->
        <?php if ($isAccounting || $stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php /* displayed in grouped sections above */ ?>
        <?php elseif (empty($files)): ?>
            <div class="empty">
                <i class="fas fa-file"></i>
                <div class="empty-title">No files submitted yet</div>
                <div class="empty-sub">Files submitted for this stage will appear here.</div>
            </div>
        <?php else:
            foreach ($files as $f):
                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                [$fi, $fc] = fileIcon($ext);
                $fStatus = $f['approval_status'] ?? 'pending';
                $myReview = $f['role_reviews'][$admin_role] ?? null;
                $myStatus = $myReview ? $myReview['review_status'] : null;

                // For GM/OM: if the other one already approved or rejected, treat as if this user already reviewed
                $gmOmAlreadyHandled = false;
                if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                    $otherGmOm = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
                    $otherGmOmRev = $f['role_reviews'][$otherGmOm] ?? null;
                    if ($otherGmOmRev && $otherGmOmRev['review_status'] === 'approved') {
                        $gmOmAlreadyHandled = true;
                    }
                }
                ?>
                <div class="file-card <?= $fStatus ?>" data-category="<?= htmlspecialchars($f['label'] ?? '') ?>">

                    <!-- My review status banner -->
                    <?php if ($canApprove && $myReview): ?>
                        <div class="reviewed-banner <?= $myStatus === 'rejected' ? 'rejected-banner' : '' ?>">
                            <i class="fas <?= $myStatus === 'approved' ? 'fa-check-double' : 'fa-times-circle' ?>"></i>
                            You <?= $myStatus === 'approved' ? 'approved' : 'rejected' ?> this file.
                            <?php if ($myStatus === 'rejected' && $myReview['review_note']): ?>
                                Your note: "<?= htmlspecialchars($myReview['review_note']) ?>"
                            <?php endif; ?>
                        </div>
                    <?php elseif ($canApprove && $gmOmAlreadyHandled): ?>
                        <div class="reviewed-banner">
                            <i class="fas fa-check-double"></i>
                            This file was already approved by <?= getRoleDisplayName($otherGmOm) ?>. No further action needed.
                        </div>
                    <?php endif; ?>

                    <div class="file-row">
                        <i class="fas <?= $fi ?> file-icon" style="color:<?= $fc ?>;"></i>
                        <div class="file-body">
                            <?php if ($f['label']): ?>
                                <div class="file-label-tag"><?= htmlspecialchars($f['label']) ?></div><?php endif; ?>
                            <div class="file-name"><?= htmlspecialchars($f['file_name']) ?></div>
                            <div class="file-meta">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['uploaded_by_name']) ?></span>
                                <span><i class="fas fa-calendar"></i>
                                    <?= date('M d, Y · g:i A', strtotime($f['uploaded_at'])) ?></span>
                                <span><i class="fas fa-weight"></i> <?= number_format($f['file_size'] / 1024, 1) ?> KB</span>
                            </div>

                            <!-- Role approval badges -->
                            <?php if ($isApproval && !empty($requiredApproversList[$stage])): ?>
                                <?php
                                $gmOmStages2 = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                                $reqRoles = $requiredApproversList[$stage];
                                ?>
                                <div class="approval-badges">
                                    <?php if (in_array($stage, $gmOmStages2)):
                                        // Non-GM/OM roles first
                                        foreach ($reqRoles as $role):
                                            if (in_array($role, ['general_manager', 'operational_manager']))
                                                continue;
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bc = $rev ? $rev['review_status'] : 'pending';
                                            $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bc ?> <?= $isMine ? 'mine' : '' ?>">
                                                <i class="fas <?= $bi ?>"></i>
                                                <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="apbadge-date">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach;
                                        // Combined GM/OM badge — check if either has reviewed
                                        $gmRev = $f['role_reviews']['general_manager'] ?? null;
                                        $omRev = $f['role_reviews']['operational_manager'] ?? null;
                                        $gmStatus = $gmRev ? $gmRev['review_status'] : null;
                                        $omStatus = $omRev ? $omRev['review_status'] : null;
                                        // Determine combined display status
                                        if ($gmStatus === 'approved' || $omStatus === 'approved') {
                                            $combinedStatus = 'approved';
                                            $whoApproved = $gmStatus === 'approved'
                                                ? getRoleDisplayName('general_manager')
                                                : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Approved by {$whoApproved}";
                                            $combinedIcon = 'fa-check-circle';
                                        } elseif ($gmStatus === 'rejected' || $omStatus === 'rejected') {
                                            $combinedStatus = 'rejected';
                                            $whoRejected = $gmStatus === 'rejected'
                                                ? getRoleDisplayName('general_manager')
                                                : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Rejected by {$whoRejected}";
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
                                        <span class="apbadge <?= $combinedStatus ?> <?= $isMineGmOm ? 'mine' : '' ?>">
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
                                        foreach ($reqRoles as $role):
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bc = $rev ? $rev['review_status'] : 'pending';
                                            $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bc ?> <?= $isMine ? 'mine' : '' ?>">
                                                <i class="fas <?= $bi ?>"></i>
                                                <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em style="font-size:9px;opacity:.7;">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="apbadge-date">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; endif; /* end isAccounting check */ ?>
                                </div>
                                <!-- Rejection notes from all roles -->
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

                                <!-- Step 1 pending notice for GM/OM -->
                                <?php
                                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages2)):
                                    $sequentialStagesInfo = [
                                        'Rough Estimation' => ['designer'],
                                        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
                                        'Quotation' => ['designer'],
                                        'Bill of Materials (BOM)' => ['technical_designer'],
                                        'Purchase Order (Submit to accounting)' => ['accounting'],
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

                            <?php endif; ?>

                            <!-- Approve / Reject actions (only if canApprove AND not yet reviewed by me) -->
                            <?php
                            // For GM/OM: hide approve/reject buttons if step 1 has not approved yet
                            $gmOmCanActNow = true;
                            if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages2)) {
                                $seqInfo2 = [
                                    'Rough Estimation' => ['designer'],
                                    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
                                    'Quotation' => ['designer'],
                                    'Bill of Materials (BOM)' => ['technical_designer'],
                                    'Purchase Order (Submit to accounting)' => ['accounting'],
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
                            <?php if ($canApprove && !$myReview && $gmOmCanActNow && !$gmOmAlreadyHandled): ?>
                                <div class="file-actions">
                                    <a href="../../<?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath('../../' . $f['file_path'])) ?: time() ?>"
                                        target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View File</a>
                                    <button class="btn btn-approve" onclick="approveFile(<?= $f['id'] ?>)"><i
                                            class="fas fa-check-circle"></i> Approve</button>
                                    <button class="btn btn-reject" onclick="showRejectForm(<?= $f['id'] ?>)"><i
                                            class="fas fa-times-circle"></i> Reject</button>
                                    <span class="fstatus <?= $fStatus ?>" style="margin-left:auto;">
                                        <?php if ($fStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                        <?php elseif ($fStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                        <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
                                        <?= ucfirst($fStatus) ?>
                                    </span>
                                </div>
                                <!-- Reject inline form -->
                                <div class="reject-form" id="reject-form-<?= $f['id'] ?>">
                                    <div style="font-size:13px;font-weight:700;color:#991b1b;margin-bottom:8px;"><i
                                            class="fas fa-times-circle"></i> Rejection Note</div>
                                    <div class="btn-form-error" id="reject-err-<?= $f['id'] ?>">Please enter a rejection reason.
                                    </div>
                                    <textarea id="reject-note-<?= $f['id'] ?>"
                                        placeholder="Explain why this file is being rejected. The submitter will be notified."></textarea>
                                    <div class="reject-form-actions">
                                        <button class="btn-cancel-reject" onclick="cancelReject(<?= $f['id'] ?>)">Cancel</button>
                                        <button class="btn-confirm-reject" onclick="submitReject(<?= $f['id'] ?>)"><i
                                                class="fas fa-times"></i> Confirm Rejection</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="file-actions">
                                    <a href="../../<?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath('../../' . $f['file_path'])) ?: time() ?>"
                                        target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View File</a>
                                    <?php if ($canApprove && ($myReview || $gmOmAlreadyHandled)): ?>
                                        <span
                                            style="font-size:11px;color:#059669;font-weight:600;display:flex;align-items:center;gap:4px;">
                                            <i class="fas fa-check-double"></i>
                                            <?= $myReview ? 'You reviewed this' : 'Handled by ' . getRoleDisplayName($otherGmOm) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="fstatus <?= $fStatus ?>" style="margin-left:auto;">
                                        <?php if ($fStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                        <?php elseif ($fStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                        <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
                                        <?= ucfirst($fStatus) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>

    </div>

    <!-- Internal P.O — Approval Status + NTP Panel (Manager read-only view) -->
    <?php if ($isInternalPo): ?>
        <div
            style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;margin-top:24px;margin-bottom:20px;box-shadow:var(--shadow);max-width:860px;margin-left:auto;margin-right:auto;">
            <div
                style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-lt);margin-bottom:14px;display:flex;align-items:center;gap:7px;">
                <i class="fas fa-stamp"></i> Stage Approval Status
            </div>

            <?php if (!$internalPoApproval): ?>
                <div
                    style="background:#faf8f5;border:2px dashed var(--border);border-radius:10px;padding:20px 22px;display:flex;align-items:center;gap:12px;">
                    <div
                        style="width:40px;height:40px;border-radius:10px;background:var(--brown-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-paper-plane" style="color:var(--brown-md);font-size:16px;"></i>
                    </div>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:var(--text-dk);">No approval requested yet</div>
                        <div style="font-size:12px;color:var(--text-lt);margin-top:3px;">The assigned staff has not yet
                            requested approval for this stage.</div>
                    </div>
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
                            <?php if ($overallStatus === 'pending'): ?>Approval in progress
                            <?php elseif ($overallStatus === 'approved'): ?>Fully approved
                            <?php else: ?>Rejected — staff needs to fix and re-request
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:<?= $oc['color'] ?>;opacity:.8;margin-top:2px;">
                            Requested by <?= htmlspecialchars($ipa['requested_by_name']) ?> ·
                            <?= date('M d, Y g:i A', strtotime($ipa['requested_at'])) ?>
                        </div>
                    </div>
                </div>

                <!-- NTP files -->
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
                    <div style="margin-bottom:16px;">
                        <div
                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-lt);margin-bottom:10px;display:flex;align-items:center;gap:7px;">
                            <i class="fas fa-file-signature" style="color:#0369a1;"></i> Notice to Proceed (NTP) Files
                        </div>
                        <?php foreach ($ntpFiles as $ntp):
                            $ntpExt = strtolower(pathinfo($ntp['file_name'], PATHINFO_EXTENSION));
                            $ntpViewable = in_array($ntpExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $ntpExt === 'pdf';
                            ?>
                            <div
                                style="background:#f0f9ff;border:1px solid #7dd3fc;border-radius:8px;padding:12px 14px;margin-bottom:8px;">
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
                                            <div
                                                style="font-size:11px;color:#374151;background:#e0f2fe;border-radius:6px;padding:5px 8px;margin-top:5px;">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($ntp['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:6px;">
                                        <?php if ($ntpViewable): ?>
                                            <a href="../../<?= htmlspecialchars($ntp['file_path']) ?>" target="_blank" class="btn btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                        <a href="../../<?= htmlspecialchars($ntp['file_path']) ?>"
                                            download="<?= htmlspecialchars($ntp['file_name']) ?>" class="btn btn-view"
                                            style="background:#dcfce7;color:#166534;border-color:#86efac;">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Reviewer steps (read-only) -->
                <div style="display:flex;flex-direction:column;gap:10px;">

                    <!-- Step 1: Accounting -->
                    <?php
                    $acStatus = $ipa['accounting_status'];
                    $acColors = [
                        'pending' => ['#f3f4f6', '#9ca3af', '#e5e7eb', 'fa-clock'],
                        'approved' => ['#d1fae5', '#065f46', '#10b981', 'fa-check-circle'],
                        'rejected' => ['#fee2e2', '#991b1b', '#ef4444', 'fa-times-circle'],
                    ];
                    $acc = $acColors[$acStatus];
                    ?>
                    <div style="background:<?= $acc[0] ?>;border:1px solid <?= $acc[2] ?>;border-radius:8px;padding:12px 14px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span
                                style="background:<?= $acc[2] ?>;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">1</span>
                            <div>
                                <div
                                    style="font-size:12px;font-weight:700;color:<?= $acc[1] ?>;display:flex;align-items:center;gap:5px;">
                                    <i class="fas <?= $acc[3] ?>"></i> Accounting
                                    <?php if ($acStatus === 'pending'): ?>
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
                    </div>

                    <!-- Step 2: Head Designer -->
                    <?php
                    $dsStatus = $ipa['designer_status'];
                    $dsLocked = ($acStatus !== 'approved');
                    $dColors = [
                        'pending' => ['#f3f4f6', '#9ca3af', '#e5e7eb', 'fa-clock'],
                        'approved' => ['#d1fae5', '#065f46', '#10b981', 'fa-check-circle'],
                        'rejected' => ['#fee2e2', '#991b1b', '#ef4444', 'fa-times-circle'],
                    ];
                    $dc = $dColors[$dsStatus];
                    ?>
                    <div
                        style="background:<?= $dc[0] ?>;border:1px solid <?= $dc[2] ?>;border-radius:8px;padding:12px 14px;<?= $dsLocked ? 'opacity:.5;' : '' ?>">
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
                    </div>

                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div id="toast" class="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

    <?php include $includes ['esign-modal']; ?>

    <script>
        const STAGE_ID = <?= $stage_id ?>;

        // Approve — opens e-sign modal
        function approveFile(approvalId) {
            _currentApprovalId = approvalId;
            _signX = null; _signY = null; _signPage = 1;

            document.getElementById('esignToggle').checked = false;
            document.getElementById('esignIframeWrap').style.display = 'none';
            document.getElementById('esignStatusBar').style.display = 'none';
            document.getElementById('toggleSlider').style.background = '#ccc';
            document.getElementById('toggleThumb').style.transform = 'translateX(0)';

            const btn = document.getElementById('esignSubmitBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';

            document.getElementById('esignModal').classList.add('show');
        }

        // Reject form
        function showRejectForm(id) {
            document.getElementById('reject-form-' + id).style.display = 'block';
            document.getElementById('reject-note-' + id).focus();
        }
        function cancelReject(id) {
            document.getElementById('reject-form-' + id).style.display = 'none';
            document.getElementById('reject-note-' + id).value = '';
            document.getElementById('reject-err-' + id).style.display = 'none';
        }
        async function submitReject(approvalId) {
            // Support both plain IDs and 'po-' prefixed IDs
            const noteEl = document.getElementById('reject-note-' + approvalId)
                || document.getElementById('reject-note-po-' + approvalId);
            const err = document.getElementById('reject-err-' + approvalId)
                || document.getElementById('reject-err-po-' + approvalId);
            const note = noteEl ? noteEl.value.trim() : '';
            if (!note) { err.style.display = 'block'; return; }
            err.style.display = 'none';
            try {
                const res = await fetch('<?= BASE_URL ?>approve-reject-stage', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ approval_id: approvalId, action: 'rejected', note })
                });
                const data = await res.json();
                if (data.success) {
                    toast('File rejected.');
                    setTimeout(() => location.reload(), 1000);
                } else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        // Category filter
        function filterCategory(cat, btn) {
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.file-card[data-category]').forEach(card => {
                card.style.display = (cat === 'all' || card.dataset.category === cat) ? '' : 'none';
            });
        }

        // Modal close on overlay
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
        });

        function toast(msg, err = false) {
            const el = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            el.className = 'toast show' + (err ? ' error' : '');
            setTimeout(() => el.classList.remove('show'), 3000);
        }
    </script>
</body>

</html>