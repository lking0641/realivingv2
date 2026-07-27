<?php
// link_product_addons.php
include $includes ['mainbody'];

require_role(['sales', 'designer']);

if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();
    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: " . BASE_URL . "designer-layout-list");
        exit();
    }
}

$message = '';
$messageType = '';

// ─── SAVE: Addon → Products ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_addon_products'])) {
    $addon_id        = intval($_POST['addon_id']);
    $product_ids     = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
    $is_required     = isset($_POST['is_required']) ? 1 : 0;
    $max_quantity    = !empty($_POST['max_quantity']) ? intval($_POST['max_quantity']) : null;
    $visible_ids     = isset($_POST['visible_item_ids']) ? array_map('intval', $_POST['visible_item_ids']) : [];

    $conn->begin_transaction();
    try {
        // Only delete links for items currently VISIBLE in the filter
        // This preserves links for items in other dimension/family filters
        if (!empty($visible_ids)) {
            $placeholders = implode(',', array_fill(0, count($visible_ids), '?'));
            $types_str    = 'i' . str_repeat('i', count($visible_ids));
            $params_arr   = array_merge([$addon_id], $visible_ids);
            $del = $conn->prepare("DELETE FROM product_addon_links WHERE addon_id = ? AND item_id IN ($placeholders)");
            $del->bind_param($types_str, ...$params_arr);
            $del->execute();
            $del->close();
        }

        if (!empty($product_ids)) {
            $ins = $conn->prepare("INSERT INTO product_addon_links (item_id, addon_id, is_required, max_quantity, display_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($product_ids as $idx => $item_id) {
                $order = $idx + 1;
                $ins->bind_param("iiiii", $item_id, $addon_id, $is_required, $max_quantity, $order);
                $ins->execute();
            }
            $ins->close();
        }

        // Update is_required and max_quantity for ALL existing links of this addon
        $upd = $conn->prepare("UPDATE product_addon_links SET is_required = ?, max_quantity = ? WHERE addon_id = ?");
        $upd->bind_param("iii", $is_required, $max_quantity, $addon_id);
        $upd->execute();
        $upd->close();

        $conn->commit();
        $message     = "Accessory linked to " . count($product_ids) . " product(s) successfully!";
        $messageType = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $message     = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// ─── FILTERS ─────────────────────────────────────────────────────────────────
$filter_dimension  = isset($_GET['dimension'])  ? $_GET['dimension']  : 'all';
$filter_family     = isset($_GET['family'])     ? $_GET['family']     : 'all';
$selected_addon_id = isset($_GET['addon_id'])   ? intval($_GET['addon_id']) : 0;
$filter_family2 = isset($_GET['family2']) ? $_GET['family2'] : 'all';

// Dimension labels
$dimensions = [];
$dr = $conn->query("SELECT DISTINCT dl.dimension_label_id, dl.dimension_label_name 
                    FROM dimension_label dl 
                    INNER JOIN items i ON i.dimension_label_fk = dl.dimension_label_id 
                    ORDER BY dl.dimension_label_name");
if ($dr) while ($row = $dr->fetch_assoc()) $dimensions[] = $row;

// Only add Fixed Modular if there are actually fixed modular items
$fm_check = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE is_fixed_modular = 1");
$fm_row = $fm_check->fetch_assoc();
if ($fm_row['cnt'] > 0) {
    $dimensions[] = ['dimension_label_id' => 'fixed_modular', 'dimension_label_name' => 'Fixed Modular'];
}

// Families based on filter
$families = [];
if ($filter_dimension !== 'all') {
    if ($filter_dimension === 'fixed_modular') {
        $fr = $conn->query("SELECT DISTINCT item_family FROM items WHERE is_fixed_modular=1 AND item_family IS NOT NULL AND item_family!='' ORDER BY item_family");
        if ($fr) while ($row = $fr->fetch_assoc()) $families[] = $row['item_family'];
    } else {
        $fs = $conn->prepare("SELECT DISTINCT item_family FROM items WHERE dimension_label_fk=? AND item_family IS NOT NULL AND item_family!='' ORDER BY item_family");
        $fs->bind_param("i", $filter_dimension);
        $fs->execute();
        $fr = $fs->get_result();
        while ($row = $fr->fetch_assoc()) $families[] = $row['item_family'];
        $fs->close();
    }
} else {
    $fr = $conn->query("SELECT DISTINCT item_family FROM items WHERE item_family IS NOT NULL AND item_family!='' ORDER BY item_family");
    if ($fr) while ($row = $fr->fetch_assoc()) $families[] = $row['item_family'];
}

// Fetch distinct item_family_2 values — only when a specific family is selected
$families2 = [];
if ($filter_family !== 'all') {
    $fam2_query = "SELECT DISTINCT item_family_2 FROM items WHERE item_family_2 IS NOT NULL AND item_family_2 != ''";
    $fam2_params = [];
    $fam2_types = "";
    $fam2_conditions = [];

    if ($filter_dimension !== 'all') {
        if ($filter_dimension === 'fixed_modular') {
            $fam2_conditions[] = "is_fixed_modular = 1";
        } else {
            $fam2_conditions[] = "dimension_label_fk = ?";
            $fam2_params[] = $filter_dimension;
            $fam2_types .= "i";
        }
    }
    $fam2_conditions[] = "item_family = ?";
    $fam2_params[] = $filter_family;
    $fam2_types .= "s";

    if (!empty($fam2_conditions)) {
        $fam2_query .= " AND " . implode(" AND ", $fam2_conditions);
    }
    $fam2_query .= " ORDER BY item_family_2";
    $fam2_stmt = $conn->prepare($fam2_query);
    if (!empty($fam2_params)) {
        $fam2_stmt->bind_param($fam2_types, ...$fam2_params);
    }
    $fam2_stmt->execute();
    $fam2_result = $fam2_stmt->get_result();
    while ($row = $fam2_result->fetch_assoc()) {
        $families2[] = $row['item_family_2'];
    }
    $fam2_stmt->close();
}

// Items
$items_query  = "SELECT i.item_id, i.item_code, i.item_name, i.item_image_path, i.item_family, i.item_family_2, i.is_fixed_modular, dl.dimension_label_name FROM items i LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id";
$conditions   = [];
$params       = [];
$types        = "";

if ($filter_dimension !== 'all') {
    if ($filter_dimension === 'fixed_modular') {
        $conditions[] = "i.is_fixed_modular = 1";
    } else {
        $conditions[] = "i.dimension_label_fk = ?";
        $params[]     = $filter_dimension;
        $types       .= "i";
    }
}
if ($filter_family !== 'all') {
    $conditions[] = "i.item_family = ?";
    $params[]     = $filter_family;
    $types       .= "s";
}
if ($filter_family2 !== 'all') {
    $conditions[] = "i.item_family_2 = ?";
    $params[]     = $filter_family2;
    $types       .= "s";
}
if (!empty($conditions)) $items_query .= " WHERE " . implode(" AND ", $conditions);
$items_query .= " ORDER BY i.item_name ASC";

$si = $conn->prepare($items_query);
if (!empty($params)) $si->bind_param($types, ...$params);
$si->execute();
$ir    = $si->get_result();
$items = [];
if ($ir) while ($row = $ir->fetch_assoc()) $items[] = $row;
$si->close();

// All addons
$addons = [];
$ar     = $conn->query("SELECT id as addon_id, addon_name, addon_price, addon_category, addon_type, addon_description, addon_image_path FROM product_addons ORDER BY addon_category, addon_name ASC");
if ($ar) while ($row = $ar->fetch_assoc()) $addons[] = $row;

// Addons grouped by category for sidebar
$addons_by_category = [];
foreach ($addons as $addon) {
    $cat = $addon['addon_category'] ?: 'Uncategorized';
    if (!isset($addons_by_category[$cat])) $addons_by_category[$cat] = [];
    $addons_by_category[$cat][] = $addon;
}

// Currently linked products for selected addon
$linked_item_ids  = [];
$linked_meta      = [];
$addon_meta       = ['is_required' => 0, 'max_quantity' => null]; // <-- ADD THIS
if ($selected_addon_id > 0) {
    $lq = $conn->prepare("SELECT item_id, is_required, max_quantity FROM product_addon_links WHERE addon_id = ?");
    $lq->bind_param("i", $selected_addon_id);
    $lq->execute();
    $lr = $lq->get_result();
    while ($row = $lr->fetch_assoc()) {
        $linked_item_ids[]            = $row['item_id'];
        $linked_meta[$row['item_id']] = $row;
        $addon_meta = $row; // <-- ADD THIS (keeps last/any row's is_required & max_quantity)
    }
    $lq->close();
}

// Selected addon details
$selected_addon = null;
if ($selected_addon_id > 0) {
    foreach ($addons as $a) {
        if ($a['addon_id'] == $selected_addon_id) { $selected_addon = $a; break; }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Product Accessories · RealLiving</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --adm-bg:      #F5F5F5;
            --adm-surface: #FFFFFF;
            --adm-ink:     #0B0B0B;
            --adm-soft:    #6B6B6B;
            --adm-muted:   #9A9A9A;
            --adm-line:    #E2E2E2;
            --success:     #16A34A;
            --error:       #DC2626;
            --gold:        #B45309;
            --sidebar-w:  300px;
            --mid-w:      360px;
            --radius:     12px;
            --shadow-sm:  0 1px 3px rgba(0,0,0,.06);
            --shadow-md:  0 4px 16px rgba(11,11,11,.08);
            --shadow-lg:  0 16px 34px -14px rgba(11,11,11,.30);
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--adm-bg);
            color: var(--adm-ink);
            min-height: 100vh;
        }

        /* ── App shell: topbar + 3-column layout ── */
        .app-shell {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 120px); /* adjust 120px to match your admin nav height */
        }

        .page-topbar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 22px;
            background: var(--adm-surface);
            border-bottom: 1px solid var(--adm-line);
        }
        .page-topbar .tb-left { display: flex; flex-direction: column; gap: 2px; }
        .adm-back {
            font-size: 12.5px; font-weight: 600; color: var(--adm-soft);
            display: inline-flex; align-items: center; gap: 8px;
            transition: color .2s ease, gap .2s ease;
            width: fit-content;
        }
        .adm-back:hover { color: var(--adm-ink); gap: 11px; }
        .tb-title { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; color: var(--adm-ink); margin-top: 4px; }
        .tb-sub { font-size: 12px; color: var(--adm-muted); }

        .layout {
            display: grid;
            grid-template-columns: var(--sidebar-w) var(--mid-w) 1fr;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        /* ── Panel shared ── */
        .panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            background: var(--adm-surface);
            border-right: 1px solid var(--adm-line);
        }
        .panel-head {
            padding: 18px 20px 14px;
            border-bottom: 1px solid var(--adm-line);
            flex-shrink: 0;
        }
        .panel-head h2 {
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--adm-soft);
            margin-bottom: 12px;
        }
        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }
        .panel-body::-webkit-scrollbar { width: 4px; }
        .panel-body::-webkit-scrollbar-track { background: transparent; }
        .panel-body::-webkit-scrollbar-thumb { background: var(--adm-line); border-radius: 4px; }

        /* ── Search ── */
        .search-wrap { position: relative; }
        .search-wrap input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1.5px solid var(--adm-line);
            border-radius: 8px;
            font-size: .85rem;
            font-family: inherit;
            color: var(--adm-ink);
            background: var(--adm-bg);
            outline: none;
            transition: border-color .2s;
        }
        .search-wrap input:focus { border-color: var(--adm-ink); background: #fff; }
        .search-wrap .fa-search {
            position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
            color: var(--adm-muted); font-size: .8rem;
        }

        /* ── Filter pills ── */
        .filter-row {
            display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px;
        }
        .filter-select {
            flex: 1; min-width: 110px;
            padding: 7px 10px;
            border: 1.5px solid var(--adm-line);
            border-radius: 8px;
            font-size: .8rem;
            font-family: inherit;
            font-weight: 600;
            color: var(--adm-ink);
            background: var(--adm-bg);
            outline: none;
            appearance: none;
            cursor: pointer;
            transition: border-color .2s;
        }
        .filter-select:focus { border-color: var(--adm-ink); }
        .btn-clear-filter {
            padding: 7px 10px;
            border: 1.5px solid var(--error);
            background: transparent;
            color: var(--error);
            border-radius: 8px;
            font-size: .75rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all .2s;
        }
        .btn-clear-filter:hover { background: var(--error); color: #fff; }

        /* ── Category group ── */
        .cat-group { margin-bottom: 8px; }
        .cat-label {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--adm-muted);
            padding: 8px 6px 4px;
        }

        /* ── Addon row (left sidebar) ── */
        .addon-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 9px;
            cursor: pointer;
            transition: background .15s;
            border: 1.5px solid transparent;
        }
        .addon-row:hover { background: var(--adm-bg); }
        .addon-row.active {
            background: var(--adm-bg);
            border-color: var(--adm-ink);
        }
        .addon-row img, .addon-row .img-ph {
            width: 40px; height: 40px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .addon-row .img-ph {
            background: var(--adm-bg);
            display: flex; align-items: center; justify-content: center;
            color: var(--adm-line); font-size: 1rem;
        }
        .addon-row .addon-info { flex: 1; min-width: 0; }
        .addon-row .addon-info .name {
            font-size: .85rem; font-weight: 700; color: var(--adm-ink);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .addon-row .addon-info .sub {
            font-size: .73rem; color: var(--adm-muted); margin-top: 1px;
        }
        .addon-row .badge-linked {
            background: var(--adm-ink);
            color: #fff;
            font-size: .65rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            flex-shrink: 0;
        }

        /* ── Middle: product picker ── */
        .mid-panel { background: var(--adm-bg); border-right: 1px solid var(--adm-line); }
        .mid-panel .panel-head { background: var(--adm-bg); }

        .empty-state {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; gap: 14px; color: var(--adm-muted); padding: 40px;
            text-align: center;
        }
        .empty-state .big-icon { font-size: 3rem; opacity: .2; }
        .empty-state p { font-size: .9rem; line-height: 1.5; }

        /* ── Product checkbox card ── */
        .prod-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1.5px solid var(--adm-line);
            background: var(--adm-surface);
            margin-bottom: 8px;
            cursor: pointer;
            transition: all .18s;
            user-select: none;
        }
        .prod-card:hover { border-color: var(--adm-ink); box-shadow: var(--shadow-sm); }
        .prod-card.checked {
            border-color: var(--adm-ink);
            background: var(--adm-bg);
        }
        .prod-card img, .prod-card .img-ph {
            width: 48px; height: 48px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .prod-card .img-ph {
            background: var(--adm-bg);
            display: flex; align-items: center; justify-content: center;
            color: var(--adm-line); font-size: 1.1rem;
        }
        .prod-card .prod-info { flex: 1; min-width: 0; }
        .prod-card .prod-info .name {
            font-size: .87rem; font-weight: 700; color: var(--adm-ink);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .prod-card .prod-info .code { font-size: .73rem; color: var(--adm-muted); margin-top: 2px; }
        .prod-card .prod-info .badges { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
        .prod-card .prod-info .pill {
            font-size: .65rem; font-weight: 700;
            padding: 2px 7px; border-radius: 20px;
        }
        .pill-blue  { background: var(--adm-ink); color: #fff; }
        .pill-amber { background: var(--gold); color: #fff; }
        .pill-gray  { background: var(--adm-bg); color: var(--adm-soft); border: 1px solid var(--adm-line); }
        .pill-variant { background: #F5F5F5; color: var(--adm-ink); border: 1px dashed var(--adm-line); }

        /* custom checkbox */
        .chk-box {
            width: 20px; height: 20px; flex-shrink: 0;
            border: 2px solid var(--adm-line);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            transition: all .15s;
            background: #fff;
        }
        .prod-card.checked .chk-box {
            background: var(--adm-ink);
            border-color: var(--adm-ink);
            color: #fff;
        }
        .chk-box i { font-size: .7rem; display: none; }
        .prod-card.checked .chk-box i { display: block; }

        /* ── Right: save panel ── */
        .save-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            background: var(--adm-surface);
        }
        .save-panel .panel-head { background: #fff; }

        .addon-detail-card {
            background: var(--adm-bg);
            border: 1px solid var(--adm-line);
            border-left: 3px solid var(--adm-ink);
            border-radius: var(--radius);
            padding: 16px;
            display: flex;
            gap: 14px;
            margin-bottom: 20px;
        }
        .addon-detail-card img, .addon-detail-card .img-ph {
            width: 72px; height: 72px;
            border-radius: 10px; object-fit: cover; flex-shrink: 0;
        }
        .addon-detail-card .img-ph {
            background: #fff;
            border: 1px solid var(--adm-line);
            display: flex; align-items: center; justify-content: center;
            color: var(--adm-muted); font-size: 1.6rem;
        }
        .addon-detail-card .detail-name {
            font-size: 1.05rem; font-weight: 700; margin-bottom: 4px; color: var(--adm-ink);
        }
        .addon-detail-card .detail-meta {
            font-size: .8rem; color: var(--adm-soft); display: flex; gap: 10px; flex-wrap: wrap;
        }
        .addon-detail-card .detail-price {
            font-size: 1.05rem; font-weight: 700; color: var(--adm-ink); margin-top: 4px;
        }

        /* Form fields */
        .field-group { margin-bottom: 16px; }
        .field-group label {
            display: block; font-size: .75rem; font-weight: 700;
            color: var(--adm-soft); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .04em;
        }
        .field-group input[type="number"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--adm-line);
            border-radius: 9px;
            font-size: .88rem;
            font-family: inherit;
            color: var(--adm-ink);
            background: var(--adm-bg);
            outline: none;
            transition: border-color .2s;
        }
        .field-group input[type="number"]:focus { border-color: var(--adm-ink); background: #fff; }

        .toggle-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 16px;
            background: var(--adm-bg);
            border-radius: 9px;
            border: 1.5px solid var(--adm-line);
        }
        .toggle-label { font-size: .88rem; font-weight: 600; color: var(--adm-ink); }
        .toggle-sub   { font-size: .75rem; color: var(--adm-muted); margin-top: 2px; }

        /* toggle */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc; border-radius: 24px;
            transition: .3s;
        }
        .slider:before {
            position: absolute; content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%;
            transition: .3s;
            box-shadow: 0 1px 4px rgba(0,0,0,.2);
        }
        input:checked + .slider { background: var(--adm-ink); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* Summary bar */
        .summary-bar {
            padding: 12px 16px;
            background: var(--adm-bg);
            border-radius: 9px;
            border: 1px solid var(--adm-line);
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .summary-bar .count { font-size: .88rem; font-weight: 600; color: var(--adm-ink); }
        .summary-bar .count span { color: var(--adm-ink); font-size: 1.1rem; font-weight: 800; }
        .btn-deselect {
            font-size: .78rem; color: var(--adm-soft);
            background: none; border: 1px solid var(--adm-line);
            padding: 5px 10px; border-radius: 7px; cursor: pointer;
            transition: all .2s;
        }
        .btn-deselect:hover { border-color: var(--error); color: var(--error); }

        /* Save button */
        .btn-save {
            width: 100%;
            padding: 14px;
            background: var(--adm-ink);
            color: #fff;
            border: 1px solid var(--adm-ink);
            border-radius: 10px;
            font-size: .92rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all .2s;
        }
        .btn-save:hover {
            opacity: .85;
            transform: translateY(-1px);
        }
        .btn-save:active { transform: translateY(0); }

        /* Toast */
        #toast {
            position: fixed; bottom: 28px; right: 28px;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: .88rem; font-weight: 600;
            display: flex; align-items: center; gap: 10px;
            box-shadow: var(--shadow-lg);
            transform: translateY(80px);
            opacity: 0;
            transition: all .35s cubic-bezier(.34,1.56,.64,1);
            z-index: 9999; pointer-events: none;
        }
        #toast.show { transform: translateY(0); opacity: 1; }
        #toast.success { background: var(--success); color: #fff; }
        #toast.error   { background: var(--error);   color: #fff; }

        /* Select all bar */
        .select-all-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 12px;
            background: var(--adm-surface);
            border: 1px solid var(--adm-line);
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .btn-sel-all {
            font-size: .78rem; font-weight: 700; color: var(--adm-ink);
            background: none; border: none; cursor: pointer;
            padding: 4px 8px; border-radius: 6px;
            transition: background .15s;
        }
        .btn-sel-all:hover { background: var(--adm-bg); }
        .sel-count-sm { font-size: .78rem; color: var(--adm-muted); font-weight: 600; }

        /* Divider */
        .divider {
            border: none; border-top: 1px solid var(--adm-line); margin: 16px 0;
        }

        /* Linked products list (inside save panel) */
        .linked-list { display: flex; flex-direction: column; gap: 6px; }
        .linked-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 10px;
            background: var(--adm-bg);
            border: 1px solid var(--adm-line);
            border-radius: 8px;
            font-size: .82rem;
        }
        .linked-item img, .linked-item .lph {
            width: 28px; height: 28px; border-radius: 5px; object-fit: cover; flex-shrink: 0;
        }
        .linked-item .lph {
            background: #fff; border: 1px solid var(--adm-line); display: flex; align-items: center; justify-content: center;
            color: var(--adm-muted); font-size: .7rem;
        }
        .linked-item .lname { flex: 1; font-weight: 600; color: var(--adm-ink); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* No-addon-selected */
        .no-addon {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; gap: 12px; color: var(--adm-muted); padding: 40px; text-align: center;
        }

        /* Responsive fallback */
        @media (max-width: 900px) {
            .app-shell { height: auto; }
            .layout { grid-template-columns: 260px 1fr; height: auto; overflow: auto; }
            .save-panel { display: none; }
        }
        @media (max-width: 600px) {
            .layout { grid-template-columns: 1fr; }
            .mid-panel { display: none; }
        }
    </style>
</head>
<body>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div id="toast"></div>

<?php if (!empty($message)): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => showToast(<?php echo json_encode($message); ?>, '<?php echo $messageType; ?>'));
</script>
<?php endif; ?>

<div class="app-shell">

    <!-- ── Top bar ─────────────────────────────────────────────── -->
    <div class="page-topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>choose" class="adm-back">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
            <div class="tb-title">Link Product Accessories</div>
        </div>
        <div class="tb-sub">Select an accessory, then choose which products it applies to.</div>
    </div>

    <!-- ── 3-column layout ─────────────────────────────────────────── -->
    <div class="layout">

        <!-- ══════════════════════════════════════════════════════════
             COL 1 · ADD-ON LIST
        ══════════════════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-head">
                <h2><i class="fas fa-puzzle-piece" style="margin-right:6px;"></i>Accessories</h2>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="addonSearch" placeholder="Search accessories…" oninput="filterAddonList()">
                </div>
            </div>
            <div class="panel-body" id="addonListBody">
                <?php foreach ($addons_by_category as $cat => $cat_addons): ?>
                <div class="cat-group" data-cat="<?php echo htmlspecialchars($cat); ?>">
                    <div class="cat-label"><?php echo htmlspecialchars(ucfirst($cat)); ?></div>
                    <?php foreach ($cat_addons as $a): 
                        // count linked products for this addon
                        $lcount_q = $conn ?? null; // connection already closed, count from PHP
                        $cnt = 0; // we'll compute via JS or show from pre-fetch
                    ?>
                    <div class="addon-row <?php echo ($selected_addon_id == $a['addon_id']) ? 'active' : ''; ?>"
                         data-id="<?php echo $a['addon_id']; ?>"
                         data-name="<?php echo htmlspecialchars($a['addon_name']); ?>"
                         data-cat="<?php echo htmlspecialchars($cat); ?>"
                         onclick="selectAddon(<?php echo $a['addon_id']; ?>)">
                        <?php if (!empty($a['addon_image_path'])): ?>
                            <img src="../../realiving_user/images/product_addons/<?php echo htmlspecialchars($a['addon_image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($a['addon_name']); ?>">
                        <?php else: ?>
                            <div class="img-ph"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <div class="addon-info">
                            <div class="name"><?php echo htmlspecialchars($a['addon_name']); ?></div>
                            <div class="sub">₱<?php echo number_format($a['addon_price'], 2); ?><?php if (!empty($a['addon_type'])) echo ' · ' . htmlspecialchars(ucfirst($a['addon_type'])); ?></div>
                        </div>
                        <span class="badge-linked" id="badge_<?php echo $a['addon_id']; ?>" style="display:none;">0</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($addons)): ?>
                    <div class="empty-state">
                        <div class="big-icon"><i class="fas fa-inbox"></i></div>
                        <p>No accessories found.<br>Create accessories first.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             COL 2 · PRODUCT PICKER
        ══════════════════════════════════════════════════════════════ -->
        <div class="panel mid-panel">
            <div class="panel-head">
                <h2><i class="fas fa-box" style="margin-right:6px;"></i>Products
                    <?php if ($selected_addon): ?>
                    <span style="color:var(--adm-ink);text-transform:none;font-size:.85rem;font-weight:700;"> — <?php echo htmlspecialchars($selected_addon['addon_name']); ?></span>
                    <?php endif; ?>
                </h2>
                <!-- Filters -->
                <div class="search-wrap" style="margin-bottom:8px;">
                    <i class="fas fa-search"></i>
                    <input type="text" id="prodSearch" placeholder="Search products…" oninput="filterProdList()">
                </div>
                <div class="filter-row">
                    <select class="filter-select" id="dimFilter" onchange="applyProdFilter()">
                        <option value="all">All Categories</option>
                        <?php foreach ($dimensions as $d): ?>
                        <option value="<?php echo htmlspecialchars($d['dimension_label_id']); ?>"
                            <?php echo $filter_dimension == $d['dimension_label_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['dimension_label_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($families)): ?>
    <select class="filter-select" id="famFilter" onchange="applyProdFilter()">
        <option value="all">All Families</option>
        <?php foreach ($families as $f): ?>
        <option value="<?php echo htmlspecialchars($f); ?>"
            <?php echo $filter_family === $f ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($f); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($families2)): ?>
    <select class="filter-select" id="fam2Filter" onchange="applyProdFilter()">
        <option value="all">All Variants</option>
        <?php foreach ($families2 as $f2): ?>
        <option value="<?php echo htmlspecialchars($f2); ?>"
            <?php echo $filter_family2 === $f2 ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($f2); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
                    <?php if ($filter_dimension !== 'all' || $filter_family !== 'all' || $filter_family2 !== 'all'): ?>
    <button class="btn-clear-filter" onclick="clearProdFilters()"><i class="fas fa-times"></i></button>
    <?php endif; ?>
                </div>
            </div>

            <?php if ($selected_addon_id > 0): ?>
            <div class="panel-body" id="prodListBody">
                <div class="select-all-bar">
                    <button class="btn-sel-all" onclick="selectAll()"><i class="fas fa-check-double"></i> Select All</button>
                    <span class="sel-count-sm" id="selCountSmall">0 selected</span>
                </div>
                <?php foreach ($items as $item): 
                    $is_checked = in_array($item['item_id'], $linked_item_ids);
                ?>
                <div class="prod-card <?php echo $is_checked ? 'checked' : ''; ?>"
                     data-id="<?php echo $item['item_id']; ?>"
                     data-name="<?php echo htmlspecialchars(strtolower($item['item_name'])); ?>"
                     data-code="<?php echo htmlspecialchars(strtolower($item['item_code'])); ?>"
                     data-dim="<?php echo htmlspecialchars($item['dimension_label_name'] ?? ''); ?>"
                     data-fam="<?php echo htmlspecialchars($item['item_family'] ?? ''); ?>"
                     onclick="toggleProduct(this)">
                    <div class="chk-box"><i class="fas fa-check"></i></div>
                    <?php if (!empty($item['item_image_path'])): ?>
                        <img src="../../realiving_user/images/products/<?php echo htmlspecialchars($item['item_image_path']); ?>"
                             alt="<?php echo htmlspecialchars($item['item_name']); ?>">
                    <?php else: ?>
                        <div class="img-ph"><i class="fas fa-box"></i></div>
                    <?php endif; ?>
                    <div class="prod-info">
                        <div class="name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                        <div class="code"><?php echo htmlspecialchars($item['item_code']); ?></div>
                        <div class="badges">
                            <?php if (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1): ?>
                                <span class="pill pill-amber"><i class="fas fa-lock"></i> Fixed</span>
                            <?php elseif (!empty($item['dimension_label_name'])): ?>
                                <span class="pill pill-blue"><?php echo htmlspecialchars($item['dimension_label_name']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['item_family'])): ?>
    <span class="pill pill-gray"><?php echo htmlspecialchars($item['item_family']); ?></span>
<?php endif; ?>
<?php if (!empty($item['item_family_2'])): ?>
    <span class="pill pill-variant"><?php echo htmlspecialchars($item['item_family_2']); ?></span>
<?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <div class="empty-state"><div class="big-icon"><i class="fas fa-inbox"></i></div><p>No products match your filters.</p></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="big-icon"><i class="fas fa-arrow-left"></i></div>
                <p>Select an <strong>accessory</strong> from the left panel to see and pick products.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             COL 3 · SAVE PANEL
        ══════════════════════════════════════════════════════════════ -->
        <div class="save-panel">
            <div class="panel-head">
                <h2><i class="fas fa-sliders-h" style="margin-right:6px;"></i>Configuration & Save</h2>
            </div>

            <?php if ($selected_addon): ?>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="save_addon_products" value="1">
    <input type="hidden" name="addon_id" value="<?php echo $selected_addon['addon_id']; ?>">
    <!-- hidden checkboxes written by JS -->
    <div id="hiddenProductInputs"></div>
    <!-- tracks which items were visible in the current filter -->
    <div id="hiddenVisibleInputs">
        <?php foreach ($items as $item): ?>
        <input type="hidden" name="visible_item_ids[]" value="<?php echo $item['item_id']; ?>">
        <?php endforeach; ?>
    </div>

                    <!-- Addon detail -->
                    <div class="addon-detail-card">
                        <?php if (!empty($selected_addon['addon_image_path'])): ?>
                            <img src="../../realiving_user/images/product_addons/<?php echo htmlspecialchars($selected_addon['addon_image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($selected_addon['addon_name']); ?>">
                        <?php else: ?>
                            <div class="img-ph"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <div>
                            <div class="detail-name"><?php echo htmlspecialchars($selected_addon['addon_name']); ?></div>
                            <div class="detail-meta">
                                <?php if (!empty($selected_addon['addon_category'])): ?>
                                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars(ucfirst($selected_addon['addon_category'])); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($selected_addon['addon_type'])): ?>
                                    <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars(ucfirst($selected_addon['addon_type'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($selected_addon['addon_description'])): ?>
                                <div style="font-size:.78rem;color:var(--adm-muted);margin-top:4px;"><?php echo htmlspecialchars($selected_addon['addon_description']); ?></div>
                            <?php endif; ?>
                            <div class="detail-price">₱<?php echo number_format($selected_addon['addon_price'], 2); ?></div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="summary-bar">
                        <div class="count">Products linked: <span id="totalCount">0</span></div>
                        <button type="button" class="btn-deselect" onclick="deselectAll()"><i class="fas fa-times"></i> Clear all</button>
                    </div>

                    <!-- Options -->
                    <div class="field-group">
                        <label>Max Quantity per order</label>
                        <input type="number" name="max_quantity" min="1" placeholder="Leave blank for unlimited"
                               value="<?php 
                                    echo htmlspecialchars($addon_meta['max_quantity'] ?? '');
                               ?>">
                    </div>

                    <div class="field-group">
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Currently used accessory</div>
                                <div class="toggle-sub">Mark as the default active add-on for linked products</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="is_required" value="1"
        <?php echo $addon_meta['is_required'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <hr class="divider">

                    <!-- Linked products preview -->
                    <div class="field-group">
                        <label>Currently linked products</label>
                        <div class="linked-list" id="linkedPreview">
                            <?php if (empty($linked_item_ids)): ?>
                                <div style="font-size:.82rem;color:var(--adm-muted);">No products linked yet.</div>
                            <?php else: ?>
                                <?php foreach ($items as $item): 
                                    if (!in_array($item['item_id'], $linked_item_ids)) continue;
                                ?>
                                <div class="linked-item" data-id="<?php echo $item['item_id']; ?>">
                                    <?php if (!empty($item['item_image_path'])): ?>
                                        <img src="../../realiving_user/images/products/<?php echo htmlspecialchars($item['item_image_path']); ?>">
                                    <?php else: ?>
                                        <div class="lph"><i class="fas fa-box"></i></div>
                                    <?php endif; ?>
                                    <span class="lname"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <i class="fas fa-check" style="color:var(--adm-ink);font-size:.7rem;"></i>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr class="divider">

                    <button type="submit" class="btn-save" onclick="syncHiddenInputs()">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="no-addon">
                <div style="font-size:2.5rem;opacity:.2;"><i class="fas fa-mouse-pointer"></i></div>
                <p style="font-size:.9rem;color:var(--adm-muted);">Select an accessory to configure and save its linked products.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /layout -->
</div><!-- /app-shell -->

<!-- ── Data ──────────────────────────────────────────────────────── -->
<script>
    // PHP data bridges
    const LINKED_IDS    = <?php echo json_encode($linked_item_ids); ?>;
    const SELECTED_ADDON = <?php echo $selected_addon_id; ?>;
    const PHP_SELF       = <?php echo json_encode($_SERVER['PHP_SELF']); ?>;

    // all items as JSON for the linked-preview update
    const ALL_ITEMS = <?php echo json_encode(array_map(function($i){ return [
        'id'    => $i['item_id'],
        'name'  => $i['item_name'],
        'code'  => $i['item_code'],
        'img'   => $i['item_image_path'] ?? '',
    ]; }, $items)); ?>;
</script>

<script>
    /* ── Toast ─────────────────────────────────────────── */
    function showToast(msg, type = 'success') {
        const t = document.getElementById('toast');
        t.textContent = '';
        t.className = '';
        const icon = document.createElement('i');
        icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
        t.appendChild(icon);
        t.appendChild(document.createTextNode(' ' + msg));
        t.classList.add(type, 'show');
        setTimeout(() => t.classList.remove('show'), 4000);
    }

    /* ── Navigate to addon ─────────────────────────────── */
    function selectAddon(addonId) {
        const url = new URL(window.location.href);
        url.searchParams.set('addon_id', addonId);
        window.location.href = url.toString();
    }

    /* ── Toggle product card ───────────────────────────── */
    function toggleProduct(card) {
        card.classList.toggle('checked');
        updateCounts();
        updateLinkedPreview();
    }

    function selectAll() {
        document.querySelectorAll('.prod-card:not([style*="display: none"])').forEach(c => c.classList.add('checked'));
        updateCounts();
        updateLinkedPreview();
    }

    function deselectAll() {
        document.querySelectorAll('.prod-card').forEach(c => c.classList.remove('checked'));
        updateCounts();
        updateLinkedPreview();
    }

    function getCheckedIds() {
        return [...document.querySelectorAll('.prod-card.checked')].map(c => parseInt(c.dataset.id));
    }

    function updateCounts() {
        const n = getCheckedIds().length;
        const tc = document.getElementById('totalCount');
        const sc = document.getElementById('selCountSmall');
        if (tc) tc.textContent = n;
        if (sc) sc.textContent = n + ' selected';
    }

    /* ── Update linked preview in right panel ──────────── */
    function updateLinkedPreview() {
        const preview = document.getElementById('linkedPreview');
        if (!preview) return;
        const ids = getCheckedIds();
        if (ids.length === 0) {
            preview.innerHTML = '<div style="font-size:.82rem;color:var(--adm-muted);">No products linked yet.</div>';
            return;
        }
        preview.innerHTML = '';
        ids.forEach(id => {
            const item = ALL_ITEMS.find(i => i.id === id);
            if (!item) return;
            const div = document.createElement('div');
            div.className = 'linked-item';
            div.dataset.id = id;
            const imgHtml = item.img
                ? `<img src="../../realiving_user/images/products/${item.img}">`
                : `<div class="lph"><i class="fas fa-box"></i></div>`;
            div.innerHTML = `${imgHtml}<span class="lname">${item.name}</span><i class="fas fa-check" style="color:var(--adm-ink);font-size:.7rem;"></i>`;
            preview.appendChild(div);
        });
    }

    /* ── Sync hidden inputs before submit ──────────────── */
    function syncHiddenInputs() {
        const container = document.getElementById('hiddenProductInputs');
        if (!container) return;
        container.innerHTML = '';
        getCheckedIds().forEach(id => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'product_ids[]';
            inp.value = id;
            container.appendChild(inp);
        });
    }

    /* ── Filter: addon list ────────────────────────────── */
    function filterAddonList() {
        const q = document.getElementById('addonSearch').value.toLowerCase();
        document.querySelectorAll('.addon-row').forEach(row => {
            const match = row.dataset.name.toLowerCase().includes(q) || row.dataset.cat.toLowerCase().includes(q);
            row.style.display = match ? '' : 'none';
        });
        // hide empty category groups
        document.querySelectorAll('.cat-group').forEach(g => {
            const visible = [...g.querySelectorAll('.addon-row')].some(r => r.style.display !== 'none');
            g.style.display = visible ? '' : 'none';
        });
    }

    /* ── Filter: product list ──────────────────────────── */
    function filterProdList() {
        const q = document.getElementById('prodSearch').value.toLowerCase();
        document.querySelectorAll('.prod-card').forEach(card => {
            const match = card.dataset.name.includes(q) || card.dataset.code.includes(q);
            card.style.display = match ? '' : 'none';
        });
    }

    function applyProdFilter() {
    const dim  = document.getElementById('dimFilter').value;
    const fam  = document.getElementById('famFilter')  ? document.getElementById('famFilter').value  : 'all';
    const fam2 = document.getElementById('fam2Filter') ? document.getElementById('fam2Filter').value : 'all';
    const url  = new URL(window.location.href);
    url.searchParams.set('dimension', dim);
    url.searchParams.set('family', fam);
    url.searchParams.set('family2', fam2);
    if (SELECTED_ADDON) url.searchParams.set('addon_id', SELECTED_ADDON);
    window.location.href = url.toString();
}

    function clearProdFilters() {
    const url = new URL(window.location.href);
    url.searchParams.set('dimension', 'all');
    url.searchParams.set('family', 'all');
    url.searchParams.set('family2', 'all');
    if (SELECTED_ADDON) url.searchParams.set('addon_id', SELECTED_ADDON);
    window.location.href = url.toString();
}

    /* ── Init ──────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', () => {
        updateCounts();

        // Show badges on addon rows
        // We compute from the product cards that are pre-checked
        // For a more accurate count we'd need a server-side count per addon;
        // for now show the count for the active addon
        if (SELECTED_ADDON) {
            const badge = document.getElementById('badge_' + SELECTED_ADDON);
            if (badge) {
                const n = document.querySelectorAll('.prod-card.checked').length;
                if (n > 0) {
                    badge.textContent = n;
                    badge.style.display = 'inline-block';
                }
            }
        }
    });
</script>
</body>
</html>