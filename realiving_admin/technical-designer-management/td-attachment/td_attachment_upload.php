<?php
// td_attachment_upload.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area = isset($_GET['area']) ? trim($_GET['area']) : '';

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['technical_designer', 'general_manager', 'operational_manager'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager'])
    || ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

$ciStmt = $conn->prepare("SELECT technical_designer_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$ciStmt->bind_param("i", $client_id);
$ciStmt->execute();
$clientInfo = $ciStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$isAssigned = ($clientInfo['technical_designer_id'] == $admin_id);
if (!$isAssigned && !$canViewAll)
    die("Access denied.");

$viewOnly = $canViewAll && !$isAssigned;

$locationLabel = $area;
$backUrl = BASE_URL . 'td-attachments?client_id=' . $client_id;

// ── Approval helpers ───────────────────────────────────────────────────────
function tdGetApprovers($conn)
{
    $s = $conn->prepare("SELECT id, full_name, role FROM account WHERE (role IN ('general_manager','operational_manager')) OR (role = 'technical_designer' AND is_head = 1) ORDER BY role");
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

function tdGetApprovalStatus($conn, $client_id, $area)
{
    $s = $conn->prepare("SELECT la.*, a.full_name as approver_name, a.role as approver_role FROM td_attachment_approvals la JOIN account a ON la.approver_id=a.id WHERE la.client_id=? AND la.area=? AND la.room_unit_number IS NULL");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

$approvers = tdGetApprovers($conn);
$approvalRecords = tdGetApprovalStatus($conn, $client_id, $area);
$approvalMap = [];
foreach ($approvalRecords as $rec)
    $approvalMap[$rec['approver_id']] = $rec;

$approvalRequested = !empty($approvalRecords);
$allApproved = $approvalRequested && count($approvers) > 0 &&
    count(array_filter($approvalRecords, fn($r) => $r['status'] === 'approved')) === count($approvers);
$anyRejected = !empty(array_filter($approvalRecords, fn($r) => $r['status'] === 'rejected'));

// Active revision
$revS = $conn->prepare("SELECT id, revision_number, reason, status, created_at FROM td_revision_log WHERE client_id=? AND area=? AND room_unit_number IS NULL AND status IN ('pending','designer_resubmitted') ORDER BY created_at DESC LIMIT 1");
$revS->bind_param("is", $client_id, $area);
$revS->execute();
$activeRevision = $revS->get_result()->fetch_assoc();
$hasActiveRevision = !empty($activeRevision);
$revisionStatus = $activeRevision['status'] ?? null;

$isApprover = false;
foreach ($approvers as $apr) {
    if ($apr['id'] == $admin_id) {
        $isApprover = true;
        break;
    }
}
$canRequestApproval = $isAssigned && $me['role'] === 'technical_designer';

// ── Fetch files ─────────────────────────────────────────────────────────
function tdGetFiles($conn, $client_id, $area)
{
    $s = $conn->prepare("SELECT ta.*, a.full_name as uploader_name FROM td_attachments ta LEFT JOIN account a ON ta.uploaded_by=a.id WHERE ta.client_id=? AND ta.area=? AND ta.room_unit_number IS NULL ORDER BY ta.category_name, ta.created_at DESC");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

function desGetFiles($conn, $client_id, $area)
{
    $s = $conn->prepare("SELECT la.*, a.full_name as uploader_name FROM layout_attachments la LEFT JOIN account a ON la.uploaded_by=a.id WHERE la.client_id=? AND la.area=? ORDER BY la.attachment_type, la.created_at ASC");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

$tdFiles = tdGetFiles($conn, $client_id, $area);
$designerFiles = desGetFiles($conn, $client_id, $area);
$fileCount = count($tdFiles);
$maxFiles = 20;
$canUpload = $fileCount < $maxFiles;

$filesByCategory = [];
foreach ($tdFiles as $f) {
    $filesByCategory[$f['category_name']][] = $f;
}

$desByType = [];
foreach ($designerFiles as $df) {
    $desByType[$df['attachment_type']][] = $df;
}

$hasAnyFile = $fileCount > 0;
$existingCategories = array_unique(array_column($tdFiles, 'category_name'));

$desTypeLabels = [
    'site_measurement' => ['label' => 'Site Measurement', 'icon' => 'fa-ruler-combined', 'color' => '#0369a1', 'bg' => '#dbeafe'],
    'floor_plan' => ['label' => 'Floor Plan', 'icon' => 'fa-vector-square', 'color' => '#5b21b6', 'bg' => '#ede9fe'],
    'rendering' => ['label' => 'Rendering', 'icon' => 'fa-cube', 'color' => '#065f46', 'bg' => '#d1fae5'],
];

// Approval panel state → Tailwind class groups
if ($allApproved) {
    $panelBorderClass = 'border-emerald-300';
    $panelBgClass = 'bg-emerald-50';
    $panelTextClass = 'text-emerald-900';
    $panelLabel = 'All Approved';
    $panelIcon = 'fa-check-circle';
} elseif ($anyRejected) {
    $panelBorderClass = 'border-red-300';
    $panelBgClass = 'bg-red-50';
    $panelTextClass = 'text-red-900';
    $panelLabel = 'Has Rejection(s)';
    $panelIcon = 'fa-times-circle';
} elseif ($approvalRequested) {
    $panelBorderClass = 'border-blue-300';
    $panelBgClass = 'bg-blue-50';
    $panelTextClass = 'text-blue-900';
    $panelLabel = 'Pending Review';
    $panelIcon = 'fa-hourglass-half';
} else {
    $panelBorderClass = 'border-line';
    $panelBgClass = 'bg-[#F5F5F5]';
    $panelTextClass = 'text-soft';
    $panelLabel = 'Not Requested';
    $panelIcon = 'fa-circle';
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$redirectBase = BASE_URL . 'td-attachment-upload?client_id=' . $client_id . '&area=' . urlencode($area);
$iconMap = ['pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'zip' => 'fa-file-archive', 'txt' => 'fa-file-alt', 'dwg' => 'fa-drafting-compass', 'dxf' => 'fa-drafting-compass'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Upload — <?= htmlspecialchars($locationLabel) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        ink: '#0B0B0B',
                        soft: '#6B6B6B',
                        muted: '#9A9A9A',
                        line: '#E2E2E2',
                    },
                },
            },
        };
    </script>
    <style>
        .tab-bar::-webkit-scrollbar { display: none; }

        .tab-pill { display: inline-flex; align-items: center; gap: 6px; padding: 7px 15px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid #E2E2E2; background: white; color: #6B6B6B; transition: all .15s; white-space: nowrap; user-select: none; }
        .tab-pill:hover { border-color: #0B0B0B; color: #0B0B0B; }
        .tab-pill.active { background: #0B0B0B; color: white; border-color: #0B0B0B; }
        .tab-pill .cnt { font-size: 10px; padding: 1px 6px; border-radius: 10px; background: #F5F5F5; color: #6B6B6B; }
        .tab-pill.active .cnt { background: rgba(255,255,255,0.25); color: white; }
        .tab-pill.upload-pill { border-color: #0369a1; color: #0369a1; }
        .tab-pill.upload-pill:hover, .tab-pill.upload-pill.active { background: #0369a1; color: white; border-color: #0369a1; }

        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #D1D5DB; border-radius: 999px; transition: .2s; }
        .toggle-slider:before { content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .toggle-switch input:checked + .toggle-slider { background: #0B0B0B; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(18px); }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>

<body class="font-sans bg-[#F5F5F5] text-ink">
    <div class="max-w-[880px] mx-auto px-5 py-8">

        <!-- Back button -->
        <div class="mb-5">
            <a href="<?= htmlspecialchars($backUrl) ?>"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-tools"></i> TD Attachment Upload
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($locationLabel) ?></h1>
            <p class="text-[13.5px] text-soft mt-1">
                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($area) ?>
            </p>
            <p class="text-[13px] text-muted mt-1">
                <?= htmlspecialchars($clientInfo['clientname']) ?> — <?= htmlspecialchars($clientInfo['nameproject']) ?>
            </p>
        </div>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 rounded-lg px-4 py-3 mb-4 text-[13px] font-medium flex items-center gap-2">
                <i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-300 text-red-800 rounded-lg px-4 py-3 mb-4 text-[13px] font-medium flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>


        <!-- ════════════════════════════════════════════
             DESIGNER REFERENCE FILES
        ════════════════════════════════════════════ -->
        <?php if (!empty($designerFiles)): ?>
            <div class="bg-white border border-line rounded-[10px] mb-5 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-line">
                    <div class="flex items-center gap-2 text-[13px] font-bold text-soft">
                        <i class="fas fa-images"></i> Designer Reference Files
                        <span class="text-[11px] font-normal text-muted">read only</span>
                    </div>
                    <span class="text-[11px] text-muted"><?= count($designerFiles) ?> file<?= count($designerFiles) !== 1 ? 's' : '' ?></span>
                </div>

                <!-- Tab bar -->
                <div class="tab-bar flex gap-2 flex-wrap px-6 pt-4 pb-4 border-b border-line overflow-x-auto" id="desTabBar">
                    <?php $i = 0;
                    foreach ($desByType as $typeKey => $dFiles):
                        $ti = $desTypeLabels[$typeKey] ?? ['label' => ucwords(str_replace('_', ' ', $typeKey)), 'icon' => 'fa-file', 'color' => '#6b7280', 'bg' => '#f3f4f6'];
                        ?>
                        <div class="tab-pill <?= $i === 0 ? 'active' : '' ?>"
                            data-target="des-<?= htmlspecialchars($typeKey, ENT_QUOTES) ?>"
                            onclick="switchTab('desTabBar',this)">
                            <i class="fas <?= $ti['icon'] ?>"></i>
                            <?= $ti['label'] ?>
                            <span class="cnt"><?= count($dFiles) ?></span>
                        </div>
                        <?php $i++; endforeach; ?>
                </div>

                <!-- Panes -->
                <div class="p-6">
                    <?php $i = 0;
                    foreach ($desByType as $typeKey => $dFiles):
                        $ti = $desTypeLabels[$typeKey] ?? ['label' => ucwords(str_replace('_', ' ', $typeKey)), 'icon' => 'fa-file', 'color' => '#6b7280', 'bg' => '#f3f4f6'];
                        ?>
                        <div class="tab-pane <?= $i === 0 ? 'active' : '' ?> flex flex-col gap-2.5"
                            id="des-<?= htmlspecialchars($typeKey, ENT_QUOTES) ?>">
                            <?php foreach ($dFiles as $df):
                                $isImg = strpos($df['file_type'], 'image/') === 0;
                                $fp = BASE_URL . 'uploads/layout_attachments/' . $df['file_path'];
                                $ext = strtolower(pathinfo($df['file_name'], PATHINFO_EXTENSION));
                                $fi = $iconMap[$ext] ?? 'fa-file';
                                ?>
                                <div class="flex items-center gap-3 p-3.5 border border-line rounded-lg bg-[#F5F5F5]">
                                    <?php if ($isImg): ?>
                                        <img src="<?= htmlspecialchars($fp) ?>" class="w-11 h-11 object-cover rounded-md border border-line flex-shrink-0" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="w-11 h-11 rounded-md flex items-center justify-center flex-shrink-0" style="background:<?= $ti['bg'] ?>;">
                                            <i class="fas <?= $fi ?> text-lg" style="color:<?= $ti['color'] ?>;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($df['file_name']) ?></div>
                                        <div class="text-[11px] text-muted mt-0.5">
                                            <?= round($df['file_size'] / 1024, 1) ?> KB &nbsp;•&nbsp;
                                            <?= htmlspecialchars($df['uploader_name'] ?? '') ?> &nbsp;•&nbsp;
                                            <?= date('M d, Y', strtotime($df['created_at'])) ?>
                                        </div>
                                        <?php if (!empty($df['note'])): ?>
                                            <span class="inline-block text-[11px] text-amber-800 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md mt-1.5">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($df['note']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                    $isViewable = $isImg || in_array($ext, $imageExts) || $ext === 'pdf';
                                    ?>
                                    <?php if ($isViewable): ?>
                                        <a href="<?= htmlspecialchars($fp) ?>" target="_blank"
                                            class="flex-shrink-0 bg-white border border-line rounded-lg px-3 py-1.5 text-[11px] font-bold hover:border-ink transition">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($df['file_name']) ?>"
                                            class="flex-shrink-0 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-3 py-1.5 text-[11px] font-bold hover:bg-emerald-100 transition">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($df['file_name']) ?>"
                                            class="flex-shrink-0 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-3 py-1.5 text-[11px] font-bold hover:bg-emerald-100 transition">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php $i++; endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             REMARK ON DESIGNER FILES
        ════════════════════════════════════════════ -->
        <?php
        // Check if approval has been requested by the designer for this area/unit
        $desApprovalCheckStmt = $conn->prepare("
            SELECT td_remark, td_remark_submitted_at, td_remark_file, td_remark_file_name
            FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
            LIMIT 1
        ");
        $desApprovalCheckStmt->bind_param("is", $client_id, $area);
        $desApprovalCheckStmt->execute();
        $desApprovalRow = $desApprovalCheckStmt->get_result()->fetch_assoc();

        $desApprovalExists = !empty($desApprovalRow);
        $existingTDRemark = $desApprovalRow['td_remark'] ?? '';
        $tdRemarkAlreadySet = !empty($existingTDRemark);
        $existingRemarkFile = $desApprovalRow['td_remark_file'] ?? '';
        $existingRemarkFileName = $desApprovalRow['td_remark_file_name'] ?? '';
        ?>

        <?php if ($desApprovalExists && $isAssigned): ?>
            <div class="bg-white border border-amber-300 rounded-[10px] mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-amber-200">
                    <div class="flex items-center gap-2 text-[13px] font-bold text-amber-700">
                        <i class="fas fa-comment-medical"></i>
                        Your Remark on Designer Files
                        <?php if ($tdRemarkAlreadySet): ?>
                            <span class="bg-emerald-100 text-emerald-800 text-[10px] px-2 py-0.5 rounded-full font-bold">
                                <i class="fas fa-check"></i> Submitted
                            </span>
                        <?php else: ?>
                            <span class="bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 rounded-full font-bold">
                                <i class="fas fa-exclamation-triangle"></i> Required
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-6">
                    <?php if ($tdRemarkAlreadySet): ?>
                        <!-- Already submitted — show it with edit option -->
                        <div class="bg-sky-50 border border-sky-200 rounded-lg p-3.5 mb-3.5">
                            <div class="text-[11px] font-bold text-sky-900 mb-1.5 uppercase tracking-[0.4px]">
                                <i class="fas fa-comment-dots"></i> Your submitted remark
                            </div>
                            <div class="text-[13px] text-ink italic">
                                "<?= htmlspecialchars($existingTDRemark) ?>"
                            </div>
                            <div class="text-[11px] text-muted mt-1.5">
                                <i class="fas fa-clock"></i>
                                Submitted on <?= $desApprovalRow['td_remark_submitted_at']
                                    ? date('M d, Y g:i A', strtotime($desApprovalRow['td_remark_submitted_at']))
                                    : '' ?>
                            </div>
                            <?php if ($existingRemarkFile): ?>
                            <div class="mt-2.5 flex items-center gap-2.5 bg-white border border-sky-200 rounded-lg px-3.5 py-2.5">
                                <i class="fas fa-file-pdf text-red-600 text-xl flex-shrink-0"></i>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[12px] font-bold truncate"><?= htmlspecialchars($existingRemarkFileName ?: 'Remark Attachment') ?></div>
                                    <div class="text-[11px] text-muted mt-0.5">PDF Attachment</div>
                                </div>
                                <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>" target="_blank"
                                    class="bg-blue-100 text-blue-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>"
                                    download="<?= htmlspecialchars($existingRemarkFileName ?: 'remark.pdf') ?>"
                                    class="bg-emerald-100 text-emerald-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <button type="button" id="tdEditRemarkBtn"
                            onclick="document.getElementById('tdDesRemarkForm').style.display='block'; this.style.display='none';"
                            class="inline-flex items-center gap-2 bg-white border border-sky-300 text-sky-700 rounded-lg px-3.5 py-2 text-[12px] font-semibold mb-3 hover:bg-sky-50 transition">
                            <i class="fas fa-edit"></i> Edit Remark
                        </button>

                        <div id="tdDesRemarkForm" style="display:none;">
                    <?php else: ?>
                        <!-- Not yet submitted -->
                        <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-3.5 py-2.5 text-[12px] mb-3.5 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            The designer has requested approval for this area. Please review the designer files above and
                            leave your technical remark. Approvers cannot proceed until your remark is submitted.
                        </div>
                        <div id="tdDesRemarkForm">
                        <?php endif; ?>

                        <form method="POST" action="<?= BASE_URL ?>designer-submit-td-remark" enctype="multipart/form-data">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="redirect_url"
                                value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                                <i class="fas fa-comment-alt"></i> Your Technical Remark
                            </label>
                            <textarea name="td_remark" rows="4" required
                                class="w-full border border-amber-300 rounded-lg px-3.5 py-2.5 text-[13px] font-sans resize-y mb-2.5 focus:outline-none focus:border-amber-500"
                                placeholder="Review the designer's uploaded files above and leave your technical assessment, notes, or concerns…"><?= htmlspecialchars($existingTDRemark) ?></textarea>
                            <!-- PDF Attachment -->
                            <div class="mb-3.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                                    <i class="fas fa-file-pdf text-red-600"></i>
                                    PDF Attachment <span class="text-muted font-normal normal-case">(optional, max 100MB)</span>
                                </label>
                                <div id="tdRemarkFileZone" onclick="document.getElementById('tdRemarkFileInput').click()"
                                    class="border-2 border-dashed border-amber-300 rounded-lg py-4.5 px-4 text-center cursor-pointer bg-amber-50 transition hover:border-amber-500 hover:bg-amber-100">
                                    <i class="fas fa-file-pdf text-2xl text-red-600 mb-1.5 block"></i>
                                    <div id="tdRemarkFileLabel" class="text-[13px] font-semibold text-amber-800">
                                        <?= $existingRemarkFile ? 'Replace PDF (click to choose)' : 'Click to attach a PDF' ?>
                                    </div>
                                    <div class="text-[11px] text-amber-700 mt-0.5">PDF only · max 100MB</div>
                                </div>
                                <input type="file" id="tdRemarkFileInput" name="td_remark_file"
                                    accept=".pdf,application/pdf" style="display:none;"
                                    onchange="onTdRemarkFileChange(this)">
                                <div id="tdRemarkFilePreview"
                                    class="hidden mt-2.5 bg-emerald-50 border border-emerald-300 rounded-lg px-3.5 py-2.5 items-center gap-2.5">
                                    <i class="fas fa-file-pdf text-red-600 text-xl flex-shrink-0"></i>
                                    <div class="flex-1 min-w-0">
                                        <div id="tdRemarkFileName" class="text-[12px] font-bold text-emerald-800 truncate"></div>
                                        <div id="tdRemarkFileSize" class="text-[11px] text-emerald-600 mt-0.5"></div>
                                    </div>
                                    <button type="button" onclick="clearTdRemarkFile()"
                                        class="bg-transparent border-none text-red-500 cursor-pointer text-[13px] p-1">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" id="tdRemarkSubmitBtn"
                                class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-5.5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                                <i class="fas fa-paper-plane"></i>
                                <?= $tdRemarkAlreadySet ? 'Update Remark' : 'Submit Remark' ?>
                            </button>
                        </form>
                        </div><!-- end tdDesRemarkForm -->
                </div><!-- end padding -->
            </div><!-- end panel -->
        <?php elseif ($desApprovalExists && !$isAssigned && $canViewAll): ?>
            <!-- Managers can see the remark status but cannot edit -->
            <?php if ($tdRemarkAlreadySet): ?>
                <div class="bg-white border border-line rounded-[10px] mb-5 overflow-hidden">
                    <div class="px-6 py-4 border-b border-line">
                        <div class="flex items-center gap-2 text-[13px] font-bold text-blue-700">
                            <i class="fas fa-comment-dots"></i> Technical Designer Remark
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="text-[13px] text-ink italic mb-2.5">
                            "<?= htmlspecialchars($existingTDRemark) ?>"
                        </div>
                        <?php if ($existingRemarkFile): ?>
                        <div class="flex items-center gap-2.5 bg-orange-50 border border-orange-200 rounded-lg px-3.5 py-2.5">
                            <i class="fas fa-file-pdf text-red-600 text-xl flex-shrink-0"></i>
                            <div class="flex-1 min-w-0">
                                <div class="text-[12px] font-bold truncate"><?= htmlspecialchars($existingRemarkFileName ?: 'TD Remark Attachment') ?></div>
                                <div class="text-[11px] text-amber-700 mt-0.5">PDF from Technical Designer</div>
                            </div>
                            <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>" target="_blank"
                               class="bg-blue-100 text-blue-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>"
                               download="<?= htmlspecialchars($existingRemarkFileName ?: 'td_remark.pdf') ?>"
                               class="bg-emerald-100 text-emerald-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>


        <!-- ════════════════════════════════════════════
             YOUR TECHNICAL DOCUMENTS
        ════════════════════════════════════════════ -->
        <div class="bg-white border border-line rounded-[10px] mb-5 overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-line">
                <div class="flex items-center gap-2 text-[13px] font-bold text-soft">
                    <i class="fas fa-folder-open text-blue-600"></i> Your Technical Documents
                </div>
                <span class="text-[12px] text-muted font-semibold"><?= $fileCount ?> / <?= $maxFiles ?></span>
            </div>

            <?php if (!empty($filesByCategory)): ?>
                <!-- Tab bar -->
                <div class="tab-bar flex gap-2 flex-wrap px-6 pt-4 pb-4 border-b border-line overflow-x-auto" id="tdTabBar">
                    <?php $i = 0;
                    foreach ($filesByCategory as $catName => $catFiles):
                        $slug = 'cat-' . md5($catName); ?>
                        <div class="tab-pill <?= $i === 0 ? 'active' : '' ?>" data-target="td-<?= $slug ?>"
                            onclick="switchTab('tdTabBar',this)">
                            <i class="fas fa-folder"></i>
                            <?= htmlspecialchars($catName) ?>
                            <span class="cnt"><?= count($catFiles) ?></span>
                        </div>
                        <?php $i++; endforeach; ?>
                    <?php if (!$viewOnly && $canUpload): ?>
                        <div class="tab-pill upload-pill" data-target="td-upload" onclick="switchTab('tdTabBar',this)">
                            <i class="fas fa-plus"></i> Upload New
                        </div>
                    <?php endif; ?>
                </div>

                <!-- File panes -->
                <div class="p-6">
                    <?php $i = 0;
                    foreach ($filesByCategory as $catName => $catFiles):
                        $slug = 'cat-' . md5($catName); ?>
                        <div class="tab-pane <?= $i === 0 ? 'active' : '' ?> flex flex-col gap-2.5" id="td-<?= $slug ?>">
                            <?php foreach ($catFiles as $f):
                                $isImg = strpos($f['file_type'], 'image/') === 0;
                                $fp = BASE_URL . $f['file_path'];
                                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                                $fi = $iconMap[$ext] ?? 'fa-file';
                                ?>
                                <div class="flex items-center gap-3 p-3.5 border border-line rounded-lg bg-[#F5F5F5]">
                                    <?php if ($isImg): ?>
                                        <img src="<?= htmlspecialchars($fp) ?>" class="w-11 h-11 object-cover rounded-md border border-line flex-shrink-0" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="w-11 h-11 rounded-md flex items-center justify-center flex-shrink-0 bg-blue-50">
                                            <i class="fas <?= $fi ?> text-lg text-blue-600"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($f['file_name']) ?></div>
                                        <div class="text-[11px] text-muted mt-0.5">
                                            <?= round($f['file_size'] / 1024, 1) ?> KB &nbsp;•&nbsp;
                                            <?= htmlspecialchars($f['uploader_name'] ?? '') ?> &nbsp;•&nbsp;
                                            <?= date('M d, Y g:i A', strtotime($f['created_at'])) ?>
                                        </div>
                                        <?php if (!empty($f['note'])): ?>
                                            <span class="inline-block text-[11px] text-amber-800 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md mt-1.5">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($f['note']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                    $isViewable = $isImg || in_array($ext, $imageExts) || $ext === 'pdf';
                                    ?>
                                    <?php if ($isViewable): ?>
                                        <a href="<?= htmlspecialchars($fp) ?>" target="_blank"
                                            class="flex-shrink-0 bg-white border border-line rounded-lg px-3 py-1.5 text-[11px] font-bold hover:border-ink transition">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($f['file_name']) ?>"
                                            class="flex-shrink-0 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-3 py-1.5 text-[11px] font-bold hover:bg-emerald-100 transition">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($f['file_name']) ?>"
                                            class="flex-shrink-0 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg px-3 py-1.5 text-[11px] font-bold hover:bg-emerald-100 transition">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!$viewOnly): ?>
                                        <button
                                            class="flex-shrink-0 bg-red-50 text-red-600 border border-red-200 rounded-lg px-2.5 py-1.5 text-[11px] font-bold hover:bg-red-100 transition"
                                            onclick="confirmDelete(<?= $f['id'] ?>,'<?= htmlspecialchars($f['file_name'], ENT_QUOTES) ?>','<?= $redirectBase ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php $i++; endforeach; ?>

                    <!-- Upload pane (when files exist) -->
                    <?php if (!$viewOnly && $canUpload): ?>
                        <div class="tab-pane" id="td-upload">
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- No files yet: show upload directly without tabs -->
                        <div class="p-6">
                        <?php endif; // endif !empty filesByCategory ?>

                        <!-- ── Upload Form ── -->
                        <?php if ($canUpload && !$viewOnly): ?>
                            <div class="border-2 border-dashed border-blue-200 rounded-lg p-5.5 bg-blue-50/40">
                                <datalist id="catSuggestions">
                                    <?php foreach ($existingCategories as $ec): ?>
                                        <option value="<?= htmlspecialchars($ec) ?>"><?php endforeach; ?>
                                    <option value="Cutting List">
                                    <option value="Shop Drawing">
                                    <option value="Sketch">
                                    <option value="Technical Drawing">
                                    <option value="Specification Sheet">
                                    <option value="BOQ">
                                    <option value="Site Photo">
                                </datalist>

                                <div class="mb-4">
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                                        <i class="fas fa-tag"></i> Category / Document Type <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="tdCategoryName"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                        list="catSuggestions" placeholder="e.g. Cutting List, Shop Drawing, Sketch…">
                                    <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-info-circle"></i> Files with the same category are grouped under one tab.</div>
                                </div>

                                <div class="mb-4">
                                    <div id="uploadZone"
                                        class="border-2 border-dashed border-line rounded-lg py-8 px-5 text-center cursor-pointer bg-white transition hover:border-ink hover:bg-[#F5F5F5]">
                                        <i class="fas fa-cloud-upload-alt text-3xl mb-2 block text-blue-600"></i>
                                        <p class="text-sm font-semibold">Click or drag files here</p>
                                        <p class="text-[11px] text-muted mt-1" id="td-hint">Images, PDFs, DWG, DXF, Word,
                                            Excel &amp; more &nbsp;·&nbsp; Max <?= $maxFiles - $fileCount ?> more
                                            &nbsp;·&nbsp; Max 50MB (Direct) or 1.3GB (Chunked)</p>
                                        <p class="text-[13px] text-blue-700 font-semibold mt-2" id="fileCountLabel"></p>
                                        <input type="file" multiple id="fileInput"
                                            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.dwg,.dxf,.txt,.zip"
                                            style="display:none;" onclick="event.stopPropagation()"
                                            onchange="tdAutoSuggestMode(this)">
                                    </div>

                                    <!-- Upload mode toggle -->
                                    <div class="flex items-center gap-2.5 bg-white border border-line rounded-lg px-3.5 py-2.5 mt-3 text-[12px] font-semibold flex-wrap">
                                        <div class="flex items-center gap-1.5 text-soft">
                                            <i class="fas fa-bolt text-blue-700"></i>
                                            <span>Upload Mode:</span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="td-mode-toggle" onchange="tdOnModeChange()">
                                            <span class="toggle-slider"></span>
                                        </label>
                                        <div id="td-mode-label">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800">
                                                <i class="fas fa-bolt"></i> Direct
                                            </span>
                                            <span class="text-[11px] text-muted ml-1">Best for files under 50MB · faster, no 405 errors</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Progress bar -->
                                <div id="td-progress-wrap" style="display:none;" class="mb-3.5">
                                    <div class="flex justify-between text-[12px] text-soft mb-1.5">
                                        <span id="td-progress-label">Uploading...</span>
                                        <span id="td-progress-pct">0%</span>
                                    </div>
                                    <div class="h-[7px] bg-line rounded-full overflow-hidden">
                                        <div id="td-progress-bar" style="height:100%; width:0%; border-radius:999px; transition:width .2s; background:linear-gradient(90deg,#0c4a6e,#0369a1);"></div>
                                    </div>
                                    <div id="td-progress-sub" class="text-[11px] text-muted mt-1"></div>
                                </div>

                                <div id="td-upload-error"
                                    class="hidden bg-red-50 border border-red-300 text-red-800 rounded-lg px-3.5 py-2.5 text-[13px] mb-2.5"></div>

                                <div class="mb-4">
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                                        <i class="fas fa-sticky-note"></i> Note (optional)
                                    </label>
                                    <textarea id="tdNote" rows="2"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink resize-y"
                                        placeholder="Any notes about these files…"></textarea>
                                </div>

                                <button type="button" id="tdUploadBtn" onclick="startTDUpload()"
                                    class="inline-flex items-center gap-2 bg-blue-700 text-white rounded-lg px-6 py-3 text-sm font-semibold hover:opacity-90 transition">
                                    <i class="fas fa-upload"></i> Upload Files
                                </button>
                            </div>

                        <?php elseif ($viewOnly): ?>
                            <div class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line rounded-lg px-4 py-2.5 text-[13px] text-soft">
                                <i class="fas fa-eye"></i> View only — you cannot upload files
                            </div>
                        <?php else: ?>
                            <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-4 py-3 text-[13px]">
                                <i class="fas fa-exclamation-triangle"></i> Maximum of <?= $maxFiles ?> files reached.
                                Delete a file to upload more.
                            </div>
                        <?php endif; ?>

                        <!-- Close wrapper divs -->
                        <?php if (!empty($filesByCategory)): ?>
                            <?php if (!$viewOnly && $canUpload): ?>
                            </div><?php endif; ?>
                        <?php else: ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Empty state (view only with no files) -->
                <?php if (empty($filesByCategory) && $viewOnly): ?>
                    <div class="text-center py-9 text-muted">
                        <i class="fas fa-folder-open text-3xl mb-2.5 block opacity-40"></i>
                        <p class="text-[13px]">No files uploaded yet.</p>
                    </div>
                <?php endif; ?>

        </div><!-- /panel -->


        <!-- ════════════════════════════════════════════
             APPROVAL PANEL
        ════════════════════════════════════════════ -->
        <div class="bg-white border <?= $panelBorderClass ?> rounded-[10px] overflow-hidden mb-5">
            <div class="<?= $panelBgClass ?> border-b <?= $panelBorderClass ?> px-5 py-3.5 flex justify-between items-center gap-3 flex-wrap">
                <div>
                    <div class="text-[13px] font-bold <?= $panelTextClass ?> flex items-center gap-2 flex-wrap">
                        <i class="fas <?= $panelIcon ?>"></i>
                        Approval Status — <?= htmlspecialchars($locationLabel) ?>
                        <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-white/60 <?= $panelTextClass ?>"><?= $panelLabel ?></span>
                    </div>
                    <div class="text-[11px] <?= $panelTextClass ?> opacity-75 mt-0.5">
                        <?= $approvalRequested ? '● Approval has been requested' : '○ No approval request yet' ?>
                    </div>
                </div>

                <!-- Request/Re-request buttons -->
                <?php if ($hasActiveRevision && $revisionStatus === 'pending' && $canRequestApproval && $hasAnyFile): ?>
                    <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                        <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                            <i class="fas fa-paper-plane"></i> Submit Revised
                        </button>
                    </form>
                <?php elseif ($canRequestApproval): ?>
                    <?php if (!$approvalRequested && $hasAnyFile): ?>
                        <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <button type="submit"
                                class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                                <i class="fas fa-paper-plane"></i> Request Approval
                            </button>
                        </form>
                    <?php elseif (!$approvalRequested): ?>
                        <span class="text-[12px] text-muted italic">Upload files first</span>
                    <?php elseif ($anyRejected): ?>
                        <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <input type="hidden" name="resubmit" value="1">
                            <button type="submit"
                                class="inline-flex items-center gap-2 bg-red-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                                <i class="fas fa-redo"></i> Re-request Approval
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Revision banners -->
            <?php if ($hasActiveRevision && $revisionStatus === 'pending'): ?>
                <div class="bg-amber-50 border-b border-amber-200 px-5 py-3.5 flex items-start gap-2.5">
                    <i class="fas fa-redo text-amber-600 mt-0.5 flex-shrink-0"></i>
                    <div>
                        <div class="text-[13px] font-bold text-amber-900 mb-0.5">
                            Revision #<?= $activeRevision['revision_number'] ?> Requested
                            <span class="bg-amber-500 text-white text-[10px] px-2 py-0.5 rounded-full ml-1.5">Awaiting Resubmission</span>
                        </div>
                        <div class="text-[12px] text-amber-800"><?= nl2br(htmlspecialchars($activeRevision['reason'])) ?></div>
                    </div>
                </div>
            <?php elseif ($hasActiveRevision && $revisionStatus === 'designer_resubmitted'): ?>
                <div class="bg-blue-50 border-b border-blue-200 px-5 py-3.5 flex items-center gap-2.5">
                    <i class="fas fa-clock text-blue-600"></i>
                    <div>
                        <div class="text-[13px] font-bold text-blue-900 mb-0.5">
                            Revision #<?= $activeRevision['revision_number'] ?> — Revised Files Submitted
                            <span class="bg-blue-600 text-white text-[10px] px-2 py-0.5 rounded-full ml-1.5">Awaiting Approval</span>
                        </div>
                        <div class="text-[12px] text-blue-800"><?= nl2br(htmlspecialchars($activeRevision['reason'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Per-approver rows -->
            <div class="p-5 flex flex-col gap-2.5">
                <?php foreach ($approvers as $apr):
                    $rec = $approvalMap[$apr['id']] ?? null;
                    $aStatus = $rec ? $rec['status'] : 'not_requested';
                    if ($aStatus === 'approved') {
                        $aRowBg = 'bg-emerald-50 border-emerald-200';
                        $aIconWrap = 'bg-emerald-100 border-emerald-300';
                        $aIconColor = 'text-emerald-700';
                        $aIcon = 'fa-check-circle';
                        $aBadge = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                        $aLabel = 'Approved';
                    } elseif ($aStatus === 'rejected') {
                        $aRowBg = 'bg-red-50 border-red-200';
                        $aIconWrap = 'bg-red-100 border-red-300';
                        $aIconColor = 'text-red-700';
                        $aIcon = 'fa-times-circle';
                        $aBadge = 'bg-red-100 text-red-800 border-red-300';
                        $aLabel = 'Rejected';
                    } elseif ($aStatus === 'pending') {
                        $aRowBg = 'bg-amber-50 border-amber-200';
                        $aIconWrap = 'bg-amber-100 border-amber-300';
                        $aIconColor = 'text-amber-700';
                        $aIcon = 'fa-hourglass-half';
                        $aBadge = 'bg-amber-100 text-amber-800 border-amber-300';
                        $aLabel = 'Pending';
                    } else {
                        $aRowBg = 'bg-[#F5F5F5] border-line';
                        $aIconWrap = 'bg-white border-line';
                        $aIconColor = 'text-muted';
                        $aIcon = 'fa-minus-circle';
                        $aBadge = 'bg-white text-soft border-line';
                        $aLabel = 'Not Requested';
                    }
                    $canAct = ($isApprover && $apr['id'] == $admin_id && $aStatus === 'pending')
                        && (!$hasActiveRevision || $revisionStatus === 'designer_resubmitted');
                    ?>
                    <div class="<?= $aRowBg ?> border rounded-lg px-3.5 py-2.5 flex justify-between items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-9 h-9 <?= $aIconWrap ?> border rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas <?= $aIcon ?> <?= $aIconColor ?> text-sm"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-bold text-[13px]"><?= htmlspecialchars($apr['full_name']) ?></span>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold capitalize <?= $aBadge ?>">
                                        <?= str_replace('_', ' ', $apr['role']) ?>
                                    </span>
                                </div>
                                <?php if ($rec && $rec['responded_at']): ?>
                                    <div class="text-[11px] text-muted mt-1"><?= date('M d, Y · g:i A', strtotime($rec['responded_at'])) ?></div>
                                <?php endif; ?>
                                <?php if ($rec && $rec['comment']): ?>
                                    <div class="text-[11px] text-amber-800 bg-amber-50 border border-amber-300 px-2.5 py-1 rounded-md mt-1.5 inline-block">
                                        <?= htmlspecialchars($rec['comment']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($canAct): ?>
                            <div class="flex gap-2 items-center flex-shrink-0">
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>,'<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>','approved')"
                                    class="bg-emerald-600 text-white border-none px-4 py-1.5 rounded-lg text-[12px] font-bold hover:opacity-90 transition inline-flex items-center gap-1.5">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>,'<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>','rejected')"
                                    class="bg-red-600 text-white border-none px-4 py-1.5 rounded-lg text-[12px] font-bold hover:opacity-90 transition inline-flex items-center gap-1.5">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="text-[11px] font-bold px-2.5 py-1 rounded-full border whitespace-nowrap <?= $aBadge ?>"><?= $aLabel ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /container -->

    <!-- Approve/Reject Modal -->
    <div id="approveModal" class="hidden fixed inset-0 z-[3000] bg-black/50 items-center justify-center">
        <div class="bg-white rounded-[14px] p-7 max-w-[480px] w-[90%]">
            <h3 id="approveModalTitle" class="text-[17px] font-bold mb-4"></h3>
            <textarea id="approveComment" placeholder="Comment (required for rejection)…"
                class="w-full border border-line rounded-lg px-3.5 py-2.5 text-[13px] resize-y min-h-[90px] mb-4 focus:outline-none focus:border-ink"></textarea>
            <div class="flex gap-2.5 justify-end">
                <button onclick="closeApproveModal()"
                    class="bg-white border border-line rounded-lg px-4.5 py-2.5 font-semibold text-[13px] hover:border-ink transition">Cancel</button>
                <button id="approveConfirmBtn" onclick="submitApproval()"
                    class="rounded-lg px-4.5 py-2.5 font-semibold text-[13px] text-white"></button>
            </div>
        </div>
    </div>

    <script>
        // ── Tab switching ─────────────────────────────────────────────────────────
        function switchTab(barId, clickedPill) {
            const bar = document.getElementById(barId);
            bar.querySelectorAll('.tab-pill').forEach(p => p.classList.remove('active'));
            clickedPill.classList.add('active');

            const targetId = clickedPill.dataset.target;
            const prefix = targetId.split('-')[0] + '-'; // "des-" or "td-"
            document.querySelectorAll('.tab-pane').forEach(pane => {
                if (pane.id.startsWith(prefix)) pane.classList.remove('active');
            });
            const target = document.getElementById(targetId);
            if (target) target.classList.add('active');
        }

        // ── Upload zone ──────────────────────────────────────────────────────────
        const zone = document.getElementById('uploadZone');
        const input = document.getElementById('fileInput');
        const lbl = document.getElementById('fileCountLabel');
        if (zone) {
            zone.addEventListener('click', () => input.click());
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('border-ink'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('border-ink'));
            zone.addEventListener('drop', e => {
                e.preventDefault(); zone.classList.remove('border-ink');
                const filtered = Array.from(e.dataTransfer.files).filter(f => !f.type.startsWith('video/'));
                const dt = new DataTransfer();
                filtered.forEach(f => dt.items.add(f));
                input.files = dt.files;
                if (lbl) lbl.textContent = input.files.length + ' file(s) selected';
            });
        }
        if (input) input.addEventListener('change', () => {
            if (lbl) lbl.textContent = input.files.length ? input.files.length + ' file(s) selected' : '';
        });

        function formatTDBytes(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 1024).toFixed(0) + ' KB';
        }

        // ── Mode toggle helpers ──────────────────────────────────────────
        function tdOnModeChange() {
            const isChunk = document.getElementById('td-mode-toggle').checked;
            const label = document.getElementById('td-mode-label');
            const hint = document.getElementById('td-hint');
            if (isChunk) {
                label.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-100 text-amber-800"><i class="fas fa-layer-group"></i> Chunked</span>
            <span class="text-[11px] text-muted ml-1">For large files up to 1.3GB · slower start</span>`;
                if (hint) hint.textContent = 'Images, PDFs, DWG, DXF, Word, Excel & more · Max 1.3GB each (Chunked mode)';
            } else {
                label.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800"><i class="fas fa-bolt"></i> Direct</span>
            <span class="text-[11px] text-muted ml-1">Best for files under 50MB · faster, no 405 errors</span>`;
                if (hint) hint.textContent = 'Images, PDFs, DWG, DXF, Word, Excel & more · Max 50MB (Direct) or 1.3GB (Chunked)';
            }
        }

        function tdAutoSuggestMode(inputEl) {
            if (!inputEl.files || inputEl.files.length === 0) return;
            const toggle = document.getElementById('td-mode-toggle');
            const DIRECT_LIMIT = 50 * 1024 * 1024;
            let needsChunk = false;
            for (const f of inputEl.files) {
                if (f.size > DIRECT_LIMIT) { needsChunk = true; break; }
            }
            const wasChunk = toggle.checked;
            toggle.checked = needsChunk;
            if (toggle.checked !== wasChunk) tdOnModeChange();
        }

        async function tdDirectUpload(files, categoryName, note) {
            const btn = document.getElementById('tdUploadBtn');
            const errEl = document.getElementById('td-upload-error');
            const progressWrap = document.getElementById('td-progress-wrap');
            const progressBar = document.getElementById('td-progress-bar');
            const progressPct = document.getElementById('td-progress-pct');
            const progressLbl = document.getElementById('td-progress-label');

            const DIRECT_LIMIT = 50 * 1024 * 1024;
            const oversized = files.filter(f => f.size > DIRECT_LIMIT);
            if (oversized.length > 0) {
                errEl.textContent = oversized.map(f => f.name + ' exceeds 50MB — switch to Chunked mode.').join(' ');
                errEl.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
                return;
            }

            progressWrap.style.display = 'block';

            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                progressBar.style.width = '100%';
                progressBar.style.transition = 'none';
                progressBar.style.background = 'repeating-linear-gradient(90deg,#0c4a6e 0px,#38bdf8 20px,#0c4a6e 40px)';
                progressBar.style.backgroundSize = '200% 100%';
                progressBar.style.animation = 'shimmer 1.5s infinite linear';
                progressPct.textContent = 'Uploading...';
                progressLbl.textContent = `Sending file ${i + 1}/${files.length}: ${file.name}`;

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>td-attachment-direct-upload';
                form.enctype = 'multipart/form-data';
                form.target = 'td_direct_frame';
                form.style.display = 'none';

                const fields = {
                    client_id: <?= $client_id ?>,
                    area: <?= json_encode($area) ?>,
                    category_name: categoryName,
                    note: note,
                    room_unit_number: '',
                    room_unit_name: ''
                };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }

                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = 'file';
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                form.appendChild(fileInput);

                document.body.appendChild(form);

                const result = await new Promise((resolve) => {
                    const iframe = document.getElementById('td_direct_frame');
                    iframe.onload = function () {
                        try {
                            const raw = iframe.contentDocument?.body?.innerText?.trim();
                            if (!raw || raw.length < 5) return;
                            resolve(JSON.parse(raw));
                        } catch (e) {
                            resolve({ success: false, error: 'Server error. Try chunked mode.' });
                        }
                    };
                    form.submit();
                });

                document.body.removeChild(form);

                if (!result.success) {
                    progressBar.style.animation = 'none';
                    progressBar.style.width = '0%';
                    errEl.textContent = file.name + ': ' + (result.error || 'Upload failed');
                    errEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
                    return;
                }
            }

            progressBar.style.animation = 'none';
            progressBar.style.background = '';
            progressBar.style.width = '100%';
            progressPct.textContent = '100%';
            progressLbl.textContent = 'All files uploaded!';
            setTimeout(() => location.reload(), 900);
        }
        // ────────────────────────────────────────────────────────────────

        async function tdChunkWithRetry(fd, maxRetries = 8) {
            let lastError;
            const delays = [1000, 2000, 3000, 5000, 8000, 10000, 15000, 20000];

            for (let attempt = 1; attempt <= maxRetries; attempt++) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 60000);

                try {
                    const res = await fetch('<?= BASE_URL ?>td-attachment-chunk-upload', {
                        method: 'POST',
                        body: fd,
                        signal: controller.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    clearTimeout(timeout);

                    if (res.status === 405 || res.status === 503 || res.status === 502) {
                        const waitMs = delays[attempt - 1] || 20000;
                        console.warn(`HTTP ${res.status} on attempt ${attempt}/${maxRetries}, retrying in ${waitMs}ms...`);
                        await new Promise(r => setTimeout(r, waitMs));
                        continue;
                    }

                    if (!res.ok) throw new Error('HTTP ' + res.status);

                    const text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (parseErr) {
                        console.warn('Non-JSON response on attempt ' + attempt + ':', text.slice(0, 200));
                        lastError = new Error('Invalid server response');
                        const waitMs = delays[attempt - 1] || 20000;
                        await new Promise(r => setTimeout(r, waitMs));
                        continue;
                    }

                } catch (e) {
                    clearTimeout(timeout);
                    lastError = e;
                    const isAbort = e.name === 'AbortError';
                    const waitMs = delays[attempt - 1] || 20000;

                    if (attempt < maxRetries) {
                        console.warn(`TD chunk error (attempt ${attempt}/${maxRetries}): ${isAbort ? 'timeout' : e.message}, retrying in ${waitMs}ms`);
                        await new Promise(r => setTimeout(r, waitMs));
                    }
                }
            }
            throw lastError;
        }

        async function startTDUpload() {
            const categoryName = document.getElementById('tdCategoryName').value.trim();
            const note = document.getElementById('tdNote').value.trim();
            const files = Array.from(input.files);
            const btn = document.getElementById('tdUploadBtn');
            const errEl = document.getElementById('td-upload-error');
            errEl.classList.add('hidden');

            if (!categoryName) {
                errEl.textContent = 'Please enter a category / document type.';
                errEl.classList.remove('hidden');
                return;
            }
            if (files.length === 0) {
                errEl.textContent = 'Please select at least one file.';
                errEl.classList.remove('hidden');
                return;
            }
            if (files.some(f => f.type.startsWith('video/'))) {
                errEl.textContent = 'Video files are not allowed.';
                errEl.classList.remove('hidden');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            const isDirectMode = !document.getElementById('td-mode-toggle').checked;
            if (isDirectMode) {
                await tdDirectUpload(files, categoryName, note);
                return;
            }

            await startTDChunkUpload(files, categoryName, note);
        }

        async function startTDChunkUpload(files, categoryName, note) {
            const btn = document.getElementById('tdUploadBtn');
            const errEl = document.getElementById('td-upload-error');

            const oversized = files.filter(f => f.size > 1.3 * 1024 * 1024 * 1024);
            if (oversized.length > 0) {
                errEl.textContent = oversized.map(f => f.name + ' exceeds 1.3GB limit.').join(' ');
                errEl.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
                return;
            }

            let CHUNK_SIZE = 2 * 1024 * 1024;

            const MIN_CHUNK = 512 * 1024;
            const MAX_CHUNK = 32 * 1024 * 1024;
            const TARGET_MS = 8000;
            const SERVER_OH = 250;

            function adjustChunkSize(elapsedMs, bytesSent) {
                const netMs = Math.max(elapsedMs - SERVER_OH, 50);
                const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
                const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
                const next = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
                console.log(`Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(next / 1024 / 1024).toFixed(1)}MB`);
                return next;
            }

            const progressWrap = document.getElementById('td-progress-wrap');
            const progressBar = document.getElementById('td-progress-bar');
            const progressPct = document.getElementById('td-progress-pct');
            const progressLbl = document.getElementById('td-progress-label');
            const progressSub = document.getElementById('td-progress-sub');
            progressWrap.style.display = 'block';

            let anyError = false;

            for (let fi = 0; fi < files.length; fi++) {
                const file = files[fi];
                CHUNK_SIZE = 2 * 1024 * 1024;
                let bytesSent = 0;
                let chunkIndex = 0;
                const uploadId = 'td_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

                while (bytesSent < file.size) {
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
                    fd.append('client_id', <?= $client_id ?>);
                    fd.append('area', <?= json_encode($area) ?>);
                    fd.append('category_name', categoryName);
                    fd.append('note', note);
                    fd.append('room_unit_number', '');
                    fd.append('room_unit_name', '');

                    try {
                        const t0 = performance.now();
                        let data;
                        try {
                            data = await tdChunkWithRetry(fd);
                        } catch (retryErr) {
                            const msg = retryErr?.message?.includes('405')
                                ? 'Server rejected the upload (405). Please wait a moment and try again.'
                                : 'Connection error after 5 attempts. Please try again.';
                            errEl.textContent = file.name + ': ' + msg;
                            errEl.classList.remove('hidden');
                            anyError = true;
                            break;
                        }
                        const elapsed = performance.now() - t0;

                        if (!data.success) {
                            errEl.textContent = file.name + ': ' + (data.error || 'Upload failed');
                            errEl.classList.remove('hidden');
                            anyError = true;
                            break;
                        }

                        bytesSent += (end - start);
                        chunkIndex++;

                        const pct = Math.round(((fi + bytesSent / file.size) / files.length) * 100);
                        progressBar.style.width = pct + '%';
                        progressPct.textContent = pct + '%';
                        progressLbl.textContent = `File ${fi + 1}/${files.length}: ${file.name} · chunk ${chunkIndex} (${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each)`;
                        progressSub.textContent = formatTDBytes(bytesSent) + ' of ' + formatTDBytes(file.size);

                        if (!isLast) {
                            CHUNK_SIZE = adjustChunkSize(elapsed, end - start);
                            await new Promise(r => setTimeout(r, 300));
                        }

                    } catch (e) {
                        errEl.textContent = file.name + ': Connection error. Please try again.';
                        errEl.classList.remove('hidden');
                        anyError = true;
                        break;
                    }

                    if (anyError) break;
                }
                if (anyError) break;
            }

            if (!anyError) {
                progressLbl.textContent = 'All files uploaded!';
                progressBar.style.width = '100%';
                progressPct.textContent = '100%';
                setTimeout(() => location.reload(), 900);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
            }
        }

        // ── Delete ───────────────────────────────────────────────────────────────
        function confirmDelete(id, name, redirectBase) {
            if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
            const form = document.createElement('form');
            form.method = 'POST'; form.action = '<?= BASE_URL ?>td-attachment-delete';
            [['attachment_id', id], ['client_id', <?= $client_id ?>], ['redirect_url', redirectBase]].forEach(([k, v]) => {
                const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; form.appendChild(i);
            });
            document.body.appendChild(form); form.submit();
        }

        // ── Approval modal ───────────────────────────────────────────────────────
        let _aprId = null, _aprAction = null;
        function openApproveModal(id, name, action) {
            _aprId = id; _aprAction = action;
            const t = document.getElementById('approveModalTitle'), b = document.getElementById('approveConfirmBtn');
            document.getElementById('approveComment').value = '';
            if (action === 'approved') { t.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;margin-right:6px;"></i>Approve — ' + name; b.style.background = '#10b981'; b.textContent = 'Confirm Approve'; }
            else { t.innerHTML = '<i class="fas fa-times-circle" style="color:#ef4444;margin-right:6px;"></i>Reject — ' + name; b.style.background = '#ef4444'; b.textContent = 'Confirm Reject'; }
            document.getElementById('approveModal').style.display = 'flex';
        }
        function closeApproveModal() { document.getElementById('approveModal').style.display = 'none'; }
        async function submitApproval() {
            const comment = document.getElementById('approveComment').value.trim();
            if (_aprAction === 'rejected' && !comment) { alert('Please enter a rejection comment.'); return; }
            const btn = document.getElementById('approveConfirmBtn');
            btn.disabled = true; btn.textContent = 'Saving…';
            try {
                const res = await fetch('<?= BASE_URL ?>td-respond-approval', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ client_id: <?= $client_id ?>, area: <?= json_encode($area) ?>, room_unit_number: null, approver_id: _aprId, status: _aprAction, comment })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else { alert('Error: ' + (data.error || 'Unknown error')); btn.disabled = false; btn.textContent = _aprAction === 'approved' ? 'Confirm Approve' : 'Confirm Reject'; }
            } catch (e) { alert('Network error.'); btn.disabled = false; }
        }
        document.addEventListener('click', e => { if (e.target === document.getElementById('approveModal')) closeApproveModal(); });

        // ── TD Remark file picker ─────────────────────────────────────────
        function onTdRemarkFileChange(input) {
            const preview = document.getElementById('tdRemarkFilePreview');
            const nameEl = document.getElementById('tdRemarkFileName');
            const sizeEl = document.getElementById('tdRemarkFileSize');
            const label = document.getElementById('tdRemarkFileLabel');
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const maxSize = 100 * 1024 * 1024;
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                alert('Only PDF files are allowed.');
                input.value = '';
                return;
            }
            if (file.size > maxSize) {
                alert('File is too large. Maximum size is 100MB.');
                input.value = '';
                return;
            }
            nameEl.textContent = file.name;
            sizeEl.textContent = (file.size / (1024 * 1024)).toFixed(1) + ' MB';
            preview.classList.remove('hidden');
            preview.style.display = 'flex';
            label.textContent = file.name;
        }
        function clearTdRemarkFile() {
            document.getElementById('tdRemarkFileInput').value = '';
            const preview = document.getElementById('tdRemarkFilePreview');
            preview.classList.add('hidden');
            preview.style.display = 'none';
            document.getElementById('tdRemarkFileLabel').textContent = 'Click to attach a PDF';
        }
    </script>
    <iframe name="td_direct_frame" id="td_direct_frame" style="display:none;"></iframe>
</body>

</html>