<?php
// update_payment_status.php
session_start();

// Buffer output to prevent any accidental HTML from includes breaking JSON
ob_start();

include $includes ['connection'];

// Clear any output from includes, then set JSON header
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

$input = json_decode(file_get_contents('php://input'), true);
$payment_id = isset($input['payment_id']) ? intval($input['payment_id']) : 0;
$status = isset($input['status']) ? $input['status'] : '';
$client_id = isset($input['client_id']) ? intval($input['client_id']) : 0;

if ($status !== 'Paid') {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit();
}

// Get payment details
$paymentStmt = $conn->prepare("
    SELECT ps.*, ui.accountaid_fk, a.role
    FROM payment_schedule ps
    JOIN user_info ui ON ps.client_id = ui.id
    LEFT JOIN account a ON a.id = ?
    WHERE ps.id = ?
");
$paymentStmt->bind_param("ii", $admin_id, $payment_id);
$paymentStmt->execute();
$payment = $paymentStmt->get_result()->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'error' => 'Payment not found']);
    exit();
}

// Check access
$admin_role = $payment['role'];
$allowedRoles = ['general_manager', 'operational_manager', 'accounting', 'superadmin'];
$hasAccess = ($payment['accountaid_fk'] == $admin_id) || in_array($admin_role, $allowedRoles);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get payment type and quotation_entry_id to check if it's downpayment
    $paymentCheckStmt = $conn->prepare("
    SELECT payment_type 
    FROM payment_schedule 
    WHERE id = ?
");
$paymentCheckStmt->bind_param("i", $payment_id);
$paymentCheckStmt->execute();
$paymentInfo = $paymentCheckStmt->get_result()->fetch_assoc();
$paymentType = $paymentInfo['payment_type'];
$isDownpayment        = strpos($paymentType, 'Down Payment') !== false;
$isBeforeInstallation = strpos($paymentType, '40% Before Installation') !== false;
$isAfterInstallation  = strpos($paymentType, '10% After Installation') !== false;
$isCollectionBilling  = strpos($paymentType, 'Collection Billing') !== false;
    
    // Update payment status
    $updateStmt = $conn->prepare("
        UPDATE payment_schedule 
        SET status = 'Paid', payment_date = NOW()
        WHERE id = ?
    ");
    $updateStmt->bind_param("i", $payment_id);
    $updateStmt->execute();

    if ($updateStmt->affected_rows === 0) {
        throw new Exception('Payment not found or already updated');
    }

    // If this is downpayment, update project_tracker Downpayment stage
    if ($isDownpayment) {
        $updateTrackerStmt = $conn->prepare("
            UPDATE project_tracker 
            SET status = 'Done',
                updated_by = ?,
                updated_at = NOW()
            WHERE client_id = ? AND stage_name = 'Downpayment'
        ");
        $updateTrackerStmt->bind_param("ii", $admin_id, $client_id);
        $updateTrackerStmt->execute();
    }

    // Get client's business type — use client_id from payment row, not from input
$client_id = intval($payment['client_id']);
$businessTypeStmt = $conn->prepare("
    SELECT business_type FROM user_info WHERE id = ?
");
    $businessTypeStmt->bind_param("i", $client_id);
    $businessTypeStmt->execute();
    $businessType = $businessTypeStmt->get_result()->fetch_assoc()['business_type'];

    // For Non-Project: Check if all payments are paid, then mark all items as billing done
    if ($businessType === 'Non-Project') {
        // Check if all payments for this client are paid
        $allPaidCheckStmt = $conn->prepare("
            SELECT COUNT(*) as total_payments,
                   SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_payments
            FROM payment_schedule
            WHERE client_id = ? AND status != 'Not Available'
        ");
        $allPaidCheckStmt->bind_param("i", $client_id);
        $allPaidCheckStmt->execute();
        $paymentCounts = $allPaidCheckStmt->get_result()->fetch_assoc();
        
        // If all payments are paid, mark all quotation entries as billing done
        if ($paymentCounts['total_payments'] == $paymentCounts['paid_payments'] && $paymentCounts['total_payments'] > 0) {
            $updateAllBillingStmt = $conn->prepare("
                UPDATE quotation_entries 
                SET billing_status = 'Done',
                    billing_updated_at = NOW()
                WHERE client_id = ?
            ");
            $updateAllBillingStmt->bind_param("i", $client_id);
            $updateAllBillingStmt->execute();
        }
    }

    // Recalculate remaining balance
    $sumStmt = $conn->prepare("
        SELECT SUM(amount) as total_paid
        FROM payment_schedule
        WHERE client_id = ? AND status = 'Paid'
    ");
    $sumStmt->bind_param("i", $client_id);
    $sumStmt->execute();
    $sumResult = $sumStmt->get_result()->fetch_assoc();
    $total_paid = $sumResult['total_paid'] ?? 0;
    
    // Update remaining balance
    $balanceStmt = $conn->prepare("
        UPDATE user_info 
        SET remaining_balance = total_project_cost - ?
        WHERE id = ?
    ");
    $balanceStmt->bind_param("di", $total_paid, $client_id);
    $balanceStmt->execute();

    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>