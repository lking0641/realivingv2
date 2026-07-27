<?php
// update_unit_status.php
// Handles status updates for individual units in quotation_room_distribution
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$input    = json_decode(file_get_contents('php://input'), true);

$distribution_id = isset($input['distribution_id']) ? intval($input['distribution_id']) : 0;
$stage           = isset($input['stage'])           ? $input['stage']                   : '';
$new_status      = isset($input['status'])          ? $input['status']                  : '';
$item_id         = isset($input['item_id'])         ? intval($input['item_id'])         : 0;
$source          = isset($input['source'])          ? $input['source']                  : 'entry'; // 'entry' or 'fixed'

// Validate stage
$valid_stages = ['Fabrication', 'Delivery', 'Installation', 'BILLING'];
if (!in_array($stage, $valid_stages)) {
    echo json_encode(['success' => false, 'error' => 'Invalid stage']);
    exit();
}

// Validate status
$base_statuses      = ['Pending', 'Ongoing', 'Done'];
$installation_extra = ['Incomplete', 'Punchlist'];
$valid_statuses     = ($stage === 'Installation')
    ? array_merge($base_statuses, $installation_extra)
    : $base_statuses;

if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit();
}

$statusColumn  = strtolower($stage) . '_status';
$updatedColumn = strtolower($stage) . '_updated_at';

