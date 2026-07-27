<?php
// td_request_revision.php
session_start();
include $connection ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id     = $_SESSION['admin_id'];
$client_id    = intval($_POST['client_id'] ?? 0);
$redirect_url = trim($_POST['redirect_url'] ?? '');
$fallback     = 'td_layout.php?client_id=' . $client_id;

// selections is an array of {area, unitNum, unitName, reason}
$selections = json_decode($_POST['selections'] ?? '[]', true);

if (empty($selections) || !is_array($selections)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("No areas selected."));
    exit();
}

// Verify role — must be technical_designer
$meStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
if ($me['role'] !== 'technical_designer') {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Verify assignment — must be the assigned technical_designer
$assignStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientRow = $assignStmt->get_result()->fetch_assoc();
if (!$clientRow || $clientRow['technical_designer_id'] != $admin_id) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
    exit();
}

// Get current max revision number for this client
$curRevStmt = $conn->prepare("SELECT COALESCE(MAX(revision_number), 0) as max_rev FROM td_revision_log WHERE client_id = ?");
$curRevStmt->bind_param("i", $client_id);
$curRevStmt->execute();
$curRevRow     = $curRevStmt->get_result()->fetch_assoc();
$nextRevNumber = intval($curRevRow['max_rev']) + 1;

// Generate a unique batch_id for this submission
$batch_id = uniqid('tdrev_', true);

foreach ($selections as $sel) {
    $area             = trim($sel['area'] ?? '');
    $room_unit_number = isset($sel['unitNum']) && $sel['unitNum'] !== null ? intval($sel['unitNum']) : null;
    $room_unit_name   = trim($sel['unitName'] ?? '');
    $reason           = trim($sel['reason'] ?? '');

    if (empty($area)) continue;

    // Reset td_attachment_approvals for this area/unit back to pending
    if ($room_unit_number !== null) {
        $resetStmt = $conn->prepare("
            UPDATE td_attachment_approvals
            SET status = 'pending',
                comment = NULL,
                responded_at = NULL,
                requested_at = NOW()
            WHERE client_id = ? AND area = ? AND room_unit_number = ?
        ");
        $resetStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $resetStmt = $conn->prepare("
            UPDATE td_attachment_approvals
            SET status = 'pending',
                comment = NULL,
                responded_at = NULL,
                requested_at = NOW()
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
        ");
        $resetStmt->bind_param("is", $client_id, $area);
    }
    $resetStmt->execute();

    // Log into td_revision_log
    $logStmt = $conn->prepare("
        INSERT INTO td_revision_log
            (client_id, area, room_unit_number, room_unit_name, requested_by, reason, status, revision_number, batch_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
    ");
    $logStmt->bind_param("isisisss",
        $client_id,
        $area,
        $room_unit_number,
        $room_unit_name,
        $admin_id,
        $reason,
        $nextRevNumber,
        $batch_id
    );
    $logStmt->execute();
}

// Keep tracker stage as Ongoing
$trackerStmt = $conn->prepare("
    UPDATE project_tracker SET status = 'Ongoing', updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = 'Technical Design'
");
$trackerStmt->bind_param("ii", $admin_id, $client_id);
$trackerStmt->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Revision #" . $nextRevNumber . " requested. Approvals have been reset."));
exit();