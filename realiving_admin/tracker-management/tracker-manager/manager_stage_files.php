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

// ── Tailwind helper class bundles (matches sales_dashboard.php design tokens) ──
// adm-bg #F5F5F5 · adm-surface #FFFFFF · adm-ink #0B0B0B · adm-soft #6B6B6B · adm-muted #9A9A9A · adm-line #E2E2E2
function fstatusClasses($status)
{
    $m = [
        'approved' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        'rejected' => 'bg-red-50 text-red-700 border border-red-200',
        'pending' => 'bg-amber-50 text-amber-700 border border-amber-200',
    ];
    return $m[$status] ?? $m['pending'];
}
function apbadgeClasses($status)
{
    $m = [
        'approved' => 'bg-emerald-50 text-emerald-700 border border-emerald-300',
        'rejected' => 'bg-red-50 text-red-700 border border-red-300',
        'pending' => 'bg-[#F5F5F5] text-[#9A9A9A] border border-[#E2E2E2]',
    ];
    return $m[$status] ?? $m['pending'];
}
function fcardBorder($status)
{
    $m = [
        'approved' => 'border-l-2 border-l-emerald-500',
        'rejected' => 'border-l-2 border-l-red-500',
        'pending' => 'border-l-2 border-l-amber-400',
    ];
    return $m[$status] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review — <?= htmlspecialchars($stage) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        @keyframes admFade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .adm-fade {
            animation: admFade .4s ease both;
        }

        @media (prefers-reduced-motion: reduce) {
            .adm-fade {
                animation: none;
            }
        }

        .adm-section-label::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #E2E2E2;
        }
    </style>
</head>

