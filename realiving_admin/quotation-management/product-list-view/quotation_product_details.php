<?php
//quotation_product_details.php
include $includes ['mainbody'];
require_role(['admin1', 'superadmin', 'sales', 'designer', 'technical_designer','project_coordinator']);

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../login.php");
  exit();
}

$admin_id = $_SESSION['admin_id'];

// Get URL parameters
$client_id = isset($_GET['id']) ? $_GET['id'] : '';
$client_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';
$client_email = isset($_GET['email']) ? urldecode($_GET['email']) : '';
$client_address = isset($_GET['address']) ? urldecode($_GET['address']) : '';
$client_contact = isset($_GET['contact']) ? urldecode($_GET['contact']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch client data
$project_name = '';
$business_type = '';
if ($client_id) {
  $stmt = $conn->prepare("SELECT nameproject, business_type FROM user_info WHERE id = ?");
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $project_name = $row['nameproject'];
    $business_type = $row['business_type'];
  }
}

// Display-friendly business type label
$business_type_label = $business_type === 'Non-Project' ? 'Individual' : $business_type;

// Fetch item data
$items = [];
if ($search !== '') {
  $like = '%' . $conn->real_escape_string($search) . '%';
  $sql = "
  SELECT 
    item_id, item_image_path, item_color, item_code, item_name, item_material,
    door_material,
    item_family, non_project_price, project_price,
    mark_up, labor_cost, jackup,
    dimension_msmt_fk, dimension_label_fk,
    is_fixed_modular
  FROM items
  WHERE (item_code LIKE ? OR item_name LIKE ?)
  AND is_hidden = 0
  ORDER BY item_name
";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $like, $like);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    // Fetch dimension info
    $dimension_sql = "SELECT * FROM dimension_measurement WHERE dimension_msmt_id = ?";
    $dimension_stmt = $conn->prepare($dimension_sql);
    $dimension_stmt->bind_param("i", $r['dimension_msmt_fk']);
    $dimension_stmt->execute();
    $dimension_result = $dimension_stmt->get_result();
    $r['dimension'] = $dimension_result->fetch_assoc();

    // Fetch label info
    $label_sql = "SELECT * FROM dimension_label WHERE dimension_label_id = ?";
    $label_stmt = $conn->prepare($label_sql);
    $label_stmt->bind_param("i", $r['dimension_label_fk']);
    $label_stmt->execute();
    $label_result = $label_stmt->get_result();
    $r['labels'] = $label_result->fetch_assoc();

    // Fetch standard colors
    $color_sql = "
      SELECT standard_color_id, standard_color, standard_color_image_path
      FROM item_standard_color
      WHERE fk_standard_color = ?
    ";
    $color_stmt = $conn->prepare($color_sql);
    $color_stmt->bind_param("i", $r['item_id']);
    $color_stmt->execute();
    $color_res = $color_stmt->get_result();
    $r['colors'] = [];
    while ($c = $color_res->fetch_assoc()) {
      $r['colors'][] = $c;
    }

    $items[] = $r;
  }
}

