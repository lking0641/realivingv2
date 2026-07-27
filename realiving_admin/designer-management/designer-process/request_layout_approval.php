<?php
//request_layout_approval.php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$client_id = intval($_POST['client_id'] ?? 0);
$area = trim($_POST['area'] ?? '');
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
    ? intval($_POST['room_unit_number']) : null;
$room_unit_name = trim($_POST['room_unit_name'] ?? '');
$redirect_url = trim($_POST['redirect_url'] ?? '');
$resubmit = isset($_POST['resubmit']) && $_POST['resubmit'] == '1';

$fallback = BASE_URL . 'designer-attachments?client_id=' . $client_id;

// Verify role
$meStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
if (!in_array($me['role'], ['designer', 'technical_designer'])) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Verify assignment
$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientRow = $assignStmt->get_result()->fetch_assoc();
if (!$clientRow || ($clientRow['designer1_id'] != $admin_id && $clientRow['designer2_id'] != $admin_id)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Get the 4 approvers
$approverStmt = $conn->prepare("
    SELECT id FROM account
    WHERE (role IN ('general_manager','operational_manager'))
       OR (role IN ('designer','technical_designer') AND is_head = 1)
");
$approverStmt->execute();
$approvers = $approverStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($approvers)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("No approvers found in the system."));
    exit();
}

