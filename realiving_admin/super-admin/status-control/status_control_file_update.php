<?php
// status_control_file_update.php
session_start();
header('Content-Type: application/json');
include $includes['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Enforce super_admin only
$roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$adminRole = $roleStmt->get_result()->fetch_assoc()['role'] ?? '';

if ($adminRole !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$approval_id = isset($data['approval_id']) ? intval($data['approval_id']) : 0;
$action = isset($data['action']) ? trim($data['action']) : '';

if (!$approval_id || !in_array($action, ['set_status', 'reset'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Confirm the file exists
$checkStmt = $conn->prepare("SELECT * FROM stage_approvals WHERE id = ?");
$checkStmt->bind_param("i", $approval_id);
$checkStmt->execute();
$file = $checkStmt->get_result()->fetch_assoc();
if (!$file) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}
$stage_id = $file['stage_id'];
$stage_name = $file['stage_name'];

if ($action === 'set_status') {
    $status = isset($data['status']) ? trim($data['status']) : '';
    if (!in_array($status, ['pending', 'approved', 'rejected'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit();
    }

    $upd = $conn->prepare("
        UPDATE stage_approvals
        SET approval_status = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $upd->bind_param("sii", $status, $admin_id, $approval_id);
    $upd->execute();

    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'reset') {
    // ── If this is a BOM file, cascade-delete any linked POs and their receipts ──
    if ($stage_name === 'Bill of Materials (BOM)') {
        $linkedPoStmt = $conn->prepare("SELECT id, file_path FROM stage_approvals WHERE linked_bom_id = ?");
        $linkedPoStmt->bind_param("i", $approval_id);
        $linkedPoStmt->execute();
        $linkedPos = $linkedPoStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($linkedPos as $po) {
            // Delete receipts linked to this PO first
            $linkedReceiptsStmt = $conn->prepare("SELECT id, file_path FROM stage_approvals WHERE linked_po_id = ?");
            $linkedReceiptsStmt->bind_param("i", $po['id']);
            $linkedReceiptsStmt->execute();
            $receipts = $linkedReceiptsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($receipts as $rc) {
                if ($rc['file_path'] && file_exists(ROOT_PATH . $rc['file_path'])) {
                    unlink(ROOT_PATH . $rc['file_path']);
                }
                $delRcRev = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
                $delRcRev->bind_param("i", $rc['id']);
                $delRcRev->execute();
                $delRc = $conn->prepare("DELETE FROM stage_approvals WHERE id = ?");
                $delRc->bind_param("i", $rc['id']);
                $delRc->execute();
            }

            if ($po['file_path'] && file_exists(ROOT_PATH . $po['file_path'])) {
                unlink(ROOT_PATH . $po['file_path']);
            }
            $delPoRev = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
            $delPoRev->bind_param("i", $po['id']);
            $delPoRev->execute();
            $delPo = $conn->prepare("DELETE FROM stage_approvals WHERE id = ?");
            $delPo->bind_param("i", $po['id']);
            $delPo->execute();
        }

        $delBomOrder = $conn->prepare("DELETE FROM bom_order_status WHERE bom_approval_id = ?");
        $delBomOrder->bind_param("i", $approval_id);
        $delBomOrder->execute();
    }

    // ── If this is a PO file, cascade-delete any linked receipts ──
    if ($stage_name === 'Purchase Order (Submit to accounting)') {
        $linkedReceiptsStmt = $conn->prepare("SELECT id, file_path FROM stage_approvals WHERE linked_po_id = ?");
        $linkedReceiptsStmt->bind_param("i", $approval_id);
        $linkedReceiptsStmt->execute();
        $receipts = $linkedReceiptsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($receipts as $rc) {
            if ($rc['file_path'] && file_exists(ROOT_PATH . $rc['file_path'])) {
                unlink(ROOT_PATH . $rc['file_path']);
            }
            $delRcRev = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
            $delRcRev->bind_param("i", $rc['id']);
            $delRcRev->execute();
            $delRc = $conn->prepare("DELETE FROM stage_approvals WHERE id = ?");
            $delRc->bind_param("i", $rc['id']);
            $delRc->execute();
        }

        if (!empty($file['linked_bom_id'])) {
            $delBomOrder = $conn->prepare("DELETE FROM bom_order_status WHERE bom_approval_id = ?");
            $delBomOrder->bind_param("i", $file['linked_bom_id']);
            $delBomOrder->execute();
        }
    }

    // ── Delete the physical file itself ──
    if ($file['file_path'] && file_exists(ROOT_PATH . $file['file_path'])) {
        unlink(ROOT_PATH . $file['file_path']);
    }

    // ── Delete its reviews, then the record itself ──
    $delReviews = $conn->prepare("DELETE FROM stage_approval_reviews WHERE approval_id = ?");
    $delReviews->bind_param("i", $approval_id);
    $delReviews->execute();

    $delFile = $conn->prepare("DELETE FROM stage_approvals WHERE id = ?");
    $delFile->bind_param("i", $approval_id);
    $delFile->execute();

    // ── If Internal P.O to Accounting: clear its approval chain entirely ──
    if ($stage_name === 'Internal P.O to Accounting') {
        $delIpo = $conn->prepare("DELETE FROM internal_po_approvals WHERE stage_id = ?");
        $delIpo->bind_param("i", $stage_id);
        $delIpo->execute();
    }

    // ── Recompute this stage's status based on what's left ──
    $remainStmt = $conn->prepare("SELECT COUNT(*) FROM stage_approvals WHERE stage_id = ?");
    $remainStmt->bind_param("i", $stage_id);
    $remainStmt->execute();
    $remaining = (int) $remainStmt->get_result()->fetch_row()[0];

    $newStatus = $remaining > 0 ? 'Ongoing' : 'Pending';
    $updStage = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
    $updStage->bind_param("si", $newStatus, $stage_id);
    $updStage->execute();

    // ── Cascade revert downstream stages if BOM/PO was the one deleted ──
    if ($stage_name === 'Bill of Materials (BOM)') {
        $cascade = $conn->prepare("
            UPDATE project_tracker
            SET status = 'Ongoing', updated_at = NOW()
            WHERE client_id = (SELECT client_id FROM project_tracker WHERE id = ?)
              AND stage_name IN ('Purchase Order (Submit to accounting)', 'Accounting (Order Processing)')
              AND status = 'Done'
        ");
        $cascade->bind_param("i", $stage_id);
        $cascade->execute();
    } elseif ($stage_name === 'Purchase Order (Submit to accounting)') {
        $cascade = $conn->prepare("
            UPDATE project_tracker
            SET status = 'Ongoing', updated_at = NOW()
            WHERE client_id = (SELECT client_id FROM project_tracker WHERE id = ?)
              AND stage_name = 'Accounting (Order Processing)'
              AND status = 'Done'
        ");
        $cascade->bind_param("i", $stage_id);
        $cascade->execute();
    }

    echo json_encode(['success' => true, 'deleted' => true]);
    exit();
}