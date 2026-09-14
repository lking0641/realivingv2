<?php
//get_notifications.php
session_start();
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../../config/notification_counts.php';

$admin_id = $_SESSION['admin_id'];

$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$currentRole = $userInfo['role'];
$isHeadUser = (bool) ($userInfo['is_head'] ?? false);

// ── Build client ID list ──────────────────────────────────────────────────

$needsAssignmentFilter = (
    $currentRole === 'project_coordinator' ||
    ($currentRole === 'designer' && !$isHeadUser) ||
    ($currentRole === 'technical_designer' && !$isHeadUser)
);

if ($needsAssignmentFilter) {
    $stmt = $conn->prepare("
        SELECT id FROM user_info 
        WHERE account_status != 'Finished'
          AND (designer1_id = ? OR designer2_id = ? OR technical_designer_id = ? OR project_coordinator_id = ?)
    ");
    $stmt->bind_param("iiii", $admin_id, $admin_id, $admin_id, $admin_id);
} else {
    $stmt = $conn->prepare("SELECT id FROM user_info WHERE account_status != 'Finished'");
}
$stmt->execute();
$result = $stmt->get_result();

// ── Tally totals ─────────────────────────────────────────────────────────

$totalPending = 0;
$totalRejectedVisits = 0;
$totalRejectedUploads = 0;
$totalPaymentProofs = 0;
$totalMissingPo = 0;
$totalPoNotOrdered = 0;
$totalPendingInternalPO = 0;

$clients = [];

while ($row = $result->fetch_assoc()) {
    $cid = $row['id'];

    $pending = getClientPendingApprovalsForUser($conn, $admin_id, $currentRole, $isHeadUser, $cid);
    $rejVisits = ($currentRole === 'designer' && $isHeadUser) ? getClientRejectedSiteVisits($conn, $cid) : 0;
    $rejUploads = getClientRejectedFilesForUploader($conn, $admin_id, $cid);
    $payProofs = in_array($currentRole, ['accounting', 'general_manager', 'operational_manager', 'superadmin']) ? getClientPendingPaymentProofs($conn, $cid) : 0;
    $missingPo = in_array($currentRole, ['project_coordinator', 'sales', 'general_manager', 'operational_manager', 'superadmin']) ? getClientMissingPoCount($conn, $cid) : 0;
    $poNotOrdered = ($currentRole === 'project_coordinator') ? getClientApprovedPoNotOrderedCount($conn, $cid) : 0;
    $pendingInternalPO = getClientPendingInternalPO($conn, $admin_id, $currentRole, $isHeadUser, $cid);

    $totalPending += $pending;
    $totalRejectedVisits += $rejVisits;
    $totalRejectedUploads += $rejUploads;
    $totalPaymentProofs += $payProofs;
    $totalMissingPo += $missingPo;
    $totalPoNotOrdered += $poNotOrdered;
    $totalPendingInternalPO += $pendingInternalPO;

    $clients[] = [
        'id' => $cid,
        'pending_approvals' => $pending,
        'rejected_site_visits' => $rejVisits,
        'rejected_uploads' => $rejUploads,
        'pending_payment_proofs' => $payProofs,
        'missing_po_count' => $missingPo,
        'po_not_ordered_count' => $poNotOrdered,
        'pending_internal_po' => $pendingInternalPO,
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'totals' => [
        'pending_approvals' => $totalPending,
        'rejected_site_visits' => $totalRejectedVisits,
        'rejected_uploads' => $totalRejectedUploads,
        'pending_payment_proofs' => $totalPaymentProofs,
        'missing_po_count' => $totalMissingPo,
        'po_not_ordered_count' => $totalPoNotOrdered,
        'pending_internal_po' => $totalPendingInternalPO,
    ],
    'clients' => $clients,
]);