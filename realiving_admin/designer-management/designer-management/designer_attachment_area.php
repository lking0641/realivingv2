<?php
//designer_attachment_area.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) { header("Location: ../login.php"); exit(); }
$admin_id  = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area      = isset($_GET['area']) ? trim($_GET['area']) : '';

// ── Pending approval notif for this area ──
function getPendingApprovalCountForArea($conn, $admin_id, $client_id, $area) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.area = ? AND la.status = 'pending'
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
    $stmt->bind_param("iis", $client_id, $admin_id, $area);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id); $meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'sales'];
if (!in_array($me['role'], $allowedRoles)) die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
           || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id); $assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();
if (!$clientInfo) die("Client not found.");

$isAssigned = ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id);
if (!$isAssigned && !$canViewAll) die("Access denied.");

// Get units for this area
function getUnits($conn, $client_id, $area) {
    $rooms = [];
    $stmt = $conn->prepare("
        SELECT rd.room_unit_number, rd.room_unit_name, SUM(rd.quantity) as total_quantity
        FROM quotation_room_distribution rd
        INNER JOIN quotation_entries qe ON rd.quotation_entry_id = qe.id
        WHERE qe.client_id = ? AND qe.area = ?
        GROUP BY rd.room_unit_number, rd.room_unit_name
    ");
    $stmt->bind_param("is", $client_id, $area); $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) $rooms[$r['room_unit_number']] = $r;
    $stmt->close();

    $stmt2 = $conn->prepare("
        SELECT rd.room_unit_number, rd.room_unit_name, SUM(rd.quantity) as total_quantity
        FROM quotation_room_distribution rd
        INNER JOIN quotation_fixed_sizes qfs ON rd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = ? AND qfs.area = ?
        GROUP BY rd.room_unit_number, rd.room_unit_name
    ");
    $stmt2->bind_param("is", $client_id, $area); $stmt2->execute();
    $result2 = $stmt2->get_result();
    while ($r = $result2->fetch_assoc()) {
        if (isset($rooms[$r['room_unit_number']])) $rooms[$r['room_unit_number']]['total_quantity'] += $r['total_quantity'];
        else $rooms[$r['room_unit_number']] = $r;
    }
    $stmt2->close();
    usort($rooms, fn($a,$b) => $a['room_unit_number'] - $b['room_unit_number']);
    return $rooms;
}

$units = getUnits($conn, $client_id, $area);
$hasUnits = !empty($units);

// Fetch active pending revision entries for this area
$unitRevStmt = $conn->prepare("
    SELECT room_unit_number, reason, revision_number, created_at
    FROM layout_revision_log
    WHERE client_id = ? AND area = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$unitRevStmt->bind_param("is", $client_id, $area);
$unitRevStmt->execute();
$unitRevRows = $unitRevStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Index by unit number for quick lookup
$unitRevMap = [];
foreach ($unitRevRows as $rv) {
    $unitRevMap[$rv['room_unit_number'] ?? 'null'] = $rv;
}

// If NO units → redirect straight to upload page (no unit number)
if (!$hasUnits) {
    header("Location: " . BASE_URL . "designer-attachment-upload?client_id={$client_id}&area=" . urlencode($area));
    exit();
}

// Count files per unit
function countUnitFiles($conn, $client_id, $area, $room_unit_number) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM layout_attachments WHERE client_id=? AND area=? AND room_unit_number=?");
    $stmt->bind_param("isi", $client_id, $area, $room_unit_number);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Units — <?= htmlspecialchars($area) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f5f1ed; font-family:'Segoe UI',sans-serif; }
        .container { max-width:700px; margin:30px auto; padding:0 20px; }
        .btn-back {
            background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; padding:8px 16px;
            border:none; border-radius:8px; font-weight:600; font-size:13px;
            display:inline-flex; align-items:center; gap:6px; text-decoration:none; margin-bottom:16px;
        }
        .page-header {
            background:linear-gradient(135deg,#3b1f0f 0%,#8a5a44 100%);
            padding:24px 30px; border-radius:14px; color:white; margin-bottom:22px;
        }
        .page-header h1 { font-size:20px; margin-bottom:4px; }
        .page-header .sub { font-size:12px; opacity:0.85; margin-top:4px; }
        .card { background:white; border-radius:12px; padding:24px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .unit-card {
            display:flex; align-items:center; justify-content:space-between;
            padding:16px 20px; border:1.5px solid #e0e7ff; border-radius:10px;
            margin-bottom:10px; cursor:pointer; text-decoration:none;
            background:white; transition:all 0.2s;
        }
        .unit-card:hover { border-color:#6366f1; background:#f0f4ff; transform:translateX(4px); }
        .unit-icon { width:42px; height:42px; background:#e0e7ff; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .unit-name { font-size:15px; font-weight:700; color:#1e1b4b; }
        .unit-meta { font-size:11px; color:#9ca3af; margin-top:2px; }
        .badge-files { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#d1fae5; color:#065f46; }
        .badge-empty { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#f3f4f6; color:#9ca3af; }
    </style>
</head>
<body>
<div class="container">
    <a href="designer-attachments?client_id=<?= $client_id ?>" class="btn-back">
        <i class="fas fa-arrow-left"></i> Back to Areas
    </a>

    <div class="page-header">
        <h1><i class="fas fa-layer-group"></i> <?= htmlspecialchars($area) ?></h1>
        <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> — <?= htmlspecialchars($clientInfo['nameproject']) ?></div>
        <div class="sub">Select a unit to manage its attachments</div>
    </div>

    <?php
$pendingAreaCount = getPendingApprovalCountForArea($conn, $admin_id, $client_id, $area);
if ($pendingAreaCount > 0):
?>
<div style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <i class="fas fa-bell" style="color:#d97706; font-size:20px; flex-shrink:0;"></i>
    <div>
        <div style="font-weight:700; font-size:14px; color:#92400e;">
            You have <?= $pendingAreaCount ?> pending approval<?= $pendingAreaCount > 1 ? 's' : '' ?> in this area
        </div>
        <div style="font-size:12px; color:#b45309; margin-top:2px;">
            Click a unit below to review and approve or reject the attachments.
        </div>
    </div>
</div>
<?php endif; ?>

    <div class="card">
        <h2 style="font-size:15px; color:#3730a3; margin-bottom:16px; padding-bottom:10px; border-bottom:2px solid #e0e7ff;">
            <i class="fas fa-building"></i> Units in <?= htmlspecialchars($area) ?>
        </h2>
        <?php foreach ($units as $unit): ?>
        <?php
            $unitLabel = !empty($unit['room_unit_name']) ? $unit['room_unit_name'] : 'Unit ' . $unit['room_unit_number'];
            $fileCount = countUnitFiles($conn, $client_id, $area, $unit['room_unit_number']);

        // Fetch detailed approval records per unit with approver info
        $unitApprStmt = $conn->prepare("
            SELECT la.status, la.comment, la.responded_at,
                   a.id as approver_id, a.full_name as approver_name, a.role as approver_role
            FROM layout_approvals la
            JOIN account a ON la.approver_id = a.id
            WHERE la.client_id=? AND la.area=? AND la.room_unit_number=?
        ");
        $unitApprStmt->bind_param("isi", $client_id, $area, $unit['room_unit_number']);
        $unitApprStmt->execute();
        $unitApprRows = $unitApprStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $unitApprStmt->close();

        // Get all approvers once (reuse if already fetched)
        if (!isset($allApprovers)) {
            $allApprStmt = $conn->prepare("
                SELECT id, full_name, role FROM account
                WHERE (role IN ('general_manager','operational_manager'))
                   OR (role IN ('designer','technical_designer') AND is_head = 1)
                ORDER BY role
            ");
            $allApprStmt->execute();
            $allApprovers = $allApprStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        // Build map: approver_id => record
        $unitApprovalMap = [];
        foreach ($unitApprRows as $rec) {
            $unitApprovalMap[$rec['approver_id']] = $rec;
        }

        if (empty($unitApprRows)) {
            $unitApprState = 'none';
        } else {
            $uStat = array_column($unitApprRows, 'status');
            if (in_array('rejected', $uStat))    $unitApprState = 'rejected';
            elseif (count(array_filter($uStat, fn($s) => $s === 'approved')) === count($uStat))
                                                 $unitApprState = 'approved';
            elseif (in_array('pending', $uStat)) $unitApprState = 'pending';
            else                                 $unitApprState = 'none';
        }

        $uCardBorder = '#e0e7ff'; $uCardBg = 'white'; $uApprBadge = '';
        if ($unitApprState === 'approved') {
            $uCardBorder = '#10b981'; $uCardBg = '#f0fdf4';
            $uApprBadge = '<span class="badge-files" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i> All Approved</span>';
        } elseif ($unitApprState === 'rejected') {
            $uCardBorder = '#ef4444'; $uCardBg = '#fff5f5';
            $uApprBadge = '<span class="badge-files" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-times-circle"></i> Rejected</span>';
        } elseif ($unitApprState === 'pending') {
            $uCardBorder = '#f59e0b'; $uCardBg = '#fffbeb';
            $uApprBadge = '<span class="badge-files" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i> Pending</span>';
        }

        // Build per-approver badges for this unit
        $unitApproverBadgesHtml = '';
        if (!empty($allApprovers)) {
            $unitApproverBadgesHtml .= '<div style="display:flex; flex-wrap:wrap; gap:5px; margin-top:6px;">';
            foreach ($allApprovers as $apr) {
                $rec     = $unitApprovalMap[$apr['id']] ?? null;
                $aStatus = $rec ? $rec['status'] : 'not_requested';

                if ($aStatus === 'approved') {
                    $bBg = '#d1fae5'; $bColor = '#065f46'; $bIcon = 'fa-check-circle';
                    $bTitle = htmlspecialchars($apr['full_name']) . ': Approved';
                } elseif ($aStatus === 'rejected') {
                    $bBg = '#fee2e2'; $bColor = '#991b1b'; $bIcon = 'fa-times-circle';
                    $bTitle = htmlspecialchars($apr['full_name']) . ': Rejected';
                    if ($rec['comment']) $bTitle .= ' — ' . htmlspecialchars($rec['comment']);
                } elseif ($aStatus === 'pending') {
                    $bBg = '#fef3c7'; $bColor = '#92400e'; $bIcon = 'fa-hourglass-half';
                    $bTitle = htmlspecialchars($apr['full_name']) . ': Pending';
                } else {
                    $bBg = '#f3f4f6'; $bColor = '#9ca3af'; $bIcon = 'fa-minus-circle';
                    $bTitle = htmlspecialchars($apr['full_name']) . ': Not requested';
                }

                $shortName = explode(' ', $apr['full_name'])[0];
                $unitApproverBadgesHtml .= '<span title="' . $bTitle . '" style="display:inline-flex; align-items:center; gap:4px; background:' . $bBg . '; color:' . $bColor . '; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:700;">';
                $unitApproverBadgesHtml .= '<i class="fas ' . $bIcon . '" style="font-size:10px;"></i> ' . htmlspecialchars($shortName);
                $unitApproverBadgesHtml .= '</span>';
            }
            $unitApproverBadgesHtml .= '</div>';
        }

        $unitRevKey = $unit['room_unit_number'];
        $unitActiveRev = $unitRevMap[$unitRevKey] ?? null;

        $url = BASE_URL . "designer-attachment-upload?client_id=" . $client_id
                 . '&area=' . urlencode($area)
                 . '&room_unit_number=' . $unit['room_unit_number']
                 . '&room_unit_name=' . urlencode($unit['room_unit_name'] ?? '');
        ?>
        <?php if ($unitActiveRev): ?>
        <div style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:8px 14px; margin-bottom:4px; display:flex; align-items:center; gap:8px; font-size:12px; color:#92400e; font-weight:600;">
            <i class="fas fa-redo"></i>
            Revision #<?= $unitActiveRev['revision_number'] ?> Pending
            <span style="font-weight:400; margin-left:4px;"><?= date('M d, Y', strtotime($unitActiveRev['created_at'])) ?></span>
            <span style="font-weight:400; font-style:italic; margin-left:4px;"><?= htmlspecialchars(mb_strimwidth($unitActiveRev['reason'], 0, 60, '...')) ?></span>
        </div>
        <?php endif; ?>
        <div class="unit-card" style="cursor:default; border-color:<?= $unitActiveRev ? '#f59e0b' : $uCardBorder ?>; background:<?= $unitActiveRev ? '#fffbeb' : $uCardBg ?>;">
            <a href="<?= $url ?>" style="display:flex; align-items:center; gap:12px; text-decoration:none; flex:1;">
                <div class="unit-icon">
                    <i class="fas fa-door-open" style="color:#6366f1; font-size:18px;"></i>
                </div>
                <div>
                    <div class="unit-name"><?= htmlspecialchars($unitLabel) ?></div>
                    <div class="unit-meta">
                        <?= $unit['total_quantity'] ?> item(s) &nbsp;•&nbsp;
                        <?php if ($fileCount > 0): ?>
                        <span class="badge-files"><i class="fas fa-paperclip"></i> <?= $fileCount ?> attachment(s)</span>
                        <?php else: ?>
                        <span class="badge-empty"><i class="fas fa-inbox"></i> No attachments yet</span>
                        <?php endif; ?>
                        <?php if ($uApprBadge): ?>
                        &nbsp;<?= $uApprBadge ?>
                        <?php endif; ?>
                        <?= $unitApproverBadgesHtml ?>
                    </div>
                </div>
            </a>
            <div style="display:flex; align-items:center; gap:8px;">
                <button type="button"
                        onclick="openItemsModal(<?= $client_id ?>, '<?= htmlspecialchars($area, ENT_QUOTES) ?>', <?= $unit['room_unit_number'] ?>, '<?= htmlspecialchars($unitLabel, ENT_QUOTES) ?>')"
                        style="background:#e0e7ff; border:none; color:#3730a3; padding:7px 13px; border-radius:7px; cursor:pointer; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap;">
                    <i class="fas fa-boxes"></i> Items
                </button>
                <a href="<?= $url ?>" style="color:#9ca3af;">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<!-- Items Modal -->
<div id="itemsModal" style="display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.55); align-items:center; justify-content:center;">
    <div style="background:white; border-radius:14px; width:90%; max-width:640px; max-height:88vh; overflow:hidden; display:flex; flex-direction:column;">

        <!-- Modal Header -->
        <div style="background:linear-gradient(135deg,#3730a3,#6366f1); padding:18px 22px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <h3 id="itemsModalTitle" style="font-size:16px; font-weight:700; color:white; margin-bottom:3px;">
                    <i class="fas fa-boxes"></i> Items
                </h3>
                <p id="itemsModalSub" style="font-size:11px; color:rgba(255,255,255,0.8);"></p>
            </div>
            <button onclick="closeItemsModal()" style="background:rgba(255,255,255,0.2); border:none; color:white; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center;">
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
    document.getElementById('itemsModalSub').innerHTML   = '<i class="fas fa-map-marker-alt"></i> ' + escItemHtml(area);
    document.getElementById('itemsModalBody').innerHTML  =
        '<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="fas fa-spinner fa-spin" style="font-size:28px;"></i><p style="margin-top:10px;">Loading items...</p></div>';
    document.getElementById('itemsModal').style.display = 'flex';

    let url = '<?= BASE_URL ?>get-area-items?client_id=' + clientId + '&area=' + encodeURIComponent(area);
    if (roomNumber !== null && roomNumber !== undefined) {
        url += '&room_number=' + roomNumber;
    }

    try {
        const res  = await fetch(url);
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

    items.forEach(function(item, index) {
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
        if (item.width)  dims.push((item.width_label  || 'W') + ': ' + item.width  + 'mm');
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
            item.addons.forEach(function(addon, ai) {
                const border = ai > 0 ? 'border-top:1px solid #dde3ff;' : '';
                html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;' + border + '">';
                if (addon.addon_image_path) {
                    html += '<img src="<?= CLIENT_ASSET ?>/images/product_addons/' + escItemHtml(addon.addon_image_path) + '" style="width:32px;height:32px;object-fit:cover;border-radius:6px;border:1px solid #c7d2fe;flex-shrink:0;" onerror="this.style.display=\'none\'">';
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
document.addEventListener('click', function(e) {
    const modal = document.getElementById('itemsModal');
    if (modal && e.target === modal) closeItemsModal();
});
</script>
</body>
</html>