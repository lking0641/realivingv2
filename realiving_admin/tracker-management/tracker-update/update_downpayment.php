<?php
// update_downpayment.php
session_start();
header('Content-Type: application/json');

include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

$input = json_decode(file_get_contents('php://input'), true);
$client_id = isset($input['client_id']) ? intval($input['client_id']) : 0;
$stage_id = isset($input['stage_id']) ? intval($input['stage_id']) : 0;

// Get client info
$clientStmt = $conn->prepare("
    SELECT business_type, total_project_cost, accountaid_fk 
    FROM user_info 
    WHERE id = ?
");
$clientStmt->bind_param("i", $client_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();

if (!$client) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit();
}

// Check access
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userRole = $roleStmt->get_result()->fetch_assoc()['role'];

$allowedRoles = ['general_manager', 'operational_manager', 'accounting', 'superadmin'];
$hasAccess = ($client['accountaid_fk'] == $admin_id) || in_array($userRole, $allowedRoles);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Calculate downpayment
$downpayment_percentage = ($client['business_type'] === 'Non-Project') ? 50 : 30;
$downpayment_amount = $client['total_project_cost'] * ($downpayment_percentage / 100);

// Start transaction
$conn->begin_transaction();

try {
    // Update user_info
    $updateClientStmt = $conn->prepare("
        UPDATE user_info 
        SET downpayment_paid = TRUE,
            downpayment_amount = ?
        WHERE id = ?
    ");
    $updateClientStmt->bind_param("di", $downpayment_amount, $client_id);
    $updateClientStmt->execute();
    
    // Update project_tracker stage
    $updateStageStmt = $conn->prepare("
        UPDATE project_tracker 
        SET status = 'Done',
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStageStmt->bind_param("ii", $admin_id, $stage_id);
    $updateStageStmt->execute();
    
    // Create or update payment schedule entry for downpayment
    $checkPaymentStmt = $conn->prepare("
        SELECT id FROM payment_schedule 
        WHERE client_id = ? AND payment_type LIKE '%Down%'
    ");
    $checkPaymentStmt->bind_param("i", $client_id);
    $checkPaymentStmt->execute();
    $existingPayment = $checkPaymentStmt->get_result()->fetch_assoc();
    
    if ($existingPayment) {
        // Update existing
        $updatePaymentStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET status = 'Paid',
                payment_date = NOW(),
                amount = ?
            WHERE id = ?
        ");
        $updatePaymentStmt->bind_param("di", $downpayment_amount, $existingPayment['id']);
        $updatePaymentStmt->execute();
    } else {
        // Insert new
        $insertPaymentStmt = $conn->prepare("
            INSERT INTO payment_schedule 
            (client_id, payment_type, percentage, amount, status, payment_date)
            VALUES (?, ?, ?, ?, 'Paid', NOW())
        ");
        $payment_type = "Down Payment ({$downpayment_percentage}%)";
        $insertPaymentStmt->bind_param("isdd", $client_id, $payment_type, $downpayment_percentage, $downpayment_amount);
        $insertPaymentStmt->execute();
    }
    
    // Recalculate remaining balance
    $sumStmt = $conn->prepare("
        SELECT SUM(amount) as total_paid
        FROM payment_schedule
        WHERE client_id = ? AND status = 'Paid'
    ");
    $sumStmt->bind_param("i", $client_id);
    $sumStmt->execute();
    $total_paid = $sumStmt->get_result()->fetch_assoc()['total_paid'] ?? 0;
    
    $balanceStmt = $conn->prepare("
        UPDATE user_info 
        SET remaining_balance = total_project_cost - ?
        WHERE id = ?
    ");
    $balanceStmt->bind_param("di", $total_paid, $client_id);
    $balanceStmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>