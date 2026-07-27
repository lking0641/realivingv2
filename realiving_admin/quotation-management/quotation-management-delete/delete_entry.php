<?php
// delete_entry.php
$data = json_decode(file_get_contents('php://input'), true);
$id   = intval($data['entry_id'] ?? 0);

if (!$id) {
    http_response_code(400);
    exit("No entry_id provided");
}

include $includes ['connection'];

// 1) Delete its add‑ons first
$stmt1 = $conn->prepare(
    "DELETE FROM quotation_entry_addons WHERE quotation_entry_id = ?"
);
if (!$stmt1) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    exit;
}
$stmt1->bind_param("i", $id);
$stmt1->execute();
if ($stmt1->errno) {
    error_log("Delete addons failed: " . $stmt1->error);
}

// 2) Delete the entry itself
$stmt2 = $conn->prepare(
    "DELETE FROM quotation_entries WHERE id = ?"
);
if (!$stmt2) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    exit;
}
$stmt2->bind_param("i", $id);
$stmt2->execute();
if ($stmt2->errno) {
    error_log("Delete entry failed: " . $stmt2->error);
}

http_response_code(204);
