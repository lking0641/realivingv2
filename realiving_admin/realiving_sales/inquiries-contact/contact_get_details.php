<?php
//contact_get_details.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || !isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$contact_id = intval($_GET['id']);
$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Fetch contact details
$contact_query = "SELECT * FROM contact WHERE id = ?";
if ($admin_role !== 'superadmin') {
    $contact_query .= " AND assigned_to = $admin_id";
}

$stmt = $conn->prepare($contact_query);
$stmt->bind_param("i", $contact_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Contact not found']);
    exit();
}

$contact = $result->fetch_assoc();
$stmt->close();

// Fetch associated items if inquiry_type is 'contact_with_items'
$items = [];
if ($contact['inquiry_type'] === 'contact_with_items') {
    $items_query = "SELECT cii.*, i.item_id, i.item_color as main_color
                    FROM contact_inquiry_items cii
                    LEFT JOIN items i ON cii.item_id = i.item_id
                    WHERE cii.contact_id = ?
                    ORDER BY cii.added_at ASC";
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bind_param("i", $contact_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    while ($item = $items_result->fetch_assoc()) {
        // Fetch item details
        $details_query = "SELECT ciid.*,
                                 ifs.size_label, ifs.size_width, ifs.size_height, ifs.size_length, ifs.measurement_unit,
                                 isc.standard_color, isc.standard_color_image_path
                          FROM contact_inquiry_item_details ciid
                          LEFT JOIN item_fixed_sizes ifs ON ciid.fixed_size_id = ifs.fixed_size_id
                          LEFT JOIN item_standard_color isc ON ciid.standard_color_id = isc.standard_color_id
                          WHERE ciid.contact_inquiry_item_id = ?";
        $details_stmt = $conn->prepare($details_query);
        $details_stmt->bind_param("i", $item['id']);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();
        
        if ($details_row = $details_result->fetch_assoc()) {
            $item['details'] = $details_row;
            
            // Fetch addons if selected_addons is not empty
            if (!empty($details_row['selected_addons'])) {
                $addon_ids = json_decode($details_row['selected_addons'], true);
                if (is_array($addon_ids) && count($addon_ids) > 0) {
                    $placeholders = implode(',', array_fill(0, count($addon_ids), '?'));
                    $addon_query = "SELECT * FROM product_addons WHERE id IN ($placeholders)";
                    $addon_stmt = $conn->prepare($addon_query);
                    
                    $types = str_repeat('i', count($addon_ids));
                    $addon_stmt->bind_param($types, ...$addon_ids);
                    $addon_stmt->execute();
                    $addon_result = $addon_stmt->get_result();
                    
                    $item['details']['addons'] = [];
                    while ($addon = $addon_result->fetch_assoc()) {
                        $item['details']['addons'][] = $addon;
                    }
                    $addon_stmt->close();
                }
            }
        } else {
            $item['details'] = null;
        }
        $details_stmt->close();
        
        $items[] = $item;
    }
    $items_stmt->close();
}

echo json_encode([
    'success' => true,
    'contact' => $contact,
    'items' => $items
]);

$conn->close();
?>