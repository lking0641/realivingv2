<?php
// quotation_products_display.php
// Display products with category and family filters

// Get filter parameters
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$filter_family = isset($_GET['family']) ? $_GET['family'] : 'all';
$filter_family2 = isset($_GET['family2']) ? $_GET['family2'] : 'all';
$filter_material = isset($_GET['material']) ? $_GET['material'] : 'all';
$filter_door_material = isset($_GET['door_material']) ? $_GET['door_material'] : 'all';

// Fetch all categories (dimension labels)
$categories = [];
$cat_query = "SELECT dimension_label_id, dimension_label_name FROM dimension_label ORDER BY dimension_label_name";
$cat_result = $conn->query($cat_query);
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Add Fixed Modular as a special category
$categories[] = ['dimension_label_id' => 'fixed_modular', 'dimension_label_name' => 'Fixed Modular'];

// Fetch families based on selected category
$families = [];
if ($filter_category !== 'all') {
    if ($filter_category === 'fixed_modular') {
        // Get families for fixed modular items only
        $fam_query = "SELECT DISTINCT item_family 
                      FROM items 
                      WHERE is_fixed_modular = 1 
                      AND item_family IS NOT NULL 
                      AND item_family != ''
                      AND is_hidden = 0
                      ORDER BY item_family";
        $fam_result = $conn->query($fam_query);
        if ($fam_result) {
            while ($row = $fam_result->fetch_assoc()) {
                $families[] = $row['item_family'];
            }
        }
    } else {
        // Get families for specific dimension
        $fam_query = "SELECT DISTINCT item_family 
                      FROM items 
                      WHERE dimension_label_fk = ? 
                      AND item_family IS NOT NULL 
                      AND item_family != ''
                      AND is_hidden = 0
                      ORDER BY item_family";
        $stmt_fam = $conn->prepare($fam_query);
        $stmt_fam->bind_param("i", $filter_category);
        $stmt_fam->execute();
        $fam_result = $stmt_fam->get_result();
        while ($row = $fam_result->fetch_assoc()) {
            $families[] = $row['item_family'];
        }
        $stmt_fam->close();
    }
} else {
    $fam_query = "SELECT DISTINCT item_family 
                  FROM items 
                  WHERE item_family IS NOT NULL 
                  AND item_family != ''
                  AND is_hidden = 0
                  ORDER BY item_family";
    $fam_result = $conn->query($fam_query);
    if ($fam_result) {
        while ($row = $fam_result->fetch_assoc()) {
            $families[] = $row['item_family'];
        }
    }
}

// Pre-fetch variant 2 data for ALL families (to avoid querying inside HTML loop)
$all_family_variants = [];
foreach ($families as $fam) {
    $chk = $conn->prepare("SELECT DISTINCT item_family_2 FROM items WHERE item_family = ? AND item_family_2 IS NOT NULL AND item_family_2 != '' AND is_hidden = 0 ORDER BY item_family_2");
    $chk->bind_param("s", $fam);
    $chk->execute();
    $chk_result = $chk->get_result();
    $variants = [];
    while ($chk_row = $chk_result->fetch_assoc()) {
        $variants[] = $chk_row['item_family_2'];
    }
    $chk->close();
    $all_family_variants[$fam] = $variants;
}