if ($resubmit) {
    // ── Fetch old PDF file path before resetting, so we can delete it ──
    if ($room_unit_number !== null) {
        $oldFileStmt = $conn->prepare("
            SELECT td_remark_file FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number = ?
            LIMIT 1
        ");
        $oldFileStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $oldFileStmt = $conn->prepare("
            SELECT td_remark_file FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
            LIMIT 1
        ");
        $oldFileStmt->bind_param("is", $client_id, $area);
    }
    $oldFileStmt->execute();
    $oldFileRow = $oldFileStmt->get_result()->fetch_assoc();
    $oldPdfPath = $oldFileRow['td_remark_file'] ?? null;

    // ── Step 1: Reset ONLY rejected approvals back to pending ────────
    foreach ($approvers as $apr) {
        $conn->query("
            UPDATE layout_approvals
            SET status = 'pending',
                comment = NULL,
                responded_at = NULL,
                requested_at = NOW()
            WHERE client_id = {$client_id}
              AND area = '" . $conn->real_escape_string($area) . "'
              AND " . ($room_unit_number !== null ? "room_unit_number = {$room_unit_number}" : "room_unit_number IS NULL") . "
              AND approver_id = {$apr['id']}
              AND status = 'rejected'
        ");
    }

    // ── Step 2: Clear TD remark on ALL rows for this area/unit ───────
    // (td_remark lives on every approver row — we must wipe all of them)
    $areaEsc = $conn->real_escape_string($area);
    $unitCond = ($room_unit_number !== null)
        ? "room_unit_number = {$room_unit_number}"
        : "room_unit_number IS NULL";

    $conn->query("
        UPDATE layout_approvals
        SET td_remark = NULL,
            td_remark_submitted_at = NULL,
            td_remark_file = NULL,
            td_remark_file_name = NULL
        WHERE client_id = {$client_id}
          AND area = '{$areaEsc}'
          AND {$unitCond}
    ");

    // ── Step 3: Delete old PDF file from disk ────────────────────────
    if ($oldPdfPath) {
        $fullPath = ROOT_PATH . $oldPdfPath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }

    header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Re-approval requested. TD remark has been reset for fresh review."));
    exit();
}

// ── Fetch old PDF before fresh insert (in case of re-request on existing rows) ──
if ($room_unit_number !== null) {
    $oldFileStmt2 = $conn->prepare("
        SELECT td_remark_file FROM layout_approvals
        WHERE client_id = ? AND area = ? AND room_unit_number = ?
        LIMIT 1
    ");
    $oldFileStmt2->bind_param("isi", $client_id, $area, $room_unit_number);
} else {
    $oldFileStmt2 = $conn->prepare("
        SELECT td_remark_file FROM layout_approvals
        WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
        LIMIT 1
    ");
    $oldFileStmt2->bind_param("is", $client_id, $area);
}
$oldFileStmt2->execute();
$oldFileRow2 = $oldFileStmt2->get_result()->fetch_assoc();
$oldPdfPath2 = $oldFileRow2['td_remark_file'] ?? null;

// ── Delete old PDF if exists ──────────────────────────────────────────────
if ($oldPdfPath2) {
    $fullPath2 = ROOT_PATH . $oldPdfPath2;
    if (file_exists($fullPath2)) {
        @unlink($fullPath2);
    }
}

// Fresh request — insert for all approvers
foreach ($approvers as $apr) {
    if ($room_unit_number !== null) {
        $insStmt = $conn->prepare("
            INSERT INTO layout_approvals
                (client_id, area, room_unit_number, approver_id, status, requested_at,
                 td_remark, td_remark_submitted_at, td_remark_file, td_remark_file_name)
            VALUES (?, ?, ?, ?, 'pending', NOW(), NULL, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                status = 'pending', comment = NULL, responded_at = NULL,
                requested_at = NOW(), td_remark = NULL,
                td_remark_submitted_at = NULL, td_remark_file = NULL,
                td_remark_file_name = NULL
        ");
        $insStmt->bind_param("isii", $client_id, $area, $room_unit_number, $apr['id']);
        $insStmt->execute();
    } else {
        $chkStmt = $conn->prepare("
            SELECT id FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND approver_id = ?
        ");
        $chkStmt->bind_param("isi", $client_id, $area, $apr['id']);
        $chkStmt->execute();
        $existing = $chkStmt->get_result()->fetch_assoc();

        if ($existing) {
            $updStmt = $conn->prepare("
                UPDATE layout_approvals
                SET status = 'pending', comment = NULL, responded_at = NULL,
                    requested_at = NOW(), td_remark = NULL,
                    td_remark_submitted_at = NULL, td_remark_file = NULL,
                    td_remark_file_name = NULL
                WHERE id = ?
            ");
            $updStmt->bind_param("i", $existing['id']);
            $updStmt->execute();
        } else {
            $insStmt = $conn->prepare("
                INSERT INTO layout_approvals
                    (client_id, area, room_unit_number, approver_id, status, requested_at,
                     td_remark, td_remark_submitted_at, td_remark_file, td_remark_file_name)
                VALUES (?, ?, NULL, ?, 'pending', NOW(), NULL, NULL, NULL, NULL)
            ");
            $insStmt->bind_param("isi", $client_id, $area, $apr['id']);
            $insStmt->execute();
        }
    }
}

// Auto-set 2D / 3D Layout stage to Ongoing on first approval request
$trackerUpd = $conn->prepare("
    UPDATE project_tracker
    SET status = 'Ongoing', updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = '2D / 3D Layout' AND status = 'Pending'
");
$trackerUpd->bind_param("ii", $admin_id, $client_id);
$trackerUpd->execute();

// If there is a pending revision log entry for this area/unit,
// mark it as designer_resubmitted so the banner disappears
// and approvers can now approve again
if ($room_unit_number !== null) {
    $revUpdStmt = $conn->prepare("
        UPDATE layout_revision_log
        SET status = 'designer_resubmitted'
        WHERE client_id = ? AND area = ? AND room_unit_number = ? AND status = 'pending'
    ");
    $revUpdStmt->bind_param("isi", $client_id, $area, $room_unit_number);
} else {
    $revUpdStmt = $conn->prepare("
        UPDATE layout_revision_log
        SET status = 'designer_resubmitted'
        WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND status = 'pending'
    ");
    $revUpdStmt->bind_param("is", $client_id, $area);
}
$revUpdStmt->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Revised design submitted. Approval requested from all reviewers."));
exit();