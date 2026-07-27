<?php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) { header("Location: ../login.php"); exit(); }

$admin_id     = $_SESSION['admin_id'];
$client_id    = intval($_POST['client_id'] ?? 0);
$redirect_url = trim($_POST['redirect_url'] ?? '');
$fallback     = BASE_URL . 'designer-2d3d-layout?client_id=' . $client_id;

// selections is now an array of {area, unitNum, unitName, reason}
$selections = json_decode($_POST['selections'] ?? '[]', true);

if (empty($selections) || !is_array($selections)) {
    header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("No areas selected."));
    exit();
}

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

// Get current revision number for this client
$curRevStmt = $conn->prepare("SELECT COALESCE(MAX(revision_number), 0) as max_rev FROM layout_revision_log WHERE client_id = ?");
$curRevStmt->bind_param("i", $client_id);
$curRevStmt->execute();
$curRevRow = $curRevStmt->get_result()->fetch_assoc();
$nextRevNumber = intval($curRevRow['max_rev']) + 1;

// Generate a unique batch_id for this submission
$batch_id = uniqid('rev_', true);

foreach ($selections as $sel) {
    $area             = trim($sel['area'] ?? '');
    $room_unit_number = isset($sel['unitNum']) && $sel['unitNum'] !== null ? intval($sel['unitNum']) : null;
    $room_unit_name   = trim($sel['unitName'] ?? '');
    $reason           = trim($sel['reason'] ?? '');

    if (empty($area)) continue;

    // Reset approvals for this area/unit
    if ($room_unit_number !== null) {
        $resetStmt = $conn->prepare("
            UPDATE layout_approvals
            SET status = 'pending',
                comment = NULL,
                responded_at = NULL,
                requested_at = NOW()
            WHERE client_id = ? AND area = ? AND room_unit_number = ?
        ");
        $resetStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $resetStmt = $conn->prepare("
            UPDATE layout_approvals
            SET status = 'pending',
                comment = NULL,
                responded_at = NULL,
                requested_at = NOW()
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
        ");
        $resetStmt->bind_param("is", $client_id, $area);
    }
    $resetStmt->execute();

    // Log with batch_id, revision_number, status = pending
    $logStmt = $conn->prepare("
    INSERT INTO layout_revision_log
        (client_id, area, room_unit_number, room_unit_name, requested_by, reason, status, revision_number, batch_id, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
");

$logStmt->bind_param("isisisii",
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

// Increment revision_count by 1 only (one submission = one revision)
$revStmt = $conn->prepare("
    UPDATE user_info SET revision_count = COALESCE(revision_count, 0) + 1 WHERE id = ?
");
$revStmt->bind_param("i", $client_id);
$revStmt->execute();

// Keep tracker as Ongoing
$trackerStmt = $conn->prepare("
    UPDATE project_tracker SET status = 'Ongoing', updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = '2D / 3D Layout'
");
$trackerStmt->bind_param("ii", $admin_id, $client_id);
$trackerStmt->execute();

header("Location: " . ($redirect_url ?: $fallback) . "&success=" . urlencode("Revision #" . $nextRevNumber . " requested. Approvals have been reset."));
exit();