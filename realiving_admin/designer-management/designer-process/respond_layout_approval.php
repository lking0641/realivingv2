<?php
session_start();
include $includes ['connection'];
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input    = json_decode(file_get_contents('php://input'), true);

$client_id       = intval($input['client_id'] ?? 0);
$area            = trim($input['area'] ?? '');
$room_unit_number = isset($input['room_unit_number']) && $input['room_unit_number'] !== null
                    ? intval($input['room_unit_number']) : null;
$approver_id     = intval($input['approver_id'] ?? 0);
$status          = trim($input['status'] ?? '');
$comment         = trim($input['comment'] ?? '');

// Validate
if (!in_array($status, ['approved', 'rejected'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit();
}

// Must be the approver themselves
if ($approver_id != $admin_id) {
    echo json_encode(['success' => false, 'error' => 'You can only respond as yourself']);
    exit();
}

// Rejection requires comment
if ($status === 'rejected' && empty($comment)) {
    echo json_encode(['success' => false, 'error' => 'Comment required for rejection']);
    exit();
}

// Verify this approver is valid
$aprStmt = $conn->prepare("
    SELECT id FROM account
    WHERE id = ? AND (
        role IN ('general_manager','operational_manager')
        OR (role IN ('designer','technical_designer') AND is_head = 1)
    )
");
$aprStmt->bind_param("i", $approver_id);
$aprStmt->execute();
if (!$aprStmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'error' => 'Not a valid approver']);
    exit();
}

// Update the record
if ($room_unit_number !== null) {
    $updStmt = $conn->prepare("
        UPDATE layout_approvals
        SET status = ?, comment = ?, responded_at = NOW()
        WHERE client_id = ? AND area = ? AND room_unit_number = ? AND approver_id = ?
    ");
    $updStmt->bind_param("ssisii", $status, $comment, $client_id, $area, $room_unit_number, $approver_id);
} else {
    $updStmt = $conn->prepare("
        UPDATE layout_approvals
        SET status = ?, comment = ?, responded_at = NOW()
        WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND approver_id = ?
    ");
    $updStmt->bind_param("ssisi", $status, $comment, $client_id, $area, $approver_id);
}

if ($updStmt->execute()) {

    // Check if all approvers have now approved this area/unit
    $reqStmt = $conn->prepare("
        SELECT COUNT(*) FROM account
        WHERE (role IN ('general_manager','operational_manager'))
           OR (role IN ('designer','technical_designer') AND is_head = 1)
    ");
    $reqStmt->execute();
    $totalRequired = (int)$reqStmt->get_result()->fetch_row()[0];

    if ($room_unit_number !== null) {
        $doneStmt = $conn->prepare("
            SELECT COUNT(*) FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number = ? AND status = 'approved'
        ");
        $doneStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $doneStmt = $conn->prepare("
            SELECT COUNT(*) FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND status = 'approved'
        ");
        $doneStmt->bind_param("is", $client_id, $area);
    }
    $doneStmt->execute();
    $doneCount = (int)$doneStmt->get_result()->fetch_row()[0];

    // If all approved → mark the revision log entry as approved
// (covers both 'pending' and 'designer_resubmitted' states)
if ($doneCount >= $totalRequired) {
    if ($room_unit_number !== null) {
        $markRevStmt = $conn->prepare("
            UPDATE layout_revision_log SET status = 'approved'
            WHERE client_id = ? AND area = ? AND room_unit_number = ?
            AND status IN ('pending', 'designer_resubmitted')
        ");
        $markRevStmt->bind_param("isi", $client_id, $area, $room_unit_number);
    } else {
        $markRevStmt = $conn->prepare("
            UPDATE layout_revision_log SET status = 'approved'
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
            AND status IN ('pending', 'designer_resubmitted')
        ");
        $markRevStmt->bind_param("is", $client_id, $area);
    }
    $markRevStmt->execute();
}
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'DB update failed']);
}
exit();