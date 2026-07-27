<?php
// delete_client.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_role(['sales', 'designer', 'technical_designer']);

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;

if (!$client_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit();
}

// ── Ownership check: only the sales who owns this record (accountaid_fk) can delete ──
$ownerStmt = $conn->prepare("SELECT accountaid_fk, clientname FROM user_info WHERE id = ?");
$ownerStmt->bind_param("i", $client_id);
$ownerStmt->execute();
$ownerRow = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$ownerRow) {
    echo json_encode(['success' => false, 'error' => 'Client not found']);
    exit();
}

if ((int) $ownerRow['accountaid_fk'] !== $admin_id) {
    echo json_encode(['success' => false, 'error' => 'Access denied: You do not own this client record']);
    exit();
}

// ────────────────────────────────────────────────
//  Helper: delete a single file, then remove its
//  parent folder if it is now empty.
// ────────────────────────────────────────────────
function deleteFileAndMaybeFolder(string $abs_path): void
{
    if (file_exists($abs_path)) {
        unlink($abs_path);
    }
    $dir = dirname($abs_path);
    if (is_dir($dir)) {
        $items = array_diff(scandir($dir), ['.', '..']);
        if (empty($items)) {
            rmdir($dir);
        }
    }
}

// ────────────────────────────────────────────────
//  Helper: recursively delete an entire directory
// ────────────────────────────────────────────────
function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

// Base path from this file back to the project root (adjust depth if needed)
$base = '../../';

