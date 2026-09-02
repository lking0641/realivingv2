<?php
//designer_attachment_upload.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area = isset($_GET['area']) ? trim($_GET['area']) : '';

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'sales'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

// Fetch client FIRST before anything that uses $clientInfo
$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$isAssignedDesigner = ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id);

if (!$isAssignedDesigner && !$canViewAll)
    die("Access denied.");

$viewOnly = $canViewAll && !$isAssignedDesigner;

$intakeStmt = $conn->prepare("SELECT layout_type_2d, layout_type_3d FROM layout_intake WHERE client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$intake = $intakeStmt->get_result()->fetch_assoc();

// Build back URL
$backUrl = BASE_URL . 'designer-attachments?client_id=' . $client_id;

// Tab types
$tabs = ['site_measurement' => ['label' => 'Site Measurement', 'icon' => 'fa-ruler-combined', 'color' => '#0369a1', 'bg' => '#e0f2fe']];

// --- Approval helpers ---
// Get the 4 approvers
function getApprovers($conn)
{
    $stmt = $conn->prepare("
        SELECT id, full_name, role, is_head FROM account
        WHERE (role IN ('general_manager','operational_manager'))
           OR (role IN ('designer','technical_designer') AND is_head = 1)
        ORDER BY role
    ");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get approval record for this area
function getApprovalStatus($conn, $client_id, $area)
{
    $stmt = $conn->prepare("
        SELECT la.*, a.full_name as approver_name, a.role as approver_role
        FROM layout_approvals la
        JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.area = ? AND la.room_unit_number IS NULL
    ");
    $stmt->bind_param("is", $client_id, $area);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$approvers = getApprovers($conn);
$approvalRecords = getApprovalStatus($conn, $client_id, $area);

// Build a map: approver_id => record
$approvalMap = [];
foreach ($approvalRecords as $rec) {
    $approvalMap[$rec['approver_id']] = $rec;
}

// Determine overall approval state
$approvalRequested = !empty($approvalRecords);
$allApproved = $approvalRequested && count($approvers) > 0 &&
    count(array_filter($approvalRecords, fn($r) => $r['status'] === 'approved')) === count($approvers);
$anyRejected = !empty(array_filter($approvalRecords, fn($r) => $r['status'] === 'rejected'));

// Fetch assigned TD for this client
$tdAssignStmt = $conn->prepare("
    SELECT u.technical_designer_id, a.full_name as td_name
    FROM user_info u
    LEFT JOIN account a ON u.technical_designer_id = a.id
    WHERE u.id = ?
");
$tdAssignStmt->bind_param("i", $client_id);
$tdAssignStmt->execute();
$tdAssignRow = $tdAssignStmt->get_result()->fetch_assoc();
$assignedTDId = $tdAssignRow['technical_designer_id'] ?? null;
$assignedTDName = $tdAssignRow['td_name'] ?? null;

// Check if TD remark has been submitted
$tdRemarkSubmitted = false;
$tdRemarkText = '';
$tdRemarkFile = '';
$tdRemarkFileName = '';
if ($approvalRequested && !empty($approvalRecords)) {
    $firstRec = reset($approvalRecords);
    if (!empty($firstRec['td_remark'])) {
        $tdRemarkSubmitted = true;
        $tdRemarkText = $firstRec['td_remark'];
        $tdRemarkFile = $firstRec['td_remark_file'] ?? '';
        $tdRemarkFileName = $firstRec['td_remark_file_name'] ?? '';
    }
}

// Check if there is an active revision (pending = awaiting designer resubmit, designer_resubmitted = awaiting approvers)
$revBlockStmt = $conn->prepare("
    SELECT id, revision_number, reason, status, created_at FROM layout_revision_log
    WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
    AND status IN ('pending', 'designer_resubmitted')
    ORDER BY created_at DESC LIMIT 1
");
$revBlockStmt->bind_param("is", $client_id, $area);
$revBlockStmt->execute();
$activeRevision = $revBlockStmt->get_result()->fetch_assoc();
$hasActiveRevision = !empty($activeRevision);
$revisionStatus = $activeRevision['status'] ?? null; // 'pending' or 'designer_resubmitted'

// Is current user an approver?
$isApprover = false;
foreach ($approvers as $apr) {
    if ($apr['id'] == $admin_id) {
        $isApprover = true;
        break;
    }
}

// Is current user the requesting designer?
$isDesigner = ($me['role'] === 'designer' || $me['role'] === 'technical_designer');

// A head designer assigned to this client can request approval even though they are also an approver
$canRequestApproval = $isAssignedDesigner && $isDesigner;
if (!empty($intake['layout_type_2d']))
    $tabs['floor_plan'] = ['label' => 'Floor Plan', 'icon' => 'fa-vector-square', 'color' => '#5b21b6', 'bg' => '#ede9fe'];
if (!empty($intake['layout_type_3d']))
    $tabs['rendering'] = ['label' => 'Rendering', 'icon' => 'fa-cube', 'color' => '#065f46', 'bg' => '#d1fae5'];

$activeTab = $_GET['tab'] ?? array_key_first($tabs);
if (!isset($tabs[$activeTab]))
    $activeTab = array_key_first($tabs);

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

function getFiles($conn, $client_id, $type, $area)
{
    $stmt = $conn->prepare("
        SELECT la.*, a.full_name as uploader_name FROM layout_attachments la
        LEFT JOIN account a ON la.uploaded_by = a.id
        WHERE la.client_id=? AND la.attachment_type=? AND la.area=? AND la.room_unit_number IS NULL
        ORDER BY la.created_at DESC
    ");
    $stmt->bind_param("iss", $client_id, $type, $area);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$locationLabel = $area;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload — <?= htmlspecialchars($locationLabel) ?></title>
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
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5 flex justify-between items-start gap-4 flex-wrap">
            <div>
                <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                    <i class="fas fa-upload"></i> Attachment Upload
                </div>
                <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($locationLabel) ?></h1>
                <p class="text-[13.5px] text-soft mt-1">
                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($area) ?>
                </p>
                <p class="text-[13px] text-muted mt-1">
                    <?= htmlspecialchars($clientInfo['clientname']) ?> — <?= htmlspecialchars($clientInfo['nameproject']) ?>
                </p>
            </div>
            <button type="button"
                onclick="openItemsModal(<?= $client_id ?>, '<?= htmlspecialchars($area, ENT_QUOTES) ?>', null, '<?= htmlspecialchars($locationLabel, ENT_QUOTES) ?>')"
                class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-sm font-semibold hover:opacity-90 transition whitespace-nowrap">
                <i class="fas fa-boxes"></i> View Items
            </button>
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

        <!-- Tab Bar -->
        <div class="tab-bar flex gap-2 mb-5 overflow-x-auto pb-1">
            <?php foreach ($tabs as $typeKey => $tabInfo): ?>
                <?php
                $files = getFiles($conn, $client_id, $typeKey, $area);
                $isActiveTab = $activeTab === $typeKey;
                ?>
                <button type="button" class="tab-btn flex-1 min-w-[110px] flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border text-[12px] font-bold whitespace-nowrap transition <?= $isActiveTab ? '' : 'bg-white border-line text-soft hover:border-ink' ?>"
                    onclick="switchTab('<?= $typeKey ?>')"
                    style="<?= $isActiveTab ? 'border-color:' . $tabInfo['color'] . '; background:' . $tabInfo['bg'] . '; color:' . $tabInfo['color'] . ';' : '' ?>">
                    <i class="fas <?= $tabInfo['icon'] ?>"></i>
                    <span class="tab-label hidden sm:inline"><?= $tabInfo['label'] ?></span>
                    <?php if (!empty($files)): ?>
                        <span class="text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                            style="background:<?= $tabInfo['color'] ?>;"><?= count($files) ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Tab Panels -->
        <?php foreach ($tabs as $typeKey => $tabInfo): ?>
            <?php
            $files = getFiles($conn, $client_id, $typeKey, $area);
            $fileCount = count($files);
            $maxFiles = 10;
            $canUpload = $fileCount < $maxFiles;
            $redirectBase = BASE_URL . 'designer-attachment-upload?client_id=' . $client_id
                . '&area=' . urlencode($area)
                . '&tab=' . $typeKey;
            $iconMap = ['pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint', 'zip' => 'fa-file-archive', 'txt' => 'fa-file-alt'];
            ?>
            <div id="tabpanel-<?= $typeKey ?>" class="mb-5" style="display:<?= $activeTab === $typeKey ? 'block' : 'none' ?>;">
                <div class="bg-white border border-line rounded-[10px] p-6">

                    <!-- Existing Files -->
                    <?php if (!empty($files)): ?>
                        <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-3">
                            <i class="fas fa-paperclip"></i> Uploaded Files (<?= $fileCount ?>/<?= $maxFiles ?>)
                        </div>
                        <div class="flex flex-col gap-2.5 mb-5">
                            <?php foreach ($files as $file): ?>
                                <?php
                                $isImage = strpos($file['file_type'], 'image/') === 0;
                                $filePath = BASE_URL . 'uploads/layout_attachments/' . $file['file_path'];
                                $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                                $fIcon = $iconMap[$ext] ?? 'fa-file';
                                $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                ?>
                                <div class="border border-line rounded-lg p-3.5 bg-[#F5F5F5]">
                                    <div class="flex items-center gap-3">
                                        <?php if ($isImage): ?>
                                            <img src="<?= htmlspecialchars($filePath) ?>"
                                                class="w-11 h-11 object-cover rounded-md border border-line flex-shrink-0"
                                                onerror="this.style.display='none'">
                                        <?php else: ?>
                                            <div class="w-11 h-11 rounded-md flex items-center justify-center flex-shrink-0"
                                                style="background:<?= $tabInfo['bg'] ?>;">
                                                <i class="fas <?= $fIcon ?> text-lg" style="color:<?= $tabInfo['color'] ?>;"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-[13px] font-semibold truncate"><?= htmlspecialchars($file['file_name']) ?></div>
                                            <div class="text-[11px] text-muted mt-0.5">
                                                <?= round($file['file_size'] / 1024, 1) ?> KB &nbsp;•&nbsp;
                                                <?= htmlspecialchars($file['uploader_name'] ?? '') ?> &nbsp;•&nbsp;
                                                <?= date('M d, Y g:i A', strtotime($file['created_at'])) ?>
                                            </div>
                                            <?php if (!empty($file['note'])): ?>
                                                <span class="inline-block text-[11px] text-amber-800 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md mt-1.5">
                                                    <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($file['note']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$viewOnly): ?>
                                            <button type="button"
                                                class="flex-shrink-0 bg-red-50 text-red-600 border border-red-200 rounded-lg px-2.5 py-1.5 text-[11px] font-bold hover:bg-red-100 transition"
                                                onclick="confirmDelete(<?= $file['id'] ?>, '<?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?>', '<?= $redirectBase ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-2 mt-2.5">
                                        <?php if ($isImage || in_array($ext, $imageExts) || $ext === 'pdf'): ?>
                                            <a href="<?= htmlspecialchars($filePath) ?>" target="_blank"
                                                class="flex-1 text-center bg-white border border-line rounded-lg py-2 text-[11px] font-bold hover:border-ink transition">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= htmlspecialchars($filePath) ?>" download="<?= htmlspecialchars($file['file_name']) ?>"
                                            class="flex-1 text-center bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg py-2 text-[11px] font-bold hover:bg-emerald-100 transition">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <?php if ($canUpload): ?>
                        <div id="form-<?= $typeKey ?>">

                            <div id="zone-<?= $typeKey ?>"
                                class="border-2 border-dashed border-line rounded-lg py-9 px-5 text-center cursor-pointer transition hover:border-ink hover:bg-[#F5F5F5] mb-3.5">
                                <i class="fas fa-cloud-upload-alt text-3xl mb-3 block" style="color:<?= $tabInfo['color'] ?>;"></i>
                                <p class="text-sm font-semibold">Click or drag files here</p>
                                <p class="text-[11px] text-muted mt-1.5" id="hint-<?= $typeKey ?>">
                                    Images &amp; documents only — no videos &nbsp;•&nbsp;
                                    Max <?= $maxFiles - $fileCount ?> more &nbsp;•&nbsp; Max 50MB (Direct) or 1.3GB (Chunked)
                                </p>
                                <p class="text-[13px] text-emerald-700 font-semibold mt-2" id="count-<?= $typeKey ?>"></p>
                                <input type="file" multiple id="input-<?= $typeKey ?>"
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" style="display:none;"
                                    onclick="event.stopPropagation()" onchange="autoSuggestAttachMode('<?= $typeKey ?>', this)">
                            </div>

                            <!-- Upload mode toggle -->
                            <div class="flex items-center gap-2.5 bg-[#F5F5F5] border border-line rounded-lg px-3.5 py-2.5 mb-3.5 text-[12px] font-semibold flex-wrap">
                                <div class="flex items-center gap-1.5 text-soft">
                                    <i class="fas fa-bolt"></i>
                                    <span>Upload Mode:</span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="mode-toggle-<?= $typeKey ?>"
                                        onchange="onAttachModeChange('<?= $typeKey ?>')">
                                    <span class="toggle-slider"></span>
                                </label>
                                <div id="mode-label-<?= $typeKey ?>">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800">
                                        <i class="fas fa-bolt"></i> Direct
                                    </span>
                                    <span class="text-[11px] text-muted ml-1">Best for files under 50MB · faster, no 405 errors</span>
                                </div>
                            </div>

                            <div class="mb-3.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                                    <i class="fas fa-sticky-note"></i> Note (optional — applies to all files in this upload)
                                </label>
                                <textarea id="note-<?= $typeKey ?>" rows="2"
                                    class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink resize-none"
                                    placeholder="e.g. Taken during initial site visit, north side..."></textarea>
                            </div>

                            <!-- Progress bar (hidden until upload starts) -->
                            <div id="progress-wrap-<?= $typeKey ?>" style="display:none;" class="mb-3.5">
                                <div class="flex justify-between text-[12px] text-soft mb-1.5">
                                    <span id="progress-label-<?= $typeKey ?>">Uploading...</span>
                                    <span id="progress-pct-<?= $typeKey ?>">0%</span>
                                </div>
                                <div class="h-[7px] bg-line rounded-full overflow-hidden">
                                    <div id="progress-bar-<?= $typeKey ?>" style="height:100%; width:0%; border-radius:999px; transition:width .2s; background:<?= $tabInfo['color'] ?>;"></div>
                                </div>
                                <div id="progress-sub-<?= $typeKey ?>" class="text-[11px] text-muted mt-1"></div>
                            </div>

                            <div id="upload-error-<?= $typeKey ?>"
                                class="hidden bg-red-50 border border-red-300 text-red-800 rounded-lg px-3.5 py-2.5 text-[13px] mb-2.5"></div>

                            <?php if (!$viewOnly): ?>
                                <button type="button" id="btn-upload-<?= $typeKey ?>" onclick="startAttachUpload('<?= $typeKey ?>')"
                                    class="inline-flex items-center gap-2 text-white rounded-lg px-6 py-3 text-sm font-semibold hover:opacity-90 transition"
                                    style="background:<?= $tabInfo['color'] ?>;">
                                    <i class="fas fa-upload"></i> Upload Files
                                </button>
                            <?php else: ?>
                                <div class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line rounded-lg px-4 py-2.5 text-[13px] text-soft">
                                    <i class="fas fa-eye"></i> View only — you cannot upload files
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-4 py-3 text-[13px]">
                            <i class="fas fa-exclamation-triangle"></i>
                            Maximum of <?= $maxFiles ?> files reached for this section. Delete a file to upload more.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- ═══════════════════════════════════════════════
             APPROVAL PANEL
        ════════════════════════════════════════════════ -->
        <?php
        $hasAnyFile = false;
        foreach ($tabs as $typeKey => $tabInfo) {
            $f = getFiles($conn, $client_id, $typeKey, $area);
            if (!empty($f)) {
                $hasAnyFile = true;
                break;
            }
        }

        // Overall color for the approval panel
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
        ?>
        <div class="bg-white border <?= $panelBorderClass ?> rounded-[10px] overflow-hidden mb-5">
            <!-- Panel Header Bar -->
            <div class="<?= $panelBgClass ?> border-b <?= $panelBorderClass ?> px-5 py-3.5 flex justify-between items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2.5">
                    <i class="fas <?= $panelIcon ?> text-lg <?= $panelTextClass ?>"></i>
                    <div>
                        <div class="text-[13px] font-bold <?= $panelTextClass ?>">
                            Approval Status — <?= htmlspecialchars($locationLabel) ?>
                        </div>
                        <div class="text-[11px] <?= $panelTextClass ?> opacity-75 mt-0.5">
                            <?= $approvalRequested ? '● Approval has been requested' : '○ No approval request yet' ?>
                        </div>
                    </div>
                </div>

                <?php if ($hasActiveRevision && $revisionStatus === 'pending'): ?>
                    <!-- Revision pending — designer needs to resubmit -->
                    <div class="w-full bg-amber-50 border border-amber-300 rounded-lg px-4 py-3.5 flex items-start gap-3">
                        <i class="fas fa-redo text-amber-600 text-lg mt-0.5 flex-shrink-0"></i>
                        <div class="flex-1">
                            <div class="text-[13px] font-bold text-amber-900 mb-1 flex items-center gap-2 flex-wrap">
                                Revision #<?= $activeRevision['revision_number'] ?> Requested
                                <span class="bg-amber-500 text-white text-[10px] px-2 py-0.5 rounded-full font-bold">Awaiting Resubmission</span>
                                <span class="text-[11px] font-normal text-amber-700"><?= date('M d, Y g:i A', strtotime($activeRevision['created_at'])) ?></span>
                            </div>
                            <div class="text-[12px] text-amber-800 mb-2"><?= nl2br(htmlspecialchars($activeRevision['reason'])) ?></div>
                            <?php if ($canRequestApproval && $hasAnyFile): ?>
                                <form method="POST" action="<?= BASE_URL ?>request-layout-approval" class="mt-1">
                                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                    <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                                    <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                                        <i class="fas fa-paper-plane"></i> Submit Revised & Request Approval
                                    </button>
                                </form>
                            <?php elseif ($canRequestApproval && !$hasAnyFile): ?>
                                <div class="text-[12px] text-muted italic mt-1.5">
                                    <i class="fas fa-upload"></i> Upload your revised files above first, then request approval.
                                </div>
                            <?php elseif ($isApprover): ?>
                                <div class="text-[12px] text-amber-800 italic mt-1.5">
                                    <i class="fas fa-clock"></i> Waiting for designer to upload revised files and resubmit.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($hasActiveRevision && $revisionStatus === 'designer_resubmitted'): ?>
                    <!-- Revision resubmitted — approvers can now act -->
                    <div class="w-full bg-blue-50 border border-blue-300 rounded-lg px-4 py-3.5 flex items-start gap-3">
                        <i class="fas fa-paper-plane text-blue-600 text-lg mt-0.5 flex-shrink-0"></i>
                        <div class="flex-1">
                            <div class="text-[13px] font-bold text-blue-900 mb-1 flex items-center gap-2 flex-wrap">
                                Revision #<?= $activeRevision['revision_number'] ?> — Revised Design Submitted
                                <span class="bg-blue-600 text-white text-[10px] px-2 py-0.5 rounded-full font-bold">Awaiting Approval</span>
                                <span class="text-[11px] font-normal text-blue-700"><?= date('M d, Y g:i A', strtotime($activeRevision['created_at'])) ?></span>
                            </div>
                            <div class="text-[12px] text-blue-800"><?= nl2br(htmlspecialchars($activeRevision['reason'])) ?></div>
                            <?php if ($canRequestApproval): ?>
                                <?php if ($anyRejected): ?>
                                    <form method="POST" action="<?= BASE_URL ?>request-layout-approval" class="mt-2">
                                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                        <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                                        <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                        <input type="hidden" name="resubmit" value="1">
                                        <button type="submit"
                                            class="inline-flex items-center gap-2 bg-red-600 text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                                            <i class="fas fa-redo"></i> Re-request Approval
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-[12px] text-blue-800 italic mt-2">
                                        <i class="fas fa-hourglass-half"></i> Revised design submitted. Waiting for all approvers to review.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($canRequestApproval): ?>
                    <?php if (!$approvalRequested && $hasAnyFile): ?>
                        <!-- Request Approval button -->
                        <form method="POST" action="<?= BASE_URL ?>request-layout-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <button type="submit"
                                class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                                <i class="fas fa-paper-plane"></i> Request Approval
                            </button>
                        </form>
                    <?php elseif (!$approvalRequested && !$hasAnyFile): ?>
                        <span class="text-[12px] text-muted italic">Upload files first to request approval</span>
                    <?php elseif ($approvalRequested && $anyRejected): ?>
                        <!-- Re-request only for rejectors -->
                        <form method="POST" action="<?= BASE_URL ?>request-layout-approval">
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

            <!-- TD Remark Display -->
            <?php if ($approvalRequested): ?>
                <div class="bg-sky-50 border-y border-sky-200 px-5 py-3.5">
                    <?php if ($tdRemarkSubmitted): ?>
                        <div class="flex items-start gap-2.5">
                            <i class="fas fa-comment-dots text-sky-700 mt-0.5 flex-shrink-0"></i>
                            <div class="flex-1">
                                <div class="text-[12px] font-bold text-sky-900 mb-1.5 flex items-center gap-1.5 flex-wrap">
                                    Technical Designer Remark
                                    <span class="bg-emerald-100 text-emerald-800 text-[10px] px-2 py-0.5 rounded-full font-bold">
                                        <i class="fas fa-check"></i> Submitted
                                    </span>
                                    <?php if ($assignedTDName): ?>
                                        <span class="text-[11px] font-normal text-soft">by <?= htmlspecialchars($assignedTDName) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[13px] text-ink bg-white border border-sky-200 px-3 py-2.5 rounded-lg italic">
                                    "<?= htmlspecialchars($tdRemarkText) ?>"
                                </div>
                                <?php if ($tdRemarkFile): ?>
                                    <div class="mt-2.5 flex items-center gap-2.5 bg-orange-50 border border-orange-200 rounded-lg px-3 py-2.5">
                                        <i class="fas fa-file-pdf text-red-600 text-xl flex-shrink-0"></i>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-[12px] font-bold truncate"><?= htmlspecialchars($tdRemarkFileName ?: 'TD Remark Attachment') ?></div>
                                            <div class="text-[11px] text-amber-700 mt-0.5">PDF from Technical Designer</div>
                                        </div>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($tdRemarkFile) ?>" target="_blank"
                                            class="bg-blue-100 text-blue-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($tdRemarkFile) ?>"
                                            download="<?= htmlspecialchars($tdRemarkFileName ?: 'td_remark.pdf') ?>"
                                            class="bg-emerald-100 text-emerald-800 px-3 py-1.5 rounded-md text-[11px] font-bold whitespace-nowrap">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($assignedTDId): ?>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-clock text-amber-500 flex-shrink-0"></i>
                            <div class="text-[12px] text-amber-800">
                                Waiting for <strong><?= htmlspecialchars($assignedTDName) ?></strong> to submit a remark from
                                their TD Attachments page before the <strong>Technical Designer</strong> approver can proceed.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-user-slash text-muted flex-shrink-0"></i>
                            <div class="text-[12px] text-soft">
                                No Technical Designer assigned yet. A TD must be assigned and submit a remark before approval
                                can proceed.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Per-approver status rows -->
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

                    $isHeadTD = ($apr['role'] === 'technical_designer' && ($apr['is_head'] ?? 0) == 1);
                    $canAct = ($isApprover && $apr['id'] == $admin_id && $aStatus === 'pending')
                        && (!$hasActiveRevision || $revisionStatus === 'designer_resubmitted')
                        && (!$isHeadTD || $tdRemarkSubmitted);
                    ?>
                    <div class="<?= $aRowBg ?> border rounded-lg px-3.5 py-2.5 flex justify-between items-center gap-3 flex-wrap">
                        <!-- Left: Icon + Info -->
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
                                    <div class="text-[11px] text-muted mt-1 flex items-center gap-1.5">
                                        <i class="fas fa-clock text-[9px]"></i>
                                        <?= date('M d, Y · g:i A', strtotime($rec['responded_at'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($rec && $rec['comment']): ?>
                                    <div class="text-[11px] text-amber-800 bg-amber-50 border border-amber-300 px-2.5 py-1 rounded-md mt-1.5 inline-flex items-center gap-1.5">
                                        <i class="fas fa-comment-alt text-[10px]"></i>
                                        <?= htmlspecialchars($rec['comment']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right: Status or Action Buttons -->
                        <?php if ($canAct): ?>
                            <div class="flex gap-2 items-center flex-shrink-0">
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>, '<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>', 'approved')"
                                    class="bg-emerald-600 text-white border-none px-4 py-1.5 rounded-lg text-[12px] font-bold hover:opacity-90 transition inline-flex items-center gap-1.5">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>, '<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>', 'rejected')"
                                    class="bg-red-600 text-white border-none px-4 py-1.5 rounded-lg text-[12px] font-bold hover:opacity-90 transition inline-flex items-center gap-1.5">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="flex flex-col items-end gap-1">
                                <span class="text-[11px] font-bold px-2.5 py-1 rounded-full border whitespace-nowrap <?= $aBadge ?>">
                                    <?= $aLabel ?>
                                </span>
                                <?php if ($aStatus === 'pending' && !$tdRemarkSubmitted && $approvalRequested && $apr['role'] === 'technical_designer' && ($apr['is_head'] ?? 0) == 1): ?>
                                    <span class="text-[10px] text-amber-600 italic whitespace-nowrap">
                                        <i class="fas fa-clock"></i> Awaiting TD remark
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Approve/Reject Modal -->
    <div id="approveModal" class="hidden fixed inset-0 z-[3000] bg-black/50 items-center justify-center">
        <div class="bg-white rounded-[14px] p-7 max-w-[480px] w-[90%]">
            <h3 id="approveModalTitle" class="text-[17px] font-bold mb-4"></h3>
            <?php if ($tdRemarkSubmitted): ?>
                <div class="bg-sky-50 border border-sky-200 rounded-lg px-3.5 py-2.5 mb-3.5 text-[12px]">
                    <div class="font-bold text-sky-900 mb-1">
                        <i class="fas fa-comment-dots"></i> TD Remark
                        <?php if ($assignedTDName): ?>
                            <span class="font-normal text-soft ml-1">by <?= htmlspecialchars($assignedTDName) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="italic">"<?= htmlspecialchars($tdRemarkText) ?>"</div>
                </div>
            <?php endif; ?>
            <textarea id="approveComment" placeholder="Comment (required for rejection, optional for approval)..."
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
        let _approveApproverId = null;
        let _approveAction = null;

        function openApproveModal(approverId, approverName, action) {
            _approveApproverId = approverId;
            _approveAction = action;
            const title = document.getElementById('approveModalTitle');
            const btn = document.getElementById('approveConfirmBtn');
            const comment = document.getElementById('approveComment');
            comment.value = '';
            if (action === 'approved') {
                title.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981"></i> Approve — ' + approverName;
                btn.style.background = '#10b981';
                btn.textContent = 'Confirm Approve';
            } else {
                title.innerHTML = '<i class="fas fa-times-circle" style="color:#ef4444"></i> Reject — ' + approverName;
                btn.style.background = '#ef4444';
                btn.textContent = 'Confirm Reject';
            }
            document.getElementById('approveModal').style.display = 'flex';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        async function submitApproval() {
            const comment = document.getElementById('approveComment').value.trim();
            if (_approveAction === 'rejected' && !comment) {
                alert('Please enter a comment explaining the rejection.');
                return;
            }
            const btn = document.getElementById('approveConfirmBtn');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            try {
                const res = await fetch('<?= BASE_URL ?>respond-layout-approval', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        client_id: <?= $client_id ?>,
                        area: <?= json_encode($area) ?>,
                        room_unit_number: null,
                        approver_id: _approveApproverId,
                        status: _approveAction,
                        comment: comment
                    })
                });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = _approveAction === 'approved' ? 'Confirm Approve' : 'Confirm Reject';
                }
            } catch (e) {
                alert('Network error.');
                btn.disabled = false;
            }
        }

        document.addEventListener('click', function (e) {
            if (e.target === document.getElementById('approveModal')) closeApproveModal();
        });
    </script>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('[id^="tabpanel-"]').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.style.borderColor = '';
                btn.style.background = '';
                btn.style.color = '';
            });

            document.getElementById('tabpanel-' + tab).style.display = 'block';
            const clicked = event.currentTarget;
            clicked.classList.add('active');

            const colors = {
                site_measurement: { border: '#0369a1', bg: '#e0f2fe', color: '#0369a1' },
                floor_plan: { border: '#5b21b6', bg: '#ede9fe', color: '#5b21b6' },
                rendering: { border: '#065f46', bg: '#d1fae5', color: '#065f46' }
            };
            if (colors[tab]) {
                clicked.style.borderColor = colors[tab].border;
                clicked.style.background = colors[tab].bg;
                clicked.style.color = colors[tab].color;
            }
        }

        // Drag & drop + file input for each tab
        document.querySelectorAll('[id^="zone-"]').forEach(zone => {
            const tabKey = zone.id.replace('zone-', '');
            const input = document.getElementById('input-' + tabKey);
            const countEl = document.getElementById('count-' + tabKey);

            zone.addEventListener('click', () => input.click());
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('border-ink'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('border-ink'));
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('border-ink');
                // Block video files dropped in
                const filtered = Array.from(e.dataTransfer.files).filter(f => !f.type.startsWith('video/'));
                if (filtered.length < e.dataTransfer.files.length) {
                    showError(tabKey, 'Video files are not allowed and were removed from selection.');
                }
                const dt = new DataTransfer();
                filtered.forEach(f => dt.items.add(f));
                input.files = dt.files;
                showCount(input, countEl);
            });
            input.addEventListener('change', () => {
                // Block video files selected via file picker
                const allFiles = Array.from(input.files);
                const filtered = allFiles.filter(f => !f.type.startsWith('video/'));
                if (filtered.length < allFiles.length) {
                    showError(tabKey, 'Video files are not allowed and were removed from selection.');
                    const dt = new DataTransfer();
                    filtered.forEach(f => dt.items.add(f));
                    input.files = dt.files;
                }
                showCount(input, countEl);
            });
        });

        function showCount(input, el) {
            if (!el) return;
            el.textContent = input.files.length > 0 ? input.files.length + ' file(s) selected' : '';
        }

        function showError(tabKey, msg) {
            const el = document.getElementById('upload-error-' + tabKey);
            if (!el) return;
            el.textContent = msg;
            el.classList.remove('hidden');
            setTimeout(() => { el.classList.add('hidden'); }, 5000);
        }

        function formatBytes(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 1024).toFixed(0) + ' KB';
        }

        // ── Mode toggle helpers ──────────────────────────────────────────
        function onAttachModeChange(tabKey) {
            const isChunk = document.getElementById('mode-toggle-' + tabKey).checked;
            const label = document.getElementById('mode-label-' + tabKey);
            const hint = document.getElementById('hint-' + tabKey);
            if (isChunk) {
                label.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-100 text-amber-800"><i class="fas fa-layer-group"></i> Chunked</span>
            <span class="text-[11px] text-muted ml-1">For large files up to 1.3GB · slower start</span>`;
                if (hint) hint.innerHTML = 'Images &amp; documents only — no videos &nbsp;•&nbsp; Max 1.3GB each (Chunked mode)';
            } else {
                label.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-800"><i class="fas fa-bolt"></i> Direct</span>
            <span class="text-[11px] text-muted ml-1">Best for files under 50MB · faster, no 405 errors</span>`;
                if (hint) hint.innerHTML = 'Images &amp; documents only — no videos &nbsp;•&nbsp; Max 50MB (Direct) or 1.3GB (Chunked)';
            }
        }

        function autoSuggestAttachMode(tabKey, input) {
            if (!input.files || input.files.length === 0) return;
            const toggle = document.getElementById('mode-toggle-' + tabKey);
            const DIRECT_LIMIT = 50 * 1024 * 1024;
            let needsChunk = false;
            for (const f of input.files) {
                if (f.size > DIRECT_LIMIT) { needsChunk = true; break; }
            }
            const wasChunk = toggle.checked;
            toggle.checked = needsChunk;
            if (toggle.checked !== wasChunk) onAttachModeChange(tabKey);
        }

        async function attachDirectUpload(tabKey, files, note) {
            const errEl = document.getElementById('upload-error-' + tabKey);
            const btn = document.getElementById('btn-upload-' + tabKey);
            const progressWrap = document.getElementById('progress-wrap-' + tabKey);
            const progressBar = document.getElementById('progress-bar-' + tabKey);
            const progressPct = document.getElementById('progress-pct-' + tabKey);
            const progressLbl = document.getElementById('progress-label-' + tabKey);

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

                // Shimmer per file
                progressBar.style.width = '100%';
                progressBar.style.transition = 'none';
                progressBar.style.background = 'repeating-linear-gradient(90deg,#1e3a8a 0px,#60a5fa 20px,#1e3a8a 40px)';
                progressBar.style.backgroundSize = '200% 100%';
                progressBar.style.animation = 'shimmer 1.5s infinite linear';
                progressPct.textContent = 'Uploading...';
                progressLbl.textContent = `Sending file ${i + 1}/${files.length}: ${file.name}`;

                // Build temporary hidden form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>attachment-direct-upload';
                form.enctype = 'multipart/form-data';
                form.target = 'attach_direct_frame';
                form.style.display = 'none';

                // Hidden fields
                const fields = {
                    client_id: <?= $client_id ?>,
                    attachment_type: tabKey,
                    area: <?= json_encode($area) ?>,
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

                // File input — transfer single file
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = 'file';
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                form.appendChild(fileInput);

                document.body.appendChild(form);

                // Wait for iframe response
                const result = await new Promise((resolve) => {
                    const iframe = document.getElementById('attach_direct_frame');
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

            // All done
            progressBar.style.animation = 'none';
            progressBar.style.background = '';
            progressBar.style.width = '100%';
            progressPct.textContent = '100%';
            progressLbl.textContent = 'All files uploaded!';
            setTimeout(() => {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabKey);
                window.location.href = url.toString();
            }, 900);
        }
        // ────────────────────────────────────────────────────────────────

        async function attachmentChunkWithRetry(fd, maxRetries = 8) {
            let lastError;
            const delays = [1000, 2000, 3000, 5000, 8000, 10000, 15000, 20000];

            for (let attempt = 1; attempt <= maxRetries; attempt++) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 60000); // 60s timeout per chunk

                try {
                    const res = await fetch('<?= BASE_URL ?>attachment-chunk-upload', {
                        method: 'POST',
                        body: fd,
                        signal: controller.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    clearTimeout(timeout);

                    // 405, 502, 503 on Hostinger are transient — always retry
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
                        // Non-JSON response usually means a PHP error page — retry
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
                        console.warn(`Attachment chunk error (attempt ${attempt}/${maxRetries}): ${isAbort ? 'timeout' : e.message}, retrying in ${waitMs}ms`);
                        await new Promise(r => setTimeout(r, waitMs));
                    }
                }
            }
            throw lastError;
        }

        async function startAttachUpload(tabKey) {
            const isDirectMode = !document.getElementById('mode-toggle-' + tabKey).checked;
            const input = document.getElementById('input-' + tabKey);
            const files = Array.from(input.files);
            const btn = document.getElementById('btn-upload-' + tabKey);
            const errEl = document.getElementById('upload-error-' + tabKey);
            errEl.classList.add('hidden');

            if (files.length === 0) { showError(tabKey, 'Please select at least one file.'); return; }
            const hasVideo = files.some(f => f.type.startsWith('video/'));
            if (hasVideo) { showError(tabKey, 'Video files are not allowed.'); return; }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

            const note = document.getElementById('note-' + tabKey).value;

            if (isDirectMode) {
                await attachDirectUpload(tabKey, files, note);
                return;
            }

            // ── Chunked path (original logic below, just remove duplicate validation) ──
            await startChunkUpload(tabKey, files, note);
        }

        async function startChunkUpload(tabKey, files, note) {
            const btn = document.getElementById('btn-upload-' + tabKey);
            const errEl = document.getElementById('upload-error-' + tabKey);

            const oversized = files.filter(f => f.size > 1.3 * 1024 * 1024 * 1024);
            if (oversized.length > 0) {
                showError(tabKey, oversized.map(f => f.name + ' exceeds 1.3GB limit.').join(' '));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
                return;
            }

            const progressWrap = document.getElementById('progress-wrap-' + tabKey);
            const progressBar = document.getElementById('progress-bar-' + tabKey);
            const progressPct = document.getElementById('progress-pct-' + tabKey);
            const progressLbl = document.getElementById('progress-label-' + tabKey);
            const progressSub = document.getElementById('progress-sub-' + tabKey);
            progressWrap.style.display = 'block';

            // Real-time auto-adjust settings
            const MIN_CHUNK = 512 * 1024;        // 512KB floor
            const MAX_CHUNK = 32 * 1024 * 1024; // 32MB ceiling
            const TARGET_MS = 8000;               // aim ~8s per chunk
            const SERVER_OH = 250;                // ~250ms Hostinger overhead

            function adjustChunkSize(currentChunkSize, elapsedMs, bytesSent) {
                const netMs = Math.max(elapsedMs - SERVER_OH, 50);
                const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
                const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
                const next = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
                console.log(`Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(next / 1024 / 1024).toFixed(1)}MB`);
                return next;
            }

            let anyError = false;

            for (let fi = 0; fi < files.length; fi++) {
                const file = files[fi];
                let CHUNK_SIZE = 2 * 1024 * 1024; // start at 2MB per file
                let bytesSent = 0;
                let chunkIndex = 0;
                const uploadId = 'att_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

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
                    fd.append('attachment_type', tabKey);
                    fd.append('area', <?= json_encode($area) ?>);
                    fd.append('note', note);
                    fd.append('room_unit_number', '');
                    fd.append('room_unit_name', '');

                    try {
                        const t0 = performance.now();
                        let data;
                        try {
                            data = await attachmentChunkWithRetry(fd);
                        } catch (retryErr) {
                            const msg = retryErr?.message?.includes('405')
                                ? 'Server rejected the upload (405). Please wait a moment and try again.'
                                : 'Connection error after 5 attempts. Please try again.';
                            showError(tabKey, file.name + ': ' + msg);
                            anyError = true;
                            break;
                        }
                        const elapsed = performance.now() - t0;

                        if (!data.success) {
                            showError(tabKey, file.name + ': ' + (data.error || 'Upload failed'));
                            anyError = true;
                            break;
                        }

                        bytesSent += (end - start);
                        chunkIndex++;

                        // Update progress
                        const pct = Math.round(((fi + bytesSent / file.size) / files.length) * 100);
                        progressBar.style.width = pct + '%';
                        progressPct.textContent = pct + '%';
                        progressLbl.textContent = `File ${fi + 1}/${files.length}: ${file.name} · chunk ${chunkIndex} (${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each)`;
                        progressSub.textContent = formatBytes(bytesSent) + ' of ' + formatBytes(file.size);

                        // Adjust chunk size for next chunk
                        if (!isLast) {
                            CHUNK_SIZE = adjustChunkSize(CHUNK_SIZE, elapsed, end - start);
                            await new Promise(r => setTimeout(r, 300));
                        }

                    } catch (e) {
                        showError(tabKey, file.name + ': Connection error. Please try again.');
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
                setTimeout(() => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabKey);
                    window.location.href = url.toString();
                }, 900);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
            }
        }

        function confirmDelete(id, name, redirectBase) {
            if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= BASE_URL ?>attachment-delete';
            [['attachment_id', id], ['client_id', <?= $client_id ?>], ['redirect_url', redirectBase]].forEach(([k, v]) => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = k; i.value = v;
                form.appendChild(i);
            });
            document.body.appendChild(form);
            form.submit();
        }
    </script>
    <!-- Items Modal -->
    <div id="itemsModal" class="hidden fixed inset-0 z-[3000] bg-black/55 items-center justify-center">
        <div class="bg-white rounded-[14px] w-[90%] max-w-[640px] max-h-[88vh] overflow-hidden flex flex-col">

            <!-- Modal Header -->
            <div class="bg-ink px-5.5 px-[22px] py-4.5 py-[18px] flex justify-between items-center flex-shrink-0">
                <div>
                    <h3 id="itemsModalTitle" class="text-[16px] font-bold text-white mb-0.5">
                        <i class="fas fa-boxes"></i> Items
                    </h3>
                    <p id="itemsModalSub" class="text-[11px] text-white/70"></p>
                </div>
                <button onclick="closeItemsModal()"
                    class="bg-white/15 border-none text-white w-8 h-8 rounded-lg cursor-pointer text-base flex items-center justify-center hover:bg-white/25 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div id="itemsModalBody" class="overflow-y-auto p-4.5 p-[18px] flex-1">
                <div class="text-center py-8 text-muted">
                    <i class="fas fa-spinner fa-spin text-[28px]"></i>
                    <p class="mt-2.5">Loading items...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function openItemsModal(clientId, area, roomNumber, label) {
            document.getElementById('itemsModalTitle').innerHTML = '<i class="fas fa-boxes"></i> ' + escItemHtml(label);
            document.getElementById('itemsModalSub').innerHTML = '<i class="fas fa-map-marker-alt"></i> ' + escItemHtml(area);
            document.getElementById('itemsModalBody').innerHTML =
                '<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="fas fa-spinner fa-spin" style="font-size:28px;"></i><p style="margin-top:10px;">Loading items...</p></div>';
            document.getElementById('itemsModal').style.display = 'flex';

            let url = '<?= BASE_URL ?>get-area-items?client_id=' + clientId + '&area=' + encodeURIComponent(area);
            if (roomNumber !== null && roomNumber !== undefined) {
                url += '&room_number=' + roomNumber;
            }

            try {
                const res = await fetch(url);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                renderItemsModal(data.items, data.total);
            } catch (err) {
                document.getElementById('itemsModalBody').innerHTML =
                    '<div style="text-align:center;padding:30px;color:#ef4444;"><i class="fas fa-exclamation-triangle" style="font-size:28px;"></i><p style="margin-top:10px;">Error: ' + err.message + '</p></div>';
            }
        }

        function renderItemsModal(items, total) {
            if (!items || items.length === 0) {
                document.getElementById('itemsModalBody').innerHTML =
                    '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fas fa-box-open" style="font-size:36px;display:block;margin-bottom:10px;"></i>No items found.</div>';
                return;
            }

            let html = '<div style="display:flex;flex-direction:column;gap:10px;">';

            items.forEach(function (item, index) {
                let imgPath = '';
                if (item.image_folder && item.image_file) {
                    imgPath = '<?= CLIENT_ASSET ?>/images/' + item.image_folder + '/' + item.image_file;
                }

                const addonBodyId = 'ia-body-' + index;
                const addonIconId = 'ia-icon-' + index;

                html += '<div style="border:1px solid #E2E2E2; border-radius:10px; overflow:hidden;">';

                // Item row
                html += '<div style="display:flex; gap:12px; padding:13px; align-items:center; background:#F5F5F5;">';

                // Image
                if (imgPath) {
                    html += '<img src="' + imgPath + '" style="width:50px;height:50px;object-fit:cover;border-radius:8px;border:1px solid #E2E2E2;flex-shrink:0;" onerror="this.style.display=\'none\'">';
                } else {
                    html += '<div style="width:50px;height:50px;background:#E2E2E2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-box" style="color:#6B6B6B;font-size:18px;"></i></div>';
                }

                // Info
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-weight:700;font-size:13px;color:#0B0B0B;">' + escItemHtml(item.item_name) + '</div>';
                if (item.display_color) {
                    html += '<div style="font-size:11px;color:#6B6B6B;margin-top:2px;"><i class="fas fa-palette"></i> ' + escItemHtml(item.display_color) + '</div>';
                }

                // Dimensions
                let dims = [];
                if (item.width) dims.push((item.width_label || 'W') + ': ' + item.width + 'mm');
                if (item.height) dims.push((item.height_label || 'H') + ': ' + item.height + 'mm');
                if (item.length) dims.push((item.length_label || 'L') + ': ' + item.length + 'mm');
                if (dims.length) {
                    html += '<div style="font-size:11px;color:#9A9A9A;margin-top:2px;">' + dims.join(' &nbsp;•&nbsp; ') + '</div>';
                }

                // Notes
                if (item.notes && item.notes.trim()) {
                    html += '<div style="font-size:11px;color:#92400e;background:#fffbeb;padding:2px 8px;border-radius:4px;margin-top:4px;"><i class="fas fa-sticky-note"></i> ' + escItemHtml(item.notes) + '</div>';
                }
                html += '</div>';

                // Right side: qty + type badge
                html += '<div style="flex-shrink:0;text-align:center;">';
                html += '<div style="background:#0B0B0B;color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;">' + item.quantity + ' pcs</div>';
                html += '<div style="font-size:10px;color:#9A9A9A;margin-top:3px;">' + (item.entry_type === 'customized' ? 'Custom' : 'Fixed') + '</div>';
                html += '</div>';

                html += '</div>'; // end item row

                // Addons toggle
                if (item.addons && item.addons.length > 0) {
                    html += '<div style="border-top:1px solid #E2E2E2;background:#FAFAFA;">';
                    html += '<button type="button" onclick="toggleItemAddon(\'' + addonBodyId + '\',\'' + addonIconId + '\')" ';
                    html += 'style="width:100%;padding:7px 14px;background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#0B0B0B;">';
                    html += '<i class="fas fa-puzzle-piece"></i> ' + item.addons.length + ' Add-on' + (item.addons.length > 1 ? 's' : '');
                    html += '<i id="' + addonIconId + '" class="fas fa-chevron-down" style="margin-left:auto;transition:transform 0.2s;"></i>';
                    html += '</button>';

                    html += '<div id="' + addonBodyId + '" style="display:none;">';
                    item.addons.forEach(function (addon, ai) {
                        const border = ai > 0 ? 'border-top:1px solid #E2E2E2;' : '';
                        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;' + border + '">';
                        if (addon.addon_image_path) {
                            html += '<img src=<?= CLIENT_ASSET ?>/images/product_addons/' + escItemHtml(addon.addon_image_path) + '" style="width:32px;height:32px;object-fit:cover;border-radius:6px;border:1px solid #E2E2E2;flex-shrink:0;" onerror="this.style.display=\'none\'">';
                        } else {
                            html += '<div style="width:32px;height:32px;background:#E2E2E2;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-puzzle-piece" style="color:#6B6B6B;font-size:12px;"></i></div>';
                        }
                        html += '<div style="flex:1;">';
                        html += '<div style="font-size:12px;font-weight:700;color:#0B0B0B;">' + escItemHtml(addon.addon_name) + '</div>';
                        html += '<div style="font-size:11px;color:#374151;">₱' + parseFloat(addon.price).toFixed(2) + ' / pc</div>';
                        if (addon.note) html += '<div style="font-size:10px;color:#6B6B6B;font-style:italic;">' + escItemHtml(addon.note) + '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div></div>'; // close addon body + section
                }

                html += '</div>'; // end card
            });

            html += '</div>';

            // Footer summary
            html += '<div style="margin-top:14px;padding:14px 16px;background:#0B0B0B;border-radius:10px;display:flex;justify-content:space-between;align-items:center;color:white;">';
            html += '<span style="font-size:13px;font-weight:600;"><i class="fas fa-boxes"></i> Total Items</span>';
            html += '<span style="font-size:22px;font-weight:700;">' + total + '</span>';
            html += '</div>';

            document.getElementById('itemsModalBody').innerHTML = html;
        }

        function toggleItemAddon(bodyId, iconId) {
            const body = document.getElementById(bodyId);
            const icon = document.getElementById(iconId);
            if (!body) return;
            const open = body.style.display !== 'none';
            body.style.display = open ? 'none' : 'block';
            if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
        }

        function closeItemsModal() {
            document.getElementById('itemsModal').style.display = 'none';
        }

        function escItemHtml(text) {
            if (!text) return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        // Close on outside click
        document.addEventListener('click', function (e) {
            const modal = document.getElementById('itemsModal');
            if (modal && e.target === modal) closeItemsModal();
        });
    </script>
    <iframe name="attach_direct_frame" id="attach_direct_frame" style="display:none;"></iframe>
</body>

</html>