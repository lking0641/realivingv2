<?php
// update_accomplishment_amount.php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id   = $_SESSION['admin_id'];
$input      = json_decode(file_get_contents('php://input'), true);

$payment_id = isset($input['payment_id']) ? intval($input['payment_id'])    : 0;
$amount     = isset($input['amount'])     ? floatval($input['amount'])      : 0;
$total_cost = isset($input['total_cost']) ? floatval($input['total_cost'])  : 0;

if (!$payment_id || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

// Verify the payment row belongs to a client this user can access, and is not already Paid
$verifyStmt = $conn->prepare("
    SELECT ps.client_id, ps.status, ps.payment_type,
           ui.accountaid_fk, a.role
    FROM payment_schedule ps
    JOIN user_info ui ON ps.client_id = ui.id
    LEFT JOIN account a ON a.id = ?
    WHERE ps.id = ?
");
$verifyStmt->bind_param("ii", $admin_id, $payment_id);
$verifyStmt->execute();
$row = $verifyStmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Payment not found']);
    exit();
}

if ($row['status'] === 'Paid') {
    echo json_encode(['success' => false, 'error' => 'Cannot edit a payment that is already marked as Paid']);
    exit();
}

$admin_role   = $row['role'];
$allowedRoles = ['general_manager', 'operational_manager', 'accounting', 'superadmin'];
$hasAccess    = ($row['accountaid_fk'] == $admin_id) || in_array($admin_role, $allowedRoles);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Recalculate percentage
$percentage = $total_cost > 0 ? ($amount / $total_cost) * 100 : 0;

$updateStmt = $conn->prepare("
    UPDATE payment_schedule
    SET amount = ?, percentage = ?
    WHERE id = ?
");
$updateStmt->bind_param("ddi", $amount, $percentage, $payment_id);

if ($updateStmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
?>