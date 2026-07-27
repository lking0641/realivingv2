<?php
// get_area_items.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$area = isset($_GET['area']) ? trim($_GET['area']) : '';
$room_number = isset($_GET['room_number']) && $_GET['room_number'] !== '' ? intval($_GET['room_number']) : null;

if (!$client_id || !$area) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

// Verify access
$meChk = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$meChk->bind_param("i", $admin_id);
$meChk->execute();
$meRow = $meChk->get_result()->fetch_assoc();
$meChk->close();

$chk = $conn->prepare("SELECT designer1_id, designer2_id FROM user_info WHERE id = ?");
$chk->bind_param("i", $client_id);
$chk->execute();
$chkRow = $chk->get_result()->fetch_assoc();
$chk->close();

$isAssigned = $chkRow && ($chkRow['designer1_id'] == $admin_id || $chkRow['designer2_id'] == $admin_id);
$canViewAll = in_array($meRow['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($meRow['role'], ['designer', 'technical_designer']) && $meRow['is_head'] == 1);

if (!$isAssigned && !$canViewAll) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$items = [];

// ── CUSTOMIZED entries ──
if ($room_number !== null) {
    // With specific unit
    $stmt = $conn->prepare("
        SELECT
            qe.id as entry_id,
            COALESCE(qe.item_name, i.item_name) AS item_name,
            i.item_color as main_color,
            qe.item_image_path,
            qe.color_image_path,
            qe.color_label,
            qe.width, qe.height, qe.length,
            qe.width_label, qe.height_label, qe.length_label,
            rd.quantity,
            rd.notes,
            rd.room_unit_name,
            'customized' as entry_type
        FROM quotation_room_distribution rd
        INNER JOIN quotation_entries qe ON rd.quotation_entry_id = qe.id
        INNER JOIN items i ON qe.entry_item_id = i.item_id
        WHERE qe.area = ? AND qe.client_id = ? AND rd.room_unit_number = ?
        ORDER BY COALESCE(qe.item_name, i.item_name) ASC
    ");
    $stmt->bind_param("sii", $area, $client_id, $room_number);
} else {
    // All items in area (no unit filter)
    $stmt = $conn->prepare("
        SELECT
            qe.id as entry_id,
            COALESCE(qe.item_name, i.item_name) AS item_name,
            i.item_color as main_color,
            qe.item_image_path,
            qe.color_image_path,
            qe.color_label,
            qe.width, qe.height, qe.length,
            qe.width_label, qe.height_label, qe.length_label,
            qe.quantity,
            NULL as notes,
            NULL as room_unit_name,
            'customized' as entry_type
        FROM quotation_entries qe
        INNER JOIN items i ON qe.entry_item_id = i.item_id
        WHERE qe.area = ? AND qe.client_id = ?
        ORDER BY COALESCE(qe.item_name, i.item_name) ASC
    ");
    $stmt->bind_param("si", $area, $client_id);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Resolve display color
    $row['display_color'] = !empty($row['color_label']) ? $row['color_label'] : $row['main_color'];

    // Resolve image
    if (!empty($row['color_image_path'])) {
        $p = PAGES_PATH . 'images/product_colors/' . $row['color_image_path'];
        $row['image_folder'] = file_exists($p) ? 'product_colors' : 'products';
        $row['image_file'] = $row['color_image_path'];
    } elseif (!empty($row['item_image_path'])) {
        $row['image_folder'] = 'products';
        $row['image_file'] = $row['item_image_path'];
    } else {
        $row['image_folder'] = null;
        $row['image_file'] = null;
    }

    // Fetch addons
    $adStmt = $conn->prepare("
        SELECT p.addon_name, p.addon_image_path, a.quantity, a.price, a.note
        FROM quotation_entry_addons a
        JOIN product_addons p ON a.addon_id = p.id
        WHERE a.quotation_entry_id = ?
    ");
    $adStmt->bind_param("i", $row['entry_id']);
    $adStmt->execute();
    $row['addons'] = $adStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $adStmt->close();

    $items[] = $row;
}
$stmt->close();

// ── FIXED SIZE entries ──
if ($room_number !== null) {
    $stmt2 = $conn->prepare("
        SELECT
            qfs.id as entry_id,
            qfs.item_name,
            i.item_color as main_color,
            qfs.item_image_path,
            qfs.color_image_path,
            qfs.color_label,
            ifs.size_width as width,
            ifs.size_height as height,
            ifs.size_length as length,
            dl.item_width_label_linear as width_label,
            dl.item_height_label_linear as height_label,
            dl.item_length_label_linear as length_label,
            rd.quantity,
            rd.notes,
            rd.room_unit_name,
            'fixed' as entry_type
        FROM quotation_room_distribution rd
        INNER JOIN quotation_fixed_sizes qfs ON rd.quotation_fixed_size_id = qfs.id
        LEFT JOIN items i ON qfs.item_id = i.item_id
        LEFT JOIN item_fixed_sizes ifs ON qfs.fixed_size_id = ifs.fixed_size_id
        LEFT JOIN dimension_label dl ON ifs.dimension_label_fk = dl.dimension_label_id
        WHERE qfs.area = ? AND qfs.client_id = ? AND rd.room_unit_number = ?
        ORDER BY qfs.item_name ASC
    ");
    $stmt2->bind_param("sii", $area, $client_id, $room_number);
} else {
    $stmt2 = $conn->prepare("
        SELECT
            qfs.id as entry_id,
            qfs.item_name,
            i.item_color as main_color,
            qfs.item_image_path,
            qfs.color_image_path,
            qfs.color_label,
            ifs.size_width as width,
            ifs.size_height as height,
            ifs.size_length as length,
            dl.item_width_label_linear as width_label,
            dl.item_height_label_linear as height_label,
            dl.item_length_label_linear as length_label,
            qfs.quantity,
            NULL as notes,
            NULL as room_unit_name,
            'fixed' as entry_type
        FROM quotation_fixed_sizes qfs
        LEFT JOIN items i ON qfs.item_id = i.item_id
        LEFT JOIN item_fixed_sizes ifs ON qfs.fixed_size_id = ifs.fixed_size_id
        LEFT JOIN dimension_label dl ON ifs.dimension_label_fk = dl.dimension_label_id
        WHERE qfs.area = ? AND qfs.client_id = ?
        ORDER BY qfs.item_name ASC
    ");
    $stmt2->bind_param("si", $area, $client_id);
}
$stmt2->execute();
$result2 = $stmt2->get_result();
while ($row = $result2->fetch_assoc()) {
    $row['display_color'] = !empty($row['color_label']) ? $row['color_label'] : $row['main_color'];

    if (!empty($row['color_image_path'])) {
        $p = PAGES_PATH . 'images/product_colors/' . $row['color_image_path'];
        $row['image_folder'] = file_exists($p) ? 'product_colors' : 'products';
        $row['image_file'] = $row['color_image_path'];
    } elseif (!empty($row['item_image_path'])) {
        $row['image_folder'] = 'products';
        $row['image_file'] = $row['item_image_path'];
    } else {
        $row['image_folder'] = null;
        $row['image_file'] = null;
    }

    // Fetch addons for fixed
    $adStmt2 = $conn->prepare("
        SELECT p.addon_name, p.addon_image_path, a.quantity, a.price, a.note
        FROM quotation_fixed_size_addons a
        JOIN product_addons p ON a.addon_id = p.id
        WHERE a.quotation_fixed_size_id = ?
    ");
    $adStmt2->bind_param("i", $row['entry_id']);
    $adStmt2->execute();
    $row['addons'] = $adStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $adStmt2->close();

    $items[] = $row;
}
$stmt2->close();

// Fetch approval records for this area/unit
$approverStmt = $conn->prepare("
    SELECT id, full_name, role FROM account
    WHERE (role IN ('general_manager','operational_manager'))
       OR (role IN ('designer','technical_designer') AND is_head = 1)
    ORDER BY role
");
$approverStmt->execute();
$approvers = $approverStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($room_number !== null) {
    $apprStmt = $conn->prepare("
        SELECT la.status, la.comment, la.responded_at,
               a.id as approver_id, a.full_name as approver_name, a.role as approver_role
        FROM layout_approvals la
        JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.area = ? AND la.room_unit_number = ?
    ");
    $apprStmt->bind_param("isi", $client_id, $area, $room_number);
} else {
    $apprStmt = $conn->prepare("
        SELECT la.status, la.comment, la.responded_at,
               a.id as approver_id, a.full_name as approver_name, a.role as approver_role
        FROM layout_approvals la
        JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.area = ? AND la.room_unit_number IS NULL
    ");
    $apprStmt->bind_param("is", $client_id, $area);
}
$apprStmt->execute();
$apprRows = $apprStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build map
$apprMap = [];
foreach ($apprRows as $rec) {
    $apprMap[$rec['approver_id']] = $rec;
}

// Build approver status list
$approvalList = [];
foreach ($approvers as $apr) {
    $rec = $apprMap[$apr['id']] ?? null;
    $approvalList[] = [
        'approver_id' => $apr['id'],
        'approver_name' => $apr['full_name'],
        'approver_role' => $apr['role'],
        'status' => $rec ? $rec['status'] : 'not_requested',
        'comment' => $rec ? $rec['comment'] : null,
        'responded_at' => $rec ? $rec['responded_at'] : null,
    ];
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'area' => $area,
    'room_number' => $room_number,
    'total' => count($items),
    'approvals' => $approvalList
]);