<?php
//delete_admin.php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
session_start();
include $includes['connection'];

if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
  exit;
}

$current_admin_id = $_SESSION['admin_id'];
$id = $_POST['id'] ?? null;

if (!$id) {
  echo json_encode(['success' => false, 'error' => 'Invalid request.']);
  exit;
}

// Prevent self-deletion
if ((int)$id === (int)$current_admin_id) {
  echo json_encode(['success' => false, 'error' => 'You cannot delete your own account.']);
  exit;
}

// Confirm target exists
$check = $conn->prepare("SELECT id, role FROM account WHERE id = ?");
$check->bind_param('i', $id);
$check->execute();
$target = $check->get_result()->fetch_assoc();

if (!$target) {
  echo json_encode(['success' => false, 'error' => 'Admin not found.']);
  exit;
}

// Unassign their inquiries across all four tables so nothing references a deleted id
$tables = ['appointments', 'concept_inquiries', 'contact', 'project_inquiries'];
foreach ($tables as $table) {
  $unassign = $conn->prepare("UPDATE `$table` SET assigned_to = NULL WHERE assigned_to = ?");
  $unassign->bind_param('i', $id);
  $unassign->execute();
  $unassign->close();
}

// Now delete the account
$stmt = $conn->prepare("DELETE FROM account WHERE id = ?");
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'Database error.']);
}

$stmt->close();