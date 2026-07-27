<?php
// chunk_probe.php — Speed probe only, no DB writes
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false]);
    exit();
}
// Just accept and discard the chunk — we only care about timing
$received = isset($_FILES['chunk']) && $_FILES['chunk']['error'] === UPLOAD_ERR_OK;
echo json_encode(['success' => true, 'probe' => true, 'received' => $received]);