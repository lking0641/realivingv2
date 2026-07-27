<?php
// tracker_step/internal_po_review.php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$client_id = intval($input['client_id'] ?? 0);
$stage_id = intval($input['stage_id'] ?? 0);

$roleStmt = $conn->prepare("SELECT role, is_head, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];
$is_head = (bool) ($userInfo['is_head'] ?? false);
$full_name = $userInfo['full_name'];

if ($action === 'request_approval') {
    // Check no existing pending approval
    $chk = $conn->prepare("SELECT id FROM internal_po_approvals WHERE stage_id = ? AND overall_status = 'pending'");
    $chk->bind_param("i", $stage_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Approval already requested.']);
        exit();
    }

    // Check there is at least one file uploaded
    $fcStmt = $conn->prepare("SELECT COUNT(*) FROM stage_approvals WHERE stage_id = ?");
    $fcStmt->bind_param("i", $stage_id);
    $fcStmt->execute();
    $fileCount = (int) $fcStmt->get_result()->fetch_row()[0];
    if ($fileCount === 0) {
        echo json_encode(['success' => false, 'error' => 'Please upload at least one file before requesting approval.']);
        exit();
    }

    $ins = $conn->prepare("INSERT INTO internal_po_approvals (client_id, stage_id, requested_by, requested_at, overall_status, accounting_status, designer_status) VALUES (?, ?, ?, NOW(), 'pending', 'pending', 'pending')");
    $ins->bind_param("iii", $client_id, $stage_id, $admin_id);
    if ($ins->execute()) {
        // Set stage to Ongoing
        $upd = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW(), updated_by = ? WHERE id = ?");
        $upd->bind_param("ii", $admin_id, $stage_id);
        $upd->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'DB error.']);
    }
    exit();
}

if ($action === 'approve' || $action === 'reject') {
    $approval_id = intval($input['approval_id'] ?? 0);
    $remark = trim($input['remark'] ?? '');

    if ($action === 'reject' && empty($remark)) {
        echo json_encode(['success' => false, 'error' => 'A remark is required when rejecting.']);
        exit();
    }

    $apStmt = $conn->prepare("SELECT * FROM internal_po_approvals WHERE id = ? AND stage_id = ?");
    $apStmt->bind_param("ii", $approval_id, $stage_id);
    $apStmt->execute();
    $ap = $apStmt->get_result()->fetch_assoc();
    if (!$ap) {
        echo json_encode(['success' => false, 'error' => 'Approval record not found.']);
        exit();
    }

    $status = $action === 'approve' ? 'approved' : 'rejected';

    // Accounting reviews first
    if ($admin_role === 'accounting' && $ap['accounting_status'] === 'pending') {
        $upd = $conn->prepare("UPDATE internal_po_approvals SET accounting_status=?, accounting_reviewed_by=?, accounting_reviewed_at=NOW(), accounting_remark=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param("sisi", $status, $admin_id, $remark, $approval_id);
        $upd->execute();

        if ($action === 'reject') {
            // Mark overall as rejected
            $conn->query("UPDATE internal_po_approvals SET overall_status='rejected' WHERE id=$approval_id");

            // Mark all files as rejected
            $rejectFilesStmt = $conn->prepare("UPDATE stage_approvals SET approval_status = 'rejected' WHERE stage_id = ?");
            $rejectFilesStmt->bind_param("i", $stage_id);
            $rejectFilesStmt->execute();
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // Designer head reviews second (only after accounting approved)
    if ($admin_role === 'designer' && $is_head && $ap['accounting_status'] === 'approved' && $ap['designer_status'] === 'pending') {
        $upd = $conn->prepare("UPDATE internal_po_approvals SET designer_status=?, designer_reviewed_by=?, designer_reviewed_at=NOW(), designer_remark=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param("sisi", $status, $admin_id, $remark, $approval_id);
        $upd->execute();

        if ($action === 'approve') {
            // Both approved — mark overall approved
            $conn->query("UPDATE internal_po_approvals SET overall_status='approved' WHERE id=$approval_id");

            // Also mark all files in this stage as approved
            $approveFilesStmt = $conn->prepare("UPDATE stage_approvals SET approval_status = 'approved' WHERE stage_id = ?");
            $approveFilesStmt->bind_param("i", $stage_id);
            $approveFilesStmt->execute();

        } else {
            $conn->query("UPDATE internal_po_approvals SET overall_status='rejected' WHERE id=$approval_id");

            // Mark all files as rejected
            $rejectFilesStmt = $conn->prepare("UPDATE stage_approvals SET approval_status = 'rejected' WHERE stage_id = ?");
            $rejectFilesStmt->bind_param("i", $stage_id);
            $rejectFilesStmt->execute();
        }
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'You are not authorized to review at this step.']);
    exit();
}

if ($action === 'reset') {
    // Uploader resets a rejected approval to re-request
    $approval_id = intval($input['approval_id'] ?? 0);
    $del = $conn->prepare("DELETE FROM internal_po_approvals WHERE id = ? AND stage_id = ? AND overall_status = 'rejected'");
    $del->bind_param("ii", $approval_id, $stage_id);
    $del->execute();
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);