<?php
session_start();
header('Content-Type: application/json');
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$client_id = isset($input['client_id']) ? intval($input['client_id']) : 0;
$tracker_mode = isset($input['tracker_mode']) ? $input['tracker_mode'] : 'non-sequential';

// Validate tracker_mode
if (!in_array($tracker_mode, ['sequential', 'non-sequential'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid tracker mode']);
    exit();
}

$stmt = $conn->prepare("UPDATE user_info SET tracker_mode = ? WHERE id = ?");
$stmt->bind_param("si", $tracker_mode, $client_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Tracker mode updated']);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>