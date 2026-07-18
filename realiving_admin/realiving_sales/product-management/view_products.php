<?php
//view_products.php
include $includes ['mainbody'];



// Allow only admin1 to admin5
require_role(['sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: ../../realiving_admin/tracker_site_visit/designer_layout_list.php");
        exit();
    }
}

// Check for success message from add_product_details.php or edit_product.php
$show_success = false;
$success_message = '';
$success_details_array = [];

if (isset($_SESSION['success_message'])) {
    $show_success = true;
    $success_message = $_SESSION['success_message'];
    if (isset($_SESSION['success_details'])) {
        $success_details_array = $_SESSION['success_details'];
    }
    // Clear session messages
    unset($_SESSION['success_message']);
    unset($_SESSION['success_details']);
}

// Get filter parameters
$filter_dimension = isset($_GET['dimension']) ? $_GET['dimension'] : 'all';
$filter_family = isset($_GET['family']) ? $_GET['family'] : 'all';
$filter_visibility = isset($_GET['visibility']) ? $_GET['visibility'] : 'visible'; // New: visible, hidden, or all

$filter_top = isset($_GET['top']) ? $_GET['top'] : 'all'; // New: all or top
$filter_material = isset($_GET['material']) ? $_GET['material'] : 'all';
$filter_door_material = isset($_GET['door_material']) ? $_GET['door_material'] : 'all';
$filter_family2 = isset($_GET['family2']) ? $_GET['family2'] : 'all';

// Get counts for filter buttons
$count_visible = 0;
$count_hidden = 0;
$count_top = 0;

// Build count query with current dimension and family filters
$count_query = "SELECT 
    SUM(CASE WHEN is_hidden = 0 THEN 1 ELSE 0 END) as visible_count,
    SUM(CASE WHEN is_hidden = 1 THEN 1 ELSE 0 END) as hidden_count,
    SUM(CASE WHEN is_top_product = 1 THEN 1 ELSE 0 END) as top_count
    FROM items i WHERE 1=1";

$count_conditions = [];
$count_params = [];
$count_types = "";

if ($filter_dimension !== 'all') {
    $count_conditions[] = "dimension_label_fk = ?";
    $count_params[] = $filter_dimension;
    $count_types .= "i";
}

if ($filter_family !== 'all') {
    $count_conditions[] = "item_family = ?";
    $count_params[] = $filter_family;
    $count_types .= "s";
}

if (!empty($count_conditions)) {
    $count_query .= " AND " . implode(" AND ", $count_conditions);
}

$count_stmt = $conn->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
if ($count_row = $count_result->fetch_assoc()) {
    $count_visible = (int)$count_row['visible_count'];
    $count_hidden = (int)$count_row['hidden_count'];
    $count_top = (int)$count_row['top_count'];
}
$count_stmt->close();

// Fetch all dimension labels for primary filter
$dimensions_query = "SELECT dimension_label_id, dimension_label_name FROM dimension_label ORDER BY dimension_label_name";
$dimensions_result = $conn->query($dimensions_query);
$dimensions = [];
if ($dimensions_result) {
    while ($row = $dimensions_result->fetch_assoc()) {
        $dimensions[] = $row;
    }
}

// Add Fixed Modular as a special category
$dimensions[] = ['dimension_label_id' => 'fixed_modular', 'dimension_label_name' => 'Fixed Modular'];

