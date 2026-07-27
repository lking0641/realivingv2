<?php
// get_stage_permissions.php
session_start();
header('Content-Type: application/json');

include '../../connection/connection.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;

if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid admin ID']);
    exit();
}

// Fetch permissions for this admin
$stmt = $conn->prepare("
    SELECT stage_name 
    FROM stage_permissions 
    WHERE admin_id = ? AND can_update = 1
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row['stage_name'];
}

// Get stages locked by roles
$lockedByRole = [];
$roleStmt = $conn->prepare("
    SELECT stage_name, role 
    FROM role_stage_permissions 
    WHERE can_update = 1
");
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
while ($row = $roleResult->fetch_assoc()) {
    $lockedByRole[$row['stage_name']] = ucwords(str_replace('_', ' ', $row['role']));
}

echo json_encode([
    'success' => true,
    'permissions' => $permissions,
    'lockedByRole' => $lockedByRole
]);
?>