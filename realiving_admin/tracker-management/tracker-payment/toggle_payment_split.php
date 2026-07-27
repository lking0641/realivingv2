<?php
session_start();
ob_start();
include $includes ['connection'];
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$data = json_decode(file_get_contents('php://input'), true);
$client_id = isset($data['client_id']) ? intval($data['client_id']) : 0;
$action = isset($data['action']) ? $data['action'] : ''; // 'merge' or 'revert'

if (!$client_id || !in_array($action, ['merge', 'revert'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Fetch client + verify assignment
$chk = $conn->prepare("SELECT business_type, total_project_cost, payment_split_mode, designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk FROM user_info WHERE id = ?");
$chk->bind_param("i", $client_id);
$chk->execute();
$client = $chk->get_result()->fetch_assoc();
if (!$client) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit();
}

$isAssigned = in_array($admin_id, array_filter([
    $client['designer1_id'] ?? null,
    $client['designer2_id'] ?? null,
    $client['technical_designer_id'] ?? null,
    $client['project_coordinator_id'] ?? null,
    $client['accountaid_fk'] ?? null,
]));

if (!$isAssigned) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to this client']);
    exit();
}

if ($client['business_type'] !== 'Non-Project') {
    echo json_encode(['success' => false, 'error' => 'Only applicable to Individual (Non-Project) clients']);
    exit();
}

$total_cost = (float) $client['total_project_cost'];
$current_mode = $client['payment_split_mode'] ?? 'standard';

// ══════════════════════════════════════════════
// MERGE: 40% Before + 10% After -> 50% After Installation
// ══════════════════════════════════════════════
if ($action === 'merge') {
    if ($current_mode === 'merged') {
        echo json_encode(['success' => false, 'error' => 'Already using the 50% split']);
        exit();
    }

    $bfStmt = $conn->prepare("SELECT id, status FROM payment_schedule WHERE client_id = ? AND payment_type = '40% Before Installation' LIMIT 1");
    $bfStmt->bind_param("i", $client_id);
    $bfStmt->execute();
    $bfRow = $bfStmt->get_result()->fetch_assoc();

    if ($bfRow && $bfRow['status'] === 'Paid') {
        echo json_encode(['success' => false, 'error' => 'Cannot switch — 40% Before Installation is already paid.']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $newAmount = $total_cost * 0.50;

        if ($bfRow) {
            $upd = $conn->prepare("UPDATE payment_schedule SET payment_type='50% Retention', percentage=50, amount=?, status='Not Available' WHERE id=?");
            $upd->bind_param("di", $newAmount, $bfRow['id']);
            $upd->execute();
            // Same payment_schedule id lang ang ginagamit, kaya iniiwan na lang natin
            // ang existing proof/review para hindi mawala ang koneksyon pag nag-toggle.
        } else {
            $ins = $conn->prepare("INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status) VALUES (?, '50% Retention', 50, ?, 'Not Available')");
            $ins->bind_param("id", $client_id, $newAmount);
            $ins->execute();
        }

        // Remove the separate 10% After Installation row
        $delRow = $conn->prepare("DELETE FROM payment_schedule WHERE client_id = ? AND payment_type = '10% After Installation'");
        $delRow->bind_param("i", $client_id);
        $delRow->execute();

        $updMode = $conn->prepare("UPDATE user_info SET payment_split_mode = 'merged' WHERE id = ?");
        $updMode->bind_param("i", $client_id);
        $updMode->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to switch: ' . $e->getMessage()]);
    }
    exit();
}

// ══════════════════════════════════════════════
// REVERT: 50% After Installation -> 40% Before + 10% After
// ══════════════════════════════════════════════
if ($action === 'revert') {
    if ($current_mode !== 'merged') {
        echo json_encode(['success' => false, 'error' => 'Not currently using the 50% split']);
        exit();
    }

    $mStmt = $conn->prepare("SELECT id, status FROM payment_schedule WHERE client_id = ? AND payment_type = '50% Retention' LIMIT 1");
    $mStmt->bind_param("i", $client_id);
    $mStmt->execute();
    $mRow = $mStmt->get_result()->fetch_assoc();

    if ($mRow && $mRow['status'] === 'Paid') {
        echo json_encode(['success' => false, 'error' => 'Cannot revert — 50% Retention is already paid.']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $bf_a = $total_cost * 0.40;
        $af_a = $total_cost * 0.10;

        if ($mRow) {
            $upd = $conn->prepare("UPDATE payment_schedule SET payment_type='40% Before Installation', percentage=40, amount=?, status='Not Available' WHERE id=?");
            $upd->bind_param("di", $bf_a, $mRow['id']);
            $upd->execute();
            // Same id lang ang ginagamit — hindi na natin binubura ang proof/review dito.
        } else {
            $ins1 = $conn->prepare("INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status) VALUES (?, '40% Before Installation', 40, ?, 'Not Available')");
            $ins1->bind_param("id", $client_id, $bf_a);
            $ins1->execute();
        }

        $ins2 = $conn->prepare("INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status) VALUES (?, '10% After Installation', 10, ?, 'Not Available')");
        $ins2->bind_param("id", $client_id, $af_a);
        $ins2->execute();

        $updMode = $conn->prepare("UPDATE user_info SET payment_split_mode = 'standard' WHERE id = ?");
        $updMode->bind_param("i", $client_id);
        $updMode->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to revert: ' . $e->getMessage()]);
    }
    exit();
}