// Fetch distinct item_family_2 values — only when a specific family is selected
$families2 = [];
if ($filter_family !== 'all') {
    $fam2_query = "SELECT DISTINCT item_family_2 FROM items WHERE item_family_2 IS NOT NULL AND item_family_2 != '' AND is_hidden = 0";
    $fam2_params = [];
    $fam2_types = "";
    $fam2_conditions = [];

    if ($filter_category !== 'all') {
        if ($filter_category === 'fixed_modular') {
            $fam2_conditions[] = "is_fixed_modular = 1";
        } else {
            $fam2_conditions[] = "dimension_label_fk = ?";
            $fam2_params[] = $filter_category;
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

// Fetch distinct carcass materials
$materials = [];
$mat_query = "SELECT DISTINCT item_material FROM items WHERE item_material IS NOT NULL AND item_material != '' AND is_hidden = 0";
$mat_conditions = [];
$mat_params = [];
$mat_types = "";
if ($filter_category !== 'all') {
    if ($filter_category === 'fixed_modular') {
        $mat_conditions[] = "is_fixed_modular = 1";
    } else {
        $mat_conditions[] = "dimension_label_fk = ?";
        $mat_params[] = $filter_category;
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
$door_query = "SELECT DISTINCT door_material FROM items WHERE door_material IS NOT NULL AND door_material != '' AND is_hidden = 0";
if (!empty($mat_conditions)) {
    $door_query .= " AND " . implode(" AND ", $mat_conditions);
}
$door_query .= " ORDER BY door_material";
$door_stmt = $conn->prepare($door_query);
if (!empty($mat_params)) {
    $door_stmt->bind_param($mat_types, ...$mat_params);
}
$door_stmt->execute();
$door_result = $door_stmt->get_result();
while ($row = $door_result->fetch_assoc()) {
    $door_materials[] = $row['door_material'];
}
$door_stmt->close();

// Build query to fetch products
$product_query = "SELECT
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
    i.is_fixed_modular,
    dl.dimension_label_name
FROM items i
LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id
WHERE i.is_hidden = 0";

$conditions = [];
$params = [];
$types = "";

if ($filter_category !== 'all') {
    if ($filter_category === 'fixed_modular') {
        $conditions[] = "i.is_fixed_modular = 1";
    } else {
        $conditions[] = "i.dimension_label_fk = ?";
        $params[] = $filter_category;
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
    $product_query .= " AND " . implode(" AND ", $conditions);
}

$product_query .= " ORDER BY i.item_name ASC LIMIT 50";

$stmt_products = $conn->prepare($product_query);
if (!empty($params)) {
    $stmt_products->bind_param($types, ...$params);
}
$stmt_products->execute();
$products_result = $stmt_products->get_result();

$display_items = [];
while ($row = $products_result->fetch_assoc()) {
    // Fetch dimension info
    $dimension_sql = "SELECT * FROM dimension_measurement WHERE dimension_msmt_id = (SELECT dimension_msmt_fk FROM items WHERE item_id = ?)";
    $dimension_stmt = $conn->prepare($dimension_sql);
    $dimension_stmt->bind_param("i", $row['item_id']);
    $dimension_stmt->execute();
    $dimension_result = $dimension_stmt->get_result();
    $row['dimension'] = $dimension_result->fetch_assoc();

    // Fetch label info
    $label_sql = "SELECT * FROM dimension_label WHERE dimension_label_id = (SELECT dimension_label_fk FROM items WHERE item_id = ?)";
    $label_stmt = $conn->prepare($label_sql);
    $label_stmt->bind_param("i", $row['item_id']);
    $label_stmt->execute();
    $label_result = $label_stmt->get_result();
    $row['labels'] = $label_result->fetch_assoc();

    // Fetch colors
    $color_sql = "SELECT * FROM item_standard_color WHERE fk_standard_color = ?";
    $color_stmt = $conn->prepare($color_sql);
    $color_stmt->bind_param("i", $row['item_id']);
    $color_stmt->execute();
    $color_res = $color_stmt->get_result();
    $row['colors'] = [];
    while ($c = $color_res->fetch_assoc()) {
        $row['colors'][] = $c;
    }

    $display_items[] = $row;
}

$stmt_products->close();

// Resolve display labels for the active-filters breadcrumb
$active_category_label = null;
if ($filter_category !== 'all') {
    foreach ($categories as $c) {
        if ($c['dimension_label_id'] == $filter_category) {
            $active_category_label = $c['dimension_label_name'];
            break;
        }
    }
}
$base_qs = "id=" . urlencode($client_id) . "&name=" . urlencode($client_name) . "&email=" . urlencode($client_email) . "&address=" . urlencode($client_address) . "&contact=" . urlencode($client_contact);
$has_active_filters = ($filter_category !== 'all' || $filter_family !== 'all' || $filter_family2 !== 'all' || $filter_material !== 'all' || $filter_door_material !== 'all');
?>

<style>
/* ============ FILTER PANEL ============ */
.pf-card {
    background: var(--surface, #fff);
    border-radius: var(--radius, 12px);
    border: 1.5px solid var(--border, #E2E2E2);
    box-shadow: var(--shadow, 0 1px 3px rgba(11,11,11,.06));
    margin-bottom: 22px;
    overflow: hidden;
}

.pf-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 16px 22px;
    border-bottom: 1.5px solid var(--border, #E2E2E2);
    background: var(--surface2, #FAFAFA);
}

.pf-card-head h3 {
    font-size: 14.5px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text, #0B0B0B);
}

.pf-card-head h3 i {
    color: var(--brand-light, #9A9A9A);
}

.pf-breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.pf-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 4px 12px;
    border-radius: 20px;
    background: var(--accent, #E8E8E8);
    color: var(--text, #0B0B0B);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
}

.pf-chip:hover {
    background: var(--hover-bg, #F2F2F2);
}

.pf-chip i {
    font-size: 10px;
    opacity: .55;
}

.pf-clear-all {
    font-size: 12px;
    font-weight: 600;
    color: var(--danger, #9B1C1C);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.pf-clear-all:hover {
    text-decoration: underline;
}

.pf-body {
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.pf-group-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--text-muted, #6B6B6B);
    margin-bottom: 9px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.pf-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    position: relative;
    overflow: visible;
}

.pf-pill {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    background: var(--surface, #fff);
    color: var(--text-muted, #6B6B6B);
    border: 1.5px solid var(--border, #E2E2E2);
    transition: all .15s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.pf-pill:hover {
    border-color: var(--brand-light, #9A9A9A);
    color: var(--text, #0B0B0B);
    background: var(--hover-bg, #F2F2F2);
}

.pf-pill.active {
    background: var(--brand, #0B0B0B);
    border-color: var(--brand, #0B0B0B);
    color: #fff;
}

.pf-divider {
    height: 1px;
    background: var(--border, #E2E2E2);
}

/* Variant dropdown (family_2) */
.pf-dropdown-wrap {
    position: relative;
    display: inline-block;
}

.pf-dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    background: var(--surface, #fff);
    border: 1.5px solid var(--border, #E2E2E2);
    border-radius: var(--radius-sm, 8px);
    box-shadow: var(--shadow-md, 0 10px 26px -16px rgba(11,11,11,.25));
    z-index: 999;
    min-width: 190px;
    overflow: hidden;
    padding: 5px;
}

.pf-dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    border-radius: var(--radius-sm, 8px);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-muted, #6B6B6B);
    transition: background .12s;
}

.pf-dropdown-item:hover {
    background: var(--hover-bg, #F2F2F2);
    color: var(--text, #0B0B0B);
}

.pf-dropdown-item.selected {
    background: var(--brand, #0B0B0B);
    color: #fff;
}

.pf-dropdown-item i {
    font-size: 10px;
    width: 12px;
    opacity: .6;
}

/* Material selects */
.pf-selects {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.pf-select-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 180px;
}

.pf-select-group label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
    text-transform: uppercase;
    color: var(--text-mute2, #9A9A9A);
}

.pf-select {
    padding: 9px 12px;
    border: 1.5px solid var(--border, #E2E2E2);
    border-radius: var(--radius-sm, 8px);
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    color: var(--text, #0B0B0B);
    background: var(--surface, #fff);
    cursor: pointer;
    outline: none;
    transition: border-color .15s;
}

.pf-select:focus {
    border-color: var(--brand, #0B0B0B);
}

/* ============ RESULTS HEADER ============ */
.pf-results-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    padding: 0 2px;
}

.pf-results-count {
    font-size: 13px;
    color: var(--text-muted, #6B6B6B);
    font-weight: 500;
}

.pf-results-count strong {
    color: var(--text, #0B0B0B);
}

/* ============ PRODUCT GRID ============ */
.products-grid-quotation {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.product-card-quotation {
    background: var(--surface, #fff);
    border-radius: var(--radius, 12px);
    border: 1.5px solid var(--border, #E2E2E2);
    overflow: hidden;
    box-shadow: var(--shadow, 0 1px 3px rgba(11,11,11,.06));
    transition: transform .18s, box-shadow .18s;
    display: flex;
    flex-direction: column;
}

.product-card-quotation:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md, 0 10px 26px -16px rgba(11,11,11,.25));
}

.product-image-quotation {
    position: relative;
    width: 100%;
    height: 220px;
    overflow: hidden;
    background: var(--surface2, #FAFAFA);
}

.product-image-quotation img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform .3s ease;
}

.product-card-quotation:hover .product-image-quotation img {
    transform: scale(1.04);
}

.no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--border, #E2E2E2);
    font-size: 44px;
}

.product-badge-quotation {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(11, 11, 11, .78);
    color: #fff;
    padding: 5px 11px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .4px;
    text-transform: uppercase;
}

.fixed-modular-badge {
    background: var(--warning, #8A6100) !important;
}

.fixed-modular-badge i {
    margin-right: 4px;
}

.product-info-quotation {
    padding: 18px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.product-code-quotation {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    color: var(--text-muted, #6B6B6B);
    font-weight: 600;
    letter-spacing: .5px;
    margin-bottom: 6px;
}

.product-name-quotation {
    font-size: 16.5px;
    font-weight: 700;
    color: var(--text, #0B0B0B);
    margin: 0 0 10px 0;
    line-height: 1.3;
}

.product-family-quotation {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: var(--info, #33475B);
    background: var(--info-bg, #EDF0F3);
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
    margin-bottom: 12px;
    width: fit-content;
}

.product-specs-quotation {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border, #E2E2E2);
}

.spec-item-quotation {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: var(--text-muted, #6B6B6B);
}

.spec-item-quotation i {
    color: var(--brand-light, #9A9A9A);
    font-size: 12.5px;
    width: 14px;
}

.spec-item-quotation .spec-tag {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-mute2, #9A9A9A);
    text-transform: uppercase;
    margin-right: 2px;
}

.product-price-quotation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding: 11px 13px;
    background: var(--surface2, #FAFAFA);
    border: 1px solid var(--border, #E2E2E2);
    border-radius: var(--radius-sm, 8px);
}

.price-label {
    font-size: 11.5px;
    color: var(--text-muted, #6B6B6B);
    font-weight: 500;
}

.price-value {
    font-size: 17px;
    font-weight: 800;
    color: var(--success, #1F6F43);
}

.view-details-btn-quotation {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 11px 20px;
    background: var(--brand, #0B0B0B);
    color: #fff;
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 600;
    border-radius: var(--radius-sm, 8px);
    transition: background .18s;
    border: none;
    cursor: pointer;
    margin-top: auto;
    font-family: inherit;
}

.view-details-btn-quotation:hover {
    background: var(--brand-mid, #262626);
}

.no-products-message {
    text-align: center;
    padding: 56px 20px;
    background: var(--surface, #fff);
    border-radius: var(--radius, 12px);
    border: 1.5px solid var(--border, #E2E2E2);
}

.no-products-message i {
    font-size: 42px;
    color: var(--border, #E2E2E2);
    margin-bottom: 14px;
    display: block;
}

.no-products-message h3 {
    font-size: 17px;
    font-weight: 700;
    color: var(--text, #0B0B0B);
    margin-bottom: 6px;
}

.no-products-message p {
    font-size: 13.5px;
    color: var(--text-muted, #6B6B6B);
}

@media (max-width: 768px) {
    .products-grid-quotation {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 14px;
    }

    .product-image-quotation {
        height: 170px;
    }

    .pf-body {
        padding: 16px;
    }

    .pf-card-head {
        padding: 14px 16px;
    }

    .pf-pill {
        font-size: 12px;
        padding: 7px 13px;
    }

    .pf-selects {
        flex-direction: column;
        gap: 12px;
    }

    .pf-select-group {
        min-width: 100%;
    }
}

@media (max-width: 480px) {
    .products-grid-quotation {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
}
</style>

<!-- ============ FILTER PANEL ============ -->
<div class="pf-card">
    <div class="pf-card-head">
        <h3><i class="fas fa-sliders-h"></i> Refine Products</h3>

        <?php if ($has_active_filters): ?>
            <div class="pf-breadcrumb">
                <?php if ($active_category_label): ?>
                    <a class="pf-chip" href="?<?= $base_qs ?>&category=all&family=all&family2=all&material=all&door_material=all" title="Remove category filter">
                        <?= htmlspecialchars($active_category_label) ?> <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <?php if ($filter_family !== 'all'): ?>
                    <a class="pf-chip" href="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=all&family2=all&material=<?= urlencode($filter_material) ?>&door_material=<?= urlencode($filter_door_material) ?>" title="Remove variant filter">
                        <?= htmlspecialchars($filter_family) ?> <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <?php if ($filter_family2 !== 'all'): ?>
                    <a class="pf-chip" href="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=all&material=<?= urlencode($filter_material) ?>&door_material=<?= urlencode($filter_door_material) ?>" title="Remove sub-variant filter">
                        <?= htmlspecialchars($filter_family2) ?> <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <?php if ($filter_material !== 'all'): ?>
                    <a class="pf-chip" href="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=all&door_material=<?= urlencode($filter_door_material) ?>" title="Remove carcass material filter">
                        Carcass: <?= htmlspecialchars($filter_material) ?> <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <?php if ($filter_door_material !== 'all'): ?>
                    <a class="pf-chip" href="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($filter_material) ?>&door_material=all" title="Remove door material filter">
                        Door: <?= htmlspecialchars($filter_door_material) ?> <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <a class="pf-clear-all" href="?<?= $base_qs ?>&category=all&family=all&family2=all&material=all&door_material=all">
                    <i class="fas fa-redo"></i> Clear all
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="pf-body">
        <!-- Category -->
        <div>
            <div class="pf-group-label"><i class="fas fa-th-large"></i> Category</div>
            <div class="pf-pills">
                <a href="?<?= $base_qs ?>&category=all&family=all&family2=all&material=all&door_material=all"
                   class="pf-pill <?= $filter_category === 'all' ? 'active' : '' ?>">
                    All Categories
                </a>
                <?php foreach ($categories as $cat): ?>
                    <?php if (strtoupper($cat['dimension_label_name']) === 'FIXED FURNITURE') continue; ?>
                    <a href="?<?= $base_qs ?>&category=<?= $cat['dimension_label_id'] ?>&family=all&family2=all&material=all&door_material=all"
                       class="pf-pill <?= $filter_category == $cat['dimension_label_id'] ? 'active' : '' ?>">
                        <?php if ($cat['dimension_label_id'] === 'fixed_modular'): ?>
                            <i class="fas fa-lock"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($cat['dimension_label_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($families)): ?>
            <div class="pf-divider"></div>

            <!-- Variant -->
            <div>
                <div class="pf-group-label"><i class="fas fa-tags"></i> Variant</div>
                <div class="pf-pills">
                    <a href="?<?= $base_qs ?>&category=<?= $filter_category ?>&family=all&family2=all"
                       class="pf-pill <?= $filter_family === 'all' ? 'active' : '' ?>">
                        All Variants
                    </a>
                    <?php foreach ($families as $fam):
                        $this_fam_variants = $all_family_variants[$fam] ?? [];
                        $is_selected = ($filter_family === $fam);
                        $has_variants = !empty($this_fam_variants);
                        $fam_id = md5($fam);
                    ?>
                        <?php if ($is_selected && $has_variants): ?>
                            <div class="pf-dropdown-wrap" id="famDropdown_<?= $fam_id ?>">
                                <button type="button" onclick="toggleFamDropdown('<?= $fam_id ?>')" class="pf-pill active">
                                    <?= htmlspecialchars($fam) ?>
                                    <i class="fas fa-angle-down" id="famChevron_<?= $fam_id ?>" style="font-size:10px; transition: transform .18s;"></i>
                                </button>
                                <div id="famMenu_<?= $fam_id ?>" class="pf-dropdown-menu">
                                    <?php foreach ($this_fam_variants as $fam2):
                                        $is_fam2_selected = ($filter_family2 === $fam2);
                                    ?>
                                        <a href="?<?= $base_qs ?>&category=<?= $filter_category ?>&family=<?= urlencode($fam) ?>&family2=<?= urlencode($fam2) ?>"
                                           class="pf-dropdown-item <?= $is_fam2_selected ? 'selected' : '' ?>">
                                            <i class="fas <?= $is_fam2_selected ? 'fa-check' : 'fa-angle-right' ?>"></i>
                                            <?= htmlspecialchars($fam2) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="?<?= $base_qs ?>&category=<?= $filter_category ?>&family=<?= urlencode($fam) ?>&family2=all"
                               class="pf-pill <?= $is_selected ? 'active' : '' ?>">
                                <?= htmlspecialchars($fam) ?>
                                <?php if ($has_variants): ?>
                                    <i class="fas fa-angle-down" style="font-size:10px; opacity:.65;"></i>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($materials) || !empty($door_materials)): ?>
            <div class="pf-divider"></div>

            <!-- Material -->
            <div>
                <div class="pf-group-label"><i class="fas fa-hammer"></i> Material</div>
                <div class="pf-selects">
                    <?php if (!empty($materials)): ?>
                        <div class="pf-select-group">
                            <label>Carcass</label>
                            <select class="pf-select" onchange="window.location.href=this.value">
                                <option value="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=all&door_material=<?= urlencode($filter_door_material) ?>"
                                    <?= $filter_material === 'all' ? 'selected' : '' ?>>All Carcass Materials</option>
                                <?php foreach ($materials as $mat): ?>
                                    <option value="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($mat) ?>&door_material=<?= urlencode($filter_door_material) ?>"
                                        <?= $filter_material === $mat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($door_materials)): ?>
                        <div class="pf-select-group">
                            <label>Door</label>
                            <select class="pf-select" onchange="window.location.href=this.value">
                                <option value="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($filter_material) ?>&door_material=all"
                                    <?= $filter_door_material === 'all' ? 'selected' : '' ?>>All Door Materials</option>
                                <?php foreach ($door_materials as $dmat): ?>
                                    <option value="?<?= $base_qs ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($filter_material) ?>&door_material=<?= urlencode($dmat) ?>"
                                        <?= $filter_door_material === $dmat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dmat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ RESULTS ============ -->
<div class="pf-results-head">
    <span class="pf-results-count">
        <strong><?= count($display_items) ?></strong> product<?= count($display_items) === 1 ? '' : 's' ?> found
    </span>
</div>

<?php if (count($display_items) > 0): ?>
    <div class="products-grid-quotation">
        <?php foreach ($display_items as $item):
            $image_path = !empty($item['item_image_path'])
                ? CLIENT_ASSET . '/images/products/' . htmlspecialchars($item['item_image_path'])
                : '';
            $price = $business_type === 'Project' ? $item['project_price'] : $item['non_project_price'];
        ?>
            <div class="product-card-quotation">
                <div class="product-image-quotation">
                    <?php if (!empty($image_path)): ?>
                        <img src="<?= $image_path ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" />
                    <?php else: ?>
                        <div class="no-image"><i class="fas fa-image"></i></div>
                    <?php endif; ?>

                    <?php if (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1): ?>
                        <span class="product-badge-quotation fixed-modular-badge">
                            <i class="fas fa-lock"></i> Fixed Modular
                        </span>
                    <?php elseif (!empty($item['dimension_label_name'])): ?>
                        <span class="product-badge-quotation">
                            <?= htmlspecialchars($item['dimension_label_name']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="product-info-quotation">
                    <div class="product-code-quotation"><?= htmlspecialchars($item['item_code']) ?></div>
                    <h3 class="product-name-quotation"><?= htmlspecialchars($item['item_name']) ?></h3>

                    <?php if (!empty($item['item_family'])): ?>
                        <div class="product-family-quotation">
                            <i class="fas fa-tags"></i>
                            <?= htmlspecialchars($item['item_family']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="product-specs-quotation">
                        <?php if (!empty($item['item_material'])): ?>
                            <span class="spec-item-quotation">
                                <i class="fas fa-hammer"></i>
                                <span class="spec-tag">Carcass:</span> <?= htmlspecialchars($item['item_material']) ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($item['door_material'])): ?>
                            <span class="spec-item-quotation">
                                <i class="fas fa-door-open"></i>
                                <span class="spec-tag">Door:</span> <?= htmlspecialchars($item['door_material']) ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($item['item_color'])): ?>
                            <span class="spec-item-quotation">
                                <i class="fas fa-palette"></i>
                                <?= htmlspecialchars($item['item_color']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="product-price-quotation">
                        <span class="price-label">Price</span>
                        <span class="price-value">₱<?= number_format($price, 2) ?></span>
                    </div>

                    <button onclick="viewProductDetails('<?= htmlspecialchars($item['item_code']) ?>')"
                            class="view-details-btn-quotation">
                        <i class="fas fa-eye"></i>
                        <span>View Details</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="no-products-message">
        <i class="fas fa-box-open"></i>
        <h3>No Products Found</h3>
        <p>No products match the selected filters. Try clearing a filter above.</p>
    </div>
<?php endif; ?>

<script>
function toggleFamDropdown(id) {
    const menu = document.getElementById('famMenu_' + id);
    const chevron = document.getElementById('famChevron_' + id);
    const isHidden = menu.style.display === 'none' || menu.style.display === '';

    // Close all other open dropdowns first
    document.querySelectorAll('[id^="famMenu_"]').forEach(m => m.style.display = 'none');
    document.querySelectorAll('[id^="famChevron_"]').forEach(c => c.style.transform = '');

    if (isHidden) {
        menu.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function (e) {
    if (!e.target.closest('.pf-dropdown-wrap')) {
        document.querySelectorAll('[id^="famMenu_"]').forEach(m => m.style.display = 'none');
        document.querySelectorAll('[id^="famChevron_"]').forEach(c => c.style.transform = '');
    }
});
</script>