// Fetch item families that belong to the selected dimension (dynamic filtering)
$families = [];
if ($filter_dimension !== 'all') {
    if ($filter_dimension === 'fixed_modular') {
        // Get families for fixed modular items only
        $families_query = "SELECT DISTINCT item_family 
                           FROM items 
                           WHERE is_fixed_modular = 1 
                           AND item_family IS NOT NULL 
                           AND item_family != '' 
                           ORDER BY item_family";
        $families_result = $conn->query($families_query);
        if ($families_result) {
            while ($row = $families_result->fetch_assoc()) {
                $families[] = $row['item_family'];
            }
        }
    } else {
        // Get families for specific dimension
        $families_query = "SELECT DISTINCT item_family 
                           FROM items 
                           WHERE dimension_label_fk = ? 
                           AND item_family IS NOT NULL 
                           AND item_family != '' 
                           ORDER BY item_family";
        $stmt_families = $conn->prepare($families_query);
        $stmt_families->bind_param("i", $filter_dimension);
        $stmt_families->execute();
        $families_result = $stmt_families->get_result();
        while ($row = $families_result->fetch_assoc()) {
            $families[] = $row['item_family'];
        }
        $stmt_families->close();
    }
} else {
    // If no dimension selected, show all families
    $families_query = "SELECT DISTINCT item_family 
                       FROM items 
                       WHERE item_family IS NOT NULL 
                       AND item_family != '' 
                       ORDER BY item_family";
    $families_result = $conn->query($families_query);
    if ($families_result) {
        while ($row = $families_result->fetch_assoc()) {
            $families[] = $row['item_family'];
        }
    }
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

    // Always filter by the selected family
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

// Fetch distinct carcass materials
$materials = [];
$mat_query = "SELECT DISTINCT item_material FROM items WHERE item_material IS NOT NULL AND item_material != ''";
$mat_params = [];
$mat_types = "";
$mat_conditions = [];
if ($filter_dimension !== 'all') {
    if ($filter_dimension === 'fixed_modular') {
        $mat_conditions[] = "is_fixed_modular = 1";
    } else {
        $mat_conditions[] = "dimension_label_fk = ?";
        $mat_params[] = $filter_dimension;
        $mat_types .= "i";
    }
}
if ($filter_family !== 'all') {
    $mat_conditions[] = "item_family = ?";
    $mat_params[] = $filter_family;
    $mat_types .= "s";
}
if (!empty($mat_conditions)) {
    $mat_query .= " AND " . implode(" AND ", $mat_conditions);
}
$mat_query .= " ORDER BY item_material";
$mat_stmt = $conn->prepare($mat_query);
if (!empty($mat_params)) {
    $mat_stmt->bind_param($mat_types, ...$mat_params);
}
$mat_stmt->execute();
$mat_result = $mat_stmt->get_result();
while ($row = $mat_result->fetch_assoc()) {
    $materials[] = $row['item_material'];
}
$mat_stmt->close();

// Fetch distinct door materials
$door_materials = [];
$door_query = "SELECT DISTINCT door_material FROM items WHERE door_material IS NOT NULL AND door_material != ''";
$door_params = $mat_params;
$door_types = $mat_types;
$door_conditions = $mat_conditions;
if (!empty($door_conditions)) {
    $door_query .= " AND " . implode(" AND ", $door_conditions);
}
$door_query .= " ORDER BY door_material";
$door_stmt = $conn->prepare($door_query);
if (!empty($door_params)) {
    $door_stmt->bind_param($door_types, ...$door_params);
}
$door_stmt->execute();
$door_result = $door_stmt->get_result();
while ($row = $door_result->fetch_assoc()) {
    $door_materials[] = $row['door_material'];
}
$door_stmt->close();

// Build query based on filters
$query = "SELECT 
    i.item_id,
    i.item_code,
    i.item_name,
    i.item_family,
    i.item_material,
    i.door_material,
    i.item_color,
    i.item_image_path,
    i.non_project_price,
    i.project_price,
    i.jackup,
    i.mark_up,
    i.labor_cost,
    i.is_top_product,
    i.is_hidden,
    i.is_fixed_modular,
    dl.dimension_label_name,
    dl.dimension_label_id,
    dm.item_width_linear,
    dm.item_height_linear,
    dm.item_length_linear
FROM items i
LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id
LEFT JOIN dimension_measurement dm ON i.dimension_msmt_fk = dm.dimension_msmt_id";

$conditions = [];
$params = [];
$types = "";

// Visibility filter
if ($filter_visibility === 'visible') {
    $conditions[] = "i.is_hidden = 0";
} elseif ($filter_visibility === 'hidden') {
    $conditions[] = "i.is_hidden = 1";
}
// If 'all', don't add any visibility condition

// Top Products filter
if ($filter_top === 'top') {
    $conditions[] = "i.is_top_product = 1";
}
// If 'all', don't add any top product condition

if ($filter_dimension !== 'all') {
    if ($filter_dimension === 'fixed_modular') {
        $conditions[] = "i.is_fixed_modular = 1";
    } else {
        $conditions[] = "i.dimension_label_fk = ?";
        $params[] = $filter_dimension;
        $types .= "i";
    }
}

if ($filter_family !== 'all') {
    $conditions[] = "i.item_family = ?";
    $params[] = $filter_family;
    $types .= "s";
}

if ($filter_family2 !== 'all') {
    $conditions[] = "i.item_family_2 = ?";
    $params[] = $filter_family2;
    $types .= "s";
}

if ($filter_material !== 'all') {
    $conditions[] = "i.item_material = ?";
    $params[] = $filter_material;
    $types .= "s";
}

if ($filter_door_material !== 'all') {
    $conditions[] = "i.door_material = ?";
    $params[] = $filter_door_material;
    $types .= "s";
}

if (!empty($conditions)) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}

