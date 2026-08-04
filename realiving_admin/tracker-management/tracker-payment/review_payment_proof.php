<?php
//review_payment_proof.php
session_start();
ob_start();
include $includes ['connection'];
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input = json_decode(file_get_contents('php://input'), true);

$payment_id = isset($input['payment_id']) ? intval($input['payment_id']) : 0;
$action = isset($input['action']) ? $input['action'] : ''; // 'approve' or 'reject'
$rejection_note = isset($input['rejection_note']) ? trim($input['rejection_note']) : '';

// Verify role
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userRow = $roleStmt->get_result()->fetch_assoc();
$role = $userRow['role'] ?? '';

$allowedRoles = ['accounting', 'general_manager', 'operational_manager', 'superadmin'];
if (!in_array($role, $allowedRoles)) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

if (!$payment_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

if ($action === 'reject' && $rejection_note === '') {
    echo json_encode(['success' => false, 'error' => 'Rejection note is required']);
    exit();
}

// If approving, signal the frontend to show the NTP upload modal
if ($action === 'approve') {
    // We will handle NTP upload separately via upload_ntp.php
    // But we still need to return a flag so the frontend can show the modal
    // For now, just proceed — the NTP upload is optional but prompted
}

$conn->begin_transaction();
try {
    $reviewStatus = $action === 'approve' ? 'approved' : 'rejected';
    $accountingStatus = $action === 'approve' ? 'approved' : 'rejected';

    // Update review row
    $updReview = $conn->prepare("
    UPDATE payment_accounting_reviews
    SET review_status = ?, reviewed_by = ?, rejection_note = ?, reviewed_at = NOW()
    WHERE payment_id = ? AND review_status = 'pending'
");
    $updReview->bind_param("sisi", $reviewStatus, $admin_id, $rejection_note, $payment_id);
    $updReview->execute();

    // Update payment accounting_status
    $updPay = $conn->prepare("UPDATE payment_schedule SET accounting_status = ? WHERE id = ?");
    $updPay->bind_param("si", $accountingStatus, $payment_id);
    $updPay->execute();

    // If approved → mark payment as Paid (reuse existing logic trigger)
    if ($action === 'approve') {
        $getClient = $conn->prepare("SELECT client_id FROM payment_schedule WHERE id = ?");
        $getClient->bind_param("i", $payment_id);
        $getClient->execute();
        $clientRow = $getClient->get_result()->fetch_assoc();
        $client_id = $clientRow['client_id'];

        $markPaid = $conn->prepare("UPDATE payment_schedule SET status = 'Paid', payment_date = NOW() WHERE id = ?");
        $markPaid->bind_param("i", $payment_id);
        $markPaid->execute();

        // Recalculate remaining balance
        $sumStmt = $conn->prepare("SELECT SUM(amount) as tp FROM payment_schedule WHERE client_id = ? AND status = 'Paid'");
        $sumStmt->bind_param("i", $client_id);
        $sumStmt->execute();
        $tp = $sumStmt->get_result()->fetch_assoc()['tp'] ?? 0;

        $balStmt = $conn->prepare("UPDATE user_info SET remaining_balance = total_project_cost - ? WHERE id = ?");
        $balStmt->bind_param("di", $tp, $client_id);
        $balStmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>