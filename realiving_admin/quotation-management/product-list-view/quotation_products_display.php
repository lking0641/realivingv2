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
?>

<style>
/* Filter Section */
.filter-section {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.filter-header {
    font-family: 'Montserrat', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: #3b1f0f;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-header i {
    font-size: 18px;
    color: #8a5a44;
}

.filter-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.filter-btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-family: 'Montserrat', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    background: #f5f5f5;
    color: #666;
    border: 2px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
}

.filter-btn:hover {
    background: #e8f4f8;
    color: #3b1f0f;
    border-color: #8a5a44;
}

.filter-btn.active {
    background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
    color: white;
    box-shadow: 0 4px 10px rgba(59, 31, 15, 0.3);
}

.filter-divider {
    height: 1px;
    background: #e0e0e0;
    margin: 20px 0;
}

/* Products Grid */
.products-grid-quotation {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.product-card-quotation {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
}

.product-card-quotation:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.product-image-quotation {
    position: relative;
    width: 100%;
    height: 240px;
    overflow: hidden;
    background: #f5f5f5;
}

.product-image-quotation img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.product-card-quotation:hover .product-image-quotation img {
    transform: scale(1.05);
}

.no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ddd;
    font-size: 48px;
}

.product-badge-quotation {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(138, 90, 68, 0.9);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.fixed-modular-badge {
    background: rgba(138, 90, 68, 0.9) !important; /* Same brown as other badges */
}

.fixed-modular-badge i {
    margin-right: 4px;
}

.product-info-quotation {
    padding: 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.product-code-quotation {
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    color: #999;
    font-weight: 600;
    letter-spacing: 1px;
    margin-bottom: 6px;
}

.product-name-quotation {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 18px;
    font-weight: 600;
    color: #3b1f0f;
    margin: 0 0 10px 0;
    line-height: 1.3;
}

.product-family-quotation {
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: 'Montserrat', sans-serif;
    font-size: 12px;
    color: #667eea;
    font-weight: 600;
    margin-bottom: 12px;
}

.product-specs-quotation {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
}

.spec-item-quotation {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Montserrat', sans-serif;
    font-size: 12px;
    color: #666;
}

.spec-item-quotation i {
    color: #8a5a44;
    font-size: 14px;
    width: 16px;
}

.product-price-quotation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 12px;
    background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%);
    border-radius: 8px;
}

.price-label {
    font-family: 'Montserrat', sans-serif;
    font-size: 12px;
    color: #666;
    font-weight: 500;
}

.price-value {
    font-family: 'Montserrat', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: #28a745;
}

.view-details-btn-quotation {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px 20px;
    background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
    color: white;
    text-decoration: none;
    font-family: 'Montserrat', sans-serif;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    margin-top: auto;
}

