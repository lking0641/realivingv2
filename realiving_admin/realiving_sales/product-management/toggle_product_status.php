<?php
session_start();
include '../../connection/connection.php';
include '../checkrole/checkrole.php';

require_role(['admin1','superadmin', 'sales', 'designer']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $toggle_type = isset($_POST['toggle_type']) ? $_POST['toggle_type'] : '';
    
    if ($product_id <= 0 || !in_array($toggle_type, ['top_product', 'visibility'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }
    
    if ($toggle_type === 'top_product') {
        // Toggle is_top_product
        $query = "UPDATE items SET is_top_product = NOT is_top_product WHERE item_id = ?";
    } else {
        // Toggle is_hidden
        $query = "UPDATE items SET is_hidden = NOT is_hidden WHERE item_id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $product_id);
    
    if ($stmt->execute()) {
        // Get updated status
        $check_query = "SELECT is_top_product, is_hidden FROM items WHERE item_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $product_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'is_top_product' => $row['is_top_product'],
            'is_hidden' => $row['is_hidden']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>