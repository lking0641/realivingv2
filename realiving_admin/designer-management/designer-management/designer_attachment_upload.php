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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', sans-serif;
        }

        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .btn-back {
            background: linear-gradient(135deg, #3b1f0f, #8a5a44);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            margin-bottom: 16px;
        }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 24px 30px;
            border-radius: 14px;
            color: white;
            margin-bottom: 22px;
        }

        .page-header h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .page-header .sub {
            font-size: 12px;
            opacity: 0.85;
            margin-top: 4px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* Tabs */
        .tab-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            overflow-x: auto;
            padding-bottom: 4px;
            padding-left: 2px;
            padding-right: 2px;
        }

        .tab-bar::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            flex: 1;
            min-width: 100px;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s;
            background: white;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .tab-btn:hover:not(.active) {
            border-color: #8a5a44;
            background: #fdf6f0;
        }

        /* Upload card */
        .upload-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
        }

        .upload-zone {
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 14px;
            background: #fafafa;
        }

        .upload-zone:hover,
        .upload-zone.drag-over {
            border-color: #8a5a44;
            background: #fdf6f0;
        }

        .upload-zone i {
            font-size: 36px;
            color: #9ca3af;
            margin-bottom: 12px;
            display: block;
        }

        .upload-zone .hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 6px;
        }

        .note-input {
            width: 100%;
            padding: 9px 13px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            resize: none;
        }

        .note-input:focus {
            outline: none;
            border-color: #8a5a44;
        }

        .btn-upload {
            background: linear-gradient(135deg, #3b1f0f, #8a5a44);
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }

        .btn-upload:hover {
            opacity: 0.9;
        }

        /* File list */
        .file-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #fafafa;
            flex-wrap: wrap;
        }

        .file-thumb {
            width: 46px;
            height: 46px;
            object-fit: cover;
            border-radius: 6px;
            flex-shrink: 0;
            border: 1px solid #e9ecef;
        }

        .file-icon {
            width: 46px;
            height: 46px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 2px;
            line-height: 1.5;
            display: flex;
            flex-wrap: wrap;
            gap: 2px 6px;
            align-items: center;
        }

        .file-note {
            font-size: 11px;
            color: #92400e;
            background: #fffbeb;
            padding: 2px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        .btn-view {
            background: #e0e7ff;
            color: #3730a3;
            border: none;
            border-radius: 6px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            flex-shrink: 0;
        }

        .btn-delete {
            background: #fee2e2;
            color: #ef4444;
            border: none;
            border-radius: 6px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .btn-delete:hover {
            background: #fecaca;
        }

        .files-header {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 10px;
        }

        .max-reached {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #92400e;
        }

        .file-count-text {
            font-size: 13px;
            color: #065f46;
            font-weight: 600;
            margin-top: 8px;
        }

        /* Upload mode toggle */
        .upload-mode-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f9fafb;
            border: 1px solid #e9ecef;
            border-radius: 9px;
            padding: 8px 14px;
            margin-bottom: 12px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
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
            background: #3b1f0f;
        }

        .toggle-switch input:checked+.toggle-slider:before {
            transform: translateX(20px);
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

        @keyframes shimmer {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        @media (max-width: 600px) {
            .container {
                max-width: 800px;
                margin: 30px auto;
                padding: 0 20px;
                overflow-x: hidden;
            }

            .page-header {
                padding: 16px;
            }

            .page-header h1 {
                font-size: 16px;
            }

            .page-header .sub {
                font-size: 11px;
            }

            /* File card compact */
            .file-thumb {
                width: 36px;
                height: 36px;
            }

            .file-icon {
                width: 36px;
                height: 36px;
            }

            .file-name {
                font-size: 12px;
            }

            .file-meta {
                font-size: 10px;
                white-space: normal;
                line-height: 1.4;
            }

            .file-note {
                font-size: 10px;
            }

            /* View/Download/Delete buttons smaller */
            .btn-view {
                padding: 4px 8px;
                font-size: 10px;
            }

            .btn-delete {
                padding: 4px 8px;
                font-size: 10px;
            }

            /* Upload zone smaller */
            .upload-zone {
                padding: 20px 14px;
            }

            .upload-zone i {
                font-size: 26px;
            }

            /* Page header — stack title and View Items button */
            .page-header>div>div:first-child {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .page-header button {
                align-self: flex-start;
                font-size: 12px !important;
                padding: 7px 12px !important;
            }

            /* Icon-only tabs on mobile */
            .tab-btn {
                min-width: unset;
                padding: 8px 10px;
                flex: 1;
            }

            .tab-label {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back
        </a>

        <div class="page-header">
            <div style="display:flex; justify-content:space-between; align-items:start; gap:16px;">
                <div>
                    <h1><i class="fas fa-upload"></i> <?= htmlspecialchars($locationLabel) ?></h1>
                    <div class="sub">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($area) ?>
                    </div>
                    <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> —
                        <?= htmlspecialchars($clientInfo['nameproject']) ?>
                    </div>
                </div>
                <button type="button"
                    onclick="openItemsModal(<?= $client_id ?>, '<?= htmlspecialchars($area, ENT_QUOTES) ?>', null, '<?= htmlspecialchars($locationLabel, ENT_QUOTES) ?>')"
                    style="background:rgba(255,255,255,0.2); border:1.5px solid rgba(255,255,255,0.5); color:white; padding:9px 18px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px; white-space:nowrap; flex-shrink:0;">
                    <i class="fas fa-boxes"></i> View Items
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tab Bar -->
        <div class="tab-bar">
            <?php foreach ($tabs as $typeKey => $tabInfo): ?>
                <?php $files = getFiles($conn, $client_id, $typeKey, $area); ?>
                <button type="button" class="tab-btn <?= $activeTab === $typeKey ? 'active' : '' ?>"
                    onclick="switchTab('<?= $typeKey ?>')"
                    style="<?= $activeTab === $typeKey ? 'border-color:' . $tabInfo['color'] . '; background:' . $tabInfo['bg'] . '; color:' . $tabInfo['color'] . ';' : '' ?>">
                    <i class="fas <?= $tabInfo['icon'] ?>"></i>
                    <span class="tab-label"><?= $tabInfo['label'] ?></span>
                    <?php if (!empty($files)): ?>
                        <span
                            style="background:<?= $tabInfo['color'] ?>; color:white; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:4px;"><?= count($files) ?></span>
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
            <div id="tabpanel-<?= $typeKey ?>" style="display:<?= $activeTab === $typeKey ? 'block' : 'none' ?>;">
                <div class="upload-card">

                    <!-- Existing Files -->
                    <?php if (!empty($files)): ?>
                        <p class="files-header"><i class="fas fa-paperclip"></i> Uploaded Files
                            (<?= $fileCount ?>/<?= $maxFiles ?>)</p>
                        <?php foreach ($files as $file): ?>
                            <?php
                            $isImage = strpos($file['file_type'], 'image/') === 0;
                            $filePath = BASE_URL . 'uploads/layout_attachments/' . $file['file_path'];
                            $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                            $fIcon = $iconMap[$ext] ?? 'fa-file';
                            ?>
                            <div class="file-card" style="flex-direction:column; gap:10px;">
                                <!-- Row 1: icon + name + delete -->
                                <div style="display:flex; align-items:center; gap:10px; width:100%;">
                                    <?php if ($isImage): ?>
                                        <img src="<?= htmlspecialchars($filePath) ?>" class="file-thumb"
                                            onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="file-icon" style="background:<?= $tabInfo['bg'] ?>;">
                                            <i class="fas <?= $fIcon ?>" style="color:<?= $tabInfo['color'] ?>; font-size:20px;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div style="flex:1; min-width:0;">
                                        <div class="file-name"><?= htmlspecialchars($file['file_name']) ?></div>
                                        <div style="font-size:11px; color:#9ca3af; margin-top:2px;">
                                            <?= round($file['file_size'] / 1024, 1) ?> KB &nbsp;•&nbsp;
                                            <?= htmlspecialchars($file['uploader_name'] ?? '') ?> &nbsp;•&nbsp;
                                            <?= date('M d, Y g:i A', strtotime($file['created_at'])) ?>
                                        </div>
                                        <?php if (!empty($file['note'])): ?>
                                            <span class="file-note"><i class="fas fa-sticky-note"></i>
                                                <?= htmlspecialchars($file['note']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$viewOnly): ?>
                                        <button type="button" class="btn-delete"
                                            onclick="confirmDelete(<?= $file['id'] ?>, '<?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?>', '<?= $redirectBase ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <!-- Row 2: View + Download buttons -->
                                <div style="display:flex; gap:8px; width:100%;">
                                    <?php $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']; ?>
                                    <?php if ($isImage || in_array($ext, $imageExts) || $ext === 'pdf'): ?>
                                        <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" class="btn-view"
                                            style="flex:1; text-align:center; padding:8px;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($filePath) ?>"
                                        download="<?= htmlspecialchars($file['file_name']) ?>" class="btn-view"
                                        style="flex:1; text-align:center; padding:8px; background:#dcfce7; color:#166534;">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div style="height:16px;"></div>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <?php if ($canUpload): ?>
                        <div id="form-<?= $typeKey ?>">

                            <div class="upload-zone" id="zone-<?= $typeKey ?>">
                                <i class="fas fa-cloud-upload-alt" style="color:<?= $tabInfo['color'] ?>;"></i>
                                <p style="font-size:14px; font-weight:600; color:#374151;">Click or drag files here</p>
                                <p class="hint" id="hint-<?= $typeKey ?>">Images &amp; documents only — no videos &nbsp;•&nbsp;
                                    Max <?= $maxFiles - $fileCount ?> more &nbsp;•&nbsp; Max 50MB (Direct) or 1.3GB (Chunked)
                                </p>
                                <p class="file-count-text" id="count-<?= $typeKey ?>"></p>
                                <input type="file" multiple id="input-<?= $typeKey ?>"
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" style="display:none;"
                                    onclick="event.stopPropagation()" onchange="autoSuggestAttachMode('<?= $typeKey ?>', this)">
                            </div>

                            <!-- Upload mode toggle -->
                            <div class="upload-mode-toggle">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <i class="fas fa-bolt" style="color:#1e40af;"></i>
                                    <span>Upload Mode:</span>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="mode-toggle-<?= $typeKey ?>"
                                        onchange="onAttachModeChange('<?= $typeKey ?>')">
                                    <span class="toggle-slider"></span>
                                </label>
                                <div id="mode-label-<?= $typeKey ?>">
                                    <span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
                                    <span style="font-size:11px; color:#9ca3af; margin-left:4px;">Best for files under 50MB ·
                                        faster, no 405 errors</span>
                                </div>
                            </div>

                            <div style="margin-bottom:14px;">
                                <label
                                    style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:6px;">
                                    <i class="fas fa-sticky-note"></i> Note (optional — applies to all files in this upload)
                                </label>
                                <textarea id="note-<?= $typeKey ?>" class="note-input" rows="2"
                                    placeholder="e.g. Taken during initial site visit, north side..."></textarea>
                            </div>

                            <!-- Progress bar (hidden until upload starts) -->
                            <div id="progress-wrap-<?= $typeKey ?>" style="display:none; margin-bottom:14px;">
                                <div
                                    style="display:flex; justify-content:space-between; font-size:12px; color:#374151; margin-bottom:5px;">
                                    <span id="progress-label-<?= $typeKey ?>">Uploading...</span>
                                    <span id="progress-pct-<?= $typeKey ?>">0%</span>
                                </div>
                                <div style="height:7px; background:#e9ecef; border-radius:99px; overflow:hidden;">
                                    <div id="progress-bar-<?= $typeKey ?>"
                                        style="height:100%; width:0%; border-radius:99px; transition:width .2s; background:linear-gradient(90deg, <?= $tabInfo['color'] ?>, <?= $tabInfo['color'] ?>99);">
                                    </div>
                                </div>
                                <div id="progress-sub-<?= $typeKey ?>" style="font-size:11px; color:#9ca3af; margin-top:4px;">
                                </div>
                            </div>

                            <div id="upload-error-<?= $typeKey ?>"
                                style="display:none; background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:10px;">
                            </div>

                            <?php if (!$viewOnly): ?>
                                <button type="button" id="btn-upload-<?= $typeKey ?>" onclick="startAttachUpload('<?= $typeKey ?>')"
                                    style="background:linear-gradient(135deg, <?= $tabInfo['color'] ?>, <?= $tabInfo['color'] ?>dd); color:white; padding:11px 24px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:8px; margin-top:4px;">
                                    <i class="fas fa-upload"></i> Upload Files
                                </button>
                            <?php else: ?>
                                <div
                                    style="background:#f3f4f6; border-radius:8px; padding:12px 16px; font-size:13px; color:#6b7280; display:inline-flex; align-items:center; gap:8px;">
                                    <i class="fas fa-eye"></i> View only — you cannot upload files
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="max-reached">
                            <i class="fas fa-exclamation-triangle"></i>
                            Maximum of <?= $maxFiles ?> files reached for this section. Delete a file to upload more.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

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
        $panelBg = '#d1fae5';
        $panelBorder = '#10b981';
        $panelColor = '#065f46';
        $panelLabel = 'All Approved';
        $panelIcon = 'fa-check-circle';
    } elseif ($anyRejected) {
        $panelBg = '#fee2e2';
        $panelBorder = '#ef4444';
        $panelColor = '#991b1b';
        $panelLabel = 'Has Rejection(s)';
        $panelIcon = 'fa-times-circle';
    } elseif ($approvalRequested) {
        $panelBg = '#dbeafe';
        $panelBorder = '#3b82f6';
        $panelColor = '#1e40af';
        $panelLabel = 'Pending Review';
        $panelIcon = 'fa-hourglass-half';
    } else {
        $panelBg = '#f3f4f6';
        $panelBorder = '#d1d5db';
        $panelColor = '#374151';
        $panelLabel = 'Not Requested';
        $panelIcon = 'fa-circle';
    }
    ?>
    <div style="max-width:800px; margin:20px auto 0 auto; padding:0 20px;">
        <div
            style="background:white; border:2px solid <?= $panelBorder ?>; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.07);">
            <!-- Panel Header Bar -->
            <div
                style="background:<?= $panelBg ?>; border-bottom:2px solid <?= $panelBorder ?>33; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas <?= $panelIcon ?>" style="color:<?= $panelColor ?>; font-size:18px;"></i>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:<?= $panelColor ?>; line-height:1.3;">
                            Approval Status — <span
                                style="font-weight:800;"><?= htmlspecialchars($locationLabel) ?></span>
                        </div>
                        <div style="font-size:11px; color:<?= $panelColor ?>; opacity:0.7; margin-top:1px;">
                            <?= $approvalRequested ? '● Approval has been requested' : '○ No approval request yet' ?>
                        </div>
                    </div>
                </div>

                <?php if ($hasActiveRevision && $revisionStatus === 'pending'): ?>
                    <!-- Revision pending — designer needs to resubmit -->
                    <div
                        style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; align-items:flex-start; gap:12px; width:100%;">
                        <i class="fas fa-redo" style="color:#d97706; font-size:18px; margin-top:2px; flex-shrink:0;"></i>
                        <div style="flex:1;">
                            <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px;">
                                Revision #<?= $activeRevision['revision_number'] ?> Requested
                                <span
                                    style="background:#f59e0b; color:white; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px; font-weight:700;">Awaiting
                                    Resubmission</span>
                                <span style="font-size:11px; font-weight:400; margin-left:8px; color:#b45309;">
                                    <?= date('M d, Y g:i A', strtotime($activeRevision['created_at'])) ?>
                                </span>
                            </div>
                            <div style="font-size:12px; color:#78350f; margin-bottom:8px;">
                                <?= nl2br(htmlspecialchars($activeRevision['reason'])) ?>
                            </div>
                            <?php if ($canRequestApproval && $hasAnyFile): ?>
                                <form method="POST" action="<?= BASE_URL ?>request-layout-approval" style="margin-top:4px;">
                                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                    <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                                    <input type="hidden" name="redirect_url"
                                        value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                    <button type="submit"
                                        style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                                        <i class="fas fa-paper-plane"></i> Submit Revised & Request Approval
                                    </button>
                                </form>
                            <?php elseif ($canRequestApproval && !$hasAnyFile): ?>
                                <div style="margin-top:6px; font-size:12px; color:#9ca3af; font-style:italic;">
                                    <i class="fas fa-upload"></i> Upload your revised files above first, then request approval.
                                </div>
                            <?php elseif ($isApprover): ?>
                                <div style="margin-top:6px; font-size:12px; color:#92400e; font-style:italic;">
                                    <i class="fas fa-clock"></i> Waiting for designer to upload revised files and resubmit.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($hasActiveRevision && $revisionStatus === 'designer_resubmitted'): ?>
                    <!-- Revision resubmitted — approvers can now act -->
                    <div
                        style="background:#dbeafe; border:2px solid #3b82f6; border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; align-items:flex-start; gap:12px; width:100%;">
                        <i class="fas fa-paper-plane"
                            style="color:#2563eb; font-size:18px; margin-top:2px; flex-shrink:0;"></i>
                        <div style="flex:1;">
                            <div style="font-size:13px; font-weight:700; color:#1e40af; margin-bottom:4px;">
                                Revision #<?= $activeRevision['revision_number'] ?> — Revised Design Submitted
                                <span
                                    style="background:#3b82f6; color:white; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px; font-weight:700;">Awaiting
                                    Approval</span>
                                <span style="font-size:11px; font-weight:400; margin-left:8px; color:#1d4ed8;">
                                    <?= date('M d, Y g:i A', strtotime($activeRevision['created_at'])) ?>
                                </span>
                            </div>
                            <div style="font-size:12px; color:#1e3a8a;">
                                <?= nl2br(htmlspecialchars($activeRevision['reason'])) ?>
                            </div>
                            <?php if ($canRequestApproval): ?>
                                <?php if ($anyRejected): ?>
                                    <form method="POST" action="<?= BASE_URL ?>request-layout-approval" style="margin-top:8px;">
                                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                        <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                                        <input type="hidden" name="redirect_url"
                                            value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                        <input type="hidden" name="resubmit" value="1">
                                        <button type="submit"
                                            style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                                            <i class="fas fa-redo"></i> Re-request Approval
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div style="margin-top:8px; font-size:12px; color:#1e40af; font-style:italic;">
                                        <i class="fas fa-hourglass-half"></i> Revised design submitted. Waiting for all approvers to
                                        review.
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
                                style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; padding:10px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                                <i class="fas fa-paper-plane"></i> Request Approval
                            </button>
                        </form>
                    <?php elseif (!$approvalRequested && !$hasAnyFile): ?>
                        <span style="font-size:12px; color:#9ca3af; font-style:italic;">Upload files first to request
                            approval</span>
                    <?php elseif ($approvalRequested && $anyRejected): ?>
                        <!-- Re-request only for rejectors -->
                        <form method="POST" action="<?= BASE_URL ?>request-layout-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <input type="hidden" name="resubmit" value="1">
                            <button type="submit"
                                style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:10px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                                <i class="fas fa-redo"></i> Re-request Approval
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- TD Remark Display -->
            <?php if ($approvalRequested): ?>
                <div
                    style="background:#f0f9ff; border-top:1px solid #bfdbfe; border-bottom:1px solid #bfdbfe; padding:14px 20px;">
                    <?php if ($tdRemarkSubmitted): ?>
                        <div style="display:flex; align-items:flex-start; gap:10px;">
                            <i class="fas fa-comment-dots" style="color:#0369a1; margin-top:2px; flex-shrink:0;"></i>
                            <div style="flex:1;">
                                <div style="font-size:12px; font-weight:700; color:#0c4a6e; margin-bottom:5px;">
                                    Technical Designer Remark
                                    <span
                                        style="background:#d1fae5; color:#065f46; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:5px; font-weight:700;">
                                        <i class="fas fa-check"></i> Submitted
                                    </span>
                                    <?php if ($assignedTDName): ?>
                                        <span style="font-size:11px; font-weight:400; color:#6b7280; margin-left:5px;">
                                            by <?= htmlspecialchars($assignedTDName) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div
                                    style="font-size:13px; color:#1e293b; background:white; border:1.5px solid #bfdbfe; padding:10px 13px; border-radius:8px; font-style:italic;">
                                    "<?= htmlspecialchars($tdRemarkText) ?>"
                                </div>
                                <?php if ($tdRemarkFile): ?>
                                    <div
                                        style="margin-top:10px; display:flex; align-items:center; gap:10px; background:#fff7ed; border:1.5px solid #fed7aa; border-radius:8px; padding:10px 13px;">
                                        <i class="fas fa-file-pdf" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                                        <div style="flex:1; min-width:0;">
                                            <div
                                                style="font-size:12px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?= htmlspecialchars($tdRemarkFileName ?: 'TD Remark Attachment') ?>
                                            </div>
                                            <div style="font-size:11px; color:#92400e; margin-top:1px;">PDF from Technical Designer
                                            </div>
                                        </div>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($tdRemarkFile) ?>" target="_blank"
                                            style="background:#dbeafe; color:#1d4ed8; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($tdRemarkFile) ?>"
                                            download="<?= htmlspecialchars($tdRemarkFileName ?: 'td_remark.pdf') ?>"
                                            style="background:#dcfce7; color:#166534; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($assignedTDId): ?>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-clock" style="color:#f59e0b; flex-shrink:0;"></i>
                            <div style="font-size:12px; color:#92400e;">
                                Waiting for <strong><?= htmlspecialchars($assignedTDName) ?></strong> to submit a remark from
                                their TD Attachments page before the <strong>Technical Designer</strong> approver can proceed.
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-user-slash" style="color:#9ca3af; flex-shrink:0;"></i>
                            <div style="font-size:12px; color:#6b7280;">
                                No Technical Designer assigned yet. A TD must be assigned and submit a remark before approval
                                can proceed.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Per-approver status rows -->
            <div style="padding:18px 22px; display:flex; flex-direction:column; gap:8px;">
                <?php foreach ($approvers as $apr):
                    $rec = $approvalMap[$apr['id']] ?? null;
                    $aStatus = $rec ? $rec['status'] : 'not_requested';

                    if ($aStatus === 'approved') {
                        $aBg = '#d1fae5';
                        $aBorder = '#10b981';
                        $aColor = '#065f46';
                        $aIcon = 'fa-check-circle';
                        $aLabel = 'Approved';
                    } elseif ($aStatus === 'rejected') {
                        $aBg = '#fee2e2';
                        $aBorder = '#ef4444';
                        $aColor = '#991b1b';
                        $aIcon = 'fa-times-circle';
                        $aLabel = 'Rejected';
                    } elseif ($aStatus === 'pending') {
                        $aBg = '#fef3c7';
                        $aBorder = '#f59e0b';
                        $aColor = '#92400e';
                        $aIcon = 'fa-hourglass-half';
                        $aLabel = 'Pending';
                    } else {
                        $aBg = '#f3f4f6';
                        $aBorder = '#d1d5db';
                        $aColor = '#6b7280';
                        $aIcon = 'fa-minus-circle';
                        $aLabel = 'Not Requested';
                    }

                    // Can this approver act? Only if status is pending AND it's them
                    // Approvers can act when:
                    // - their approval is pending AND
                    // - either no active revision, OR revision is designer_resubmitted (designer already uploaded revised)
                    $isHeadTD = ($apr['role'] === 'technical_designer' && ($apr['is_head'] ?? 0) == 1);
                    $canAct = ($isApprover && $apr['id'] == $admin_id && $aStatus === 'pending')
                        && (!$hasActiveRevision || $revisionStatus === 'designer_resubmitted')
                        && (!$isHeadTD || $tdRemarkSubmitted);
                    ?>
                    <div
                        style="background:<?= $aBg ?>; border:1.5px solid <?= $aBorder ?>44; border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
                        <!-- Left: Icon + Info -->
                        <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                            <div
                                style="width:36px; height:36px; background:<?= $aBorder ?>22; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; border:1.5px solid <?= $aBorder ?>55;">
                                <i class="fas <?= $aIcon ?>" style="color:<?= $aColor ?>; font-size:14px;"></i>
                            </div>
                            <div style="min-width:0;">
                                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span
                                        style="font-weight:700; font-size:13px; color:#1f2937;"><?= htmlspecialchars($apr['full_name']) ?></span>
                                    <span
                                        style="font-size:10px; background:<?= $aBorder ?>22; color:<?= $aColor ?>; padding:2px 8px; border-radius:20px; font-weight:600; text-transform:capitalize; letter-spacing:0.3px;">
                                        <?= str_replace('_', ' ', $apr['role']) ?>
                                    </span>
                                </div>
                                <?php if ($rec && $rec['responded_at']): ?>
                                    <div
                                        style="font-size:11px; color:#9ca3af; margin-top:3px; display:flex; align-items:center; gap:4px;">
                                        <i class="fas fa-clock" style="font-size:9px;"></i>
                                        <?= date('M d, Y · g:i A', strtotime($rec['responded_at'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($rec && $rec['comment']): ?>
                                    <div
                                        style="font-size:11px; color:#92400e; background:#fffbeb; border:1px solid #fcd34d; padding:4px 10px; border-radius:6px; margin-top:6px; display:inline-flex; align-items:center; gap:5px;">
                                        <i class="fas fa-comment-alt" style="font-size:10px;"></i>
                                        <?= htmlspecialchars($rec['comment']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right: Status or Action Buttons -->
                        <?php if ($canAct): ?>
                            <div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>, '<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>', 'approved')"
                                    style="background:#10b981; color:white; border:none; padding:7px 16px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 4px rgba(16,185,129,0.3);">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>, '<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>', 'rejected')"
                                    style="background:#ef4444; color:white; border:none; padding:7px 16px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 4px rgba(239,68,68,0.3);">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
                                <span
                                    style="font-size:11px; font-weight:700; color:<?= $aColor ?>; background:<?= $aBorder ?>22; padding:4px 10px; border-radius:20px; border:1px solid <?= $aBorder ?>55; white-space:nowrap;">
                                    <?= $aLabel ?>
                                </span>
                                <?php if ($aStatus === 'pending' && !$tdRemarkSubmitted && $approvalRequested && $apr['role'] === 'technical_designer' && ($apr['is_head'] ?? 0) == 1): ?>
                                    <span style="font-size:10px; color:#d97706; font-style:italic; white-space:nowrap;">
                                        <i class="fas fa-clock"></i> Awaiting TD remark
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div><!-- end approver rows padding -->
        </div><!-- end panel wrapper -->
    </div><!-- end constrained container -->

    <!-- Approve/Reject Modal -->
    <div id="approveModal"
        style="display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:white; border-radius:12px; padding:28px; max-width:480px; width:90%;">
            <h3 id="approveModalTitle" style="font-size:17px; font-weight:700; color:#1f2937; margin-bottom:16px;"></h3>
            <?php if ($tdRemarkSubmitted): ?>
                <div
                    style="background:#f0f9ff; border:1.5px solid #bfdbfe; border-radius:8px; padding:10px 13px; margin-bottom:14px; font-size:12px;">
                    <div style="font-weight:700; color:#0c4a6e; margin-bottom:3px;">
                        <i class="fas fa-comment-dots"></i> TD Remark
                        <?php if ($assignedTDName): ?>
                            <span style="font-weight:400; color:#6b7280; margin-left:4px;">by
                                <?= htmlspecialchars($assignedTDName) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="color:#1f2937; font-style:italic;">"<?= htmlspecialchars($tdRemarkText) ?>"</div>
                </div>
            <?php endif; ?>
            <textarea id="approveComment" placeholder="Comment (required for rejection, optional for approval)..."
                style="width:100%; padding:10px; border:2px solid #e9ecef; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; min-height:90px; margin-bottom:16px;"></textarea>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button onclick="closeApproveModal()"
                    style="background:#6b7280; color:white; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-weight:600;">Cancel</button>
                <button id="approveConfirmBtn" onclick="submitApproval()"
                    style="padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-weight:600; color:white;"></button>
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
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
            zone.addEventListener('drop', e => {
                e.preventDefault();
                zone.classList.remove('drag-over');
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
            el.style.display = 'block';
            setTimeout(() => { el.style.display = 'none'; }, 5000);
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
                label.innerHTML = `<span class="mode-badge chunked"><i class="fas fa-layer-group"></i> Chunked</span>
            <span style="font-size:11px;color:#9ca3af;margin-left:4px;">For large files up to 1.3GB · slower start</span>`;
                if (hint) hint.innerHTML = 'Images &amp; documents only — no videos &nbsp;•&nbsp; Max 1.3GB each (Chunked mode)';
            } else {
                label.innerHTML = `<span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
            <span style="font-size:11px;color:#9ca3af;margin-left:4px;">Best for files under 50MB · faster, no 405 errors</span>`;
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
                errEl.style.display = 'block';
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
                    errEl.style.display = 'block';
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
            errEl.style.display = 'none';

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
    <div id="itemsModal"
        style="display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.55); align-items:center; justify-content:center;">
        <div
            style="background:white; border-radius:14px; width:90%; max-width:640px; max-height:88vh; overflow:hidden; display:flex; flex-direction:column;">

            <!-- Modal Header -->
            <div
                style="background:linear-gradient(135deg,#3730a3,#6366f1); padding:18px 22px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
                <div>
                    <h3 id="itemsModalTitle" style="font-size:16px; font-weight:700; color:white; margin-bottom:3px;">
                        <i class="fas fa-boxes"></i> Items
                    </h3>
                    <p id="itemsModalSub" style="font-size:11px; color:rgba(255,255,255,0.8);"></p>
                </div>
                <button onclick="closeItemsModal()"
                    style="background:rgba(255,255,255,0.2); border:none; color:white; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div id="itemsModalBody" style="overflow-y:auto; padding:18px; flex:1;">
                <div style="text-align:center; padding:30px; color:#9ca3af;">
                    <i class="fas fa-spinner fa-spin" style="font-size:28px;"></i>
                    <p style="margin-top:10px;">Loading items...</p>
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

                html += '<div style="border:1px solid #e0e7ff; border-radius:10px; overflow:hidden;">';

                // Item row
                html += '<div style="display:flex; gap:12px; padding:13px; align-items:center; background:#fafbff;">';

                // Image
                if (imgPath) {
                    html += '<img src="' + imgPath + '" style="width:50px;height:50px;object-fit:cover;border-radius:8px;border:1px solid #e0e7ff;flex-shrink:0;" onerror="this.style.display=\'none\'">';
                } else {
                    html += '<div style="width:50px;height:50px;background:#e0e7ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-box" style="color:#818cf8;font-size:18px;"></i></div>';
                }

                // Info
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-weight:700;font-size:13px;color:#1e1b4b;">' + escItemHtml(item.item_name) + '</div>';
                if (item.display_color) {
                    html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;"><i class="fas fa-palette"></i> ' + escItemHtml(item.display_color) + '</div>';
                }

                // Dimensions
                let dims = [];
                if (item.width) dims.push((item.width_label || 'W') + ': ' + item.width + 'mm');
                if (item.height) dims.push((item.height_label || 'H') + ': ' + item.height + 'mm');
                if (item.length) dims.push((item.length_label || 'L') + ': ' + item.length + 'mm');
                if (dims.length) {
                    html += '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + dims.join(' &nbsp;•&nbsp; ') + '</div>';
                }

                // Notes
                if (item.notes && item.notes.trim()) {
                    html += '<div style="font-size:11px;color:#92400e;background:#fffbeb;padding:2px 8px;border-radius:4px;margin-top:4px;"><i class="fas fa-sticky-note"></i> ' + escItemHtml(item.notes) + '</div>';
                }
                html += '</div>';

                // Right side: qty + type badge
                html += '<div style="flex-shrink:0;text-align:center;">';
                html += '<div style="background:#e0e7ff;color:#3730a3;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;">' + item.quantity + ' pcs</div>';
                html += '<div style="font-size:10px;color:#9ca3af;margin-top:3px;">' + (item.entry_type === 'customized' ? 'Custom' : 'Fixed') + '</div>';
                html += '</div>';

                html += '</div>'; // end item row

                // Addons toggle
                if (item.addons && item.addons.length > 0) {
                    html += '<div style="border-top:1px solid #e0e7ff;background:#f0f4ff;">';
                    html += '<button type="button" onclick="toggleItemAddon(\'' + addonBodyId + '\',\'' + addonIconId + '\')" ';
                    html += 'style="width:100%;padding:7px 14px;background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#3730a3;">';
                    html += '<i class="fas fa-puzzle-piece"></i> ' + item.addons.length + ' Add-on' + (item.addons.length > 1 ? 's' : '');
                    html += '<i id="' + addonIconId + '" class="fas fa-chevron-down" style="margin-left:auto;transition:transform 0.2s;"></i>';
                    html += '</button>';

                    html += '<div id="' + addonBodyId + '" style="display:none;">';
                    item.addons.forEach(function (addon, ai) {
                        const border = ai > 0 ? 'border-top:1px solid #dde3ff;' : '';
                        html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;' + border + '">';
                        if (addon.addon_image_path) {
                            html += '<img src=<?= CLIENT_ASSET ?>/images/product_addons/' + escItemHtml(addon.addon_image_path) + '" style="width:32px;height:32px;object-fit:cover;border-radius:6px;border:1px solid #c7d2fe;flex-shrink:0;" onerror="this.style.display=\'none\'">';
                        } else {
                            html += '<div style="width:32px;height:32px;background:#dde3ff;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-puzzle-piece" style="color:#818cf8;font-size:12px;"></i></div>';
                        }
                        html += '<div style="flex:1;">';
                        html += '<div style="font-size:12px;font-weight:700;color:#1e1b4b;">' + escItemHtml(addon.addon_name) + '</div>';
                        html += '<div style="font-size:11px;color:#4f46e5;">₱' + parseFloat(addon.price).toFixed(2) + ' / pc</div>';
                        if (addon.note) html += '<div style="font-size:10px;color:#64748b;font-style:italic;">' + escItemHtml(addon.note) + '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div></div>'; // close addon body + section
                }

                html += '</div>'; // end card
            });

            html += '</div>';

            // Footer summary
            html += '<div style="margin-top:14px;padding:14px 16px;background:linear-gradient(135deg,#3730a3,#6366f1);border-radius:10px;display:flex;justify-content:space-between;align-items:center;color:white;">';
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