<body class="min-h-screen bg-[#F5F5F5] text-[#0B0B0B]">
    <div class="max-w-4xl mx-auto w-full px-4 sm:px-6 lg:px-8 pt-10 pb-16">

        <a href="manager-project-detail?client_id=<?= $client_id ?>"
            class="inline-flex items-center gap-2 text-[13px] font-semibold text-[#6B6B6B] hover:text-[#0B0B0B] transition-colors mb-6">
            <i class="fas fa-arrow-left"></i> Back to Project Dashboard
        </a>

        <!-- Header -->
        <div class="mb-6 adm-fade">
            <div class="bg-[#0B0B0B] rounded-xl p-6 sm:p-7 text-white">
                <div class="flex items-start justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div
                            class="w-11 h-11 rounded-[9px] bg-white/10 border border-white/15 flex items-center justify-center text-[17px] flex-shrink-0">
                            <i class="fas fa-<?= $isApproval ? 'stamp' : ($isAccounting ? 'receipt' : 'file-upload') ?>"></i>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-[1.5px] text-white/50 mb-0.5">Stage
                                Review</div>
                            <div class="text-[19px] font-bold tracking-tight"><?= htmlspecialchars($stage) ?></div>
                            <div class="text-[12.5px] text-white/60 mt-0.5"><?= htmlspecialchars($client['clientname']) ?>
                                &middot; <?= htmlspecialchars($client['nameproject']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <div class="text-[10px] uppercase tracking-wide text-white/45 mb-1">Stage Status</div>
                        <div class="text-[14px] font-bold flex items-center gap-1.5 justify-end">
                            <?php if ($stageStatus === 'Done'): ?>
                                <i class="fas fa-check-circle text-emerald-400"></i>
                            <?php elseif ($stageStatus === 'Ongoing'): ?>
                                <i class="fas fa-circle-notch fa-spin text-sky-400"></i>
                            <?php else: ?>
                                <i class="fas fa-clock text-amber-300"></i>
                            <?php endif; ?>
                            <?= $stageStatus ?>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 mt-4">
                    <span
                        class="bg-white/10 border border-white/15 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><?= htmlspecialchars(str_replace('_', ' ', $admin_role)) ?></span>
                    <?php if ($isApproval): ?>
                        <span
                            class="bg-amber-400/15 border border-amber-300/30 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><i
                                class="fas fa-stamp"></i> Approval Required</span>
                        <?php
                        $gmOmStages = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)', 'Production Data Submittals'];
                        $required = $requiredApproversList[$stage] ?? [];
                        if (in_array($stage, $gmOmStages)):
                            foreach ($required as $role):
                                if (in_array($role, ['general_manager', 'operational_manager']))
                                    continue;
                                ?>
                                <span
                                    class="bg-white/10 border border-white/15 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><i
                                        class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                            <?php endforeach; ?>
                            <span
                                class="bg-indigo-400/15 border border-indigo-300/30 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide">
                                <i class="fas fa-user-check"></i> GM <em class="opacity-60 text-[10px] not-italic">or</em>
                                OM <em class="opacity-60 text-[10px] not-italic">(one required)</em>
                            </span>
                        <?php else:
                            foreach ($required as $role):
                                ?>
                                <span
                                    class="bg-white/10 border border-white/15 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><i
                                        class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                            <?php endforeach; endif; ?>
                    <?php elseif ($isFileUpload): ?>
                        <span
                            class="bg-violet-400/15 border border-violet-300/30 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><i
                                class="fas fa-file-upload"></i> File Upload Stage</span>
                    <?php elseif ($isAccounting): ?>
                        <span
                            class="bg-sky-400/15 border border-sky-300/30 rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"><i
                                class="fas fa-receipt"></i> Delivery Receipt</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary counts -->
        <?php if ($isApproval && !empty($files)): ?>
            <div class="grid grid-cols-3 gap-3 mb-6 adm-fade">
                <div class="bg-white border border-[#E2E2E2] rounded-[10px] px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold font-mono text-amber-500"><?= $pendingCount ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9A9A9A] mt-0.5"><i
                            class="fas fa-clock"></i> Pending</div>
                </div>
                <div class="bg-white border border-[#E2E2E2] rounded-[10px] px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold font-mono text-emerald-600"><?= $approvedCount ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9A9A9A] mt-0.5"><i
                            class="fas fa-check-circle"></i> Approved</div>
                </div>
                <div class="bg-white border border-[#E2E2E2] rounded-[10px] px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold font-mono text-red-500"><?= $rejectedCount ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9A9A9A] mt-0.5"><i
                            class="fas fa-times-circle"></i> Rejected</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- My action banner -->
        <?php
        $myPendingFiles = array_filter($files, fn($f) => $f['approval_status'] === 'pending' && !myReviewDone($f, $admin_role));
        if ($canApprove && count($myPendingFiles) > 0):
            ?>
            <div class="bg-amber-50 border border-amber-200 rounded-[10px] px-4 py-3.5 mb-5 flex items-center gap-3 adm-fade">
                <i class="fas fa-exclamation-circle text-amber-500"></i>
                <div class="text-[13px] font-semibold text-amber-800">
                    <?= count($myPendingFiles) ?> file<?= count($myPendingFiles) !== 1 ? 's' : '' ?>
                    need<?= count($myPendingFiles) === 1 ? 's' : '' ?> your review. Use the Approve or Reject buttons below.
                </div>
            </div>
        <?php endif; ?>

        <!-- PO Mirror (Accounting) — grouped with receipts -->
        <?php if ($isAccounting): ?>
            <div class="adm-section-label flex items-center gap-2.5 text-xs font-semibold text-[#0B0B0B] mb-4"><i
                    class="fas fa-file-import"></i> Purchase Orders &amp; Receipts</div>
            <?php if (empty($poApprovedFiles)): ?>
                <div class="text-center py-11 px-5 bg-white border-2 border-dashed border-[#E2E2E2] rounded-[10px] mb-6">
                    <i class="fas fa-hourglass-half text-3xl text-[#E2E2E2] mb-2.5 block"></i>
                    <div class="text-sm font-semibold text-[#0B0B0B] mb-1">Waiting for PO Approval</div>
                    <div class="text-xs text-[#9A9A9A]">Purchase Order files will appear here once approved.</div>
                </div>
            <?php else: ?>
                <?php foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fi, $fc] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <div class="mb-4">
                        <div
                            class="bg-sky-50 border border-sky-200 border-l-2 border-l-sky-500 p-4 sm:p-5 <?= $hasReceipt ? 'rounded-t-[10px] border-b-0' : 'rounded-[10px]' ?>">
                            <div class="flex gap-3.5 items-start">
                                <i class="fas <?= $fi ?> text-2xl flex-shrink-0 mt-0.5" style="color:<?= $fc ?>;"></i>
                                <div class="flex-1 min-w-0">
                                    <?php if ($pof['label']): ?>
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-[#6B6B6B] mb-0.5">
                                            <?= htmlspecialchars($pof['label']) ?>
                                        </div><?php endif; ?>
                                    <div class="text-sm font-semibold truncate"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="text-[11px] text-[#9A9A9A] flex flex-wrap gap-2.5 mt-1">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase <?= fstatusClasses('approved') ?>"><i
                                            class="fas fa-check-circle"></i> Approved PO</span>
                                    <a href="<?= BASE_URL . htmlspecialchars($pof['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $pof['file_path']) ? filemtime(ROOT_PATH . $pof['file_path']) : time() ?>"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <span
                                        class="text-[11px] font-bold flex items-center gap-1 <?= $hasReceipt ? 'text-sky-700' : 'text-[#9A9A9A]' ?>">
                                        <i class="fas fa-receipt"></i>
                                        <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($hasReceipt): ?>
                            <div class="bg-sky-50/60 border border-sky-200 border-t-0 rounded-b-[10px] px-4 pt-2.5 pb-3.5">
                                <div
                                    class="text-[10px] font-bold uppercase tracking-wide text-sky-700 mb-2.5 flex items-center gap-1.5">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIc, $rcCo] = fileIcon($rcExt);
                                    ?>
                                    <div
                                        class="bg-white border border-sky-200 rounded-lg px-4 py-3 mb-2 flex gap-3 items-center">
                                        <i class="fas <?= $rcIc ?> text-xl flex-shrink-0" style="color:<?= $rcCo ?>;"></i>
                                        <div class="flex-1 min-w-0">
                                            <?php if ($rc['label']): ?>
                                                <div class="text-[10px] font-bold uppercase tracking-wide text-sky-700 mb-0.5">
                                                    <?= htmlspecialchars($rc['label']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($rc['file_name']) ?>
                                            </div>
                                            <div class="text-[11px] text-[#9A9A9A] flex gap-2.5 flex-wrap mt-0.5">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i>
                                                    <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?>
                                                    KB</span>
                                            </div>
                                        </div>
                                        <a href="<?= BASE_URL . htmlspecialchars($rc['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $rc['file_path']) ? filemtime(ROOT_PATH . $rc['file_path']) : time() ?>"
                                            target="_blank"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors flex-shrink-0">
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
                <div class="adm-section-label flex items-center gap-2.5 text-xs font-semibold text-[#0B0B0B] mb-4"><i
                        class="fas fa-calculator"></i> Approved Bills of Materials</div>
            <?php endif; ?>
            <?php if (!empty($poApprovedFiles)):
                foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fi, $fc] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <div class="mb-4">
                        <!-- PO Card -->
                        <div
                            class="bg-sky-50 border border-sky-200 border-l-2 border-l-sky-500 p-4 sm:p-5 <?= $hasReceipt ? 'rounded-t-[10px] border-b-0' : 'rounded-[10px]' ?>">
                            <div class="flex gap-3.5 items-start">
                                <i class="fas <?= $fi ?> text-2xl flex-shrink-0 mt-0.5" style="color:<?= $fc ?>;"></i>
                                <div class="flex-1 min-w-0">
                                    <?php if ($pof['label']): ?>
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-[#6B6B6B] mb-0.5">
                                            <?= htmlspecialchars($pof['label']) ?>
                                        </div><?php endif; ?>
                                    <div class="text-sm font-semibold truncate"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="text-[11px] text-[#9A9A9A] flex flex-wrap gap-2.5 mt-1">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase <?= fstatusClasses('approved') ?>"><i
                                            class="fas fa-check-circle"></i> Approved PO</span>
                                    <a href="<?= BASE_URL . htmlspecialchars($pof['file_path']) ?>" target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <span
                                        class="text-[11px] font-bold flex items-center gap-1 <?= $hasReceipt ? 'text-sky-700' : 'text-[#9A9A9A]' ?>">
                                        <i class="fas fa-receipt"></i>
                                        <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Receipts nested under this PO -->
                        <?php if ($hasReceipt): ?>
                            <div class="bg-sky-50/60 border border-sky-200 border-t-0 rounded-b-[10px] px-4 pt-2.5 pb-3.5">
                                <div
                                    class="text-[10px] font-bold uppercase tracking-wide text-sky-700 mb-2.5 flex items-center gap-1.5">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIc, $rcCo] = fileIcon($rcExt);
                                    ?>
                                    <div
                                        class="bg-white border border-sky-200 rounded-lg px-4 py-3 mb-2 flex gap-3 items-center">
                                        <i class="fas <?= $rcIc ?> text-xl flex-shrink-0" style="color:<?= $rcCo ?>;"></i>
                                        <div class="flex-1 min-w-0">
                                            <?php if ($rc['label']): ?>
                                                <div class="text-[10px] font-bold uppercase tracking-wide text-sky-700 mb-0.5">
                                                    <?= htmlspecialchars($rc['label']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($rc['file_name']) ?>
                                            </div>
                                            <div class="text-[11px] text-[#9A9A9A] flex gap-2.5 flex-wrap mt-0.5">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i>
                                                    <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?>
                                                    KB</span>
                                            </div>
                                        </div>
                                        <a href="<?= BASE_URL . htmlspecialchars($rc['file_path']) ?>" target="_blank"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors flex-shrink-0">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
        <?php elseif ($isApproval): ?>
            <div class="adm-section-label flex items-center gap-2.5 text-xs font-semibold text-[#0B0B0B] mb-4"><i
                    class="fas fa-folder-open"></i> Submitted Files for Approval</div>
        <?php else: ?>
            <div class="adm-section-label flex items-center gap-2.5 text-xs font-semibold text-[#0B0B0B] mb-4"><i
                    class="fas fa-folder-open"></i> Uploaded Files</div>
        <?php endif; ?>

        <!-- PO Stage: BOM-grouped view -->
        <?php if ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php if (empty($bomApprovedFiles)): ?>
                <div class="text-center py-11 px-5 bg-white border-2 border-dashed border-[#E2E2E2] rounded-[10px] mb-6">
                    <i class="fas fa-hourglass-half text-3xl text-[#E2E2E2] mb-2.5 block"></i>
                    <div class="text-sm font-semibold text-[#0B0B0B] mb-1">No Approved BOMs Yet</div>
                    <div class="text-xs text-[#9A9A9A]">BOMs will appear here once approved in the Bill of Materials
                        stage.</div>
                </div>
            <?php else: ?>

                <?php
                $osColors = [
                    'pending' => ['bg' => 'bg-amber-50', 'color' => 'text-amber-700', 'border' => 'border-amber-200', 'label' => 'Not Yet Ordered', 'icon' => 'fa-clock'],
                    'ordered' => ['bg' => 'bg-emerald-50', 'color' => 'text-emerald-700', 'border' => 'border-emerald-200', 'label' => 'Ordered', 'icon' => 'fa-check-circle'],
                    'partially_ordered' => ['bg' => 'bg-sky-50', 'color' => 'text-sky-700', 'border' => 'border-sky-200', 'label' => 'Partially Ordered', 'icon' => 'fa-adjust'],
                ];
                ?>

                <?php foreach ($bomApprovedFiles as $bom):
                    $bomExt = strtolower(pathinfo($bom['file_name'], PATHINFO_EXTENSION));
                    [$bomIcon, $bomColor] = fileIcon($bomExt);
                    $linkedPos = $posByBom[$bom['id']] ?? [];
                    $hasPos = !empty($linkedPos);
                    $osc = $osColors[$bom['order_status']] ?? $osColors['pending'];
                    ?>
                    <div class="mb-4">
                        <!-- BOM Card -->
                        <div
                            class="bg-emerald-50 border border-emerald-200 border-l-2 border-l-emerald-500 p-4 sm:p-5 <?= $hasPos ? 'rounded-t-[10px] border-b-0' : 'rounded-[10px]' ?>">
                            <div class="flex gap-3.5 items-start">
                                <i class="fas <?= $bomIcon ?> text-2xl flex-shrink-0 mt-0.5" style="color:<?= $bomColor ?>;"></i>
                                <div class="flex-1 min-w-0">
                                    <?php if ($bom['label']): ?>
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 mb-0.5">
                                            <?= htmlspecialchars($bom['label']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-sm font-semibold truncate"><?= htmlspecialchars($bom['file_name']) ?></div>
                                    <div class="text-[11px] text-[#9A9A9A] flex flex-wrap gap-2.5 mt-1">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($bom['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($bom['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($bom['file_size'] / 1024, 1) ?>
                                            KB</span>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase <?= fstatusClasses('approved') ?>"><i
                                            class="fas fa-check-circle"></i> Approved BOM</span>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold <?= $osc['bg'] ?> <?= $osc['color'] ?> border <?= $osc['border'] ?>">
                                        <i class="fas <?= $osc['icon'] ?>"></i> <?= $osc['label'] ?>
                                    </span>
                                    <a href="<?= BASE_URL . htmlspecialchars($bom['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $bom['file_path']) ? filemtime(ROOT_PATH . $bom['file_path']) : time() ?>"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors">
                                        <i class="fas fa-eye"></i> View BOM
                                    </a>
                                    <span
                                        class="text-[11px] font-bold flex items-center gap-1 <?= $hasPos ? 'text-emerald-700' : 'text-[#9A9A9A]' ?>">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <?= count($linkedPos) ?> PO<?= count($linkedPos) !== 1 ? 's' : '' ?> submitted
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- POs nested under this BOM -->
                        <?php if ($hasPos): ?>
                            <div class="bg-emerald-50/60 border border-emerald-200 border-t-0 rounded-b-[10px] px-4 pt-2.5 pb-3.5">
                                <div
                                    class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 mb-2.5 flex items-center gap-1.5">
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
                                    <div class="bg-white border border-emerald-200 rounded-lg px-4 py-3 mb-2">

                                        <!-- My review banner for this PO -->
                                        <?php if ($canApprove && $myPoReview): ?>
                                            <div
                                                class="rounded-md px-3 py-2 text-xs font-semibold flex items-center gap-1.5 mb-2.5 <?= $myPoStatus === 'rejected' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700' ?>">
                                                <i class="fas <?= $myPoStatus === 'approved' ? 'fa-check-double' : 'fa-times-circle' ?>"></i>
                                                You <?= $myPoStatus === 'approved' ? 'approved' : 'rejected' ?> this PO.
                                                <?php if ($myPoStatus === 'rejected' && $myPoReview['review_note']): ?>
                                                    Your note: "<?= htmlspecialchars($myPoReview['review_note']) ?>"
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($canApprove && $poGmOmAlreadyHandled): ?>
                                            <div
                                                class="rounded-md px-3 py-2 text-xs font-semibold flex items-center gap-1.5 mb-2.5 bg-emerald-50 border border-emerald-200 text-emerald-700">
                                                <i class="fas fa-check-double"></i>
                                                Already approved by <?= getRoleDisplayName($otherGmOm2) ?>. No further action needed.
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex gap-3 items-start">
                                            <i class="fas <?= $poIcon ?> text-xl flex-shrink-0 mt-0.5" style="color:<?= $poColor ?>;"></i>
                                            <div class="flex-1 min-w-0">
                                                <?php if ($po['label']): ?>
                                                    <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 mb-0.5">
                                                        <?= htmlspecialchars($po['label']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($po['file_name']) ?>
                                                </div>
                                                <div class="text-[11px] text-[#9A9A9A] flex gap-2.5 flex-wrap mt-0.5">
                                                    <span><i class="fas fa-user"></i>
                                                        <?= htmlspecialchars($po['uploaded_by_name']) ?></span>
                                                    <span><i class="fas fa-calendar"></i>
                                                        <?= date('M d, Y · g:i A', strtotime($po['uploaded_at'])) ?></span>
                                                    <span><i class="fas fa-weight"></i> <?= number_format($po['file_size'] / 1024, 1) ?>
                                                        KB</span>
                                                </div>

                                                <!-- Approval badges for PO -->
                                                <div class="flex flex-wrap gap-1.5 mt-2">
                                                    <?php foreach ($reqPoRoles as $role):
                                                        if (in_array($role, ['general_manager', 'operational_manager']))
                                                            continue;
                                                        $rev = $po['role_reviews'][$role] ?? null;
                                                        $bc = $rev ? $rev['review_status'] : 'pending';
                                                        $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                                        $isMine = ($role === $admin_role);
                                                        ?>
                                                        <span
                                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= apbadgeClasses($bc) ?> <?= $isMine ? 'ring-2 ring-[#c49a78]' : '' ?>">
                                                            <i class="fas <?= $bi ?>"></i> <?= getRoleDisplayName($role) ?>
                                                            <?php if ($isMine): ?><em
                                                                    class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                            <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                                <span class="text-[9px] font-medium opacity-80 ml-0.5">&middot;
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
                                                    $gmOmActedRev3 = null;
                                                    if ($gmStatus3 === 'approved' || $gmStatus3 === 'rejected')
                                                        $gmOmActedRev3 = $gmRev3;
                                                    elseif ($omStatus3 === 'approved' || $omStatus3 === 'rejected')
                                                        $gmOmActedRev3 = $omRev3;
                                                    ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= apbadgeClasses($cs3) ?> <?= $isMineGmOm3 ? 'ring-2 ring-[#c49a78]' : '' ?>">
                                                        <i class="fas <?= $ci3 ?>"></i> <?= $cl3 ?>
                                                        <?php if ($isMineGmOm3 && ($gmRev3 || $omRev3)): ?><em
                                                                class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                        <?php if ($gmOmActedRev3 && !empty($gmOmActedRev3['reviewed_at'])): ?>
                                                            <span class="text-[9px] font-medium opacity-80 ml-0.5">&middot;
                                                                <?= date('M d, Y g:i A', strtotime($gmOmActedRev3['reviewed_at'])) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>

                                                <!-- Rejection notes for this PO -->
                                                <?php foreach ($po['role_reviews'] as $rKey => $rev):
                                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                                        <div class="bg-red-50 border border-red-200 rounded-md px-3 py-2 text-xs text-red-700 mt-1.5">
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
                                                        class="bg-amber-50 border border-amber-200 rounded-md px-3 py-2 text-xs text-amber-800 mt-1.5 flex items-center gap-1.5">
                                                        <i class="fas fa-hourglass-half text-amber-500 flex-shrink-0"></i>
                                                        <span>Waiting for <strong>Accounting</strong> to approve first.</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                                <span
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase <?= fstatusClasses($poStatus) ?>">
                                                    <?php if ($poStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                                    <?php elseif ($poStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                                    <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
                                                    <?= ucfirst($poStatus) ?>
                                                </span>
                                                <a href="<?= BASE_URL . htmlspecialchars($po['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $po['file_path']) ? filemtime(ROOT_PATH . $po['file_path']) : time() ?>"
                                                    target="_blank"
                                                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <?php if ($canApprove && !$myPoReview && $poGmOmCanActNow && !$poGmOmAlreadyHandled && $poStatus === 'pending'): ?>
                                                    <button
                                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-[13px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors"
                                                        onclick="approveFile(<?= $po['id'] ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button
                                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-[13px] font-semibold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors"
                                                        onclick="showRejectForm('po-<?= $po['id'] ?>')">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Inline reject form for this PO -->
                                        <div class="hidden mt-3 bg-red-50 border border-red-200 rounded-lg p-3.5" id="reject-form-po-<?= $po['id'] ?>">
                                            <div class="text-[13px] font-bold text-red-700 mb-2"><i
                                                    class="fas fa-times-circle"></i> Rejection Note</div>
                                            <div class="hidden text-xs text-red-600 mb-2" id="reject-err-po-<?= $po['id'] ?>">Please
                                                enter a rejection reason.</div>
                                            <textarea id="reject-note-po-<?= $po['id'] ?>"
                                                class="w-full px-3 py-2 border border-red-300 rounded-md text-sm resize-y min-h-[80px] mb-2.5 focus:outline-none focus:border-red-500"
                                                placeholder="Explain why this PO is being rejected..."></textarea>
                                            <div class="flex gap-2 justify-end">
                                                <button
                                                    class="bg-[#F5F5F5] text-[#6B6B6B] px-3.5 py-1.5 rounded-md cursor-pointer font-semibold text-xs hover:bg-[#E2E2E2]"
                                                    onclick="cancelReject('po-<?= $po['id'] ?>')">Cancel</button>
                                                <button
                                                    class="bg-red-500 text-white px-3.5 py-1.5 rounded-md cursor-pointer font-bold text-xs inline-flex items-center gap-1.5 hover:bg-red-600"
                                                    onclick="submitReject(<?= $po['id'] ?>)"><i class="fas fa-times"></i> Confirm
                                                    Rejection</button>
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
            <div class="flex flex-wrap gap-2 mb-4">
                <button
                    class="cat-btn active inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-[#0B0B0B] text-white border border-[#0B0B0B]"
                    onclick="filterCategory('all',this)"><i class="fas fa-th-large"></i> All</button>
                <?php foreach ($categories as $cat): ?>
                    <button
                        class="cat-btn inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white text-[#6B6B6B] border border-[#E2E2E2] hover:border-[#0B0B0B]"
                        onclick="filterCategory('<?= htmlspecialchars(addslashes($cat)) ?>',this)"><?= htmlspecialchars($cat) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Files — hidden for Accounting (receipts shown per-PO above) -->
        <?php if ($isAccounting || $stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php /* displayed in grouped sections above */ ?>
        <?php elseif (empty($files)): ?>
            <div class="text-center py-11 px-5 bg-white border-2 border-dashed border-[#E2E2E2] rounded-[10px]">
                <i class="fas fa-file text-3xl text-[#E2E2E2] mb-2.5 block"></i>
                <div class="text-sm font-semibold text-[#0B0B0B] mb-1">No files submitted yet</div>
                <div class="text-xs text-[#9A9A9A]">Files submitted for this stage will appear here.</div>
            </div>
        <?php else:
            foreach ($files as $f):
                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                [$fi, $fc] = fileIcon($ext);
                $fStatus = $f['approval_status'] ?? 'pending';
                $myReview = $f['role_reviews'][$admin_role] ?? null;
                $myStatus = $myReview ? $myReview['review_status'] : null;

                $gmOmAlreadyHandled = false;
                if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                    $otherGmOm = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
                    $otherGmOmRev = $f['role_reviews'][$otherGmOm] ?? null;
                    if ($otherGmOmRev && $otherGmOmRev['review_status'] === 'approved') {
                        $gmOmAlreadyHandled = true;
                    }
                }
                ?>
                <div class="bg-white border border-[#E2E2E2] rounded-[10px] p-4 sm:p-5 mb-3 <?= fcardBorder($fStatus) ?>"
                    data-category="<?= htmlspecialchars($f['label'] ?? '') ?>">

                    <!-- My review status banner -->
                    <?php if ($canApprove && $myReview): ?>
                        <div
                            class="rounded-md px-3 py-2 text-xs font-semibold flex items-center gap-1.5 mb-3 <?= $myStatus === 'rejected' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700' ?>">
                            <i class="fas <?= $myStatus === 'approved' ? 'fa-check-double' : 'fa-times-circle' ?>"></i>
                            You <?= $myStatus === 'approved' ? 'approved' : 'rejected' ?> this file.
                            <?php if ($myStatus === 'rejected' && $myReview['review_note']): ?>
                                Your note: "<?= htmlspecialchars($myReview['review_note']) ?>"
                            <?php endif; ?>
                        </div>
                    <?php elseif ($canApprove && $gmOmAlreadyHandled): ?>
                        <div
                            class="rounded-md px-3 py-2 text-xs font-semibold flex items-center gap-1.5 mb-3 bg-emerald-50 border border-emerald-200 text-emerald-700">
                            <i class="fas fa-check-double"></i>
                            This file was already approved by <?= getRoleDisplayName($otherGmOm) ?>. No further action needed.
                        </div>
                    <?php endif; ?>

                    <div class="flex gap-3.5 items-start">
                        <i class="fas <?= $fi ?> text-2xl flex-shrink-0 mt-0.5" style="color:<?= $fc ?>;"></i>
                        <div class="flex-1 min-w-0">
                            <?php if ($f['label']): ?>
                                <div class="text-[10px] font-bold uppercase tracking-wide text-[#6B6B6B] mb-0.5">
                                    <?= htmlspecialchars($f['label']) ?>
                                </div><?php endif; ?>
                            <div class="text-sm font-semibold truncate"><?= htmlspecialchars($f['file_name']) ?></div>
                            <div class="text-[11px] text-[#9A9A9A] flex flex-wrap gap-2.5 mb-2 mt-1">
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
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    <?php if (in_array($stage, $gmOmStages2)):
                                        foreach ($reqRoles as $role):
                                            if (in_array($role, ['general_manager', 'operational_manager']))
                                                continue;
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bc = $rev ? $rev['review_status'] : 'pending';
                                            $bi = $bc === 'approved' ? 'fa-check-circle' : ($bc === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= apbadgeClasses($bc) ?> <?= $isMine ? 'ring-2 ring-[#c49a78]' : '' ?>">
                                                <i class="fas <?= $bi ?>"></i>
                                                <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="text-[9px] font-medium opacity-80 ml-0.5">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach;
                                        $gmRev = $f['role_reviews']['general_manager'] ?? null;
                                        $omRev = $f['role_reviews']['operational_manager'] ?? null;
                                        $gmStatus = $gmRev ? $gmRev['review_status'] : null;
                                        $omStatus = $omRev ? $omRev['review_status'] : null;
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
                                        $gmOmActedRev = null;
                                        if ($gmStatus === 'approved' || $gmStatus === 'rejected')
                                            $gmOmActedRev = $gmRev;
                                        elseif ($omStatus === 'approved' || $omStatus === 'rejected')
                                            $gmOmActedRev = $omRev;
                                        ?>
                                        <span
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= apbadgeClasses($combinedStatus) ?> <?= $isMineGmOm ? 'ring-2 ring-[#c49a78]' : '' ?>">
                                            <i class="fas <?= $combinedIcon ?>"></i>
                                            <?= $combinedLabel ?>
                                            <?php if ($isMineGmOm && ($gmRev || $omRev)): ?><em
                                                    class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                            <?php if ($gmOmActedRev && !empty($gmOmActedRev['reviewed_at'])): ?>
                                                <span class="text-[9px] font-medium opacity-80 ml-0.5">&middot;
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
                                            <span
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= apbadgeClasses($bc) ?> <?= $isMine ? 'ring-2 ring-[#c49a78]' : '' ?>">
                                                <i class="fas <?= $bi ?>"></i>
                                                <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                    <span class="text-[9px] font-medium opacity-80 ml-0.5">&middot;
                                                        <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; endif; ?>
                                </div>
                                <!-- Rejection notes from all roles -->
                                <?php foreach ($f['role_reviews'] as $rKey => $rev):
                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                        <div class="bg-red-50 border border-red-200 rounded-md px-3 py-2 text-xs text-red-700 mb-1.5">
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
                                            class="bg-amber-50 border border-amber-200 rounded-md px-3 py-2 text-xs text-amber-800 mb-1.5 flex items-center gap-1.5">
                                            <i class="fas fa-hourglass-half text-amber-500 flex-shrink-0"></i>
                                            <span>Waiting for <strong><?= implode(' and ', $missingStep1) ?></strong> to approve first
                                                before you can review this file.</span>
                                        </div>
                                    <?php endif; endif; ?>

                            <?php endif; ?>

                            <!-- Approve / Reject actions (only if canApprove AND not yet reviewed by me) -->
                            <?php
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
                                <div class="flex gap-1.5 flex-wrap items-center mt-2.5">
                                    <a href="<?= BASE_URL . htmlspecialchars($f['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $f['file_path']) ? filemtime(ROOT_PATH . $f['file_path']) : time() ?>"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors"><i
                                            class="fas fa-eye"></i> View File</a>
                                    <button
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-[13px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors"
                                        onclick="approveFile(<?= $f['id'] ?>)"><i class="fas fa-check-circle"></i>
                                        Approve</button>
                                    <button
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-[13px] font-semibold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors"
                                        onclick="showRejectForm(<?= $f['id'] ?>)"><i class="fas fa-times-circle"></i>
                                        Reject</button>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase ml-auto <?= fstatusClasses($fStatus) ?>">
                                        <?php if ($fStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                        <?php elseif ($fStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                        <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
                                        <?= ucfirst($fStatus) ?>
                                    </span>
                                </div>
                                <!-- Reject inline form -->
                                <div class="hidden mt-3 bg-red-50 border border-red-200 rounded-lg p-3.5" id="reject-form-<?= $f['id'] ?>">
                                    <div class="text-[13px] font-bold text-red-700 mb-2"><i
                                            class="fas fa-times-circle"></i> Rejection Note</div>
                                    <div class="hidden text-xs text-red-600 mb-2" id="reject-err-<?= $f['id'] ?>">Please enter
                                        a rejection reason.</div>
                                    <textarea id="reject-note-<?= $f['id'] ?>"
                                        class="w-full px-3 py-2 border border-red-300 rounded-md text-sm resize-y min-h-[80px] mb-2.5 focus:outline-none focus:border-red-500"
                                        placeholder="Explain why this file is being rejected. The submitter will be notified."></textarea>
                                    <div class="flex gap-2 justify-end">
                                        <button
                                            class="bg-[#F5F5F5] text-[#6B6B6B] px-3.5 py-1.5 rounded-md cursor-pointer font-semibold text-xs hover:bg-[#E2E2E2]"
                                            onclick="cancelReject(<?= $f['id'] ?>)">Cancel</button>
                                        <button
                                            class="bg-red-500 text-white px-3.5 py-1.5 rounded-md cursor-pointer font-bold text-xs inline-flex items-center gap-1.5 hover:bg-red-600"
                                            onclick="submitReject(<?= $f['id'] ?>)"><i class="fas fa-times"></i> Confirm
                                            Rejection</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="flex gap-1.5 flex-wrap items-center mt-2.5">
                                    <a href="<?= BASE_URL . htmlspecialchars($f['file_path']) ?>?v=<?= file_exists(ROOT_PATH . $f['file_path']) ? filemtime(ROOT_PATH . $f['file_path']) : time() ?>"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors"><i
                                            class="fas fa-eye"></i> View File</a>
                                    <?php if ($canApprove && ($myReview || $gmOmAlreadyHandled)): ?>
                                        <span class="text-[11px] text-emerald-600 font-semibold flex items-center gap-1">
                                            <i class="fas fa-check-double"></i>
                                            <?= $myReview ? 'You reviewed this' : 'Handled by ' . getRoleDisplayName($otherGmOm) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold uppercase ml-auto <?= fstatusClasses($fStatus) ?>">
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
        <div class="bg-white border border-[#E2E2E2] rounded-[10px] p-5 sm:p-6 mt-6 mb-5 max-w-4xl mx-auto">
            <div class="adm-section-label flex items-center gap-2 text-xs font-semibold text-[#0B0B0B] mb-4"><i
                    class="fas fa-stamp"></i> Stage Approval Status</div>

            <?php if (!$internalPoApproval): ?>
                <div class="bg-[#F5F5F5] border-2 border-dashed border-[#E2E2E2] rounded-[10px] px-5 py-5 flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-[10px] bg-white border border-[#E2E2E2] flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-paper-plane text-[#6B6B6B]"></i>
                    </div>
                    <div>
                        <div class="text-sm font-bold">No approval requested yet</div>
                        <div class="text-xs text-[#9A9A9A] mt-0.5">The assigned staff has not yet requested approval for
                            this stage.</div>
                    </div>
                </div>

            <?php else:
                $ipa = $internalPoApproval;
                $overallStatus = $ipa['overall_status'];
                $overallColors = [
                    'pending' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'color' => 'text-amber-700', 'icon' => 'fa-clock'],
                    'approved' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'color' => 'text-emerald-700', 'icon' => 'fa-check-circle'],
                    'rejected' => ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'color' => 'text-red-700', 'icon' => 'fa-times-circle'],
                ];
                $oc = $overallColors[$overallStatus];
                ?>

                <!-- Overall status banner -->
                <div
                    class="<?= $oc['bg'] ?> border <?= $oc['border'] ?> rounded-lg px-3.5 py-2.5 mb-3.5 flex items-center gap-2.5">
                    <i class="fas <?= $oc['icon'] ?> <?= $oc['color'] ?> flex-shrink-0"></i>
                    <div>
                        <div class="text-[13px] font-bold <?= $oc['color'] ?>">
                            <?php if ($overallStatus === 'pending'): ?>Approval in progress
                            <?php elseif ($overallStatus === 'approved'): ?>Fully approved
                            <?php else: ?>Rejected — staff needs to fix and re-request
                            <?php endif; ?>
                        </div>
                        <div class="text-[11px] <?= $oc['color'] ?> opacity-80 mt-0.5">
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
                    <div class="mb-4">
                        <div class="text-[11px] font-bold uppercase tracking-wide text-[#9A9A9A] mb-2.5 flex items-center gap-1.5">
                            <i class="fas fa-file-signature text-sky-600"></i> Notice to Proceed (NTP) Files
                        </div>
                        <?php foreach ($ntpFiles as $ntp):
                            $ntpExt = strtolower(pathinfo($ntp['file_name'], PATHINFO_EXTENSION));
                            $ntpViewable = in_array($ntpExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $ntpExt === 'pdf';
                            ?>
                            <div class="bg-sky-50 border border-sky-200 rounded-lg px-3.5 py-3 mb-2">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <div>
                                        <div class="text-xs font-bold text-sky-700 mb-0.5">
                                            <i class="fas fa-file-signature"></i>
                                            NTP — <?= htmlspecialchars($ntp['payment_type'] ?? 'Payment') ?>
                                        </div>
                                        <div class="text-[11px] text-[#6B6B6B]">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($ntp['uploader_name']) ?>
                                            &bull; <?= date('M d, Y g:i A', strtotime($ntp['uploaded_at'])) ?>
                                        </div>
                                        <?php if (!empty($ntp['notes'])): ?>
                                            <div class="text-[11px] text-[#0B0B0B] bg-sky-100 rounded-md px-2 py-1 mt-1.5">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($ntp['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <?php if ($ntpViewable): ?>
                                            <a href="<?= BASE_URL . htmlspecialchars($ntp['file_path']) ?>" target="_blank"
                                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-white text-[#0B0B0B] border border-[#E2E2E2] hover:border-[#0B0B0B] transition-colors">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL . htmlspecialchars($ntp['file_path']) ?>"
                                            download="<?= htmlspecialchars($ntp['file_name']) ?>"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Reviewer steps (read-only) -->
                <div class="flex flex-col gap-2.5">

                    <!-- Step 1: Accounting -->
                    <?php
                    $acStatus = $ipa['accounting_status'];
                    $acColors = [
                        'pending' => ['bg' => 'bg-[#F5F5F5]', 'color' => 'text-[#9A9A9A]', 'border' => 'border-[#E2E2E2]', 'dot' => 'bg-[#9A9A9A]', 'icon' => 'fa-clock'],
                        'approved' => ['bg' => 'bg-emerald-50', 'color' => 'text-emerald-700', 'border' => 'border-emerald-200', 'dot' => 'bg-emerald-500', 'icon' => 'fa-check-circle'],
                        'rejected' => ['bg' => 'bg-red-50', 'color' => 'text-red-700', 'border' => 'border-red-200', 'dot' => 'bg-red-500', 'icon' => 'fa-times-circle'],
                    ];
                    $acc = $acColors[$acStatus];
                    ?>
                    <div class="<?= $acc['bg'] ?> border <?= $acc['border'] ?> rounded-lg px-3.5 py-3">
                        <div class="flex items-center gap-2">
                            <span
                                class="<?= $acc['dot'] ?> text-white rounded-full w-[22px] h-[22px] flex items-center justify-center text-[11px] font-bold flex-shrink-0">1</span>
                            <div>
                                <div class="text-xs font-bold <?= $acc['color'] ?> flex items-center gap-1.5">
                                    <i class="fas <?= $acc['icon'] ?>"></i> Accounting
                                    <?php if ($acStatus === 'pending'): ?>
                                        <span
                                            class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-full text-[10px]">Waiting</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($ipa['accounting_reviewed_at']): ?>
                                    <div class="text-[11px] text-[#9A9A9A] mt-0.5">
                                        <?= htmlspecialchars($ipa['accounting_reviewer_name']) ?> ·
                                        <?= date('M d, Y g:i A', strtotime($ipa['accounting_reviewed_at'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ipa['accounting_remark']): ?>
                                    <div class="text-xs text-red-700 mt-1.5 bg-red-50 rounded-md px-2.5 py-1.5 italic">
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
                        'pending' => ['bg' => 'bg-[#F5F5F5]', 'color' => 'text-[#9A9A9A]', 'border' => 'border-[#E2E2E2]', 'dot' => 'bg-[#9A9A9A]', 'icon' => 'fa-clock'],
                        'approved' => ['bg' => 'bg-emerald-50', 'color' => 'text-emerald-700', 'border' => 'border-emerald-200', 'dot' => 'bg-emerald-500', 'icon' => 'fa-check-circle'],
                        'rejected' => ['bg' => 'bg-red-50', 'color' => 'text-red-700', 'border' => 'border-red-200', 'dot' => 'bg-red-500', 'icon' => 'fa-times-circle'],
                    ];
                    $dc = $dColors[$dsStatus];
                    ?>
                    <div
                        class="<?= $dc['bg'] ?> border <?= $dc['border'] ?> rounded-lg px-3.5 py-3 <?= $dsLocked ? 'opacity-50' : '' ?>">
                        <div class="flex items-center gap-2">
                            <span
                                class="<?= $dc['dot'] ?> text-white rounded-full w-[22px] h-[22px] flex items-center justify-center text-[11px] font-bold flex-shrink-0">2</span>
                            <div>
                                <div class="text-xs font-bold <?= $dc['color'] ?> flex items-center gap-1.5">
                                    <i class="fas <?= $dc['icon'] ?>"></i> Head Designer
                                    <?php if ($dsLocked): ?>
                                        <span
                                            class="bg-[#E2E2E2] text-[#6B6B6B] border border-[#9A9A9A]/30 px-1.5 py-0.5 rounded-full text-[10px]"><i
                                                class="fas fa-lock"></i> Waiting for Accounting</span>
                                    <?php elseif ($dsStatus === 'pending'): ?>
                                        <span
                                            class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-full text-[10px]">Waiting</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($ipa['designer_reviewed_at']): ?>
                                    <div class="text-[11px] text-[#9A9A9A] mt-0.5">
                                        <?= htmlspecialchars($ipa['designer_reviewer_name']) ?> ·
                                        <?= date('M d, Y g:i A', strtotime($ipa['designer_reviewed_at'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ipa['designer_remark']): ?>
                                    <div class="text-xs text-red-700 mt-1.5 bg-red-50 rounded-md px-2.5 py-1.5 italic">
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

    <!-- Toast -->
    <div id="toast"
        class="fixed bottom-7 right-7 bg-[#0B0B0B] text-white px-5 py-3.5 rounded-[10px] text-[13px] font-semibold flex items-center gap-2.5 shadow-[0_8px_32px_rgba(11,11,11,.3)] translate-y-20 opacity-0 transition-all duration-300 ease-[cubic-bezier(.34,1.56,.64,1)] z-[9999] pointer-events-none">
        <i class="fas fa-check-circle"></i><span id="toastMsg"></span>
    </div>

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
            document.getElementById('reject-form-' + id).classList.remove('hidden');
            document.getElementById('reject-note-' + id).focus();
        }
        function cancelReject(id) {
            document.getElementById('reject-form-' + id).classList.add('hidden');
            document.getElementById('reject-note-' + id).value = '';
            document.getElementById('reject-err-' + id).classList.add('hidden');
        }
        async function submitReject(approvalId) {
            // Support both plain IDs and 'po-' prefixed IDs
            const noteEl = document.getElementById('reject-note-' + approvalId)
                || document.getElementById('reject-note-po-' + approvalId);
            const err = document.getElementById('reject-err-' + approvalId)
                || document.getElementById('reject-err-po-' + approvalId);
            const note = noteEl ? noteEl.value.trim() : '';
            if (!note) { err.classList.remove('hidden'); return; }
            err.classList.add('hidden');
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
            document.querySelectorAll('.cat-btn').forEach(b => {
                b.classList.remove('bg-[#0B0B0B]', 'text-white', 'border-[#0B0B0B]');
                b.classList.add('bg-white', 'text-[#6B6B6B]', 'border-[#E2E2E2]');
            });
            btn.classList.remove('bg-white', 'text-[#6B6B6B]', 'border-[#E2E2E2]');
            btn.classList.add('bg-[#0B0B0B]', 'text-white', 'border-[#0B0B0B]');
            document.querySelectorAll('.file-card, [data-category]').forEach(card => {
                if (!card.dataset || card.dataset.category === undefined) return;
                card.style.display = (cat === 'all' || card.dataset.category === cat) ? '' : 'none';
            });
        }

        function toast(msg, err = false) {
            const el = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            el.classList.remove('translate-y-20', 'opacity-0', 'bg-[#0B0B0B]', 'bg-red-600');
            el.classList.add(err ? 'bg-red-600' : 'bg-[#0B0B0B]');
            setTimeout(() => el.classList.add('translate-y-20', 'opacity-0'), 3000);
        }
    </script>
</body>

</html>