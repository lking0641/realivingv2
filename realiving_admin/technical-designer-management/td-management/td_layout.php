<?php
// td_layout.php
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

$allowedRoles = ['technical_designer', 'general_manager', 'operational_manager', 'project_coordinator'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager'])
    || ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

$canSeeTrackerBtn = ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

// ── Client info ────────────────────────────────────────────────────────────
$ciStmt = $conn->prepare("
    SELECT u.*,
           d1.full_name AS designer1_name,
           d2.full_name AS designer2_name,
           td.full_name AS tech_designer_name,
           pc.full_name AS coordinator_name
    FROM user_info u
    LEFT JOIN account d1 ON u.designer1_id   = d1.id
    LEFT JOIN account d2 ON u.designer2_id   = d2.id
    LEFT JOIN account td ON u.technical_designer_id = td.id
    LEFT JOIN account pc ON u.project_coordinator_id = pc.id
    WHERE u.id = ?
");
$ciStmt->bind_param("i", $client_id);
$ciStmt->execute();
$clientInfo = $ciStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$business_type_label = ($clientInfo['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($clientInfo['business_type'] ?? '');

$isAssigned = ($clientInfo['technical_designer_id'] == $admin_id);
if (!$isAssigned && !$canViewAll)
    die("Access denied: You are not assigned to this client.");

$isTDHead = ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

// Back URL
$backToList = BASE_URL . 'td-layout-list';
$backToTracker = BASE_URL . 'unified-project-tracker?client_id=' . $client_id;

// Fetch revision log for this client
$revLogStmt = $conn->prepare("
    SELECT rl.*, a.full_name as requester_name
    FROM td_revision_log rl
    LEFT JOIN account a ON rl.requested_by = a.id
    WHERE rl.client_id = ?
    ORDER BY rl.created_at DESC
");
$revLogStmt->bind_param("i", $client_id);
$revLogStmt->execute();
$revisionLogs = $revLogStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch areas for revision selector
$tdAreasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
$tdAreasStmt->bind_param("ii", $client_id, $client_id);
$tdAreasStmt->execute();
$tdAreas = array_column($tdAreasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// ── Designer intake (read-only reference) ─────────────────────────────────
$intakeStmt = $conn->prepare("SELECT li.*, a.full_name as submitter_name FROM layout_intake li LEFT JOIN account a ON li.submitted_by = a.id WHERE li.client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$designerIntake = $intakeStmt->get_result()->fetch_assoc();

// ── Assign TD (TD Head only) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_td' && $isTDHead) {
    $new_td = !empty($_POST['technical_designer_id']) ? intval($_POST['technical_designer_id']) : null;
    $upd = $conn->prepare("UPDATE user_info SET technical_designer_id=? WHERE id=?");
    $upd->bind_param("ii", $new_td, $client_id);
    $upd->execute();
    header("Location: " . BASE_URL . "td-layout?client_id={$client_id}&success=" . urlencode("Technical Designer assigned."));
    exit();
}

// ── TD team list for assignment ──────────────────────────────────────────
$tdList = [];
if ($isTDHead) {
    $tdListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role='technical_designer' ORDER BY full_name");
    $tdListStmt->execute();
    $tdList = $tdListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Tracker status ────────────────────────────────────────────────────────
$trkStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id=? AND stage_name='2D / 3D Layout'");
$trkStmt->bind_param("i", $client_id);
$trkStmt->execute();
$layoutTrackerStatus = ($trkStmt->get_result()->fetch_assoc())['status'] ?? 'Pending';

// ── Cuttinglist tracker status ────────────────────────────────────────────
$cutTrkStmt = $conn->prepare("SELECT id, status FROM project_tracker WHERE client_id=? AND stage_name='Cuttinglist'");
$cutTrkStmt->bind_param("i", $client_id);
$cutTrkStmt->execute();
$cuttingTrackerRow = $cutTrkStmt->get_result()->fetch_assoc();
$cuttingTrackerStatus = $cuttingTrackerRow['status'] ?? 'Pending';
$cuttingTrackerId = $cuttingTrackerRow['id'] ?? null;

// ── Check if ALL areas are fully approved in td_attachment_approvals ──────
$allAreasApproved = false;
if (!empty($tdAreas)) {
    $approvedAreaCount = 0;
    foreach ($tdAreas as $checkArea) {
        $aChkStmt = $conn->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count
            FROM td_attachment_approvals 
            WHERE client_id = ? AND area = ?
        ");
        $aChkStmt->bind_param("is", $client_id, $checkArea);
        $aChkStmt->execute();
        $aChkRow = $aChkStmt->get_result()->fetch_assoc();
        if ($aChkRow['total'] > 0 && $aChkRow['total'] == $aChkRow['approved_count']) {
            $approvedAreaCount++;
        }
    }
    $allAreasApproved = ($approvedAreaCount === count($tdAreas));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Layout — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px 60px;
        }

        .btn-nav {
            color: white;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
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

        .card {
            background: white;
            border-radius: 12px;
            padding: 26px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 22px;
        }

        .card h2 {
            font-size: 16px;
            color: #0c4a6e;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f9ff;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .form-control {
            padding: 9px 13px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            color: #111;
            transition: border-color 0.2s;
            font-family: inherit;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: #0369a1;
        }

        .btn-blue {
            background: linear-gradient(135deg, #0c4a6e, #0369a1);
        }

        .btn-brown {
            background: linear-gradient(135deg, #3b1f0f, #7a4528);
        }

        .intake-item {
            background: #f0f9ff;
            border-radius: 8px;
            padding: 14px;
            border-left: 3px solid #0369a1;
        }

        .intake-item .label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .intake-item .value {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- Back buttons -->
        <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
            <a href="<?= $backToList ?>" class="btn-nav btn-blue"><i class="fas fa-arrow-left"></i> Back to List</a>
            <?php if ($canSeeTrackerBtn): ?>
                <a href="<?= $backToTracker ?>" class="btn-nav btn-brown"><i class="fas fa-chart-line"></i> Back to
                    Tracker</a>
            <?php endif; ?>
        </div>

        <?php
        // Fetch rejected TD approvals — only shown to the assigned TD
        $rejectedTDItems = [];
        if ($isAssigned) {
            $rejTDStmt = $conn->prepare("
        SELECT taa.area, taa.room_unit_number, taa.comment, taa.responded_at,
               a.full_name as rejected_by_name
        FROM td_attachment_approvals taa
        LEFT JOIN account a ON taa.approver_id = a.id
        WHERE taa.client_id = ? AND taa.status = 'rejected'
        ORDER BY taa.responded_at DESC
    ");
            $rejTDStmt->bind_param("i", $client_id);
            $rejTDStmt->execute();
            $rejectedTDItems = $rejTDStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        ?>

        <?php
        // Remark needed banner
        if ($isAssigned) {
            $remarkNeededStmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals
        WHERE client_id = ?
        AND (td_remark IS NULL OR td_remark = '')
        AND requested_at IS NOT NULL
    ");
            $remarkNeededStmt->bind_param("i", $client_id);
            $remarkNeededStmt->execute();
            $remarkNeededCount = (int) $remarkNeededStmt->get_result()->fetch_row()[0];
            if ($remarkNeededCount > 0):
                ?>
                <div
                    style="background:#eff6ff; border:2px solid #93c5fd; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-comment-medical" style="color:#2563eb; font-size:20px;"></i>
                        <div>
                            <div style="font-weight:700; font-size:14px; color:#1e40af;">
                                Some areas need your technical remark
                            </div>
                            <div style="font-size:12px; color:#3b82f6; margin-top:2px;">
                                The designer has requested approval but your remark is missing. Go to TD Attachments to submit.
                            </div>
                        </div>
                    </div>
                    <a href="td-attachments?client_id=<?= $client_id ?>"
                        style="background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:white; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:7px; white-space:nowrap;">
                        <i class="fas fa-arrow-right"></i> Go to TD Attachments
                    </a>
                </div>
                <?php
            endif;
        }
        ?>

        <?php
        $tdPendingCount = getTDPendingApprovalCount($conn, $admin_id, $client_id);
        if ($tdPendingCount > 0):
            ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-bell" style="color:#d97706; font-size:20px;"></i>
                    <div>
                        <div style="font-weight:700; font-size:14px; color:#92400e;">
                            You have <?= $tdPendingCount ?> pending approval<?= $tdPendingCount > 1 ? 's' : '' ?> for this
                            client
                        </div>
                        <div style="font-size:12px; color:#b45309; margin-top:2px;">
                            Go to TD Attachments to review and approve or reject.
                        </div>
                    </div>
                </div>
                <a href="td-attachments?client_id=<?= $client_id ?>"
                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:7px; white-space:nowrap;">
                    <i class="fas fa-arrow-right"></i> Go to TD Attachments
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($rejectedTDItems) && $isAssigned): ?>
            <div
                style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:14px 20px; margin-bottom:18px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                    <i class="fas fa-times-circle" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:14px; color:#991b1b;">
                            <?= count($rejectedTDItems) ?> TD area<?= count($rejectedTDItems) > 1 ? 's/units' : '/unit' ?>
                            rejected — action required
                        </div>
                        <div style="font-size:12px; color:#b91c1c; margin-top:2px;">
                            Go to <strong>TD Attachments</strong> to review the rejection comments and resubmit updated
                            files.
                        </div>
                    </div>
                    <a href="td-attachments?client_id=<?= $client_id ?>"
                        style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:7px; white-space:nowrap; flex-shrink:0;">
                        <i class="fas fa-arrow-right"></i> Go to TD Attachments
                    </a>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($rejectedTDItems as $rej): ?>
                        <div style="background:white; border:1px solid #fca5a5; border-radius:8px; padding:10px 14px;">
                            <div style="font-size:13px; font-weight:700; color:#991b1b;">
                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($rej['area']) ?>
                                <?php if ($rej['room_unit_number']): ?>
                                    <span style="color:#6b7280; font-weight:400;"> › </span>
                                    <i class="fas fa-door-open"></i> Unit <?= $rej['room_unit_number'] ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($rej['comment']): ?>
                                <div
                                    style="font-size:12px; color:#7f1d1d; background:#fff5f5; padding:6px 10px; border-radius:6px; margin-top:6px; border-left:3px solid #ef4444; font-style:italic;">
                                    <i class="fas fa-comment-slash"></i> "<?= htmlspecialchars($rej['comment']) ?>"
                                </div>
                            <?php endif; ?>
                            <div
                                style="font-size:11px; color:#9ca3af; margin-top:5px; display:flex; align-items:center; gap:5px;">
                                <i class="fas fa-user-times"></i>
                                Rejected by: <?= htmlspecialchars($rej['rejected_by_name'] ?? 'Manager') ?>
                                <?php if ($rej['responded_at']): ?>
                                    &nbsp;•&nbsp; <?= date('M d, Y g:i A', strtotime($rej['responded_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php
        // Show Cuttinglist deadline if set (Project type only)
        $dlStmtCut = $conn->prepare("SELECT start_date, end_date, duration FROM stage_deadlines WHERE client_id = ? AND stage_name = 'Cuttinglist'");
        $dlStmtCut->bind_param("i", $client_id);
        $dlStmtCut->execute();
        $dlRowCut = $dlStmtCut->get_result()->fetch_assoc();
        if ($dlRowCut && ($dlRowCut['start_date'] || $dlRowCut['end_date'])):
            $nowCut = new DateTime();
            $endDtCut = $dlRowCut['end_date'] ? new DateTime($dlRowCut['end_date']) : null;
            $isOverdueCut = $endDtCut && $nowCut > $endDtCut;
            $dlBgCut = $isOverdueCut ? '#fee2e2' : '#fffbeb';
            $dlBorderCut = $isOverdueCut ? '#ef4444' : '#f59e0b';
            $dlColorCut = $isOverdueCut ? '#991b1b' : '#92400e';
            $dlIconCut = $isOverdueCut ? 'fa-exclamation-circle' : 'fa-calendar-alt';
            ?>
            <div
                style="background:<?= $dlBgCut ?>; border:2px solid <?= $dlBorderCut ?>; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <i class="fas <?= $dlIconCut ?>" style="color:<?= $dlBorderCut ?>; font-size:20px; flex-shrink:0;"></i>
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:14px; color:<?= $dlColorCut ?>;">
                        Cuttinglist
                        <?= $isOverdueCut ? '— OVERDUE' : 'Deadline' ?>
                    </div>
                    <div
                        style="font-size:12px; color:<?= $dlColorCut ?>; opacity:0.85; margin-top:2px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <?php if ($dlRowCut['start_date']): ?>
                            <span><i class="fas fa-play-circle" style="color:#10b981;"></i> Start: <strong>
                                    <?= date('F d, Y', strtotime($dlRowCut['start_date'])) ?>
                                </strong></span>
                        <?php endif; ?>
                        <?php if ($dlRowCut['end_date']): ?>
                            <span><i class="fas fa-stop-circle" style="color:#ef4444;"></i> Deadline: <strong>
                                    <?= date('F d, Y', strtotime($dlRowCut['end_date'])) ?>
                                </strong></span>
                        <?php endif; ?>
                        <?php if ($dlRowCut['duration']): ?>
                            <span><i class="fas fa-clock"></i>
                                <?= $dlRowCut['duration'] ?> day
                                <?= $dlRowCut['duration'] != 1 ? 's' : '' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <!-- Client Header -->
        <div
            style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%); border-radius:12px; padding:28px 35px; margin-bottom:20px; color:white;">
            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:20px;">
                <div>
                    <h1 style="font-size:26px; margin-bottom:6px;">🔧 <?= htmlspecialchars($clientInfo['clientname']) ?>
                    </h1>
                    <p style="opacity:0.9; font-size:14px;"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
                </div>
                <button onclick="document.getElementById('clientDetailModal').style.display='flex'"
                    style="background:white; color:#0c4a6e; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-info-circle"></i> View Details
                </button>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px;">
                <div
                    style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); border-radius:9px; padding:13px;">
                    <div style="font-size:10px; opacity:0.75; text-transform:uppercase; letter-spacing:0.5px;">Reference
                    </div>
                    <div style="font-size:13px; font-weight:600; margin-top:4px; font-family:monospace;">
                        <?= htmlspecialchars($clientInfo['reference_number']) ?>
                    </div>
                </div>
                <div
                    style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); border-radius:9px; padding:13px;">
                    <div style="font-size:10px; opacity:0.75; text-transform:uppercase; letter-spacing:0.5px;">Business
                        Type</div>
                    <div style="font-size:13px; font-weight:600; margin-top:4px;">
                        <?= htmlspecialchars($business_type_label) ?>
                    </div>
                </div>
                <div
                    style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); border-radius:9px; padding:13px;">
                    <div style="font-size:10px; opacity:0.75; text-transform:uppercase; letter-spacing:0.5px;">Project
                        Cost</div>
                    <div style="font-size:13px; font-weight:600; margin-top:4px;">
                        ₱<?= number_format($clientInfo['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div
                    style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); border-radius:9px; padding:13px;">
                    <div style="font-size:10px; opacity:0.75; text-transform:uppercase; letter-spacing:0.5px;">Layout
                        Stage</div>
                    <div style="font-size:13px; font-weight:600; margin-top:4px;">
                        <?= htmlspecialchars($layoutTrackerStatus) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Detail Modal -->
        <div id="clientDetailModal"
            style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
            <div
                style="background:white; padding:28px; border-radius:12px; max-width:580px; width:90%; max-height:88vh; overflow-y:auto; position:relative;">
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:2px solid #e0f2fe; padding-bottom:12px;">
                    <h2 style="font-size:18px; font-weight:bold; color:#0c4a6e;"><i class="fas fa-user-circle"
                            style="color:#0369a1;"></i> Client Details</h2>
                    <button onclick="document.getElementById('clientDetailModal').style.display='none'"
                        style="font-size:20px; color:#666; background:none; border:none; cursor:pointer;"><i
                            class="fas fa-times"></i></button>
                </div>
                <?php
                $rows = [
                    ['Reference Number', $clientInfo['reference_number']],
                    ['Client Name', $clientInfo['clientname']],
                    ['Project Name', $clientInfo['nameproject']],
                    ['Status', $clientInfo['status']],
                    ['Business Type', $business_type_label],
                    ['Phone', $clientInfo['contact']],
                    ['Email', $clientInfo['email']],
                    ['Address', $clientInfo['address']],
                    ['Gender', $clientInfo['gender'] ?? ''],
                    ['Classification', $clientInfo['client_class'] ?? ''],
                    ['Client Type', $clientInfo['client_type'] ?? ''],
                    ['Designer 1', $clientInfo['designer1_name']],
                    ['Designer 2', $clientInfo['designer2_name']],
                    ['Technical Designer', $clientInfo['tech_designer_name']],
                    ['Coordinator', $clientInfo['coordinator_name']],
                ];
                foreach ($rows as [$lbl, $val]):
                    if (!$val)
                        continue;
                    ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:10px 0; border-bottom:1px solid #e9ecef;">
                        <div style="font-weight:600; color:#666; font-size:13px;"><?= $lbl ?>:</div>
                        <div style="color:#111; font-size:13px;"><?= nl2br(htmlspecialchars($val)) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($clientInfo['project_scope'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:10px 0; border-bottom:1px solid #e9ecef;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Project Scope:</div>
                        <div style="color:#111; font-size:13px;">
                            <?= nl2br(htmlspecialchars($clientInfo['project_scope'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['scope_of_work'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:10px 0; border-bottom:1px solid #e9ecef;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Scope of Work:</div>
                        <div style="color:#111; font-size:13px;">
                            <?= nl2br(htmlspecialchars($clientInfo['scope_of_work'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['house_state'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:10px 0; border-bottom:1px solid #e9ecef;">
                        <div style="font-weight:600; color:#666; font-size:13px;">House State:</div>
                        <div>
                            <?php
                            $hsBg = '#fef3c7';
                            $hsColor = '#92400e';
                            if ($clientInfo['house_state'] === 'Bare/Empty Lot') {
                                $hsBg = '#dbeafe';
                                $hsColor = '#1e40af';
                            } elseif ($clientInfo['house_state'] === 'Construction Started') {
                                $hsBg = '#fee2e2';
                                $hsColor = '#991b1b';
                            } elseif ($clientInfo['house_state'] === 'Renovation') {
                                $hsBg = '#ede9fe';
                                $hsColor = '#5b21b6';
                            }
                            ?>
                            <span style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700;
                                 background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
                                <?= htmlspecialchars($clientInfo['house_state']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['permit_required'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:10px 0; border-bottom:1px solid #e9ecef;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Permit Required:</div>
                        <div>
                            <?php
                            $prBg = '#fef3c7';
                            $prColor = '#92400e';
                            if ($clientInfo['permit_required'] === 'Yes') {
                                $prBg = '#fee2e2';
                                $prColor = '#991b1b';
                            } elseif ($clientInfo['permit_required'] === 'No') {
                                $prBg = '#d1fae5';
                                $prColor = '#065f46';
                            }
                            ?>
                            <span style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700;
                                 background:<?= $prBg ?>; color:<?= $prColor ?>;">
                                <?= htmlspecialchars($clientInfo['permit_required']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['target_movein_date'])): ?>
                    <div style="display:grid; grid-template-columns:160px 1fr; padding:10px 0;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Target Move-in:</div>
                        <div style="color:#111; font-size:13px; font-weight:600;">
                            <i class="fas fa-calendar-check" style="color:#10b981;"></i>
                            <?= date('F d, Y', strtotime($clientInfo['target_movein_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assigned Staff Card -->
        <div class="card">
            <h2><i class="fas fa-users"></i> Assigned Staff</h2>
            <div
                style="display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; margin-bottom:18px;">
                <div class="intake-item" style="border-left-color:#3b82f6;">
                    <div class="label">Designer 1</div>
                    <div class="value">
                        <?= $clientInfo['designer1_name'] ? htmlspecialchars($clientInfo['designer1_name']) : '<span style="color:#9ca3af; font-weight:400;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#6366f1;">
                    <div class="label">Designer 2</div>
                    <div class="value">
                        <?= $clientInfo['designer2_name'] ? htmlspecialchars($clientInfo['designer2_name']) : '<span style="color:#9ca3af; font-weight:400;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#0891b2;">
                    <div class="label">Technical Designer</div>
                    <div class="value">
                        <?= $clientInfo['tech_designer_name'] ? htmlspecialchars($clientInfo['tech_designer_name']) : '<span style="color:#9ca3af; font-weight:400;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#059669;">
                    <div class="label">Project Coordinator</div>
                    <div class="value">
                        <?= $clientInfo['coordinator_name'] ? htmlspecialchars($clientInfo['coordinator_name']) : '<span style="color:#9ca3af; font-weight:400;">Not assigned</span>' ?>
                    </div>
                </div>
            </div>
            <?php if ($isTDHead): ?>
                <div style="border-top:2px solid #f0f9ff; padding-top:16px;">
                    <div style="font-size:13px; font-weight:700; color:#0369a1; margin-bottom:10px;">
                        <i class="fas fa-tools"></i> Assign Technical Designer
                        <span
                            style="background:#e0f2fe; color:#0369a1; padding:2px 10px; border-radius:10px; font-size:11px; margin-left:6px;">Head
                            Only</span>
                    </div>
                    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="assign_td">
                        <div style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase;">Technical
                                Designer</label>
                            <select name="technical_designer_id" class="form-control" style="min-width:220px;">
                                <option value="">— None —</option>
                                <?php foreach ($tdList as $td): ?>
                                    <option value="<?= $td['id'] ?>" <?= ($clientInfo['technical_designer_id'] == $td['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($td['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-nav btn-blue" style="height:38px; padding:0 18px;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Designer Intake Reference (read-only) -->
        <?php if ($designerIntake): ?>
            <div class="card">
                <h2><i class="fas fa-clipboard-list"></i> Designer Intake — Reference Only</h2>
                <div
                    style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; font-size:12px; color:#92400e; margin-bottom:16px;">
                    <i class="fas fa-info-circle"></i> This is the intake submitted by the designer. It is read-only and for
                    your reference.
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:12px;">
                    <?php
                    $iFields = [
                        'Decoration Stage' => $designerIntake['decoration_stage'],
                        'Decoration Style' => $designerIntake['decoration_style'],
                        'Occupation' => $designerIntake['occupation'],
                        'Favourite Color' => $designerIntake['favour_color'],
                        'Area (SQM)' => number_format($designerIntake['area_sqm'], 2) . ' m²',
                        'Family Members' => $designerIntake['family_members'] !== null ? $designerIntake['family_members'] . ' people' : '—',
                        'Budget' => '₱' . number_format($designerIntake['budget'], 2),
                    ];
                    foreach ($iFields as $lbl => $val): ?>
                        <div class="intake-item">
                            <div class="label"><?= $lbl ?></div>
                            <div class="value"><?= htmlspecialchars($val) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($designerIntake['measurement_remark']): ?>
                        <div class="intake-item" style="grid-column:1/-1;">
                            <div class="label">Measurement Remark</div>
                            <div class="value" style="font-weight:400; font-size:13px;">
                                <?= nl2br(htmlspecialchars($designerIntake['measurement_remark'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:12px; font-size:11px; color:#9ca3af;">
                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                    Submitted by <?= htmlspecialchars($designerIntake['submitter_name'] ?? '') ?> on
                    <?= date('F d, Y g:i A', strtotime($designerIntake['created_at'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Revision Request Section ─────────────────────────────────── -->
        <?php
        // Fetch areas with their unit distributions for the revision selector
        $revAreaDataStmt = $conn->prepare("
        SELECT DISTINCT qe.area,
               qrd.room_unit_number,
               qrd.room_unit_name
        FROM quotation_entries qe
        LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_entry_id = qe.id
        WHERE qe.client_id = ?
        UNION
        SELECT DISTINCT qfs.area,
               qrd.room_unit_number,
               qrd.room_unit_name
        FROM quotation_fixed_sizes qfs
        LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = ?
        ORDER BY area, room_unit_number
    ");
        $revAreaDataStmt->bind_param("ii", $client_id, $client_id);
        $revAreaDataStmt->execute();
        $revAreaRows = $revAreaDataStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Group by area → units
        $revAreaMap = [];
        foreach ($revAreaRows as $row) {
            $area = $row['area'];
            if (!isset($revAreaMap[$area]))
                $revAreaMap[$area] = [];
            if ($row['room_unit_number'] !== null) {
                $revAreaMap[$area][] = [
                    'unit_num' => $row['room_unit_number'],
                    'unit_name' => $row['room_unit_name'],
                ];
            }
        }

        // Fallback: if no unit distribution rows found, build map from $tdAreas (areas only, no units)
        if (empty($revAreaMap) && !empty($tdAreas)) {
            foreach ($tdAreas as $area) {
                $revAreaMap[$area] = []; // empty units = whole-area checkbox
            }
        }
        ?>

        <?php if ($isAssigned && !empty($revAreaMap)): ?>
            <div class="card">
                <h2><i class="fas fa-redo-alt" style="color:#f59e0b;"></i> Request Revision</h2>

                <div
                    style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; font-size:12px; color:#92400e; margin-bottom:16px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Requesting a revision will reset approvals for the selected areas/units and notify the assigned
                    Technical Designer.
                </div>

                <form method="POST" action="<?= BASE_URL ?>td-request-revision" onsubmit="return confirmRevision();">
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                    <input type="hidden" name="selections" id="selectionsInput" value="">

                    <!-- Area/Unit selector -->
                    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;">
                        <?php foreach ($revAreaMap as $area => $units):
                            $slug = preg_replace('/[^a-z0-9]/i', '_', strtolower($area));
                            ?>
                            <div style="border:1px solid #e9ecef; border-radius:9px; overflow:hidden;">
                                <!-- Area header row -->
                                <div style="background:#f8fafc; padding:11px 14px; display:flex; align-items:center; gap:10px; cursor:pointer;"
                                    onclick="toggleUnits('<?= $slug ?>')">
                                    <?php if (empty($units)): ?>
                                        <input type="checkbox" class="rev-area-check" data-area="<?= htmlspecialchars($area) ?>"
                                            onclick="event.stopPropagation(); onAreaCheck(this);"
                                            style="width:15px; height:15px; cursor:pointer; flex-shrink:0;">
                                    <?php endif; ?>
                                    <span style="font-size:13px; font-weight:700; color:#1f2937; flex:1;">
                                        <i class="fas fa-map-marker-alt" style="color:#0369a1;"></i>
                                        <?= htmlspecialchars($area) ?>
                                        <?php if (!empty($units)): ?>
                                            <span
                                                style="font-size:11px; font-weight:400; color:#6b7280; margin-left:6px;"><?= count($units) ?>
                                                unit<?= count($units) > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($units)): ?>
                                        <i class="fas fa-chevron-down" id="chevron-<?= $slug ?>"
                                            style="color:#6b7280; transition:transform .2s; font-size:12px;"></i>
                                    <?php endif; ?>
                                </div>

                                <!-- Units list (collapsible) -->
                                <?php if (!empty($units)): ?>
                                    <div id="units-<?= $slug ?>"
                                        style="display:none; padding:10px 14px; border-top:1px solid #e9ecef; background:white;">
                                        <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
                                            <button type="button" id="selectAllBtn-<?= $slug ?>"
                                                onclick="selectAllUnits('<?= htmlspecialchars($area, ENT_QUOTES) ?>','<?= $slug ?>')"
                                                style="font-size:11px; color:#0369a1; background:none; border:none; cursor:pointer; font-weight:600;">
                                                Select All
                                            </button>
                                        </div>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <?php foreach ($units as $unit): ?>
                                                <label id="unitlabel-<?= $slug ?>-<?= $unit['unit_num'] ?>"
                                                    style="display:flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid #e9ecef; border-radius:7px; cursor:pointer; transition:background .15s;"
                                                    onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                                                    <input type="checkbox" class="rev-unit-check"
                                                        data-area="<?= htmlspecialchars($area) ?>" data-area-slug="<?= $slug ?>"
                                                        data-unit-num="<?= $unit['unit_num'] ?>"
                                                        data-unit-name="<?= htmlspecialchars($unit['unit_name'] ?? '') ?>"
                                                        onclick="onUnitCheck(this);"
                                                        style="width:14px; height:14px; cursor:pointer; flex-shrink:0;">
                                                    <span style="font-size:13px; color:#374151;">
                                                        <i class="fas fa-door-open" style="color:#6b7280; font-size:11px;"></i>
                                                        <?= htmlspecialchars($unit['unit_name'] ?? 'Unit ' . $unit['unit_num']) ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Selected items summary + reason inputs -->
                    <div id="selectionSummary"
                        style="display:none; background:#fffbeb; border:2px solid #fcd34d; border-radius:9px; padding:14px; margin-bottom:14px;">
                        <div
                            style="font-size:12px; font-weight:700; color:#92400e; margin-bottom:10px; text-transform:uppercase; letter-spacing:.4px;">
                            <i class="fas fa-list-check"></i> Selected for Revision — add a reason for each
                        </div>
                        <div id="selectionItems" style="display:flex; flex-direction:column; gap:10px;"></div>
                    </div>

                    <div style="display:flex; justify-content:flex-end;">
                        <button type="submit" id="revisionSubmitBtn" disabled
                            style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:11px 24px; border:none; border-radius:9px; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:8px; opacity:0.5; cursor:not-allowed;">
                            <i class="fas fa-redo-alt"></i> Submit Revision Request
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- ── Revision History ───────────────────────────────────────────── -->
        <?php if (!empty($revisionLogs)): ?>
            <div class="card">
                <h2 style="cursor:pointer;" onclick="toggleRevPanel('revHistoryPanel','revHistoryChevron')">
                    <i class="fas fa-history" style="color:#6b7280;"></i>
                    Revision History
                    <span
                        style="font-size:12px; font-weight:400; color:#6b7280; margin-left:6px;">(<?= count($revisionLogs) ?>
                        entr<?= count($revisionLogs) > 1 ? 'ies' : 'y' ?>)</span>
                    <i class="fas fa-chevron-down" id="revHistoryChevron"
                        style="margin-left:auto; color:#6b7280; font-size:13px; transition:transform .2s;"></i>
                </h2>
                <div id="revHistoryPanel" style="display:none;">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($revisionLogs as $log): ?>
                            <div style="border:1px solid #e9ecef; border-radius:9px; padding:13px 16px; background:#fafafa;">
                                <div
                                    style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; margin-bottom:6px;">
                                    <div style="font-size:13px; font-weight:700; color:#1f2937;">
                                        <i class="fas fa-map-marker-alt" style="color:#0369a1;"></i>
                                        <?= htmlspecialchars($log['area']) ?>
                                        <?php if ($log['room_unit_number']): ?>
                                            <span style="color:#6b7280; font-weight:400;"> › </span>
                                            <i class="fas fa-door-open" style="color:#6b7280; font-size:11px;"></i>
                                            Unit <?= $log['room_unit_number'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <span
                                        style="font-size:11px; padding:3px 9px; border-radius:20px; font-weight:700;
                            background:<?= $log['status'] === 'pending' ? '#fef3c7' : ($log['status'] === 'done' ? '#d1fae5' : '#f3f4f6') ?>;
                            color:<?= $log['status'] === 'pending' ? '#92400e' : ($log['status'] === 'done' ? '#065f46' : '#374151') ?>;">
                                        <?= ucfirst($log['status']) ?>
                                    </span>
                                </div>
                                <?php if ($log['reason']): ?>
                                    <div
                                        style="font-size:12px; color:#6b7280; background:#f3f4f6; padding:7px 10px; border-radius:6px; border-left:3px solid #d97706; font-style:italic; margin-bottom:6px;">
                                        <i class="fas fa-quote-left" style="font-size:10px; opacity:.5;"></i>
                                        <?= htmlspecialchars($log['reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <div style="font-size:11px; color:#9ca3af; display:flex; align-items:center; gap:5px;">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($log['requester_name'] ?? 'Unknown') ?>
                                    &nbsp;•&nbsp;
                                    <i class="fas fa-clock"></i> <?= date('M d, Y g:i A', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TD Attachments button -->
        <div style="margin-bottom:22px;">
            <a href="td-attachments?client_id=<?= $client_id ?>"
                style="background:linear-gradient(135deg,#0c4a6e,#0369a1); color:white; padding:22px 28px; border-radius:12px; text-decoration:none; display:flex; align-items:center; gap:16px; box-shadow:0 4px 10px rgba(3,105,161,0.2); transition:opacity 0.2s;">
                <i class="fas fa-paperclip" style="font-size:30px; opacity:0.9;"></i>
                <div>
                    <div style="font-size:16px; font-weight:700;">TD Attachments</div>
                    <div style="font-size:12px; opacity:0.8; margin-top:3px;">Upload technical documents &amp; cutting
                        list files per area</div>
                </div>
                <i class="fas fa-chevron-right" style="margin-left:auto; opacity:0.7;"></i>
            </a>
        </div>

        <?php if ($allAreasApproved && $cuttingTrackerStatus === 'Ongoing' && $cuttingTrackerId && $isAssigned): ?>
            <div
                style="margin-top:20px; background:linear-gradient(135deg,#065f46,#10b981); color:white; padding:20px 26px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 12px rgba(16,185,129,0.3);">
                <div>
                    <div style="font-size:15px; font-weight:700; margin-bottom:4px;">
                        <i class="fas fa-check-double"></i> All Areas Approved!
                    </div>
                    <div style="font-size:12px; opacity:0.85;">All TD attachments have been fully approved. You can now mark
                        the Cuttinglist stage as Done.</div>
                </div>
                <button onclick="markCuttinglistDone(<?= $cuttingTrackerId ?>)"
                    style="background:white; color:#065f46; padding:12px 24px; border:none; border-radius:10px; cursor:pointer; font-size:14px; font-weight:700; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; flex-shrink:0;">
                    <i class="fas fa-check-circle"></i> Mark Cuttinglist as Done
                </button>
            </div>
        <?php elseif ($allAreasApproved && $cuttingTrackerStatus === 'Done' && $isAssigned): ?>
            <div
                style="margin-top:20px; background:#d1fae5; color:#065f46; padding:16px 22px; border-radius:12px; display:flex; align-items:center; gap:10px; border:2px solid #10b981;">
                <i class="fas fa-check-circle" style="font-size:20px;"></i>
                <span style="font-size:14px; font-weight:700;">Cuttinglist stage is marked as Done.</span>
            </div>
        <?php endif; ?>

    </div>

    <!-- Close modal on outside click -->
    <script>
        document.addEventListener('click', function (e) {
            const m = document.getElementById('clientDetailModal');
            if (m && e.target === m) m.style.display = 'none';
        });


        // ── Revision multi-select ────────────────────────────────────────────────
        let revSelections = [];
        function toggleUnits(slug) {
            const el = document.getElementById('units-' + slug), chv = document.getElementById('chevron-' + slug);
            if (!el) return;
            const open = el.style.display !== 'none';
            el.style.display = open ? 'none' : 'block';
            chv.style.transform = open ? '' : 'rotate(180deg)';
        }
        function getSelKey(area, unitNum) { return area + '||' + (unitNum ?? 'null'); }
        function onAreaCheck(cb) {
            const area = cb.dataset.area, key = getSelKey(area, null);
            if (cb.checked) { if (!revSelections.find(s => getSelKey(s.area, s.unitNum) === key)) revSelections.push({ area, unitNum: null, unitName: null, reason: '' }); }
            else revSelections = revSelections.filter(s => getSelKey(s.area, s.unitNum) !== key);
            updateSummary();
        }
        function onUnitCheck(cb) {
            const area = cb.dataset.area, unitNum = parseInt(cb.dataset.unitNum), unitName = cb.dataset.unitName, key = getSelKey(area, unitNum);
            if (cb.checked) {
                if (!revSelections.find(s => getSelKey(s.area, s.unitNum) === key)) revSelections.push({ area, unitNum, unitName, reason: '' });
                const lbl = document.getElementById('unitlabel-' + cb.dataset.areaSlug + '-' + unitNum);
                if (lbl) lbl.style.outline = '2px solid #f59e0b';
            } else {
                revSelections = revSelections.filter(s => getSelKey(s.area, s.unitNum) !== key);
                const lbl = document.getElementById('unitlabel-' + cb.dataset.areaSlug + '-' + unitNum);
                if (lbl) lbl.style.outline = '';
            }
            updateSummary();
        }
        function selectAllUnits(area, slug) {
            const checks = document.querySelectorAll(`.rev-unit-check[data-area="${CSS.escape(area)}"]`);
            const allChecked = Array.from(checks).every(c => c.checked);
            checks.forEach(cb => { cb.checked = !allChecked; onUnitCheck(cb); });
            const btn = document.getElementById('selectAllBtn-' + slug);
            if (btn) btn.textContent = allChecked ? 'Select All' : 'Deselect All';
        }
        function removeSelection(key) {
            const idx = revSelections.findIndex(s => getSelKey(s.area, s.unitNum) === key);
            if (idx === -1) return;
            const s = revSelections[idx];
            if (s.unitNum !== null) { const cb = document.querySelector(`.rev-unit-check[data-area="${CSS.escape(s.area)}"][data-unit-num="${s.unitNum}"]`); if (cb) { cb.checked = false; onUnitCheck(cb); return; } }
            else { const cb = document.querySelector(`.rev-area-check[data-area="${CSS.escape(s.area)}"]`); if (cb) { cb.checked = false; onAreaCheck(cb); return; } }
            revSelections.splice(idx, 1); updateSummary();
        }
        function updateSummary() {
            const box = document.getElementById('selectionSummary'), items = document.getElementById('selectionItems'), inp = document.getElementById('selectionsInput');
            if (!box) return;
            if (revSelections.length === 0) { box.style.display = 'none'; if (inp) inp.value = ''; updateSubmitBtn(); return; }
            box.style.display = 'block';
            items.innerHTML = revSelections.map(s => {
                const key = getSelKey(s.area, s.unitNum);
                const label = s.unitNum !== null ? s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum) : s.area + ' (whole area)';
                return `<div style="border:1px solid #fcd34d;border-radius:8px;padding:12px 14px;background:white;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <span style="font-size:13px;font-weight:700;color:#92400e;"><i class="fas fa-map-marker-alt"></i> ${label}</span>
                <button type="button" onclick="removeSelection('${key}')" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:13px;"><i class="fas fa-times"></i> Remove</button>
            </div>
            <textarea placeholder="Reason for revision on this area/unit... *" oninput="updateReason('${key}',this.value)"
                style="width:100%;padding:8px 10px;border:1px solid #e9ecef;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;min-height:60px;box-sizing:border-box;"
            >${s.reason}</textarea>
        </div>`;
            }).join('');
            if (inp) inp.value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }
        function updateReason(key, val) {
            const s = revSelections.find(s => getSelKey(s.area, s.unitNum) === key);
            if (s) s.reason = val.trim();
            const inp = document.getElementById('selectionsInput');
            if (inp) inp.value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }
        function updateSubmitBtn() {
            const btn = document.getElementById('revisionSubmitBtn');
            if (!btn) return;
            const ready = revSelections.length > 0 && revSelections.every(s => s.reason.trim() !== '');
            btn.disabled = !ready; btn.style.opacity = ready ? '1' : '0.5'; btn.style.cursor = ready ? 'pointer' : 'not-allowed';
        }
        function confirmRevision() {
            if (revSelections.length === 0) return false;
            if (!revSelections.every(s => s.reason.trim() !== '')) { alert('Please fill in a reason for each selected area/unit.'); return false; }
            const lines = revSelections.map(s => s.unitNum !== null ? '  • ' + s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum) : '  • ' + s.area + ' (whole area)').join('\n');
            return confirm('This will request a revision.\n\nAreas/units to reset:\n' + lines + '\n\nApprovals for these will be reset. Continue?');
        }
        function toggleRevHistory() {
            const panel = document.getElementById('revHistoryPanel'), icon = document.getElementById('revHistoryBtnIcon'), text = document.getElementById('revHistoryBtnText');
            if (!panel) return;
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            icon.className = open ? 'fas fa-eye' : 'fas fa-eye-slash';
            text.textContent = open ? 'Show History' : 'Hide History';
        }
        async function markCuttinglistDone(stageId) {
            if (!confirm('Mark the Cuttinglist stage as Done? All areas have been approved.')) return;
            try {
                const response = await fetch('<?= BASE_URL ?>update-tracker-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: 'Done' })
                });
                const result = await response.json();
                if (result.success) {
                    alert('Cuttinglist marked as Done!');
                    location.reload();
                } else {
                    alert('Failed: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('An error occurred.');
            }
        }
        function toggleRevPanel(panelId, chevronId) {
            const panel = document.getElementById(panelId), chev = document.getElementById(chevronId);
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
        }
    </script>
</body>

</html>