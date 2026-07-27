<?php
// td_attachments.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif for TD ──
function getTDPendingApprovalCount($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM td_attachment_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
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
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

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

// Distinct areas
$areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
$areasStmt->bind_param("ii", $client_id, $client_id);
$areasStmt->execute();
$areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

function tdCountAreaFiles($conn, $client_id, $area)
{
    $s = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=?");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    return $s->get_result()->fetch_row()[0] ?? 0;
}

function tdAreaHasUnits($conn, $client_id, $area)
{
    $s = $conn->prepare("
        SELECT COUNT(*) FROM quotation_room_distribution rd
        INNER JOIN quotation_entries qe ON rd.quotation_entry_id = qe.id
        WHERE qe.client_id=? AND qe.area=? LIMIT 1
    ");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    if ($s->get_result()->fetch_row()[0] > 0)
        return true;
    $s2 = $conn->prepare("
        SELECT COUNT(*) FROM quotation_room_distribution rd
        INNER JOIN quotation_fixed_sizes qfs ON rd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id=? AND qfs.area=? LIMIT 1
    ");
    $s2->bind_param("is", $client_id, $area);
    $s2->execute();
    return $s2->get_result()->fetch_row()[0] > 0;
}

// Fetch pending revisions for this client
$revStmt = $conn->prepare("
    SELECT area, room_unit_number, reason, revision_number, created_at
    FROM td_revision_log WHERE client_id=? AND status='pending' ORDER BY created_at DESC
");
$revStmt->bind_param("i", $client_id);
$revStmt->execute();
$revRows = $revStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$revMap = [];
foreach ($revRows as $rv)
    $revMap[$rv['area'] . '||' . ($rv['room_unit_number'] ?? 'null')] = $rv;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Attachments — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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
            max-width: 700px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .btn-back {
            background: linear-gradient(135deg, #0c4a6e, #0369a1);
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
            background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 100%);
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

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 18px;
        }

        .area-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border: 1.5px solid #e0f2fe;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            text-decoration: none;
            background: white;
            transition: all 0.2s;
        }

        .area-card:hover {
            border-color: #0369a1;
            background: #f0f9ff;
            transform: translateX(4px);
        }

        .area-icon {
            width: 42px;
            height: 42px;
            background: #e0f2fe;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .area-name {
            font-size: 15px;
            font-weight: 700;
            color: #0c4a6e;
        }

        .area-meta {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="td-layoutclient_id=<?= $client_id ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to TD Layout
        </a>

        <div class="page-header">
            <h1><i class="fas fa-paperclip"></i> TD Attachments</h1>
            <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> —
                <?= htmlspecialchars($clientInfo['nameproject']) ?>
            </div>
            <div class="sub">Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?></div>
        </div>

        <?php
        $tdPendingCount = getTDPendingApprovalCount($conn, $admin_id, $client_id);
        if ($tdPendingCount > 0):
            ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <i class="fas fa-bell" style="color:#d97706; font-size:20px; flex-shrink:0;"></i>
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:14px; color:#92400e;">
                        You have <?= $tdPendingCount ?> pending approval<?= $tdPendingCount > 1 ? 's' : '' ?> — click an
                        area below to review
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:2px;">
                        Areas highlighted in yellow/orange have pending approvals waiting for you.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($areas)): ?>
            <div class="card" style="text-align:center; padding:40px; color:#9ca3af;">
                <i class="fas fa-inbox" style="font-size:40px; display:block; margin-bottom:12px;"></i>
                No areas found. The designer needs to add items to the computation list first.
            </div>
        <?php else: ?>
            <div class="card">
                <h2
                    style="font-size:15px; color:#0c4a6e; margin-bottom:16px; padding-bottom:10px; border-bottom:2px solid #e0f2fe;">
                    <i class="fas fa-map-marker-alt"></i> Select an Area
                </h2>
                <?php foreach ($areas as $area):
                    $hasUnits = tdAreaHasUnits($conn, $client_id, $area);
                    $fileCount = tdCountAreaFiles($conn, $client_id, $area);
                    $revKey = $area . '||null';
                    $areaRev = $revMap[$revKey] ?? null;
                    $url = BASE_URL . 'td-attachment-area?client_id=' . $client_id . '&area=' . urlencode($area);

                    // Approval state for this area
                    if ($hasUnits) {
                        $apSt = $conn->prepare("SELECT taa.status, a.full_name, a.role FROM td_attachment_approvals taa JOIN account a ON taa.approver_id=a.id WHERE taa.client_id=? AND taa.area=? AND taa.room_unit_number IS NOT NULL GROUP BY taa.approver_id");
                    } else {
                        $apSt = $conn->prepare("SELECT taa.status, a.full_name, a.role FROM td_attachment_approvals taa JOIN account a ON taa.approver_id=a.id WHERE taa.client_id=? AND taa.area=? AND taa.room_unit_number IS NULL");
                    }
                    $apSt->bind_param("is", $client_id, $area);
                    $apSt->execute();
                    $apRows = $apSt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $apStatuses = array_column($apRows, 'status');

                    if (empty($apRows)) {
                        $cardBorder = '#e0f2fe';
                        $cardBg = 'white';
                    } elseif (in_array('rejected', $apStatuses)) {
                        $cardBorder = '#ef4444';
                        $cardBg = '#fff5f5';
                    } elseif (count(array_filter($apStatuses, fn($s) => $s === 'approved')) === count($apStatuses)) {
                        $cardBorder = '#10b981';
                        $cardBg = '#f0fdf4';
                    } elseif (in_array('pending', $apStatuses)) {
                        $cardBorder = '#f59e0b';
                        $cardBg = '#fffbeb';
                    } else {
                        $cardBorder = '#e0f2fe';
                        $cardBg = 'white';
                    }

                    // Check if remark is needed for this area
                    $remarkAreaStmt = null;
                    if ($room_unit_number_check = null) {
                    } // reset
                    $remarkAreaNeeded = false;
                    if ($isAssigned) {
                        if ($hasUnits) {
                            $rmkStmt = $conn->prepare("
            SELECT COUNT(*) FROM layout_approvals
            WHERE client_id = ? AND area = ?
            AND room_unit_number IS NOT NULL
            AND (td_remark IS NULL OR td_remark = '')
            AND requested_at IS NOT NULL
        ");
                            $rmkStmt->bind_param("is", $client_id, $area);
                        } else {
                            $rmkStmt = $conn->prepare("
            SELECT COUNT(*) FROM layout_approvals
            WHERE client_id = ? AND area = ?
            AND room_unit_number IS NULL
            AND (td_remark IS NULL OR td_remark = '')
            AND requested_at IS NOT NULL
        ");
                            $rmkStmt->bind_param("is", $client_id, $area);
                        }
                        $rmkStmt->execute();
                        $remarkAreaNeeded = (int) $rmkStmt->get_result()->fetch_row()[0] > 0;
                    }

                    // Build per-approver badges (shown for no-unit areas inline; unit areas handled inside area page)
                    $apBadge = '';
                    if (!$hasUnits && !empty($apRows)) {
                        foreach ($apRows as $apr) {
                            if ($apr['status'] === 'approved') {
                                $bc = '#d1fae5';
                                $tc = '#065f46';
                                $ic = 'fa-check-circle';
                            } elseif ($apr['status'] === 'rejected') {
                                $bc = '#fee2e2';
                                $tc = '#991b1b';
                                $ic = 'fa-times-circle';
                            } else {
                                $bc = '#fef3c7';
                                $tc = '#92400e';
                                $ic = 'fa-hourglass-half';
                            }
                            $shortName = explode(' ', trim($apr['full_name']))[0]; // first name only
                            $apBadge .= '<span class="badge" style="background:' . $bc . ';color:' . $tc . ';"><i class="fas ' . $ic . '"></i> ' . htmlspecialchars($shortName) . '</span> ';
                        }
                    } elseif ($hasUnits && !empty($apRows)) {
                        // For has-units areas just show the overall summary badge
                        if (in_array('rejected', $apStatuses)) {
                            $apBadge = '<span class="badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-times-circle"></i> Rejected</span>';
                        } elseif (count(array_filter($apStatuses, fn($s) => $s === 'approved')) === count($apStatuses)) {
                            $apBadge = '<span class="badge" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i> Approved</span>';
                        } else {
                            $apBadge = '<span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i> Pending</span>';
                        }
                    }
                    ?>
                    <?php if ($areaRev): ?>
                        <div
                            style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:8px 14px; margin-bottom:4px; font-size:12px; color:#92400e; font-weight:600; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-redo"></i> Revision #<?= $areaRev['revision_number'] ?> Pending
                            <span style="font-weight:400;"><?= date('M d, Y', strtotime($areaRev['created_at'])) ?></span>
                            <span
                                style="font-weight:400; font-style:italic;"><?= htmlspecialchars(mb_strimwidth($areaRev['reason'], 0, 60, '...')) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php
                    $finalBorder = $areaRev ? '#f59e0b' : ($remarkAreaNeeded ? '#93c5fd' : $cardBorder);
                    $finalBg = $areaRev ? '#fffbeb' : ($remarkAreaNeeded ? '#eff6ff' : $cardBg);
                    ?>
                    <a href="<?= $url ?>" class="area-card"
                        style="border-color:<?= $finalBorder ?>; background:<?= $finalBg ?>;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div class="area-icon"><i class="fas fa-layer-group" style="color:#0369a1; font-size:18px;"></i>
                            </div>
                            <div>
                                <div class="area-name"><?= htmlspecialchars($area) ?></div>
                                <div class="area-meta">
                                    <?php if ($hasUnits): ?>
                                        <span class="badge" style="background:#dbeafe;color:#1e40af;"><i
                                                class="fas fa-building"></i> Has Units</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f3f4f6;color:#9ca3af;"><i class="fas fa-home"></i> No
                                            Units</span>
                                    <?php endif; ?>
                                    &nbsp;
                                    <?php if ($fileCount > 0): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46;"><i class="fas fa-file"></i>
                                            <?= $fileCount ?> file(s)</span>
                                    <?php endif; ?>
                                    <?php if ($apBadge): ?>&nbsp;<?= $apBadge ?><?php endif; ?>
                                    <?php if ($remarkAreaNeeded): ?>
                                        &nbsp;<span class="badge"
                                            style="background:#eff6ff;color:#1e40af;border:1px solid #93c5fd;"><i
                                                class="fas fa-comment-medical"></i> Remark Needed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right" style="color:#9ca3af;"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>