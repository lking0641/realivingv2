<?php
// edit_quotation_entry.php
// Edit an existing quotation entry (customized or fixed size)
include $includes ['mainbody'];
require_role(['admin1', 'superadmin', 'sales', 'designer', 'technical_designer', 'project_coordinator']);

$admin_id = $_SESSION['admin_id'];

// Params
$client_id      = isset($_GET['client_id'])   ? intval($_GET['client_id'])       : 0;
$client_name    = isset($_GET['client_name']) ? urldecode($_GET['client_name'])   : '';
$client_email   = isset($_GET['email'])       ? urldecode($_GET['email'])         : '';
$client_address = isset($_GET['address'])     ? urldecode($_GET['address'])       : '';
$client_contact = isset($_GET['contact'])     ? urldecode($_GET['contact'])       : '';
$entry_id       = isset($_GET['entry_id'])    ? intval($_GET['entry_id'])         : 0;
$fixed_id       = isset($_GET['fixed_id'])    ? intval($_GET['fixed_id'])         : 0;

$is_fixed = $fixed_id > 0;

// Fetch client info
$project_name  = '';
$business_type = '';
if ($client_id) {
    $stmt = $conn->prepare("SELECT nameproject, business_type FROM user_info WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $project_name  = $row['nameproject'];
        $business_type = $row['business_type'];
    }
}
$business_type_label = $business_type === 'Non-Project' ? 'Individual' : $business_type;

// Check if quotation is locked
$quotationDone = false;
$qLock = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Quotation'");
$qLock->bind_param("i", $client_id);
$qLock->execute();
$lockRow = $qLock->get_result()->fetch_assoc();
if ($lockRow && $lockRow['status'] === 'Done') {
    $quotationDone = true;
}

// ── Fetch the existing entry ──
$entry = null;
$item  = null;

