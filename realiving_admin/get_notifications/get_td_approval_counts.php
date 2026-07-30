<?php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$id = (int) $_SESSION['admin_id'];
$role = $_SESSION['admin_role'] ?? '';

$count = 0;

if (in_array($role, ['general_manager', 'operational_manager', 'technical_designer'])) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM td_attachment_approvals la
        WHERE la.approver_id = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM td_revision_log rl
            WHERE rl.client_id = la.client_id
            AND rl.area = la.area
            AND rl.status = 'pending'
            AND (
                (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                OR rl.room_unit_number = la.room_unit_number
            )
        )
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
}

// ── TD remark needed (true/false — does this TD have ANY area needing a remark) ──
$remark_needed = 0;
if ($role === 'technical_designer') {
    $rmkStmt = $conn->prepare("
        SELECT COUNT(DISTINCT la.client_id) FROM layout_approvals la
        INNER JOIN user_info u ON u.id = la.client_id
        WHERE u.technical_designer_id = ?
        AND (la.td_remark IS NULL OR la.td_remark = '')
        AND la.requested_at IS NOT NULL
    ");
    $rmkStmt->bind_param("i", $id);
    $rmkStmt->execute();
    $remark_needed = (int) $rmkStmt->get_result()->fetch_row()[0];
}

header('Content-Type: application/json');
echo json_encode([
    'td_approvals'  => $count,
    'remark_needed' => $remark_needed,
]);
$conn->close();
exit();
?>