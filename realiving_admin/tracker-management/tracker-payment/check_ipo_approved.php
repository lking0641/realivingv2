<?php
session_start();
ob_start();
include $includes ['connection'];
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['approved' => false]);
    exit();
}

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
if (!$client_id) {
    echo json_encode(['approved' => false]);
    exit();
}

// Check if Internal P.O to Accounting stage has an approved internal_po_approval
$stmt = $conn->prepare("
    SELECT ipa.id 
    FROM internal_po_approvals ipa
    JOIN project_tracker pt ON pt.id = ipa.stage_id
    WHERE pt.client_id = ? 
      AND pt.stage_name = 'Internal P.O to Accounting'
      AND ipa.overall_status = 'approved'
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode(['approved' => !empty($row)]);