<?php
//update_addon_entry.php
header('Content-Type: application/json');
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$addon_id = intval($data['addon_id'] ?? 0);

// Validate early
if (!$addon_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid addon_id']);
    exit;
}

$quantity      = floatval($data['quantity']        ?? 0);
$price         = floatval($data['price']           ?? 0);
$labor_cost    = floatval($data['labor_cost']      ?? 0);
$note          = trim($data['note']                ?? '');
$addon_jackup  = floatval($data['addon_jackup']    ?? 0);
$u1            = floatval($data['user_dim_value_1'] ?? 0);
$u2            = floatval($data['user_dim_value_2'] ?? 0);
$u3            = floatval($data['user_dim_value_3'] ?? 0);
$computed_area = floatval($data['computed_area']   ?? 0);

$effective_unit = $computed_area > 0 ? $computed_area : 1;
$jack_amt       = $price * ($addon_jackup / 100);

// Fetch product_addons meta
$metaStmt = $conn->prepare("
    SELECT pa.multiply_value, pa.is_stable_mat, pa.min_required_unit
    FROM product_addons pa
    JOIN quotation_entry_addons qea ON qea.addon_id = pa.id
    WHERE qea.id = ?
");
$metaStmt->bind_param("i", $addon_id);
$metaStmt->execute();
$meta = $metaStmt->get_result()->fetch_assoc();

if (!$meta) {
    echo json_encode(['success' => false, 'error' => 'Addon meta not found']);
    exit;
}

$multiply_value    = floatval($meta['multiply_value']    ?? 0);
$is_stable_mat     = (int)($meta['is_stable_mat']        ?? 0);
$min_required_unit = floatval($meta['min_required_unit'] ?? 0);

// Compute totals
if ($is_stable_mat) {
    $raw_mats = ($price * $quantity) + ($jack_amt * $quantity);
} else {
    $raw_mats = ($price * $effective_unit * $quantity) + ($jack_amt * $quantity);
}
$total_mats = ($multiply_value > 0) ? $raw_mats * $multiply_value : $raw_mats;

$labor_unit  = ($min_required_unit > 0 && $effective_unit < $min_required_unit) ? 1 : $effective_unit;
$total_labor = $labor_cost * $labor_unit * $quantity;
$total       = $total_mats + $total_labor;

// Update DB
$stmt = $conn->prepare("
    UPDATE quotation_entry_addons
    SET quantity          = ?,
        price             = ?,
        labor_cost        = ?,
        note              = ?,
        total_computed    = ?,
        addon_jackup      = ?,
        user_dim_value_1  = ?,
        user_dim_value_2  = ?,
        user_dim_value_3  = ?,
        computed_area     = ?
    WHERE id = ?
");
$stmt->bind_param(
    "dddsddddddi",
    $quantity,
    $price,
    $labor_cost,
    $note,
    $total,
    $addon_jackup,
    $u1,
    $u2,
    $u3,
    $computed_area,
    $addon_id
);

if ($stmt->execute()) {
    echo json_encode([
        'success'     => true,
        'total'       => $total,
        'total_mats'  => $total_mats,
        'total_labor' => $total_labor,
        'quantity'    => $quantity,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}