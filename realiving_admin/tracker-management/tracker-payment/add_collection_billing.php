<?php
// add_collection_billing.php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input    = json_decode(file_get_contents('php://input'), true);

$client_id    = isset($input['client_id'])    ? intval($input['client_id'])    : 0;
$label        = isset($input['label'])        ? trim($input['label'])          : '';
$amount       = isset($input['amount'])       ? floatval($input['amount'])     : 0;
$total_cost   = isset($input['total_cost'])   ? floatval($input['total_cost']) : 0;
$snapshot_pct = isset($input['snapshot_pct']) ? floatval($input['snapshot_pct']) : 0;

if (!$client_id || !$label || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid required fields']);
    exit();
}

// Verify caller has access to this client
$accessStmt = $conn->prepare("
    SELECT ui.accountaid_fk, a.role
    FROM user_info ui
    LEFT JOIN account a ON a.id = ?
    WHERE ui.id = ?
");
$accessStmt->bind_param("ii", $admin_id, $client_id);
$accessStmt->execute();
$access = $accessStmt->get_result()->fetch_assoc();

if (!$access) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit();
}

$admin_role   = $access['role'];
$allowedRoles = ['general_manager', 'operational_manager', 'accounting', 'superadmin'];
$hasAccess    = ($access['accountaid_fk'] == $admin_id) || in_array($admin_role, $allowedRoles);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// Build payment_type from label — prefix with "Collection Billing - " if not already
$payment_type = (strpos($label, 'Collection Billing') === 0)
    ? $label
    : 'Collection Billing - ' . $label;

// Calculate % of total project cost
$percentage = $total_cost > 0 ? ($amount / $total_cost) * 100 : 0;

// Check if payment_schedule has snapshot_pct column; if not, insert without it
// (safe fallback — snapshot_pct is optional display-only data)
$hasSnapshotCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM payment_schedule LIKE 'snapshot_pct'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasSnapshotCol = true;
}

if ($hasSnapshotCol) {
    $insertStmt = $conn->prepare("
        INSERT INTO payment_schedule
            (client_id, payment_type, percentage, amount, status, snapshot_pct)
        VALUES
            (?, ?, ?, ?, 'Pending', ?)
    ");
    $insertStmt->bind_param("isddd", $client_id, $payment_type, $percentage, $amount, $snapshot_pct);
} else {
    $insertStmt = $conn->prepare("
        INSERT INTO payment_schedule
            (client_id, payment_type, percentage, amount, status)
        VALUES
            (?, ?, ?, ?, 'Pending')
    ");
    $insertStmt->bind_param("isdd", $client_id, $payment_type, $percentage, $amount);
}

if ($insertStmt->execute()) {
    echo json_encode(['success' => true, 'payment_id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
?>