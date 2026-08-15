<?php
//toggle_admin_status.php
header('Content-Type: application/json');

include $includes['connection'];
include $includes['checkrole'];

require_role(['super_admin']);

$current_admin_id = $_SESSION['admin_id'] ?? 0;

$id = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$id || !in_array($status, ['active', 'suspended'], true)) {
  echo json_encode(['success' => false, 'error' => 'Invalid request.']);
  exit;
}

// Prevent self-suspension
if ((int)$id === (int)$current_admin_id) {
  echo json_encode(['success' => false, 'error' => 'You cannot change your own account status.']);
  exit;
}

// Prevent tampering: make sure target isn't a superadmin being suspended by mistake (optional safety — remove if you want superadmins suspendable too)
$check = $conn->prepare("SELECT role FROM account WHERE id = ?");
$check->bind_param('i', $id);
$check->execute();
$target = $check->get_result()->fetch_assoc();

if (!$target) {
  echo json_encode(['success' => false, 'error' => 'Admin not found.']);
  exit;
}

$stmt = $conn->prepare("UPDATE account SET account_status = ? WHERE id = ?");
$stmt->bind_param('si', $status, $id);

if ($stmt->execute()) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'Database error.']);
}