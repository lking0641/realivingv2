<?php
// update_item_status.php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input = json_decode(file_get_contents('php://input'), true);
$entry_id = isset($input['entry_id']) ? intval($input['entry_id']) : 0;
$stage = isset($input['stage']) ? $input['stage'] : '';
$new_status = isset($input['status']) ? $input['status'] : '';
$source = isset($input['source']) ? $input['source'] : 'entry'; // 'entry' or 'fixed'
$remark = isset($input['remark']) ? trim($input['remark']) : '';

// Validate stage
$valid_stages = ['Fabrication', 'Delivery', 'Installation', 'BILLING'];
if (!in_array($stage, $valid_stages)) {
    echo json_encode(['success' => false, 'error' => 'Invalid stage']);
    exit();
}

// Dependency check: per-item level only
// Delivery requires this specific item to be Done in Fabrication
// Installation requires this specific item to be Done in Delivery
$stageDependencies = [
    'Delivery' => 'Fabrication',
    'Installation' => 'Delivery',
];
if (isset($stageDependencies[$stage]) && $entry_id > 0) {
    $requiredStage = $stageDependencies[$stage];
    $requiredCol = strtolower($requiredStage) . '_status';

    $depItemStmt = ($source === 'fixed')
        ? $conn->prepare("SELECT COALESCE($requiredCol, 'Pending') AS prev_status FROM quotation_fixed_sizes WHERE id = ?")
        : $conn->prepare("SELECT COALESCE($requiredCol, 'Pending') AS prev_status FROM quotation_entries WHERE id = ?");
    $depItemStmt->bind_param("i", $entry_id);
    $depItemStmt->execute();
    $depItemRow = $depItemStmt->get_result()->fetch_assoc();

    if (($depItemRow['prev_status'] ?? 'Pending') !== 'Done') {
        echo json_encode([
            'success' => false,
            'error' => "This item must be Done in {$requiredStage} before updating {$stage}."
        ]);
        exit();
    }
}

// Validate status — Installation allows extra statuses
$base_statuses = ['Pending', 'Ongoing', 'Done'];
$installation_extra = ['Incomplete', 'Punchlist'];
$valid_statuses = ($stage === 'Installation')
    ? array_merge($base_statuses, $installation_extra)
    : $base_statuses;

if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status: ' . $new_status]);
    exit();
}

$statusColumn = strtolower($stage) . '_status';
$updatedColumn = strtolower($stage) . '_updated_at';

// ── Verify access depending on source ──
if ($source === 'fixed') {
    $verifyStmt = $conn->prepare("
        SELECT qfs.client_id, ui.accountaid_fk, a.role
        FROM quotation_fixed_sizes qfs
        JOIN user_info ui ON qfs.client_id = ui.id
        LEFT JOIN account a ON a.id = ?
        WHERE qfs.id = ?
    ");
} else {
    $verifyStmt = $conn->prepare("
        SELECT qe.client_id, ui.accountaid_fk, a.role
        FROM quotation_entries qe
        JOIN user_info ui ON qe.client_id = ui.id
        LEFT JOIN account a ON a.id = ?
        WHERE qe.id = ?
    ");
}
$verifyStmt->bind_param("ii", $admin_id, $entry_id);
$verifyStmt->execute();
$verifyResult = $verifyStmt->get_result()->fetch_assoc();

if (!$verifyResult) {
    echo json_encode(['success' => false, 'error' => 'Item not found']);
    exit();
}

$admin_role = $verifyResult['role'];
$client_id = $verifyResult['client_id'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$hasAccess = ($verifyResult['accountaid_fk'] == $admin_id) || in_array($admin_role, $allowedRolesForAllClients);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// ── Update the correct table ──
$table = ($source === 'fixed') ? 'quotation_fixed_sizes' : 'quotation_entries';
$updateStmt = $conn->prepare("
    UPDATE $table
    SET $statusColumn = ?, $updatedColumn = NOW()
    WHERE id = ?
");
$updateStmt->bind_param("si", $new_status, $entry_id);

if (!$updateStmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
    exit();
}

// ── Aggregate status across BOTH tables for this client ──
$aggStmt = $conn->prepare("
    SELECT
        COUNT(*)                                                                    AS total,
        SUM(CASE WHEN $statusColumn = 'Done'       THEN 1 ELSE 0 END)             AS cnt_done,
        SUM(CASE WHEN $statusColumn IN ('Ongoing','Incomplete','Punchlist') THEN 1 ELSE 0 END) AS cnt_active
    FROM (
        SELECT $statusColumn FROM quotation_entries      WHERE client_id = ?
        UNION ALL
        SELECT $statusColumn FROM quotation_fixed_sizes  WHERE client_id = ?
    ) AS combined
");
$aggStmt->bind_param("ii", $client_id, $client_id);
$aggStmt->execute();
$agg = $aggStmt->get_result()->fetch_assoc();

if ($agg['total'] == 0)
    $aggregatedStatus = 'Pending';
elseif ($agg['total'] == $agg['cnt_done'])
    $aggregatedStatus = 'Done';
elseif ($agg['cnt_active'] > 0)
    $aggregatedStatus = 'Ongoing';
else
    $aggregatedStatus = 'Pending';

// ── Update project_tracker ──
$updateTrackerStmt = $conn->prepare("
    UPDATE project_tracker
    SET status = ?, updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = ?
");
$updateTrackerStmt->bind_param("siis", $aggregatedStatus, $admin_id, $client_id, $stage);
$updateTrackerStmt->execute();

// ── If BILLING, also update addons (only for entries) ──
if ($stage === 'BILLING' && $source === 'entry') {
    $updateAddonsStmt = $conn->prepare("
        UPDATE quotation_entry_addons
        SET billing_status = ?
        WHERE quotation_entry_id = ?
    ");
    $updateAddonsStmt->bind_param("si", $new_status, $entry_id);
    $updateAddonsStmt->execute();
}

// ── Save remark if provided ──
if ($remark !== '') {
    $remStmt = $conn->prepare("INSERT INTO item_status_remarks (client_id, stage, source, item_id, distribution_id, status, remark, created_by) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)");
    $remStmt->bind_param("ississi", $client_id, $stage, $source, $entry_id, $new_status, $remark, $admin_id);
    $remStmt->execute();
}

echo json_encode([
    'success' => true,
    'message' => 'Status updated successfully',
    'aggregated_status' => $aggregatedStatus
]);
?>