<?php
session_start();
include $includes ['connection'];

// Check if the admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$business_type = isset($_GET['business_type']) ? $_GET['business_type'] : 'Non-Project';

if (strlen($query) < 2) {
    exit(json_encode([]));
}

$like = '%' . $conn->real_escape_string($query) . '%';

$sql = "
    SELECT 
        item_id,
        item_code,
        item_name,
        item_material,
        item_image_path,
        non_project_price,
        project_price
    FROM items
    WHERE (item_code LIKE ? OR item_name LIKE ?)
    AND is_hidden = 0
    ORDER BY 
        CASE 
            WHEN item_code LIKE ? THEN 1
            WHEN item_name LIKE ? THEN 2
            ELSE 3
        END,
        item_name
    LIMIT 10
";

$stmt = $conn->prepare($sql);
$likeStart = $conn->real_escape_string($query) . '%';
$stmt->bind_param("ssss", $like, $like, $likeStart, $likeStart);
$stmt->execute();
$result = $stmt->get_result();

$suggestions = [];
while ($row = $result->fetch_assoc()) {
    $price = $business_type === 'Project' ? $row['project_price'] : $row['non_project_price'];
    
    $suggestions[] = [
        'item_id' => $row['item_id'],
        'item_code' => $row['item_code'],
        'item_name' => $row['item_name'],
        'item_material' => $row['item_material'],
        'item_image_path' => $row['item_image_path'],
        'price' => number_format($price, 2)
    ];
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($suggestions);