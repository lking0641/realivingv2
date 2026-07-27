<?php
//get_stage_approvals.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$stage_id = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;

// Support single approval_id lookup for e-sign modal
$approval_id_single = isset($_GET['approval_id']) ? intval($_GET['approval_id']) : 0;

if ($approval_id_single) {
    $stmt = $conn->prepare("
        SELECT sa.*, a1.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a1 ON sa.uploaded_by = a1.id
        WHERE sa.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $approval_id_single);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode([
        'success'    => (bool)$row,
        'file_path'  => $row['file_path'] ?? null,
        'file_name'  => $row['file_name'] ?? null,
        'page_count' => 1
    ]);
    exit();
}

$stmt = $conn->prepare("
    SELECT 
        sa.*,
        a1.full_name as uploaded_by_name,
        a1.role as uploader_role,
        a2.full_name as reviewed_by_name
    FROM stage_approvals sa
    LEFT JOIN account a1 ON sa.uploaded_by = a1.id
    LEFT JOIN account a2 ON sa.reviewed_by = a2.id
    WHERE sa.stage_id = ?
    ORDER BY sa.uploaded_at DESC
");
$stmt->bind_param("i", $stage_id);
$stmt->execute();
$result = $stmt->get_result();

$approvals = [];
while ($row = $result->fetch_assoc()) {
    $approvals[] = $row;
}

echo json_encode(['success' => true, 'approvals' => $approvals]);