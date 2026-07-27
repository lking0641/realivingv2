<?php
// td_attachment_upload.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area = isset($_GET['area']) ? trim($_GET['area']) : '';
$room_unit_number = isset($_GET['room_unit_number']) && $_GET['room_unit_number'] !== '' ? intval($_GET['room_unit_number']) : null;
$room_unit_name = isset($_GET['room_unit_name']) ? trim($_GET['room_unit_name']) : '';

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

$hasUnit = $room_unit_number !== null;
$locationLabel = $hasUnit ? ($room_unit_name ?: 'Unit ' . $room_unit_number) : $area;
$backUrl = $hasUnit
    ? BASE_URL . 'td-attachment-area?client_id=' . $client_id . '&area=' . urlencode($area)
    : BASE_URL . 'td-attachments?client_id=' . $client_id;

// ── Approval helpers ───────────────────────────────────────────────────────
function tdGetApprovers($conn)
{
    $s = $conn->prepare("SELECT id, full_name, role FROM account WHERE (role IN ('general_manager','operational_manager')) OR (role = 'technical_designer' AND is_head = 1) ORDER BY role");
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

function tdGetApprovalStatus($conn, $client_id, $area, $room_unit_number)
{
    if ($room_unit_number !== null) {
        $s = $conn->prepare("SELECT la.*, a.full_name as approver_name, a.role as approver_role FROM td_attachment_approvals la JOIN account a ON la.approver_id=a.id WHERE la.client_id=? AND la.area=? AND la.room_unit_number=?");
        $s->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $s = $conn->prepare("SELECT la.*, a.full_name as approver_name, a.role as approver_role FROM td_attachment_approvals la JOIN account a ON la.approver_id=a.id WHERE la.client_id=? AND la.area=? AND la.room_unit_number IS NULL");
        $s->bind_param("is", $client_id, $area);
    }
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

$approvers = tdGetApprovers($conn);
$approvalRecords = tdGetApprovalStatus($conn, $client_id, $area, $room_unit_number);
$approvalMap = [];
foreach ($approvalRecords as $rec)
    $approvalMap[$rec['approver_id']] = $rec;

$approvalRequested = !empty($approvalRecords);
$allApproved = $approvalRequested && count($approvers) > 0 &&
    count(array_filter($approvalRecords, fn($r) => $r['status'] === 'approved')) === count($approvers);
$anyRejected = !empty(array_filter($approvalRecords, fn($r) => $r['status'] === 'rejected'));

// Active revision
if ($room_unit_number !== null) {
    $revS = $conn->prepare("SELECT id, revision_number, reason, status, created_at FROM td_revision_log WHERE client_id=? AND area=? AND room_unit_number=? AND status IN ('pending','designer_resubmitted') ORDER BY created_at DESC LIMIT 1");
    $revS->bind_param("isi", $client_id, $area, $room_unit_number);
} else {
    $revS = $conn->prepare("SELECT id, revision_number, reason, status, created_at FROM td_revision_log WHERE client_id=? AND area=? AND room_unit_number IS NULL AND status IN ('pending','designer_resubmitted') ORDER BY created_at DESC LIMIT 1");
    $revS->bind_param("is", $client_id, $area);
}
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
function tdGetFiles($conn, $client_id, $area, $room_unit_number)
{
    if ($room_unit_number !== null) {
        $s = $conn->prepare("SELECT ta.*, a.full_name as uploader_name FROM td_attachments ta LEFT JOIN account a ON ta.uploaded_by=a.id WHERE ta.client_id=? AND ta.area=? AND ta.room_unit_number=? ORDER BY ta.category_name, ta.created_at DESC");
        $s->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $s = $conn->prepare("SELECT ta.*, a.full_name as uploader_name FROM td_attachments ta LEFT JOIN account a ON ta.uploaded_by=a.id WHERE ta.client_id=? AND ta.area=? AND ta.room_unit_number IS NULL ORDER BY ta.category_name, ta.created_at DESC");
        $s->bind_param("is", $client_id, $area);
    }
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

function desGetFiles($conn, $client_id, $area, $room_unit_number)
{
    if ($room_unit_number !== null) {
        $s = $conn->prepare("SELECT la.*, a.full_name as uploader_name FROM layout_attachments la LEFT JOIN account a ON la.uploaded_by=a.id WHERE la.client_id=? AND la.area=? AND la.room_unit_number=? ORDER BY la.attachment_type, la.created_at ASC");
        $s->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $s = $conn->prepare("SELECT la.*, a.full_name as uploader_name FROM layout_attachments la LEFT JOIN account a ON la.uploaded_by=a.id WHERE la.client_id=? AND la.area=? ORDER BY la.attachment_type, la.created_at ASC");
        $s->bind_param("is", $client_id, $area);
    }
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

$tdFiles = tdGetFiles($conn, $client_id, $area, $room_unit_number);
$designerFiles = desGetFiles($conn, $client_id, $area, $room_unit_number);
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

if ($allApproved) {
    $panelBg = '#f0fdf4';
    $panelBorder = '#86efac';
    $panelColor = '#166534';
    $panelLabel = 'All Approved';
    $panelIcon = 'fa-check-circle';
} elseif ($anyRejected) {
    $panelBg = '#fff5f5';
    $panelBorder = '#fca5a5';
    $panelColor = '#991b1b';
    $panelLabel = 'Has Rejection(s)';
    $panelIcon = 'fa-times-circle';
} elseif ($approvalRequested) {
    $panelBg = '#eff6ff';
    $panelBorder = '#93c5fd';
    $panelColor = '#1e40af';
    $panelLabel = 'Pending Review';
    $panelIcon = 'fa-hourglass-half';
} else {
    $panelBg = '#f8fafc';
    $panelBorder = '#e2e8f0';
    $panelColor = '#475569';
    $panelLabel = 'Not Requested';
    $panelIcon = 'fa-circle';
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$redirectBase = BASE_URL . 'td-attachment-upload?client_id=' . $client_id . '&area=' . urlencode($area)
    . ($hasUnit ? '&room_unit_number=' . $room_unit_number . '&room_unit_name=' . urlencode($room_unit_name) : '');
$iconMap = ['pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'zip' => 'fa-file-archive', 'txt' => 'fa-file-alt', 'dwg' => 'fa-drafting-compass', 'dxf' => 'fa-drafting-compass'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Upload — <?= htmlspecialchars($locationLabel) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f4f8;
            font-family: 'Segoe UI', sans-serif;
            color: #1e293b;
        }

        .page-wrap {
            max-width: 880px;
            margin: 28px auto;
            padding: 0 20px 60px;
        }

        .btn-back {
            background: white;
            color: #0c4a6e;
            border: 1.5px solid #bfdbfe;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            transition: all 0.15s;
        }

        .btn-back:hover {
            background: #0c4a6e;
            color: white;
            border-color: #0c4a6e;
        }

        .page-header {
            background: linear-gradient(135deg, #0c4a6e, #0369a1);
            padding: 22px 28px;
            border-radius: 14px;
            color: white;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .page-header .hicon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .page-header h1 {
            font-size: 19px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .page-header .sub {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 1px;
        }

        .alert {
            padding: 11px 16px;
            border-radius: 9px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* ── PANEL ── */
        .panel {
            background: white;
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
            margin-bottom: 22px;
            overflow: hidden;
            border: 1px solid #f1f5f9;
        }

        .panel-head {
            padding: 15px 22px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            font-size: 14px;
            font-weight: 700;
            color: #0c4a6e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── TABS (pill row) ── */
        .tab-bar {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding: 14px 22px 0;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 14px;
        }

        .tab-pill {
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1.5px solid #e2e8f0;
            background: white;
            color: #64748b;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            user-select: none;
        }

        .tab-pill:hover {
            border-color: #0369a1;
            color: #0369a1;
            background: #eff6ff;
        }

        .tab-pill.active {
            background: #0c4a6e;
            color: white;
            border-color: #0c4a6e;
        }

        .tab-pill .cnt {
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 10px;
        }

        .tab-pill.active .cnt {
            background: rgba(255, 255, 255, 0.25);
        }

        .tab-pill:not(.active) .cnt {
            background: #f1f5f9;
            color: #64748b;
        }

        .tab-pill.upload-pill {
            border-color: #0369a1;
            color: #0369a1;
        }

        .tab-pill.upload-pill:hover,
        .tab-pill.upload-pill.active {
            background: #0c4a6e;
            color: white;
            border-color: #0c4a6e;
        }

        /* ── PANES ── */
        .tab-pane {
            display: none;
            padding: 20px 22px;
        }

        .tab-pane.active {
            display: block;
        }

        /* ── FILE ROW ── */
        .file-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1.5px solid #f1f5f9;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fafcff;
            transition: border-color 0.15s;
        }

        .file-row:hover {
            border-color: #bfdbfe;
            background: #f0f9ff;
        }

        .file-row:last-child {
            margin-bottom: 0;
        }

        .f-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 8px;
            flex-shrink: 0;
            border: 1px solid #e0e7ff;
        }

        .f-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .f-info {
            flex: 1;
            min-width: 0;
        }

        .f-name {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .f-meta {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .f-note {
            font-size: 11px;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 2px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        .btn-view {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1.5px solid #bfdbfe;
            border-radius: 7px;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
            white-space: nowrap;
            transition: all 0.15s;
        }

        .btn-view:hover {
            background: #1d4ed8;
            color: white;
            border-color: #1d4ed8;
        }

        .btn-del {
            background: white;
            color: #ef4444;
            border: 1.5px solid #fecaca;
            border-radius: 7px;
            padding: 6px 10px;
            font-size: 11px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
            transition: all 0.15s;
        }

        .btn-del:hover {
            background: #ef4444;
            color: white;
            border-color: #ef4444;
        }

        /* ── UPLOAD FORM ── */
        .upload-box {
            background: #f8faff;
            border: 1.5px dashed #bfdbfe;
            border-radius: 12px;
            padding: 22px;
        }

        .form-label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 7px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-ctrl {
            width: 100%;
            padding: 9px 13px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            color: #1e293b;
            font-family: inherit;
            background: white;
            transition: border-color 0.2s;
        }

        .form-ctrl:focus {
            outline: none;
            border-color: #0369a1;
        }

        .drop-zone {
            border: 2px dashed #bfdbfe;
            border-radius: 10px;
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            background: white;
            transition: all 0.2s;
            margin-bottom: 4px;
        }

        .drop-zone:hover,
        .drop-zone.drag-over {
            border-color: #0369a1;
            background: #eff6ff;
        }

        .drop-zone i.dz-icon {
            font-size: 28px;
            color: #0369a1;
            display: block;
            margin-bottom: 8px;
        }

        .btn-upload {
            background: linear-gradient(135deg, #0c4a6e, #0369a1);
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 9px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.15s;
        }

        .btn-upload:hover {
            opacity: 0.88;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 36px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 30px;
            margin-bottom: 10px;
            display: block;
            opacity: 0.4;
        }

        .empty-state p {
            font-size: 13px;
        }

        /* ── APPROVAL ── */
        .appr-panel {
            border-radius: 14px;
            border: 2px solid
                <?= $panelBorder ?>
            ;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .appr-head {
            background:
                <?= $panelBg ?>
            ;
            padding: 15px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid
                <?= $panelBorder ?>
                55;
        }

        .appr-title {
            font-size: 14px;
            font-weight: 700;
            color:
                <?= $panelColor ?>
            ;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .appr-rows {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: white;
        }

        .appr-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1.5px solid;
        }

        input[list]::-webkit-calendar-picker-indicator {
            display: none;
        }

        /* Upload mode toggle */
        .upload-mode-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            padding: 8px 14px;
            margin-bottom: 14px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
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
            background: #0c4a6e;
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
    </style>
</head>

<body>
    <div class="page-wrap">

        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>

        <!-- Header -->
        <div class="page-header">
            <div class="hicon"><i class="fas fa-tools" style="font-size:22px;"></i></div>
            <div>
                <h1><?= htmlspecialchars($locationLabel) ?></h1>
                <div class="sub"><i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars($area) ?><?php if ($hasUnit): ?> &nbsp;›&nbsp;
                        <?= htmlspecialchars($locationLabel) ?><?php endif; ?>
                </div>
                <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> —
                    <?= htmlspecialchars($clientInfo['nameproject']) ?>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>


        <!-- ════════════════════════════════════════════
         DESIGNER REFERENCE FILES
    ═════════════════════════════════════════════ -->
        <?php if (!empty($designerFiles)): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title" style="color:#475569;">
                        <i class="fas fa-images"></i> Designer Reference Files
                        <span style="font-size:11px; font-weight:400; color:#94a3b8;">read only</span>
                    </div>
                    <span style="font-size:11px; color:#94a3b8;"><?= count($designerFiles) ?>
                        file<?= count($designerFiles) !== 1 ? 's' : '' ?></span>
                </div>

                <!-- Tab bar -->
                <div class="tab-bar" id="desTabBar">
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
                <?php $i = 0;
                foreach ($desByType as $typeKey => $dFiles):
                    $ti = $desTypeLabels[$typeKey] ?? ['label' => ucwords(str_replace('_', ' ', $typeKey)), 'icon' => 'fa-file', 'color' => '#6b7280', 'bg' => '#f3f4f6'];
                    ?>
                    <div class="tab-pane <?= $i === 0 ? 'active' : '' ?>"
                        id="des-<?= htmlspecialchars($typeKey, ENT_QUOTES) ?>">
                        <?php foreach ($dFiles as $df):
                            $isImg = strpos($df['file_type'], 'image/') === 0;
                            $fp = BASE_URL . 'uploads/layout_attachments/' . $df['file_path'];
                            $ext = strtolower(pathinfo($df['file_name'], PATHINFO_EXTENSION));
                            $fi = $iconMap[$ext] ?? 'fa-file';
                            ?>
                            <div class="file-row">
                                <?php if ($isImg): ?>
                                    <img src="<?= htmlspecialchars($fp) ?>" class="f-thumb" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="f-icon" style="background:<?= $ti['bg'] ?>;"><i class="fas <?= $fi ?>"
                                            style="color:<?= $ti['color'] ?>; font-size:18px;"></i></div>
                                <?php endif; ?>
                                <div class="f-info">
                                    <div class="f-name"><?= htmlspecialchars($df['file_name']) ?></div>
                                    <div class="f-meta"><?= round($df['file_size'] / 1024, 1) ?> KB &nbsp;·&nbsp;
                                        <?= htmlspecialchars($df['uploader_name'] ?? '') ?> &nbsp;·&nbsp;
                                        <?= date('M d, Y', strtotime($df['created_at'])) ?>
                                    </div>
                                    <?php if (!empty($df['note'])): ?>
                                        <div class="f-note"><?= htmlspecialchars($df['note']) ?></div><?php endif; ?>
                                </div>
                                <?php
                                $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                $isViewable = $isImg || in_array($ext, $imageExts) || $ext === 'pdf';
                                ?>
                                <?php if ($isViewable): ?>
                                    <a href="<?= htmlspecialchars($fp) ?>" target="_blank" class="btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($df['file_name']) ?>"
                                        class="btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac; margin-left:4px;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($df['file_name']) ?>"
                                        class="btn-view" style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php $i++; endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
         REMARK ON DESIGNER FILES
    ═════════════════════════════════════════════ -->
        <?php
        // Check if approval has been requested by the designer for this area/unit
        $desApprovalCheckStmt = null;
        if ($room_unit_number !== null) {
            $desApprovalCheckStmt = $conn->prepare("
            SELECT td_remark, td_remark_submitted_at, td_remark_file, td_remark_file_name
            FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number = ?
            LIMIT 1
        ");
            $desApprovalCheckStmt->bind_param("isi", $client_id, $area, $room_unit_number);
        } else {
            $desApprovalCheckStmt = $conn->prepare("
            SELECT td_remark, td_remark_submitted_at, td_remark_file, td_remark_file_name
            FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
            LIMIT 1
        ");
            $desApprovalCheckStmt->bind_param("is", $client_id, $area);
        }
        $desApprovalCheckStmt->execute();
        $desApprovalRow = $desApprovalCheckStmt->get_result()->fetch_assoc();

        $desApprovalExists = !empty($desApprovalRow);
        $existingTDRemark = $desApprovalRow['td_remark'] ?? '';
        $tdRemarkAlreadySet = !empty($existingTDRemark);
        $existingRemarkFile = $desApprovalRow['td_remark_file'] ?? '';
        $existingRemarkFileName = $desApprovalRow['td_remark_file_name'] ?? '';
        ?>

        <?php if ($desApprovalExists && $isAssigned): ?>
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-title" style="color:#d97706;">
                        <i class="fas fa-comment-medical"></i>
                        Your Remark on Designer Files
                        <?php if ($tdRemarkAlreadySet): ?>
                            <span
                                style="background:#d1fae5; color:#065f46; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px; font-weight:700;">
                                <i class="fas fa-check"></i> Submitted
                            </span>
                        <?php else: ?>
                            <span
                                style="background:#fef3c7; color:#92400e; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px; font-weight:700;">
                                <i class="fas fa-exclamation-triangle"></i> Required
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="padding:18px 22px;">
                    <?php if ($tdRemarkAlreadySet): ?>
                        <!-- Already submitted — show it with edit option -->
                        <div style="background:#f0f9ff; border:1.5px solid #bfdbfe; border-radius:10px; padding:13px 16px; margin-bottom:14px;">
                            <div style="font-size:11px; font-weight:700; color:#0c4a6e; margin-bottom:5px; text-transform:uppercase; letter-spacing:0.4px;">
                                <i class="fas fa-comment-dots"></i> Your submitted remark
                            </div>
                            <div style="font-size:13px; color:#1e293b; font-style:italic;">
                                "<?= htmlspecialchars($existingTDRemark) ?>"
                            </div>
                            <div style="font-size:11px; color:#94a3b8; margin-top:6px;">
                                <i class="fas fa-clock"></i>
                                Submitted on <?= $desApprovalRow['td_remark_submitted_at']
                                    ? date('M d, Y g:i A', strtotime($desApprovalRow['td_remark_submitted_at']))
                                    : '' ?>
                            </div>
                            <?php if ($existingRemarkFile): ?>
                            <div style="margin-top:10px; display:flex; align-items:center; gap:10px; background:white; border:1.5px solid #bfdbfe; border-radius:8px; padding:10px 13px;">
                                <i class="fas fa-file-pdf" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:12px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= htmlspecialchars($existingRemarkFileName ?: 'Remark Attachment') ?>
                                    </div>
                                    <div style="font-size:11px; color:#64748b; margin-top:2px;">PDF Attachment</div>
                                </div>
                                <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>" target="_blank"
                                    style="background:#dbeafe; color:#1d4ed8; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>"
                                    download="<?= htmlspecialchars($existingRemarkFileName ?: 'remark.pdf') ?>"
                                    style="background:#dcfce7; color:#166534; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <button type="button" id="tdEditRemarkBtn"
                            onclick="document.getElementById('tdDesRemarkForm').style.display='block'; this.style.display='none';"
                            style="background:none; border:1.5px solid #bfdbfe; color:#0369a1; padding:7px 14px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:6px; margin-bottom:12px;">
                            <i class="fas fa-edit"></i> Edit Remark
                        </button>

                        <div id="tdDesRemarkForm" style="display:none;">
                    <?php else: ?>
                        <!-- Not yet submitted -->
                        <div
                            style="background:#fffbeb; border:1.5px solid #fcd34d; border-radius:8px; padding:10px 14px; font-size:12px; color:#92400e; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            The designer has requested approval for this area. Please review the designer files above and
                            leave your technical remark. Approvers cannot proceed until your remark is submitted.
                        </div>
                        <div id="tdDesRemarkForm">
                        <?php endif; ?>

                        <form method="POST" action="<?= BASE_URL ?>designer-submit-td-remark" enctype="multipart/form-data">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="room_unit_number" value="<?= $room_unit_number ?? '' ?>">
                            <input type="hidden" name="redirect_url"
                                value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <label
                                style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:7px;">
                                <i class="fas fa-comment-alt"></i> Your Technical Remark
                            </label>
                            <textarea name="td_remark" rows="4" required
                                style="width:100%; padding:10px 13px; border:1.5px solid #fcd34d; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; margin-bottom:10px;"
                                placeholder="Review the designer's uploaded files above and leave your technical assessment, notes, or concerns…"><?= htmlspecialchars($existingTDRemark) ?></textarea>
                            <!-- PDF Attachment -->
                            <div style="margin-bottom:14px;">
                                <label
                                    style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:7px;">
                                    <i class="fas fa-file-pdf" style="color:#dc2626;"></i>
                                    PDF Attachment <span
                                        style="color:#94a3b8; normal-case font-normal; text-transform:none; font-weight:400;">(optional,
                                        max 100MB)</span>
                                </label>
                                <div id="tdRemarkFileZone" onclick="document.getElementById('tdRemarkFileInput').click()"
                                    style="border:2px dashed #fcd34d; border-radius:10px; padding:18px 16px; text-align:center; cursor:pointer; background:#fffbeb; transition:all 0.2s;"
                                    onmouseover="this.style.borderColor='#d97706'; this.style.background='#fef9c3';"
                                    onmouseout="this.style.borderColor='#fcd34d'; this.style.background='#fffbeb';">
                                    <i class="fas fa-file-pdf"
                                        style="font-size:24px; color:#dc2626; display:block; margin-bottom:6px;"></i>
                                    <div id="tdRemarkFileLabel" style="font-size:13px; font-weight:600; color:#92400e;">
                                        <?= $existingRemarkFile ? 'Replace PDF (click to choose)' : 'Click to attach a PDF' ?>
                                    </div>
                                    <div style="font-size:11px; color:#b45309; margin-top:3px;">PDF only · max 100MB</div>
                                </div>
                                <input type="file" id="tdRemarkFileInput" name="td_remark_file"
                                    accept=".pdf,application/pdf" style="display:none;"
                                    onchange="onTdRemarkFileChange(this)">
                                <div id="tdRemarkFilePreview"
                                    style="display:none; margin-top:10px; background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; padding:10px 13px; display:none; align-items:center; gap:10px;">
                                    <i class="fas fa-file-pdf" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                                    <div style="flex:1; min-width:0;">
                                        <div id="tdRemarkFileName"
                                            style="font-size:12px; font-weight:700; color:#166534; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        </div>
                                        <div id="tdRemarkFileSize" style="font-size:11px; color:#4ade80; margin-top:1px;">
                                        </div>
                                    </div>
                                    <button type="button" onclick="clearTdRemarkFile()"
                                        style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:13px; padding:4px;">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" id="tdRemarkSubmitBtn"
                                style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:10px 22px; border:none; border-radius:9px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                                <i class="fas fa-paper-plane"></i>
                                <?= $tdRemarkAlreadySet ? 'Update Remark' : 'Submit Remark' ?>
                            </button>
                        </form>
                        </div><!-- end tdDesRemarkForm -->
                </div><!-- end padding:18px 22px -->
            </div><!-- end panel -->
        <?php elseif ($desApprovalExists && !$isAssigned && $canViewAll): ?>
            <!-- Managers can see the remark status but cannot edit -->
            <?php if ($tdRemarkAlreadySet): ?>
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title" style="color:#0369a1;">
                            <i class="fas fa-comment-dots"></i> Technical Designer Remark
                        </div>
                    </div>
                    <div style="padding:16px 22px;">
                        <div style="font-size:13px; color:#1e293b; font-style:italic; margin-bottom:10px;">
                            "<?= htmlspecialchars($existingTDRemark) ?>"
                        </div>
                        <?php if ($existingRemarkFile): ?>
                        <div style="display:flex; align-items:center; gap:10px; background:#fff7ed; border:1.5px solid #fed7aa; border-radius:8px; padding:10px 13px;">
                            <i class="fas fa-file-pdf" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:12px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= htmlspecialchars($existingRemarkFileName ?: 'TD Remark Attachment') ?>
                                </div>
                                <div style="font-size:11px; color:#92400e; margin-top:1px;">PDF from Technical Designer</div>
                            </div>
                            <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>" target="_blank"
                               style="background:#dbeafe; color:#1d4ed8; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="<?= BASE_URL ?><?= htmlspecialchars($existingRemarkFile) ?>"
                               download="<?= htmlspecialchars($existingRemarkFileName ?: 'td_remark.pdf') ?>"
                               style="background:#dcfce7; color:#166534; padding:6px 12px; border-radius:7px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
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
    ═════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title"><i class="fas fa-folder-open" style="color:#0369a1;"></i> Your Technical
                    Documents</div>
                <span style="font-size:12px; color:#94a3b8; font-weight:600;"><?= $fileCount ?> /
                    <?= $maxFiles ?></span>
            </div>

            <?php if (!empty($filesByCategory)): ?>
                <!-- Tab bar -->
                <div class="tab-bar" id="tdTabBar">
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
                <?php $i = 0;
                foreach ($filesByCategory as $catName => $catFiles):
                    $slug = 'cat-' . md5($catName); ?>
                    <div class="tab-pane <?= $i === 0 ? 'active' : '' ?>" id="td-<?= $slug ?>">
                        <?php foreach ($catFiles as $f):
                            $isImg = strpos($f['file_type'], 'image/') === 0;
                            $fp = BASE_URL . $f['file_path'];
                            $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                            $fi = $iconMap[$ext] ?? 'fa-file';
                            ?>
                            <div class="file-row">
                                <?php if ($isImg): ?>
                                    <img src="<?= htmlspecialchars($fp) ?>" class="f-thumb" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="f-icon" style="background:#e0f2fe;"><i class="fas <?= $fi ?>"
                                            style="color:#0369a1; font-size:18px;"></i></div>
                                <?php endif; ?>
                                <div class="f-info">
                                    <div class="f-name"><?= htmlspecialchars($f['file_name']) ?></div>
                                    <div class="f-meta"><?= round($f['file_size'] / 1024, 1) ?> KB &nbsp;·&nbsp;
                                        <?= htmlspecialchars($f['uploader_name'] ?? '') ?> &nbsp;·&nbsp;
                                        <?= date('M d, Y g:i A', strtotime($f['created_at'])) ?>
                                    </div>
                                    <?php if (!empty($f['note'])): ?>
                                        <div class="f-note"><?= htmlspecialchars($f['note']) ?></div><?php endif; ?>
                                </div>
                                <?php
                                $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                $isViewable = $isImg || in_array($ext, $imageExts) || $ext === 'pdf';
                                ?>
                                <?php if ($isViewable): ?>
                                    <a href="<?= htmlspecialchars($fp) ?>" target="_blank" class="btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($f['file_name']) ?>"
                                        class="btn-view"
                                        style="background:#dcfce7; color:#166534; border-color:#86efac; margin-left:4px;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($fp) ?>" download="<?= htmlspecialchars($f['file_name']) ?>"
                                        class="btn-view" style="background:#dcfce7; color:#166534; border-color:#86efac;">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                <?php endif; ?>
                                <?php if (!$viewOnly): ?>
                                    <button class="btn-del"
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
                    <div class="tab-pane" id="td-upload" style="padding:20px 22px;">
                    <?php endif; ?>

                <?php else: ?>
                    <!-- No files yet: show upload directly without tabs -->
                    <div style="padding:20px 22px;">
                    <?php endif; // endif !empty filesByCategory ?>

                    <!-- ── Upload Form ── -->
                    <?php if ($canUpload && !$viewOnly): ?>
                        <div class="upload-box">
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

                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-tag"></i> Category / Document Type <span
                                        style="color:#ef4444;">*</span></label>
                                <input type="text" id="tdCategoryName" class="form-ctrl" list="catSuggestions"
                                    placeholder="e.g. Cutting List, Shop Drawing, Sketch…">
                                <div style="font-size:11px; color:#94a3b8; margin-top:5px;"><i
                                        class="fas fa-info-circle"></i> Files with the same category are grouped under
                                    one tab.</div>
                            </div>

                            <div class="form-group">
                                <div class="drop-zone" id="uploadZone">
                                    <i class="fas fa-cloud-upload-alt dz-icon"></i>
                                    <p style="font-size:14px; font-weight:600; color:#1e293b; margin-bottom:3px;">Click
                                        or drag files here</p>
                                    <p style="font-size:11px; color:#94a3b8;" id="td-hint">Images, PDFs, DWG, DXF, Word,
                                        Excel &amp; more &nbsp;·&nbsp; Max <?= $maxFiles - $fileCount ?> more
                                        &nbsp;·&nbsp; Max 50MB (Direct) or 1.3GB (Chunked)</p>
                                    <p style="font-size:12px; color:#0369a1; font-weight:600; margin-top:8px;"
                                        id="fileCountLabel"></p>
                                    <input type="file" multiple id="fileInput"
                                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.dwg,.dxf,.txt,.zip"
                                        style="display:none;" onclick="event.stopPropagation()"
                                        onchange="tdAutoSuggestMode(this)">
                                </div>

                                <!-- Upload mode toggle -->
                                <div class="upload-mode-toggle">
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-bolt" style="color:#1e40af;"></i>
                                        <span>Upload Mode:</span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="td-mode-toggle" onchange="tdOnModeChange()">
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <div id="td-mode-label">
                                        <span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
                                        <span style="font-size:11px; color:#94a3b8; margin-left:4px;">Best for files
                                            under 50MB · faster, no 405 errors</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Progress bar -->
                            <div id="td-progress-wrap" style="display:none; margin-bottom:14px;">
                                <div
                                    style="display:flex; justify-content:space-between; font-size:12px; color:#374151; margin-bottom:5px;">
                                    <span id="td-progress-label">Uploading...</span>
                                    <span id="td-progress-pct">0%</span>
                                </div>
                                <div style="height:7px; background:#e9ecef; border-radius:99px; overflow:hidden;">
                                    <div id="td-progress-bar"
                                        style="height:100%; width:0%; border-radius:99px; transition:width .2s; background:linear-gradient(90deg,#0c4a6e,#0369a1);">
                                    </div>
                                </div>
                                <div id="td-progress-sub" style="font-size:11px; color:#9ca3af; margin-top:4px;"></div>
                            </div>

                            <div id="td-upload-error"
                                style="display:none; background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:10px;">
                            </div>

                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-sticky-note"></i> Note (optional)</label>
                                <textarea id="tdNote" class="form-ctrl" rows="2" placeholder="Any notes about these files…"
                                    style="resize:vertical;"></textarea>
                            </div>

                            <button type="button" id="tdUploadBtn" onclick="startTDUpload()" class="btn-upload">
                                <i class="fas fa-upload"></i> Upload Files
                            </button>
                        </div>

                    <?php elseif ($viewOnly): ?>
                        <div
                            style="background:#f8fafc; border-radius:8px; padding:12px 16px; font-size:13px; color:#64748b; display:inline-flex; align-items:center; gap:8px;">
                            <i class="fas fa-eye"></i> View only — you cannot upload files
                        </div>
                    <?php else: ?>
                        <div
                            style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e;">
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

            <!-- Empty state (view only with no files) -->
            <?php if (empty($filesByCategory) && $viewOnly): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No files uploaded yet.</p>
                </div>
            <?php endif; ?>

        </div><!-- /.panel -->


        <!-- ════════════════════════════════════════════
         APPROVAL PANEL
    ═════════════════════════════════════════════ -->
        <div class="appr-panel">
            <div class="appr-head">
                <div>
                    <div class="appr-title">
                        <i class="fas <?= $panelIcon ?>"></i>
                        Approval Status — <?= htmlspecialchars($locationLabel) ?>
                        <span
                            style="font-size:11px; font-weight:700; background:<?= $panelBorder ?>44; color:<?= $panelColor ?>; padding:2px 10px; border-radius:20px;"><?= $panelLabel ?></span>
                    </div>
                    <div style="font-size:11px; color:<?= $panelColor ?>; opacity:0.75; margin-top:3px;">
                        <?= $approvalRequested ? '● Approval has been requested' : '○ No approval request yet' ?>
                    </div>
                </div>

                <!-- Request/Re-request buttons -->
                <?php if ($hasActiveRevision && $revisionStatus === 'pending' && $canRequestApproval && $hasAnyFile): ?>
                    <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                        <input type="hidden" name="room_unit_number" value="<?= $room_unit_number ?? '' ?>">
                        <input type="hidden" name="room_unit_name" value="<?= htmlspecialchars($room_unit_name) ?>">
                        <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit"
                            style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                            <i class="fas fa-paper-plane"></i> Submit Revised
                        </button>
                    </form>
                <?php elseif ($canRequestApproval): ?>
                    <?php if (!$approvalRequested && $hasAnyFile): ?>
                        <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="room_unit_number" value="<?= $room_unit_number ?? '' ?>">
                            <input type="hidden" name="room_unit_name" value="<?= htmlspecialchars($room_unit_name) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <button type="submit"
                                style="background:linear-gradient(135deg,#0c4a6e,#0369a1); color:white; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fas fa-paper-plane"></i> Request Approval
                            </button>
                        </form>
                    <?php elseif (!$approvalRequested): ?>
                        <span style="font-size:12px; color:#94a3b8; font-style:italic;">Upload files first</span>
                    <?php elseif ($anyRejected): ?>
                        <form method="POST" action="<?= BASE_URL ?>td-request-approval">
                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                            <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                            <input type="hidden" name="room_unit_number" value="<?= $room_unit_number ?? '' ?>">
                            <input type="hidden" name="room_unit_name" value="<?= htmlspecialchars($room_unit_name) ?>">
                            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                            <input type="hidden" name="resubmit" value="1">
                            <button type="submit"
                                style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fas fa-redo"></i> Re-request Approval
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Revision banners -->
            <?php if ($hasActiveRevision && $revisionStatus === 'pending'): ?>
                <div
                    style="background:#fffbeb; border-bottom:1px solid #fde68a; padding:12px 22px; display:flex; align-items:flex-start; gap:10px;">
                    <i class="fas fa-redo" style="color:#d97706; margin-top:1px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:2px;">
                            Revision #<?= $activeRevision['revision_number'] ?> Requested
                            <span
                                style="background:#f59e0b; color:white; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px;">Awaiting
                                Resubmission</span>
                        </div>
                        <div style="font-size:12px; color:#78350f;">
                            <?= nl2br(htmlspecialchars($activeRevision['reason'])) ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($hasActiveRevision && $revisionStatus === 'designer_resubmitted'): ?>
                <div
                    style="background:#eff6ff; border-bottom:1px solid #bfdbfe; padding:12px 22px; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-clock" style="color:#2563eb;"></i>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:#1e40af; margin-bottom:2px;">
                            Revision #<?= $activeRevision['revision_number'] ?> — Revised Files Submitted
                            <span
                                style="background:#3b82f6; color:white; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:6px;">Awaiting
                                Approval</span>
                        </div>
                        <div style="font-size:12px; color:#1e3a8a;">
                            <?= nl2br(htmlspecialchars($activeRevision['reason'])) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Per-approver rows -->
            <div class="appr-rows">
                <?php foreach ($approvers as $apr):
                    $rec = $approvalMap[$apr['id']] ?? null;
                    $aStatus = $rec ? $rec['status'] : 'not_requested';
                    if ($aStatus === 'approved') {
                        $aBg = '#f0fdf4';
                        $aBd = '#86efac';
                        $aC = '#166534';
                        $aIc = 'fa-check-circle';
                        $aLb = 'Approved';
                    } elseif ($aStatus === 'rejected') {
                        $aBg = '#fff5f5';
                        $aBd = '#fca5a5';
                        $aC = '#991b1b';
                        $aIc = 'fa-times-circle';
                        $aLb = 'Rejected';
                    } elseif ($aStatus === 'pending') {
                        $aBg = '#fffbeb';
                        $aBd = '#fcd34d';
                        $aC = '#92400e';
                        $aIc = 'fa-hourglass-half';
                        $aLb = 'Pending';
                    } else {
                        $aBg = '#f8fafc';
                        $aBd = '#e2e8f0';
                        $aC = '#64748b';
                        $aIc = 'fa-minus-circle';
                        $aLb = 'Not Requested';
                    }
                    $canAct = ($isApprover && $apr['id'] == $admin_id && $aStatus === 'pending')
                        && (!$hasActiveRevision || $revisionStatus === 'designer_resubmitted');
                    ?>
                    <div class="appr-row" style="background:<?= $aBg ?>; border-color:<?= $aBd ?>;">
                        <div style="display:flex; align-items:center; gap:11px; flex:1; min-width:0;">
                            <div
                                style="width:34px; height:34px; border-radius:50%; background:<?= $aBd ?>44; border:1.5px solid <?= $aBd ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fas <?= $aIc ?>" style="color:<?= $aC ?>; font-size:13px;"></i>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:7px; flex-wrap:wrap;">
                                    <span
                                        style="font-weight:700; font-size:13px;"><?= htmlspecialchars($apr['full_name']) ?></span>
                                    <span
                                        style="font-size:10px; background:<?= $aBd ?>55; color:<?= $aC ?>; padding:2px 8px; border-radius:20px; font-weight:600; text-transform:capitalize;"><?= str_replace('_', ' ', $apr['role']) ?></span>
                                </div>
                                <?php if ($rec && $rec['responded_at']): ?>
                                    <div style="font-size:11px; color:#94a3b8; margin-top:2px;">
                                        <?= date('M d, Y · g:i A', strtotime($rec['responded_at'])) ?>
                                    </div><?php endif; ?>
                                <?php if ($rec && $rec['comment']): ?>
                                    <div
                                        style="font-size:11px; color:#92400e; background:#fffbeb; border:1px solid #fde68a; padding:3px 9px; border-radius:5px; margin-top:5px; display:inline-block;">
                                        <?= htmlspecialchars($rec['comment']) ?>
                                    </div><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($canAct): ?>
                            <div style="display:flex; gap:7px; flex-shrink:0;">
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>,'<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>','approved')"
                                    style="background:#10b981; color:white; border:none; padding:7px 13px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button
                                    onclick="openApproveModal(<?= $apr['id'] ?>,'<?= htmlspecialchars($apr['full_name'], ENT_QUOTES) ?>','rejected')"
                                    style="background:#ef4444; color:white; border:none; padding:7px 13px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        <?php else: ?>
                            <span
                                style="font-size:11px; font-weight:700; color:<?= $aC ?>; background:<?= $aBd ?>44; padding:4px 12px; border-radius:20px; border:1px solid <?= $aBd ?>; flex-shrink:0; white-space:nowrap;"><?= $aLb ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /.page-wrap -->

    <!-- Approve/Reject Modal -->
    <div id="approveModal"
        style="display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.45); align-items:center; justify-content:center;">
        <div
            style="background:white; border-radius:14px; padding:28px; max-width:460px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,0.18);">
            <h3 id="approveModalTitle" style="font-size:15px; font-weight:700; color:#1e293b; margin-bottom:14px;">
            </h3>
            <textarea id="approveComment" placeholder="Comment (required for rejection)…"
                style="width:100%; padding:10px 13px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; min-height:80px; margin-bottom:16px;"></textarea>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button onclick="closeApproveModal()"
                    style="background:#f1f5f9; color:#475569; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px;">Cancel</button>
                <button id="approveConfirmBtn" onclick="submitApproval()"
                    style="padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-weight:700; color:white; font-size:13px;"></button>
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
            // Hide all panes that share the same prefix
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
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
            zone.addEventListener('drop', e => {
                e.preventDefault(); zone.classList.remove('drag-over');
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
                label.innerHTML = `<span class="mode-badge chunked"><i class="fas fa-layer-group"></i> Chunked</span>
            <span style="font-size:11px;color:#94a3b8;margin-left:4px;">For large files up to 1.3GB · slower start</span>`;
                if (hint) hint.textContent = 'Images, PDFs, DWG, DXF, Word, Excel & more · Max 1.3GB each (Chunked mode)';
            } else {
                label.innerHTML = `<span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
            <span style="font-size:11px;color:#94a3b8;margin-left:4px;">Best for files under 50MB · faster, no 405 errors</span>`;
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
                progressBar.style.background = 'repeating-linear-gradient(90deg,#0c4a6e 0px,#38bdf8 20px,#0c4a6e 40px)';
                progressBar.style.backgroundSize = '200% 100%';
                progressBar.style.animation = 'shimmer 1.5s infinite linear';
                progressPct.textContent = 'Uploading...';
                progressLbl.textContent = `Sending file ${i + 1}/${files.length}: ${file.name}`;

                // Build temporary hidden form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= BASE_URL ?>td-attachment-direct-upload';
                form.enctype = 'multipart/form-data';
                form.target = 'td_direct_frame';
                form.style.display = 'none';

                // Hidden fields
                const fields = {
                    client_id: <?= $client_id ?>,
                    area: <?= json_encode($area) ?>,
                    category_name: categoryName,
                    note: note,
                    room_unit_number: <?= json_encode($room_unit_number !== null ? (string) $room_unit_number : '') ?>,
                    room_unit_name: <?= json_encode($room_unit_name) ?>
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
            errEl.style.display = 'none';

            if (!categoryName) {
                errEl.textContent = 'Please enter a category / document type.';
                errEl.style.display = 'block';
                return;
            }
            if (files.length === 0) {
                errEl.textContent = 'Please select at least one file.';
                errEl.style.display = 'block';
                return;
            }
            if (files.some(f => f.type.startsWith('video/'))) {
                errEl.textContent = 'Video files are not allowed.';
                errEl.style.display = 'block';
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
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Files';
                return;
            }

            let CHUNK_SIZE = 2 * 1024 * 1024; // start at 2MB, auto-adjusts

            const MIN_CHUNK = 512 * 1024;        // 512KB floor
            const MAX_CHUNK = 32 * 1024 * 1024; // 32MB ceiling
            const TARGET_MS = 8000;               // aim ~8s per chunk
            const SERVER_OH = 250;                // ~250ms server overhead

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
                CHUNK_SIZE = 2 * 1024 * 1024; // reset per file
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
                    fd.append('room_unit_number', <?= json_encode($room_unit_number !== null ? (string) $room_unit_number : '') ?>);
                    fd.append('room_unit_name', <?= json_encode($room_unit_name) ?>);

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
                            errEl.style.display = 'block';
                            anyError = true;
                            break;
                        }
                        const elapsed = performance.now() - t0;

                        if (!data.success) {
                            errEl.textContent = file.name + ': ' + (data.error || 'Upload failed');
                            errEl.style.display = 'block';
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
                        errEl.style.display = 'block';
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
                    body: JSON.stringify({ client_id: <?= $client_id ?>, area: <?= json_encode($area) ?>, room_unit_number: <?= json_encode($room_unit_number) ?>, approver_id: _aprId, status: _aprAction, comment })
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
            preview.style.display = 'flex';
            label.textContent = file.name;
        }
        function clearTdRemarkFile() {
            document.getElementById('tdRemarkFileInput').value = '';
            document.getElementById('tdRemarkFilePreview').style.display = 'none';
            document.getElementById('tdRemarkFileLabel').textContent = 'Click to attach a PDF';
        }
    </script>
    <iframe name="td_direct_frame" id="td_direct_frame" style="display:none;"></iframe>
</body>

</html>