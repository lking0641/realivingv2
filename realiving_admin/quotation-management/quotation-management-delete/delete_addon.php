<?php
// delete_addon.php
$data = json_decode(file_get_contents('php://input'), true);
$id   = intval($data['addon_id'] ?? 0);

if (!$id) {
    http_response_code(400);
    exit("No addon_id provided");
}

include $includes ['connection'];

// 1) Prepare
$stmt = $conn->prepare("DELETE FROM quotation_entry_addons WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    exit;
}

// 2) Bind + execute
$stmt->bind_param("i", $id);
$stmt->execute();
if ($stmt->errno) {
    error_log("Delete addon failed: " . $stmt->error);
    http_response_code(500);
    exit;
}

// 3) Success
http_response_code(204);