try {
    $conn->begin_transaction();

    // ════════════════════════════════════════════════════════════════════════
    // 1. stage_approvals
    //    file_path stored as: uploads/stage_approvals/{client_id}/{filename}
    //    We delete each file then wipe the whole client subfolder.
    // ════════════════════════════════════════════════════════════════════════
    $saStmt = $conn->prepare("SELECT file_path FROM stage_approvals WHERE client_id = ?");
    $saStmt->bind_param("i", $client_id);
    $saStmt->execute();
    $saResult = $saStmt->get_result();
    while ($row = $saResult->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            deleteFileAndMaybeFolder($base . $row['file_path']);
        }
    }
    $saStmt->close();

    // Delete stage_approval_reviews first (child of stage_approvals)
    $conn->query("
    DELETE sar FROM stage_approval_reviews sar
    INNER JOIN stage_approvals sa ON sar.approval_id = sa.id
    WHERE sa.client_id = $client_id
");

    // Wipe the entire client stage-approval folder
    deleteDirectory($base . 'uploads/stage_approvals/' . $client_id . '/');

    $conn->query("DELETE FROM stage_approvals WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 2. quotation_entry_addons  (child of quotation_entries — no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("
        DELETE qea FROM quotation_entry_addons qea
        INNER JOIN quotation_entries qe ON qea.quotation_entry_id = qe.id
        WHERE qe.client_id = $client_id
    ");

    // ════════════════════════════════════════════════════════════════════════
    // 3. quotation_entries
    //    item_image / color_image  → LONGBLOB (no file on disk)
    //    item_image_path / color_image_path → stored path on disk
    // ════════════════════════════════════════════════════════════════════════
    $qeStmt = $conn->prepare("SELECT item_image_path, color_image_path FROM quotation_entries WHERE client_id = ?");
    $qeStmt->bind_param("i", $client_id);
    $qeStmt->execute();
    $qeResult = $qeStmt->get_result();
    while ($row = $qeResult->fetch_assoc()) {
        foreach (['item_image_path', 'color_image_path'] as $col) {
            if (!empty($row[$col])) {
                deleteFileAndMaybeFolder($base . $row[$col]);
            }
        }
    }
    $qeStmt->close();
    $conn->query("DELETE FROM quotation_entries WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 4. quotation_fixed_size_addons  (child of quotation_fixed_sizes — no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("
        DELETE qfsa FROM quotation_fixed_size_addons qfsa
        INNER JOIN quotation_fixed_sizes qfs ON qfsa.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = $client_id
    ");

    // ════════════════════════════════════════════════════════════════════════
    // 5. quotation_fixed_sizes
    //    item_image_path / color_image_path → stored path on disk
    // ════════════════════════════════════════════════════════════════════════
    $qfsStmt = $conn->prepare("SELECT item_image_path, color_image_path FROM quotation_fixed_sizes WHERE client_id = ?");
    $qfsStmt->bind_param("i", $client_id);
    $qfsStmt->execute();
    $qfsResult = $qfsStmt->get_result();
    while ($row = $qfsResult->fetch_assoc()) {
        foreach (['item_image_path', 'color_image_path'] as $col) {
            if (!empty($row[$col])) {
                deleteFileAndMaybeFolder($base . $row[$col]);
            }
        }
    }
    $qfsStmt->close();
    $conn->query("DELETE FROM quotation_fixed_sizes WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 6. project_tracker  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM project_tracker WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 7. site_visit
    //    designer1_photo / designer2_photo → just the filename
    //    full path: uploads/site_visit_photos/{filename}
    // ════════════════════════════════════════════════════════════════════════
    $svStmt = $conn->prepare("SELECT designer1_photo, designer2_photo FROM site_visit WHERE client_id = ?");
    $svStmt->bind_param("i", $client_id);
    $svStmt->execute();
    $svResult = $svStmt->get_result();
    while ($row = $svResult->fetch_assoc()) {
        foreach (['designer1_photo', 'designer2_photo'] as $col) {
            if (!empty($row[$col])) {
                deleteFileAndMaybeFolder($base . 'uploads/site_visit_photos/' . $row[$col]);
            }
        }
    }
    $svStmt->close();
    $conn->query("DELETE FROM site_visit WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 8. payment_proofs
    //    file_path stored as full relative path from root:
    //    e.g. uploads/payment_proofs/proof_54_1774662992.pdf
    // ════════════════════════════════════════════════════════════════════════
    $ppStmt = $conn->prepare("SELECT file_path FROM payment_proofs WHERE client_id = ? AND file_path IS NOT NULL");
    $ppStmt->bind_param("i", $client_id);
    $ppStmt->execute();
    $ppResult = $ppStmt->get_result();
    while ($row = $ppResult->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            deleteFileAndMaybeFolder($base . $row['file_path']);
        }
    }
    $ppStmt->close();
    $conn->query("DELETE FROM payment_proofs WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 9. notice_to_proceed
    //    file_path stored as: uploads/ntp/{filename}
    // ════════════════════════════════════════════════════════════════════════
    $ntpStmt = $conn->prepare("SELECT file_path FROM notice_to_proceed WHERE client_id = ?");
    $ntpStmt->bind_param("i", $client_id);
    $ntpStmt->execute();
    $ntpResult = $ntpStmt->get_result();
    while ($row = $ntpResult->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            deleteFileAndMaybeFolder($base . $row['file_path']);
        }
    }
    $ntpStmt->close();
    $conn->query("DELETE FROM notice_to_proceed WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 10. payment_accounting_reviews  (no files — child of payment_schedule)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("
        DELETE par FROM payment_accounting_reviews par
        INNER JOIN payment_schedule ps ON par.payment_id = ps.id
        WHERE ps.client_id = $client_id
    ");

    // ════════════════════════════════════════════════════════════════════════
    // 11. payment_schedule  (no files — proofs and reviews handled above)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM payment_schedule WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 12. layout_intake  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM layout_intake WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 13. layout_attachments
    //     full path: uploads/layout_attachments/{file_path}
    // ════════════════════════════════════════════════════════════════════════
    $laStmt = $conn->prepare("SELECT file_path FROM layout_attachments WHERE client_id = ?");
    $laStmt->bind_param("i", $client_id);
    $laStmt->execute();
    $laResult = $laStmt->get_result();
    while ($row = $laResult->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            deleteFileAndMaybeFolder($base . 'uploads/layout_attachments/' . $row['file_path']);
        }
    }
    $laStmt->close();
    $conn->query("DELETE FROM layout_attachments WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 14. layout_approvals  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM layout_approvals WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 15. layout_revision_log  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM layout_revision_log WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 16. td_attachments
    //     full path: uploads/td_attachments/{file_path}
    // ════════════════════════════════════════════════════════════════════════
    $tdaStmt = $conn->prepare("SELECT file_path FROM td_attachments WHERE client_id = ?");
    $tdaStmt->bind_param("i", $client_id);
    $tdaStmt->execute();
    $tdaResult = $tdaStmt->get_result();
    while ($row = $tdaResult->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            deleteFileAndMaybeFolder($base . 'uploads/td_attachments/' . $row['file_path']);
        }
    }
    $tdaStmt->close();
    $conn->query("DELETE FROM td_attachments WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 17. td_attachment_approvals  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM td_attachment_approvals WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 18. td_revision_log  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM td_revision_log WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 19. bom_order_status  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM bom_order_status WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 20. timeline_area  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM timeline_area WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 20a. timeline_area_groups  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM timeline_area_groups WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 20b. stage_deadlines  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM stage_deadlines WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
// 21. item_status_remarks  (no files)
// ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM item_status_remarks WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 21a. internal_po_approvals  (no files)
    // ════════════════════════════════════════════════════════════════════════
    $conn->query("DELETE FROM internal_po_approvals WHERE client_id = $client_id");

    // ════════════════════════════════════════════════════════════════════════
    // 22. user_info — the parent row (always last!)
    // ════════════════════════════════════════════════════════════════════════
    $delStmt = $conn->prepare("DELETE FROM user_info WHERE id = ? AND accountaid_fk = ?");
    $delStmt->bind_param("ii", $client_id, $admin_id);
    $delStmt->execute();

    if ($delStmt->affected_rows === 0) {
        throw new Exception('Client record could not be deleted (ownership mismatch or already removed)');
    }
    $delStmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Client "' . htmlspecialchars($ownerRow['clientname']) . '" and all related data have been deleted.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();