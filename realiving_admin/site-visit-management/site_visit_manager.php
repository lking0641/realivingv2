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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 35px 40px;
            border-radius: 16px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            font-size: 26px;
            margin-bottom: 5px;
        }

        .page-header .subtitle {
            opacity: 0.85;
            font-size: 14px;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 9px 18px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
            margin-bottom: 18px;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 25px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .card h2 {
            font-size: 17px;
            color: #3b1f0f;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f5f1ed;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stage-status-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f9f9f9;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 2px solid #e9ecef;
        }

        .stage-status-label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .status-badge {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.ongoing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.done {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-done-stage {
            background: #10b981;
            color: white;
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.2s;
        }

        .btn-done-stage:hover {
            opacity: 0.85;
        }

        /* Visit Cards */
        .visit-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 14px;
            transition: all 0.2s;
            position: relative;
        }

        .visit-card.v-pending {
            border-left: 4px solid #f59e0b;
            background: #fffbeb;
        }

        .visit-card.v-ongoing {
            border-left: 4px solid #3b82f6;
            background: #eff6ff;
        }

        .visit-card.v-done {
            border-left: 4px solid #10b981;
            background: #f0fdf4;
        }

        .visit-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .visit-number {
            font-size: 13px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .visit-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .visit-info {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 13px;
            color: #374151;
        }

        .visit-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .visit-info i {
            color: #8a5a44;
        }

        .visit-notes {
            margin-top: 10px;
            padding: 10px;
            background: rgba(0, 0, 0, 0.03);
            border-radius: 6px;
            font-size: 12px;
            color: #666;
            font-style: italic;
        }

        .designer-report {
            margin-top: 10px;
            padding: 10px 14px;
            background: #f0fdf4;
            border-radius: 6px;
            border-left: 3px solid #10b981;
            font-size: 12px;
            color: #374151;
        }

        .designer-report strong {
            color: #065f46;
            display: block;
            margin-bottom: 4px;
        }

        .btn-sm {
            padding: 5px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: opacity 0.2s;
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        /* Form */
        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .required-star {
            color: #ef4444;
            margin-left: 2px;
        }

        .optional-tag {
            font-size: 10px;
            color: #9ca3af;
            font-weight: 400;
            text-transform: none;
            margin-left: 4px;
        }

        .form-control {
            width: 100%;
            padding: 9px 13px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            color: #111;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #8a5a44;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: opacity 0.2s;
        }

        .btn-submit:hover {
            opacity: 0.9;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
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

        /* Workload */
        .designer-card {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-left: 4px solid #e9ecef;
        }

        .designer-card-name {
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
        }

        .designer-card-sub {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .workload-badges {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-end;
        }

        .wb {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .wb-clients {
            background: #dbeafe;
            color: #1e40af;
        }

        .wb-visits {
            background: #fef3c7;
            color: #92400e;
        }

        .wb-free {
            background: #d1fae5;
            color: #065f46;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 12px;
            display: block;
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="unified-project-tracker?client_id=<?= $client_id ?>" class="btn-back"
            style="background: #3b1f0f; color: white; border: 1px solid #3b1f0f;">
            <i class="fas fa-arrow-left"></i> Back to Tracker
        </a>

        <div class="page-header">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                <div>
                    <h1><i class="fas fa-map-marker-alt"></i> Site Visit Manager</h1>
                    <div class="subtitle">
                        <?= htmlspecialchars($client['clientname']) ?> — <?= htmlspecialchars($client['nameproject']) ?>
                        &nbsp;•&nbsp; Ref: <?= htmlspecialchars($client['reference_number']) ?>
                        &nbsp;•&nbsp; Client Status: <strong><?= htmlspecialchars($client['status']) ?></strong>
                    </div>
                </div>
                <button onclick="document.getElementById('clientDetailModal').style.display='flex'" style="background:white; color:#3b1f0f; padding:10px 20px; border:none; border-radius:8px;
                       cursor:pointer; font-weight:600; font-size:14px; display:inline-flex;
                       align-items:center; gap:8px; transition:all 0.2s; white-space:nowrap;">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
        <?php endif; ?>

        <div class="grid-layout">
            <!-- LEFT COLUMN -->
            <div>
                <!-- Stage Status Bar -->
                <div class="card">
                    <div class="stage-status-bar">
                        <div>
                            <div class="stage-status-label">Site Visit Stage Status</div>
                            <div style="font-size: 12px; color: #9ca3af; margin-top: 3px;">
                                <?= count($allVisits) ?> visit(s) scheduled
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <span class="status-badge <?= strtolower($stageStatus) ?>"><?= $stageStatus ?></span>
                            <?php
                            $allVisitsDone = !empty($allVisits) && count(array_filter($allVisits, fn($v) => $v['status'] !== 'Done')) === 0;
                            ?>
                            <?php if ($stageStatus !== 'Done' && $allVisitsDone): ?>
                                <form method="POST" onsubmit="return confirm('Mark the entire Site Visit stage as Done?')">
                                    <input type="hidden" name="action" value="mark_stage_done">
                                    <button type="submit" class="btn-done-stage">
                                        <i class="fas fa-check-double"></i> Mark Stage Done
                                    </button>
                                </form>
                            <?php elseif ($stageStatus === 'Done'): ?>
                                <form method="POST"
                                    onsubmit="return confirm('Revert Site Visit stage back to Ongoing? You can then add more visits.')">
                                    <input type="hidden" name="action" value="revert_stage_ongoing">
                                    <button type="submit" style="background:#3b82f6; color:white; padding:9px 20px; border:none;
            border-radius:8px; cursor:pointer; font-weight:600; font-size:13px;
            display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fas fa-undo"></i> Revert to Ongoing
                                    </button>
                                </form>
                            <?php elseif ($stageStatus !== 'Done' && !empty($allVisits) && !$allVisitsDone): ?>
                                <div style="font-size:12px; color:#9ca3af; display:flex; align-items:center; gap:6px;">
                                    <i class="fas fa-info-circle"></i> All visits must be Done before marking stage
                                    complete.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h2><i class="fas fa-calendar-check"></i> Scheduled Visits</h2>

                    <?php if (empty($allVisits)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            No site visits scheduled yet. Add one using the form.
                        </div>
                    <?php else: ?>
                        <?php foreach ($allVisits as $vi => $visit): ?>
                            <div class="visit-card v-<?= strtolower($visit['status']) ?>">
                                <div class="visit-card-header">
                                    <span class="visit-number">Visit #<?= $vi + 1 ?></span>
                                    <div class="visit-actions">
                                        <span
                                            class="status-badge <?= strtolower($visit['status']) ?>"><?= $visit['status'] ?></span>
                                        <?php if ($visit['status'] !== 'Done'): ?>
                                            <?php if ($visit['approval_status'] === 'Rejected'): ?>
                                                <button type="button" class="btn-sm" style="background:#dbeafe; color:#1e40af;" onclick="openEditModal(<?= $visit['id'] ?>, 
                '<?= $visit['designer1_id'] ?>', 
                '<?= $visit['designer2_id'] ?? '' ?>', 
                '<?= $visit['visit_date'] ?>', 
                '<?= $visit['visit_time'] ?? '' ?>', 
                '<?= addslashes(htmlspecialchars($visit['notes'] ?? '')) ?>',
                '<?= $visit['visit_type'] ?>',
                '<?= $visit['visit_amount'] ?? '' ?>')">
                                                    <i class="fas fa-edit"></i> Edit & Resubmit
                                                </button>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;"
                                                onsubmit="return confirm('Remove this visit?')">
                                                <input type="hidden" name="action" value="delete_visit">
                                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                <button type="submit" class="btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i> Remove
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Approval Status Banner -->
                                <?php if ($visit['approval_status'] === 'Pending'): ?>
                                    <div style="background:#fef3c7; border-radius:8px; padding:10px 14px; margin-bottom:12px;
            display:flex; align-items:center; gap:10px; font-size:13px; font-weight:600; color:#92400e;">
                                        <i class="fas fa-clock"></i> Awaiting approval from General/Operational Manager
                                    </div>
                                <?php elseif ($visit['approval_status'] === 'Rejected'): ?>
                                    <div
                                        style="background:#fee2e2; border-radius:8px; padding:10px 14px; margin-bottom:12px; color:#991b1b;">
                                        <div style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;">
                                            <i class="fas fa-times-circle"></i> Rejected by
                                            <?= htmlspecialchars($visit['approved_by_name'] ?? 'Manager') ?>
                                        </div>
                                        <?php if ($visit['approval_comment']): ?>
                                            <div
                                                style="font-size:12px; margin-top:6px; padding:8px; background:#fff5f5; border-radius:6px; font-style:italic;">
                                                "<?= htmlspecialchars($visit['approval_comment']) ?>"
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size:11px; margin-top:6px; color:#dc2626;">
                                            Please make adjustments and resubmit for approval.
                                        </div>
                                    </div>
                                <?php elseif ($visit['approval_status'] === 'Approved'): ?>
                                    <div style="background:#d1fae5; border-radius:8px; padding:10px 14px; margin-bottom:12px;
            display:flex; align-items:center; gap:10px; font-size:13px; font-weight:600; color:#065f46;">
                                        <i class="fas fa-check-circle"></i> Approved by
                                        <?= htmlspecialchars($visit['approved_by_name'] ?? 'Manager') ?>
                                        <?php if ($visit['approved_at']): ?>
                                            <span
                                                style="font-weight:400; margin-left:auto; font-size:11px;"><?= date('M d, Y g:i A', strtotime($visit['approved_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="visit-info">
                                    <span>
                                        <i class="fas fa-calendar-day"></i>
                                        <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                                        <?php if (!empty($visit['visit_time'])): ?>
                                            &nbsp;<i class="fas fa-clock" style="margin-left:6px;"></i>
                                            <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                        <?php endif; ?>
                                    </span>

                                    <?php if ($visit['is_due']): ?>
                                        <span style="color:#ef4444; font-weight:700;">
                                            <i class="fas fa-exclamation-circle"></i> DUE
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#10b981; font-weight:600;">
                                            <i class="fas fa-check-circle"></i> Not Due
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($visit['visit_type'] === 'Paid'): ?>
                                        <span style="color:#f59e0b; font-weight:700;">
                                            <i class="fas fa-money-bill-wave"></i> Paid —
                                            ₱<?= number_format($visit['visit_amount'], 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#6b7280;">
                                            <i class="fas fa-gift"></i> Free
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Designer 1 Row -->
                                <div
                                    style="display:flex; align-items:center; gap:12px; margin-top:10px; padding:10px; background:#f9f9f9; border-radius:8px;">
                                    <i class="fas fa-user-tie" style="color:#8a5a44;"></i>
                                    <span
                                        style="font-size:13px; font-weight:600;"><?= htmlspecialchars($visit['designer1_name']) ?></span>
                                    <em style="font-size:11px; color:#9ca3af;">(Designer 1)</em>
                                    <?php if ($visit['designer1_absent']): ?>
                                        <div style="margin-left:auto; text-align:right;">
                                            <span
                                                style="background:#fee2e2; color:#991b1b; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700;">
                                                <i class="fas fa-user-slash"></i> Absent
                                            </span>
                                            <?php if ($visit['original_designer1_name']): ?>
                                                <div
                                                    style="font-size:11px; color:#991b1b; margin-top:4px; display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                                    <i class="fas fa-user"></i>
                                                    Originally:
                                                    <strong><?= htmlspecialchars($visit['original_designer1_name']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($visit['designer1_absent_reason']): ?>
                                                <div style="font-size:11px; color:#991b1b; margin-top:3px; font-style:italic;">
                                                    "<?= htmlspecialchars($visit['designer1_absent_reason']) ?>"
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($visit['status'] !== 'Done'): ?>
                                            <form method="POST" style="display:inline; margin-left:6px;">
                                                <input type="hidden" name="action" value="toggle_absent">
                                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                <input type="hidden" name="which" value="designer1">
                                                <input type="hidden" name="absent_val" value="0">
                                                <button type="submit" class="btn-sm" style="background:#d1fae5; color:#065f46;"
                                                    title="Remove Absent">
                                                    <i class="fas fa-undo"></i> Undo
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($visit['status'] !== 'Done'): ?>
                                            <button type="button" class="btn-sm btn-danger" style="margin-left:auto;" onclick="openAbsentModal(
                    <?= $visit['id'] ?>,
                    'designer1',
                    '<?= addslashes(htmlspecialchars($visit['designer1_name'])) ?>',
                    <?= intval($visit['designer1_id']) ?>,
                    <?= intval($visit['designer2_id'] ?? 0) ?>
                )">
                                                <i class="fas fa-user-slash"></i> Mark Absent
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Designer 2 Row (if assigned) -->
                                <?php if ($visit['designer2_name']): ?>
                                    <div
                                        style="display:flex; align-items:center; gap:12px; margin-top:8px; padding:10px; background:#f9f9f9; border-radius:8px;">
                                        <i class="fas fa-user-tie" style="color:#8a5a44;"></i>
                                        <span
                                            style="font-size:13px; font-weight:600;"><?= htmlspecialchars($visit['designer2_name']) ?></span>
                                        <em style="font-size:11px; color:#9ca3af;">(Designer 2)</em>
                                        <?php if ($visit['designer2_absent']): ?>
                                            <div style="margin-left:auto; text-align:right;">
                                                <span
                                                    style="background:#fee2e2; color:#991b1b; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700;">
                                                    <i class="fas fa-user-slash"></i> Absent
                                                </span>
                                                <?php if ($visit['original_designer2_name']): ?>
                                                    <div
                                                        style="font-size:11px; color:#991b1b; margin-top:4px; display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                                        <i class="fas fa-user"></i>
                                                        Originally:
                                                        <strong><?= htmlspecialchars($visit['original_designer2_name']) ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($visit['designer2_absent_reason']): ?>
                                                    <div style="font-size:11px; color:#991b1b; margin-top:3px; font-style:italic;">
                                                        "<?= htmlspecialchars($visit['designer2_absent_reason']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($visit['status'] !== 'Done'): ?>
                                                <form method="POST" style="display:inline; margin-left:6px;">
                                                    <input type="hidden" name="action" value="toggle_absent">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <input type="hidden" name="which" value="designer2">
                                                    <input type="hidden" name="absent_val" value="0">
                                                    <button type="submit" class="btn-sm" style="background:#d1fae5; color:#065f46;">
                                                        <i class="fas fa-undo"></i> Undo
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($visit['status'] !== 'Done'): ?>
                                                <button type="button" class="btn-sm btn-danger" style="margin-left:auto;" onclick="openAbsentModal(
                    <?= $visit['id'] ?>,
                    'designer2',
                    '<?= addslashes(htmlspecialchars($visit['designer2_name'])) ?>',
                    <?= intval($visit['designer2_id'] ?? 0) ?>,
                    <?= intval($visit['designer1_id']) ?>
                )">
                                                    <i class="fas fa-user-slash"></i> Mark Absent
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($visit['notes']): ?>
                                    <div class="visit-notes" style="margin-top:8px;"><i class="fas fa-sticky-note"></i>
                                        <?= htmlspecialchars($visit['notes']) ?></div>
                                <?php endif; ?>

                                <!-- Designer Reports -->
                                <?php if ($visit['designer1_report'] || $visit['designer1_finished'] || !empty($visit['designer1_photo'])): ?>
                                    <div class="designer-report">
                                        <strong>
                                            <i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer1_name']) ?>'s
                                            Report
                                            <?php if ($visit['designer1_finished']): ?>
                                                <span class="status-badge done" style="float:right;">Finished</span>
                                            <?php elseif (!empty($visit['designer1_photo'])): ?>
                                                <span
                                                    style="background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:20px; font-size:10px; font-weight:700; float:right;">
                                                    <i class="fas fa-camera"></i> Photo Uploaded
                                                </span>
                                            <?php endif; ?>
                                        </strong>
                                        <?php if ($visit['designer1_finished'] && $visit['designer1_finished_at']): ?>
                                            <div
                                                style="font-size:11px; color:#6b7280; margin:5px 0 8px 0; display:flex; align-items:center; gap:5px;">
                                                <i class="fas fa-clock" style="color:#8a5a44;"></i>
                                                Submitted: <?= date('F d, Y g:i A', strtotime($visit['designer1_finished_at'])) ?>
                                                <?php
                                                $d1Date = date('Y-m-d', strtotime($visit['designer1_finished_at']));
                                                $visitDate = $visit['visit_date'];
                                                $visitDeadline = date('Y-m-d', strtotime($visitDate . ' +2 days'));
                                                if ($d1Date > $visitDeadline): ?>
                                                    <span
                                                        style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
                                                        <i class="fas fa-exclamation-circle"></i> Late
                                                    </span>
                                                <?php elseif ($d1Date <= $visitDate): ?>
                                                    <span
                                                        style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
                                                        <i class="fas fa-star"></i> Early
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
                                                        <i class="fas fa-check"></i> On Time
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($visit['designer1_photo'])): ?>
                                            <div style="margin-bottom:10px; margin-top:8px;">
                                                <div
                                                    style="font-size:11px; font-weight:700; color:#065f46; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                                    <i class="fas fa-camera"></i> Proof Photo
                                                </div>
                                                <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer1_photo']) ?>"
                                                    alt="Proof"
                                                    style="max-width:100%; max-height:220px; border-radius:8px; border:2px solid #bbf7d0; object-fit:cover; display:block; cursor:pointer;"
                                                    onclick="openPhotoModal(this.src)">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($visit['designer1_report']): ?>
                                            <?= nl2br(htmlspecialchars($visit['designer1_report'])) ?>
                                        <?php elseif (!$visit['designer1_finished']): ?>
                                            <em style="color:#9ca3af; font-size:12px;">
                                                <i class="fas fa-hourglass-half"></i> Report not submitted yet.
                                            </em>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($visit['designer2_name'] && ($visit['designer2_report'] || $visit['designer2_finished'] || !empty($visit['designer2_photo']))): ?>
                                    <div class="designer-report">
                                        <strong>
                                            <i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer2_name']) ?>'s
                                            Report
                                            <?php if ($visit['designer2_finished']): ?>
                                                <span class="status-badge done" style="float:right;">Finished</span>
                                            <?php elseif (!empty($visit['designer2_photo'])): ?>
                                                <span
                                                    style="background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:20px; font-size:10px; font-weight:700; float:right;">
                                                    <i class="fas fa-camera"></i> Photo Uploaded
                                                </span>
                                            <?php endif; ?>
                                        </strong>
                                        <?php if ($visit['designer2_finished'] && $visit['designer2_finished_at']): ?>
                                            <div
                                                style="font-size:11px; color:#6b7280; margin:5px 0 8px 0; display:flex; align-items:center; gap:5px;">
                                                <i class="fas fa-clock" style="color:#8a5a44;"></i>
                                                Submitted: <?= date('F d, Y g:i A', strtotime($visit['designer2_finished_at'])) ?>
                                                <?php
                                                $d2Date = date('Y-m-d', strtotime($visit['designer2_finished_at']));
                                                if ($d2Date > $visitDeadline): ?>
    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-exclamation-circle"></i> Late
    </span>
<?php elseif ($d2Date <= $visitDate): ?>
    <span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-star"></i> Early
    </span>
<?php else: ?>
    <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-check"></i> On Time
    </span>
<?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($visit['designer2_photo'])): ?>
                                            <div style="margin-bottom:10px; margin-top:8px;">
                                                <div
                                                    style="font-size:11px; font-weight:700; color:#065f46; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                                    <i class="fas fa-camera"></i> Proof Photo
                                                </div>
                                                <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer2_photo']) ?>"
                                                    alt="Proof"
                                                    style="max-width:100%; max-height:220px; border-radius:8px; border:2px solid #bbf7d0; object-fit:cover; display:block; cursor:pointer;"
                                                    onclick="openPhotoModal(this.src)">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($visit['designer2_report']): ?>
                                            <?= nl2br(htmlspecialchars($visit['designer2_report'])) ?>
                                        <?php elseif (!$visit['designer2_finished']): ?>
                                            <em style="color:#9ca3af; font-size:12px;">
                                                <i class="fas fa-hourglass-half"></i> Report not submitted yet.
                                            </em>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Add New Visit Form -->
                <?php if ($stageStatus !== 'Done'): ?>
                    <div class="card">
                        <h2><i class="fas fa-calendar-plus"></i> Add New Site Visit</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_visit">
                            <div class="form-group">
                                <label class="form-label">Designer 1 <span class="required-star">*</span></label>
                                <select name="designer1_id" class="form-control" required onchange="filterD2(this.value)">
                                    <option value="">— Select Designer —</option>
                                    <?php foreach ($designers as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= ($hasAssignedDesigners && $assignedDesigners['designer1_id'] == $d['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($hasAssignedDesigners): ?>
                                    <div
                                        style="font-size: 11px; color: #10b981; margin-top: 5px; display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-info-circle"></i> Auto-filled from client's assigned designer
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Designer 2 <span class="optional-tag">(Optional)</span></label>
                                <select name="designer2_id" id="d2Select" class="form-control">
                                    <option value="">— No 2nd Designer —</option>
                                    <?php foreach ($designers as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= ($hasAssignedDesigners && $assignedDesigners['designer2_id'] == $d['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($hasAssignedDesigners && $assignedDesigners['designer2_id']): ?>
                                    <div
                                        style="font-size: 11px; color: #10b981; margin-top: 5px; display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-info-circle"></i> Auto-filled from client's assigned designer
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Visit Date <span class="required-star">*</span></label>
                                <input type="date" name="visit_date" class="form-control" required
                                    min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Visit Time <span class="optional-tag">(Optional)</span></label>
                                <input type="time" name="visit_time" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Notes <span class="optional-tag">(Optional)</span></label>
                                <textarea name="notes" class="form-control" rows="3"
                                    placeholder="Additional instructions..."></textarea>
                            </div>

                            <!-- Visit Type -->
                            <div class="form-group">
                                <label class="form-label">Visit Type <span class="required-star">*</span></label>
                                <div style="display:flex; gap:12px;">
                                    <label
                                        style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border:2px solid #e9ecef; border-radius:8px; font-size:14px; font-weight:600;">
                                        <input type="radio" name="visit_type" value="Free" checked
                                            onchange="toggleAmount(this.value)">
                                        <i class="fas fa-gift" style="color:#10b981;"></i> Free
                                    </label>
                                    <label
                                        style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border:2px solid #e9ecef; border-radius:8px; font-size:14px; font-weight:600;">
                                        <input type="radio" name="visit_type" value="Paid"
                                            onchange="toggleAmount(this.value)">
                                        <i class="fas fa-money-bill-wave" style="color:#f59e0b;"></i> Paid (Out of NCR)
                                    </label>
                                </div>
                            </div>

                            <!-- Amount (shown only if Paid) -->
                            <div class="form-group" id="amountGroup" style="display:none;">
                                <label class="form-label">Visit Amount <span class="required-star">*</span></label>
                                <div style="position:relative;">
                                    <span
                                        style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:#374151;">₱</span>
                                    <input type="number" name="visit_amount" class="form-control" style="padding-left:28px;"
                                        placeholder="0.00" step="0.01" min="0">
                                </div>
                                <div style="font-size:11px; color:#9ca3af; margin-top:5px;">
                                    <i class="fas fa-info-circle"></i> Set by the lead designer
                                </div>
                            </div>

                            <button type="submit" class="btn-submit">
                                <i class="fas fa-plus-circle"></i> Add Site Visit
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: Designer Workload -->
            <div>
                <div class="card">
                    <h2><i class="fas fa-users"></i> Designer Workload</h2>
                    <?php foreach ($designerWorkloads as $dw): ?>
                        <div class="designer-card">
                            <div>
                                <div class="designer-card-name"><?= htmlspecialchars($dw['full_name']) ?></div>
                                <div class="designer-card-sub">Designer</div>
                            </div>
                            <div class="workload-badges">
                                <span class="wb wb-clients" title="Clients assigned to this designer">
                                    <i class="fas fa-users" style="font-size:10px;"></i>
                                    <?= $dw['assigned_as_designer'] ?>
                                    client<?= $dw['assigned_as_designer'] != 1 ? 's' : '' ?>
                                </span>
                                <?php if ($dw['site_visit_assignments'] > 0): ?>
                                    <span class="wb wb-visits" title="Active site visit assignments">
                                        <i class="fas fa-map-marker-alt" style="font-size:10px;"></i>
                                        <?= $dw['site_visit_assignments'] ?>
                                        visit<?= $dw['site_visit_assignments'] != 1 ? 's' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="wb wb-free">Free</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Absent Reason Modal -->
    <div id="absentModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%;
     background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:white; border-radius:14px; padding:30px; max-width:480px; width:90%;
                box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <h3 style="color:#991b1b; margin-bottom:6px; font-size:18px;">
                <i class="fas fa-user-slash"></i> Mark as Absent
            </h3>
            <p style="font-size:13px; color:#6b7280; margin-bottom:20px;">
                Provide a reason for the absence. You may also assign a replacement designer.
            </p>
            <form method="POST" id="absentForm">
                <input type="hidden" name="action" value="toggle_absent">
                <input type="hidden" name="absent_val" value="1">
                <input type="hidden" name="visit_id" id="absentVisitId">
                <input type="hidden" name="which" id="absentWhich">

                <!-- Absent Designer (read-only display) -->
                <div
                    style="margin-bottom:16px; background:#fff5f5; border:1px solid #fca5a5; border-radius:8px; padding:12px 14px; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-user-tie" style="color:#991b1b;"></i>
                    <div>
                        <div
                            style="font-size:11px; color:#9ca3af; text-transform:uppercase; font-weight:600; letter-spacing:0.4px;">
                            Absent Designer</div>
                        <div style="font-size:14px; font-weight:700; color:#991b1b;" id="absentDesignerName">—</div>
                    </div>
                </div>

                <!-- Reason -->
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#374151;
                              text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">
                        Reason for Absence <span style="color:#ef4444;">*</span>
                    </label>
                    <textarea name="absent_reason" id="absentReason" required style="width:100%; padding:10px; border:2px solid #e9ecef; border-radius:8px;
                                 font-size:13px; resize:vertical; min-height:80px; font-family:inherit;"
                        placeholder="e.g. Sick, Emergency, No show..."></textarea>
                </div>

                <!-- Replacement Designer -->
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#374151;
                              text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">
                        Assign Replacement Designer
                        <span
                            style="font-size:10px; color:#9ca3af; font-weight:400; text-transform:none; margin-left:6px;">(Optional)</span>
                    </label>
                    <select name="replacement_designer_id" id="replacementDesignerSelect" style="width:100%; padding:10px 13px; border:2px solid #e9ecef; border-radius:8px;
                               font-size:13px; color:#111; font-family:inherit;">
                        <option value="">— No Replacement (keep absent) —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                data-name="<?= htmlspecialchars($d['full_name'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($d['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div
                        style="font-size:11px; color:#6b7280; margin-top:6px; display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-info-circle"></i>
                        If selected, this designer will replace the absent one on this visit.
                    </div>
                </div>

                <!-- Replacement Preview -->
                <div id="replacementPreview" style="display:none; background:#dbeafe; border:1px solid #93c5fd;
                 border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:13px;
                 color:#1e40af; display:none; align-items:center; gap:8px;">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Replacing with: <strong id="replacementName"></strong></span>
                </div>

                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeAbsentModal()" style="padding:9px 20px; border:2px solid #e9ecef; border-radius:8px;
                               background:white; font-weight:600; cursor:pointer; font-size:13px; color:#374151;">
                        Cancel
                    </button>
                    <button type="submit" style="padding:9px 20px; background:#ef4444; color:white; border:none;
                               border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;
                               display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-user-slash"></i> Confirm Absent
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit & Resubmit Modal -->
    <div id="editResubmitModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%;
     background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:white; border-radius:14px; padding:30px; max-width:520px; width:90%;
                box-shadow:0 10px 40px rgba(0,0,0,0.25); max-height:90vh; overflow-y:auto;">
            <h3 style="color:#1e40af; margin-bottom:6px; font-size:18px;">
                <i class="fas fa-edit"></i> Edit & Resubmit Visit
            </h3>
            <p style="font-size:13px; color:#6b7280; margin-bottom:20px;">
                Make your adjustments below, then resubmit for approval.
            </p>
            <form method="POST" id="editResubmitForm">
                <input type="hidden" name="action" value="resubmit_visit">
                <input type="hidden" name="visit_id" id="editVisitId">

                <!-- Designer 1 -->
                <div class="form-group">
                    <label class="form-label">Designer 1 <span class="required-star">*</span></label>
                    <select name="designer1_id" id="editD1" class="form-control" required
                        onchange="filterEditD2(this.value)">
                        <option value="">— Select Designer —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Designer 2 -->
                <div class="form-group">
                    <label class="form-label">Designer 2 <span class="optional-tag">(Optional)</span></label>
                    <select name="designer2_id" id="editD2" class="form-control">
                        <option value="">— No 2nd Designer —</option>
                        <?php foreach ($designers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date -->
                <div class="form-group">
                    <label class="form-label">Visit Date <span class="required-star">*</span></label>
                    <input type="date" name="visit_date" id="editDate" class="form-control" required>
                </div>

                <!-- Time -->
                <div class="form-group">
                    <label class="form-label">Visit Time <span class="optional-tag">(Optional)</span></label>
                    <input type="time" name="visit_time" id="editTime" class="form-control">
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Notes <span class="optional-tag">(Optional)</span></label>
                    <textarea name="notes" id="editNotes" class="form-control" rows="3"
                        placeholder="Additional instructions..."></textarea>
                </div>

                <!-- Visit Type -->
                <div class="form-group">
                    <label class="form-label">Visit Type <span class="required-star">*</span></label>
                    <div style="display:flex; gap:12px;">
                        <label
                            style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border:2px solid #e9ecef; border-radius:8px; font-size:14px; font-weight:600;">
                            <input type="radio" name="visit_type" value="Free" id="editTypeFree"
                                onchange="toggleEditAmount(this.value)">
                            <i class="fas fa-gift" style="color:#10b981;"></i> Free
                        </label>
                        <label
                            style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border:2px solid #e9ecef; border-radius:8px; font-size:14px; font-weight:600;">
                            <input type="radio" name="visit_type" value="Paid" id="editTypePaid"
                                onchange="toggleEditAmount(this.value)">
                            <i class="fas fa-money-bill-wave" style="color:#f59e0b;"></i> Paid
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div class="form-group" id="editAmountGroup" style="display:none;">
                    <label class="form-label">Visit Amount <span class="required-star">*</span></label>
                    <div style="position:relative;">
                        <span
                            style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:#374151;">₱</span>
                        <input type="number" name="visit_amount" id="editAmount" class="form-control"
                            style="padding-left:28px;" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>

                <!-- Buttons -->
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                    <button type="button" onclick="closeEditModal()" style="padding:9px 20px; border:2px solid #e9ecef; border-radius:8px;
                               background:white; font-weight:600; cursor:pointer; font-size:13px; color:#374151;">
                        Cancel
                    </button>
                    <button type="submit" style="padding:9px 22px; background:#1e40af; color:white; border:none;
                               border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;
                               display:inline-flex; align-items:center; gap:6px;">
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

        // Auto-run filter on page load if designers are pre-selected
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

            // Reset replacement dropdown
            const repSelect = document.getElementById('replacementDesignerSelect');
            repSelect.value = '';
            document.getElementById('replacementPreview').style.display = 'none';

            // Hide the absent designer AND the other already-assigned designer from options
            // so you can't pick someone already on the visit
            Array.from(repSelect.options).forEach(opt => {
                if (!opt.value) {
                    // Keep the "No Replacement" blank option
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

            const modal = document.getElementById('absentModal');
            modal.style.display = 'flex';
        }

        function closeAbsentModal() {
            document.getElementById('absentModal').style.display = 'none';
        }

        // Show replacement preview when a designer is selected
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

        // Close modal on outside click
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

            const modal = document.getElementById('editResubmitModal');
            modal.style.display = 'flex';
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

        // Close edit modal on outside click
        document.addEventListener('click', function (e) {
            const modal = document.getElementById('editResubmitModal');
            if (e.target === modal) closeEditModal();
        });
    </script>
    <!-- Client Detail Modal -->
    <div id="clientDetailModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%;
     background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:white; padding:30px; border-radius:12px; max-width:600px; width:90%;
                max-height:90vh; overflow-y:auto; position:relative;">

            <!-- Modal Header -->
            <div style="display:flex; justify-content:space-between; align-items:center;
                    margin-bottom:20px; border-bottom:2px solid #f3f4f6; padding-bottom:14px;">
                <h2 style="font-size:20px; font-weight:bold; color:#3b1f0f; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-user-circle" style="color:#8a5a44;"></i> Client Details
                </h2>
                <button onclick="document.getElementById('clientDetailModal').style.display='none'"
                    style="font-size:22px; color:#666; background:none; border:none; cursor:pointer; line-height:1;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Reference Number -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Reference Number:</div>
                <div style="color:#3b82f6; font-family:monospace; font-size:13px; font-weight:600;">
                    <?= htmlspecialchars($client['reference_number']) ?>
                </div>
            </div>

            <!-- Client Name -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Client Name:</div>
                <div style="color:#111; font-size:13px;"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>

            <!-- Project Name -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Project Name:</div>
                <div style="color:#111; font-size:13px;"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>

            <!-- Status -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Status:</div>
                <div>
                    <?php $st = $client['status'] ?? ''; ?>
                    <span style="padding:4px 12px; border-radius:12px; font-size:11px; font-weight:700; text-transform:uppercase;
                    background:<?= $st === 'New Client' ? '#fef3c7' : '#dbeafe' ?>;
                    color:<?= $st === 'New Client' ? '#92400e' : '#1e40af' ?>;">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>

            <!-- Phone -->
            <?php if (!empty($client['contact'])): ?>
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Phone:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($client['contact']) ?></div>
                </div>
            <?php endif; ?>

            <!-- Email -->
            <?php if (!empty($client['email'])): ?>
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Email:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($client['email']) ?></div>
                </div>
            <?php endif; ?>

            <!-- Address -->
            <?php if (!empty($client['address'])): ?>
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Address:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($client['address']) ?></div>
                </div>
            <?php endif; ?>

            <!-- Project Scope -->
            <?php if (!empty($client['project_scope'])): ?>
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Project Scope:</div>
                    <div style="color:#111; font-size:13px;"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Scope of Work -->
            <?php if (!empty($client['scope_of_work'])): ?>
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Scope of Work:</div>
                    <div style="color:#111; font-size:13px;"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- House State -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">House State:</div>
                <div>
                    <?php if ($house_state):
                        $hsBg = '#fef3c7';
                        $hsColor = '#92400e';
                        if ($house_state === 'Bare/Empty Lot') {
                            $hsBg = '#dbeafe';
                            $hsColor = '#1e40af';
                        } elseif ($house_state === 'Construction Started') {
                            $hsBg = '#fee2e2';
                            $hsColor = '#991b1b';
                        } elseif ($house_state === 'Renovation') {
                            $hsBg = '#ede9fe';
                            $hsColor = '#5b21b6';
                        }
                        ?>
                        <span style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700;
                                 background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
                            <?= htmlspecialchars($house_state) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#9ca3af; font-size:13px;">—</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Permit Required -->
            <div
                style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Permit Required:</div>
                <div>
                    <?php if ($permit_required):
                        $prBg = '#fef3c7';
                        $prColor = '#92400e';
                        if ($permit_required === 'Yes') {
                            $prBg = '#fee2e2';
                            $prColor = '#991b1b';
                        } elseif ($permit_required === 'No') {
                            $prBg = '#d1fae5';
                            $prColor = '#065f46';
                        }
                        ?>
                        <span style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700;
                                 background:<?= $prBg ?>; color:<?= $prColor ?>;">
                            <?= htmlspecialchars($permit_required) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#9ca3af; font-size:13px;">—</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Target Move-in Date -->
            <div style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; align-items:start;">
                <div style="font-weight:600; color:#666; font-size:13px;">Target Move-in:</div>
                <div style="color:#111; font-size:13px; font-weight:600;">
                    <?php if ($target_movein_date): ?>
                        <i class="fas fa-calendar-check" style="color:#10b981;"></i>
                        <?= date('F d, Y', strtotime($target_movein_date)) ?>
                    <?php else: ?>
                        <span style="color:#9ca3af;">—</span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Close modal when clicking outside
        document.getElementById('clientDetailModal').addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });
    </script>

    <!-- Photo Lightbox -->
    <div id="photoModal" onclick="closePhotoModal()" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%;
            background:rgba(0,0,0,0.88); align-items:center; justify-content:center; cursor:zoom-out;">
        <img id="photoModalImg" src="" alt="Proof Photo" style="max-width:92vw; max-height:92vh; border-radius:10px; object-fit:contain;
                box-shadow:0 10px 40px rgba(0,0,0,0.5);">
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