.view-details-btn-quotation:hover {
    background: linear-gradient(135deg, #2a1609 0%, #5a3520 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 31, 15, 0.3);
}

.no-products-message {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.no-products-message i {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 20px;
}

.no-products-message h3 {
    font-family: 'Montserrat', sans-serif;
    font-size: 24px;
    color: #3b1f0f;
    margin-bottom: 10px;
}

.no-products-message p {
    font-family: 'Montserrat', sans-serif;
    font-size: 14px;
    color: #666;
}

.relative-fam-dropdown {
    position: relative;
    display: inline-block;
}

.filter-buttons {
    overflow: visible !important;
    position: relative;
}

@media (max-width: 768px) {
    .products-grid-quotation {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }

    .product-image-quotation {
        height: 200px;
    }

    .filter-buttons {
        gap: 8px;
    }

    .filter-btn {
        font-size: 12px;
        padding: 8px 14px;
    }
}

@media (max-width: 480px) {
    .products-grid-quotation {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Filter Section -->
<div class="filter-section">
    <!-- Category Filter -->
    <div class="filter-header">
        <i class="fas fa-th-large"></i>
        Categories
    </div>
    <div class="filter-buttons">
    <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=all&family=all" 
       class="filter-btn <?= $filter_category === 'all' ? 'active' : '' ?>">
        All Categories
    </a>
    <?php foreach ($categories as $cat): ?>
    <?php if (strtoupper($cat['dimension_label_name']) === 'FIXED FURNITURE') continue; ?>
    <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= $cat['dimension_label_id'] ?>&family=all" 
       class="filter-btn <?= $filter_category == $cat['dimension_label_id'] ? 'active' : '' ?>">
        <?php if ($cat['dimension_label_id'] === 'fixed_modular'): ?>
            <i class="fas fa-lock" style="margin-right: 5px;"></i>
        <?php endif; ?>
        <?= htmlspecialchars($cat['dimension_label_name']) ?>
    </a>
<?php endforeach; ?>
</div>

    <?php if (!empty($families)): ?>
        <div class="filter-divider"></div>
        
        <!-- Family Filter (Variants) -->
        <div class="filter-header">
            <i class="fas fa-tags"></i>
            Variants
        </div>
        <div class="filter-buttons" style="overflow: visible; position: relative;">
            <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= $filter_category ?>&family=all&family2=all" 
               class="filter-btn <?= $filter_family === 'all' ? 'active' : '' ?>">
                All Variants
            </a>
            <?php foreach ($families as $fam): ?>
                <?php
                $this_fam_variants = $all_family_variants[$fam] ?? [];
                $is_selected = ($filter_family === $fam);
                $has_variants = !empty($this_fam_variants);
                $fam_id = md5($fam);
                ?>

                <?php if ($is_selected && $has_variants): ?>
                    <!-- Selected button with floating dropdown -->
                    <div class="relative-fam-dropdown" id="famDropdown_<?= $fam_id ?>">
                        <button onclick="toggleFamDropdown('<?= $fam_id ?>')"
                            class="filter-btn active" style="display:flex; align-items:center; gap:6px;">
                            <?= htmlspecialchars($fam) ?>
                            <i class="fas fa-angle-down" id="famChevron_<?= $fam_id ?>" style="font-size:11px; transition: transform 0.2s;"></i>
                        </button>
                        <!-- Floating dropdown -->
                        <div id="famMenu_<?= $fam_id ?>"
                            style="display:none; position:absolute; top:calc(100% + 4px); left:0; background:white; border:1px solid #e0e0e0; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.12); z-index:9999; min-width:180px; overflow:hidden;">
                            <?php foreach ($this_fam_variants as $fam2): ?>
                                <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= $filter_category ?>&family=<?= urlencode($fam) ?>&family2=<?= urlencode($fam2) ?>"
                                    style="display:flex; align-items:center; gap:8px; padding:10px 16px; font-family:'Montserrat',sans-serif; font-size:13px; font-weight:600; text-decoration:none; transition: background 0.15s;
                                    <?= $filter_family2 === $fam2 ? 'background: linear-gradient(135deg,#3b1f0f,#8a5a44); color:white;' : 'color:#555;' ?>"
                                    onmouseover="if('<?= $filter_family2 ?>'!=='<?= $fam2 ?>') this.style.background='#f5ede8'; this.style.color='#3b1f0f';"
                                    onmouseout="if('<?= $filter_family2 ?>'!=='<?= $fam2 ?>') this.style.background=''; this.style.color='#555';">
                                    <?php if ($filter_family2 === $fam2): ?>
                                        <i class="fas fa-check" style="font-size:11px;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-angle-right" style="font-size:11px; opacity:0.4;"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($fam2) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= $filter_category ?>&family=<?= urlencode($fam) ?>&family2=all" 
                       class="filter-btn <?= $is_selected ? 'active' : '' ?>">
                        <?= htmlspecialchars($fam) ?>
                        <?php if ($has_variants): ?>
                            <i class="fas fa-angle-down" style="font-size:11px; margin-left:4px; opacity:0.7;"></i>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<!-- Material Filter (compact inline bar) -->
<?php if (!empty($materials) || !empty($door_materials)): ?>
<div class="filter-divider"></div>
<div class="filter-section" style="padding: 12px 20px; display:flex; align-items:center; flex-wrap:wrap; gap:12px; border-left: 4px solid #8a5a44; margin-top: 0;">
    <div style="font-family:'Montserrat',sans-serif; font-size:13px; font-weight:700; color:#8a5a44; display:flex; align-items:center; gap:6px; white-space:nowrap;">
        <i class="fas fa-hammer"></i> Filter by Material:
    </div>

    <?php if (!empty($materials)): ?>
    <div style="display:flex; align-items:center; gap:8px;">
        <label style="font-family:'Montserrat',sans-serif; font-size:11px; font-weight:700; color:#999; white-space:nowrap;">CARCASS:</label>
        <select onchange="window.location.href=this.value"
            style="padding:6px 10px; border:2px solid #8a5a44; border-radius:8px; font-family:'Montserrat',sans-serif; font-size:12px; font-weight:600; color:#3b1f0f; background:white; cursor:pointer; outline:none;">
            <option value="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=all&door_material=<?= urlencode($filter_door_material) ?>"
                <?= $filter_material === 'all' ? 'selected' : '' ?>>All</option>
            <?php foreach ($materials as $mat): ?>
                <option value="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($mat) ?>&door_material=<?= urlencode($filter_door_material) ?>"
                    <?= $filter_material === $mat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($mat) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if (!empty($door_materials)): ?>
    <div style="display:flex; align-items:center; gap:8px;">
        <label style="font-family:'Montserrat',sans-serif; font-size:11px; font-weight:700; color:#999; white-space:nowrap;">DOOR:</label>
        <select onchange="window.location.href=this.value"
            style="padding:6px 10px; border:2px solid #8a5a44; border-radius:8px; font-family:'Montserrat',sans-serif; font-size:12px; font-weight:600; color:#3b1f0f; background:white; cursor:pointer; outline:none;">
            <option value="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($filter_material) ?>&door_material=all"
                <?= $filter_door_material === 'all' ? 'selected' : '' ?>>All</option>
            <?php foreach ($door_materials as $dmat): ?>
                <option value="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=<?= urlencode($filter_category) ?>&family=<?= urlencode($filter_family) ?>&family2=<?= urlencode($filter_family2) ?>&material=<?= urlencode($filter_material) ?>&door_material=<?= urlencode($dmat) ?>"
                    <?= $filter_door_material === $dmat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($dmat) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
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
                        <img src="<?= $image_path ?>" 
                             alt="<?= htmlspecialchars($item['item_name']) ?>" />
                    <?php else: ?>
                        <div class="no-image">
                            <i class="fas fa-image"></i>
                        </div>
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
            <span style="font-size:10px; font-weight:700; color:#999; text-transform:uppercase; margin-right:3px;">Carcass:</span>
            <?= htmlspecialchars($item['item_material']) ?>
        </span>
    <?php endif; ?>

    <?php if (!empty($item['door_material'])): ?>
        <span class="spec-item-quotation">
            <i class="fas fa-door-open"></i>
            <span style="font-size:10px; font-weight:700; color:#999; text-transform:uppercase; margin-right:3px;">Door:</span>
            <?= htmlspecialchars($item['door_material']) ?>
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
                        <span class="price-label">Price:</span>
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
        <p>No products available for the selected filters.</p>
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
document.addEventListener('click', function(e) {
    if (!e.target.closest('.relative-fam-dropdown')) {
        document.querySelectorAll('[id^="famMenu_"]').forEach(m => m.style.display = 'none');
        document.querySelectorAll('[id^="famChevron_"]').forEach(c => c.style.transform = '');
    }
});
</script>