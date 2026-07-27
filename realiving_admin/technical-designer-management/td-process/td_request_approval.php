<?php
// td_request_approval.php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) { header("Location: ../login.php"); exit(); }

$admin_id         = $_SESSION['admin_id'];
$client_id        = intval($_POST['client_id'] ?? 0);
$area             = trim($_POST['area'] ?? '');
$room_unit_number = (isset($_POST['room_unit_number']) && $_POST['room_unit_number'] !== '')
                    ? intval($_POST['room_unit_number']) : null;
$room_unit_name   = trim($_POST['room_unit_name'] ?? '');
$redirect_url     = trim($_POST['redirect_url'] ?? '');
$resubmit         = isset($_POST['resubmit']) && $_POST['resubmit'] == '1';

$fallback = 'td_attachments.php?client_id=' . $client_id;

// Verify role — must be technical_designer
$meStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
if ($me['role'] !== 'technical_designer') {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Verify assignment — must be the assigned technical_designer for this client
$assignStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientRow = $assignStmt->get_result()->fetch_assoc();
if (!$clientRow || $clientRow['technical_designer_id'] != $admin_id) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Get the 4 approvers (GM, OM, designer head, TD head)
$approverStmt = $conn->prepare("
    SELECT id FROM account
    WHERE (role IN ('general_manager','operational_manager'))
       OR (role = 'technical_designer' AND is_head = 1)
");
$approverStmt->execute();
$approvers = $approverStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($approvers)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("No approvers found in the system."));
    exit();
}

if ($resubmit) {
    // Re-request: only reset rejected approvals back to pending
    foreach ($approvers as $apr) {
        $conn->query("
            UPDATE td_attachment_approvals
            SET status = 'pending', comment = NULL, responded_at = NULL, requested_at = NOW()
            WHERE client_id = {$client_id}
              AND area = '" . $conn->real_escape_string($area) . "'
              AND " . ($room_unit_number !== null ? "room_unit_number = {$room_unit_number}" : "room_unit_number IS NULL") . "
              AND approver_id = {$apr['id']}
              AND status = 'rejected'
        ");
    }
    header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Re-approval requested for rejected approvers."));
    exit();
}

// Fresh request — insert or update approval records for all approvers
foreach ($approvers as $apr) {
    if ($room_unit_number !== null) {
        // Has unit — ON DUPLICATE KEY works fine (no NULL in unique key)
        $insStmt = $conn->prepare("
            INSERT INTO td_attachment_approvals
                (client_id, area, room_unit_number, approver_id, status, requested_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE status = 'pending', comment = NULL,
                responded_at = NULL, requested_at = NOW()
        ");
        $insStmt->bind_param("isii", $client_id, $area, $room_unit_number, $apr['id']);
        $insStmt->execute();
    } else {
        // No unit — NULL breaks ON DUPLICATE KEY, so check manually
        $chkStmt = $conn->prepare("
            SELECT id FROM td_attachment_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND approver_id = ?
        ");
        $chkStmt->bind_param("isi", $client_id, $area, $apr['id']);
        $chkStmt->execute();
        $existing = $chkStmt->get_result()->fetch_assoc();

        if ($existing) {
            $updStmt = $conn->prepare("
                UPDATE td_attachment_approvals
                SET status = 'pending', comment = NULL, responded_at = NULL, requested_at = NOW()
                WHERE id = ?
            ");
            $updStmt->bind_param("i", $existing['id']);
            $updStmt->execute();
        } else {
            $insStmt = $conn->prepare("
                INSERT INTO td_attachment_approvals
                    (client_id, area, room_unit_number, approver_id, status, requested_at)
                VALUES (?, ?, NULL, ?, 'pending', NOW())
            ");
            $insStmt->bind_param("isi", $client_id, $area, $apr['id']);
            $insStmt->execute();
        }
    }
}

// If there is a pending revision log entry for this area/unit,
// mark it as designer_resubmitted so approvers can now act again
if ($room_unit_number !== null) {
    $revUpdStmt = $conn->prepare("
        UPDATE td_revision_log
        SET status = 'designer_resubmitted'
        WHERE client_id = ? AND area = ? AND room_unit_number = ? AND status = 'pending'
    ");
    $revUpdStmt->bind_param("isi", $client_id, $area, $room_unit_number);
} else {
    $revUpdStmt = $conn->prepare("
        UPDATE td_revision_log
        SET status = 'designer_resubmitted'
        WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND status = 'pending'
    ");
    $revUpdStmt->bind_param("is", $client_id, $area);
}
$revUpdStmt->execute();

// Auto-set Technical Design tracker stage to Ongoing on first approval request
$trackerUpd = $conn->prepare("
    UPDATE project_tracker
    SET status = 'Ongoing', updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = 'Technical Design' AND status = 'Pending'
");
$trackerUpd->bind_param("ii", $admin_id, $client_id);
$trackerUpd->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Approval requested from all reviewers."));
exit();