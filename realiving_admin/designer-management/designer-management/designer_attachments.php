<?php
//designer_attachments.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif ──
function getPendingApprovalCount($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
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
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'sales'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$isAssigned = ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id);
if (!$isAssigned && !$canViewAll)
    die("Access denied.");

$intakeStmt = $conn->prepare("SELECT layout_type_2d, layout_type_3d FROM layout_intake WHERE client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$intake = $intakeStmt->get_result()->fetch_assoc();

$areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
$areasStmt->bind_param("ii", $client_id, $client_id);
$areasStmt->execute();
$areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

// Fetch active pending revision log entries for this client
$activeRevStmt = $conn->prepare("
    SELECT area, room_unit_number, reason, revision_number, created_at
    FROM layout_revision_log
    WHERE client_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$activeRevStmt->bind_param("i", $client_id);
$activeRevStmt->execute();
$activeRevRows = $activeRevStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Index by area+unit for quick lookup
$activeRevMap = [];
foreach ($activeRevRows as $rv) {
    $mapKey = $rv['area'] . '||' . ($rv['room_unit_number'] ?? 'null');
    $activeRevMap[$mapKey] = $rv;
}

// Count total attachments per area
function countAreaAttachments($conn, $client_id, $area)
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM layout_attachments WHERE client_id = ? AND area = ?");
    $stmt->bind_param("is", $client_id, $area);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_row();
    $stmt->close();
    return $row[0] ?? 0;
}

// Check if area has units
function areaHasUnits($conn, $client_id, $area)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM quotation_room_distribution rd
        INNER JOIN quotation_entries qe ON rd.quotation_entry_id = qe.id
        WHERE qe.client_id = ? AND qe.area = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $client_id, $area);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (($row[0] ?? 0) > 0)
        return true;

    $stmt2 = $conn->prepare("
        SELECT COUNT(*) FROM quotation_room_distribution rd
        INNER JOIN quotation_fixed_sizes qfs ON rd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = ? AND qfs.area = ?
        LIMIT 1
    ");
    $stmt2->bind_param("is", $client_id, $area);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_row();
    $stmt2->close();
    return ($row2[0] ?? 0) > 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attachments — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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
            border: 1.5px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            text-decoration: none;
            background: white;
            transition: all 0.2s;
        }

        .area-card:hover {
            border-color: #8a5a44;
            background: #fdf6f0;
            transform: translateX(4px);
        }

        .area-card .left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .area-icon {
            width: 42px;
            height: 42px;
            background: #f0e6db;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .area-name {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
        }

        .area-meta {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
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

        .badge-unit {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-files {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-nofile {
            background: #f3f4f6;
            color: #9ca3af;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
        }

        .warning-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13px;
            color: #92400e;
            margin-bottom: 16px;
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="designer-2d3d-layout?client_id=<?= $client_id ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Layout
        </a>

        <div class="page-header">
            <h1><i class="fas fa-paperclip"></i> Attachments</h1>
            <div class="sub"><?= htmlspecialchars($clientInfo['clientname']) ?> —
                <?= htmlspecialchars($clientInfo['nameproject']) ?>
            </div>
            <div class="sub">Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?> &nbsp;•&nbsp;
                <?= htmlspecialchars($me['full_name']) ?>
            </div>
        </div>

        <?php
        $pendingApprovalCount = getPendingApprovalCount($conn, $admin_id, $client_id);
        if ($pendingApprovalCount > 0):
            ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <i class="fas fa-bell" style="color:#d97706; font-size:20px; flex-shrink:0;"></i>
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:14px; color:#92400e;">
                        You have <?= $pendingApprovalCount ?> pending approval<?= $pendingApprovalCount > 1 ? 's' : '' ?> —
                        click an area below to review
                    </div>
                    <div style="font-size:12px; color:#b45309; margin-top:2px;">
                        Areas highlighted in yellow/orange have pending approvals waiting for you.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($intake)): ?>
            <div class="warning-box"><i class="fas fa-exclamation-triangle"></i> Please submit the intake form first before
                uploading attachments.</div>
        <?php elseif (empty($areas)): ?>
            <div class="card">
                <div class="empty-state"><i class="fas fa-inbox"></i>No areas found. Add items to the computation list
                    first.</div>
            </div>
        <?php else: ?>
            <div class="card">
                <h2
                    style="font-size:15px; color:#3b1f0f; margin-bottom:16px; padding-bottom:10px; border-bottom:2px solid #f5f1ed;">
                    <i class="fas fa-map-marker-alt"></i> Select an Area
                </h2>
                <?php foreach ($areas as $area): ?>
                    <?php
                    $hasUnits = areaHasUnits($conn, $client_id, $area);
                    $fileCount = countAreaAttachments($conn, $client_id, $area);
                    $url = BASE_URL . 'designer-attachment-area?client_id=' . $client_id
                        . '&area=' . urlencode($area);

                    // Approval summary for color coding
                    // Fetch detailed approval records for this area (with approver info)
                    // For areas with units, aggregate approval status across ALL units
// For areas without units, check NULL unit approvals as before
                    if ($hasUnits) {
                        $approvalSummaryStmt = $conn->prepare("
        SELECT la.status, la.comment, la.responded_at,
               a.id as approver_id, a.full_name as approver_name, a.role as approver_role
        FROM layout_approvals la
        JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.area = ?
        AND la.room_unit_number IS NOT NULL
    ");
                    } else {
                        $approvalSummaryStmt = $conn->prepare("
        SELECT la.status, la.comment, la.responded_at,
               a.id as approver_id, a.full_name as approver_name, a.role as approver_role
        FROM layout_approvals la
        JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.area = ?
        AND la.room_unit_number IS NULL
    ");
                    }
                    $approvalSummaryStmt->bind_param("is", $client_id, $area);
                    $approvalSummaryStmt->execute();
                    $approvalRows = $approvalSummaryStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                    // Also get list of all approvers for this system
                    $allApproversStmt = $conn->prepare("
        SELECT id, full_name, role FROM account
        WHERE (role IN ('general_manager','operational_manager'))
           OR (role IN ('designer','technical_designer') AND is_head = 1)
        ORDER BY role
    ");
                    $allApproversStmt->execute();
                    $allApprovers = $allApproversStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                    // Build map: approver_id => record
                    $areaApprovalMap = [];
                    foreach ($approvalRows as $rec) {
                        $areaApprovalMap[$rec['approver_id']] = $rec;
                    }

                    if (empty($approvalRows)) {
                        $areaApprovalState = 'none';
                    } else {
                        $aStatuses = array_column($approvalRows, 'status');
                        if (in_array('rejected', $aStatuses))
                            $areaApprovalState = 'rejected';
                        elseif (count(array_filter($aStatuses, fn($s) => $s === 'approved')) === count($aStatuses) && count($aStatuses) > 0)
                            $areaApprovalState = 'approved';
                        elseif (in_array('pending', $aStatuses))
                            $areaApprovalState = 'pending';
                        else
                            $areaApprovalState = 'none';
                    }

                    // Color scheme per approval state
                    $cardBorder = '#e9ecef';
                    $cardBg = 'white';
                    $approvalBadge = '';
                    if ($areaApprovalState === 'approved') {
                        $cardBorder = '#10b981';
                        $cardBg = '#f0fdf4';
                        $approvalBadge = '<span class="badge" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i> All Approved</span>';
                    } elseif ($areaApprovalState === 'rejected') {
                        $cardBorder = '#ef4444';
                        $cardBg = '#fff5f5';
                        $approvalBadge = '<span class="badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-times-circle"></i> Rejected</span>';
                    } elseif ($areaApprovalState === 'pending') {
                        $cardBorder = '#f59e0b';
                        $cardBg = '#fffbeb';
                        $approvalBadge = '<span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i> Pending Review</span>';
                    }

                    // Build approver badges HTML
                    $approverBadgesHtml = '';
                    if (!empty($allApprovers)) {
                        $approverBadgesHtml .= '<div style="display:flex; flex-wrap:wrap; gap:5px; margin-top:8px;">';
                        foreach ($allApprovers as $apr) {
                            $rec = $areaApprovalMap[$apr['id']] ?? null;
                            $aStatus = $rec ? $rec['status'] : 'not_requested';

                            if ($aStatus === 'approved') {
                                $bBg = '#d1fae5';
                                $bColor = '#065f46';
                                $bIcon = 'fa-check-circle';
                                $bTitle = htmlspecialchars($apr['full_name']) . ': Approved';
                            } elseif ($aStatus === 'rejected') {
                                $bBg = '#fee2e2';
                                $bColor = '#991b1b';
                                $bIcon = 'fa-times-circle';
                                $bTitle = htmlspecialchars($apr['full_name']) . ': Rejected';
                                if ($rec['comment'])
                                    $bTitle .= ' — ' . htmlspecialchars($rec['comment']);
                            } elseif ($aStatus === 'pending') {
                                $bBg = '#fef3c7';
                                $bColor = '#92400e';
                                $bIcon = 'fa-hourglass-half';
                                $bTitle = htmlspecialchars($apr['full_name']) . ': Pending';
                            } else {
                                $bBg = '#f3f4f6';
                                $bColor = '#9ca3af';
                                $bIcon = 'fa-minus-circle';
                                $bTitle = htmlspecialchars($apr['full_name']) . ': Not requested';
                            }

                            $shortName = explode(' ', $apr['full_name'])[0]; // First name only
                            $approverBadgesHtml .= '<span title="' . $bTitle . '" style="display:inline-flex; align-items:center; gap:4px; background:' . $bBg . '; color:' . $bColor . '; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:700;">';
                            $approverBadgesHtml .= '<i class="fas ' . $bIcon . '" style="font-size:10px;"></i> ' . htmlspecialchars($shortName);
                            $approverBadgesHtml .= '</span>';
                        }
                        $approverBadgesHtml .= '</div>';
                    }
                    ?>
                    <?php
                    // Check if this area (no unit) has an active revision
                    $areaRevKey = $area . '||null';
                    $areaActiveRev = $activeRevMap[$areaRevKey] ?? null;
                    ?>
                    <?php if ($areaActiveRev): ?>
                        <div
                            style="background:#fef3c7; border:2px solid #f59e0b; border-radius:8px; padding:8px 14px; margin-bottom:4px; display:flex; align-items:center; gap:8px; font-size:12px; color:#92400e; font-weight:600;">
                            <i class="fas fa-redo"></i>
                            Revision #<?= $areaActiveRev['revision_number'] ?> Pending
                            <span
                                style="font-weight:400; margin-left:4px;"><?= date('M d, Y', strtotime($areaActiveRev['created_at'])) ?></span>
                            <span
                                style="font-weight:400; margin-left:4px; font-style:italic;"><?= htmlspecialchars(mb_strimwidth($areaActiveRev['reason'], 0, 60, '...')) ?></span>
                        </div>
                    <?php endif; ?>
                    <a href="<?= $url ?>" class="area-card"
                        style="border-color:<?= $areaActiveRev ? '#f59e0b' : $cardBorder ?>; background:<?= $areaActiveRev ? '#fffbeb' : $cardBg ?>;">
                        <div class="left">
                            <div class="area-icon">
                                <i class="fas fa-layer-group" style="color:#8a5a44; font-size:18px;"></i>
                            </div>
                            <div>
                                <div class="area-name"><?= htmlspecialchars($area) ?></div>
                                <div class="area-meta">
                                    <?php if ($hasUnits): ?>
                                        <span class="badge badge-unit"><i class="fas fa-building"></i> Has Units</span>
                                    <?php else: ?>
                                        <span class="badge badge-nofile"><i class="fas fa-home"></i> No Units</span>
                                    <?php endif; ?>
                                    &nbsp;
                                    <?php if ($fileCount > 0): ?>
                                        <span class="badge badge-files"><i class="fas fa-file"></i> <?= $fileCount ?> file(s)</span>
                                    <?php endif; ?>
                                    <?php if ($approvalBadge): ?>
                                        &nbsp;<?= $approvalBadge ?>
                                    <?php endif; ?>
                                    <?php if (!$hasUnits): ?>
                                        <?= $approverBadgesHtml ?>
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