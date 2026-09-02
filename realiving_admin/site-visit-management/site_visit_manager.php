<?php
//site_visit_manager.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$headStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$headStmt->bind_param("i", $admin_id);
$headStmt->execute();
$userInfo = $headStmt->get_result()->fetch_assoc();

if (!$userInfo['is_head'] || $userInfo['role'] === 'technical_designer') {
    die("Access denied: Only the head designer can manage site visits.");
}

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Handle Add New Site Visit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_visit') {
        $designer1_id = intval($_POST['designer1_id']);
        $designer2_id = !empty($_POST['designer2_id']) ? intval($_POST['designer2_id']) : null;
        $visit_date = $_POST['visit_date'];
        $notes = trim($_POST['notes'] ?? '');
        $visit_type = $_POST['visit_type'] ?? 'Free';
        $visit_amount = ($visit_type === 'Paid' && !empty($_POST['visit_amount'])) ? floatval($_POST['visit_amount']) : null;

        if (!$designer1_id || !$visit_date) {
            $error = "Designer 1 and Visit Date are required.";
        } elseif ($visit_type === 'Paid' && !$visit_amount) {
            $error = "Please enter the amount for Paid site visit.";
        } else {
            $visit_time = !empty($_POST['visit_time']) ? $_POST['visit_time'] : null;

            $insertStmt = $conn->prepare("
    INSERT INTO site_visit (client_id, designer1_id, designer2_id, visit_date, visit_time, notes, visit_type, visit_amount, status, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
");
            $insertStmt->bind_param("iiissssdi", $client_id, $designer1_id, $designer2_id, $visit_date, $visit_time, $notes, $visit_type, $visit_amount, $admin_id);

            if ($insertStmt->execute()) {
                $trackStmt = $conn->prepare("
        UPDATE project_tracker 
        SET status='Ongoing', updated_by=?, updated_at=NOW()
        WHERE client_id=? AND stage_name='Site Visit'
    ");
                $trackStmt->bind_param("ii", $admin_id, $client_id);
                $trackStmt->execute();

                // If this is the FIRST site visit, assign designers to the client in user_info
                $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM site_visit WHERE client_id = ?");
                $countStmt->bind_param("i", $client_id);
                $countStmt->execute();
                $visitCount = $countStmt->get_result()->fetch_assoc()['cnt'];

                if ($visitCount === 1) {
                    $d2ForAssign = $designer2_id ?? null;
                    $assignDesignerStmt = $conn->prepare("
        UPDATE user_info 
        SET designer1_id = ?, designer2_id = ?
        WHERE id = ?
    ");
                    $assignDesignerStmt->bind_param("iii", $designer1_id, $d2ForAssign, $client_id);
                    $assignDesignerStmt->execute();
                }

                $success = "Site visit scheduled successfully!";
            } else {
                $error = "Failed to save. Please try again.";
            }
        }
    }

    if ($_POST['action'] === 'mark_stage_done') {
        $trackStmt = $conn->prepare("UPDATE project_tracker SET status='Done', updated_by=?, updated_at=NOW() WHERE client_id=? AND stage_name='Site Visit'");
        $trackStmt->bind_param("ii", $admin_id, $client_id);
        $trackStmt->execute();

        // Mark all pending/ongoing visits as done
        $doneStmt = $conn->prepare("UPDATE site_visit SET status='Done' WHERE client_id=? AND status != 'Done'");
        $doneStmt->bind_param("i", $client_id);
        $doneStmt->execute();

        $success = "Site Visit stage marked as Done!";
    }

    if ($_POST['action'] === 'revert_stage_ongoing') {
        $trackStmt = $conn->prepare("UPDATE project_tracker SET status='Ongoing', updated_by=?, updated_at=NOW() WHERE client_id=? AND stage_name='Site Visit'");
        $trackStmt->bind_param("ii", $admin_id, $client_id);
        $trackStmt->execute();
        $success = "Site Visit stage reverted to Ongoing. You can now add more visits.";
    }

    if ($_POST['action'] === 'delete_visit') {
        $visit_id = intval($_POST['visit_id']);
        $delStmt = $conn->prepare("DELETE FROM site_visit WHERE id=? AND client_id=?");
        $delStmt->bind_param("ii", $visit_id, $client_id);
        $delStmt->execute();
        $success = "Visit removed.";
    }

    if ($_POST['action'] === 'resubmit_visit') {
        $visit_id = intval($_POST['visit_id']);
        $designer1_id = intval($_POST['designer1_id']);
        $designer2_id = !empty($_POST['designer2_id']) ? intval($_POST['designer2_id']) : null;
        $visit_date = $_POST['visit_date'];
        $visit_time = !empty($_POST['visit_time']) ? $_POST['visit_time'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $visit_type = $_POST['visit_type'] ?? 'Free';
        $visit_amount = ($visit_type === 'Paid' && !empty($_POST['visit_amount'])) ? floatval($_POST['visit_amount']) : null;

        $resubStmt = $conn->prepare("
        UPDATE site_visit 
        SET designer1_id = ?, designer2_id = ?, visit_date = ?, visit_time = ?,
            notes = ?, visit_type = ?, visit_amount = ?,
            approval_status = 'Pending', approval_comment = NULL, approved_by = NULL, approved_at = NULL
        WHERE id = ? AND client_id = ?
    ");
        $resubStmt->bind_param(
            "iissssdii",
            $designer1_id,
            $designer2_id,
            $visit_date,
            $visit_time,
            $notes,
            $visit_type,
            $visit_amount,
            $visit_id,
            $client_id
        );
        $resubStmt->execute();
        $success = "Visit updated and resubmitted for approval.";
    }

    if ($_POST['action'] === 'toggle_absent') {
        $visit_id = intval($_POST['visit_id']);
        $which = $_POST['which'];
        $absent_val = intval($_POST['absent_val']);
        $absent_reason = trim($_POST['absent_reason'] ?? '');
        $replacement_id = !empty($_POST['replacement_designer_id']) ? intval($_POST['replacement_designer_id']) : null;

        if (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid request.";
        } else {
            if ($absent_val === 1 && empty($absent_reason)) {
                $error = "Please provide a reason for marking as absent.";
            } else {

                // ── MARKING ABSENT ──
                if ($absent_val === 1) {

                    // Fetch current designer IDs so we can save originals
                    $origStmt = $conn->prepare("
                        SELECT designer1_id, designer2_id,
                               original_designer1_id, original_designer2_id
                        FROM site_visit WHERE id = ?
                    ");
                    $origStmt->bind_param("i", $visit_id);
                    $origStmt->execute();
                    $origRow = $origStmt->get_result()->fetch_assoc();

                    if ($which === 'designer1') {
                        // Save original only if not already saved
                        $saveOriginal = $origRow['original_designer1_id'] ?? null;
                        if (!$saveOriginal) {
                            $saveOriginal = $origRow['designer1_id'];
                        }

                        if ($replacement_id) {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer1_absent = 1,
                                    designer1_absent_reason = ?,
                                    designer1_id = ?,
                                    original_designer1_id = ?
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("siiii", $absent_reason, $replacement_id, $saveOriginal, $visit_id, $client_id);
                        } else {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer1_absent = 1,
                                    designer1_absent_reason = ?,
                                    original_designer1_id = ?
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("siii", $absent_reason, $saveOriginal, $visit_id, $client_id);
                        }

                    } else { // designer2
                        $saveOriginal = $origRow['original_designer2_id'] ?? null;
                        if (!$saveOriginal) {
                            $saveOriginal = $origRow['designer2_id'];
                        }

                        if ($replacement_id) {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer2_absent = 1,
                                    designer2_absent_reason = ?,
                                    designer2_id = ?,
                                    original_designer2_id = ?
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("siiii", $absent_reason, $replacement_id, $saveOriginal, $visit_id, $client_id);
                        } else {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer2_absent = 1,
                                    designer2_absent_reason = ?,
                                    original_designer2_id = ?
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("siii", $absent_reason, $saveOriginal, $visit_id, $client_id);
                        }
                    }

                    $abStmt->execute();

                    // Check if visit should now be marked Done
                    $checkStmt = $conn->prepare("
    SELECT designer1_id, designer2_id,
           designer1_finished, designer2_finished,
           designer1_absent, designer2_absent,
           original_designer1_id, original_designer2_id
    FROM site_visit WHERE id = ?
");
                    $checkStmt->bind_param("i", $visit_id);
                    $checkStmt->execute();
                    $vRow = $checkStmt->get_result()->fetch_assoc();

                    // A slot is "ok" if:
// - The original was marked absent AND there's no replacement (original_designerX_id is set means replaced)
//   → absent with NO replacement = ok (slot skipped)
// - The current designer (possibly replacement) has finished
                    $d1HasReplacement = !empty($vRow['original_designer1_id']);
                    $d2HasReplacement = !empty($vRow['original_designer2_id']);

                    $d1ok = ($vRow['designer1_absent'] && !$d1HasReplacement) || $vRow['designer1_finished'];
                    $d2ok = !$vRow['designer2_id'] || ($vRow['designer2_absent'] && !$d2HasReplacement) || $vRow['designer2_finished'];

                    if ($d1ok && $d2ok) {
                        $doneStmt = $conn->prepare("UPDATE site_visit SET status='Done' WHERE id=?");
                        $doneStmt->bind_param("i", $visit_id);
                        $doneStmt->execute();
                    }

                    $msg = "Marked as absent.";
                    if ($replacement_id)
                        $msg .= " Designer replaced successfully.";
                    $success = $msg;

                    // ── UNDOING ABSENCE — restore original designer ──
                } else {

                    // Fetch original IDs
                    $origStmt = $conn->prepare("
                        SELECT original_designer1_id, original_designer2_id
                        FROM site_visit WHERE id = ?
                    ");
                    $origStmt->bind_param("i", $visit_id);
                    $origStmt->execute();
                    $origRow = $origStmt->get_result()->fetch_assoc();

                    if ($which === 'designer1') {
                        $restoreId = $origRow['original_designer1_id'];
                        if ($restoreId) {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer1_absent = 0,
                                    designer1_absent_reason = NULL,
                                    designer1_id = ?,
                                    original_designer1_id = NULL
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("iii", $restoreId, $visit_id, $client_id);
                        } else {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer1_absent = 0,
                                    designer1_absent_reason = NULL
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("ii", $visit_id, $client_id);
                        }
                    } else {
                        $restoreId = $origRow['original_designer2_id'];
                        if ($restoreId) {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer2_absent = 0,
                                    designer2_absent_reason = NULL,
                                    designer2_id = ?,
                                    original_designer2_id = NULL
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("iii", $restoreId, $visit_id, $client_id);
                        } else {
                            $abStmt = $conn->prepare("
                                UPDATE site_visit
                                SET designer2_absent = 0,
                                    designer2_absent_reason = NULL
                                WHERE id = ? AND client_id = ?
                            ");
                            $abStmt->bind_param("ii", $visit_id, $client_id);
                        }
                    }

                    $abStmt->execute();
                    $success = "Absence removed. Original designer restored.";
                }
            }
        }
    }

    // PRG — prevent resubmission on back/reload
    $redirect_url = "site-visit-manager?client_id={$client_id}";
    if ($success)
        $redirect_url .= "&success=" . urlencode($success);
    if ($error)
        $redirect_url .= "&error=" . urlencode($error);
    header("Location: " . $redirect_url);
    exit();
}

// Fetch client
$clientStmt = $conn->prepare("SELECT clientname, nameproject, reference_number, status, contact, email, address, project_scope, scope_of_work, house_state, permit_required, target_movein_date FROM user_info WHERE id = ?");
$clientStmt->bind_param("i", $client_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
if (!$client)
    die("Client not found.");

$house_state = $client['house_state'] ?? '';
$permit_required = $client['permit_required'] ?? '';
$target_movein_date = $client['target_movein_date'] ?? '';

// Fetch already assigned designers for this client
$assignedDesignersStmt = $conn->prepare("
    SELECT designer1_id, designer2_id FROM user_info WHERE id = ?
");
$assignedDesignersStmt->bind_param("i", $client_id);
$assignedDesignersStmt->execute();
$assignedDesigners = $assignedDesignersStmt->get_result()->fetch_assoc();
$hasAssignedDesigners = !empty($assignedDesigners['designer1_id']);

// Fetch all site visits for this client
$visitsStmt = $conn->prepare("
    SELECT sv.*,
           a1.full_name as designer1_name,
           a2.full_name as designer2_name,
           ab.full_name as approved_by_name,
           orig1.full_name as original_designer1_name,
           orig2.full_name as original_designer2_name
    FROM site_visit sv
    LEFT JOIN account a1    ON sv.designer1_id          = a1.id
    LEFT JOIN account a2    ON sv.designer2_id          = a2.id
    LEFT JOIN account ab    ON sv.approved_by           = ab.id
    LEFT JOIN account orig1 ON sv.original_designer1_id = orig1.id
    LEFT JOIN account orig2 ON sv.original_designer2_id = orig2.id
    WHERE sv.client_id = ?
    ORDER BY sv.visit_date ASC
");
$visitsStmt->bind_param("i", $client_id);
$visitsStmt->execute();
$allVisits = $visitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch stage status
$stageStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id=? AND stage_name='Site Visit'");
$stageStmt->bind_param("i", $client_id);
$stageStmt->execute();
$stageStatus = $stageStmt->get_result()->fetch_assoc()['status'] ?? 'Pending';

// Fetch designers with workload (from user_info active clients)
$workloadStmt = $conn->prepare("
    SELECT 
        a.id, 
        a.full_name,
        COUNT(DISTINCT CASE 
            WHEN (ui2.designer1_id = a.id OR ui2.designer2_id = a.id)
            AND ui2.designer1_id IS NOT NULL
            AND (pt.status IS NULL OR pt.status != 'Done')
            THEN ui2.id 
        END) as assigned_as_designer,
        COUNT(DISTINCT sv1.client_id) as site_visit_assignments
    FROM account a
    LEFT JOIN user_info ui2 ON (ui2.designer1_id = a.id OR ui2.designer2_id = a.id)
        AND ui2.designer1_id IS NOT NULL
    LEFT JOIN project_tracker pt ON pt.client_id = ui2.id 
        AND pt.stage_name = '2D / 3D Layout'
    LEFT JOIN site_visit sv1 ON (a.id = sv1.designer1_id OR a.id = sv1.designer2_id) 
        AND sv1.status != 'Done'
    WHERE a.role = 'designer'
    GROUP BY a.id, a.full_name
    ORDER BY assigned_as_designer ASC, a.full_name ASC
");
$workloadStmt->execute();
$designerWorkloads = $workloadStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$designers = array_map(fn($d) => ['id' => $d['id'], 'full_name' => $d['full_name']], $designerWorkloads);

// Auto-update due status:
// Due = designers submitted their report AFTER the visit date (late)
// Not Due = submitted on time OR not yet submitted (future/pending)
$autoUpdateDueStmt = $conn->prepare("
    UPDATE site_visit 
    SET is_due = CASE
        WHEN designer1_finished = 1 
             AND DATE(designer1_finished_at) > DATE_ADD(visit_date, INTERVAL 2 DAY)
        THEN 1
        WHEN designer2_id IS NOT NULL 
             AND designer2_finished = 1 
             AND DATE(designer2_finished_at) > DATE_ADD(visit_date, INTERVAL 2 DAY)
        THEN 1
        ELSE 0
    END
    WHERE client_id = ?
");
$autoUpdateDueStmt->bind_param("i", $client_id);
$autoUpdateDueStmt->execute();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Visit Manager</title>
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
    <div class="max-w-[1200px] mx-auto px-5 py-8">

        <!-- Back button -->
        <div class="mb-5">
            <a href="unified-project-tracker?client_id=<?= $client_id ?>"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back to Tracker
            </a>
        </div>

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5 flex justify-between items-start gap-4 flex-wrap">
            <div>
                <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                    <i class="fas fa-map-marker-alt"></i> Site Visit Manager
                </div>
                <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($client['clientname']) ?></h1>
                <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($client['nameproject']) ?></p>
                <p class="text-[13px] text-muted mt-1">
                    Ref: <?= htmlspecialchars($client['reference_number']) ?>
                    &nbsp;•&nbsp; Client Status: <strong class="text-ink"><?= htmlspecialchars($client['status']) ?></strong>
                </p>
            </div>
            <button onclick="document.getElementById('clientDetailModal').style.display='flex'"
                class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-sm font-semibold hover:opacity-90 transition whitespace-nowrap">
                <i class="fas fa-info-circle"></i> View Full Details
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

        <!-- ── Stage Status Bar (own strip, always visible) ── -->
        <?php
        $allVisitsDone = !empty($allVisits) && count(array_filter($allVisits, fn($v) => $v['status'] !== 'Done')) === 0;
        $stageBadgeClass = 'bg-[#F5F5F5] text-soft border-line';
        if (strtolower($stageStatus) === 'pending') $stageBadgeClass = 'bg-amber-100 text-amber-800 border-amber-300';
        elseif (strtolower($stageStatus) === 'ongoing') $stageBadgeClass = 'bg-blue-100 text-blue-800 border-blue-300';
        elseif (strtolower($stageStatus) === 'done') $stageBadgeClass = 'bg-emerald-100 text-emerald-800 border-emerald-300';
        ?>
        <div class="bg-white border border-line rounded-[10px] p-5 mb-5 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3.5">
                <div>
                    <div class="text-[13px] font-bold">Site Visit Stage Status</div>
                    <div class="text-[12px] text-muted mt-0.5"><?= count($allVisits) ?> visit(s) scheduled</div>
                </div>
                <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wide border <?= $stageBadgeClass ?>">
                    <?= $stageStatus ?>
                </span>
            </div>
            <?php if ($stageStatus !== 'Done' && $allVisitsDone): ?>
                <form method="POST" onsubmit="return confirm('Mark the entire Site Visit stage as Done?')">
                    <input type="hidden" name="action" value="mark_stage_done">
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-emerald-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                        <i class="fas fa-check-double"></i> Mark Stage Done
                    </button>
                </form>
            <?php elseif ($stageStatus === 'Done'): ?>
                <form method="POST" onsubmit="return confirm('Revert Site Visit stage back to Ongoing? You can then add more visits.')">
                    <input type="hidden" name="action" value="revert_stage_ongoing">
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                        <i class="fas fa-undo"></i> Revert to Ongoing
                    </button>
                </form>
            <?php elseif ($stageStatus !== 'Done' && !empty($allVisits) && !$allVisitsDone): ?>
                <div class="text-[12px] text-muted flex items-center gap-1.5">
                    <i class="fas fa-info-circle"></i> All visits must be Done before marking stage complete.
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5 items-start">
            <!-- LEFT COLUMN -->
            <div class="flex flex-col gap-5">

                <!-- Scheduled Visits -->
                <div class="bg-white border border-line rounded-[10px] p-6">
                    <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                        <i class="fas fa-calendar-check text-soft"></i> Scheduled Visits
                        <span class="flex-1 h-px bg-line"></span>
                    </div>

                    <?php if (empty($allVisits)): ?>
                        <div class="text-center py-10 text-muted">
                            <i class="fas fa-calendar-times text-3xl mb-3 block"></i>
                            No site visits scheduled yet. Add one using the form below.
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col gap-4">
                            <?php foreach ($allVisits as $vi => $visit): ?>
                                <?php
                                $statusL = strtolower($visit['status']);
                                $stripeClass = 'border-line';
                                $stBadge = 'bg-[#F5F5F5] text-soft border-line';
                                if ($statusL === 'pending') { $stripeClass = 'border-l-amber-400'; $stBadge = 'bg-amber-100 text-amber-800 border-amber-300'; }
                                elseif ($statusL === 'ongoing') { $stripeClass = 'border-l-blue-400'; $stBadge = 'bg-blue-100 text-blue-800 border-blue-300'; }
                                elseif ($statusL === 'done') { $stripeClass = 'border-l-emerald-400'; $stBadge = 'bg-emerald-100 text-emerald-800 border-emerald-300'; }
                                $visitDeadline = date('Y-m-d', strtotime($visit['visit_date'] . ' +2 days'));
                                ?>
                                <div class="border border-line <?= $stripeClass ?> border-l-4 rounded-lg p-4.5 p-[18px]">

                                    <!-- Top row: visit #, date/time, status, actions -->
                                    <div class="flex justify-between items-start gap-3 flex-wrap mb-3">
                                        <div>
                                            <div class="text-[11px] font-bold uppercase tracking-[0.5px] text-muted mb-1">
                                                Visit #<?= $vi + 1 ?>
                                            </div>
                                            <div class="text-[14px] font-semibold flex items-center gap-2 flex-wrap">
                                                <i class="fas fa-calendar-day text-soft"></i>
                                                <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                                                <?php if (!empty($visit['visit_time'])): ?>
                                                    <span class="text-soft font-normal">
                                                        <i class="fas fa-clock ml-1"></i> <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                                <?php if ($visit['is_due']): ?>
                                                    <span class="text-[11px] font-bold text-red-600"><i class="fas fa-exclamation-circle"></i> Due</span>
                                                <?php else: ?>
                                                    <span class="text-[11px] font-semibold text-emerald-600"><i class="fas fa-check-circle"></i> Not Due</span>
                                                <?php endif; ?>
                                                <?php if ($visit['visit_type'] === 'Paid'): ?>
                                                    <span class="text-[11px] font-bold text-amber-600"><i class="fas fa-money-bill-wave"></i> Paid — ₱<?= number_format($visit['visit_amount'], 2) ?></span>
                                                <?php else: ?>
                                                    <span class="text-[11px] text-muted"><i class="fas fa-gift"></i> Free</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase border <?= $stBadge ?>"><?= $visit['status'] ?></span>
                                            <?php if ($visit['status'] !== 'Done'): ?>
                                                <?php if ($visit['approval_status'] === 'Rejected'): ?>
                                                    <button type="button"
                                                        class="bg-blue-50 text-blue-800 border border-blue-200 rounded-lg px-2.5 py-1.5 text-[11px] font-bold hover:bg-blue-100 transition"
                                                        onclick="openEditModal(<?= $visit['id'] ?>, 
                '<?= $visit['designer1_id'] ?>', 
                '<?= $visit['designer2_id'] ?? '' ?>', 
                '<?= $visit['visit_date'] ?>', 
                '<?= $visit['visit_time'] ?? '' ?>', 
                '<?= addslashes(htmlspecialchars($visit['notes'] ?? '')) ?>',
                '<?= $visit['visit_type'] ?>',
                '<?= $visit['visit_amount'] ?? '' ?>')">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('Remove this visit?')">
                                                    <input type="hidden" name="action" value="delete_visit">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <button type="submit"
                                                        class="bg-red-50 text-red-600 border border-red-200 rounded-lg px-2.5 py-1.5 text-[11px] font-bold hover:bg-red-100 transition">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Approval Status Banner -->
                                    <?php if ($visit['approval_status'] === 'Pending'): ?>
                                        <div class="bg-amber-50 border border-amber-300 rounded-lg px-3.5 py-2.5 mb-3 text-[12.5px] font-semibold text-amber-800 flex items-center gap-2">
                                            <i class="fas fa-clock"></i> Awaiting approval from General/Operational Manager
                                        </div>
                                    <?php elseif ($visit['approval_status'] === 'Rejected'): ?>
                                        <div class="bg-red-50 border border-red-300 rounded-lg px-3.5 py-2.5 mb-3 text-red-900">
                                            <div class="text-[12.5px] font-bold flex items-center gap-2">
                                                <i class="fas fa-times-circle"></i> Rejected by <?= htmlspecialchars($visit['approved_by_name'] ?? 'Manager') ?>
                                            </div>
                                            <?php if ($visit['approval_comment']): ?>
                                                <div class="text-[12px] mt-1.5 bg-white/70 rounded-md px-2.5 py-1.5 italic">
                                                    "<?= htmlspecialchars($visit['approval_comment']) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-[11px] mt-1.5 text-red-700">Please make adjustments and resubmit for approval.</div>
                                        </div>
                                    <?php elseif ($visit['approval_status'] === 'Approved'): ?>
                                        <div class="bg-emerald-50 border border-emerald-300 rounded-lg px-3.5 py-2.5 mb-3 text-[12.5px] font-semibold text-emerald-800 flex items-center gap-2 flex-wrap">
                                            <i class="fas fa-check-circle"></i> Approved by <?= htmlspecialchars($visit['approved_by_name'] ?? 'Manager') ?>
                                            <?php if ($visit['approved_at']): ?>
                                                <span class="font-normal ml-auto text-[11px]"><?= date('M d, Y g:i A', strtotime($visit['approved_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Designer slots: side by side -->
                                    <div class="grid grid-cols-1 <?= $visit['designer2_name'] ? 'md:grid-cols-2' : '' ?> gap-2.5 mb-3">
                                        <!-- Designer 1 -->
                                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-user-tie text-soft"></i>
                                                <span class="text-[13px] font-semibold"><?= htmlspecialchars($visit['designer1_name']) ?></span>
                                                <em class="text-[11px] text-muted font-normal not-italic">(D1)</em>
                                            </div>
                                            <?php if ($visit['designer1_absent']): ?>
                                                <div class="mt-2 flex items-start justify-between gap-2 flex-wrap">
                                                    <div>
                                                        <span class="bg-red-100 text-red-800 px-2.5 py-0.5 rounded-full text-[10px] font-bold">
                                                            <i class="fas fa-user-slash"></i> Absent
                                                        </span>
                                                        <?php if ($visit['original_designer1_name']): ?>
                                                            <div class="text-[11px] text-red-700 mt-1">
                                                                <i class="fas fa-user"></i> Originally: <strong><?= htmlspecialchars($visit['original_designer1_name']) ?></strong>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($visit['designer1_absent_reason']): ?>
                                                            <div class="text-[11px] text-red-700 mt-1 italic">"<?= htmlspecialchars($visit['designer1_absent_reason']) ?>"</div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($visit['status'] !== 'Done'): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="toggle_absent">
                                                            <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                            <input type="hidden" name="which" value="designer1">
                                                            <input type="hidden" name="absent_val" value="0">
                                                            <button type="submit" class="bg-emerald-100 text-emerald-800 rounded-md px-2 py-1 text-[10px] font-bold" title="Remove Absent">
                                                                <i class="fas fa-undo"></i> Undo
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($visit['status'] !== 'Done'): ?>
                                                <button type="button"
                                                    class="mt-2 bg-red-50 text-red-600 border border-red-200 rounded-md px-2.5 py-1 text-[10px] font-bold hover:bg-red-100 transition"
                                                    onclick="openAbsentModal(
                    <?= $visit['id'] ?>,
                    'designer1',
                    '<?= addslashes(htmlspecialchars($visit['designer1_name'])) ?>',
                    <?= intval($visit['designer1_id']) ?>,
                    <?= intval($visit['designer2_id'] ?? 0) ?>
                )">
                                                    <i class="fas fa-user-slash"></i> Mark Absent
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Designer 2 -->
                                        <?php if ($visit['designer2_name']): ?>
                                            <div class="bg-[#F5F5F5] border border-line rounded-lg p-3">
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-user-tie text-soft"></i>
                                                    <span class="text-[13px] font-semibold"><?= htmlspecialchars($visit['designer2_name']) ?></span>
                                                    <em class="text-[11px] text-muted font-normal not-italic">(D2)</em>
                                                </div>
                                                <?php if ($visit['designer2_absent']): ?>
                                                    <div class="mt-2 flex items-start justify-between gap-2 flex-wrap">
                                                        <div>
                                                            <span class="bg-red-100 text-red-800 px-2.5 py-0.5 rounded-full text-[10px] font-bold">
                                                                <i class="fas fa-user-slash"></i> Absent
                                                            </span>
                                                            <?php if ($visit['original_designer2_name']): ?>
                                                                <div class="text-[11px] text-red-700 mt-1">
                                                                    <i class="fas fa-user"></i> Originally: <strong><?= htmlspecialchars($visit['original_designer2_name']) ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($visit['designer2_absent_reason']): ?>
                                                                <div class="text-[11px] text-red-700 mt-1 italic">"<?= htmlspecialchars($visit['designer2_absent_reason']) ?>"</div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($visit['status'] !== 'Done'): ?>
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="toggle_absent">
                                                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                                <input type="hidden" name="which" value="designer2">
                                                                <input type="hidden" name="absent_val" value="0">
                                                                <button type="submit" class="bg-emerald-100 text-emerald-800 rounded-md px-2 py-1 text-[10px] font-bold">
                                                                    <i class="fas fa-undo"></i> Undo
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($visit['status'] !== 'Done'): ?>
                                                    <button type="button"
                                                        class="mt-2 bg-red-50 text-red-600 border border-red-200 rounded-md px-2.5 py-1 text-[10px] font-bold hover:bg-red-100 transition"
                                                        onclick="openAbsentModal(
                    <?= $visit['id'] ?>,
                    'designer2',
                    '<?= addslashes(htmlspecialchars($visit['designer2_name'])) ?>',
                    <?= intval($visit['designer2_id'] ?? 0) ?>,
                    <?= intval($visit['designer1_id']) ?>
                )">
                                                        <i class="fas fa-user-slash"></i> Mark Absent
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($visit['notes']): ?>
                                        <div class="text-[12px] text-soft bg-[#F5F5F5] border border-line rounded-md px-3 py-2 italic mb-3">
                                            <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($visit['notes']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Designer Reports: side by side -->
                                    <?php
                                    $hasR1 = $visit['designer1_report'] || $visit['designer1_finished'] || !empty($visit['designer1_photo']);
                                    $hasR2 = $visit['designer2_name'] && ($visit['designer2_report'] || $visit['designer2_finished'] || !empty($visit['designer2_photo']));
                                    ?>
                                    <?php if ($hasR1 || $hasR2): ?>
                                        <div class="grid grid-cols-1 <?= ($hasR1 && $hasR2) ? 'md:grid-cols-2' : '' ?> gap-2.5">
                                            <?php if ($hasR1): ?>
                                                <div class="bg-emerald-50 border-l-2 border-emerald-400 rounded-md p-3">
                                                    <div class="flex items-center justify-between gap-2 mb-1">
                                                        <strong class="text-[12px] text-emerald-800"><i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer1_name']) ?>'s Report</strong>
                                                        <?php if ($visit['designer1_finished']): ?>
                                                            <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full text-[10px] font-bold">Finished</span>
                                                        <?php elseif (!empty($visit['designer1_photo'])): ?>
                                                            <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-[10px] font-bold"><i class="fas fa-camera"></i> Photo</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($visit['designer1_finished'] && $visit['designer1_finished_at']): ?>
                                                        <div class="text-[11px] text-soft mb-2 flex items-center gap-1.5 flex-wrap">
                                                            <i class="fas fa-clock"></i> Submitted: <?= date('M d, Y g:i A', strtotime($visit['designer1_finished_at'])) ?>
                                                            <?php
                                                            $d1Date = date('Y-m-d', strtotime($visit['designer1_finished_at']));
                                                            if ($d1Date > $visitDeadline): ?>
                                                                <span class="bg-red-100 text-red-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-exclamation-circle"></i> Late</span>
                                                            <?php elseif ($d1Date <= $visit['visit_date']): ?>
                                                                <span class="bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-star"></i> Early</span>
                                                            <?php else: ?>
                                                                <span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-check"></i> On Time</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($visit['designer1_photo'])): ?>
                                                        <div class="mb-2">
                                                            <div class="text-[10px] font-bold text-emerald-800 mb-1"><i class="fas fa-camera"></i> Proof Photo</div>
                                                            <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer1_photo']) ?>"
                                                                alt="Proof" class="max-w-full max-h-[180px] rounded-md border border-emerald-200 object-cover cursor-pointer"
                                                                onclick="openPhotoModal(this.src)">
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($visit['designer1_report']): ?>
                                                        <div class="text-[12px]"><?= nl2br(htmlspecialchars($visit['designer1_report'])) ?></div>
                                                    <?php elseif (!$visit['designer1_finished']): ?>
                                                        <em class="text-muted text-[11px]"><i class="fas fa-hourglass-half"></i> Report not submitted yet.</em>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($hasR2): ?>
                                                <div class="bg-emerald-50 border-l-2 border-emerald-400 rounded-md p-3">
                                                    <div class="flex items-center justify-between gap-2 mb-1">
                                                        <strong class="text-[12px] text-emerald-800"><i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer2_name']) ?>'s Report</strong>
                                                        <?php if ($visit['designer2_finished']): ?>
                                                            <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full text-[10px] font-bold">Finished</span>
                                                        <?php elseif (!empty($visit['designer2_photo'])): ?>
                                                            <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-[10px] font-bold"><i class="fas fa-camera"></i> Photo</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($visit['designer2_finished'] && $visit['designer2_finished_at']): ?>
                                                        <div class="text-[11px] text-soft mb-2 flex items-center gap-1.5 flex-wrap">
                                                            <i class="fas fa-clock"></i> Submitted: <?= date('M d, Y g:i A', strtotime($visit['designer2_finished_at'])) ?>
                                                            <?php
                                                            $d2Date = date('Y-m-d', strtotime($visit['designer2_finished_at']));
                                                            if ($d2Date > $visitDeadline): ?>
                                                                <span class="bg-red-100 text-red-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-exclamation-circle"></i> Late</span>
                                                            <?php elseif ($d2Date <= $visit['visit_date']): ?>
                                                                <span class="bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-star"></i> Early</span>
                                                            <?php else: ?>
                                                                <span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-check"></i> On Time</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($visit['designer2_photo'])): ?>
                                                        <div class="mb-2">
                                                            <div class="text-[10px] font-bold text-emerald-800 mb-1"><i class="fas fa-camera"></i> Proof Photo</div>
                                                            <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer2_photo']) ?>"
                                                                alt="Proof" class="max-w-full max-h-[180px] rounded-md border border-emerald-200 object-cover cursor-pointer"
                                                                onclick="openPhotoModal(this.src)">
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($visit['designer2_report']): ?>
                                                        <div class="text-[12px]"><?= nl2br(htmlspecialchars($visit['designer2_report'])) ?></div>
                                                    <?php elseif (!$visit['designer2_finished']): ?>
                                                        <em class="text-muted text-[11px]"><i class="fas fa-hourglass-half"></i> Report not submitted yet.</em>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Add New Visit Form -->
                <?php if ($stageStatus !== 'Done'): ?>
                    <div class="bg-white border border-line rounded-[10px] p-6">
                        <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                            <i class="fas fa-calendar-plus text-soft"></i> Add New Site Visit
                            <span class="flex-1 h-px bg-line"></span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_visit">

                            <!-- Designers group -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Designer 1 <span class="text-red-500">*</span></label>
                                    <select name="designer1_id" required onchange="filterD2(this.value)"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                        <option value="">— Select Designer —</option>
                                        <?php foreach ($designers as $d): ?>
                                            <option value="<?= $d['id'] ?>" <?= ($hasAssignedDesigners && $assignedDesigners['designer1_id'] == $d['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($d['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($hasAssignedDesigners): ?>
                                        <div class="text-[11px] text-emerald-600 mt-1.5 flex items-center gap-1.5">
                                            <i class="fas fa-info-circle"></i> Auto-filled from client's assigned designer
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Designer 2 <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                    <select name="designer2_id" id="d2Select"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                        <option value="">— No 2nd Designer —</option>
                                        <?php foreach ($designers as $d): ?>
                                            <option value="<?= $d['id'] ?>" <?= ($hasAssignedDesigners && $assignedDesigners['designer2_id'] == $d['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($d['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($hasAssignedDesigners && $assignedDesigners['designer2_id']): ?>
                                        <div class="text-[11px] text-emerald-600 mt-1.5 flex items-center gap-1.5">
                                            <i class="fas fa-info-circle"></i> Auto-filled from client's assigned designer
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Schedule group -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Date <span class="text-red-500">*</span></label>
                                    <input type="date" name="visit_date" required min="<?= date('Y-m-d') ?>"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Time <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                    <input type="time" name="visit_time"
                                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                </div>
                            </div>

                            <!-- Type group -->
                            <div class="mb-4">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Type <span class="text-red-500">*</span></label>
                                <div class="flex gap-3 mb-3">
                                    <label class="flex items-center gap-2 cursor-pointer border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                                        <input type="radio" name="visit_type" value="Free" checked onchange="toggleAmount(this.value)">
                                        <i class="fas fa-gift text-emerald-600"></i> Free
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                                        <input type="radio" name="visit_type" value="Paid" onchange="toggleAmount(this.value)">
                                        <i class="fas fa-money-bill-wave text-amber-600"></i> Paid (Out of NCR)
                                    </label>
                                </div>
                                <div id="amountGroup" style="display:none;">
                                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Amount <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 font-bold text-soft">₱</span>
                                        <input type="number" name="visit_amount" step="0.01" min="0" placeholder="0.00"
                                            class="w-full border border-line rounded-lg pl-7 pr-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                    </div>
                                    <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-info-circle"></i> Set by the lead designer</div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="mb-4">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Notes <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <textarea name="notes" rows="3" placeholder="Additional instructions..."
                                    class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"></textarea>
                            </div>

                            <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 bg-ink text-white rounded-lg px-6 py-3 text-sm font-semibold hover:opacity-90 transition">
                                <i class="fas fa-plus-circle"></i> Add Site Visit
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: Designer Workload -->
            <div class="bg-white border border-line rounded-[10px] p-6">
                <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                    <i class="fas fa-users text-soft"></i> Designer Workload
                    <span class="flex-1 h-px bg-line"></span>
                </div>
                <div class="flex flex-col gap-2">
                    <?php foreach ($designerWorkloads as $dw): ?>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg px-3.5 py-2.5 flex items-center justify-between gap-2">
                            <div>
                                <div class="text-[13px] font-semibold"><?= htmlspecialchars($dw['full_name']) ?></div>
                                <div class="text-[11px] text-muted">Designer</div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <span class="bg-blue-100 text-blue-800 px-2.5 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" title="Clients assigned to this designer">
                                    <i class="fas fa-users text-[10px]"></i> <?= $dw['assigned_as_designer'] ?> client<?= $dw['assigned_as_designer'] != 1 ? 's' : '' ?>
                                </span>
                                <?php if ($dw['site_visit_assignments'] > 0): ?>
                                    <span class="bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" title="Active site visit assignments">
                                        <i class="fas fa-map-marker-alt text-[10px]"></i> <?= $dw['site_visit_assignments'] ?> visit<?= $dw['site_visit_assignments'] != 1 ? 's' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="bg-emerald-100 text-emerald-800 px-2.5 py-0.5 rounded-full text-[11px] font-bold">Free</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Absent Reason Modal -->
    <div id="absentModal" class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center">
        <div class="bg-white rounded-[14px] p-7 max-w-[480px] w-[90%]">
            <h3 class="text-red-800 mb-1.5 text-[18px] font-bold"><i class="fas fa-user-slash"></i> Mark as Absent</h3>
            <p class="text-[13px] text-soft mb-5">Provide a reason for the absence. You may also assign a replacement designer.</p>
            <form method="POST" id="absentForm">
                <input type="hidden" name="action" value="toggle_absent">
                <input type="hidden" name="absent_val" value="1">
                <input type="hidden" name="visit_id" id="absentVisitId">
                <input type="hidden" name="which" id="absentWhich">

                <div class="mb-4 bg-red-50 border border-red-300 rounded-lg px-3.5 py-3 flex items-center gap-2.5">
                    <i class="fas fa-user-tie text-red-800"></i>
                    <div>
                        <div class="text-[11px] text-muted uppercase font-semibold tracking-[0.4px]">Absent Designer</div>
                        <div class="text-[14px] font-bold text-red-800" id="absentDesignerName">—</div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Reason for Absence <span class="text-red-500">*</span></label>
                    <textarea name="absent_reason" id="absentReason" required rows="3" placeholder="e.g. Sick, Emergency, No show..."
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink resize-y"></textarea>
                </div>

                <div class="mb-5">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">
                        Assign Replacement Designer <span class="text-muted font-normal normal-case">(Optional)</span>
                    </label>
                    <select name="replacement_designer_id" id="replacementDesignerSelect"
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                        <option value="">— No Replacement (keep absent) —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>" data-name="<?= htmlspecialchars($d['full_name'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($d['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-[11px] text-soft mt-1.5 flex items-center gap-1.5">
                        <i class="fas fa-info-circle"></i> If selected, this designer will replace the absent one on this visit.
                    </div>
                </div>

                <div id="replacementPreview" style="display:none;" class="bg-blue-50 border border-blue-300 rounded-lg px-3.5 py-2.5 mb-4 text-[13px] text-blue-800 items-center gap-2">
                    <i class="fas fa-exchange-alt"></i> <span>Replacing with: <strong id="replacementName"></strong></span>
                </div>

                <div class="flex gap-2.5 justify-end">
                    <button type="button" onclick="closeAbsentModal()"
                        class="bg-white border border-line rounded-lg px-5 py-2.5 font-semibold text-[13px] hover:border-ink transition">Cancel</button>
                    <button type="submit"
                        class="bg-red-600 text-white rounded-lg px-5 py-2.5 font-semibold text-[13px] inline-flex items-center gap-2 hover:opacity-90 transition">
                        <i class="fas fa-user-slash"></i> Confirm Absent
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit & Resubmit Modal -->
    <div id="editResubmitModal" class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center">
        <div class="bg-white rounded-[14px] p-7 max-w-[520px] w-[90%] max-h-[90vh] overflow-y-auto">
            <h3 class="text-blue-800 mb-1.5 text-[18px] font-bold"><i class="fas fa-edit"></i> Edit & Resubmit Visit</h3>
            <p class="text-[13px] text-soft mb-5">Make your adjustments below, then resubmit for approval.</p>
            <form method="POST" id="editResubmitForm">
                <input type="hidden" name="action" value="resubmit_visit">
                <input type="hidden" name="visit_id" id="editVisitId">

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Designer 1 <span class="text-red-500">*</span></label>
                    <select name="designer1_id" id="editD1" required onchange="filterEditD2(this.value)"
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                        <option value="">— Select Designer —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Designer 2 <span class="text-muted font-normal normal-case">(Optional)</span></label>
                    <select name="designer2_id" id="editD2"
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                        <option value="">— No 2nd Designer —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Date <span class="text-red-500">*</span></label>
                    <input type="date" name="visit_date" id="editDate" required
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Time <span class="text-muted font-normal normal-case">(Optional)</span></label>
                    <input type="time" name="visit_time" id="editTime"
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Notes <span class="text-muted font-normal normal-case">(Optional)</span></label>
                    <textarea name="notes" id="editNotes" rows="3" placeholder="Additional instructions..."
                        class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"></textarea>
                </div>

                <div class="mb-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Type <span class="text-red-500">*</span></label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                            <input type="radio" name="visit_type" value="Free" id="editTypeFree" onchange="toggleEditAmount(this.value)">
                            <i class="fas fa-gift text-emerald-600"></i> Free
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                            <input type="radio" name="visit_type" value="Paid" id="editTypePaid" onchange="toggleEditAmount(this.value)">
                            <i class="fas fa-money-bill-wave text-amber-600"></i> Paid
                        </label>
                    </div>
                </div>

                <div class="mb-4" id="editAmountGroup" style="display:none;">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-1.5">Visit Amount <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 font-bold text-soft">₱</span>
                        <input type="number" name="visit_amount" id="editAmount" step="0.01" min="0" placeholder="0.00"
                            class="w-full border border-line rounded-lg pl-7 pr-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                    </div>
                </div>

                <div class="flex gap-2.5 justify-end mt-2">
                    <button type="button" onclick="closeEditModal()"
                        class="bg-white border border-line rounded-lg px-5 py-2.5 font-semibold text-[13px] hover:border-ink transition">Cancel</button>
                    <button type="submit"
                        class="bg-blue-700 text-white rounded-lg px-5.5 py-2.5 font-semibold text-[13px] inline-flex items-center gap-2 hover:opacity-90 transition">
                        <i class="fas fa-paper-plane"></i> Save & Resubmit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterD2(selectedId) {
            const select2 = document.getElementById('d2Select');
            Array.from(select2.options).forEach(opt => {
                opt.disabled = opt.value && opt.value === selectedId;
                if (opt.disabled && opt.selected) select2.value = '';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const d1 = document.querySelector('select[name="designer1_id"]');
            if (d1 && d1.value) filterD2(d1.value);
        });

        function toggleAmount(val) {
            document.getElementById('amountGroup').style.display = val === 'Paid' ? 'block' : 'none';
            document.querySelector('input[name="visit_amount"]').required = val === 'Paid';
        }

        function openAbsentModal(visitId, which, designerName, absentDesignerId, otherDesignerId) {
            document.getElementById('absentVisitId').value = visitId;
            document.getElementById('absentWhich').value = which;
            document.getElementById('absentReason').value = '';
            document.getElementById('absentDesignerName').textContent = designerName || '—';

            const repSelect = document.getElementById('replacementDesignerSelect');
            repSelect.value = '';
            document.getElementById('replacementPreview').style.display = 'none';

            Array.from(repSelect.options).forEach(opt => {
                if (!opt.value) {
                    opt.style.display = 'block';
                    return;
                }
                const optId = parseInt(opt.value);
                if (optId === absentDesignerId || optId === otherDesignerId) {
                    opt.style.display = 'none';
                    opt.disabled = true;
                } else {
                    opt.style.display = 'block';
                    opt.disabled = false;
                }
            });

            document.getElementById('absentModal').style.display = 'flex';
        }

        function closeAbsentModal() {
            document.getElementById('absentModal').style.display = 'none';
        }

        document.getElementById('replacementDesignerSelect').addEventListener('change', function () {
            const preview = document.getElementById('replacementPreview');
            const nameSpan = document.getElementById('replacementName');
            if (this.value) {
                const selectedOpt = this.options[this.selectedIndex];
                nameSpan.textContent = selectedOpt.dataset.name || selectedOpt.text;
                preview.style.display = 'flex';
            } else {
                preview.style.display = 'none';
            }
        });

        document.addEventListener('click', function (e) {
            const modal = document.getElementById('absentModal');
            if (e.target === modal) closeAbsentModal();
        });

        function openEditModal(visitId, d1, d2, date, time, notes, type, amount) {
            document.getElementById('editVisitId').value = visitId;
            document.getElementById('editD1').value = d1;
            document.getElementById('editD2').value = d2;
            document.getElementById('editDate').value = date;
            document.getElementById('editTime').value = time;
            document.getElementById('editNotes').value = notes;

            if (type === 'Paid') {
                document.getElementById('editTypePaid').checked = true;
                document.getElementById('editAmountGroup').style.display = 'block';
                document.getElementById('editAmount').value = amount;
            } else {
                document.getElementById('editTypeFree').checked = true;
                document.getElementById('editAmountGroup').style.display = 'none';
            }

            filterEditD2(d1);

            document.getElementById('editResubmitModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editResubmitModal').style.display = 'none';
        }

        function filterEditD2(selectedId) {
            const select2 = document.getElementById('editD2');
            Array.from(select2.options).forEach(opt => {
                opt.disabled = opt.value && opt.value === selectedId;
                if (opt.disabled && opt.selected) select2.value = '';
            });
        }

        function toggleEditAmount(val) {
            document.getElementById('editAmountGroup').style.display = val === 'Paid' ? 'block' : 'none';
            document.getElementById('editAmount').required = val === 'Paid';
        }

        document.addEventListener('click', function (e) {
            const modal = document.getElementById('editResubmitModal');
            if (e.target === modal) closeEditModal();
        });
    </script>

    <!-- Client Detail Modal -->
    <div id="clientDetailModal" class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center">
        <div class="bg-white p-7 rounded-[14px] max-w-xl w-[90%] max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-5 border-b border-line pb-3.5">
                <h2 class="text-lg font-bold flex items-center gap-2"><i class="fas fa-user-circle text-soft"></i> Client Details</h2>
                <button onclick="document.getElementById('clientDetailModal').style.display='none'" class="text-soft hover:text-ink text-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Reference Number:</div>
                <div class="text-blue-700 font-mono text-[13px] font-semibold"><?= htmlspecialchars($client['reference_number']) ?></div>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Client Name:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Project Name:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Status:</div>
                <div>
                    <?php $st = $client['status'] ?? ''; ?>
                    <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= $st === 'New Client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($client['contact'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Phone:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($client['contact']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($client['email'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Email:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($client['email']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($client['address'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Address:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($client['address']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($client['project_scope'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Project Scope:</div>
                    <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($client['scope_of_work'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Scope of Work:</div>
                    <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">House State:</div>
                <div>
                    <?php if ($house_state):
                        $hsClass = 'bg-amber-100 text-amber-800';
                        if ($house_state === 'Bare/Empty Lot') { $hsClass = 'bg-blue-100 text-blue-800'; }
                        elseif ($house_state === 'Construction Started') { $hsClass = 'bg-red-100 text-red-800'; }
                        elseif ($house_state === 'Renovation') { $hsClass = 'bg-purple-100 text-purple-800'; }
                        ?>
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?= $hsClass ?>"><?= htmlspecialchars($house_state) ?></span>
                    <?php else: ?>
                        <span class="text-muted text-[13px]">—</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Permit Required:</div>
                <div>
                    <?php if ($permit_required):
                        $prClass = 'bg-amber-100 text-amber-800';
                        if ($permit_required === 'Yes') { $prClass = 'bg-red-100 text-red-800'; }
                        elseif ($permit_required === 'No') { $prClass = 'bg-emerald-100 text-emerald-800'; }
                        ?>
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?= $prClass ?>"><?= htmlspecialchars($permit_required) ?></span>
                    <?php else: ?>
                        <span class="text-muted text-[13px]">—</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-[160px_1fr] py-3 items-start">
                <div class="font-semibold text-soft text-[13px]">Target Move-in:</div>
                <div class="text-ink text-[13px] font-semibold">
                    <?php if ($target_movein_date): ?>
                        <i class="fas fa-calendar-check text-emerald-600"></i> <?= date('F d, Y', strtotime($target_movein_date)) ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('clientDetailModal').addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });
    </script>

    <!-- Photo Lightbox -->
    <div id="photoModal" onclick="closePhotoModal()"
        class="hidden fixed inset-0 z-[99999] bg-black/90 items-center justify-center cursor-zoom-out">
        <img id="photoModalImg" src="" alt="Proof Photo" class="max-w-[92vw] max-h-[92vh] rounded-lg object-contain">
    </div>
    <script>
        function openPhotoModal(src) {
            document.getElementById('photoModalImg').src = src;
            document.getElementById('photoModal').style.display = 'flex';
        }
        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
        }
    </script>
</body>

</html>