$query .= " ORDER BY i.item_name ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Inventory - RealLiving</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --adm-bg:#F5F5F5;
            --adm-surface:#FFFFFF;
            --adm-ink:#0B0B0B;
            --adm-soft:#6B6B6B;
            --adm-muted:#9A9A9A;
            --adm-line:#E2E2E2;
        }

        body{
            font-family:'Inter', sans-serif;
            background: var(--adm-bg);
            color: var(--adm-ink);
        }

        /* ── Header ─────────────────────────────── */
        .adm-eyebrow{ font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase; color: var(--adm-soft); }
        .adm-title{ font-size:26px; font-weight:700; letter-spacing:-0.01em; color: var(--adm-ink); }
        .adm-subtitle{ font-size:13px; color: var(--adm-soft); }
        .adm-back{
            font-size:12.5px; font-weight:600; color: var(--adm-soft);
            display:inline-flex; align-items:center; gap:8px;
            margin-bottom:1rem;
            transition: color .2s ease, gap .2s ease;
        }
        .adm-back:hover{ color: var(--adm-ink); gap:11px; }

        /* ── Buttons ────────────────────────────── */
        .adm-btn{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-ink); color:#fff;
            font-size:13px; font-weight:600;
            padding:.75rem 1.25rem; border-radius:9px;
            border:1px solid var(--adm-ink);
            transition: opacity .2s ease, transform .2s ease;
        }
        .adm-btn:hover{ opacity:.85; transform: translateY(-1px); color:#fff; }
        .adm-btn-outline{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-surface); color: var(--adm-ink);
            font-size:13px; font-weight:600;
            padding:.7rem 1.15rem; border-radius:9px;
            border:1px solid var(--adm-line);
            transition: border-color .2s ease, transform .2s ease;
        }
        .adm-btn-outline:hover{ border-color: var(--adm-ink); transform: translateY(-1px); }

        /* ── Cards / sections ───────────────────── */
        .adm-card{
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:12px;
        }

        /* ── Alert ──────────────────────────────── */
        .adm-alert-success{
            display:flex; align-items:flex-start; gap:.9rem;
            background: var(--adm-surface);
            border:1px solid var(--adm-line); border-left:3px solid #16A34A;
            border-radius:10px; padding:1.1rem 1.25rem;
        }
        .adm-alert-icon{
            width:36px; height:36px; border-radius:999px; flex-shrink:0;
            background:#ECFDF3; color:#16A34A;
            display:flex; align-items:center; justify-content:center; font-size:15px;
        }
        .adm-stat-chip{
            display:inline-flex; align-items:center; gap:6px;
            font-size:11.5px; font-weight:600; color: var(--adm-ink);
            background: var(--adm-bg); border:1px solid var(--adm-line);
            padding:.4rem .75rem; border-radius:8px;
        }
        .adm-stat-chip i{ color:#16A34A; }

        /* ── Filter pills ───────────────────────── */
        .adm-pill{
            display:inline-flex; align-items:center; gap:6px;
            font-size:12.5px; font-weight:600;
            padding:.55rem 1rem; border-radius:9px;
            border:1px solid var(--adm-line);
            background: var(--adm-bg); color: var(--adm-soft);
            transition: background .2s ease, color .2s ease, border-color .2s ease, transform .2s ease;
            cursor:pointer;
        }
        .adm-pill:hover{ transform: translateY(-1px); }
        .adm-pill.is-ink{ background: var(--adm-ink); color:#fff; border-color: var(--adm-ink); }
        .adm-pill.is-green{ background:#ECFDF3; color:#16A34A; border-color:#BBF7D0; }
        .adm-pill.is-red{ background:#FEF2F2; color:#DC2626; border-color:#FECACA; }
        .adm-pill.is-gold{ background:#FFFBEB; color:#B45309; border-color:#FDE68A; }
        .adm-pill-count{
            background: rgba(0,0,0,0.1); padding:.15rem .5rem; border-radius:999px; font-size:10.5px;
        }
        .adm-pill.is-ink .adm-pill-count,
        .adm-pill.is-green .adm-pill-count,
        .adm-pill.is-red .adm-pill-count,
        .adm-pill.is-gold .adm-pill-count{ background: rgba(255,255,255,0.3); }

        .adm-section-icon{
            width:34px; height:34px; border-radius:9px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
        }
        .adm-section-title{ font-size:15px; font-weight:700; color: var(--adm-ink); }
        .adm-section-hint{ font-size:12px; color: var(--adm-muted); }

        .secondary-filter{
            opacity:0; max-height:0; overflow:hidden;
            transition: opacity .3s ease, max-height .3s ease, margin-top .3s ease;
            margin-top:0;
        }
        .secondary-filter.show{ opacity:1; max-height:600px; margin-top:1.25rem; overflow:visible; }

        /* Floating family-variant dropdown */
        .adm-dd-menu{
            background: var(--adm-surface); border:1px solid var(--adm-line);
            border-radius:9px; box-shadow: 0 14px 30px -14px rgba(11,11,11,0.3);
        }
        .adm-dd-item{
            display:flex; align-items:center; gap:8px;
            padding:.65rem 1rem; font-size:12.5px; font-weight:600;
            color: var(--adm-soft); transition: background .15s ease, color .15s ease;
        }
        .adm-dd-item:hover{ background: var(--adm-bg); color: var(--adm-ink); }
        .adm-dd-item.is-active{ background: var(--adm-ink); color:#fff; }

        /* ── Material filter bar ───────────────── */
        .adm-material-bar{
            background: var(--adm-surface); border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:.9rem 1.1rem;
        }
        .adm-select{
            padding:.5rem .8rem; border-radius:8px;
            border:1px solid var(--adm-line); background: var(--adm-bg);
            font-size:12.5px; font-weight:600; color: var(--adm-ink);
            cursor:pointer; transition: border-color .2s ease;
        }
        .adm-select:focus{ outline:none; border-color: var(--adm-ink); }

        /* ── Active filters ─────────────────────── */
        .adm-active-filters{
            background: var(--adm-surface); border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:1rem 1.1rem;
        }
        .adm-chip-outline{
            display:inline-flex; align-items:center; gap:5px;
            font-size:11px; font-weight:600;
            padding:.35rem .7rem; border-radius:7px;
            background: var(--adm-bg); color: var(--adm-ink);
            border:1px solid var(--adm-line);
        }
        .adm-clear-link{
            display:inline-flex; align-items:center; gap:6px;
            font-size:12.5px; font-weight:600; color:#DC2626;
            background: var(--adm-surface); border:1px solid var(--adm-line);
            padding:.5rem .9rem; border-radius:8px;
            transition: background .2s ease, border-color .2s ease;
        }
        .adm-clear-link:hover{ background:#FEF2F2; border-color:#FECACA; }

        .adm-results-bar{
            background: var(--adm-surface); border:1px solid var(--adm-line);
            border-radius:10px; padding:.9rem 1.1rem;
        }

        /* ── Product cards ──────────────────────── */
        .product-card {
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
            background: var(--adm-surface);
            border-radius: 12px;
            border: 1px solid var(--adm-line);
            overflow: hidden;
            display:flex; flex-direction:column;
        }
        .product-card:hover {
            border-color: var(--adm-ink);
            transform: translateY(-4px);
            box-shadow: 0 16px 34px -20px rgba(11, 11, 11, 0.3);
        }
        .product-card.is-hidden{
            opacity: 0.55;
            border: 1px dashed #FECACA;
        }
        .product-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: var(--adm-bg);
            display:block;
        }
        .hidden-overlay {
            position: absolute; top: 10px; left: 10px;
            background: var(--adm-ink); color: white;
            padding: 5px 12px; border-radius: 7px;
            font-size: 10.5px; font-weight: 700; letter-spacing:.3px;
            z-index: 10;
        }
        .top-product-badge {
            position: absolute; top: 10px; right: 10px;
            background: #B45309; color: white;
            padding: 5px 10px; border-radius: 7px;
            font-size: 10px; font-weight: 700; letter-spacing:.3px;
            z-index: 10;
        }
        .badge {
            display: inline-flex; align-items:center; gap:4px;
            padding: 0.32rem 0.65rem;
            border-radius: 7px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: .2px;
        }
        .badge-category{ background: var(--adm-ink); color:#fff; }
        .badge-family{ background:#FFFFFF; color: var(--adm-ink); border:1px solid var(--adm-line); }

        .adm-card-body{ padding:1.1rem 1.25rem 1.25rem; display:flex; flex-direction:column; flex:1; }
        .adm-item-code{ font-size:10.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color: var(--adm-muted); margin-bottom:.35rem; }
        .adm-item-name{ font-size:14.5px; font-weight:700; color: var(--adm-ink); margin-bottom:.7rem; line-height:1.4;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .adm-spec-row{ display:flex; align-items:center; gap:6px; font-size:12px; color: var(--adm-soft); margin-bottom:.4rem; }
        .adm-spec-row i{ width:16px; color: var(--adm-muted); font-size:11px; }
        .adm-spec-label{ color: var(--adm-muted); font-weight:600; font-size:10.5px; text-transform:uppercase; }

        .adm-price-row{ display:grid; grid-template-columns:1fr 1fr; gap:.5rem; padding-top:.9rem; margin-top:.4rem; border-top:1px solid var(--adm-line); }
        .adm-price-label{ font-size:10.5px; color: var(--adm-muted); font-weight:600; text-transform:uppercase; }
        .adm-price-value{ font-size:15px; font-weight:700; color: var(--adm-ink); }

        .adm-action-row{ display:grid; grid-template-columns:1fr; gap:.5rem; margin-top:.85rem; }
        .adm-action-row.two-col{ grid-template-columns:1fr 1fr; }
        .adm-mini-btn{
            display:flex; align-items:center; justify-content:center; gap:6px;
            padding:.55rem .6rem; border-radius:8px;
            font-size:11.5px; font-weight:600;
            border:1px solid var(--adm-line); background: var(--adm-surface); color: var(--adm-ink);
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
        }
        .adm-mini-btn:hover{ transform: translateY(-1px); border-color: var(--adm-ink); }
        .adm-mini-btn.is-primary{ background: var(--adm-ink); color:#fff; border-color: var(--adm-ink); }
        .adm-mini-btn.is-primary:hover{ opacity:.85; }

        .toggle-container{ display:flex; gap:8px; margin-top:10px; }
        .toggle-btn {
            flex: 1;
            padding: 7px 8px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--adm-line);
            background: var(--adm-surface); color: var(--adm-soft);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex; align-items: center; justify-content: center; gap: 4px;
        }
        .toggle-btn:hover{ transform: translateY(-1px); box-shadow: 0 6px 14px -8px rgba(11,11,11,0.25); }
        .toggle-btn.active{ background:#FFFBEB; border-color:#FDE68A; color:#B45309; }
        .toggle-btn.inactive{ background: var(--adm-surface); border-color: var(--adm-line); color: var(--adm-soft); }
        .visibility-btn.visible{ background:#ECFDF3; border-color:#BBF7D0; color:#16A34A; }
        .visibility-btn.hidden{ background:#FEF2F2; border-color:#FECACA; color:#DC2626; }

        /* ── Empty state ─────────────────────────── */
        .adm-empty-state{
            background: var(--adm-surface); border:1px dashed var(--adm-line);
            border-radius:14px; padding:4rem 1.5rem; text-align:center;
        }
        .adm-empty-icon{
            width:80px; height:80px; border-radius:999px; background: var(--adm-bg);
            display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem;
            color: var(--adm-muted); font-size:2rem;
        }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-16px); } to { opacity: 1; transform: translateY(0); } }
        .success-alert { animation: slideDown 0.4s ease-out; }

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
    </style>
</head>

<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-7xl">

        <!-- Success Message -->
        <?php if ($show_success): ?>
        <div class="success-alert adm-alert-success mb-6" id="successAlert">
            <div class="adm-alert-icon"><i class="fas fa-check"></i></div>
            <div class="flex-1">
                <h3 class="text-sm font-bold mb-2" style="color:var(--adm-ink);">
                    <?php echo htmlspecialchars($success_message); ?>
                </h3>
                <?php if (!empty($success_details_array)): ?>
                <div class="flex flex-wrap gap-2 mt-2">
                    <?php if (isset($success_details_array['visual_assets']) && $success_details_array['visual_assets'] > 0): ?>
                    <span class="adm-stat-chip"><i class="fas fa-images"></i> <?php echo $success_details_array['visual_assets']; ?> Visual Asset(s)</span>
                    <?php endif; ?>
                    <?php if (isset($success_details_array['specifications']) && $success_details_array['specifications'] > 0): ?>
                    <span class="adm-stat-chip"><i class="fas fa-clipboard-list"></i> <?php echo $success_details_array['specifications']; ?> Specification(s)</span>
                    <?php endif; ?>
                    <?php if (isset($success_details_array['features']) && $success_details_array['features'] > 0): ?>
                    <span class="adm-stat-chip"><i class="fas fa-star"></i> <?php echo $success_details_array['features']; ?> Feature(s)</span>
                    <?php endif; ?>
                    <?php if (isset($success_details_array['sizes']) && $success_details_array['sizes'] > 0): ?>
                    <span class="adm-stat-chip"><i class="fas fa-ruler-combined"></i> <?php echo $success_details_array['sizes']; ?> Fixed Size(s)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <button onclick="closeSuccessAlert()" class="flex-shrink-0" style="color:var(--adm-muted);">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <a href="<?= BASE_URL ?>choose" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog</div>
                    <h1 class="adm-title">Product Inventory</h1>
                    <p class="adm-subtitle mt-1">Browse and manage your product catalog by category.</p>
                </div>
                <a href="<?= BASE_URL ?>add-product" class="adm-btn">
                    <i class="fas fa-plus"></i>
                    <span>Add New Product</span>
                </a>
            </div>

            <!-- Visibility & Top Products Filter Buttons -->
            <div class="mt-6 pt-6" style="border-top:1px solid var(--adm-line);">
                <div class="flex items-center gap-2 flex-wrap mb-3">
                    <span class="text-xs font-semibold mr-1" style="color:var(--adm-soft);">
                        <i class="fas fa-eye mr-1"></i> Visibility:
                    </span>
                    <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&visibility=visible&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                        class="adm-pill <?php echo $filter_visibility === 'visible' ? 'is-green' : ''; ?>">
                        <i class="fas fa-eye"></i> Visible Only
                        <span class="adm-pill-count"><?php echo $count_visible; ?></span>
                    </a>
                    <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&visibility=hidden&top=<?php echo urlencode($filter_top); ?>"
                        class="adm-pill <?php echo $filter_visibility === 'hidden' ? 'is-red' : ''; ?>">
                        <i class="fas fa-eye-slash"></i> Hidden Only
                        <span class="adm-pill-count"><?php echo $count_hidden; ?></span>
                    </a>
                    <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&visibility=all&top=<?php echo urlencode($filter_top); ?>"
                        class="adm-pill <?php echo $filter_visibility === 'all' ? 'is-ink' : ''; ?>">
                        <i class="fas fa-list"></i> Show All
                        <span class="adm-pill-count"><?php echo ($count_visible + $count_hidden); ?></span>
                    </a>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-semibold mr-1" style="color:var(--adm-soft);">
                        <i class="fas fa-star mr-1"></i> Featured:
                    </span>
                    <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=all&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                        class="adm-pill <?php echo $filter_top === 'all' ? 'is-ink' : ''; ?>">
                        <i class="fas fa-th"></i> All Products
                    </a>
                    <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=top"
                        class="adm-pill <?php echo $filter_top === 'top' ? 'is-gold' : ''; ?>">
                        <i class="fas fa-star"></i> Top Products Only
                        <span class="adm-pill-count"><?php echo $count_top; ?></span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Sections -->
        <div class="space-y-4">
            <!-- Primary Filter: Category (Dimension Label) -->
            <div class="adm-card p-6 adm-fade">
                <div class="flex items-center gap-3 mb-4">
                    <div class="adm-section-icon"><i class="fas fa-th-large"></i></div>
                    <div>
                        <div class="adm-section-title">Select Category</div>
                        <div class="adm-section-hint">Choose a category first</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="?dimension=all&family=all&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=all&door_material=all"
                        class="adm-pill <?php echo $filter_dimension === 'all' ? 'is-ink' : ''; ?>">
                        <i class="fas fa-list"></i> All Categories
                    </a>
                    <?php foreach ($dimensions as $dimension): ?>
                        <?php if (strtoupper($dimension['dimension_label_name']) === 'FIXED FURNITURE') continue; ?>
                        <a href="?dimension=<?php echo urlencode($dimension['dimension_label_id']); ?>&family=all&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=all&door_material=all"
                            class="adm-pill <?php echo $filter_dimension == $dimension['dimension_label_id'] ? 'is-ink' : ''; ?>">
                            <?php if ($dimension['dimension_label_id'] === 'fixed_modular'): ?>
                                <i class="fas fa-lock"></i>
                            <?php else: ?>
                                <i class="fas fa-folder"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($dimension['dimension_label_name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Secondary Filter: Item Family (buttons) + Family Variant 2 (dropdown) -->
            <div class="adm-card p-6 secondary-filter <?php echo ($filter_dimension !== 'all' && !empty($families)) ? 'show' : ''; ?>">
                <div class="flex items-center gap-3 mb-4">
                    <div class="adm-section-icon"><i class="fas fa-filter"></i></div>
                    <div>
                        <div class="adm-section-title">Filter by Item Family</div>
                        <?php if ($filter_dimension !== 'all'): ?>
                            <div class="adm-section-hint">Within selected category</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($families)): ?>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=all&family2=all&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                            class="adm-pill <?php echo $filter_family === 'all' ? 'is-ink' : ''; ?>">
                            <i class="fas fa-th"></i> All Families
                        </a>
                        <?php foreach ($families as $family): ?>
                            <?php
                            $chk = $conn->prepare("SELECT DISTINCT item_family_2 FROM items WHERE item_family = ? AND item_family_2 IS NOT NULL AND item_family_2 != '' ORDER BY item_family_2");
                            $chk->bind_param("s", $family);
                            $chk->execute();
                            $chk_result = $chk->get_result();
                            $this_family_variants = [];
                            while ($chk_row = $chk_result->fetch_assoc()) {
                                $this_family_variants[] = $chk_row['item_family_2'];
                            }
                            $chk->close();
                            $is_selected = ($filter_family === $family);
                            $has_variants = !empty($this_family_variants);
                            ?>

                            <?php if ($is_selected && $has_variants): ?>
                                <!-- Selected button with floating dropdown -->
                                <div class="relative" id="familyDropdown_<?php echo md5($family); ?>">
                                    <button onclick="toggleFamilyDropdown('<?php echo md5($family); ?>')" class="adm-pill is-ink">
                                        <?php echo htmlspecialchars($family); ?>
                                        <i class="fas fa-angle-down text-xs" id="familyChevron_<?php echo md5($family); ?>"></i>
                                    </button>

                                    <div id="familyMenu_<?php echo md5($family); ?>" class="hidden absolute left-0 mt-1 adm-dd-menu z-50 min-w-max overflow-hidden">
                                        <?php foreach ($this_family_variants as $fam2): ?>
                                            <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($family); ?>&family2=<?php echo urlencode($fam2); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                                                class="adm-dd-item <?php echo $filter_family2 === $fam2 ? 'is-active' : ''; ?>">
                                                <?php if ($filter_family2 === $fam2): ?>
                                                    <i class="fas fa-check text-xs"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-angle-right text-xs opacity-40"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($fam2); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($family); ?>&family2=all&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                                    class="adm-pill <?php echo $is_selected ? 'is-ink' : ''; ?>">
                                    <?php echo htmlspecialchars($family); ?>
                                    <?php if ($has_variants): ?>
                                        <i class="fas fa-angle-down text-xs opacity-70"></i>
                                    <?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-center py-4" style="color:var(--adm-muted);">No item families available in this category.</p>
                <?php endif; ?>
            </div>

            <!-- Tertiary Filter: Carcass Material & Door Material (compact inline) -->
            <?php if (!empty($materials) || !empty($door_materials)): ?>
            <div class="adm-material-bar flex flex-wrap items-center gap-3 adm-fade">
                <div class="flex items-center gap-2 font-semibold text-sm whitespace-nowrap" style="color:var(--adm-ink);">
                    <i class="fas fa-hammer"></i>
                    Filter by Material:
                </div>

                <?php if (!empty($materials)): ?>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold whitespace-nowrap" style="color:var(--adm-muted);">Carcass:</label>
                    <select onchange="window.location.href=this.value" class="adm-select">
                        <option value="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&family2=<?php echo urlencode($filter_family2); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=all&door_material=<?php echo urlencode($filter_door_material); ?>"
                            <?php echo $filter_material === 'all' ? 'selected' : ''; ?>>All</option>
                        <?php foreach ($materials as $mat): ?>
                            <option value="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&family2=<?php echo urlencode($filter_family2); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($mat); ?>&door_material=<?php echo urlencode($filter_door_material); ?>"
                                <?php echo $filter_material === $mat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if (!empty($door_materials)): ?>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold whitespace-nowrap" style="color:var(--adm-muted);">Door:</label>
                    <select onchange="window.location.href=this.value" class="adm-select">
                        <option value="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&family2=<?php echo urlencode($filter_family2); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=all"
                            <?php echo $filter_door_material === 'all' ? 'selected' : ''; ?>>All</option>
                        <?php foreach ($door_materials as $dmat): ?>
                            <option value="?dimension=<?php echo urlencode($filter_dimension); ?>&family=<?php echo urlencode($filter_family); ?>&family2=<?php echo urlencode($filter_family2); ?>&visibility=<?php echo urlencode($filter_visibility); ?>&top=<?php echo urlencode($filter_top); ?>&material=<?php echo urlencode($filter_material); ?>&door_material=<?php echo urlencode($dmat); ?>"
                                <?php echo $filter_door_material === $dmat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dmat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Active Filters Display -->
            <?php if ($filter_dimension !== 'all' || $filter_family !== 'all' || $filter_material !== 'all' || $filter_door_material !== 'all'): ?>
                <div class="adm-active-filters adm-fade">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3 flex-wrap">
                            <i class="fas fa-circle-check" style="color:var(--adm-ink);"></i>
                            <span class="font-bold text-sm" style="color:var(--adm-ink);">Active Filters:</span>
                            <div class="flex gap-2 flex-wrap">
                                <?php if ($filter_dimension !== 'all'):
                                    $dim_name = '';
                                    foreach ($dimensions as $d) {
                                        if ($d['dimension_label_id'] == $filter_dimension) {
                                            $dim_name = $d['dimension_label_name'];
                                            break;
                                        }
                                    }
                                ?>
                                    <span class="adm-chip-outline"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($dim_name); ?></span>
                                <?php endif; ?>
                                <?php if ($filter_family !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($filter_family); ?></span>
                                <?php endif; ?>
                                <?php if ($filter_family2 !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($filter_family2); ?></span>
                                <?php endif; ?>
                                <?php if ($filter_material !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-hammer"></i> <?php echo htmlspecialchars($filter_material); ?></span>
                                <?php endif; ?>
                                <?php if ($filter_door_material !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($filter_door_material); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="?dimension=all&family=all&visibility=visible&top=all&material=all&door_material=all" class="adm-clear-link">
                            <i class="fas fa-xmark"></i> Clear All
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Results Count -->
        <div class="adm-results-bar mb-6 mt-6 adm-fade">
            <div class="flex items-center gap-3">
                <i class="fas fa-box" style="color:var(--adm-ink);"></i>
                <span class="text-sm font-semibold" style="color:var(--adm-ink);">
                    Showing <?php echo $result->num_rows; ?> product(s)
                </span>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 adm-fade">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="product-card <?php echo $row['is_hidden'] ? 'is-hidden' : ''; ?>" data-product-id="<?php echo $row['item_id']; ?>">
                        <!-- Product Image -->
                        <div class="relative">
                            <?php if ($row['is_hidden']): ?>
                                <div class="hidden-overlay"><i class="fas fa-eye-slash"></i> HIDDEN</div>
                            <?php endif; ?>

                            <?php if ($row['is_top_product']): ?>
                                <div class="top-product-badge"><i class="fas fa-star"></i> TOP PRODUCT</div>
                            <?php endif; ?>

                            <?php if (!empty($row['item_image_path'])): ?>
                                <img src="<?= CLIENT_ASSET ?>/images/products/<?php echo htmlspecialchars($row['item_image_path']); ?>"
                                    alt="<?php echo htmlspecialchars($row['item_name']); ?>" class="product-image">
                            <?php else: ?>
                                <div class="product-image flex items-center justify-center">
                                    <i class="fas fa-image text-4xl" style="color:var(--adm-muted);"></i>
                                </div>
                            <?php endif; ?>

                            <div class="absolute bottom-3 left-3 flex flex-col gap-1.5">
                                <?php if (!empty($row['is_fixed_modular']) && $row['is_fixed_modular'] == 1): ?>
                                    <span class="badge badge-category"><i class="fas fa-lock"></i> Fixed Modular</span>
                                <?php elseif (!empty($row['dimension_label_name'])): ?>
                                    <span class="badge badge-category"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($row['dimension_label_name']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['item_family'])): ?>
                                    <span class="badge badge-family"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['item_family']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Product Details -->
                        <div class="adm-card-body">
                            <div class="adm-item-code"><?php echo htmlspecialchars($row['item_code']); ?></div>
                            <h3 class="adm-item-name"><?php echo htmlspecialchars($row['item_name']); ?></h3>

                            <div class="mb-2">
                                <?php if (!empty($row['item_material'])): ?>
                                    <div class="adm-spec-row">
                                        <i class="fas fa-hammer"></i>
                                        <span class="adm-spec-label">Carcass</span>
                                        <span><?php echo htmlspecialchars($row['item_material']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['door_material'])): ?>
                                    <div class="adm-spec-row">
                                        <i class="fas fa-door-open"></i>
                                        <span class="adm-spec-label">Door</span>
                                        <span><?php echo htmlspecialchars($row['door_material']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($row['item_color'])): ?>
                                    <div class="adm-spec-row">
                                        <i class="fas fa-palette"></i>
                                        <span><?php echo htmlspecialchars($row['item_color']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($row['non_project_price'] > 0 || $row['project_price'] > 0): ?>
                            <div class="adm-price-row">
                                <?php if ($row['non_project_price'] > 0): ?>
                                    <div>
                                        <div class="adm-price-label">Individual</div>
                                        <div class="adm-price-value">₱<?php echo number_format($row['non_project_price'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['project_price'] > 0): ?>
                                    <div>
                                        <div class="adm-price-label">Project</div>
                                        <div class="adm-price-value">₱<?php echo number_format($row['project_price'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="adm-action-row">
                                <button onclick="editProduct(<?php echo $row['item_id']; ?>)" class="adm-mini-btn is-primary">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                            </div>

                            <div class="adm-action-row two-col">
                                <button onclick="addProductDetails(<?php echo $row['item_id']; ?>)" class="adm-mini-btn">
                                    <i class="fas fa-plus"></i> Add Details
                                </button>
                                <button onclick="manageFixedSizes(<?php echo $row['item_id']; ?>)" class="adm-mini-btn">
                                    <i class="fas fa-ruler-combined"></i> Fixed Sizes
                                </button>
                            </div>

                            <div class="toggle-container">
                                <button onclick="toggleTopProduct(<?php echo $row['item_id']; ?>)"
                                    class="toggle-btn <?php echo $row['is_top_product'] ? 'active' : 'inactive'; ?>"
                                    data-product-id="<?php echo $row['item_id']; ?>"
                                    data-toggle-type="top_product">
                                    <i class="fas fa-star"></i>
                                    <span><?php echo $row['is_top_product'] ? 'Top' : 'Regular'; ?></span>
                                </button>

                                <button onclick="toggleVisibility(<?php echo $row['item_id']; ?>)"
                                    class="toggle-btn visibility-btn <?php echo $row['is_hidden'] ? 'hidden' : 'visible'; ?>"
                                    data-product-id="<?php echo $row['item_id']; ?>"
                                    data-toggle-type="visibility">
                                    <i class="fas fa-<?php echo $row['is_hidden'] ? 'eye-slash' : 'eye'; ?>"></i>
                                    <span><?php echo $row['is_hidden'] ? 'Hidden' : 'Visible'; ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full">
                    <div class="adm-empty-state">
                        <div class="adm-empty-icon"><i class="fas fa-box-open"></i></div>
                        <h3 class="text-lg font-bold mb-2" style="color:var(--adm-ink);">No Products Found</h3>
                        <p class="text-sm mb-6" style="color:var(--adm-soft);">
                            <?php
                            if ($filter_dimension !== 'all' || $filter_family !== 'all') {
                                echo "No products match your current filters.";
                            } else {
                                echo "No products have been added yet. Start by selecting a category above!";
                            }
                            ?>
                        </p>
                        <div class="flex items-center justify-center gap-3 flex-wrap">
                            <?php if ($filter_dimension !== 'all' || $filter_family !== 'all'): ?>
                                <a href="?dimension=all&family=all" class="adm-btn-outline">
                                    <i class="fas fa-rotate-left"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>add-product" class="adm-btn">
                                <i class="fas fa-plus"></i> Add New Product
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleFamilyDropdown(id) {
            const menu = document.getElementById('familyMenu_' + id);
            const chevron = document.getElementById('familyChevron_' + id);
            const isHidden = menu.classList.contains('hidden');

            document.querySelectorAll('[id^="familyMenu_"]').forEach(m => m.classList.add('hidden'));
            document.querySelectorAll('[id^="familyChevron_"]').forEach(c => c.style.transform = '');

            if (isHidden) {
                menu.classList.remove('hidden');
                chevron.style.transform = 'rotate(180deg)';
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('[id^="familyDropdown_"]')) {
                document.querySelectorAll('[id^="familyMenu_"]').forEach(m => m.classList.add('hidden'));
                document.querySelectorAll('[id^="familyChevron_"]').forEach(c => c.style.transform = '');
            }
        });

        function editProduct(itemId) {
            window.location.href = 'edit-product?id=' + itemId;
        }

        function addProductDetails(itemId) {
            window.location.href = 'add-details?id=' + itemId;
        }

        function manageFixedSizes(itemId) {
            window.location.href = 'fixed-sized-setting?id=' + itemId;
        }c

        function closeSuccessAlert() {
            const alert = document.getElementById('successAlert');
            if (alert) {
                alert.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => { alert.remove(); }, 300);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.getElementById('successAlert');
            if (successAlert) {
                setTimeout(function() { closeSuccessAlert(); }, 10000);
            }
        });

        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) { target.scrollIntoView({ behavior: 'smooth' }); }
            });
        });

        function toggleTopProduct(productId) {
            const button = document.querySelector(`[data-product-id="${productId}"][data-toggle-type="top_product"]`);
            const card = document.querySelector(`.product-card[data-product-id="${productId}"]`);

            fetch('../pages/toggle_product_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `product_id=${productId}&toggle_type=top_product`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.is_top_product) {
                        button.classList.remove('inactive');
                        button.classList.add('active');
                        button.innerHTML = '<i class="fas fa-star"></i><span>Top</span>';

                        const imageContainer = card.querySelector('.relative');
                        if (!imageContainer.querySelector('.top-product-badge')) {
                            const badge = document.createElement('div');
                            badge.className = 'top-product-badge';
                            badge.innerHTML = '<i class="fas fa-star"></i> TOP PRODUCT';
                            imageContainer.appendChild(badge);
                        }
                    } else {
                        button.classList.remove('active');
                        button.classList.add('inactive');
                        button.innerHTML = '<i class="fas fa-star"></i><span>Regular</span>';

                        const badge = card.querySelector('.top-product-badge');
                        if (badge) badge.remove();
                    }
                } else {
                    alert('Error updating product status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating product status');
            });
        }

        function toggleVisibility(productId) {
            const button = document.querySelector(`[data-product-id="${productId}"][data-toggle-type="visibility"]`);
            const card = document.querySelector(`.product-card[data-product-id="${productId}"]`);

            fetch('../pages/toggle_product_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `product_id=${productId}&toggle_type=visibility`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.is_hidden) {
                        button.classList.remove('visible');
                        button.classList.add('hidden');
                        button.innerHTML = '<i class="fas fa-eye-slash"></i><span>Hidden</span>';
                        card.classList.add('is-hidden');

                        const imageContainer = card.querySelector('.relative');
                        if (!imageContainer.querySelector('.hidden-overlay')) {
                            const overlay = document.createElement('div');
                            overlay.className = 'hidden-overlay';
                            overlay.innerHTML = '<i class="fas fa-eye-slash"></i> HIDDEN';
                            imageContainer.insertBefore(overlay, imageContainer.firstChild);
                        }
                    } else {
                        button.classList.remove('hidden');
                        button.classList.add('visible');
                        button.innerHTML = '<i class="fas fa-eye"></i><span>Visible</span>';
                        card.classList.remove('is-hidden');

                        const overlay = card.querySelector('.hidden-overlay');
                        if (overlay) overlay.remove();
                    }
                } else {
                    alert('Error updating product visibility');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating product visibility');
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>