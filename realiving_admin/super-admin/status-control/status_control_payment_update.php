<?php
// status_control_payment_update.php
session_start();
header('Content-Type: application/json');
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Enforce super_admin only
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$adminRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? '';

if ($adminRole !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$payment_id = isset($data['payment_id']) ? intval($data['payment_id']) : 0;
$action = isset($data['action']) ? trim($data['action']) : '';

if (!$payment_id || !in_array($action, ['set_status', 'reset_proof', 'reset_ntp'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Confirm payment exists
$checkStmt = $conn->prepare("SELECT * FROM payment_schedule WHERE id = ?");
$checkStmt->bind_param("i", $payment_id);
$checkStmt->execute();
$payment = $checkStmt->get_result()->fetch_assoc();
if (!$payment) {
    echo json_encode(['success' => false, 'error' => 'Payment not found']);
    exit();
}

$client_id = intval($payment['client_id']);
$isDownpayment = strpos($payment['payment_type'], 'Down Payment') !== false;

// ── Helper: recompute remaining_balance for the client ──
function recalcRemainingBalance($conn, $client_id)
{
    $sumStmt = $conn->prepare("SELECT SUM(amount) as total_paid FROM payment_schedule WHERE client_id = ? AND status = 'Paid'");
    $sumStmt->bind_param("i", $client_id);
    $sumStmt->execute();
    $total_paid = $sumStmt->get_result()->fetch_assoc()['total_paid'] ?? 0;

    $balStmt = $conn->prepare("UPDATE user_info SET remaining_balance = total_project_cost - ? WHERE id = ?");
    $balStmt->bind_param("di", $total_paid, $client_id);
    $balStmt->execute();
}

if ($action === 'set_status') {
    $status = isset($data['status']) ? trim($data['status']) : '';
    if (!in_array($status, ['Pending', 'Paid', 'Not Available'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit();
    }

    if ($status === 'Paid') {
        $upd = $conn->prepare("UPDATE payment_schedule SET status = ?, payment_date = NOW() WHERE id = ?");
    } else {
        $upd = $conn->prepare("UPDATE payment_schedule SET status = ?, payment_date = NULL WHERE id = ?");
    }
    $upd->bind_param("si", $status, $payment_id);
    $upd->execute();

    // Keep Downpayment stage in project_tracker in sync
    if ($isDownpayment) {
        $trackerStatus = ($status === 'Paid') ? 'Done' : 'Ongoing';
        $updTracker = $conn->prepare("
            UPDATE project_tracker
            SET status = ?, updated_by = ?, updated_at = NOW()
            WHERE client_id = ? AND stage_name = 'Downpayment'
        ");
        $updTracker->bind_param("sii", $trackerStatus, $admin_id, $client_id);
        $updTracker->execute();
    }

    recalcRemainingBalance($conn, $client_id);

    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'reset_proof') {
    // Fetch proof record
    $proofStmt = $conn->prepare("SELECT * FROM payment_proofs WHERE payment_id = ? ORDER BY id DESC LIMIT 1");
    $proofStmt->bind_param("i", $payment_id);
    $proofStmt->execute();
    $proof = $proofStmt->get_result()->fetch_assoc();

    if ($proof) {
        if (!empty($proof['file_path']) && file_exists(ROOT_PATH . $proof['file_path'])) {
            unlink(ROOT_PATH . $proof['file_path']);
        }

        $delReview = $conn->prepare("DELETE FROM payment_accounting_reviews WHERE payment_id = ?");
        $delReview->bind_param("i", $payment_id);
        $delReview->execute();

        $delProof = $conn->prepare("DELETE FROM payment_proofs WHERE payment_id = ?");
        $delProof->bind_param("i", $payment_id);
        $delProof->execute();
    }

    // Revert payment_schedule back to original unpaid state
    $revert = $conn->prepare("
        UPDATE payment_schedule
        SET status = 'Pending', payment_date = NULL, accounting_status = NULL
        WHERE id = ?
    ");
    $revert->bind_param("i", $payment_id);
    $revert->execute();

    // Revert Downpayment stage back to Ongoing if it was Done
    if ($isDownpayment) {
        $revertTracker = $conn->prepare("
            UPDATE project_tracker
            SET status = 'Ongoing', updated_by = ?, updated_at = NOW()
            WHERE client_id = ? AND stage_name = 'Downpayment' AND status = 'Done'
        ");
        $revertTracker->bind_param("ii", $admin_id, $client_id);
        $revertTracker->execute();
    }

    recalcRemainingBalance($conn, $client_id);

    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'reset_ntp') {
    $ntpStmt = $conn->prepare("SELECT * FROM notice_to_proceed WHERE payment_id = ? ORDER BY id DESC LIMIT 1");
    $ntpStmt->bind_param("i", $payment_id);
    $ntpStmt->execute();
    $ntp = $ntpStmt->get_result()->fetch_assoc();

    if ($ntp) {
        if (!empty($ntp['file_path']) && file_exists(ROOT_PATH . $ntp['file_path'])) {
            unlink(ROOT_PATH . $ntp['file_path']);
        }
        $delNtp = $conn->prepare("DELETE FROM notice_to_proceed WHERE payment_id = ?");
        $delNtp->bind_param("i", $payment_id);
        $delNtp->execute();
    }

    echo json_encode(['success' => true]);
    exit();
}