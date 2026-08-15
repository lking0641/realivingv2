<?php
//get_admin_status.php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();
include $includes['connection'];
include $includes['online_status'];

// Only superadmin should see everyone's presence
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
  exit;
}

$result = $conn->query("SELECT id, is_online, last_activity FROM account");

$statuses = [];
$online_count = 0;

while ($row = $result->fetch_assoc()) {
  $online = isAdminOnline($row['is_online'], $row['last_activity']);
  $statuses[$row['id']] = $online;
  if ($online) $online_count++;
}

echo json_encode([
  'success' => true,
  'statuses' => $statuses,
  'online_count' => $online_count
]);