if (!$is_fixed && $entry_id) {
    // CUSTOMIZED entry
    $stmt = $conn->prepare("
        SELECT e.*, 
               COALESCE(e.item_name, i.item_name) AS resolved_name,
               i.item_image_path AS orig_image,
               i.mark_up        AS default_mark_up,
               i.labor_cost     AS default_labor_cost,
               i.jackup         AS default_jackup
        FROM quotation_entries e
        LEFT JOIN items i ON e.entry_item_id = i.item_id
        WHERE e.id = ? AND e.client_id = ? AND e.admin_id = ?
    ");
    $stmt->bind_param("iii", $entry_id, $client_id, $admin_id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();

    if (!$entry) {
        header("Location: " . BASE_URL . "computation-list?client_id=$client_id&client_name=" . urlencode($client_name));
        exit();
    }

    // Fetch dimension info
    $dimStmt = $conn->prepare("SELECT * FROM dimension_measurement WHERE dimension_msmt_id = ?");
    $dimStmt->bind_param("i", $entry['dimension_msmt_id']);
    $dimStmt->execute();
    $dimension = $dimStmt->get_result()->fetch_assoc();

    $lblStmt = $conn->prepare("SELECT * FROM dimension_label WHERE dimension_label_id = ?");
    $lblStmt->bind_param("i", $entry['dimension_label_id']);
    $lblStmt->execute();
    $labels = $lblStmt->get_result()->fetch_assoc();

    // Fetch item colors
    $colorStmt = $conn->prepare("SELECT * FROM item_standard_color WHERE fk_standard_color = ?");
    $colorStmt->bind_param("i", $entry['entry_item_id']);
    $colorStmt->execute();
    $colors = $colorStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch addons for this entry
    $addonStmt = $conn->prepare("
    SELECT a.*, p.addon_name, p.addon_price AS default_price, p.labor_cost AS default_labor_cost,
           p.addon_image_path, p.addon_description, p.addon_type, p.addon_category,
           p.has_dimension, p.dimension_type,
           p.dimension_label_1, p.dimension_label_2, p.dimension_label_3,
           p.dimension_value_1, p.dimension_value_2, p.dimension_value_3,
           p.labor_cost_jack_up AS addon_jackup,
           pal.is_required, pal.max_quantity
    FROM quotation_entry_addons a
    JOIN product_addons p ON a.addon_id = p.id
    LEFT JOIN product_addon_links pal ON pal.addon_id = p.id AND pal.item_id = ?
    WHERE a.quotation_entry_id = ?
    ORDER BY p.addon_category, p.addon_name
");
    $addonStmt->bind_param("ii", $entry['entry_item_id'], $entry_id);
    $addonStmt->execute();
    $savedAddons = $addonStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // All available addons for this item (grouped by category)
    $allAddonStmt = $conn->prepare("
    SELECT pa.*, pa.labor_cost AS default_labor_cost, pal.is_required, pal.max_quantity, pal.display_order
    FROM product_addons pa
    INNER JOIN product_addon_links pal ON pa.id = pal.addon_id
    WHERE pal.item_id = ?
    ORDER BY pa.addon_category, pal.display_order, pa.addon_name
");
    $allAddonStmt->bind_param("i", $entry['entry_item_id']);
    $allAddonStmt->execute();
    $allAddons = $allAddonStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Map saved addons by addon_id for easy lookup
    $savedAddonMap = [];
    foreach ($savedAddons as $sa) {
        $savedAddonMap[$sa['addon_id']] = $sa;
    }

    // Group all addons by category
    $addons_by_category = [];
    foreach ($allAddons as $a) {
        $cat = $a['addon_category'] ?: 'Uncategorized';
        $addons_by_category[$cat][] = $a;
    }

} else if ($is_fixed && $fixed_id) {
    // FIXED SIZE entry
    $stmt = $conn->prepare("
        SELECT qfs.*,
               ifs.size_label, ifs.size_width, ifs.size_height, ifs.size_length,
               ifs.measurement_unit, ifs.dimension_label_fk,
               dl.dimension_label_name,
               dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
        FROM quotation_fixed_sizes qfs
        LEFT JOIN item_fixed_sizes ifs ON qfs.fixed_size_id = ifs.fixed_size_id
        LEFT JOIN dimension_label dl ON ifs.dimension_label_fk = dl.dimension_label_id
        WHERE qfs.id = ? AND qfs.client_id = ? AND qfs.admin_id = ?
    ");
    $stmt->bind_param("iii", $fixed_id, $client_id, $admin_id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();

    if (!$entry) {
        header("Location: " . BASE_URL . "computation-list?client_id=$client_id&client_name=" . urlencode($client_name));
        exit();
    }

    // Fetch item colors
    $colorStmt = $conn->prepare("SELECT * FROM item_standard_color WHERE fk_standard_color = ?");
    $colorStmt->bind_param("i", $entry['item_id']);
    $colorStmt->execute();
    $colors = $colorStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // All fixed sizes for this item
    $sizesStmt = $conn->prepare("
        SELECT fs.*, dl.dimension_label_name,
               dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
        FROM item_fixed_sizes fs
        LEFT JOIN dimension_label dl ON fs.dimension_label_fk = dl.dimension_label_id
        WHERE fs.item_fk = ? ORDER BY fs.display_order
    ");
    $sizesStmt->bind_param("i", $entry['item_id']);
    $sizesStmt->execute();
    $fixedSizesRaw = $sizesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch pricing per size
    $fixed_sizes = [];
    foreach ($fixedSizesRaw as $sz) {
        $prStmt = $conn->prepare("SELECT * FROM item_size_color_pricing WHERE fixed_size_fk = ?");
        $prStmt->bind_param("i", $sz['fixed_size_id']);
        $prStmt->execute();
        $pricing = $prStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sz['main_color_price'] = null;
        $sz['standard_color_prices'] = [];
        foreach ($pricing as $p) {
            if ($p['color_type'] === 'main') {
                $sz['main_color_price'] = $p['fixed_price'];
            } elseif ($p['color_type'] === 'standard') {
                $sz['standard_color_prices'][$p['color_reference_id']] = $p['fixed_price'];
            }
        }
        $fixed_sizes[] = $sz;
    }

    // Fetch saved addons for this fixed size entry
    $fixedAddonStmt = $conn->prepare("
    SELECT a.*, p.addon_name, p.addon_price AS default_price, p.labor_cost AS default_labor_cost,
           p.addon_image_path, p.addon_description,
           p.has_dimension, p.dimension_type,
           p.dimension_label_1, p.dimension_label_2, p.dimension_label_3,
           p.dimension_value_1, p.dimension_value_2, p.dimension_value_3,
           p.labor_cost_jack_up AS addon_jackup,
           pal.is_required, pal.max_quantity
    FROM quotation_fixed_size_addons a
    JOIN product_addons p ON a.addon_id = p.id
    LEFT JOIN product_addon_links pal ON pal.addon_id = p.id AND pal.item_id = ?
    WHERE a.quotation_fixed_size_id = ?
    ORDER BY a.addon_category, p.addon_name
");
    $fixedAddonStmt->bind_param("ii", $entry['item_id'], $fixed_id);
    $fixedAddonStmt->execute();
    $savedAddons = $fixedAddonStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // All available addons for this item
    $allAddonStmt = $conn->prepare("
    SELECT pa.*, pa.labor_cost AS default_labor_cost, pal.is_required, pal.max_quantity, pal.display_order
    FROM product_addons pa
    INNER JOIN product_addon_links pal ON pa.id = pal.addon_id
    WHERE pal.item_id = ?
    ORDER BY pa.addon_category, pal.display_order, pa.addon_name
");
    $allAddonStmt->bind_param("i", $entry['item_id']);
    $allAddonStmt->execute();
    $allAddons = $allAddonStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $savedAddonMap = [];
    foreach ($savedAddons as $sa) {
        $savedAddonMap[$sa['addon_id']] = $sa;
    }

    $addons_by_category = [];
    foreach ($allAddons as $a) {
        $cat = $a['addon_category'] ?: 'Uncategorized';
        $addons_by_category[$cat][] = $a;
    }
}

// ── Handle form save ──
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_edit'])) {
    if ($quotationDone) {
        $errorMsg = 'Quotation is locked. No changes allowed.';
    } else {
        if (!$is_fixed) {
            // ── Save CUSTOMIZED entry ──
            $upStmt = $conn->prepare("
                UPDATE quotation_entries SET
                    item_name        = ?,
                    unit_price       = ?,
                    width            = ?,
                    height           = ?,
                    length           = ?,
                    mark_up          = ?,
                    jackup           = ?,
                    labor_cost       = ?,
                    quantity         = ?,
                    unit_type        = ?,
                    area             = ?,
                    unit_mode        = ?,
                    color_label      = ?,
                    color_image_path = ?
                WHERE id = ? AND client_id = ? AND admin_id = ?
            ");
            $upStmt->bind_param(
                "sdddddddisssssiii",
                $_POST['item_name'],
                $_POST['unit_price'],
                $_POST['width'],
                $_POST['height'],
                $_POST['length'],
                $_POST['mark_up'],
                $_POST['jackup'],
                $_POST['labor_cost'],
                $_POST['quantity'],
                $_POST['unit_type'],
                $_POST['area'],
                $_POST['unit_mode'],
                $_POST['color_label'],
                $_POST['color_image_path'],
                $entry_id, $client_id, $admin_id
            );
            $upStmt->execute();

            // Re-insert selected addons
            if (!empty($_POST['addon_selected'])) {
    $insAddon = $conn->prepare("
    INSERT INTO quotation_entry_addons
      (quotation_entry_id, addon_id, quantity, price, labor_cost, note,
       dimension_type, dimension_label_1, dimension_label_2, dimension_label_3,
       dimension_value_1, dimension_value_2, dimension_value_3,
       addon_jackup, user_dim_value_1, user_dim_value_2, user_dim_value_3, computed_area)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
foreach ($_POST['addon_selected'] as $addonId => $isSelected) {
    if ($isSelected == '1') {
        $qty       = intval($_POST['addon_qty'][$addonId]         ?? 1);
        $price     = floatval($_POST['addon_price'][$addonId]     ?? 0);
        $labor     = floatval($_POST['addon_labor_cost'][$addonId]?? 0);
        $note      = trim($_POST['addon_note'][$addonId]          ?? '');
        $dimType   = $_POST['addon_dim_type'][$addonId]           ?? null;
        $dimLabel1 = $_POST['addon_dim_label_1'][$addonId]        ?? null;
        $dimLabel2 = $_POST['addon_dim_label_2'][$addonId]        ?? null;
        $dimLabel3 = $_POST['addon_dim_label_3'][$addonId]        ?? null;
        $dimDef1   = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
        $dimDef2   = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
        $dimDef3   = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
        $jackupVal = !empty($_POST['addon_jackup_val'][$addonId])    ? floatval($_POST['addon_jackup_val'][$addonId])    : null;
        $userDim1  = !empty($_POST['addon_user_dim_1'][$addonId])    ? floatval($_POST['addon_user_dim_1'][$addonId])    : null;
        $userDim2  = !empty($_POST['addon_user_dim_2'][$addonId])    ? floatval($_POST['addon_user_dim_2'][$addonId])    : null;
        $userDim3  = !empty($_POST['addon_user_dim_3'][$addonId])    ? floatval($_POST['addon_user_dim_3'][$addonId])    : null;
        $compArea  = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

        $insAddon->bind_param(
            "iiiddsssssdddddddd",
            $entry_id, $addonId, $qty, $price, $labor, $note,
            $dimType, $dimLabel1, $dimLabel2, $dimLabel3,
            $dimDef1, $dimDef2, $dimDef3, $jackupVal,
            $userDim1, $userDim2, $userDim3, $compArea
        );
        $insAddon->execute();
    }
}
}

            $successMsg = 'Customized entry updated successfully.';

        } else {
            // ── Save FIXED SIZE entry ──
            $selColorId = $_POST['selected_color_id'] ?? null;
            if ($selColorId === 'main' || empty($selColorId)) $selColorId = null;
            else $selColorId = intval($selColorId);

            $upStmt = $conn->prepare("
                UPDATE quotation_fixed_sizes SET
                    base_price          = ?,
                    quantity            = ?,
                    unit_type           = ?,
                    area                = ?,
                    color_label         = ?,
                    color_image_path    = ?,
                    selected_color_type = ?,
                    selected_color_id   = ?,
                    fixed_size_id       = ?
                WHERE id = ? AND client_id = ? AND admin_id = ?
            ");
            $upStmt->bind_param(
                "disssssiiiii",
                $_POST['base_price'],
                $_POST['quantity'],
                $_POST['unit_type'],
                $_POST['area'],
                $_POST['color_label'],
                $_POST['color_image_path'],
                $_POST['selected_color_type'],
                $selColorId,
                $_POST['fixed_size_id'],
                $fixed_id, $client_id, $admin_id
            );
            $upStmt->execute();

            if (!empty($_POST['addon_selected'])) {
    $insFixed = $conn->prepare("
    INSERT INTO quotation_fixed_size_addons
      (quotation_fixed_size_id, addon_id, addon_category, quantity, price, labor_cost, note,
       dimension_type, dimension_label_1, dimension_label_2, dimension_label_3,
       dimension_value_1, dimension_value_2, dimension_value_3,
       addon_jackup, user_dim_value_1, user_dim_value_2, user_dim_value_3, computed_area)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
foreach ($_POST['addon_selected'] as $addonId => $isSelected) {
    if ($isSelected == '1') {
        $cat       = $_POST['addon_category'][$addonId]           ?? 'Uncategorized';
        $qty       = intval($_POST['addon_qty'][$addonId]         ?? 1);
        $price     = floatval($_POST['addon_price'][$addonId]     ?? 0);
        $labor     = floatval($_POST['addon_labor_cost'][$addonId]?? 0);
        $note      = trim($_POST['addon_note'][$addonId]          ?? '');
        $dimType   = $_POST['addon_dim_type'][$addonId]           ?? null;
        $dimLabel1 = $_POST['addon_dim_label_1'][$addonId]        ?? null;
        $dimLabel2 = $_POST['addon_dim_label_2'][$addonId]        ?? null;
        $dimLabel3 = $_POST['addon_dim_label_3'][$addonId]        ?? null;
        $dimDef1   = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
        $dimDef2   = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
        $dimDef3   = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
        $jackupVal = !empty($_POST['addon_jackup_val'][$addonId])    ? floatval($_POST['addon_jackup_val'][$addonId])    : null;
        $userDim1  = !empty($_POST['addon_user_dim_1'][$addonId])    ? floatval($_POST['addon_user_dim_1'][$addonId])    : null;
        $userDim2  = !empty($_POST['addon_user_dim_2'][$addonId])    ? floatval($_POST['addon_user_dim_2'][$addonId])    : null;
        $userDim3  = !empty($_POST['addon_user_dim_3'][$addonId])    ? floatval($_POST['addon_user_dim_3'][$addonId])    : null;
        $compArea  = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

        $insFixed->bind_param(
            "iisiddssssddddddddd",
            $fixed_id, $addonId, $cat, $qty, $price, $labor, $note,
            $dimType, $dimLabel1, $dimLabel2, $dimLabel3,
            $dimDef1, $dimDef2, $dimDef3, $jackupVal,
            $userDim1, $userDim2, $userDim3, $compArea
        );
        $insFixed->execute();
    }
}
}

            $successMsg = 'Fixed size entry updated successfully.';
        }

        // Redirect back to computation list on success
        if (empty($errorMsg)) {
            header("Location: " . BASE_URL . "computation-list?client_id=$client_id&client_name=" . urlencode($client_name) .
                   "&email=" . urlencode($client_email) .
                   "&address=" . urlencode($client_address) .
                   "&contact=" . urlencode($client_contact) .
                   "&edit_success=1");
            exit();
        }
    }
}

// Existing areas for suggestions
$areaStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ? AND admin_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ? AND admin_id = ?
    ORDER BY area
");
$areaStmt->bind_param("iiii", $client_id, $admin_id, $client_id, $admin_id);
$areaStmt->execute();
$existingAreas = $areaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$existingAreasList = array_column($existingAreas, 'area');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Quotation Entry</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .hide-scrollbar::-webkit-scrollbar { display:none; }
    .hide-scrollbar { -ms-overflow-style:none; scrollbar-width:none; }
    .category-tab.active {
      border-color:#4f46e5;
      background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
      color:white;
    }
    .category-tab.active i,
    .category-tab.active span { color:white !important; }
    .addon-card { cursor:pointer; transition:all 0.2s; }
    .addon-card:hover { transform:translateY(-2px); }
    .size-select-btn.active {
      background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
      color:white; border-color:#667eea;
    }
    .save-btn {
      background:linear-gradient(135deg,#10b981 0%,#059669 100%);
      transition:all 0.2s;
    }
    .save-btn:hover { opacity:0.9; transform:translateY(-1px); }
    .category-tab:not(.bg-indigo-600):hover i {
  color: #4f46e5 !important;
}
.category-tab:not(.bg-indigo-600):hover span.font-semibold {
  color: #4338ca !important;
}
  </style>
</head>
<body class="bg-gray-50">

<!-- Header -->
<div class="bg-white shadow-sm border-b">
  <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="computation-list?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
       class="flex items-center gap-2 text-indigo-600 hover:text-indigo-800 font-semibold transition">
      <i class="fas fa-arrow-left"></i>
      <span>Back to Computation List</span>
    </a>
    <div class="text-right">
      <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($client_name) ?></h1>
      <p class="text-sm text-gray-500"><?= htmlspecialchars($project_name) ?></p>
    </div>
  </div>
</div>

<div class="max-w-5xl mx-auto px-4 py-8">

  <!-- Page Title -->
  <div class="flex items-center gap-3 mb-6">
    <div style="background:<?= $is_fixed ? '#8b5cf6' : '#3b82f6' ?>; width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center;">
      <i class="fas fa-edit" style="color:white; font-size:18px;"></i>
    </div>
    <div>
      <h2 class="text-2xl font-bold text-gray-900">
        Edit <?= $is_fixed ? 'Fixed Size' : 'Customized' ?> Entry
      </h2>
      <p class="text-sm text-gray-500">
        <?= htmlspecialchars($entry['item_name'] ?? $entry['resolved_name'] ?? '') ?>
        <?php if (!empty($entry['area'])): ?>
          &mdash; <span class="text-indigo-600 font-medium"><?= htmlspecialchars($entry['area']) ?></span>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($is_fixed): ?>
      <span class="ml-auto px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-bold">Fixed Size</span>
    <?php else: ?>
      <span class="ml-auto px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-bold">Customized</span>
    <?php endif; ?>
  </div>

  <?php if ($quotationDone): ?>
  <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-lg mb-6 flex items-center gap-3">
    <i class="fas fa-lock text-amber-500 text-xl"></i>
    <div>
      <strong class="text-amber-800">Quotation Locked</strong>
      <p class="text-amber-700 text-sm mt-1">This quotation is marked as Done. Changes cannot be saved.</p>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
  <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-lg mb-6">
    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
    <span class="text-red-700"><?= htmlspecialchars($errorMsg) ?></span>
  </div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="save_edit" value="1">

    <!-- ══════════════════════════════════════════════ -->
    <!-- PRODUCT IMAGE + COLOR PICKER -->
    <!-- ══════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
      <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i class="fas fa-palette text-indigo-500"></i> Product &amp; Color
      </h3>
      <div class="flex gap-6 flex-wrap">
        <!-- Main image -->
        <div class="flex-shrink-0">
          <?php
          $mainImgSrc = '';
          $imgPath    = !empty($entry['item_image_path']) ? $entry['item_image_path'] : '';
          $colorPath  = !empty($entry['color_image_path']) ? $entry['color_image_path'] : '';

          if ($colorPath) {
              if (file_exists(PAGES_PATH . 'images/product_colors/' . $colorPath)) {
                  $mainImgSrc = CLIENT_ASSET . '/images/product_colors/' . $colorPath;
              } else {
                  $mainImgSrc = CLIENT_ASSET . '/images/products/' . $colorPath;
              }
          } elseif ($imgPath) {
              $mainImgSrc = CLIENT_ASSET . '/images/products/' . $imgPath;
          }

          $originalImgSrc = $imgPath ? CLIENT_ASSET . '/images/products/' . $imgPath : $mainImgSrc;
          ?>
          <img id="item_main_image"
               src="<?= htmlspecialchars($mainImgSrc) ?>"
               data-original="<?= htmlspecialchars($originalImgSrc) ?>"
               class="w-40 h-40 object-cover rounded-xl border-2 border-gray-200"
               alt="Item Image">
        </div>
        <!-- Color swatches -->
        <div class="flex-1">
          <p class="text-sm font-medium text-gray-700 mb-3">Select Color:</p>
          <div class="flex flex-wrap gap-3">
            <?php if ($originalImgSrc): ?>
            <div class="text-center">
              <img id="default_color_image"
                   src="<?= htmlspecialchars($originalImgSrc) ?>"
                   data-full="<?= htmlspecialchars($originalImgSrc) ?>"
                   data-label="<?= htmlspecialchars($entry['color_label'] ?? '') ?>"
                   onclick="changeColor(this)"
                   class="w-12 h-12 object-cover rounded-lg border-2 border-gray-300 cursor-pointer
                          <?= (empty($colorPath) || $colorPath === $imgPath) ? 'ring-4 ring-indigo-500' : '' ?>">
              <p class="text-xs text-gray-500 mt-1">Default</p>
            </div>
            <?php endif; ?>
            <?php foreach ($colors as $color):
              $cImg = !empty($color['standard_color_image_path'])
                ? CLIENT_ASSET . '/images/product_colors/' . $color['standard_color_image_path']
                : '';
              if (!$cImg) continue;
              $isActive = ($colorPath === $color['standard_color_image_path']);
            ?>
            <div class="text-center">
              <img src="<?= htmlspecialchars($cImg) ?>"
                   data-full="<?= htmlspecialchars($cImg) ?>"
                   data-label="<?= htmlspecialchars($color['standard_color']) ?>"
                   onclick="changeColor(this)"
                   class="w-12 h-12 object-cover rounded-lg border-2 border-gray-300 cursor-pointer
                          <?= $isActive ? 'ring-4 ring-indigo-500' : '' ?>">
              <p class="text-xs text-gray-500 mt-1 truncate w-12"><?= htmlspecialchars($color['standard_color']) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Hidden fields for color -->
          <input type="hidden" name="color_label"      id="form_color_label"
                 value="<?= htmlspecialchars($entry['color_label'] ?? '') ?>">
          <input type="hidden" name="color_image_path" id="form_color_image"
                 value="<?= htmlspecialchars($colorPath) ?>">
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════ -->
    <!-- MAIN FIELDS -->
    <!-- ══════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
      <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i class="fas fa-sliders-h text-indigo-500"></i>
        <?= $is_fixed ? 'Fixed Size Settings' : 'Customized Settings' ?>
      </h3>

      <!-- Item Name -->
      <div class="mb-4">
        <label class="block text-sm font-semibold text-gray-700 mb-1">Item Name</label>
        <input type="text" name="item_name"
               value="<?= htmlspecialchars($entry['item_name'] ?? $entry['resolved_name'] ?? '') ?>"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-400"
               <?= $quotationDone ? 'readonly' : '' ?>>
      </div>

      <?php if (!$is_fixed): ?>
      <!-- CUSTOMIZED FIELDS -->
      <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Unit Mode</label>
          <select name="unit_mode" id="unit_mode"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
        onchange="isUserChangingMode = true; updateLabels();"
        <?= $quotationDone ? 'disabled' : '' ?>>
            <option value="linear" <?= ($entry['unit_mode'] ?? '') === 'linear' ? 'selected' : '' ?>>Linear</option>
            <option value="sqm"    <?= ($entry['unit_mode'] ?? '') === 'sqm'    ? 'selected' : '' ?>>Square Meter</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Unit Price (₱)</label>
          <input type="number" step="0.01" name="unit_price"
                 value="<?= htmlspecialchars($entry['unit_price'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Quantity</label>
          <input type="number" step="1" name="quantity" id="qty_input"
                 value="<?= htmlspecialchars($entry['quantity'] ?? 1) ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
      </div>

      <!-- Dimensions -->
      <div class="grid grid-cols-3 gap-4 mb-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">
            <span id="width_label" class="inline-block px-2 py-0.5 rounded text-white text-xs bg-yellow-500">
              <?= htmlspecialchars($entry['width_label'] ?? 'Width') ?>
            </span>
          </label>
          <input type="number" step="0.01" name="width"
                 value="<?= htmlspecialchars($entry['width'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">
            <span id="height_label" class="inline-block px-2 py-0.5 rounded text-white text-xs bg-yellow-500">
              <?= htmlspecialchars($entry['height_label'] ?? 'Height') ?>
            </span>
          </label>
          <input type="number" step="0.01" name="height"
                 value="<?= htmlspecialchars($entry['height'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">
            <span id="length_label" class="inline-block px-2 py-0.5 rounded text-white text-xs bg-yellow-500">
              <?= htmlspecialchars($entry['length_label'] ?? 'Length') ?>
            </span>
          </label>
          <input type="number" step="0.01" name="length"
                 value="<?= htmlspecialchars($entry['length'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
      </div>

      <!-- Pricing adjustments -->
      <div class="grid grid-cols-3 gap-4 mb-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Jack-Up (%)</label>
          <input type="number" step="0.01" name="mark_up"
                 value="<?= htmlspecialchars($entry['mark_up'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Dim. Adjustment (%)</label>
          <input type="number" step="0.01" name="jackup"
                 value="<?= htmlspecialchars($entry['jackup'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Labor Cost</label>
          <input type="number" step="0.01" name="labor_cost"
                 value="<?= htmlspecialchars($entry['labor_cost'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
      </div>

      <!-- Hidden label fields -->
      <input type="hidden" name="width_label"  value="<?= htmlspecialchars($entry['width_label'] ?? '') ?>">
      <input type="hidden" name="height_label" value="<?= htmlspecialchars($entry['height_label'] ?? '') ?>">
      <input type="hidden" name="length_label" value="<?= htmlspecialchars($entry['length_label'] ?? '') ?>">

      <?php else: ?>
      <!-- FIXED SIZE FIELDS -->
      <div class="mb-4">
        <label class="block text-sm font-semibold text-gray-700 mb-2">Select Size</label>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
          <?php foreach ($fixed_sizes as $idx => $sz): ?>
          <button type="button"
                  class="size-select-btn <?= $sz['fixed_size_id'] == $entry['fixed_size_id'] ? 'active' : '' ?> px-4 py-3 border-2 border-gray-300 rounded-lg font-semibold text-sm transition-all"
                  onclick="selectFixedSize(this, <?= $idx ?>)"
                  data-size-index="<?= $idx ?>"
                  data-fixed-size-id="<?= $sz['fixed_size_id'] ?>"
                  data-main-price="<?= $sz['main_color_price'] ?? '' ?>"
                  <?php foreach ($colors as $c): ?>
                    <?php if (isset($sz['standard_color_prices'][$c['standard_color_id']])): ?>
                      data-price-color-<?= $c['standard_color_id'] ?>="<?= $sz['standard_color_prices'][$c['standard_color_id']] ?>"
                    <?php endif; ?>
                  <?php endforeach; ?>>
            <?= htmlspecialchars($sz['size_label'] ?? 'Size ' . ($idx+1)) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <!-- Size detail cards -->
        <?php foreach ($fixed_sizes as $idx => $sz): ?>
        <div class="size-detail-card border-2 border-gray-200 rounded-lg p-4"
             data-size-card="<?= $idx ?>"
             data-fixed-size-id="<?= $sz['fixed_size_id'] ?>"
             data-main-price="<?= $sz['main_color_price'] ?? '' ?>"
             <?php foreach ($colors as $c): ?>
               <?php if (isset($sz['standard_color_prices'][$c['standard_color_id']])): ?>
                 data-price-color-<?= $c['standard_color_id'] ?>="<?= $sz['standard_color_prices'][$c['standard_color_id']] ?>"
               <?php endif; ?>
             <?php endforeach; ?>
             style="<?= $sz['fixed_size_id'] != $entry['fixed_size_id'] ? 'display:none;' : '' ?>">
          <div class="grid grid-cols-3 gap-2 text-sm mb-3">
            <?php if ($sz['size_width']):  ?><div><span class="text-gray-500"><?= $sz['item_width_label_linear']  ?? 'W' ?>:</span> <strong><?= $sz['size_width']  ?> <?= $sz['measurement_unit'] ?></strong></div><?php endif; ?>
            <?php if ($sz['size_height']): ?><div><span class="text-gray-500"><?= $sz['item_height_label_linear'] ?? 'H' ?>:</span> <strong><?= $sz['size_height'] ?> <?= $sz['measurement_unit'] ?></strong></div><?php endif; ?>
            <?php if ($sz['size_length']): ?><div><span class="text-gray-500"><?= $sz['item_length_label_linear'] ?? 'L' ?>:</span> <strong><?= $sz['size_length'] ?> <?= $sz['measurement_unit'] ?></strong></div><?php endif; ?>
          </div>
          <div class="bg-gray-50 rounded-lg p-3">
            <div class="text-sm font-semibold text-gray-700 mb-1">Price for selected color:</div>
            <div class="text-xl font-bold text-green-600 base-price-amount">
              <?php
              $displayPrice = $sz['main_color_price'] ?? 0;
              if ($entry['selected_color_type'] === 'standard' && $entry['selected_color_id']) {
                  $displayPrice = $sz['standard_color_prices'][$entry['selected_color_id']] ?? $displayPrice;
              }
              echo '₱' . number_format($displayPrice, 2);
              ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Base Price (₱)</label>
          <input type="number" step="0.01" name="base_price" id="base_price_input"
                 value="<?= htmlspecialchars($entry['base_price'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Quantity</label>
          <input type="number" step="1" name="quantity" id="qty_input"
                 value="<?= htmlspecialchars($entry['quantity'] ?? 1) ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
        </div>
      </div>

      <!-- Hidden fields for fixed size -->
      <input type="hidden" name="fixed_size_id"       id="fixed_size_id"
             value="<?= htmlspecialchars($entry['fixed_size_id'] ?? '') ?>">
      <input type="hidden" name="selected_color_type" id="selected_color_type"
             value="<?= htmlspecialchars($entry['selected_color_type'] ?? 'main') ?>">
      <input type="hidden" name="selected_color_id"   id="selected_color_id"
             value="<?= htmlspecialchars($entry['selected_color_id'] ?? 'main') ?>">
      <?php endif; ?>

      <?php
// Room distribution feature removed (quotation_room_distribution table no longer exists)
$existingRoomDist = [];
?>


      <!-- Common fields: Unit Type & Area -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Unit Type</label>
          <select name="unit_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                  <?= $quotationDone ? 'disabled' : '' ?>>
            <option value="pcs" <?= ($entry['unit_type'] ?? '') === 'pcs' ? 'selected' : '' ?>>Pcs</option>
            <option value="set" <?= ($entry['unit_type'] ?? '') === 'set' ? 'selected' : '' ?>>Set</option>
          </select>
        </div>
        <div class="relative">
          <label class="block text-sm font-semibold text-gray-700 mb-1">Location / Area</label>
          <input type="text" name="area" id="area_input"
                 value="<?= htmlspecialchars($entry['area'] ?? '') ?>"
                 placeholder="e.g. 1st Floor, Master Bedroom"
                 autocomplete="off"
                 oninput="handleAreaInput(this)"
                 onblur="hideAreaSuggestions()"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                 <?= $quotationDone ? 'readonly' : '' ?>>
          <div id="area-suggestions-dropdown"
               class="hidden absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 border-b text-xs font-semibold text-gray-500 uppercase tracking-wide">
              <i class="fas fa-history mr-1"></i>Previously Used
            </div>
            <div id="area-suggestions-list"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════ -->
    <!-- ADD-ONS SECTION -->
    <!-- ══════════════════════════════════════════════ -->
    <?php if (!empty($addons_by_category)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
      <h3 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i class="fas fa-puzzle-piece text-indigo-500"></i>
        Accessories
        <span class="ml-2 px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full text-xs">
          <?= count($allAddons) ?> accessories available
        </span>
      </h3>

      <!-- Sidebar + Content Layout -->
      <div class="flex gap-4">

        <!-- LEFT: Category Sidebar -->
        <div class="w-48 flex-shrink-0">
          <div class="sticky top-4 space-y-1">

            <!-- Search Box -->
            <div class="relative mb-3">
              <input type="text"
                     id="addon-search-input"
                     placeholder="Search accessories..."
                     oninput="searchAddons(this.value)"
                     class="w-full px-3 py-2 pr-8 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white">
              <i class="fas fa-search absolute right-2 top-2.5 text-gray-400 text-xs pointer-events-none"></i>
            </div>

            <!-- Search Results Panel -->
            <div id="addon-search-results" class="hidden mb-2">
              <p class="text-xs font-bold text-indigo-500 uppercase tracking-wider mb-1 px-1">
                <i class="fas fa-search mr-1"></i> Results
              </p>
              <div id="addon-search-list" class="space-y-1 max-h-64 overflow-y-auto"></div>
              <button type="button"
                      onclick="clearAddonSearch()"
                      class="mt-2 w-full text-xs text-gray-500 hover:text-red-500 py-1 flex items-center justify-center gap-1">
                <i class="fas fa-times-circle"></i> Clear search
              </button>
            </div>

            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 px-2" id="categories-label">Categories</p>
            <?php foreach ($addons_by_category as $category => $catAddons): ?>
              <button type="button"
                      onclick="showCategory('<?= htmlspecialchars($category) ?>')"
                      data-category="<?= htmlspecialchars($category) ?>"
                      title="<?= htmlspecialchars(ucfirst($category)) ?>"
                      class="category-tab w-full text-left px-3 py-2.5 rounded-lg border-2 transition-all
                      border-transparent bg-gray-50 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-700">
                <div class="flex items-center justify-between gap-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <i class="fas fa-tag text-gray-400 text-xs flex-shrink-0"></i>
                    <span class="font-semibold text-gray-700 text-sm truncate">
                      <?= htmlspecialchars(ucfirst($category)) ?>
                    </span>
                  </div>
                  <span class="text-xs bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded-full flex-shrink-0 font-bold">
                    <?= count($catAddons) ?>
                  </span>
                </div>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- RIGHT: Category Content -->
        <div class="flex-1 min-w-0">
          <?php foreach ($addons_by_category as $category => $catAddons): ?>
          <div id="category-content-<?= htmlspecialchars($category) ?>" class="category-content hidden">
            <h3 class="text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
              <i class="fas fa-tag text-indigo-500"></i>
              <?= htmlspecialchars(ucfirst($category)) ?> Accessories
            </h3>
        <?php
        $types_in_category = array_unique(array_filter(array_column($catAddons, 'addon_type')));
        ?>
        <?php if (!empty($types_in_category)): ?>
        <div class="flex flex-wrap gap-2 mb-3">
          <button type="button"
                  class="type-filter-btn active px-3 py-1 text-xs font-semibold rounded-full border-2 border-indigo-500 bg-indigo-500 text-white transition-all"
                  onclick="filterByType(this, '<?= htmlspecialchars($category) ?>', 'all')">
            All
          </button>
          <?php foreach ($types_in_category as $type): ?>
          <button type="button"
                  class="type-filter-btn px-3 py-1 text-xs font-semibold rounded-full border-2 border-gray-300 bg-white text-gray-600 hover:border-indigo-400 hover:text-indigo-600 transition-all"
                  onclick="filterByType(this, '<?= htmlspecialchars($category) ?>', '<?= htmlspecialchars($type) ?>')">
            <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($type) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          <?php foreach ($catAddons as $addon):
            $isSaved    = false;
            $savedAddon = null;
            $addonImg   = !empty($addon['addon_image_path'])
              ? CLIENT_ASSET . '/images/product_addons/' . htmlspecialchars($addon['addon_image_path'])
              : '';
          ?>
          <div class="relative border-2 rounded-lg p-4 flex flex-col transition-all hover:shadow-md addon-card
                      <?= $isSaved ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 bg-white' ?>"
               data-addon-id="<?= $addon['id'] ?>"
               data-addon-category="<?= htmlspecialchars($category) ?>"
               onclick="toggleAddonSelection(this)">

            <!-- Selection check -->
            <div class="absolute top-2 right-2 w-6 h-6 border-2 rounded-full flex items-center justify-center selection-indicator
                        <?= $isSaved ? 'bg-indigo-500 border-indigo-500' : 'border-gray-300 bg-white' ?>">
              <i class="fas fa-check text-white text-xs" <?= $isSaved ? '' : 'style="display:none"' ?>></i>
            </div>

            <?php if ($addonImg): ?>
              <img src="<?= $addonImg ?>" class="w-20 h-20 object-contain mx-auto mb-2 rounded" alt="">
            <?php else: ?>
              <div class="w-20 h-20 bg-gray-100 rounded mx-auto mb-2 flex items-center justify-content:center">
                <i class="fas fa-image text-gray-300 text-2xl mx-auto"></i>
              </div>
            <?php endif; ?>

            <p class="font-medium text-center text-gray-800 text-sm mb-2"><?= htmlspecialchars($addon['addon_name']) ?></p>
<?php if (!empty($addon['addon_type'])): ?>
<p class="text-xs text-center text-indigo-500 font-semibold mb-1">
  <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($addon['addon_type']) ?>
</p>
<?php else: ?>
<p class="text-xs text-center text-gray-500 mb-1">
  <i class="fas fa-puzzle-piece mr-1 text-indigo-400"></i>Accessory
</p>
<?php endif; ?>

            <?php
$has_dim     = !empty($addon['has_dimension']);
$dim_type    = $addon['dimension_type'] ?? '';
$dim_labels  = [
    1 => $addon['dimension_label_1'] ?? '',
    2 => $addon['dimension_label_2'] ?? '',
    3 => $addon['dimension_label_3'] ?? '',
];
$dim_defaults = [
    1 => $addon['dimension_value_1'] ?? '',
    2 => $addon['dimension_value_2'] ?? '',
    3 => $addon['dimension_value_3'] ?? '',
];
$addon_jackup = $addon['addon_jackup'] ?? 0;
// Use saved user dimension values if they exist
$saved_user_dim = [
    1 => $savedAddon['user_dim_value_1'] ?? $dim_defaults[1],
    2 => $savedAddon['user_dim_value_2'] ?? $dim_defaults[2],
    3 => $savedAddon['user_dim_value_3'] ?? $dim_defaults[3],
];
$saved_comp_area = $savedAddon['computed_area'] ?? 0;
?>
<!-- Price + Labor Cost inputs -->
<div class="flex items-center justify-center gap-2 mb-1" onclick="event.stopPropagation()">
  <div class="flex flex-col items-center">
    <span class="text-xs text-gray-500 mb-0.5">Material Price</span>
    <div class="flex items-center">
      <span class="text-sm text-gray-500 mr-1">₱</span>
      <input type="number" step="0.01"
             name="addon_price[<?= $addon['id'] ?>]"
             value="<?= htmlspecialchars($savedAddon ? $savedAddon['price'] : $addon['addon_price']) ?>"
             class="w-24 text-center text-sm border border-gray-300 rounded px-2 py-1">
    </div>
  </div>
  <div class="flex flex-col items-center">
    <span class="text-xs text-gray-500 mb-0.5">Labor Cost</span>
    <div class="flex items-center">
      <span class="text-sm text-gray-500 mr-1">₱</span>
      <input type="number" step="0.01"
             name="addon_labor_cost[<?= $addon['id'] ?>]"
             value="<?= htmlspecialchars($savedAddon ? ($savedAddon['labor_cost'] ?? 0) : ($addon['labor_cost'] ?? 0)) ?>"
             class="w-24 text-center text-sm border border-gray-300 rounded px-2 py-1">
    </div>
  </div>
</div>
<p class="text-xs text-center text-gray-400 mb-2">
  Total: ₱<span class="font-semibold text-gray-600">
    <?= number_format(
        ($savedAddon ? $savedAddon['price'] : $addon['addon_price']) +
        ($savedAddon ? ($savedAddon['labor_cost'] ?? 0) : ($addon['labor_cost'] ?? 0)),
        2
    ) ?>
  </span>
</p>

<!-- Qty & note + dimension inputs (visible when selected) -->
<div class="addon-details mt-2 space-y-2 <?= $isSaved ? '' : 'hidden' ?>"
     onclick="event.stopPropagation()">

  <?php if ($has_dim): ?>
  <!-- Dimension inputs -->
  <div class="bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-xl p-3 mb-2 shadow-sm">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-6 h-6 bg-indigo-500 rounded-full flex items-center justify-center flex-shrink-0">
        <i class="fas fa-ruler-combined text-white" style="font-size:10px;"></i>
      </div>
      <span class="text-xs font-bold text-indigo-700 uppercase tracking-wide">
        <?= $dim_type === 'sqm' ? 'Area Measurement (m²)' : 'Linear Measurement (lm)' ?>
      </span>
    </div>
    <?php foreach ([1,2,3] as $slot):
      if (empty($dim_labels[$slot])) continue; ?>
    <div class="flex items-center gap-2 mb-2 w-full overflow-hidden">
      <span class="text-xs font-semibold text-indigo-600 w-16 flex-shrink-0 bg-white px-1.5 py-0.5 rounded border border-indigo-200 text-center truncate">
        <?= htmlspecialchars($dim_labels[$slot]) ?>
      </span>
      <input type="number" step="0.01" min="0"
             name="addon_user_dim_<?= $slot ?>[<?= $addon['id'] ?>]"
             value="<?= ($saved_user_dim[$slot] !== '' && $saved_user_dim[$slot] !== null) ? (float)$saved_user_dim[$slot] : '' ?>"
             placeholder="e.g. <?= htmlspecialchars($dim_defaults[$slot] ?: '0.00') ?>"
             class="addon-dim-input w-full min-w-0 text-sm px-2 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500"
             data-slot="<?= $slot ?>"
             data-addon-id="<?= $addon['id'] ?>"
             data-dim-type="<?= htmlspecialchars($dim_type) ?>"
             onchange="recalcAddonArea(<?= $addon['id'] ?>, '<?= $dim_type ?>')">
    </div>
    <?php endforeach; ?>
    <div class="mt-2 pt-2 border-t border-indigo-200 flex items-center justify-between">
      <span class="text-xs text-indigo-600 font-medium">Computed area/length:</span>
      <span class="bg-indigo-600 text-white text-xs font-bold px-2 py-0.5 rounded-full">
        <span id="addon-area-display-<?= $addon['id'] ?>"><?= number_format((float)$saved_comp_area, 2) ?></span>
        <?= $dim_type === 'sqm' ? 'm²' : 'lm' ?>
      </span>
    </div>
    <?php if (!empty($addon_jackup)): ?>
    <div class="mt-1 flex items-center justify-between">
      <span class="text-xs text-gray-500">Jack-up:</span>
      <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-0.5 rounded-full border border-orange-200">
        <?= number_format($addon_jackup, 2) ?>%
      </span>
    </div>
    <?php endif; ?>
  </div>
  <!-- Hidden dimension meta fields -->
  <input type="hidden" name="addon_dim_type[<?= $addon['id'] ?>]"      value="<?= htmlspecialchars($dim_type) ?>">
  <input type="hidden" name="addon_dim_label_1[<?= $addon['id'] ?>]"   value="<?= htmlspecialchars($dim_labels[1]) ?>">
  <input type="hidden" name="addon_dim_label_2[<?= $addon['id'] ?>]"   value="<?= htmlspecialchars($dim_labels[2]) ?>">
  <input type="hidden" name="addon_dim_label_3[<?= $addon['id'] ?>]"   value="<?= htmlspecialchars($dim_labels[3]) ?>">
  <input type="hidden" name="addon_dim_default_1[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[1]) ?>">
  <input type="hidden" name="addon_dim_default_2[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[2]) ?>">
  <input type="hidden" name="addon_dim_default_3[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[3]) ?>">
  <input type="hidden" name="addon_jackup_val[<?= $addon['id'] ?>]"    value="<?= htmlspecialchars($addon_jackup) ?>">
  <input type="hidden" name="addon_computed_area[<?= $addon['id'] ?>]"
         class="addon-computed-area"
         id="addon-computed-area-<?= $addon['id'] ?>"
         value="<?= htmlspecialchars($saved_comp_area) ?>">
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <label class="text-xs font-medium text-gray-700">Qty:</label>
    <input type="number" min="1"
           name="addon_qty[<?= $addon['id'] ?>]"
           value="<?= $savedAddon ? intval($savedAddon['quantity']) : 1 ?>"
           class="w-16 text-sm px-2 py-1 border border-gray-300 rounded text-center">
  </div>
  <textarea name="addon_note[<?= $addon['id'] ?>]" rows="2"
            placeholder="Note (optional)…"
            class="w-full text-xs p-2 border border-gray-300 rounded resize-none"
    ><?= $savedAddon ? htmlspecialchars($savedAddon['note'] ?? '') : '' ?></textarea>
</div>

<!-- Hidden select input -->
<input type="hidden" class="addon-selected-input"
       name="addon_selected[<?= $addon['id'] ?>]"
       value="<?= $isSaved ? '1' : '0' ?>">
<input type="hidden" name="addon_category[<?= $addon['id'] ?>]"
       value="<?= htmlspecialchars($category) ?>">
          </div>
          <?php endforeach; ?>
        </div>
          </div>
          <?php endforeach; ?>
        </div> <!-- end right content -->
      </div> <!-- end sidebar flex wrapper -->
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════ -->
    <!-- SAVE / CANCEL BUTTONS -->
    <!-- ══════════════════════════════════════════════ -->
    <div class="flex gap-4">
      <button type="submit"
              class="save-btn flex-1 px-6 py-3 text-white rounded-xl font-bold text-base flex items-center justify-center gap-2
                     <?= $quotationDone ? 'opacity-50 cursor-not-allowed' : '' ?>"
              <?= $quotationDone ? 'disabled' : '' ?>>
        <i class="fas fa-save"></i>
        Save Changes
      </button>
      <a href="computation-list?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
         class="px-6 py-3 bg-gray-200 text-gray-700 rounded-xl font-bold hover:bg-gray-300 transition flex items-center gap-2">
        <i class="fas fa-times"></i>
        Cancel
      </a>
    </div>

  </form>
</div>

<script>
// ─── Color change ───────────────────────────────────────────
let activeColorSwatch = null;

function changeColor(el) {
  const full  = el.getAttribute('data-full');
  const label = el.getAttribute('data-label');
  if (!full) return;

  document.getElementById('item_main_image').src = full;

  document.querySelectorAll('[data-full]').forEach(s => s.classList.remove('ring-4', 'ring-indigo-500'));
  el.classList.add('ring-4', 'ring-indigo-500');
  activeColorSwatch = el;

  document.getElementById('form_color_image').value = extractPath(full);
  document.getElementById('form_color_label').value = label;

  <?php if ($is_fixed): ?>
  const colorId   = el.getAttribute('data-color-id') || 'main';
  const colorType = el.getAttribute('data-color-type') || 'main';
  document.getElementById('selected_color_type').value = colorType;
  document.getElementById('selected_color_id').value   = colorId;

  const activeCard = document.querySelector('.size-detail-card:not([style*="display:none"])');
  if (activeCard) updateFixedPrice(activeCard, colorType, colorId);
  <?php endif; ?>
}

function extractPath(url) {
  const m1 = url.match(/product_colors\/(.+)$/);
  if (m1) return m1[1];
  const m2 = url.match(/products\/(.+)$/);
  if (m2) return m2[1];
  return url;
}

// ─── Fixed size selection ────────────────────────────────────
<?php if ($is_fixed): ?>
function selectFixedSize(btn, idx) {
  document.querySelectorAll('.size-select-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.size-detail-card').forEach(c => c.style.display = 'none');
  btn.classList.add('active');
  const card = document.querySelector(`[data-size-card="${idx}"]`);
  if (card) card.style.display = 'block';

  document.getElementById('fixed_size_id').value = btn.getAttribute('data-fixed-size-id');

  const colorType = document.getElementById('selected_color_type').value || 'main';
  const colorId   = document.getElementById('selected_color_id').value   || 'main';
  updateFixedPrice(card, colorType, colorId);
}

function updateFixedPrice(card, colorType, colorId) {
  if (!card) return;
  let price = 0;
  if (colorType === 'main' || colorId === 'main') {
    price = parseFloat(card.getAttribute('data-main-price')) || 0;
  } else {
    price = parseFloat(card.getAttribute('data-price-color-' + colorId)) || 0;
  }
  const priceEl = card.querySelector('.base-price-amount');
  if (priceEl) priceEl.textContent = price > 0 ? '₱' + price.toLocaleString('en-PH', {minimumFractionDigits:2}) : '₱0.00';
  document.getElementById('base_price_input').value = price;
}
<?php endif; ?>

// ─── Addon toggle ────────────────────────────────────────────
const selectedAddons = new Set();

function toggleAddonSelection(card) {
  const addonId = card.getAttribute('data-addon-id');

  const indicator  = card.querySelector('.selection-indicator');
  const checkIcon  = indicator.querySelector('i');
  const hiddenInput = card.querySelector('.addon-selected-input');
  const details    = card.querySelector('.addon-details');

  if (selectedAddons.has(addonId)) {
    // Deselect
    selectedAddons.delete(addonId);
    card.classList.remove('border-indigo-500', 'bg-indigo-50');
    card.classList.add('border-gray-200', 'bg-white');
    indicator.classList.remove('bg-indigo-500', 'border-indigo-500');
    indicator.classList.add('border-gray-300');
    checkIcon.style.display = 'none';
    hiddenInput.value = '0';
    if (details) details.classList.add('hidden');
  } else {
    // Select
    selectedAddons.add(addonId);
    card.classList.add('border-indigo-500', 'bg-indigo-50');
    card.classList.remove('border-gray-200', 'bg-white');
    indicator.classList.add('bg-indigo-500', 'border-indigo-500');
    indicator.classList.remove('border-gray-300');
    checkIcon.style.display = 'block';
    hiddenInput.value = '1';
    if (details) details.classList.remove('hidden');
  }
}

// ─── Category tabs ────────────────────────────────────────────
function showCategory(category) {
  document.querySelectorAll('.category-content').forEach(el => el.classList.add('hidden'));

  document.querySelectorAll('.category-tab').forEach(btn => {
    btn.classList.remove('bg-indigo-600', 'border-indigo-600');
    btn.classList.add('bg-gray-50', 'border-transparent');

    // Reset icon
    btn.querySelectorAll('i').forEach(i => {
      i.classList.remove('text-white');
      i.classList.add('text-gray-400');
    });

    // Reset label text
    btn.querySelectorAll('span.font-semibold').forEach(s => {
      s.classList.remove('text-white');
      s.classList.add('text-gray-700');
    });

    // Reset badge — always keep gray, never turn white/indigo
    btn.querySelectorAll('span.rounded-full').forEach(s => {
      s.classList.remove('bg-indigo-500', 'text-white');
      s.classList.add('bg-gray-200', 'text-gray-600');
    });
  });

  const content = document.getElementById('category-content-' + category);
  if (content) content.classList.remove('hidden');

  const activeTab = document.querySelector(`.category-tab[data-category="${category}"]`);
  if (activeTab) {
    activeTab.classList.add('bg-indigo-600', 'border-indigo-600');
    activeTab.classList.remove('bg-gray-50', 'border-transparent');

    // Active icon
    activeTab.querySelectorAll('i').forEach(i => {
      i.classList.add('text-white');
      i.classList.remove('text-gray-400');
    });

    // Active label text
    activeTab.querySelectorAll('span.font-semibold').forEach(s => {
      s.classList.add('text-white');
      s.classList.remove('text-gray-700');
    });

    // Badge stays gray — do NOT change it
  }
}

// ─── Area suggestions ────────────────────────────────────────
const existingAreas = <?= json_encode($existingAreasList) ?>;

function handleAreaInput(input) {
  const typed    = input.value.trim();
  const dropdown = document.getElementById('area-suggestions-dropdown');
  const list     = document.getElementById('area-suggestions-list');
  const filtered = existingAreas.filter(a => a.toLowerCase().includes(typed.toLowerCase()));
  if (filtered.length > 0) {
    list.innerHTML = filtered.map(a => `
      <div class="px-3 py-2 cursor-pointer hover:bg-indigo-50 text-sm text-gray-700 border-b border-gray-50 last:border-0"
           onmousedown="selectArea('${a.replace(/'/g,"\\'")}')">
        <i class="fas fa-map-marker-alt mr-2 text-indigo-400 text-xs"></i>${a}
      </div>
    `).join('');
    dropdown.classList.remove('hidden');
  } else {
    dropdown.classList.add('hidden');
  }
}

function selectArea(area) {
  document.getElementById('area_input').value = area;
  document.getElementById('area-suggestions-dropdown').classList.add('hidden');
}

function hideAreaSuggestions() {
  setTimeout(() => {
    document.getElementById('area-suggestions-dropdown').classList.add('hidden');
  }, 150);
}

// ─── Dimension data from DB ──────────────────────────────────
const dimensionData = <?= !empty($dimension) ? json_encode($dimension) : 'null' ?>;
const labelData     = <?= !empty($labels)    ? json_encode($labels)    : 'null' ?>;

// ─── Unit mode label colors + dimension update ───────────────
// Track whether user has manually changed the unit mode
let isUserChangingMode = false;

function updateLabels() {
  const mode  = document.getElementById('unit_mode').value;
  const color = mode === 'linear' ? 'bg-yellow-500' : 'bg-blue-600';

  ['width_label', 'height_label', 'length_label'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.className = `inline-block px-2 py-0.5 rounded text-white text-xs ${color}`;
    }
  });

  // Only update dimension VALUES if user actively changed the mode (not on page load)
  if (isUserChangingMode && dimensionData) {
    const width  = parseFloat(mode === 'linear' ? dimensionData.item_width_linear  : dimensionData.item_width_sqm);
    const height = parseFloat(mode === 'linear' ? dimensionData.item_height_linear : dimensionData.item_height_sqm);
    const length = parseFloat(mode === 'linear' ? dimensionData.item_length_linear : dimensionData.item_length_sqm);

    const wInput = document.querySelector('input[name="width"]');
    const hInput = document.querySelector('input[name="height"]');
    const lInput = document.querySelector('input[name="length"]');

    if (wInput) wInput.value = isNaN(width)  ? '' : width;
    if (hInput) hInput.value = isNaN(height) ? '' : height;
    if (lInput) lInput.value = isNaN(length) ? '' : length;
  }

  // Always update label TEXT (this is safe to do on load and on change)
  if (labelData) {
    const wLab = mode === 'linear' ? labelData.item_width_label_linear  : labelData.item_width_label_sqm;
    const hLab = mode === 'linear' ? labelData.item_height_label_linear : labelData.item_height_label_sqm;
    const lLab = mode === 'linear' ? labelData.item_length_label_linear : labelData.item_length_label_sqm;

    const wEl = document.getElementById('width_label');
    const hEl = document.getElementById('height_label');
    const lEl = document.getElementById('length_label');

    if (wEl) wEl.textContent = wLab;
    if (hEl) hEl.textContent = hLab;
    if (lEl) lEl.textContent = lLab;
  }
}

// ─── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Open first category
  const firstTab = document.querySelector('.category-tab');
  if (firstTab) showCategory(firstTab.getAttribute('data-category'));

  // Apply label color on load WITHOUT overwriting saved dimension values
  isUserChangingMode = false;
  updateLabels();
});

// Recalculate addon area from dimension inputs
function recalcAddonArea(addonId, dimType) {
  const getVal = slot => {
    const el = document.querySelector(`input[name="addon_user_dim_${slot}[${addonId}]"]`);
    return el ? parseFloat(el.value) || 0 : 0;
  };
  const v1 = getVal(1), v2 = getVal(2);
  let area = 0;
  if (dimType === 'sqm') {
    area = v1 * v2;
  } else {
    area = v1;
  }
  area = Math.round(area * 100) / 100;
  const display = document.getElementById('addon-area-display-' + addonId);
  if (display) display.textContent = area.toFixed(2);
  const hidden = document.getElementById('addon-computed-area-' + addonId);
  if (hidden) hidden.value = area;
}

function searchAddons(query) {
  const q = query.trim().toLowerCase();
  const searchResults = document.getElementById('addon-search-results');
  const searchList = document.getElementById('addon-search-list');
  const categoriesLabel = document.getElementById('categories-label');
  const categoryTabs = document.querySelectorAll('.category-tab');

  if (q === '') { clearAddonSearch(); return; }

  searchResults.classList.remove('hidden');
  categoriesLabel.classList.add('hidden');
  categoryTabs.forEach(btn => btn.classList.add('hidden'));
  document.querySelectorAll('.category-content').forEach(el => el.classList.add('hidden'));

  const allCards = document.querySelectorAll('.addon-card');
  const matched = [];
  allCards.forEach(card => {
    const nameEl = card.querySelector('p.font-medium');
    const name = nameEl ? nameEl.textContent.toLowerCase() : '';
    const category = card.getAttribute('data-addon-category') || '';
    if (name.includes(q) || category.toLowerCase().includes(q)) {
      matched.push({ card, name: nameEl ? nameEl.textContent : '', category });
    }
  });

  if (matched.length === 0) {
    searchList.innerHTML = `<div class="text-xs text-gray-400 italic px-2 py-3 text-center">No accessories found</div>`;
    return;
  }

  searchList.innerHTML = matched.map(m => `
    <button type="button"
            onclick="jumpToAddon(${m.card.getAttribute('data-addon-id')}, '${m.card.getAttribute('data-addon-category').replace(/'/g, "\\'")}')"
            class="w-full text-left px-2 py-2 rounded-lg bg-white border border-gray-200 hover:border-indigo-400 hover:bg-indigo-50 transition-all">
      <div class="text-xs font-semibold text-gray-800 truncate">${m.name}</div>
      <div class="text-xs text-indigo-400 truncate">${m.category}</div>
    </button>
  `).join('');
}

function clearAddonSearch() {
  const input = document.getElementById('addon-search-input');
  if (input) input.value = '';
  document.getElementById('addon-search-results').classList.add('hidden');
  document.getElementById('categories-label').classList.remove('hidden');
  document.querySelectorAll('.category-tab').forEach(btn => btn.classList.remove('hidden'));
  const firstTab = document.querySelector('.category-tab');
  if (firstTab) showCategory(firstTab.getAttribute('data-category'));
}

function jumpToAddon(addonId, category) {
  showCategory(category);
  setTimeout(() => {
    const card = document.querySelector(`.addon-card[data-addon-id="${addonId}"]`);
    if (card) {
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      card.style.transition = 'box-shadow 0.3s';
      card.style.boxShadow = '0 0 0 3px #6366f1';
      setTimeout(() => { card.style.boxShadow = ''; }, 1500);
    }
  }, 100);
}

function filterByType(btn, category, type) {
  const categoryContent = document.getElementById('category-content-' + category);
  if (!categoryContent) return;

  categoryContent.querySelectorAll('.type-filter-btn').forEach(b => {
    b.classList.remove('bg-indigo-500', 'border-indigo-500', 'text-white');
    b.classList.add('bg-white', 'border-gray-300', 'text-gray-600');
  });
  btn.classList.add('bg-indigo-500', 'border-indigo-500', 'text-white');
  btn.classList.remove('bg-white', 'border-gray-300', 'text-gray-600');

  categoryContent.querySelectorAll('.addon-card').forEach(card => {
    if (type === 'all') {
      card.style.display = '';
    } else {
      const typeEl = card.querySelector('.fas.fa-layer-group')?.parentElement;
      const cardType = typeEl ? typeEl.textContent.trim() : '';
      card.style.display = cardType.includes(type) ? '' : 'none';
    }
  });
}
</script>
</body>
</html>