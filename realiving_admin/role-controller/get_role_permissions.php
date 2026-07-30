<?php
// get_role_permissions.php
session_start();
header('Content-Type: application/json');

include $includes ['connection'];

$role = isset($_GET['role']) ? $_GET['role'] : '';

if (empty($role)) {
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit();
}

// Fetch permissions for this role
$stmt = $conn->prepare("
    SELECT stage_name 
    FROM role_stage_permissions 
    WHERE role = ? AND can_update = 1
");
$stmt->bind_param("s", $role);
$stmt->execute();
$result = $stmt->get_result();

$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row['stage_name'];
}

// Get stages locked by sales users
$lockedBySales = [];
$salesStmt = $conn->prepare("
    SELECT sp.stage_name, a.full_name 
    FROM stage_permissions sp
    JOIN account a ON sp.admin_id = a.id
    WHERE sp.can_update = 1
");
$salesStmt->execute();
$salesResult = $salesStmt->get_result();
while ($row = $salesResult->fetch_assoc()) {
    if (!isset($lockedBySales[$row['stage_name']])) {
        $lockedBySales[$row['stage_name']] = [];
    }
    $lockedBySales[$row['stage_name']][] = $row['full_name'];
}

// Get stages locked by other roles
$lockedByOtherRole = [];
$otherRoleStmt = $conn->prepare("
    SELECT stage_name, role 
    FROM role_stage_permissions 
    WHERE can_update = 1 AND role != ?
");
$otherRoleStmt->bind_param("s", $role);
$otherRoleStmt->execute();
$otherRoleResult = $otherRoleStmt->get_result();
while ($row = $otherRoleResult->fetch_assoc()) {
    $lockedByOtherRole[$row['stage_name']] = ucwords(str_replace('_', ' ', $row['role']));
}

echo json_encode([
    'success' => true,
    'permissions' => $permissions,
    'lockedBySales' => $lockedBySales,
    'lockedByOtherRole' => $lockedByOtherRole
]);
?>