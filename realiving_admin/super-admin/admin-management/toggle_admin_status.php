<?php
//toggle_admin_status.php
header('Content-Type: application/json');

include $includes['connection'];
include $includes['checkrole'];

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'human_resource'])) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
  exit;
}

$current_admin_id = $_SESSION['admin_id'];
$current_role = $_SESSION['admin_role'];

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

// HR is never allowed to touch a super_admin account, regardless of what the request contains
if ($current_role === 'human_resource' && $target['role'] === 'super_admin') {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'You are not permitted to modify this account.']);
  exit;
}

$stmt = $conn->prepare("UPDATE account SET account_status = ? WHERE id = ?");
$stmt->bind_param('si', $status, $id);

if ($stmt->execute()) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'Database error.']);
}