    <?php
    //udate_fixed_addon.php
    session_start();
    include $includes ['connection'];
    header('Content-Type: application/json');

    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }

    $data          = json_decode(file_get_contents('php://input'), true);
    $addon_id      = intval($data['addon_id'] ?? 0);
    $quantity      = floatval($data['quantity'] ?? 0);
    $price         = floatval($data['price'] ?? 0);
    $labor_cost    = floatval($data['labor_cost'] ?? 0);
    $note          = $data['note'] ?? '';
    $addon_jackup  = floatval($data['addon_jackup'] ?? 0);
    $u1            = floatval($data['user_dim_value_1'] ?? 0);
    $u2            = floatval($data['user_dim_value_2'] ?? 0);
    $u3            = floatval($data['user_dim_value_3'] ?? 0);
    $computed_area = floatval($data['computed_area'] ?? 0);

    $metaStmt2 = $conn->prepare("
    SELECT multiply_value
    FROM product_addons
    WHERE id = (SELECT addon_id FROM quotation_fixed_size_addons WHERE id = ?)
");
$metaStmt2->bind_param("i", $addon_id);
$metaStmt2->execute();
$meta2 = $metaStmt2->get_result()->fetch_assoc();
$multiply_value = floatval($meta2['multiply_value'] ?? 0);

    if (!$addon_id) {
        echo json_encode(['success' => false, 'error' => 'Missing addon_id']);
        exit();
    }

    $effective_unit = $computed_area > 0 ? $computed_area : 1;
    $jack_amt       = $price * ($addon_jackup / 100);

    // Fetch addon meta for stable_mat and min_required_unit
    $metaStmt = $conn->prepare("SELECT is_stable_mat, min_required_unit FROM product_addons WHERE id = (SELECT addon_id FROM quotation_fixed_size_addons WHERE id = ?)");
    $metaStmt->bind_param("i", $addon_id);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc();
    $is_stable_mat     = (int)($meta['is_stable_mat'] ?? 0);
    $min_required_unit = floatval($meta['min_required_unit'] ?? 0);

    // Stable mat: don't multiply price by computed_unit
    if ($is_stable_mat) {
        $raw_mats = ($price * $quantity) + ($jack_amt * $quantity);
    } else {
        $raw_mats = ($price * $effective_unit * $quantity) + ($jack_amt * $quantity);
    }
    $total_mats = ($multiply_value > 0) ? $raw_mats * $multiply_value : $raw_mats;

    // Min required unit: if computed_unit < min_required_unit, labor uses unit=1
    $labor_unit     = ($min_required_unit > 0 && $effective_unit < $min_required_unit) ? 1 : $effective_unit;
    $total_labor    = $labor_cost * $labor_unit * $quantity;
    $total_computed = $total_mats + $total_labor;

    $stmt = $conn->prepare("
    UPDATE quotation_fixed_size_addons
    SET quantity = ?, price = ?, labor_cost = ?, note = ?,
        addon_jackup = ?, user_dim_value_1 = ?, user_dim_value_2 = ?,
        user_dim_value_3 = ?, computed_area = ?
    WHERE id = ?
");
$stmt->bind_param("dddsdddddi",
    $quantity, $price, $labor_cost, $note,
    $addon_jackup, $u1, $u2, $u3, $computed_area,
    $addon_id
);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }