<?php
session_start();
header('Content-Type: application/json');

include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$client_id = isset($data['client_id']) ? intval($data['client_id']) : 0;
$revision_count = isset($data['revision_count']) ? intval($data['revision_count']) : 0;

if (!$client_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit();
}

// Ensure revision count is not negative
if ($revision_count < 0) {
    $revision_count = 0;
}

try {
    $stmt = $conn->prepare("
        UPDATE user_info 
        SET revision_count = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $revision_count, $client_id);
    $stmt->execute();
    
    if ($stmt->affected_rows >= 0) {
        echo json_encode([
            'success' => true,
            'new_count' => $revision_count
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No changes made']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>