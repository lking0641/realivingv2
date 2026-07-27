    <?php
    // attachment_delete.php
    session_start();
    include $includes ['connection'];
    date_default_timezone_set('Asia/Manila');

    $admin_id      = $_SESSION['admin_id'];
    $attachment_id = intval($_POST['attachment_id'] ?? 0);
    $client_id     = intval($_POST['client_id'] ?? 0);
    $redirect_url  = trim($_POST['redirect_url'] ?? '');

    // Fallback redirect if none provided
    $fallback = 'designer_attachments.php?client_id=' . $client_id;

    if (!$attachment_id || !$client_id) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Invalid request."));
        exit();
    }

    // Verify designer is assigned to this client
    $checkStmt = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
    $checkStmt->bind_param("i", $client_id);
    $checkStmt->execute();
    $clientRow = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$clientRow || ($clientRow['designer1_id'] != $admin_id && $clientRow['designer2_id'] != $admin_id)) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Access denied."));
        exit();
    }

    // Fetch the attachment record — make sure it belongs to this client
    $fetchStmt = $conn->prepare("
        SELECT id, file_path, file_name, client_id, uploaded_by
        FROM layout_attachments
        WHERE id = ? AND client_id = ?
    ");
    $fetchStmt->bind_param("ii", $attachment_id, $client_id);
    $fetchStmt->execute();
    $att = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    if (!$att) {
        header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("Attachment not found or already deleted."));
        exit();
    }

    // Only the uploader or designer1 can delete
    // (optional strict rule — remove if you want any assigned designer to delete)
    // if ($att['uploaded_by'] != $admin_id) {
    //     header("Location: " . ($redirect_url ?: $fallback) . "&error=" . urlencode("You can only delete your own uploads."));
    //     exit();
    // }

    // Delete physical file from disk
    $uploadDir = ROOT_PATH . 'uploads/layout_attachments/';
    $filePath  = $uploadDir . $att['file_path'];

    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            // File exists but couldn't be deleted — log it but still remove DB record
            error_log("attachment_delete.php: Could not delete file {$filePath} for attachment id {$attachment_id}");
        }
    }

    // Delete from database
    $delStmt = $conn->prepare("DELETE FROM layout_attachments WHERE id = ? AND client_id = ?");
    $delStmt->bind_param("ii", $attachment_id, $client_id);
    $delStmt->execute();
    $affected = $delStmt->affected_rows;
    $delStmt->close();

    if ($affected > 0) {
        $successMsg = '"' . $att['file_name'] . '" deleted successfully.';
        $dest = ($redirect_url ?: $fallback) . '&success=' . urlencode($successMsg);
    } else {
        $dest = ($redirect_url ?: $fallback) . '&error=' . urlencode("Could not delete the record. Please try again.");
    }

    header("Location: " . $dest);
    exit();