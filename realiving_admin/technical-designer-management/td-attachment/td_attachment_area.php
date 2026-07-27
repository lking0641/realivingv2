<?php
// td_attachment_area.php
session_start();
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id  = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area      = isset($_GET['area']) ? trim($_GET['area']) : '';

// ── Pending approval notif for this area ──
function getTDPendingApprovalCountForArea($conn, $admin_id, $client_id, $area) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM td_attachment_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.area = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM td_revision_log rl
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

$allowedRoles = ['technical_designer', 'general_manager', 'operational_manager'];
if (!in_array($me['role'], $allowedRoles)) die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager'])
           || ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

$ciStmt = $conn->prepare("SELECT technical_designer_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$ciStmt->bind_param("i", $client_id); $ciStmt->execute();
$clientInfo = $ciStmt->get_result()->fetch_assoc();
if (!$clientInfo) die("Client not found.");

$isAssigned = ($clientInfo['technical_designer_id'] == $admin_id);
if (!$isAssigned && !$canViewAll) die("Access denied.");

// Get units for area
function tdGetUnits($conn, $client_id, $area) {
    $rooms = [];
    $s = $conn->prepare("SELECT rd.room_unit_number, rd.room_unit_name, SUM(rd.quantity) as total_quantity FROM quotation_room_distribution rd INNER JOIN quotation_entries qe ON rd.quotation_entry_id = qe.id WHERE qe.client_id=? AND qe.area=? GROUP BY rd.room_unit_number, rd.room_unit_name");
    $s->bind_param("is", $client_id, $area); $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $rooms[$row['room_unit_number']] = $row;
    $s->close();

    $s2 = $conn->prepare("SELECT rd.room_unit_number, rd.room_unit_name, SUM(rd.quantity) as total_quantity FROM quotation_room_distribution rd INNER JOIN quotation_fixed_sizes qfs ON rd.quotation_fixed_size_id = qfs.id WHERE qfs.client_id=? AND qfs.area=? GROUP BY rd.room_unit_number, rd.room_unit_name");
    $s2->bind_param("is", $client_id, $area); $s2->execute();
    $r2 = $s2->get_result();
    while ($row = $r2->fetch_assoc()) {
        if (isset($rooms[$row['room_unit_number']])) $rooms[$row['room_unit_number']]['total_quantity'] += $row['total_quantity'];
        else $rooms[$row['room_unit_number']] = $row;
    }
    $s2->close();
    usort($rooms, fn($a,$b) => $a['room_unit_number'] - $b['room_unit_number']);
    return $rooms;
}

$units    = tdGetUnits($conn, $client_id, $area);
$hasUnits = !empty($units);

// If no units → go straight to upload
if (!$hasUnits) {
    header("Location: " . BASE_URL . "td-attachment-upload?client_id={$client_id}&area=" . urlencode($area));
    exit();
}

// Pending revisions for this area per unit
$uRevStmt = $conn->prepare("SELECT room_unit_number, reason, revision_number, created_at FROM td_revision_log WHERE client_id=? AND area=? AND status='pending' ORDER BY created_at DESC");
$uRevStmt->bind_param("is", $client_id, $area); $uRevStmt->execute();
$uRevMap = [];
foreach ($uRevStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rv) {
    $uRevMap[$rv['room_unit_number'] ?? 'null'] = $rv;
}

function tdCountUnitFiles($conn, $client_id, $area, $room_unit_number) {
    $s = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=? AND room_unit_number=?");
    $s->bind_param("isi", $client_id, $area, $room_unit_number); $s->execute();
    return $s->get_result()->fetch_row()[0] ?? 0;
}

// All approvers
$allApprStmt = $conn->prepare("SELECT id, full_name, role FROM account WHERE (role IN ('general_manager','operational_manager')) OR (role = 'technical_designer' AND is_head=1) ORDER BY role");
$allApprStmt->execute();
$allApprovers = $allApprStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Units — <?= htmlspecialchars($area) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f5f1ed; font-family:'Segoe UI',sans-serif; }
        .container { max-width:700px; margin:30px auto; padding:0 20px; }
        .btn-back { background:linear-gradient(135deg,#0c4a6e,#0369a1); color:white; padding:8px 16px; border:none; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:6px; text-decoration:none; margin-bottom:16px; }
        .page-header { background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%); padding:24px 30px; border-radius:14px; color:white; margin-bottom:22px; }
        .page-header h1 { font-size:20px; margin-bottom:4px; }
        .page-header .sub { font-size:12px; opacity:0.85; margin-top:4px; }
        .card { background:white; border-radius:12px; padding:24px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .unit-card { display:flex; align-items:center; justify-content:space-between; padding:15px 18px; border:1.5px solid #e0e7ff; border-radius:10px; margin-bottom:10px; background:white; transition:all 0.2s; }
        .unit-card:hover { border-color:#6366f1; background:#f0f4ff; }
        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    </style>
</head>
<body>
<div class="container">
    <a href="td-attachments?client_id=<?= $client_id ?>" class="btn-back">
        <i class="fas fa-arrow-left"></i> Back to Areas
    </a>
    <div class="page-header">
        <h1><i class="fas fa-layer-group"></i> <?= htmlspecialchars($area) ?></h1>
        <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> — Select a unit to manage attachments</div>
    </div>

    <?php
$tdPendingAreaCount = getTDPendingApprovalCountForArea($conn, $admin_id, $client_id, $area);
if ($tdPendingAreaCount > 0):
?>
<div style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <i class="fas fa-bell" style="color:#d97706; font-size:20px; flex-shrink:0;"></i>
    <div>
        <div style="font-weight:700; font-size:14px; color:#92400e;">
            You have <?= $tdPendingAreaCount ?> pending approval<?= $tdPendingAreaCount > 1 ? 's' : '' ?> in this area
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
        <?php foreach ($units as $unit):
            $unitLabel = !empty($unit['room_unit_name']) ? $unit['room_unit_name'] : 'Unit ' . $unit['room_unit_number'];
            $fileCount = tdCountUnitFiles($conn, $client_id, $area, $unit['room_unit_number']);
            $unitRev   = $uRevMap[$unit['room_unit_number']] ?? null;
            $url = BASE_URL . 'td-attachment-upload?client_id='.$client_id.'&area='.urlencode($area).'&room_unit_number='.$unit['room_unit_number'].'&room_unit_name='.urlencode($unit['room_unit_name'] ?? '');

            // Approval state for unit
            $uApSt = $conn->prepare("SELECT la.status, a.id as approver_id FROM td_attachment_approvals la JOIN account a ON la.approver_id=a.id WHERE la.client_id=? AND la.area=? AND la.room_unit_number=?");
            $uApSt->bind_param("isi", $client_id, $area, $unit['room_unit_number']); $uApSt->execute();
            $uApRows    = $uApSt->get_result()->fetch_all(MYSQLI_ASSOC);
            $uApMap     = [];
            foreach ($uApRows as $rec) $uApMap[$rec['approver_id']] = $rec;
            $uApStatuses = array_column($uApRows, 'status');

            if (empty($uApRows)) { $uBorder = '#e0e7ff'; $uBg = 'white'; $uBadge = ''; }
            elseif (in_array('rejected', $uApStatuses)) { $uBorder = '#ef4444'; $uBg = '#fff5f5'; $uBadge = '<span class="badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-times-circle"></i> Rejected</span>'; }
            elseif (count(array_filter($uApStatuses, fn($s)=>$s==='approved'))===count($uApStatuses)) { $uBorder = '#10b981'; $uBg = '#f0fdf4'; $uBadge = '<span class="badge" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i> Approved</span>'; }
            elseif (in_array('pending', $uApStatuses)) { $uBorder = '#f59e0b'; $uBg = '#fffbeb'; $uBadge = '<span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i> Pending</span>'; }
            else { $uBorder = '#e0e7ff'; $uBg = 'white'; $uBadge = ''; }

            // Per-approver badges
            $aprBadgesHtml = '<div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:5px;">';
            foreach ($allApprovers as $apr) {
                $rec     = $uApMap[$apr['id']] ?? null;
                $aStatus = $rec ? $rec['status'] : 'not_requested';
                $bBg = '#f3f4f6'; $bColor = '#9ca3af'; $bIcon = 'fa-minus-circle';
                if ($aStatus === 'approved') { $bBg = '#d1fae5'; $bColor = '#065f46'; $bIcon = 'fa-check-circle'; }
                elseif ($aStatus === 'rejected') { $bBg = '#fee2e2'; $bColor = '#991b1b'; $bIcon = 'fa-times-circle'; }
                elseif ($aStatus === 'pending') { $bBg = '#fef3c7'; $bColor = '#92400e'; $bIcon = 'fa-hourglass-half'; }
                $sn = explode(' ', $apr['full_name'])[0];
                $aprBadgesHtml .= '<span style="display:inline-flex; align-items:center; gap:3px; background:'.$bBg.'; color:'.$bColor.'; padding:2px 7px; border-radius:20px; font-size:10px; font-weight:700;"><i class="fas '.$bIcon.'" style="font-size:9px;"></i> '.htmlspecialchars($sn).'</span>';
            }
            $aprBadgesHtml .= '</div>';
        ?>
        <?php if ($unitRev): ?>
        <div style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:8px 14px; margin-bottom:4px; font-size:12px; color:#92400e; font-weight:600; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-redo"></i> Revision #<?= $unitRev['revision_number'] ?> Pending
            <span style="font-weight:400;"><?= date('M d, Y', strtotime($unitRev['created_at'])) ?></span>
        </div>
        <?php endif; ?>
        <div class="unit-card" style="border-color:<?= $unitRev ? '#f59e0b' : $uBorder ?>; background:<?= $unitRev ? '#fffbeb' : $uBg ?>;">
            <a href="<?= $url ?>" style="display:flex; align-items:center; gap:12px; text-decoration:none; flex:1;">
                <div style="width:42px; height:42px; background:#e0e7ff; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="fas fa-door-open" style="color:#6366f1; font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:15px; font-weight:700; color:#1e1b4b;"><?= htmlspecialchars($unitLabel) ?></div>
                    <div style="font-size:11px; color:#9ca3af; margin-top:3px;">
                        <?= $unit['total_quantity'] ?> item(s) &nbsp;•&nbsp;
                        <?php if ($fileCount > 0): ?>
                        <span class="badge" style="background:#d1fae5;color:#065f46;"><i class="fas fa-paperclip"></i> <?= $fileCount ?> file(s)</span>
                        <?php else: ?>
                        <span class="badge" style="background:#f3f4f6;color:#9ca3af;"><i class="fas fa-inbox"></i> No files</span>
                        <?php endif; ?>
                        <?php if ($uBadge): ?>&nbsp;<?= $uBadge ?><?php endif; ?>
                        <?= $aprBadgesHtml ?>
                    </div>
                </div>
            </a>
            <a href="<?= $url ?>" style="color:#9ca3af; text-decoration:none;">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>