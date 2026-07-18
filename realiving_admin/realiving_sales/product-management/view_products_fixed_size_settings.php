<?php
// manage_fixed_sizes.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1','superadmin', 'sales', 'designer']);

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

// Get product ID from URL
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    header("Location: view_products.php");
    exit();
}

// Fetch product information
$product_query = "SELECT i.*, dl.dimension_label_name 
                  FROM items i 
                  LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id
                  WHERE i.item_id = ?";
$stmt = $conn->prepare($product_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product_result = $stmt->get_result();

if ($product_result->num_rows === 0) {
    header("Location: view_products.php");
    exit();
}

$product = $product_result->fetch_assoc();
$stmt->close();

// Fetch main color and standard colors for the product
$main_color_name = $product['item_color'] ?? 'Default';

$standard_colors = [];
$colors_query = "SELECT standard_color_id, standard_color, standard_color_image_path 
                 FROM item_standard_color 
                 WHERE fk_standard_color = ? 
                 ORDER BY standard_color_id";
$stmt_colors = $conn->prepare($colors_query);
$stmt_colors->bind_param("i", $product_id);
$stmt_colors->execute();
$colors_result = $stmt_colors->get_result();
while ($color = $colors_result->fetch_assoc()) {
    $standard_colors[] = $color;
}
$stmt_colors->close();

// Fetch existing fixed sizes with pricing
$existing_sizes = [];
$sizes_query = "SELECT ifs.*, dl.dimension_label_name, 
                dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
                FROM item_fixed_sizes ifs
                LEFT JOIN dimension_label dl ON ifs.dimension_label_fk = dl.dimension_label_id
                WHERE ifs.item_fk = ? ORDER BY ifs.display_order";
$stmt = $conn->prepare($sizes_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$sizes_result = $stmt->get_result();
while ($row = $sizes_result->fetch_assoc()) {
    // Fetch pricing for this size
    $pricing_query = "SELECT * FROM item_size_color_pricing WHERE fixed_size_fk = ?";
    $stmt_pricing = $conn->prepare($pricing_query);
    $stmt_pricing->bind_param("i", $row['fixed_size_id']);
    $stmt_pricing->execute();
    $pricing_result = $stmt_pricing->get_result();
    $row['pricing'] = [];
    while ($pricing = $pricing_result->fetch_assoc()) {
        $row['pricing'][] = $pricing;
    }
    $stmt_pricing->close();
    
    $existing_sizes[] = $row;
}
$stmt->close();

// Fetch all dimension labels for fixed sizes
$dimension_labels = [];
$result = $conn->query("SELECT dimension_label_id, dimension_label_name, 
                        item_width_label_linear, item_height_label_linear, item_length_label_linear 
                        FROM dimension_label ORDER BY dimension_label_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dimension_labels[] = $row;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->autocommit(FALSE);
    
    try {
        // Delete existing fixed sizes
        $delete_sizes = $conn->prepare("DELETE FROM item_fixed_sizes WHERE item_fk = ?");
        $delete_sizes->bind_param("i", $product_id);
        $delete_sizes->execute();
        $delete_sizes->close();
        
        // Handle Fixed Sizes
$sizes_added = 0;
if (isset($_POST['fixed_sizes']) && is_array($_POST['fixed_sizes'])) {
    foreach ($_POST['fixed_sizes'] as $index => $size_data) {
        $dimension_label_fk = $size_data['dimension_label_fk'] ?? 0;
        $width = !empty($size_data['width']) ? $size_data['width'] : null;
        $height = !empty($size_data['height']) ? $size_data['height'] : null;
        $length = !empty($size_data['length']) ? $size_data['length'] : null;
        $unit = $size_data['unit'] ?? 'cm';
        $label = $size_data['label'] ?? '';
        
        if ($dimension_label_fk > 0 && ($width || $height || $length)) {
            $order = $index + 1;
            $stmt = $conn->prepare("INSERT INTO item_fixed_sizes (item_fk, dimension_label_fk, size_width, size_height, size_length, measurement_unit, size_label, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iidddssi", $product_id, $dimension_label_fk, $width, $height, $length, $unit, $label, $order);
            $stmt->execute();
            $new_size_id = $conn->insert_id;
            $stmt->close();
            
            // Insert pricing data for this size
            if (isset($size_data['pricing']) && is_array($size_data['pricing'])) {
                // Insert main color pricing
                if (isset($size_data['pricing']['main']) && !empty($size_data['pricing']['main'])) {
                    $stmt = $conn->prepare("INSERT INTO item_size_color_pricing (fixed_size_fk, color_type, color_reference_id, fixed_price) VALUES (?, 'main', NULL, ?)");
                    $stmt->bind_param("id", $new_size_id, $size_data['pricing']['main']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Insert standard color pricing
                if (isset($size_data['pricing']['standard']) && is_array($size_data['pricing']['standard'])) {
                    foreach ($size_data['pricing']['standard'] as $color_id => $price) {
                        if (!empty($price)) {
                            $stmt = $conn->prepare("INSERT INTO item_size_color_pricing (fixed_size_fk, color_type, color_reference_id, fixed_price) VALUES (?, 'standard', ?, ?)");
                            $stmt->bind_param("iid", $new_size_id, $color_id, $price);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
            
            $sizes_added++;
        }
    }
}
        
        $conn->commit();
        
        // Redirect to view_products.php with success message
        $_SESSION['success_message'] = "Fixed sizes updated successfully!";
        $_SESSION['success_details'] = array(
            'sizes' => $sizes_added
        );
        
        header("Location: view_products.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
    
    $conn->autocommit(TRUE);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Fixed Sizes - <?php echo htmlspecialchars($product['item_name']); ?></title>
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
            padding:.75rem 1.25rem; border-radius:9px;
            border:1px solid var(--adm-line);
            transition: border-color .2s ease, transform .2s ease;
        }
        .adm-btn-outline:hover{ border-color: var(--adm-ink); transform: translateY(-1px); }

        .adm-btn-danger{
            display:inline-flex; align-items:center; gap:8px;
            background:#FEF2F2; color:#DC2626;
            font-size:12px; font-weight:600;
            padding:.55rem 1rem; border-radius:8px;
            border:1px solid #FECACA;
            transition: background .2s ease, transform .2s ease;
        }
        .adm-btn-danger:hover{ background:#FEE2E2; transform: translateY(-1px); }

        .adm-btn-success{
            display:inline-flex; align-items:center; gap:10px;
            background: var(--adm-ink); color:#fff;
            font-size:14px; font-weight:700;
            padding:1rem 2.25rem; border-radius:10px;
            border:1px solid var(--adm-ink);
            transition: opacity .2s ease, transform .2s ease;
        }
        .adm-btn-success:hover{ opacity:.85; transform: translateY(-1px); color:#fff; }

        /* ── Cards / sections ───────────────────── */
        .adm-card{
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:12px;
        }

        .adm-section-icon{
            width:34px; height:34px; border-radius:9px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
        }
        .adm-section-title{ font-size:18px; font-weight:700; color: var(--adm-ink); display:flex; align-items:center; }
        .adm-section-hint{ font-size:12px; color: var(--adm-muted); }

        .adm-stat-chip{
            display:inline-flex; align-items:center; gap:6px;
            font-size:11.5px; font-weight:600; color: var(--adm-ink);
            background: var(--adm-bg); border:1px solid var(--adm-line);
            padding:.4rem .75rem; border-radius:8px;
        }
        .adm-stat-chip i{ color:#16A34A; }

        /* ── Info banner ────────────────────────── */
        .adm-info-banner{
            background: var(--adm-surface);
            border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:1.1rem 1.25rem;
        }

        /* ── Error alert ─────────────────────────── */
        .adm-alert-error{
            display:flex; align-items:flex-start; gap:.9rem;
            background: var(--adm-surface);
            border:1px solid var(--adm-line); border-left:3px solid #DC2626;
            border-radius:10px; padding:1.1rem 1.25rem;
        }
        .adm-alert-icon-error{
            width:36px; height:36px; border-radius:999px; flex-shrink:0;
            background:#FEF2F2; color:#DC2626;
            display:flex; align-items:center; justify-content:center; font-size:15px;
        }

        /* ── Size cards ─────────────────────────── */
        .size-card {
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:12px;
            padding: 1.5rem;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }
        .size-card:hover {
            border-color: var(--adm-ink);
            box-shadow: 0 16px 34px -22px rgba(11, 11, 11, 0.35);
        }

        .adm-size-badge{
            width:32px; height:32px; border-radius:999px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:12.5px; font-weight:700; flex-shrink:0;
        }

        .adm-field-label{
            display:flex; align-items:center; gap:6px;
            font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px;
            color: var(--adm-soft); margin-bottom:.5rem;
        }
        .adm-field-label i{ color: var(--adm-muted); font-size:11px; }

        .adm-input, .adm-select-field{
            width:100%;
            padding:.7rem .9rem; border-radius:8px;
            border:1px solid var(--adm-line); background: var(--adm-bg);
            font-size:13px; font-weight:500; color: var(--adm-ink);
            transition: border-color .2s ease, background .2s ease;
        }
        .adm-input:focus, .adm-select-field:focus{
            outline:none; border-color: var(--adm-ink); background: var(--adm-surface);
        }
        .adm-input::placeholder{ color: var(--adm-muted); }

        /* ── Pricing blocks ─────────────────────── */
        .adm-price-block-main{
            background: var(--adm-bg);
            border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:1rem;
        }
        .adm-price-block-standard{
            background: var(--adm-bg);
            border:1px solid var(--adm-line); border-left:3px solid var(--adm-muted);
            border-radius:10px; padding:1rem;
        }
        .adm-price-input-wrap{ position:relative; }
        .adm-price-input-wrap span{
            position:absolute; left:.75rem; top:50%; transform:translateY(-50%);
            color: var(--adm-muted); font-weight:700; font-size:13px;
        }
        .adm-price-input-wrap input{ padding-left:1.85rem; }

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

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-6xl">

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <a href="<?= BASE_URL ?>view-products" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>All Products</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog · Fixed Sizes</div>
                    <h1 class="adm-title">Manage Fixed Sizes</h1>
                    <p class="adm-subtitle mt-1">
                        <span class="font-semibold" style="color:var(--adm-ink);"><?php echo htmlspecialchars($product['item_name']); ?></span>
                        <span class="mx-2" style="color:var(--adm-muted);">•</span>
                        <span><?php echo htmlspecialchars($product['item_code']); ?></span>
                    </p>
                </div>
                <div class="flex gap-3">
                    <a href="add_product_details.php?id=<?php echo $product_id; ?>" class="adm-btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Details</span>
                    </a>
                    <a href="<?= BASE_URL ?>view-products" class="adm-btn">
                        <i class="fas fa-th"></i>
                        <span>All Products</span>
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
        <div class="adm-alert-error mb-6 adm-fade">
            <div class="adm-alert-icon-error"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="flex-1">
                <h3 class="text-sm font-bold mb-1" style="color:var(--adm-ink);">Something went wrong</h3>
                <p class="text-sm" style="color:var(--adm-soft);"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <!-- Fixed Sizes Section -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
                    <div class="flex items-center gap-3">
                        <div class="adm-section-icon"><i class="fas fa-ruler-combined"></i></div>
                        <div>
                            <h2 class="adm-section-title">
                                Fixed Sizes
                                <?php if (!empty($existing_sizes)): ?>
                                    <span class="adm-stat-chip ml-3">
                                        <i class="fas fa-check-circle"></i>
                                        <?php echo count($existing_sizes); ?> existing
                                    </span>
                                <?php endif; ?>
                            </h2>
                            <div class="adm-section-hint">Add measurements and pricing per color</div>
                        </div>
                    </div>
                    <button type="button" onclick="addFixedSize()" class="adm-btn">
                        <i class="fas fa-plus"></i>
                        <span>Add New Size</span>
                    </button>
                </div>

                <div class="mb-6 adm-info-banner">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-circle-info mt-1" style="color:var(--adm-ink);"></i>
                        <div>
                            <p class="text-sm font-bold mb-1" style="color:var(--adm-ink);">How to add fixed sizes with pricing:</p>
                            <ul class="text-xs space-y-1 list-disc list-inside" style="color:var(--adm-soft);">
                                <li>Select the dimension label that matches your product's measurement type</li>
                                <li>Enter at least one dimension (width, height, or length)</li>
                                <li>Choose the appropriate measurement unit</li>
                                <li>Optionally add a descriptive label (e.g., "Small", "Medium", "Large")</li>
                                <li><strong>Set fixed prices for the main color and each standard color variant</strong></li>
                                <li>Leave price fields empty if that size-color combination is not available</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div id="fixedSizesContainer" class="space-y-4">
                    <?php if (empty($existing_sizes)): ?>
                    <div class="adm-empty-state">
                        <div class="adm-empty-icon"><i class="fas fa-ruler-combined"></i></div>
                        <p class="text-base font-bold mb-1" style="color:var(--adm-ink);">No fixed sizes added yet</p>
                        <p class="text-sm" style="color:var(--adm-muted);">Click "Add New Size" to get started</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-center gap-4 pt-4">
                <a href="view_products.php" class="adm-btn-outline" style="padding:1rem 2rem;">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </a>
                <button type="submit" class="adm-btn-success">
                    <i class="fas fa-save"></i>
                    <span>Save Fixed Sizes</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        let sizeCount = <?php echo count($existing_sizes); ?>;
const dimensionLabels = <?php echo json_encode($dimension_labels); ?>;
const mainColorName = <?php echo json_encode($main_color_name); ?>;
const standardColors = <?php echo json_encode($standard_colors); ?>;

        // Load existing sizes on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($existing_sizes as $index => $size): ?>
            addFixedSize(
                <?php echo $size['dimension_label_fk']; ?>,
                <?php echo $size['size_width'] ?? 'null'; ?>,
                <?php echo $size['size_height'] ?? 'null'; ?>,
                <?php echo $size['size_length'] ?? 'null'; ?>,
                <?php echo json_encode($size['measurement_unit']); ?>,
                <?php echo json_encode($size['size_label']); ?>,
                <?php echo json_encode($size['pricing'] ?? []); ?>
            );
            <?php endforeach; ?>
        });

        function addFixedSize(dimLabelFk = '', width = null, height = null, length = null, unit = 'cm', label = '', existingPricing = []) {
            const container = document.getElementById('fixedSizesContainer');
            
            // Remove "no sizes" message if it exists
            const noSizesMsg = container.querySelector('.adm-empty-state');
            if (noSizesMsg) {
                noSizesMsg.remove();
            }

            const sizeDiv = document.createElement('div');
            sizeDiv.className = 'size-card';
            sizeDiv.id = 'size_' + sizeCount;
            
            let dimensionOptions = '<option value="">Select Dimension Label</option>';
            let selectedLabel = null;
            
            dimensionLabels.forEach(dimLabel => {
                const selected = dimLabelFk == dimLabel.dimension_label_id ? 'selected' : '';
                if (selected) selectedLabel = dimLabel;
                dimensionOptions += `<option value="${dimLabel.dimension_label_id}" 
                                     data-width="${dimLabel.item_width_label_linear || 'Width'}"
                                     data-height="${dimLabel.item_height_label_linear || 'Height'}"
                                     data-length="${dimLabel.item_length_label_linear || 'Length'}"
                                     ${selected}>
                    ${dimLabel.dimension_label_name}
                </option>`;
            });
            
            const widthLabel = selectedLabel ? (selectedLabel.item_width_label_linear || 'Width') : 'Width';
            const heightLabel = selectedLabel ? (selectedLabel.item_height_label_linear || 'Height') : 'Height';
            const lengthLabel = selectedLabel ? (selectedLabel.item_length_label_linear || 'Length') : 'Length';
            
            const widthVal = width !== null ? width : '';
            const heightVal = height !== null ? height : '';
            const lengthVal = length !== null ? length : '';
            const escapedLabel = label.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            
            // Get existing pricing values
            let mainColorPrice = '';
            let standardColorPrices = {};
            
            if (Array.isArray(existingPricing)) {
                existingPricing.forEach(pricing => {
                    if (pricing.color_type === 'main') {
                        mainColorPrice = pricing.fixed_price;
                    } else if (pricing.color_type === 'standard' && pricing.color_reference_id) {
                        standardColorPrices[pricing.color_reference_id] = pricing.fixed_price;
                    }
                });
            }
            
            // Build pricing section HTML
            let pricingHTML = `
                <!-- Pricing Section -->
                <div class="mt-6 pt-6" style="border-top:1px solid var(--adm-line);">
                    <h4 class="text-sm font-bold mb-4 flex items-center" style="color:var(--adm-ink);">
                        <i class="fas fa-tags mr-2" style="color:var(--adm-muted);"></i>
                        Pricing per Color
                    </h4>
                    
                    <div class="space-y-3">
                        <!-- Main Color Pricing -->
                        <div class="adm-price-block-main">
                            <label class="adm-field-label">
                                <i class="fas fa-palette"></i>
                                Main Color: <span style="color:var(--adm-ink);">${mainColorName || 'Default'}</span>
                            </label>
                            <div class="adm-price-input-wrap">
                                <span>&#8369;</span>
                                <input type="number" name="fixed_sizes[${sizeCount}][pricing][main]" 
                                    step="0.01" 
                                    value="${mainColorPrice}"
                                    class="adm-input"
                                    placeholder="0.00">
                            </div>
                        </div>
            `;
            
            // Add standard colors pricing if available
            if (standardColors && standardColors.length > 0) {
                pricingHTML += `
                    <!-- Standard Colors Pricing -->
                    <div class="adm-price-block-standard">
                        <h5 class="adm-field-label">
                            <i class="fas fa-swatchbook"></i>
                            Standard Colors
                        </h5>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                `;
                
                standardColors.forEach(color => {
                    const colorPrice = standardColorPrices[color.standard_color_id] || '';
                    pricingHTML += `
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--adm-soft);">
                                ${color.standard_color}
                            </label>
                            <div class="adm-price-input-wrap">
                                <span style="font-size:12px;">&#8369;</span>
                                <input type="number" name="fixed_sizes[${sizeCount}][pricing][standard][${color.standard_color_id}]" 
                                    step="0.01" 
                                    value="${colorPrice}"
                                    class="adm-input text-sm"
                                    placeholder="0.00">
                            </div>
                        </div>
                    `;
                });
                
                pricingHTML += `
                        </div>
                    </div>
                `;
            }
            
            pricingHTML += `
                    </div>
                </div>
            `;
            
            sizeDiv.innerHTML = `
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-base font-bold flex items-center" style="color:var(--adm-ink);">
                        <div class="adm-size-badge mr-3">
                            ${sizeCount + 1}
                        </div>
                        Fixed Size ${sizeCount + 1}
                    </h3>
                    <button type="button" onclick="removeFixedSize(${sizeCount})" class="adm-btn-danger">
                        <i class="fas fa-trash"></i>
                        Remove
                    </button>
                </div>

                <div class="space-y-4">
                    <!-- Dimension Label -->
                    <div>
                        <label class="adm-field-label">
                            <i class="fas fa-tag"></i>
                            Dimension Label *
                        </label>
                        <select name="fixed_sizes[${sizeCount}][dimension_label_fk]" required
                                onchange="updateSizeLabels(${sizeCount}, this.value)"
                                class="adm-select-field">
                            ${dimensionOptions}
                        </select>
                    </div>

                    <!-- Dimensions Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="adm-field-label">
                                <i class="fas fa-arrows-alt-h"></i>
                                <span id="width_label_${sizeCount}">${widthLabel}</span>
                            </label>
                            <input type="number" name="fixed_sizes[${sizeCount}][width]" step="0.01" value="${widthVal}"
                                class="adm-input"
                                placeholder="0.00">
                        </div>

                        <div>
                            <label class="adm-field-label">
                                <i class="fas fa-arrows-alt-v"></i>
                                <span id="height_label_${sizeCount}">${heightLabel}</span>
                            </label>
                            <input type="number" name="fixed_sizes[${sizeCount}][height]" step="0.01" value="${heightVal}"
                                class="adm-input"
                                placeholder="0.00">
                        </div>

                        <div>
                            <label class="adm-field-label">
                                <i class="fas fa-ruler"></i>
                                <span id="length_label_${sizeCount}">${lengthLabel}</span>
                            </label>
                            <input type="number" name="fixed_sizes[${sizeCount}][length]" step="0.01" value="${lengthVal}"
                                class="adm-input"
                                placeholder="0.00">
                        </div>
                    </div>

                    <!-- Unit and Label -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="adm-field-label">
                                <i class="fas fa-ruler-combined"></i>
                                Measurement Unit *
                            </label>
                            <select name="fixed_sizes[${sizeCount}][unit]" required class="adm-select-field">
                                <option value="cm" ${unit === 'cm' ? 'selected' : ''}>Centimeters (cm)</option>
                                <option value="mm" ${unit === 'mm' ? 'selected' : ''}>Millimeters (mm)</option>
                                <option value="inch" ${unit === 'inch' ? 'selected' : ''}>Inches (inch)</option>
                                <option value="meter" ${unit === 'meter' ? 'selected' : ''}>Meters (meter)</option>
                                <option value="feet" ${unit === 'feet' ? 'selected' : ''}>Feet (feet)</option>
                            </select>
                        </div>

                        <div>
                            <label class="adm-field-label">
                                <i class="fas fa-tag"></i>
                                Size Label <span class="font-normal normal-case" style="color:var(--adm-muted);">(Optional)</span>
                            </label>
                            <input type="text" name="fixed_sizes[${sizeCount}][label]" value="${escapedLabel}"
                                class="adm-input"
                                placeholder="e.g., Small, Medium, Large, Standard">
                        </div>
                    </div>
                    
                    ${pricingHTML}
                </div>
            `;

            container.appendChild(sizeDiv);
            sizeCount++;
        }

        function removeFixedSize(index) {
            const sizeDiv = document.getElementById('size_' + index);
            if (sizeDiv) {
                // Add animation before removing
                sizeDiv.style.transition = 'all 0.3s ease';
                sizeDiv.style.opacity = '0';
                sizeDiv.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    sizeDiv.remove();
                    
                    const container = document.getElementById('fixedSizesContainer');
                    const remainingSizes = container.querySelectorAll('.size-card');
                    
                    if (remainingSizes.length === 0) {
                        container.innerHTML = `
                            <div class="adm-empty-state">
                                <div class="adm-empty-icon"><i class="fas fa-ruler-combined"></i></div>
                                <p class="text-base font-bold mb-1" style="color:var(--adm-ink);">No fixed sizes added yet</p>
                                <p class="text-sm" style="color:var(--adm-muted);">Click "Add New Size" to get started</p>
                            </div>
                        `;
                    }
                }, 300);
            }
        }

        function updateSizeLabels(sizeIndex, dimensionLabelId) {
            const select = event.target;
            const selectedOption = select.options[select.selectedIndex];
            
            const widthLabel = selectedOption.getAttribute('data-width') || 'Width';
            const heightLabel = selectedOption.getAttribute('data-height') || 'Height';
            const lengthLabel = selectedOption.getAttribute('data-length') || 'Length';
            
            document.getElementById(`width_label_${sizeIndex}`).textContent = widthLabel;
            document.getElementById(`height_label_${sizeIndex}`).textContent = heightLabel;
            document.getElementById(`length_label_${sizeIndex}`).textContent = lengthLabel;
        }
    </script>
</body>
</html>