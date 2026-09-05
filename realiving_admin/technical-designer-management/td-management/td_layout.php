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
</head>

<body class="font-sans bg-[#F5F5F5] text-ink">
    <div class="max-w-[1100px] mx-auto px-5 py-8">

        <!-- Back buttons -->
        <div class="flex gap-2.5 mb-5 flex-wrap">
            <a href="<?= $backToList ?>"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php if ($canSeeTrackerBtn): ?>
                <a href="<?= $backToTracker ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-chart-line"></i> Back to Tracker
                </a>
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
                <div class="bg-blue-50 border border-blue-300 rounded-[10px] p-4 mb-[18px] flex items-center justify-between gap-3.5 flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <i class="fas fa-comment-medical text-blue-600 text-xl"></i>
                        <div>
                            <div class="font-semibold text-sm text-blue-900">
                                Some areas need your technical remark
                            </div>
                            <div class="text-xs text-blue-700 mt-0.5">
                                The designer has requested approval but your remark is missing. Go to TD Attachments to submit.
                            </div>
                        </div>
                    </div>
                    <a href="td-attachments?client_id=<?= $client_id ?>"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap">
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
            <div class="bg-amber-50 border border-amber-300 rounded-[10px] p-4 mb-[18px] flex items-center justify-between gap-3.5 flex-wrap">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-bell text-amber-600 text-xl"></i>
                    <div>
                        <div class="font-semibold text-sm text-amber-900">
                            You have <?= $tdPendingCount ?> pending approval<?= $tdPendingCount > 1 ? 's' : '' ?> for this client
                        </div>
                        <div class="text-xs text-amber-700 mt-0.5">
                            Go to TD Attachments to review and approve or reject.
                        </div>
                    </div>
                </div>
                <a href="td-attachments?client_id=<?= $client_id ?>"
                    class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap">
                    <i class="fas fa-arrow-right"></i> Go to TD Attachments
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($rejectedTDItems) && $isAssigned): ?>
            <div class="bg-red-50 border border-red-300 rounded-[10px] p-4 mb-[18px]">
                <div class="flex items-center gap-2.5 mb-3 flex-wrap">
                    <i class="fas fa-times-circle text-red-600 text-xl flex-shrink-0"></i>
                    <div class="flex-1">
                        <div class="font-semibold text-sm text-red-900">
                            <?= count($rejectedTDItems) ?> TD area<?= count($rejectedTDItems) > 1 ? 's/units' : '/unit' ?> rejected — action required
                        </div>
                        <div class="text-xs text-red-700 mt-0.5">
                            Go to <strong>TD Attachments</strong> to review the rejection comments and resubmit updated files.
                        </div>
                    </div>
                    <a href="td-attachments?client_id=<?= $client_id ?>"
                        class="inline-flex items-center gap-2 bg-red-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap flex-shrink-0">
                        <i class="fas fa-arrow-right"></i> Go to TD Attachments
                    </a>
                </div>
                <div class="flex flex-col gap-2">
                    <?php foreach ($rejectedTDItems as $rej): ?>
                        <div class="bg-white border border-red-200 rounded-lg px-3.5 py-2.5 flex items-start gap-2.5 flex-wrap">
                            <div class="flex-1 min-w-0">
                                <div class="text-[13px] font-semibold text-red-900">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($rej['area']) ?>
                                    <?php if ($rej['room_unit_number']): ?>
                                        <span class="text-soft font-normal"> › </span>
                                        <i class="fas fa-door-open"></i> Unit <?= $rej['room_unit_number'] ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($rej['comment']): ?>
                                    <div class="text-xs text-red-800 bg-red-50 px-2.5 py-1.5 rounded-md mt-1.5 border-l-2 border-red-500 italic">
                                        <i class="fas fa-comment-slash"></i> "<?= htmlspecialchars($rej['comment']) ?>"
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-muted mt-1.5 flex items-center gap-1.5">
                                    <i class="fas fa-user-times"></i>
                                    Rejected by: <?= htmlspecialchars($rej['rejected_by_name'] ?? 'Manager') ?>
                                    <?php if ($rej['responded_at']): ?>
                                        &nbsp;•&nbsp; <?= date('M d, Y g:i A', strtotime($rej['responded_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

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
            $dlClassesCut = $isOverdueCut ? 'bg-red-50 border-red-300 text-red-900' : 'bg-blue-50 border-blue-300 text-blue-900';
            $dlIconColorCut = $isOverdueCut ? 'text-red-600' : 'text-blue-600';
            $dlIconCut = $isOverdueCut ? 'fa-exclamation-circle' : 'fa-calendar-alt';
        ?>
        <div class="border rounded-[10px] p-4 mb-[18px] flex items-center gap-3 flex-wrap <?= $dlClassesCut ?>">
            <i class="fas <?= $dlIconCut ?> <?= $dlIconColorCut ?> text-xl flex-shrink-0"></i>
            <div class="flex-1">
                <div class="font-semibold text-sm">
                    Cuttinglist <?= $isOverdueCut ? '— OVERDUE' : 'Deadline' ?>
                </div>
                <div class="text-xs opacity-85 mt-0.5 flex items-center gap-2.5 flex-wrap">
                    <?php if ($dlRowCut['start_date']): ?>
                        <span><i class="fas fa-play-circle text-emerald-600"></i> Start: <strong><?= date('F d, Y', strtotime($dlRowCut['start_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRowCut['end_date']): ?>
                        <span><i class="fas fa-stop-circle text-red-600"></i> Deadline: <strong><?= date('F d, Y', strtotime($dlRowCut['end_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRowCut['duration']): ?>
                        <span><i class="fas fa-clock"></i> <?= $dlRowCut['duration'] ?> day<?= $dlRowCut['duration'] != 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Client Information Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="flex justify-between items-start gap-4 mb-5 flex-wrap">
                <div>
                    <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                        <i class="fas fa-drafting-compass"></i> TD Layout Manager
                    </div>
                    <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($clientInfo['clientname']) ?></h1>
                    <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
                </div>
                <button onclick="document.getElementById('clientDetailModal').style.display='flex'"
                    class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-sm font-semibold hover:opacity-90 transition">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Reference Number</div>
                    <div class="text-[13px] font-semibold font-mono"><?= htmlspecialchars($clientInfo['reference_number']) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Business Type</div>
                    <div class="text-[14px] font-semibold"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Project Cost</div>
                    <div class="text-[14px] font-semibold">₱<?= number_format($clientInfo['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Layout Stage</div>
                    <div class="text-[14px] font-semibold"><?= htmlspecialchars($layoutTrackerStatus) ?></div>
                </div>
            </div>
        </div>

        <!-- Client Detail Modal -->
        <div id="clientDetailModal" class="hidden fixed inset-0 z-[1000] bg-black/50 items-center justify-center">
            <div class="bg-white p-7 rounded-[14px] max-w-xl w-[90%] max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-5 border-b border-line pb-3.5">
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-user-circle text-soft"></i> Client Details
                    </h2>
                    <button onclick="document.getElementById('clientDetailModal').style.display='none'"
                        class="text-soft hover:text-ink text-lg">
                        <i class="fas fa-times"></i>
                    </button>
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
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]"><?= $lbl ?>:</div>
                        <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($val)) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($clientInfo['project_scope'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Project Scope:</div>
                        <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($clientInfo['project_scope'])) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['scope_of_work'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Scope of Work:</div>
                        <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($clientInfo['scope_of_work'])) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['house_state'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">House State:</div>
                        <div>
                            <?php
                            $hsClass = 'bg-amber-100 text-amber-800';
                            if ($clientInfo['house_state'] === 'Bare/Empty Lot') {
                                $hsClass = 'bg-blue-100 text-blue-800';
                            } elseif ($clientInfo['house_state'] === 'Construction Started') {
                                $hsClass = 'bg-red-100 text-red-800';
                            } elseif ($clientInfo['house_state'] === 'Renovation') {
                                $hsClass = 'bg-purple-100 text-purple-800';
                            }
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $hsClass ?>">
                                <?= htmlspecialchars($clientInfo['house_state']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['permit_required'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Permit Required:</div>
                        <div>
                            <?php
                            $prClass = 'bg-amber-100 text-amber-800';
                            if ($clientInfo['permit_required'] === 'Yes') {
                                $prClass = 'bg-red-100 text-red-800';
                            } elseif ($clientInfo['permit_required'] === 'No') {
                                $prClass = 'bg-emerald-100 text-emerald-800';
                            }
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $prClass ?>">
                                <?= htmlspecialchars($clientInfo['permit_required']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($clientInfo['target_movein_date'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 items-start">
                        <div class="font-semibold text-soft text-[13px]">Target Move-in:</div>
                        <div class="text-ink text-[13px] font-semibold">
                            <i class="fas fa-calendar-check text-emerald-600"></i>
                            <?= date('F d, Y', strtotime($clientInfo['target_movein_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Assigned Staff Section ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                <i class="fas fa-users text-soft"></i> Assigned Staff
                <span class="flex-1 h-px bg-line"></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 mb-5">
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-pencil-ruler"></i> Designer 1
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $clientInfo['designer1_name'] ? htmlspecialchars($clientInfo['designer1_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-pencil-ruler"></i> Designer 2
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $clientInfo['designer2_name'] ? htmlspecialchars($clientInfo['designer2_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-tools"></i> Technical Designer
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $clientInfo['tech_designer_name'] ? htmlspecialchars($clientInfo['tech_designer_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-clipboard-check"></i> Project Coordinator
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $clientInfo['coordinator_name'] ? htmlspecialchars($clientInfo['coordinator_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
            </div>

            <?php if ($isTDHead): ?>
                <div class="border-t border-line pt-[18px]">
                    <div class="text-[13px] font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-tools"></i> Assign Technical Designer
                        <span class="bg-[#F5F5F5] border border-line text-soft px-2.5 py-0.5 rounded-full text-[11px]">Head Only</span>
                    </div>
                    <form method="POST" class="flex gap-2.5 items-end flex-wrap">
                        <input type="hidden" name="action" value="assign_td">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Technical Designer</label>
                            <select name="technical_designer_id" class="min-w-[220px] border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                <option value="">— None —</option>
                                <?php foreach ($tdList as $td): ?>
                                    <option value="<?= $td['id'] ?>" <?= ($clientInfo['technical_designer_id'] == $td['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($td['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition h-[42px]">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Designer Intake Reference (read-only) ── -->
        <?php if ($designerIntake): ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                    <i class="fas fa-clipboard-list text-soft"></i> Designer Intake — Reference Only
                    <span class="flex-1 h-px bg-line"></span>
                </div>
                <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-3.5 py-2.5 text-xs mb-4 flex items-center gap-2">
                    <i class="fas fa-info-circle"></i> This is the intake submitted by the designer. It is read-only and for your reference.
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
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
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1"><?= $lbl ?></div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($val) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($designerIntake['measurement_remark']): ?>
                        <div class="sm:col-span-2 lg:col-span-3 bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Measurement Remark</div>
                            <div class="text-[13px] font-normal"><?= nl2br(htmlspecialchars($designerIntake['measurement_remark'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-[11px] text-muted mt-3 flex items-center gap-1.5">
                    <i class="fas fa-check-circle text-emerald-600"></i>
                    Submitted by <?= htmlspecialchars($designerIntake['submitter_name'] ?? '') ?> on
                    <?= date('F d, Y g:i A', strtotime($designerIntake['created_at'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Revision Request Section ─────────────────────────────────── -->
        <?php
        // Fetch areas with their unit distributions for the revision selector
        $revAreaDataStmt = $conn->prepare("
    SELECT DISTINCT area, NULL as room_unit_number, NULL as room_unit_name
    FROM quotation_entries
    WHERE client_id = ?
    UNION
    SELECT DISTINCT area, NULL as room_unit_number, NULL as room_unit_name
    FROM quotation_fixed_sizes
    WHERE client_id = ?
    ORDER BY area
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
            <div class="bg-white border-2 border-amber-400 rounded-[10px] p-6 mb-5">
                <div class="flex items-center gap-2.5 text-xs font-semibold text-amber-800 mb-2">
                    <i class="fas fa-redo-alt"></i> Request Revision
                    <span class="flex-1 h-px bg-line"></span>
                </div>

                <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-3.5 py-2.5 text-xs mb-4 flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle"></i>
                    Requesting a revision will reset approvals for the selected areas/units and notify the assigned Technical Designer.
                </div>

                <form method="POST" action="<?= BASE_URL ?>td-request-revision" onsubmit="return confirmRevision();">
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                    <input type="hidden" name="selections" id="selectionsInput" value="">

                    <!-- Area/Unit selector -->
                    <div class="flex flex-col gap-2.5 mb-4">
                        <?php foreach ($revAreaMap as $area => $units):
                            $slug = preg_replace('/[^a-z0-9]/i', '_', strtolower($area));
                            ?>
                            <div class="border border-line rounded-lg overflow-hidden">
                                <!-- Area header row -->
                                <div class="bg-[#F5F5F5] px-4 py-3 flex items-center gap-2.5 cursor-pointer"
                                    onclick="toggleUnits('<?= $slug ?>')">
                                    <?php if (empty($units)): ?>
                                        <input type="checkbox" class="rev-area-check w-4 h-4 cursor-pointer accent-amber-500" data-area="<?= htmlspecialchars($area) ?>"
                                            onclick="event.stopPropagation(); onAreaCheck(this);">
                                    <?php endif; ?>
                                    <span class="text-sm font-semibold text-ink flex-1">
                                        <i class="fas fa-map-marker-alt text-soft"></i>
                                        <?= htmlspecialchars($area) ?>
                                        <?php if (!empty($units)): ?>
                                            <span class="text-[11px] font-normal text-muted ml-1.5"><?= count($units) ?> unit<?= count($units) > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($units)): ?>
                                        <i class="fas fa-chevron-down text-soft text-xs transition-transform" id="chevron-<?= $slug ?>"></i>
                                    <?php endif; ?>
                                </div>

                                <!-- Units list (collapsible) -->
                                <?php if (!empty($units)): ?>
                                    <div id="units-<?= $slug ?>" class="hidden px-4 py-3.5 border-t border-line bg-white">
                                        <div class="flex justify-end mb-2">
                                            <button type="button" id="selectAllBtn-<?= $slug ?>"
                                                onclick="selectAllUnits('<?= htmlspecialchars($area, ENT_QUOTES) ?>','<?= $slug ?>')"
                                                class="text-[11px] text-ink font-semibold hover:underline">
                                                Select All
                                            </button>
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            <?php foreach ($units as $unit): ?>
                                                <label id="unitlabel-<?= $slug ?>-<?= $unit['unit_num'] ?>"
                                                    class="flex items-center gap-2.5 px-2.5 py-2 border border-line rounded-md cursor-pointer transition hover:bg-[#F5F5F5]">
                                                    <input type="checkbox" class="rev-unit-check w-3.5 h-3.5 cursor-pointer accent-amber-500"
                                                        data-area="<?= htmlspecialchars($area) ?>" data-area-slug="<?= $slug ?>"
                                                        data-unit-num="<?= $unit['unit_num'] ?>"
                                                        data-unit-name="<?= htmlspecialchars($unit['unit_name'] ?? '') ?>"
                                                        onclick="onUnitCheck(this);">
                                                    <span class="text-[13px] text-ink">
                                                        <i class="fas fa-door-open text-muted text-[11px]"></i>
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
                    <div id="selectionSummary" class="hidden bg-amber-50 border border-amber-300 rounded-lg p-4 mb-4">
                        <div class="text-xs font-semibold text-amber-800 uppercase tracking-[0.4px] mb-3">
                            <i class="fas fa-list-check"></i> Selected for Revision — add a reason for each
                        </div>
                        <div id="selectionItems" class="flex flex-col gap-2.5"></div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" id="revisionSubmitBtn" disabled
                            class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-6 py-2.5 text-[13px] font-semibold opacity-50 cursor-not-allowed transition">
                            <i class="fas fa-redo-alt"></i> Submit Revision Request
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- ── Revision History ───────────────────────────────────────────── -->
        <?php if (!empty($revisionLogs)): ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-line">
                    <div class="flex items-center gap-2.5 text-xs font-semibold">
                        <i class="fas fa-history"></i> Revision History
                        <span class="text-muted font-normal">(<?= count($revisionLogs) ?> entr<?= count($revisionLogs) > 1 ? 'ies' : 'y' ?>)</span>
                    </div>
                    <button type="button" onclick="toggleRevPanel('revHistoryPanel','revHistoryChevron')"
                        class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                        <i class="fas fa-eye"></i> Toggle
                        <i class="fas fa-chevron-down text-[11px]" id="revHistoryChevron"></i>
                    </button>
                </div>
                <div id="revHistoryPanel" class="hidden">
                    <div class="flex flex-col gap-2.5">
                        <?php foreach ($revisionLogs as $log): ?>
                            <div class="border border-line rounded-lg px-4 py-3.5 bg-[#F5F5F5]">
                                <div class="flex justify-between items-start gap-2.5 flex-wrap mb-1.5">
                                    <div class="text-[13px] font-semibold text-ink">
                                        <i class="fas fa-map-marker-alt text-soft"></i>
                                        <?= htmlspecialchars($log['area']) ?>
                                        <?php if ($log['room_unit_number']): ?>
                                            <span class="text-soft font-normal"> › </span>
                                            <i class="fas fa-door-open text-muted text-[11px]"></i>
                                            Unit <?= $log['room_unit_number'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] px-2.5 py-0.5 rounded-full font-bold
                                        <?= $log['status'] === 'pending' ? 'bg-amber-100 text-amber-800' : ($log['status'] === 'done' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-soft') ?>">
                                        <?= ucfirst($log['status']) ?>
                                    </span>
                                </div>
                                <?php if ($log['reason']): ?>
                                    <div class="text-[12px] text-soft bg-white px-2.5 py-1.5 rounded-md border-l-2 border-amber-500 italic mb-1.5">
                                        <i class="fas fa-quote-left text-[10px] opacity-50"></i>
                                        <?= htmlspecialchars($log['reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-muted flex items-center gap-1.5">
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
        <div class="mb-[22px]">
            <a href="td-attachments?client_id=<?= $client_id ?>"
                class="bg-ink text-white rounded-[10px] px-7 py-6 flex items-center gap-4 hover:opacity-90 transition">
                <i class="fas fa-paperclip text-2xl opacity-90"></i>
                <div>
                    <div class="text-base font-bold">TD Attachments</div>
                    <div class="text-xs opacity-75 mt-0.5">Upload technical documents &amp; cutting list files per area</div>
                </div>
                <i class="fas fa-chevron-right ml-auto opacity-70"></i>
            </a>
        </div>

        <?php if ($allAreasApproved && $cuttingTrackerStatus === 'Ongoing' && $cuttingTrackerId && $isAssigned): ?>
            <div class="bg-emerald-50 border border-emerald-300 rounded-[10px] p-5 mb-5 flex justify-between items-center gap-4 flex-wrap">
                <div>
                    <div class="font-semibold text-[15px] text-emerald-900 mb-1">
                        <i class="fas fa-check-double"></i> All Areas Approved!
                    </div>
                    <div class="text-[13px] text-emerald-800 opacity-85">
                        All TD attachments have been fully approved. You can now mark the Cuttinglist stage as Done.
                    </div>
                </div>
                <button onclick="markCuttinglistDone(<?= $cuttingTrackerId ?>)"
                    class="inline-flex items-center gap-2 bg-emerald-700 text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap">
                    <i class="fas fa-check-circle"></i> Mark Cuttinglist as Done
                </button>
            </div>
        <?php elseif ($allAreasApproved && $cuttingTrackerStatus === 'Done' && $isAssigned): ?>
            <div class="bg-emerald-50 border border-emerald-300 rounded-[10px] px-6 py-4 mb-5 flex items-center gap-2.5">
                <i class="fas fa-check-circle text-emerald-600 text-xl"></i>
                <span class="font-semibold text-sm text-emerald-900">Cuttinglist stage is marked as Done.</span>
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
            const open = el.style.display !== 'none' && el.style.display !== '';
            el.style.display = open ? 'none' : 'block';
            if (chv) chv.style.transform = open ? '' : 'rotate(180deg)';
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
                return `<div class="border border-amber-300 rounded-lg p-3.5 bg-white">
            <div class="flex justify-between items-center mb-2">
                <span class="text-[13px] font-semibold text-amber-900"><i class="fas fa-map-marker-alt"></i> ${label}</span>
                <button type="button" onclick="removeSelection('${key}')" class="bg-transparent border-none text-red-500 cursor-pointer text-[13px]"><i class="fas fa-times"></i> Remove</button>
            </div>
            <textarea placeholder="Reason for revision on this area/unit... *" oninput="updateReason('${key}',this.value)"
                class="w-full px-2.5 py-2 border border-line rounded-md text-[13px] font-sans resize-y min-h-[60px] box-border focus:outline-none focus:border-ink"
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
            btn.disabled = !ready;
            btn.style.opacity = ready ? '1' : '0.5';
            btn.style.cursor = ready ? 'pointer' : 'not-allowed';
        }
        function confirmRevision() {
            if (revSelections.length === 0) return false;
            if (!revSelections.every(s => s.reason.trim() !== '')) { alert('Please fill in a reason for each selected area/unit.'); return false; }
            const lines = revSelections.map(s => s.unitNum !== null ? '  • ' + s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum) : '  • ' + s.area + ' (whole area)').join('\n');
            return confirm('This will request a revision.\n\nAreas/units to reset:\n' + lines + '\n\nApprovals for these will be reset. Continue?');
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
            const open = panel.style.display !== 'none' && panel.style.display !== '';
            panel.style.display = open ? 'none' : 'block';
            if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
        }
    </script>
</body>

</html>