// Verify the distribution row exists and get client_id for access check
if ($source === 'fixed') {
    $verifyStmt = $conn->prepare("
        SELECT qrd.distribution_id, qfs.client_id, ui.accountaid_fk, a.role
        FROM quotation_room_distribution qrd
        INNER JOIN quotation_fixed_sizes qfs ON qrd.quotation_fixed_size_id = qfs.id
        INNER JOIN user_info ui ON qfs.client_id = ui.id
        LEFT JOIN account a ON a.id = ?
        WHERE qrd.distribution_id = ?
    ");
} else {
    $verifyStmt = $conn->prepare("
        SELECT qrd.distribution_id, qe.client_id, ui.accountaid_fk, a.role
        FROM quotation_room_distribution qrd
        INNER JOIN quotation_entries qe ON qrd.quotation_entry_id = qe.id
        INNER JOIN user_info ui ON qe.client_id = ui.id
        LEFT JOIN account a ON a.id = ?
        WHERE qrd.distribution_id = ?
    ");
}
$verifyStmt->bind_param("ii", $admin_id, $distribution_id);
$verifyStmt->execute();
$verify = $verifyStmt->get_result()->fetch_assoc();

if (!$verify) {
    echo json_encode(['success' => false, 'error' => 'Distribution row not found']);
    exit();
}

$admin_role = $verify['role'];
$client_id  = $verify['client_id'];

$allowedRoles = ['general_manager','operational_manager','designer','technical_designer','accounting','superadmin','project_coordinator'];
$hasAccess    = in_array($admin_role, $allowedRoles) || ($verify['accountaid_fk'] == $admin_id);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// ── Per-item dependency check ──
// Delivery requires that specific item to be Done in Fabrication
// Installation requires that specific item to be Done in Delivery
$stageDependencies = [
    'Delivery'     => 'Fabrication',
    'Installation' => 'Delivery',
];
if (isset($stageDependencies[$stage])) {
    $requiredStage   = $stageDependencies[$stage];
    $requiredCol     = strtolower($requiredStage) . '_status';

    if ($source === 'fixed') {
        $depItemStmt = $conn->prepare("SELECT $requiredCol AS prev_status FROM quotation_fixed_sizes WHERE id = ?");
    } else {
        $depItemStmt = $conn->prepare("SELECT $requiredCol AS prev_status FROM quotation_entries WHERE id = ?");
    }
    $depItemStmt->bind_param("i", $item_id);
    $depItemStmt->execute();
    $depItemRow = $depItemStmt->get_result()->fetch_assoc();

    if (($depItemRow['prev_status'] ?? '') !== 'Done') {
        echo json_encode([
            'success' => false,
            'error'   => "This item must be marked Done in {$requiredStage} before updating {$stage}."
        ]);
        exit();
    }
}

// ── Update the distribution row status ──
$updateStmt = $conn->prepare("
    UPDATE quotation_room_distribution
    SET $statusColumn = ?, $updatedColumn = NOW()
    WHERE distribution_id = ?
");
$updateStmt->bind_param("si", $new_status, $distribution_id);

if (!$updateStmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
    exit();
}

// ── Roll up: compute the parent item's aggregate status from all its unit rows ──
// This keeps the parent item status in sync with its units
if ($source === 'fixed') {
    $rollupStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                            AS total,
            SUM(CASE WHEN $statusColumn = 'Done'       THEN 1 ELSE 0 END)     AS cnt_done,
            SUM(CASE WHEN $statusColumn = 'Ongoing'    THEN 1 ELSE 0 END)     AS cnt_ongoing,
            SUM(CASE WHEN $statusColumn = 'Incomplete' THEN 1 ELSE 0 END)     AS cnt_incomplete,
            SUM(CASE WHEN $statusColumn = 'Punchlist'  THEN 1 ELSE 0 END)     AS cnt_punchlist
        FROM quotation_room_distribution
        WHERE quotation_fixed_size_id = ?
    ");
    $rollupStmt->bind_param("i", $item_id);
} else {
    $rollupStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                            AS total,
            SUM(CASE WHEN $statusColumn = 'Done'       THEN 1 ELSE 0 END)     AS cnt_done,
            SUM(CASE WHEN $statusColumn = 'Ongoing'    THEN 1 ELSE 0 END)     AS cnt_ongoing,
            SUM(CASE WHEN $statusColumn = 'Incomplete' THEN 1 ELSE 0 END)     AS cnt_incomplete,
            SUM(CASE WHEN $statusColumn = 'Punchlist'  THEN 1 ELSE 0 END)     AS cnt_punchlist
        FROM quotation_room_distribution
        WHERE quotation_entry_id = ?
    ");
    $rollupStmt->bind_param("i", $item_id);
}
$rollupStmt->execute();
$r = $rollupStmt->get_result()->fetch_assoc();

if ($r['total'] == 0)                        $itemStatus = 'Pending';
elseif ($r['total'] == $r['cnt_done'])       $itemStatus = 'Done';
elseif ($r['cnt_ongoing']>0 || $r['cnt_incomplete']>0 || $r['cnt_punchlist']>0)
                                             $itemStatus = 'Ongoing';
else                                         $itemStatus = 'Pending';

// Update parent item status
if ($source === 'fixed') {
    $parentStmt = $conn->prepare("UPDATE quotation_fixed_sizes SET $statusColumn = ?, $updatedColumn = NOW() WHERE id = ?");
} else {
    $parentStmt = $conn->prepare("UPDATE quotation_entries SET $statusColumn = ?, $updatedColumn = NOW() WHERE id = ?");
}
$parentStmt->bind_param("si", $itemStatus, $item_id);
$parentStmt->execute();

// ── Roll up further: update project_tracker aggregate for the whole client ──
// Must aggregate from BOTH quotation_entries AND quotation_fixed_sizes
// But use unit-level status from quotation_room_distribution where units exist,
// and item-level status where no units exist
$aggStmt = $conn->prepare("
    SELECT
        COUNT(*)                                                                        AS total,
        SUM(CASE WHEN $statusColumn = 'Done'       THEN 1 ELSE 0 END)                 AS cnt_done,
        SUM(CASE WHEN $statusColumn IN ('Ongoing','Incomplete','Punchlist') THEN 1 ELSE 0 END) AS cnt_active
    FROM (
        -- Units from quotation_entries
        SELECT qrd.$statusColumn
        FROM quotation_room_distribution qrd
        INNER JOIN quotation_entries qe ON qrd.quotation_entry_id = qe.id
        WHERE qe.client_id = ? AND qrd.quotation_entry_id IS NOT NULL
        UNION ALL
        -- Units from quotation_fixed_sizes
        SELECT qrd.$statusColumn
        FROM quotation_room_distribution qrd
        INNER JOIN quotation_fixed_sizes qfs ON qrd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = ? AND qrd.quotation_fixed_size_id IS NOT NULL
        UNION ALL
        -- Items with NO units in quotation_entries
        SELECT qe.$statusColumn
        FROM quotation_entries qe
        WHERE qe.client_id = ?
          AND NOT EXISTS (SELECT 1 FROM quotation_room_distribution qrd WHERE qrd.quotation_entry_id = qe.id)
        UNION ALL
        -- Items with NO units in quotation_fixed_sizes
        SELECT qfs.$statusColumn
        FROM quotation_fixed_sizes qfs
        WHERE qfs.client_id = ?
          AND NOT EXISTS (SELECT 1 FROM quotation_room_distribution qrd WHERE qrd.quotation_fixed_size_id = qfs.id)
    ) AS combined
");
$aggStmt->bind_param("iiii", $client_id, $client_id, $client_id, $client_id);
$aggStmt->execute();
$agg = $aggStmt->get_result()->fetch_assoc();

if ($agg['total'] == 0)                        $aggregatedStatus = 'Pending';
elseif ($agg['total'] == $agg['cnt_done'])     $aggregatedStatus = 'Done';
elseif ($agg['cnt_active'] > 0)                $aggregatedStatus = 'Ongoing';
else                                           $aggregatedStatus = 'Pending';

$trackerStmt = $conn->prepare("
    UPDATE project_tracker
    SET status = ?, updated_by = ?, updated_at = NOW()
    WHERE client_id = ? AND stage_name = ?
");
$trackerStmt->bind_param("siis", $aggregatedStatus, $admin_id, $client_id, $stage);
$trackerStmt->execute();

echo json_encode([
    'success'           => true,
    'message'           => 'Unit status updated',
    'item_status'       => $itemStatus,
    'aggregated_status' => $aggregatedStatus
]);
?>