// Check if Quotation stage is Done (locked)
$quotationDone = false;
if ($client_id) {
    $quotationStmt = $conn->prepare("
        SELECT status FROM project_tracker 
        WHERE client_id = ? AND stage_name = 'Quotation'
    ");
    $quotationStmt->bind_param("i", $client_id);
    $quotationStmt->execute();
    $quotationResult = $quotationStmt->get_result()->fetch_assoc();
    if ($quotationResult && $quotationResult['status'] === 'Done') {
        $quotationDone = true;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quotation'])) {
    // Block saving if quotation is locked
    if ($quotationDone) {
        header("Location: " . BASE_URL . "quotation-items?id=$client_id&name=" . urlencode($client_name) . 
               "&email=" . urlencode($client_email) . "&address=" . urlencode($client_address) . 
               "&contact=" . urlencode($client_contact) . "&error=locked");
        exit();
    }
  $colorLabel = $_POST['color_label'] ?? '';
  $itemId = intval($_POST['entry_item_id']);
  $itemCode = $_POST['item_code'];
  $sizeType = $_POST['size_type'] ?? 'customized';
  
  $item = $items[0];
  
  $selectedColorImage = $_POST['color_image'] ?? '';
  $colorImagePath = '';
  if (!empty($selectedColorImage)) {
    if (strpos($selectedColorImage, 'product_colors') !== false) {
      preg_match('/product_colors\/(.+)$/', $selectedColorImage, $matches);
      $colorImagePath = $matches[1] ?? '';
    } elseif (strpos($selectedColorImage, 'products') !== false) {
      preg_match('/products\/(.+)$/', $selectedColorImage, $matches);
      $colorImagePath = $matches[1] ?? '';
    }
  }
  
  if (empty($colorImagePath)) {
    $colorImagePath = $item['item_image_path'];
  }

  if ($sizeType === 'customized') {
    // CUSTOMIZED SIZE
    $unitPrice = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0.00;
    $dimMsmtId = intval($_POST['dimension_msmt_id']);
    $dimLabelId = intval($_POST['dimension_label_id']);
    $widthLabel = $_POST['width_label'];
    $heightLabel = $_POST['height_label'];
    $lengthLabel = $_POST['length_label'];
    $unitMode = $_POST['unit_mode'];
    $width = floatval($_POST['width']);
    $height = floatval($_POST['height']);
    $length = floatval($_POST['length']);
    $markUp = floatval($_POST['mark_up']);
    $jackup = floatval($_POST['jackup']);
    $laborCost = floatval($_POST['labor_cost']);
    $quantity = intval($_POST['quantity']);
    $area = $_POST['area'];
    $unit_type = $_POST['unit_type'] ?? 'pcs';

    $ins = $conn->prepare("
      INSERT INTO quotation_entries
      (admin_id, client_id, entry_item_id, item_code, item_name, unit_price, item_image_path, color_image_path,
       dimension_msmt_id, dimension_label_id, unit_mode,
       width, height, length, width_label, height_label, length_label,
       mark_up, jackup, labor_cost, quantity, unit_type, area, color_label)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $savedItemName = $item['item_name'];
if (!empty($item['item_material'])) $savedItemName .= ' C-' . $item['item_material'];
if (!empty($item['door_material'])) $savedItemName .= ', D-' . $item['door_material'];

$ins->bind_param(
  "iiissdssiisiiisssddiisss",
  $admin_id, $client_id, $itemId, $itemCode, $savedItemName, $unitPrice,
      $item['item_image_path'], $colorImagePath,
      $dimMsmtId, $dimLabelId, $unitMode,
      $width, $height, $length,
      $widthLabel, $heightLabel, $lengthLabel,
      $markUp, $jackup, $laborCost,
      $quantity, $unit_type, $area, $colorLabel
    );

    $ins->execute();

    if ($ins->affected_rows) {
      $entry_id = $ins->insert_id;

      $addonIns = $conn->prepare("
        INSERT INTO quotation_entry_addons
          (quotation_entry_id, addon_id, quantity, price, labor_cost, note)
        VALUES (?,?,?,?,?,?)
      ");

      if (!empty($_POST['addon_selected'])) {
  $addonIns = $conn->prepare("
    INSERT INTO quotation_entry_addons
      (quotation_entry_id, addon_id, quantity, price, labor_cost, note,
       dimension_type, dimension_label_1, dimension_label_2, dimension_label_3,
       dimension_value_1, dimension_value_2, dimension_value_3,
       addon_jackup, user_dim_value_1, user_dim_value_2, user_dim_value_3, computed_area)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  foreach ($_POST['addon_selected'] as $addonId => $isSelected) {
    if ($isSelected == '1') {
      $qty        = intval($_POST['addon_qty'][$addonId] ?? 1);
      $price      = floatval($_POST['addon_price'][$addonId] ?? 0.00);
      $labor      = floatval($_POST['addon_labor_cost'][$addonId] ?? 0.00);
      $note       = trim($_POST['addon_note'][$addonId] ?? '');
      $dimType    = $_POST['addon_dim_type'][$addonId] ?? null;
      $dimLabel1  = $_POST['addon_dim_label_1'][$addonId] ?? null;
      $dimLabel2  = $_POST['addon_dim_label_2'][$addonId] ?? null;
      $dimLabel3  = $_POST['addon_dim_label_3'][$addonId] ?? null;
      $dimDef1    = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
      $dimDef2    = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
      $dimDef3    = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
      $jackupVal  = !empty($_POST['addon_jackup_val'][$addonId]) ? floatval($_POST['addon_jackup_val'][$addonId]) : null;
      $userDim1   = !empty($_POST['addon_user_dim_1'][$addonId]) ? floatval($_POST['addon_user_dim_1'][$addonId]) : null;
      $userDim2   = !empty($_POST['addon_user_dim_2'][$addonId]) ? floatval($_POST['addon_user_dim_2'][$addonId]) : null;
      $userDim3   = !empty($_POST['addon_user_dim_3'][$addonId]) ? floatval($_POST['addon_user_dim_3'][$addonId]) : null;
      $compArea   = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

      $addonIns->bind_param(
        "iiiddsssssdddddddd",
        $entry_id, $addonId, $qty, $price, $labor, $note,
        $dimType, $dimLabel1, $dimLabel2, $dimLabel3,
        $dimDef1, $dimDef2, $dimDef3, $jackupVal,
        $userDim1, $userDim2, $userDim3, $compArea
      );
      $addonIns->execute();
    }
  }
}

      header("Location: " . BASE_URL . "quotation-items?id=$client_id&name=" . urlencode($client_name) .
       "&email=" . urlencode($client_email) . "&address=" . urlencode($client_address) .
       "&contact=" . urlencode($client_contact) . "&success=1");
exit();
    }
  } else if ($sizeType === 'fixed') {
    // FIXED SIZE
    $fixedSizeId = intval($_POST['fixed_size_id']);
    $selectedColorType = $_POST['selected_color_type'] ?? 'main';
    $selectedColorIdValue = $_POST['selected_color_id'] ?? null;
    $basePrice = floatval($_POST['base_price'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    $area = $_POST['area'] ?? '';
    $unit_type = $_POST['unit_type'] ?? 'pcs';
    
    if ($selectedColorIdValue === 'main' || empty($selectedColorIdValue)) {
      $selectedColorIdValue = null;
    } else {
      $selectedColorIdValue = intval($selectedColorIdValue);
    }

    $fixedIns = $conn->prepare("
      INSERT INTO quotation_fixed_sizes
      (admin_id, client_id, item_id, item_code, item_name, item_image_path, color_image_path,
       color_label, fixed_size_id, selected_color_type, selected_color_id, base_price,
       quantity, unit_type, area)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $itemName = $item['item_name'];
if (!empty($item['item_material'])) $itemName .= ' C-' . $item['item_material'];
if (!empty($item['door_material'])) $itemName .= ', D-' . $item['door_material'];

$fixedIns->bind_param(
      "iiisssssissdiss",
      $admin_id, $client_id, $itemId, $itemCode, $itemName,
      $item['item_image_path'], $colorImagePath, $colorLabel,
      $fixedSizeId, $selectedColorType, $selectedColorIdValue, $basePrice,
      $quantity, $unit_type, $area
    );

    $fixedIns->execute();

    if ($fixedIns->affected_rows) {
      $quotation_fixed_size_id = $fixedIns->insert_id;

      if (!empty($_POST['addon_selected'])) {
        foreach ($_POST['addon_selected'] as $addonId => $isSelected) {
  if ($isSelected == '1') {
    $category   = $_POST['addon_category'][$addonId] ?? 'Uncategorized';
    $qty        = intval($_POST['addon_qty'][$addonId] ?? 1);
    $price      = floatval($_POST['addon_price'][$addonId] ?? 0.00);
    $labor      = floatval($_POST['addon_labor_cost'][$addonId] ?? 0.00);
    $note       = trim($_POST['addon_note'][$addonId] ?? '');
    $dimType    = $_POST['addon_dim_type'][$addonId] ?? null;
    $dimLabel1  = $_POST['addon_dim_label_1'][$addonId] ?? null;
    $dimLabel2  = $_POST['addon_dim_label_2'][$addonId] ?? null;
    $dimLabel3  = $_POST['addon_dim_label_3'][$addonId] ?? null;
    $dimDef1    = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
    $dimDef2    = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
    $dimDef3    = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
    $jackupVal  = !empty($_POST['addon_jackup_val'][$addonId]) ? floatval($_POST['addon_jackup_val'][$addonId]) : null;
    $userDim1   = !empty($_POST['addon_user_dim_1'][$addonId]) ? floatval($_POST['addon_user_dim_1'][$addonId]) : null;
    $userDim2   = !empty($_POST['addon_user_dim_2'][$addonId]) ? floatval($_POST['addon_user_dim_2'][$addonId]) : null;
    $userDim3   = !empty($_POST['addon_user_dim_3'][$addonId]) ? floatval($_POST['addon_user_dim_3'][$addonId]) : null;
    $compArea   = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

    $fixedAddonIns = $conn->prepare("
      INSERT INTO quotation_fixed_size_addons
        (quotation_fixed_size_id, addon_id, addon_category, quantity, price, labor_cost, note,
         dimension_type, dimension_label_1, dimension_label_2, dimension_label_3,
         dimension_value_1, dimension_value_2, dimension_value_3,
         addon_jackup, user_dim_value_1, user_dim_value_2, user_dim_value_3, computed_area)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $fixedAddonIns->bind_param(
      "iisiddssssddddddddd",
      $quotation_fixed_size_id, $addonId, $category, $qty, $price, $labor, $note,
      $dimType, $dimLabel1, $dimLabel2, $dimLabel3,
      $dimDef1, $dimDef2, $dimDef3, $jackupVal,
      $userDim1, $userDim2, $userDim3, $compArea
    );
    $fixedAddonIns->execute();
  }
}
      }

      header("Location: " . BASE_URL . "quotation-items?id=$client_id&name=" . urlencode($client_name) .
       "&email=" . urlencode($client_email) . "&address=" . urlencode($client_address) .
       "&contact=" . urlencode($client_contact) . "&success=1");
exit();
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Details</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .category-tab.active {
      border-color: #4f46e5;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }
    .category-tab.active i,
    .category-tab.active span { color: white !important; }
    .category-tab.active .bg-gray-100 { background: rgba(255, 255, 255, 0.3); color: white; }
  </style>
</head>
<body class="bg-gray-50">
  <!-- Header with Back Button -->
  <div class="bg-white shadow-sm border-b">
    <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
      <a href="quotation-items?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=all&family=all"
         class="flex items-center gap-2 text-gray-700 hover:text-gray-900 font-semibold transition">
        <i class="fas fa-arrow-left"></i>
        <span>Back to Products</span>
      </a>
      <div class="text-right">
  <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($client_name) ?></h1>
  <p class="text-sm text-gray-600"><?= htmlspecialchars($project_name) ?></p>
  <?php if ($business_type): ?>
    <span style="
      display: inline-block;
      margin-top: 4px;
      padding: 2px 10px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 600;
      background: <?= $business_type === 'Project' ? '#d1fae5' : '#ede9fe' ?>;
      color: <?= $business_type === 'Project' ? '#065f46' : '#4c1d95' ?>;
    ">
      <?= htmlspecialchars($business_type_label) ?>
    </span>
  <?php endif; ?>
</div>
    </div>
  </div>

  <!-- Main Content -->
  <div class="max-w-7xl mx-auto px-4 py-8">
    <?php if (empty($items)): ?>
      <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-search text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">Product Not Found</h3>
        <p class="text-gray-500">The product you're looking for doesn't exist.</p>
      </div>
    <?php else: ?>
      <?php include $includes ['quotation-modal-content']; ?>
    <?php endif; ?>
  </div>

  <script>
    const dimensionData = <?= json_encode($item['dimension'] ?? []) ?>;
    const labelData = <?= json_encode($item['labels'] ?? []) ?>;

    function updateDimensions() {
      const mode = document.getElementById('unit_mode').value;
      document.getElementById('form_width_label').value = document.getElementById('width_label').textContent;
      document.getElementById('form_height_label').value = document.getElementById('height_label').textContent;
      document.getElementById('form_length_label').value = document.getElementById('length_label').textContent;
      
      let sel = document.querySelector('.ring-indigo-500') || document.getElementById('item_main_image');
      document.getElementById('form_color_image').value = sel.getAttribute('data-full') || sel.src;

      const width = parseFloat(mode === 'linear' ? dimensionData.item_width_linear : dimensionData.item_width_sqm);
      const height = parseFloat(mode === 'linear' ? dimensionData.item_height_linear : dimensionData.item_height_sqm);
      const length = parseFloat(mode === 'linear' ? dimensionData.item_length_linear : dimensionData.item_length_sqm);

      document.getElementById('width_input').value = isNaN(width) ? '' : width.toString();
      document.getElementById('height_input').value = isNaN(height) ? '' : height.toString();
      document.getElementById('length_input').value = isNaN(length) ? '' : length.toString();

      const wLab = mode === 'linear' ? labelData.item_width_label_linear : labelData.item_width_label_sqm;
      const hLab = mode === 'linear' ? labelData.item_height_label_linear : labelData.item_height_label_sqm;
      const lLab = mode === 'linear' ? labelData.item_length_label_linear : labelData.item_length_label_sqm;

      document.getElementById('width_label').textContent = wLab;
      document.getElementById('height_label').textContent = hLab;
      document.getElementById('length_label').textContent = lLab;

      document.getElementById('form_width_label').value = wLab;
      document.getElementById('form_height_label').value = hLab;
      document.getElementById('form_length_label').value = lLab;

      const colorClass = mode === 'linear' ? 'bg-yellow-500' : 'bg-blue-600';
      ['width_label', 'height_label', 'length_label'].forEach(id => {
        const el = document.getElementById(id);
        el.className = `text-white text-xs px-2 py-1 rounded font-semibold ${colorClass}`;
      });
    }

    let activeSwatch = null;
    function toggleColor(el) {
      const full = el.getAttribute('data-full');
      const label = el.getAttribute('data-label');
      if (!full) return;

      const mainImg = document.getElementById('item_main_image');
      const hiddenField = document.getElementById('form_color_image');
      const hiddenLabel = document.getElementById('form_color_label');

      if (activeSwatch === el) {
        mainImg.src = mainImg.getAttribute('data-original');
        el.classList.remove('ring-4', 'ring-indigo-500');
        activeSwatch = null;
        hiddenField.value = mainImg.getAttribute('data-original');
        hiddenLabel.value = document.getElementById('default_color_image').getAttribute('data-label');
      } else {
        if (activeSwatch) {
          activeSwatch.classList.remove('ring-4', 'ring-indigo-500');
        }
        mainImg.src = full;
        el.classList.add('ring-4', 'ring-indigo-500');
        activeSwatch = el;
        hiddenField.value = full;
        hiddenLabel.value = label;
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (document.getElementById('unit_mode')) {
        updateDimensions();
      }

      const defaultImg = document.getElementById('default_color_image');
      if (defaultImg) {
        activeSwatch = defaultImg;
        document.getElementById('form_color_image').value = defaultImg.getAttribute('data-full');
        document.getElementById('form_color_label').value = defaultImg.getAttribute('data-label');
      }

      const requiredCheckboxes = document.querySelectorAll('input[name^="addon_selected"]:disabled');
      requiredCheckboxes.forEach(checkbox => {
        checkbox.checked = true;
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = checkbox.name;
        hiddenInput.value = checkbox.value;
        checkbox.parentNode.appendChild(hiddenInput);
      });

      const firstCategory = document.querySelector('.category-tab');
      if (firstCategory) {
        const categoryName = firstCategory.getAttribute('data-category');
        showCategory(categoryName);
      }
    });

    function scrollCategories(direction) {
      const container = document.getElementById('category-scroll-container');
      const scrollAmount = 200;
      if (direction === 'left') {
        container.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
      } else {
        container.scrollBy({ left: scrollAmount, behavior: 'smooth' });
      }
    }

    function showCategory(categoryName) {
      document.querySelectorAll('.category-content').forEach(el => {
        el.classList.add('hidden');
      });
      document.querySelectorAll('.category-tab').forEach(tab => {
        tab.classList.remove('active');
      });
      const content = document.getElementById('category-content-' + categoryName);
      if (content) {
        content.classList.remove('hidden');
      }
      const tab = document.querySelector(`.category-tab[data-category="${categoryName}"]`);
      if (tab) {
        tab.classList.add('active');
      }
    }

    function loadVariant(itemCode) {
      window.location.href = `?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&search=${encodeURIComponent(itemCode)}`;
    }
  </script>
</body>
</html>