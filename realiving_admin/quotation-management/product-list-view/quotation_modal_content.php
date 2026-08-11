<?php
// quotation_modal_content.php
// This file should be included within quotation_items.php where $item, $items, etc. are already defined

if (empty($items)) {
    return;
}

$item = $items[0];
$item_family = $item['item_family'] ?? '';
$variants = [];
if (!empty($item['item_family'])) {
  $stmt = $conn->prepare("SELECT * FROM items WHERE item_family = ? AND item_code != ? AND is_hidden = 0");
$stmt->bind_param("ss", $item['item_family'], $item['item_code']);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($variant = $result->fetch_assoc()) {
    $variants[] = $variant;
  }
}

$price = $business_type === 'Project' ? $item['project_price'] : $item['non_project_price'];
$dimension = $item['dimension'] ?? [];
$labels = $item['labels'] ?? [];
$colors = $item['colors'] ?? [];

// Fetch linked addons
$addons = [];
$addons_by_category = [];
if ($item['item_id']) {
  $addon_stmt = $conn->prepare("
              SELECT 
                pa.id, 
                pa.addon_name, 
                pa.addon_price, 
                pa.labor_cost,
                pa.addon_category, 
                pa.addon_image_path, 
                pa.addon_description,
                pa.addon_type,
                pa.has_dimension,
                pa.dimension_type,
                pa.dimension_label_1,
                pa.dimension_label_2,
                pa.dimension_label_3,
                pa.dimension_value_1,
                pa.dimension_value_2,
                pa.dimension_value_3,
                pa.labor_cost_jack_up AS addon_jackup,
                pal.is_required,
                pal.max_quantity,
                pal.display_order
              FROM product_addons pa
              INNER JOIN product_addon_links pal ON pa.id = pal.addon_id
              WHERE pal.item_id = ?
              ORDER BY pa.addon_category ASC, pal.display_order ASC, pa.addon_name ASC
            ");
  $addon_stmt->bind_param("i", $item['item_id']);
  $addon_stmt->execute();
  $addon_res = $addon_stmt->get_result();
  while ($a = $addon_res->fetch_assoc()) {
    $addons[] = $a;
    $category = $a['addon_category'] ?: 'Uncategorized';
    if (!isset($addons_by_category[$category])) {
      $addons_by_category[$category] = [];
    }
    $addons_by_category[$category][] = $a;
  }
  $addon_stmt->close();
}
?>

<form method="post">
        <?php if (count($items) === 0): ?>
          <div style="background: white; padding: 40px; border-radius: 12px; text-align: center;">
            <i class="fas fa-search" style="font-size: 48px; color: #ddd; margin-bottom: 15px;"></i>
            <p style="color: #666;">No items found for "<?= htmlspecialchars($search) ?>".</p>
          </div>
        <?php else: ?>
          <?php
          $item = $items[0];
          $item_family = $item['item_family'] ?? '';
          $variants = [];
          if (!empty($item['item_family'])) {
            $stmt = $conn->prepare("SELECT * FROM items WHERE item_family = ? AND item_code != ? AND is_hidden = 0");
$stmt->bind_param("ss", $item['item_family'], $item['item_code']);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($variant = $result->fetch_assoc()) {
              $variants[] = $variant;
            }
          }

          $price = $business_type === 'Project' ? $item['project_price'] : $item['non_project_price'];
          $dimension = $item['dimension'] ?? [];
          $labels = $item['labels'] ?? [];
          $colors = $item['colors'] ?? [];

          // Fetch linked addons for this specific item, grouped by category
          $addons = [];
          $addons_by_category = [];
          if ($item['item_id']) {
            $addon_stmt = $conn->prepare("
              SELECT 
                pa.id, 
                pa.addon_name, 
                pa.addon_price, 
                pa.labor_cost,
                pa.addon_category, 
                pa.addon_image_path, 
                pa.addon_description,
                pa.addon_type,
                pa.has_dimension,
                pa.dimension_type,
                pa.dimension_label_1,
                pa.dimension_label_2,
                pa.dimension_label_3,
                pa.dimension_value_1,
                pa.dimension_value_2,
                pa.dimension_value_3,
                pa.labor_cost_jack_up AS addon_jackup,
                pal.is_required,
                pal.max_quantity,
                pal.display_order
              FROM product_addons pa
              INNER JOIN product_addon_links pal ON pa.id = pal.addon_id
              WHERE pal.item_id = ?
              ORDER BY pa.addon_category ASC, pal.display_order ASC, pa.addon_name ASC
            ");
            $addon_stmt->bind_param("i", $item['item_id']);
            $addon_stmt->execute();
            $addon_res = $addon_stmt->get_result();
            while ($a = $addon_res->fetch_assoc()) {
              $addons[] = $a;
              // Group by category
              $category = $a['addon_category'] ?: 'Uncategorized';
              if (!isset($addons_by_category[$category])) {
                $addons_by_category[$category] = [];
              }
              $addons_by_category[$category][] = $a;
            }
            $addon_stmt->close();
          }
          ?>
          <div class="bg-white shadow-lg rounded-xl overflow-hidden md:flex">
            <!-- Main Image -->
            <div class="md:w-1/3 bg-gray-100 flex items-center justify-center p-4">
              <?php if (!empty($item['item_image_path'])): ?>
                <?php $itemImgSrc = CLIENT_ASSET . '/images/products/' . htmlspecialchars($item['item_image_path']); ?>
                <img id="item_main_image" src="<?= $itemImgSrc ?>"
                  data-original="<?= $itemImgSrc ?>" alt="Item Image"
                  class="w-full md:w-6/10 h-auto max-h-96 object-contain rounded mx-auto" />
              <?php else: ?>
                <div class="text-gray-500 text-sm">No Image Available</div>
              <?php endif; ?>
            </div>

            <!-- Details & Controls -->
            <div class="md:w-2/3 p-6">
  <input type="hidden" name="item_code" value="<?= htmlspecialchars($item['item_code']) ?>">
<?php 
$default_size_type = (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1) ? 'fixed' : 'customized';
?>
<input type="hidden" name="size_type" id="size_type" value="<?= $default_size_type ?>">
  <input type="hidden" name="fixed_size_id" id="fixed_size_id" value="">
  <input type="hidden" name="selected_color_type" id="selected_color_type" value="main">
  <input type="hidden" name="selected_color_id" id="selected_color_id" value="">
  <input type="hidden" name="base_price" id="base_price" value="0">
              <input type="hidden" name="entry_item_id" value="<?= (int) $item['item_id'] ?>">
              <input type="hidden" name="color_label" id="form_color_label">
              <input type="hidden" name="unit_price" value="<?= number_format($price, 2, '.', '') ?>">
              <input type="hidden" name="color_image" id="form_color_image">
              <input type="hidden" name="dimension_msmt_id" value="<?= $item['dimension_msmt_fk'] ?>">
              <input type="hidden" name="dimension_label_id" value="<?= $item['dimension_label_fk'] ?>">
              <input type="hidden" name="width_label" id="form_width_label"
  value="<?= isset($labels['item_width_label_linear']) ? $labels['item_width_label_linear'] : '' ?>">
<input type="hidden" name="height_label" id="form_height_label"
  value="<?= isset($labels['item_height_label_linear']) ? $labels['item_height_label_linear'] : '' ?>">
<input type="hidden" name="length_label" id="form_length_label"
  value="<?= isset($labels['item_length_label_linear']) ? $labels['item_length_label_linear'] : '' ?>">

              <h3 class="text-2xl font-bold mb-2 text-indigo-700"><?= htmlspecialchars($item['item_name']) ?></h3>
              <p class="text-sm text-gray-600 mb-1"><strong>Code:</strong> <?= htmlspecialchars($item['item_code']) ?></p>
              <p class="text-sm text-gray-600 mb-1"><strong>Carcass:</strong>
  <?= htmlspecialchars($item['item_material']) ?></p>
<?php if (!empty($item['door_material'])): ?>
<p class="text-sm text-gray-600 mb-1"><strong>Door:</strong>
  <?= htmlspecialchars($item['door_material']) ?></p>
<?php endif; ?>
              <p id="reference-price" class="text-lg font-semibold mt-2 mb-4" style="<?= $default_size_type === 'fixed' ? 'display:none;' : '' ?>">
  Price (<?= $business_type === 'Non-Project' ? 'Individual' : htmlspecialchars($business_type) ?>):
  <span class="text-green-600">₱<?= number_format($price, 2) ?></span>
</p>

              <!-- Size Type Selector -->
<div class="mb-6 bg-gray-50 p-4 rounded-lg">
  <h4 class="font-semibold mb-3 text-gray-700">Size Options</h4>
  
  <div class="flex gap-3 mb-4">
    <?php if (empty($item['is_fixed_modular']) || $item['is_fixed_modular'] != 1): ?>
    <button type="button" 
            class="size-type-btn active flex-1 px-4 py-3 bg-white border-2 border-indigo-500 rounded-lg font-semibold text-indigo-700 transition-all hover:bg-indigo-50" 
            onclick="showSizeType('customized')"
            data-size-type="customized">
      <i class="fas fa-edit mr-2"></i>
      Customized
    </button>
    <?php endif; ?>
    
    <?php
    // Fetch fixed sizes for this item
    $fixed_sizes = [];
    if ($item['item_id']) {
      $sizes_stmt = $conn->prepare("
        SELECT fs.*, dl.dimension_label_name,
        dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
        FROM item_fixed_sizes fs
        LEFT JOIN dimension_label dl ON fs.dimension_label_fk = dl.dimension_label_id
        WHERE fs.item_fk = ? ORDER BY fs.display_order
      ");
      $sizes_stmt->bind_param("i", $item['item_id']);
      $sizes_stmt->execute();
      $sizes_result = $sizes_stmt->get_result();
      
      while ($size_row = $sizes_result->fetch_assoc()) {
        // Fetch pricing for this size
        $pricing_stmt = $conn->prepare("SELECT * FROM item_size_color_pricing WHERE fixed_size_fk = ? ORDER BY color_type, color_reference_id");
        $pricing_stmt->bind_param("i", $size_row['fixed_size_id']);
        $pricing_stmt->execute();
        $pricing_result = $pricing_stmt->get_result();
        
        $size_row['pricing'] = [];
        $size_row['main_color_price'] = null;
        $size_row['standard_color_prices'] = [];
        
        while ($pricing = $pricing_result->fetch_assoc()) {
          $size_row['pricing'][] = $pricing;
          
          if ($pricing['color_type'] === 'main') {
            $size_row['main_color_price'] = $pricing['fixed_price'];
          } elseif ($pricing['color_type'] === 'standard' && $pricing['color_reference_id']) {
            $size_row['standard_color_prices'][$pricing['color_reference_id']] = $pricing['fixed_price'];
          }
        }
        $pricing_stmt->close();
        
        $fixed_sizes[] = $size_row;
      }
      $sizes_stmt->close();
    }
    ?>
    
    <?php if (!empty($fixed_sizes)): ?>
    <button type="button" 
            class="size-type-btn flex-1 px-4 py-3 bg-white border-2 border-gray-300 rounded-lg font-semibold text-gray-700 transition-all hover:bg-gray-50 <?php echo (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1) ? 'active border-indigo-500 text-indigo-700' : ''; ?>" 
            onclick="showSizeType('fixed')"
            data-size-type="fixed">
      <i class="fas fa-ruler-2 mr-2"></i>
      Fixed Sizes
    </button>
    <?php endif; ?>
  </div>

  <!-- Customized Content -->
<?php if (empty($item['is_fixed_modular']) || $item['is_fixed_modular'] != 1): ?>
<div id="customized-content" class="size-content <?= $default_size_type === 'customized' ? 'active' : '' ?>" style="<?= $default_size_type === 'customized' ? '' : 'display:none;' ?>">
    <!-- Unit Mode Selector -->
    <label for="unit_mode" class="block font-medium text-sm mb-1">Select Measurement Unit</label>
    <select id="unit_mode" name="unit_mode" class="mb-4 px-4 py-2 border rounded-md w-full"
      onchange="updateDimensions()">
      <option value="linear">Linear</option>
      <option value="sqm">Square Meter</option>
    </select>

    <!-- Dimensions (move existing dimension inputs here) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="dimension_inputs">
      <div class="flex items-center space-x-2">
        <span id="width_label"
          class="text-white text-xs px-2 py-1 rounded font-semibold whitespace-nowrap"></span>
        <input type="number" id="width_input" name="width" class="w-full px-3 py-2 border rounded-md">
      </div>
      <div class="flex items-center space-x-2">
        <span id="height_label"
          class="text-white text-xs px-2 py-1 rounded font-semibold whitespace-nowrap"></span>
        <input type="number" id="height_input" name="height" class="w-full px-3 py-2 border rounded-md">
      </div>
      <div class="flex items-center space-x-2">
        <span id="length_label"
          class="text-white text-xs px-2 py-1 rounded font-semibold whitespace-nowrap"></span>
        <input type="number" id="length_input" name="length" class="w-full px-3 py-2 border rounded-md">
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Fixed Sizes Content -->
  <?php if (!empty($fixed_sizes)): ?>
  <div id="fixed-sizes-content" class="size-content <?php echo (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1) ? 'active' : ''; ?>" style="<?php echo (empty($item['is_fixed_modular']) || $item['is_fixed_modular'] != 1) ? 'display:none;' : ''; ?>">
    
    <!-- Size Selection Buttons -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
      <?php foreach ($fixed_sizes as $index => $size): ?>
        <button type="button" 
                class="size-select-btn <?= $index === 0 ? 'active' : '' ?> px-4 py-3 border-2 rounded-lg font-semibold transition-all"
                onclick="selectFixedSize(this, <?= $index ?>)"
                data-size-index="<?= $index ?>"
                data-fixed-size-id="<?= $size['fixed_size_id'] ?>"
                data-main-price="<?= !empty($size['main_color_price']) ? $size['main_color_price'] : '' ?>"
                <?php foreach ($colors as $color): ?>
                  <?php if (isset($size['standard_color_prices'][$color['standard_color_id']])): ?>
                    data-price-color-<?= $color['standard_color_id'] ?>="<?= $size['standard_color_prices'][$color['standard_color_id']] ?>"
                  <?php endif; ?>
                <?php endforeach; ?>>
          <?php if (!empty($size['size_label'])): ?>
            <?= htmlspecialchars($size['size_label']) ?>
          <?php else: ?>
            Size <?= $index + 1 ?>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Size Details Cards -->
    <div class="size-details-container">
      <?php foreach ($fixed_sizes as $index => $size): ?>
        <div class="size-detail-card <?= $index === 0 ? 'active' : '' ?> bg-white border-2 border-gray-200 rounded-lg p-4" 
             data-size-card="<?= $index ?>"
             data-fixed-size-id="<?= $size['fixed_size_id'] ?>"
             data-main-price="<?= !empty($size['main_color_price']) ? $size['main_color_price'] : '' ?>"
             <?php foreach ($colors as $color): ?>
                <?php if (isset($size['standard_color_prices'][$color['standard_color_id']])): ?>
                  data-price-color-<?= $color['standard_color_id'] ?>="<?= $size['standard_color_prices'][$color['standard_color_id']] ?>"
                <?php endif; ?>
              <?php endforeach; ?>
             style="<?= $index !== 0 ? 'display:none;' : '' ?>">
          
          <?php if (!empty($size['dimension_label_name'])): ?>
            <div class="text-xs font-semibold text-indigo-600 mb-2">
              <?= htmlspecialchars($size['dimension_label_name']) ?>
            </div>
          <?php endif; ?>

          <div class="grid grid-cols-3 gap-2 text-sm mb-3">
            <?php if (!empty($size['size_width'])): ?>
              <div>
                <span class="text-gray-600"><?= htmlspecialchars($size['item_width_label_linear'] ?? 'Width') ?>:</span>
                <span class="font-semibold"><?= $size['size_width'] ?> <?= $size['measurement_unit'] ?></span>
              </div>
            <?php endif; ?>

            <?php if (!empty($size['size_height'])): ?>
              <div>
                <span class="text-gray-600"><?= htmlspecialchars($size['item_height_label_linear'] ?? 'Height') ?>:</span>
                <span class="font-semibold"><?= $size['size_height'] ?> <?= $size['measurement_unit'] ?></span>
              </div>
            <?php endif; ?>

            <?php if (!empty($size['size_length'])): ?>
              <div>
                <span class="text-gray-600"><?= htmlspecialchars($size['item_length_label_linear'] ?? 'Length') ?>:</span>
                <span class="font-semibold"><?= $size['size_length'] ?> <?= $size['measurement_unit'] ?></span>
              </div>
            <?php endif; ?>
          </div>

          <!-- Price Display -->
          <div class="bg-gray-50 p-3 rounded-lg">
            <div class="text-sm font-semibold text-gray-700 mb-1">
              <i class="fas fa-tag mr-1"></i>
              <span class="current-color-name">Selected Color Price</span>
            </div>
            <div class="text-lg font-bold text-green-600 base-price-amount">₱0.00</div>
            <div class="no-price-message text-sm text-red-600" style="display:none;">
              <i class="fas fa-exclamation-circle mr-1"></i>
              Price not available for this color
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

              <!-- Mark-up & Labor Cost (ONLY FOR CUSTOMIZED) -->
<div id="customized-costs" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6" style="<?= $default_size_type === 'fixed' ? 'display:none;' : '' ?>">
  <!-- Jackup -->
  <div>
    <label for="mark_up" class="block text-sm font-medium mb-1">Jack-Up (%)</label>
    <input type="number" id="mark_up" name="mark_up" step="0.01" class="w-full px-3 py-2 border rounded-md"
      value="<?= isset($item['mark_up']) ? floatval($item['mark_up']) : '' ?>">
  </div>

  <!-- Dimension Adjustment -->
  <div>
    <label for="jackup" class="block text-sm font-medium mb-1">Dimension Adjustment (%)</label>
    <input type="number" id="jackup" name="jackup" step="0.01" class="w-full px-3 py-2 border rounded-md"
      value="<?= isset($item['jackup']) ? floatval($item['jackup']) : '' ?>">
  </div>

  <!-- Labor Cost -->
  <div>
    <label for="labor_cost" class="block text-sm font-medium mb-1">Labor Cost</label>
    <input type="number" id="labor_cost" name="labor_cost" step="0.01"
      class="w-full px-3 py-2 border rounded-md"
      value="<?= isset($item['labor_cost']) ? floatval($item['labor_cost']) : '' ?>">
  </div>
</div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                  <label class="block text-sm font-medium mb-1">Quantity</label>
                  <input type="number" id="total_quantity" name="quantity" class="w-full px-3 py-2 border rounded-md" value="1" required min="1">
                </div>

                <!-- Unit Dropdown -->
                <div>
                  <label class="block text-sm font-medium mb-1">Unit</label>
                  <select name="unit_type" required class="w-full px-3 py-2 border rounded-md">
                    <option value="">Unit</option>
                    <option value="pcs">Pcs</option>
                    <option value="set">Set</option>
                  </select>
                </div>

                <div class="relative">
                  <label class="block text-sm font-medium mb-1">Location</label>
                  <input type="text" 
                         id="area_input"
                         name="area" 
                         class="w-full px-3 py-2 border rounded-md" 
                         placeholder="e.g. 1st Floor, Master Bedroom"
                         autocomplete="off"
                         oninput="handleAreaInput(this)"
                         onblur="hideAreaSuggestions()"
                         required>
                  <!-- Duplicate warning -->
                  <div id="area-duplicate-warning" class="hidden mt-1 text-xs text-amber-600 flex items-center gap-1">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="area-duplicate-text"></span>
                  </div>
                  <!-- Suggestions dropdown -->
                  <div id="area-suggestions-dropdown" 
                       class="hidden absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 overflow-hidden">
                    <div class="px-3 py-2 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                      <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                        <i class="fas fa-history mr-1"></i>Previously Used Areas
                      </span>
                      <span class="text-xs text-gray-400">Click to use</span>
                    </div>
                    <div id="area-suggestions-list"></div>
                  </div>
                </div>
              </div>

              <!-- Available Colors -->
<div class="mt-6">
  <h4 class="font-medium mb-2">Available Colors</h4>
  <?php
  $mainImageSrc = !empty($item['item_image_path'])
    ? CLIENT_ASSET . '/images/products/' . htmlspecialchars($item['item_image_path'])
    : '';
  ?>
  <?php if (empty($colors) && !$mainImageSrc): ?>
    <p class="text-gray-500 italic">No colors available.</p>
  <?php else: ?>
    <div class="flex flex-wrap gap-3">
      <?php if ($mainImageSrc): ?>
        <div class="text-center">
          <img id="default_color_image" src="<?= $mainImageSrc ?>" alt="Default Item Image"
            class="w-12 h-12 object-cover border-2 border-gray-400 rounded cursor-pointer ring-4 ring-indigo-500"
            data-full="<?= $mainImageSrc ?>" 
            data-label="<?= htmlspecialchars($item['item_color']) ?>"
            data-color-type="main"
            data-color-id="main"
            onclick="changeColor(this)" />
          <p class="text-xs mt-1 text-gray-600"><?= htmlspecialchars($item['item_color']) ?></p>
        </div>
      <?php endif; ?>

      <?php foreach ($colors as $color):
        $colorImg = !empty($color['standard_color_image_path'])
          ? CLIENT_ASSET . '/images/product_colors/' . htmlspecialchars($color['standard_color_image_path'])
          : '';
        if ($colorImg): ?>
          <div class="text-center">
            <img src="<?= $colorImg ?>" alt="<?= htmlspecialchars($color['standard_color']) ?>"
              class="w-12 h-12 object-cover border-2 border-gray-400 rounded cursor-pointer"
              data-full="<?= $colorImg ?>" 
              data-label="<?= htmlspecialchars($color['standard_color']) ?>"
              data-color-type="standard"
              data-color-id="<?= $color['standard_color_id'] ?>"
              onclick="changeColor(this)" />
            <p class="text-xs mt-1 text-gray-600 text-center"><?= htmlspecialchars($color['standard_color']) ?></p>
          </div>
        <?php endif; endforeach; ?>
    </div>
  <?php endif; ?>
</div>
            </div>
          </div>

          <!-- Addons Section -->
          <?php if (!empty($addons)): ?>
            <details class="mt-8 border border-gray-300 rounded-lg bg-white shadow-sm">
              <summary class="cursor-pointer px-4 py-3 text-base font-semibold bg-white hover:bg-gray-50 rounded-lg transition-colors flex items-center justify-between border-b">
                <span class="flex items-center text-gray-700">
                  <i class="fas fa-puzzle-piece mr-2 text-gray-500"></i>
                  Available Accessories for this Product
                </span>
                <span class="text-sm bg-gray-100 text-gray-600 px-3 py-1 rounded-full">
                  <?= count($addons) ?> accessory(ies)
                </span>
              </summary>

              <div class="p-4">
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

      <!-- Search Results Panel (hidden by default) -->
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
      <?php foreach ($addons_by_category as $category => $category_addons): ?>
        <button type="button"
                onclick="showCategory('<?= htmlspecialchars($category) ?>')"
                data-category="<?= htmlspecialchars($category) ?>"
                title="<?= htmlspecialchars(ucfirst($category)) ?>"
                class="category-tab w-full text-left px-3 py-2.5 rounded-lg border-2 transition-all
                       border-transparent bg-gray-50 hover:bg-indigo-50 hover:border-indigo-300">
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
              <i class="fas fa-tag text-gray-400 text-xs flex-shrink-0"></i>
              <span class="font-semibold text-gray-700 text-sm truncate">
                <?= htmlspecialchars(ucfirst($category)) ?>
              </span>
            </div>
            <span class="text-xs bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded-full flex-shrink-0 font-bold">
              <?= count($category_addons) ?>
            </span>
          </div>
        </button>
      <?php endforeach; ?>
    </div><!-- end sticky -->
  </div><!-- end sidebar -->

  <!-- RIGHT: Category Content -->
  <div class="flex-1 min-w-0">
    <?php foreach ($addons_by_category as $category => $category_addons): ?>
      <div id="category-content-<?= htmlspecialchars($category) ?>" 
           class="category-content hidden">
        <h3 class="text-base font-semibold text-gray-800 mb-3 flex items-center gap-2">
          <i class="fas fa-tag text-indigo-500"></i>
          <?= htmlspecialchars(ucfirst($category)) ?> Accessories
        </h3>

        <!-- Type Filter Buttons -->
        <?php
        $types_in_category = array_unique(array_filter(array_column($category_addons, 'addon_type')));
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

        <!-- Category Addons Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
  <?php foreach ($category_addons as $addon):
  $img = !empty($addon['addon_image_path'])
    ? CLIENT_ASSET . '/images/product_addons/' . htmlspecialchars($addon['addon_image_path'])
    : '';
  $is_required = $addon['is_required'] ?? 0;
  $is_currently_used = $is_required;
  $max_qty = $addon['max_quantity'] ?? null;
  $has_dim = !empty($addon['has_dimension']);
  $dim_type = $addon['dimension_type'] ?? '';
  $dim_labels = [
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
  ?>
  <div class="relative border rounded-lg p-4 flex flex-col transition-all hover:shadow-md addon-card <?= $is_required ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white' ?>"
       data-addon-id="<?= $addon['id'] ?>"
       data-addon-category="<?= htmlspecialchars($category) ?>"
       data-addon-price="<?= htmlspecialchars($addon['addon_price']) ?>"
       data-is-currently-used="<?= $is_currently_used ?>"
       data-is-required="<?= $is_required ?>"
       onclick="toggleAddonSelection(this)">
    
    <!-- Currently Used badge if applicable -->
    <?php if ($is_currently_used): ?>
    <span class="absolute top-2 left-2 bg-blue-500 text-white text-xs px-2 py-1 rounded-full font-semibold z-10">
      Currently Used
    </span>
    <?php endif; ?>
    
    <!-- Visual selection indicator -->
    <div class="absolute top-2 right-2 w-6 h-6 border-2 border-gray-300 rounded-full flex items-center justify-center selection-indicator">
      <i class="fas fa-check text-white text-xs" style="display:none;"></i>
    </div>

    <?php if ($img): ?>
      <img src="<?= $img ?>" class="w-24 h-24 object-contain mx-auto mb-2"
        alt="<?= htmlspecialchars($addon['addon_name']) ?>">
    <?php else: ?>
      <div class="w-24 h-24 bg-gray-100 rounded mx-auto mb-2 flex items-center justify-center">
        <i class="fas fa-image text-gray-300 text-2xl"></i>
      </div>
    <?php endif; ?>

    <p class="font-medium text-center text-gray-800 addon-name"><?= htmlspecialchars($addon['addon_name']) ?></p>
<?php if (!empty($addon['addon_type'])): ?>
<p class="text-xs text-center text-indigo-500 font-semibold mb-1">
  <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($addon['addon_type']) ?>
</p>
<?php endif; ?>
    
    <!-- Editable Price -->
    <div class="flex items-center justify-center gap-2 mb-1" onclick="event.stopPropagation()">
      <div class="flex flex-col items-center">
        <span class="text-xs text-gray-500 mb-0.5">Material Price</span>
        <div class="flex items-center">
          <span class="text-sm text-gray-600 mr-1">₱</span>
          <input type="number" 
                 name="addon_price[<?= $addon['id'] ?>]"
                 value="<?= htmlspecialchars($addon['addon_price']) ?>" 
                 step="0.01"
                 class="addon-price-input w-24 text-center text-sm border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
        </div>
      </div>
      <?php if (!empty($addon['labor_cost'])): ?>
      <div class="flex flex-col items-center">
        <span class="text-xs text-gray-500 mb-0.5">Labor Cost</span>
        <div class="flex items-center">
          <span class="text-sm text-gray-600 mr-1">₱</span>
          <input type="number" 
                 name="addon_labor_cost[<?= $addon['id'] ?>]"
                 value="<?= htmlspecialchars($addon['labor_cost']) ?>" 
                 step="0.01"
                 class="addon-labor-input w-24 text-center text-sm border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-green-500 focus:border-green-500" />
        </div>
      </div>
      <?php else: ?>
      <input type="hidden" name="addon_labor_cost[<?= $addon['id'] ?>]" value="0">
      <?php endif; ?>
    </div>
    <p class="text-xs text-center text-gray-400 mb-2">
      Total: ₱<span class="addon-total-display font-semibold text-gray-600">
        <?= number_format($addon['addon_price'] + ($addon['labor_cost'] ?? 0), 2) ?>
      </span>
    </p>

    <details class="border-t border-gray-200 pt-2 mt-2" onclick="event.stopPropagation()">
      <summary class="cursor-pointer text-blue-600 hover:text-blue-800 hover:underline font-medium text-xs">
        View Description
      </summary>
      <p class="mt-1 text-gray-600 text-xs">
        <?= nl2br(htmlspecialchars($addon['addon_description'])) ?>
      </p>
    </details>
    
    <!-- Quantity and Note inputs (visible when selected) -->
    <div class="addon-details mt-3 space-y-2" style="display:none;" onclick="event.stopPropagation()">
      
      <?php if ($has_dim): ?>
      <!-- Dimension inputs for this addon -->
      <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-2">
        <div class="flex items-center gap-2 mb-2">
          <i class="fas fa-ruler-combined text-indigo-500 text-xs"></i>
          <span class="text-xs font-semibold text-indigo-700 uppercase tracking-wide">
            <?= $dim_type === 'sqm' ? 'Area Measurement (m²)' : 'Linear Measurement (lm)' ?>
          </span>
        </div>
        
        <?php foreach ([1,2,3] as $slot):
          if (empty($dim_labels[$slot])) continue; ?>
        <div class="flex items-center gap-2 mb-1">
          <span class="text-xs font-medium text-gray-600 w-20 flex-shrink-0"><?= htmlspecialchars($dim_labels[$slot]) ?>:</span>
          <input type="number"
                 name="addon_user_dim_<?= $slot ?>[<?= $addon['id'] ?>]"
                 step="0.01" min="0"
                 value="<?= htmlspecialchars($dim_defaults[$slot]) ?>"
                 placeholder="e.g. <?= htmlspecialchars($dim_defaults[$slot] ?: '0.00') ?>"
                 class="addon-dim-input flex-1 text-sm px-2 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500"
                 data-slot="<?= $slot ?>"
                 data-addon-id="<?= $addon['id'] ?>"
                 data-dim-type="<?= htmlspecialchars($dim_type) ?>"
                 onchange="recalcAddonArea(<?= $addon['id'] ?>, '<?= $dim_type ?>')" />
        </div>
        <?php endforeach; ?>
        
        <div class="mt-2 pt-2 border-t border-indigo-200 text-xs text-indigo-700">
          Computed area/length: <strong id="addon-area-display-<?= $addon['id'] ?>">0.00</strong>
          <?= $dim_type === 'sqm' ? 'm²' : 'lm' ?>
        </div>
        
        <?php if (!empty($addon_jackup)): ?>
        <div class="mt-1 text-xs text-gray-500">
          Jack-up: <?= number_format($addon_jackup, 2) ?>%
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Hidden fields to carry dimension meta to server -->
      <input type="hidden" name="addon_dim_type[<?= $addon['id'] ?>]"     value="<?= htmlspecialchars($dim_type) ?>">
      <input type="hidden" name="addon_dim_label_1[<?= $addon['id'] ?>]"  value="<?= htmlspecialchars($dim_labels[1]) ?>">
      <input type="hidden" name="addon_dim_label_2[<?= $addon['id'] ?>]"  value="<?= htmlspecialchars($dim_labels[2]) ?>">
      <input type="hidden" name="addon_dim_label_3[<?= $addon['id'] ?>]"  value="<?= htmlspecialchars($dim_labels[3]) ?>">
      <input type="hidden" name="addon_dim_default_1[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[1]) ?>">
      <input type="hidden" name="addon_dim_default_2[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[2]) ?>">
      <input type="hidden" name="addon_dim_default_3[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($dim_defaults[3]) ?>">
      <input type="hidden" name="addon_jackup_val[<?= $addon['id'] ?>]"   value="<?= htmlspecialchars($addon_jackup) ?>">
      <input type="hidden" name="addon_computed_area[<?= $addon['id'] ?>]" class="addon-computed-area" id="addon-computed-area-<?= $addon['id'] ?>" value="0">
      <?php endif; ?>
      
      <div class="flex items-center justify-between">
        <label class="text-sm font-medium text-gray-700">
          Qty:
          <?php if ($max_qty): ?>
          <span class="text-xs text-gray-500">(Max: <?= $max_qty ?>)</span>
          <?php endif; ?>
        </label>
        <input type="number" 
               name="addon_qty[<?= $addon['id'] ?>]" 
               min="1" 
               <?= $max_qty ? 'max="' . $max_qty . '"' : '' ?>
               value="1"
               class="addon-qty-input w-16 text-sm px-2 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
      </div>
      <textarea name="addon_note[<?= $addon['id'] ?>]" 
                rows="2" 
                placeholder="Add note (optional)..."
                class="addon-note-input w-full text-xs p-2 border border-gray-300 rounded resize-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></textarea>
    </div>
    
    <!-- Hidden inputs for form submission -->
    <input type="hidden" class="addon-selected-input" name="addon_selected[<?= $addon['id'] ?>]" value="0">
    <input type="hidden" name="addon_category[<?= $addon['id'] ?>]" value="<?= htmlspecialchars($category) ?>">
  </div>
<?php endforeach; ?>
</div>
        </div>
      <?php endforeach; ?>
    </div> <!-- end right content -->
  </div> <!-- end sidebar flex wrapper -->
</div> <!-- end p-4 -->
</details>
          <?php endif; ?>

          <!-- Back to Products Button -->
<div class="mt-6 flex gap-3">
  <button type="submit" name="submit_quotation"
    class="flex-1 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg hover:from-indigo-700 hover:to-purple-700 font-semibold transition-all">
    <i class="fas fa-save mr-2"></i>
    Save Quotation
  </button>

  <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=all&family=all"
     class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold transition-all flex items-center justify-center">
    <i class="fas fa-arrow-left mr-2"></i>
    Back to Products
  </a>
</div>
        <?php endif; ?>
        <script>
// Size type switching
let currentSizeType = '<?= (!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1) ? 'fixed' : 'customized' ?>';
let selectedColorType = 'main';
let selectedColorId = 'main';
let activeColorSwatch = null;

function searchAddons(query) {
  const q = query.trim().toLowerCase();
  const searchResults = document.getElementById('addon-search-results');
  const searchList = document.getElementById('addon-search-list');
  const categoriesLabel = document.getElementById('categories-label');
  const categoryTabs = document.querySelectorAll('.category-tab');

  if (q === '') {
    clearAddonSearch();
    return;
  }

  // Show search results panel, hide category tabs
  searchResults.classList.remove('hidden');
  categoriesLabel.classList.add('hidden');
  categoryTabs.forEach(btn => btn.classList.add('hidden'));

  // Hide all category content panels
  document.querySelectorAll('.category-content').forEach(el => el.classList.add('hidden'));

  // Search across all addon cards
  const allCards = document.querySelectorAll('.addon-card');
  const matched = [];

  allCards.forEach(card => {
    const nameEl = card.querySelector('.addon-name');
    const name = nameEl ? nameEl.textContent.toLowerCase() : '';
    const category = card.getAttribute('data-addon-category') || '';
    if (name.includes(q) || category.toLowerCase().includes(q)) {
      matched.push({ card, name: nameEl ? nameEl.textContent : '', category });
    }
  });

  if (matched.length === 0) {
    searchList.innerHTML = `
      <div class="text-xs text-gray-400 italic px-2 py-3 text-center">
        <i class="fas fa-box-open mb-1 block text-lg"></i>
        No accessories found
      </div>`;
    return;
  }

  // Build result buttons
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

  // Re-open the first category
  const firstTab = document.querySelector('.category-tab');
  if (firstTab) showCategory(firstTab.getAttribute('data-category'));
}

function jumpToAddon(addonId, category) {
  // Show the correct category panel
  showCategory(category);

  // Scroll to the specific addon card
  setTimeout(() => {
    const card = document.querySelector(`.addon-card[data-addon-id="${addonId}"]`);
    if (card) {
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // Flash highlight
      card.style.transition = 'box-shadow 0.3s';
      card.style.boxShadow = '0 0 0 3px #6366f1';
      setTimeout(() => { card.style.boxShadow = ''; }, 1500);
    }
  }, 100);
}

function showCategory(category) {
  // Hide all content panels
  document.querySelectorAll('.category-content').forEach(el => el.classList.add('hidden'));

  // Reset all sidebar tab styles
  document.querySelectorAll('.category-tab').forEach(btn => {
    btn.classList.remove('bg-indigo-600', 'border-indigo-600');
    btn.classList.add('bg-gray-50', 'border-transparent');
    btn.querySelectorAll('i').forEach(i => {
      i.classList.remove('text-white');
      i.classList.add('text-gray-400');
    });
    btn.querySelectorAll('span.font-semibold').forEach(s => {
      s.classList.remove('text-white');
      s.classList.add('text-gray-700');
    });
    btn.querySelectorAll('span.bg-gray-200').forEach(s => {
      s.classList.remove('bg-indigo-500', 'text-white');
      s.classList.add('bg-gray-200', 'text-gray-600');
    });
  });

  // Show selected content panel
  const content = document.getElementById('category-content-' + category);
  if (content) content.classList.remove('hidden');

  // Highlight active sidebar tab
  const activeTab = document.querySelector(`.category-tab[data-category="${category}"]`);
  if (activeTab) {
    activeTab.classList.add('bg-indigo-600', 'border-indigo-600');
    activeTab.classList.remove('bg-gray-50', 'border-transparent');
    activeTab.querySelectorAll('i').forEach(i => {
      i.classList.add('text-white');
      i.classList.remove('text-gray-400');
    });
    activeTab.querySelectorAll('span.font-semibold').forEach(s => {
      s.classList.add('text-white');
      s.classList.remove('text-gray-700');
    });
    activeTab.querySelectorAll('span.bg-gray-200').forEach(s => {
      s.classList.add('bg-indigo-500', 'text-white');
      s.classList.remove('bg-gray-200', 'text-gray-600');
    });
  }
}

function showSizeType(type) {
  currentSizeType = type;
  document.getElementById('size_type').value = type;
  
  // Update button states
  const allTypeBtns = document.querySelectorAll('.size-type-btn');
  allTypeBtns.forEach(btn => {
    if (btn.getAttribute('data-size-type') === type) {
      btn.classList.add('active', 'border-indigo-500', 'text-indigo-700', 'bg-indigo-50');
      btn.classList.remove('border-gray-300', 'text-gray-700');
    } else {
      btn.classList.remove('active', 'border-indigo-500', 'text-indigo-700', 'bg-indigo-50');
      btn.classList.add('border-gray-300', 'text-gray-700');
    }
  });
  
  // Show/hide content
  const customizedContent = document.getElementById('customized-content');
  const fixedContent = document.getElementById('fixed-sizes-content');
  const customizedCosts = document.getElementById('customized-costs');
  const referencePrice = document.getElementById('reference-price');
  
  if (type === 'customized') {
    if (customizedContent) {
      customizedContent.style.display = 'block';
      customizedContent.classList.add('active');
    }
    if (fixedContent) {
      fixedContent.style.display = 'none';
      fixedContent.classList.remove('active');
    }
    if (customizedCosts) {
      customizedCosts.style.display = 'grid'; // Show markup/labor fields
    }
    if (referencePrice) {
      referencePrice.style.display = 'block'; // Show reference price
    }
  } else if (type === 'fixed') {
    if (customizedContent) {
      customizedContent.style.display = 'none';
      customizedContent.classList.remove('active');
    }
    if (fixedContent) {
      fixedContent.style.display = 'block';
      fixedContent.classList.add('active');
    }
    if (customizedCosts) {
      customizedCosts.style.display = 'none'; // Hide markup/labor fields
    }
    if (referencePrice) {
      referencePrice.style.display = 'none'; // Hide reference price
    }
    
    // Auto-select first size
    const firstSizeBtn = document.querySelector('.size-select-btn');
    if (firstSizeBtn) {
      selectFixedSize(firstSizeBtn, 0);
    }
  }
}

function selectFixedSize(element, sizeIndex) {
  // Update button states
  const allSizeBtns = document.querySelectorAll('.size-select-btn');
  allSizeBtns.forEach(btn => {
    btn.classList.remove('active', 'bg-indigo-500', 'text-white', 'border-indigo-500');
    btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
  });
  
  element.classList.add('active', 'bg-indigo-500', 'text-white', 'border-indigo-500');
  element.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
  
  // Hide all size detail cards
  const allCards = document.querySelectorAll('.size-detail-card');
  allCards.forEach(card => {
    card.style.display = 'none';
    card.classList.remove('active');
  });
  
  // Show selected size detail card
  const selectedCard = document.querySelector(`[data-size-card="${sizeIndex}"]`);
  if (selectedCard) {
    selectedCard.style.display = 'block';
    selectedCard.classList.add('active');
    
    // Update hidden fields
    const fixedSizeId = selectedCard.getAttribute('data-fixed-size-id');
    document.getElementById('fixed_size_id').value = fixedSizeId;
    
    // Update price display
    updateFixedSizePrice(selectedCard);
  }
}

function updateFixedSizePrice(sizeCard) {
  const colorType = selectedColorType || 'main';
  const colorId = selectedColorId || 'main';
  
  let basePrice = 0;
  
  if (colorType === 'main') {
    basePrice = parseFloat(sizeCard.getAttribute('data-main-price')) || 0;
  } else {
    basePrice = parseFloat(sizeCard.getAttribute('data-price-color-' + colorId)) || 0;
  }
  
  // Update hidden field
  document.getElementById('base_price').value = basePrice;
  
  // Get color name
  let colorName = '';
  if (activeColorSwatch) {
    colorName = activeColorSwatch.getAttribute('data-label');
  }
  
  // Update UI
  const colorNameElement = sizeCard.querySelector('.current-color-name');
  const basePriceElement = sizeCard.querySelector('.base-price-amount');
  const noPriceMessage = sizeCard.querySelector('.no-price-message');
  
  if (basePrice > 0) {
    if (colorNameElement) colorNameElement.textContent = colorName;
    if (basePriceElement) {
      basePriceElement.textContent = '₱' + basePrice.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }
    if (noPriceMessage) noPriceMessage.style.display = 'none';
  } else {
    if (noPriceMessage) noPriceMessage.style.display = 'block';
    if (basePriceElement) basePriceElement.textContent = '';
  }
}

function changeColor(element) {
  const fullImageUrl = element.getAttribute('data-full');
  const colorLabel = element.getAttribute('data-label');
  const colorType = element.getAttribute('data-color-type');
  const colorId = element.getAttribute('data-color-id');
  const mainImage = document.getElementById('item_main_image');

  if (!fullImageUrl || !mainImage) return;

  // Update main image
  mainImage.src = fullImageUrl;
  
  // Update active state
  const allSwatches = document.querySelectorAll('[data-color-type]');
  allSwatches.forEach(swatch => {
    swatch.classList.remove('ring-4', 'ring-indigo-500');
  });
  element.classList.add('ring-4', 'ring-indigo-500');
  
  // Store color info
  activeColorSwatch = element;
  selectedColorType = colorType;
  selectedColorId = colorId;
  
  // Update hidden fields
  document.getElementById('form_color_image').value = fullImageUrl;
  document.getElementById('form_color_label').value = colorLabel;
  document.getElementById('selected_color_type').value = colorType;
  document.getElementById('selected_color_id').value = colorId;
  
  // Update price if in fixed size mode
  if (currentSizeType === 'fixed') {
    const activeCard = document.querySelector('.size-detail-card.active');
    if (activeCard) {
      updateFixedSizePrice(activeCard);
    }
  }
}

let selectedAddons = new Set(); // stores individual addonIds

function toggleAddonSelection(addonCard) {
  const addonId = addonCard.getAttribute('data-addon-id');
  const isRequired = addonCard.getAttribute('data-is-required') === '1';

  const selectionIndicator = addonCard.querySelector('.selection-indicator');
  const checkIcon = selectionIndicator.querySelector('i');
  const hiddenInput = addonCard.querySelector('.addon-selected-input');
  const addonDetails = addonCard.querySelector('.addon-details');

  if (selectedAddons.has(addonId)) {
    // Deselect
    selectedAddons.delete(addonId);
    addonCard.classList.remove('border-indigo-500', 'bg-indigo-50');
    if (isRequired) {
      addonCard.classList.add('border-blue-300', 'bg-blue-50');
    } else {
      addonCard.classList.add('border-gray-200', 'bg-white');
    }
    selectionIndicator.classList.remove('bg-indigo-500', 'border-indigo-500');
    selectionIndicator.classList.add('border-gray-300');
    checkIcon.style.display = 'none';
    hiddenInput.value = '0';
    if (addonDetails) addonDetails.style.display = 'none';

  } else {
    // Select
    selectedAddons.add(addonId);
    addonCard.classList.add('border-indigo-500', 'bg-indigo-50');
    addonCard.classList.remove('border-gray-200', 'bg-white', 'border-blue-300', 'bg-blue-50');
    selectionIndicator.classList.add('bg-indigo-500', 'border-indigo-500');
    selectionIndicator.classList.remove('border-gray-300');
    checkIcon.style.display = 'block';
    hiddenInput.value = '1';

    if (addonDetails) {
      addonDetails.style.display = 'block';
      const mainQty = parseInt(document.getElementById('total_quantity')?.value) || 1;
      const qtyInput = addonCard.querySelector('.addon-qty-input');
      if (qtyInput) {
        const maxQty = parseInt(qtyInput.getAttribute('max'));
        qtyInput.value = (maxQty && mainQty > maxQty) ? maxQty : mainQty;
      }
    }
  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  // Set default color
  const defaultColor = document.getElementById('default_color_image');
  if (defaultColor) {
    activeColorSwatch = defaultColor;
    selectedColorType = 'main';
    selectedColorId = 'main';
    document.getElementById('form_color_image').value = defaultColor.getAttribute('data-full');
    document.getElementById('form_color_label').value = defaultColor.getAttribute('data-label');
    document.getElementById('selected_color_type').value = 'main';
    document.getElementById('selected_color_id').value = 'main';
  }
  
  // Initialize size type based on is_fixed_modular
  const isFixedModular = <?= json_encode(!empty($item['is_fixed_modular']) && $item['is_fixed_modular'] == 1) ?>;
  if (isFixedModular) {
    currentSizeType = 'fixed';
    showSizeType('fixed');
  } else {
    currentSizeType = 'customized';
    showSizeType('customized');
  }

  // Auto-open first addon category
  const firstCategoryTab = document.querySelector('.category-tab');
  if (firstCategoryTab) {
    showCategory(firstCategoryTab.getAttribute('data-category'));
  }

  // Auto-select "currently used" addons (required addons)
  
  // Auto-select "currently used" addons (required addons)
  const currentlyUsedAddons = document.querySelectorAll('[data-is-currently-used="1"]');
  currentlyUsedAddons.forEach(addon => {
    const addonId = addon.getAttribute('data-addon-id');
    const category = addon.getAttribute('data-addon-category');
    selectedAddons.add(addonId);
    
    const selectionIndicator = addon.querySelector('.selection-indicator');
    const checkIcon = selectionIndicator.querySelector('i');
    const hiddenInput = addon.querySelector('.addon-selected-input');
    const addonDetails = addon.querySelector('.addon-details');
    
    addon.classList.add('border-indigo-500', 'bg-indigo-50');
    addon.classList.remove('border-blue-300', 'bg-blue-50');
    selectionIndicator.classList.add('bg-indigo-500', 'border-indigo-500');
    selectionIndicator.classList.remove('border-gray-300');
    checkIcon.style.display = 'block';
    hiddenInput.value = '1';
    
    // Show details for selected addons
    if (addonDetails) {
      addonDetails.style.display = 'block';
    }
  });
});

// ─── Area Input Helpers ───────────────────────────────────────────────
<?php
// Fetch all existing areas for this client from DB
$existingAreasStmt = $conn->prepare("
  SELECT DISTINCT area FROM quotation_entries WHERE client_id = ? AND admin_id = ?
  UNION
  SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ? AND admin_id = ?
  ORDER BY area
");
$existingAreasStmt->bind_param("iiii", $client_id, $admin_id, $client_id, $admin_id);
$existingAreasStmt->execute();
$existingAreasResult = $existingAreasStmt->get_result();
$existingAreasList = [];
while ($aRow = $existingAreasResult->fetch_assoc()) {
  $existingAreasList[] = $aRow['area'];
}
$existingAreasStmt->close();
?>
function getExistingAreas() {
  return <?= json_encode($existingAreasList) ?>;
}

function handleAreaInput(input) {
  const typed = input.value.trim();
  const existing = getExistingAreas();

  // ── Duplicate check (case-insensitive) ──
  const warning = document.getElementById('area-duplicate-warning');
  const warningText = document.getElementById('area-duplicate-text');
  const match = existing.find(a => a.toLowerCase() === typed.toLowerCase() && a !== typed);
  
  if (match) {
    // Same area but different casing — auto-correct silently
    // (we will fix on submit, just warn for now)
    warning.classList.remove('hidden');
    warningText.textContent = `Did you mean "${match}"? Casing will be matched automatically.`;
    input.dataset.suggestedArea = match;
  } else {
    warning.classList.add('hidden');
    delete input.dataset.suggestedArea;
  }

  // ── Suggestions dropdown ──
  const dropdown = document.getElementById('area-suggestions-dropdown');
  const list = document.getElementById('area-suggestions-list');

  const filtered = existing.filter(a => 
    a.toLowerCase().includes(typed.toLowerCase())
  );

  if (filtered.length > 0 && typed.length >= 0) {
    list.innerHTML = filtered.map(a => {
      const isExact = a.toLowerCase() === typed.toLowerCase();
      return `
        <div class="area-suggestion-item px-3 py-2 cursor-pointer hover:bg-indigo-50 flex items-center justify-between border-b border-gray-50 last:border-0"
             onmousedown="selectAreaSuggestion('${a.replace(/'/g, "\\'")}')"
             style="transition: background 0.15s;">
          <span class="text-sm text-gray-700">
            <i class="fas fa-map-marker-alt mr-2 text-indigo-400 text-xs"></i>
            ${a}
          </span>
          ${isExact 
            ? '<span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-semibold">Exact match</span>'
            : '<span class="text-xs text-gray-400">tap to use</span>'
          }
        </div>
      `;
    }).join('');
    dropdown.classList.remove('hidden');
  } else {
    dropdown.classList.add('hidden');
  }
}

function selectAreaSuggestion(area) {
  const input = document.getElementById('area_input');
  if (input) {
    input.value = area;
    delete input.dataset.suggestedArea;
    document.getElementById('area-duplicate-warning').classList.add('hidden');
    document.getElementById('area-suggestions-dropdown').classList.add('hidden');
  }
}

function hideAreaSuggestions() {
  // Delay to allow mousedown click to fire first
  setTimeout(() => {
    const dropdown = document.getElementById('area-suggestions-dropdown');
    if (dropdown) dropdown.classList.add('hidden');
    
    // Auto-correct casing on blur
    const input = document.getElementById('area_input');
    if (input && input.dataset.suggestedArea) {
      input.value = input.dataset.suggestedArea;
      delete input.dataset.suggestedArea;
      document.getElementById('area-duplicate-warning').classList.add('hidden');
    }
  }, 150);
}

// Show all suggestions when field is focused (even empty)
document.addEventListener('DOMContentLoaded', function() {
  const areaInput = document.getElementById('area_input');
  if (areaInput) {
    areaInput.addEventListener('focus', function() {
      handleAreaInput(this);
    });
  }
});
// ─────────────────────────────────────────────────────────────────────

// Shared helper: sync all selected addon quantities to a given qty
function syncAddonQuantities(qty) {
  document.querySelectorAll('.addon-card').forEach(card => {
    const selectedInput = card.querySelector('.addon-selected-input');
    const qtyInput = card.querySelector('.addon-qty-input');
    if (selectedInput && selectedInput.value === '1' && qtyInput) {
      const maxQty = parseInt(qtyInput.getAttribute('max'));
      qtyInput.value = (maxQty && qty > maxQty) ? maxQty : qty;
    }
  });
}

// Recalculate addon area from user dimension inputs
function recalcAddonArea(addonId, dimType) {
  const getVal = slot => {
    const el = document.querySelector(`input[name="addon_user_dim_${slot}[${addonId}]"]`);
    return el ? parseFloat(el.value) || 0 : 0;
  };
  const v1 = getVal(1), v2 = getVal(2), v3 = getVal(3);
  let area = 0;
  if (dimType === 'sqm') {
    area = v1 * v2; // width × length
  } else {
    area = v1; // linear — first dimension is the length
  }
  area = Math.round(area * 10000) / 10000;
  const display = document.getElementById('addon-area-display-' + addonId);
  if (display) display.textContent = area.toFixed(4);
  const hidden = document.getElementById('addon-computed-area-' + addonId);
  if (hidden) hidden.value = area;
}

// Update quantity validation
document.getElementById('total_quantity')?.addEventListener('change', function() {
  const newQty = parseInt(this.value) || 1;

  // Sync addon quantities
  syncAddonQuantities(newQty);
});

function filterByType(btn, category, type) {
  // Update button active states within this category
  const categoryContent = document.getElementById('category-content-' + category);
  if (!categoryContent) return;

  categoryContent.querySelectorAll('.type-filter-btn').forEach(b => {
    b.classList.remove('bg-indigo-500', 'border-indigo-500', 'text-white');
    b.classList.add('bg-white', 'border-gray-300', 'text-gray-600');
  });
  btn.classList.add('bg-indigo-500', 'border-indigo-500', 'text-white');
  btn.classList.remove('bg-white', 'border-gray-300', 'text-gray-600');

  // Show/hide addon cards based on type
  categoryContent.querySelectorAll('.addon-card').forEach(card => {
    if (type === 'all') {
      card.style.display = '';
    } else {
      const cardType = card.querySelector('.addon-name')?.nextElementSibling?.textContent?.trim() || '';
      card.style.display = cardType.includes(type) ? '' : 'none';
    }
  });
}
</script>

<style>
.size-content { display: none; }
.size-content.active { display: block; }

.size-type-btn {
  transition: all 0.2s;
}

.size-select-btn {
  transition: all 0.2s;
}

.size-select-btn.active {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-color: #667eea;
}

.addon-card {
  cursor: pointer;
  transition: all 0.2s;
}

.addon-card:hover {
  transform: translateY(-2px);
}

.selection-indicator {
  transition: all 0.2s;
  background: white;
}

.addon-card.border-indigo-500 .selection-indicator {
  background: #667eea;
  border-color: #667eea;
}

/* Modal styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.5);
  align-items: center;
  justify-content: center;
  overflow-y: auto;
}

.modal-content {
  background-color: #fefefe;
  margin: 20px auto;
  border-radius: 12px;
  max-width: 600px;
  width: 90%;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 30px;
  border-bottom: 1px solid #e5e7eb;
}

.modal-header h2 {
  font-size: 20px;
  font-weight: bold;
  color: #1f2937;
  margin: 0;
}

.modal-close {
  font-size: 24px;
  color: #6b7280;
  cursor: pointer;
  background: none;
  border: none;
  padding: 0;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  transition: all 0.2s;
}

.modal-close:hover {
  background: #f3f4f6;
  color: #111827;
}
</style>
      </form>

<!-- Variants Section -->
<div class="mt-10">
  <h2 class="text-lg font-semibold mb-3 text-gray-800">Product Variants</h2>
  <?php if (empty($variants)): ?>
    <div class="bg-white p-6 rounded-lg text-center">
      <i class="fas fa-box-open" style="font-size: 40px; color: #ddd; margin-bottom: 10px;"></i>
      <p class="text-gray-500">No variants available for this item.</p>
    </div>
  <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
      <?php foreach ($variants as $v): ?>
        <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&search=<?= urlencode($v['item_code']) ?>"
          class="block hover:shadow-lg transition">
          <div class="bg-white shadow-md rounded-lg p-4 text-center">
            <?php if (!empty($v['item_image_path'])): ?>
              <img src="<?= CLIENT_ASSET ?>/images/products/<?= htmlspecialchars($v['item_image_path']) ?>"
                class="mx-auto mb-2 w-24 h-24 object-contain rounded" alt="Variant Image">
            <?php else: ?>
              <div class="mx-auto mb-2 w-24 h-24 bg-gray-100 rounded flex items-center justify-center">
                <i class="fas fa-image text-gray-300 text-2xl"></i>
              </div>
            <?php endif; ?>
            <h4 class="text-sm font-semibold"><?= htmlspecialchars($v['item_name']) ?></h4>
            <?php if (!empty($v['item_material'])): ?>
  <p class="text-xs text-gray-600">
    <span class="font-semibold text-gray-400" style="font-size:9px; text-transform:uppercase;">Carcass:</span>
    <?= htmlspecialchars($v['item_material']) ?>
  </p>
<?php endif; ?>
<?php if (!empty($v['door_material'])): ?>
  <p class="text-xs text-gray-600">
    <span class="font-semibold text-gray-400" style="font-size:9px; text-transform:uppercase;">Door:</span>
    <?= htmlspecialchars($v['door_material']) ?>
  </p>
<?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>