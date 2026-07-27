<?php
// update_project_cost.php
session_start();
header('Content-Type: application/json');

include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit(); 
}

$input = json_decode(file_get_contents('php://input'), true);
$client_id = isset($input['client_id']) ? intval($input['client_id']) : 0;
$total_cost = isset($input['total_cost']) ? floatval($input['total_cost']) : 0;

if ($client_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit();
}

// Calculate total already paid from payment_schedule
$paidStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_paid
    FROM payment_schedule
    WHERE client_id = ? AND status = 'Paid'
");
$paidStmt->bind_param("i", $client_id);
$paidStmt->execute();
$paidRow = $paidStmt->get_result()->fetch_assoc();
$total_paid = (float)$paidRow['total_paid'];

// Remaining = new total cost minus what's already been paid
$total_cost    = round($total_cost, 2);
$new_remaining = round(max(0, $total_cost - $total_paid), 2);

// Update total_project_cost and remaining_balance
$stmt = $conn->prepare("
    UPDATE user_info 
    SET total_project_cost = ?,
        remaining_balance = ?
    WHERE id = ?
");
$stmt->bind_param("ddi", $total_cost, $new_remaining, $client_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'remaining_balance' => $new_remaining, 'total_cost' => $total_cost]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>