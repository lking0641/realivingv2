<?php
//quotation_items.php
include $includes ['mainbody'];
// Allow only sales and superadmin
require_role(['superadmin', 'sales', 'designer', 'technical_designer', 'project_coordinator']);

$admin_id = $_SESSION['admin_id'];

// Get URL parameters (for client info)
$client_id = isset($_GET['id']) ? $_GET['id'] : '';
$client_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';
$client_email = isset($_GET['email']) ? urldecode($_GET['email']) : '';
$client_address = isset($_GET['address']) ? urldecode($_GET['address']) : '';
$client_contact = isset($_GET['contact']) ? urldecode($_GET['contact']) : '';

// Fetch additional client data: project_name & business_type
$project_name = '';
$business_type = '';
$project_scope = '';
$scope_of_work = '';
$reference_number = '';
$status = '';

if ($client_id) {
  // Display-friendly business type label
  $business_type_label = $business_type === 'Non-Project' ? 'Individual' : $business_type;
  $house_state = '';
  $permit_required = '';
  $target_movein_date = '';

  $stmt = $conn->prepare("SELECT nameproject, business_type, project_scope, scope_of_work, reference_number, status, house_state, permit_required, target_movein_date, gender, client_class, client_type FROM user_info WHERE id = ?");
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $project_name = $row['nameproject'];
    $business_type = $row['business_type'];
    $project_scope = $row['project_scope'];
    $scope_of_work = $row['scope_of_work'];
    $reference_number = $row['reference_number'];
    $status = $row['status'];
    $house_state = $row['house_state'] ?? '';
    $permit_required = $row['permit_required'] ?? '';
    $target_movein_date = $row['target_movein_date'] ?? '';
    $gender = $row['gender'] ?? '';
    $client_class = $row['client_class'] ?? '';
    $client_type = $row['client_type'] ?? '';
  }
}

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_item_code = isset($_GET['search_item_code']) ? trim($_GET['search_item_code']) : '';
$search_item_id = isset($_GET['search_item_id']) ? intval($_GET['search_item_id']) : 0;
$items = [];

// If search_item_code exists, use it for search (takes priority)
if ($search_item_code !== '') {
  $search = $search_item_code;
}

// If searching by item ID, get that specific item's code
if ($search_item_id > 0) {
  $stmt = $conn->prepare("SELECT item_code FROM items WHERE item_id = ?");
  $stmt->bind_param("i", $search_item_id);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $search = $row['item_code'];
  }
  $stmt->close();
}

if ($search !== '') {
  $like = '%' . $conn->real_escape_string($search) . '%';
  $sql = "
  SELECT 
    item_id,
    item_image_path, item_color, item_code, item_name, item_material,
    item_family, 
    non_project_price, project_price,
    mark_up, labor_cost, jackup,
    dimension_msmt_fk,
    dimension_label_fk,
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
      SELECT standard_color_id,
             standard_color,
             standard_color_image_path
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

// Fetch variants if we have items
$variants = [];
if (!empty($items)) {
  $item = $items[0];
  $item_family = $item['item_family'] ?? '';

  if (!empty($item_family)) {
    $stmt = $conn->prepare("SELECT * FROM items WHERE item_family = ? AND item_code != ? AND is_hidden = 0");
    $stmt->bind_param("ss", $item_family, $item['item_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($variant = $result->fetch_assoc()) {
      $variants[] = $variant;
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quotation'])) {
  // Block saving if quotation is locked
  if ($quotationDone) {
    echo "<div class='p-4 bg-red-100 text-red-800 mb-4 rounded border border-red-300'>
              <i class='fas fa-lock mr-2'></i>
              <strong>Quotation Locked:</strong> The Quotation stage is marked as \"Done\". No new items can be added.
            </div>";
    // Don't process further
    goto skip_quotation_save;
  }

  $colorLabel = $_POST['color_label'] ?? '';
  $itemId = intval($_POST['entry_item_id']);
  $itemCode = $_POST['item_code'];
  $sizeType = $_POST['size_type'] ?? 'customized';

  // Debugging: Check what size_type is being received
  error_log("Size Type Received: " . $sizeType);
  error_log("POST Data: " . print_r($_POST, true));

  // Get color info
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

  $item = $items[0];
  if (empty($colorImagePath)) {
    $colorImagePath = $item['item_image_path'];
  }

  if ($sizeType === 'customized') {
    // ========================================
    // CUSTOMIZED SIZE - Use quotation_entries
    // ========================================
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
    if (!empty($item['item_material']))
      $savedItemName .= ' C-' . $item['item_material'];
    if (!empty($item['door_material']))
      $savedItemName .= ', D-' . $item['door_material'];

    $ins->bind_param(
      "iiissdssiisiiisssddiisss",
      $admin_id,
      $client_id,
      $itemId,
      $itemCode,
      $savedItemName,
      $unitPrice,
      $item['item_image_path'],
      $colorImagePath,
      $dimMsmtId,
      $dimLabelId,
      $unitMode,
      $width,
      $height,
      $length,
      $widthLabel,
      $heightLabel,
      $lengthLabel,
      $markUp,
      $jackup,
      $laborCost,
      $quantity,
      $unit_type,
      $area,
      $colorLabel
    );

    $ins->execute();

    if ($ins->affected_rows) {
      $entry_id = $ins->insert_id;

      // Handle room distribution if provided
      if (!empty($_POST['room_distribution']) && is_array($_POST['room_distribution'])) {
        $distStmt = $conn->prepare("
      INSERT INTO quotation_room_distribution
      (quotation_entry_id, room_unit_number, room_unit_name, quantity, notes)
      VALUES (?, ?, ?, ?, ?)
    ");

        foreach ($_POST['room_distribution'] as $room) {
          $roomNumber = intval($room['room_number'] ?? 0);
          $roomName = trim($room['room_name'] ?? '');
          $roomQty = intval($room['quantity'] ?? 0);
          $roomNotes = trim($room['notes'] ?? '');

          if ($roomQty > 0) {
            $distStmt->bind_param("iisis", $entry_id, $roomNumber, $roomName, $roomQty, $roomNotes);
            $distStmt->execute();
          }
        }
        $distStmt->close();
      }

      // Handle customized addons
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
            $qty = intval($_POST['addon_qty'][$addonId] ?? 1);
            $price = floatval($_POST['addon_price'][$addonId] ?? 0.00);
            $labor = floatval($_POST['addon_labor_cost'][$addonId] ?? 0.00);
            $note = trim($_POST['addon_note'][$addonId] ?? '');
            $dimType = $_POST['addon_dim_type'][$addonId] ?? null;
            $dimLabel1 = $_POST['addon_dim_label_1'][$addonId] ?? null;
            $dimLabel2 = $_POST['addon_dim_label_2'][$addonId] ?? null;
            $dimLabel3 = $_POST['addon_dim_label_3'][$addonId] ?? null;
            $dimDef1 = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
            $dimDef2 = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
            $dimDef3 = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
            $jackupVal = !empty($_POST['addon_jackup_val'][$addonId]) ? floatval($_POST['addon_jackup_val'][$addonId]) : null;
            $userDim1 = !empty($_POST['addon_user_dim_1'][$addonId]) ? floatval($_POST['addon_user_dim_1'][$addonId]) : null;
            $userDim2 = !empty($_POST['addon_user_dim_2'][$addonId]) ? floatval($_POST['addon_user_dim_2'][$addonId]) : null;
            $userDim3 = !empty($_POST['addon_user_dim_3'][$addonId]) ? floatval($_POST['addon_user_dim_3'][$addonId]) : null;
            $compArea = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

            $addonIns->bind_param(
              "iiiddsssssdddddddd",
              $entry_id,
              $addonId,
              $qty,
              $price,
              $labor,
              $note,
              $dimType,
              $dimLabel1,
              $dimLabel2,
              $dimLabel3,
              $dimDef1,
              $dimDef2,
              $dimDef3,
              $jackupVal,
              $userDim1,
              $userDim2,
              $userDim3,
              $compArea
            );
            $addonIns->execute();
          }
        }
      }

      echo "<div class='p-4 bg-green-100 text-green-800 mb-4 rounded'>
              ✓ Customized quotation saved successfully.
            </div>";
    } else {
      echo "<div class='p-4 bg-red-100 text-red-800 mb-4 rounded'>
              ✗ Error saving customized quotation.
            </div>";
    }

  } else if ($sizeType === 'fixed') {
    // ========================================
    // FIXED SIZE - Use quotation_fixed_sizes (SEPARATE TABLE)
    // ========================================
    $fixedSizeId = intval($_POST['fixed_size_id']);
    $selectedColorType = $_POST['selected_color_type'] ?? 'main';
    $selectedColorIdValue = $_POST['selected_color_id'] ?? null;
    $basePrice = floatval($_POST['base_price'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    $area = $_POST['area'] ?? '';
    $unit_type = $_POST['unit_type'] ?? 'pcs';

    // Debugging
    error_log("Fixed Size ID: " . $fixedSizeId);
    error_log("Selected Color Type: " . $selectedColorType);
    error_log("Selected Color ID: " . $selectedColorIdValue);
    error_log("Base Price: " . $basePrice);

    // Convert 'main' to NULL for storage
    if ($selectedColorIdValue === 'main' || empty($selectedColorIdValue)) {
      $selectedColorIdValue = null;
    } else {
      $selectedColorIdValue = intval($selectedColorIdValue);
    }

    // Insert into quotation_fixed_sizes (STANDALONE)
    $fixedIns = $conn->prepare("
      INSERT INTO quotation_fixed_sizes
      (admin_id, client_id, item_id, item_code, item_name, item_image_path, color_image_path,
       color_label, fixed_size_id, selected_color_type, selected_color_id, base_price,
       quantity, unit_type, area)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $itemName = $item['item_name'];
    if (!empty($item['item_material']))
      $itemName .= ' C-' . $item['item_material'];
    if (!empty($item['door_material']))
      $itemName .= ', D-' . $item['door_material'];

    if ($selectedColorIdValue === null) {
      // Use bind_param with NULL handling
      $fixedIns->bind_param(
        "iiisssssisdiss",
        $admin_id,
        $client_id,
        $itemId,
        $itemCode,
        $itemName,
        $item['item_image_path'],
        $colorImagePath,
        $colorLabel,
        $fixedSizeId,
        $selectedColorType,
        $selectedColorIdValue,
        $basePrice,
        $quantity,
        $unit_type,
        $area
      );
    } else {
      $fixedIns->bind_param(
        "iiisssssissdiss",
        $admin_id,
        $client_id,
        $itemId,
        $itemCode,
        $itemName,
        $item['item_image_path'],
        $colorImagePath,
        $colorLabel,
        $fixedSizeId,
        $selectedColorType,
        $selectedColorIdValue,
        $basePrice,
        $quantity,
        $unit_type,
        $area
      );
    }

    $fixedIns->execute();

    if ($fixedIns->affected_rows) {
      $quotation_fixed_size_id = $fixedIns->insert_id;

      // Handle room distribution if provided
      if (!empty($_POST['room_distribution']) && is_array($_POST['room_distribution'])) {
        $distStmt = $conn->prepare("
      INSERT INTO quotation_room_distribution
      (quotation_fixed_size_id, room_unit_number, room_unit_name, quantity, notes)
      VALUES (?, ?, ?, ?, ?)
    ");

        foreach ($_POST['room_distribution'] as $room) {
          $roomNumber = intval($room['room_number'] ?? 0);
          $roomName = trim($room['room_name'] ?? '');
          $roomQty = intval($room['quantity'] ?? 0);
          $roomNotes = trim($room['notes'] ?? '');

          if ($roomQty > 0) {
            $distStmt->bind_param("iisis", $quotation_fixed_size_id, $roomNumber, $roomName, $roomQty, $roomNotes);
            $distStmt->execute();
          }
        }
        $distStmt->close();
      }

      // Handle fixed size addons (ONE PER CATEGORY)
      if (!empty($_POST['addon_selected'])) {
        foreach ($_POST['addon_selected'] as $addonId => $isSelected) {
          if ($isSelected == '1') {
            $category = $_POST['addon_category'][$addonId] ?? 'Uncategorized';
            $qty = intval($_POST['addon_qty'][$addonId] ?? 1);
            $price = floatval($_POST['addon_price'][$addonId] ?? 0.00);
            $labor = floatval($_POST['addon_labor_cost'][$addonId] ?? 0.00);
            $note = trim($_POST['addon_note'][$addonId] ?? '');
            $dimType = $_POST['addon_dim_type'][$addonId] ?? null;
            $dimLabel1 = $_POST['addon_dim_label_1'][$addonId] ?? null;
            $dimLabel2 = $_POST['addon_dim_label_2'][$addonId] ?? null;
            $dimLabel3 = $_POST['addon_dim_label_3'][$addonId] ?? null;
            $dimDef1 = !empty($_POST['addon_dim_default_1'][$addonId]) ? floatval($_POST['addon_dim_default_1'][$addonId]) : null;
            $dimDef2 = !empty($_POST['addon_dim_default_2'][$addonId]) ? floatval($_POST['addon_dim_default_2'][$addonId]) : null;
            $dimDef3 = !empty($_POST['addon_dim_default_3'][$addonId]) ? floatval($_POST['addon_dim_default_3'][$addonId]) : null;
            $jackupVal = !empty($_POST['addon_jackup_val'][$addonId]) ? floatval($_POST['addon_jackup_val'][$addonId]) : null;
            $userDim1 = !empty($_POST['addon_user_dim_1'][$addonId]) ? floatval($_POST['addon_user_dim_1'][$addonId]) : null;
            $userDim2 = !empty($_POST['addon_user_dim_2'][$addonId]) ? floatval($_POST['addon_user_dim_2'][$addonId]) : null;
            $userDim3 = !empty($_POST['addon_user_dim_3'][$addonId]) ? floatval($_POST['addon_user_dim_3'][$addonId]) : null;
            $compArea = !empty($_POST['addon_computed_area'][$addonId]) ? floatval($_POST['addon_computed_area'][$addonId]) : null;

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
              $quotation_fixed_size_id,
              $addonId,
              $category,
              $qty,
              $price,
              $labor,
              $note,
              $dimType,
              $dimLabel1,
              $dimLabel2,
              $dimLabel3,
              $dimDef1,
              $dimDef2,
              $dimDef3,
              $jackupVal,
              $userDim1,
              $userDim2,
              $userDim3,
              $compArea
            );
            $fixedAddonIns->execute();
          }
        }
      }

      echo "<div class='p-4 bg-green-100 text-green-800 mb-4 rounded'>
              ✓ Fixed size quotation saved successfully.
            </div>";
    } else {
      echo "<div class='p-4 bg-red-100 text-red-800 mb-4 rounded'>
              ✗ Error saving fixed size quotation: " . $fixedIns->error . "
            </div>";
    }
  }

  skip_quotation_save:
  ;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quotation List</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: #f5f5f5;
    }

    /* Client Header */
    .client-header {
      background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
      color: white;
      padding: 40px;
      border-radius: 12px;
      margin: 30px auto;
      max-width: 1400px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .client-header h1 {
      font-size: 32px;
      margin-bottom: 10px;
    }

    .client-header p {
      opacity: 0.9;
      font-size: 16px;
    }

    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-top: 20px;
    }

    .info-card {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 10px;
      padding: 15px;
      transition: all 0.3s ease;
      overflow: hidden;
    }

    .info-card:hover {
      background: rgba(255, 255, 255, 0.25);
      transform: translateY(-2px);
    }

    .info-icon {
      width: 40px;
      height: 40px;
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(5px);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 8px;
      transition: all 0.3s ease;
    }

    .info-card:hover .info-icon {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }

    .info-label {
      font-size: 11px;
      opacity: 0.75;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .info-value {
      font-size: 14px;
      font-weight: 600;
      margin-top: 4px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }

    /* Badge */
    .badge {
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      display: inline-block;
      margin-top: 4px;
    }

    .badge-new {
      background: #fef3c7;
      color: #92400e;
    }

    .badge-old {
      background: #dbeafe;
      color: #1e40af;
    }

    /* View Details Button */
    .btn-view-details {
      background: white;
      color: #3b1f0f;
      padding: 10px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      margin-top: 15px;
    }

    .btn-view-details:hover {
      background: #f5f5f5;
      transform: translateY(-1px);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      align-items: center;
      justify-content: center;
      overflow-y: auto;
    }

    .modal.active {
      display: flex;
    }

    .modal-content {
      background-color: #fefefe;
      padding: 30px;
      border-radius: 12px;
      max-width: 600px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      margin: 20px;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .modal-header h2 {
      font-size: 24px;
      font-weight: bold;
      color: #3b1f0f;
    }

    .modal-close {
      font-size: 24px;
      color: #666;
      cursor: pointer;
      background: none;
      border: none;
    }

    .modal-close:hover {
      color: #000;
    }

    .detail-row {
      display: grid;
      grid-template-columns: 140px 1fr;
      padding: 12px 0;
      border-bottom: 1px solid #e9ecef;
    }

    .detail-row:last-child {
      border-bottom: none;
    }

    .detail-label {
      font-weight: 600;
      color: #666;
    }

    .detail-value {
      color: #111;
    }

    /* Search Section */
    .search-container {
      max-width: 1400px;
      margin: 0 auto 30px;
      padding: 0 30px;
    }

    .search-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .search-form {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 15px;
    }

    .search-input-group {
      display: flex;
      gap: 10px;
      flex: 1;
      min-width: 300px;
    }

    .search-input {
      flex: 1;
      padding: 12px 16px;
      padding-right: 50px;
      /* Make room for loading spinner */
      border: 2px solid #e9ecef;
      border-radius: 8px;
      font-size: 14px;
      transition: all 0.2s;
    }

    .search-input:focus {
      outline: none;
      border-color: #3b1f0f;
      border-radius: 8px 8px 0 0;
      /* Round only top when suggestions are shown */
    }

    .btn {
      padding: 12px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }

    .btn-search {
      background: #3b1f0f;
      color: white;
    }

    .btn-search:hover {
      background: #2a1609;
      transform: translateY(-1px);
    }

    .btn-computation {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      color: white;
    }

    .btn-computation:hover {
      background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
      transform: translateY(-1px);
    }

    /* Content Container */
    .content-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 30px 30px;
    }

    /* Hide scrollbar but keep functionality */
    .hide-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .hide-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    /* Active category tab */
    .category-tab.active {
      border-color: #4f46e5;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }

    .category-tab.active i,
    .category-tab.active span {
      color: white !important;
    }

    .category-tab.active .bg-gray-100 {
      background: rgba(255, 255, 255, 0.3);
      color: white;
    }

    /* Optional hover for swatches */
    img[data-full] {
      transition: transform 0.2s ease;
    }

    img[data-full]:hover {
      transform: scale(1.05);
    }

    @media (max-width: 768px) {
      .info-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .search-input-group {
        min-width: 100%;
      }
    }

    /* Search Suggestions Dropdown */
    .search-suggestions {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border: 2px solid #e9ecef;
      border-top: none;
      border-radius: 0 0 8px 8px;
      max-height: 400px;
      overflow-y: auto;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      z-index: 1000;
      display: none;
    }

    .search-suggestions.active {
      display: block;
    }

    .suggestion-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      cursor: pointer;
      transition: all 0.2s;
      border-bottom: 1px solid #f0f0f0;
    }

    .suggestion-item:last-child {
      border-bottom: none;
    }

    .suggestion-item:hover {
      background: #f8f9fa;
    }

    .suggestion-image {
      width: 50px;
      height: 50px;
      object-fit: cover;
      border-radius: 6px;
      background: #f5f5f5;
      flex-shrink: 0;
    }

    .suggestion-image-placeholder {
      width: 50px;
      height: 50px;
      background: #f5f5f5;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ddd;
      flex-shrink: 0;
    }

    .suggestion-details {
      flex: 1;
      min-width: 0;
    }

    .suggestion-name {
      font-weight: 600;
      color: #3b1f0f;
      font-size: 14px;
      margin-bottom: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .suggestion-code {
      font-size: 12px;
      color: #666;
      font-family: monospace;
    }

    .suggestion-material {
      font-size: 11px;
      color: #999;
      margin-top: 2px;
    }

    .suggestion-price {
      font-weight: 600;
      color: #28a745;
      font-size: 14px;
      white-space: nowrap;
    }

    .no-suggestions {
      padding: 20px;
      text-align: center;
      color: #999;
      font-size: 14px;
    }

    .search-wrapper {
      position: relative;
      flex: 1;
      min-width: 300px;
    }

    .search-loading {
      position: absolute;
      right: 50px;
      top: 50%;
      transform: translateY(-50%);
      color: #3b1f0f;
    }

    .spinner {
      border: 2px solid #f3f3f3;
      border-top: 2px solid #3b1f0f;
      border-radius: 50%;
      width: 16px;
      height: 16px;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    @media (max-width: 768px) {
      .search-suggestions {
        max-height: 300px;
      }

      .suggestion-item {
        padding: 10px 12px;
      }

      .suggestion-image,
      .suggestion-image-placeholder {
        width: 40px;
        height: 40px;
      }

      .suggestion-name {
        font-size: 13px;
      }

      .suggestion-code {
        font-size: 11px;
      }

      .suggestion-price {
        font-size: 13px;
      }
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

    @media (max-width: 768px) {
      .products-grid-quotation {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
      }

      .product-image-quotation {
        height: 200px;
      }
    }

    @media (max-width: 480px) {
      .products-grid-quotation {
        grid-template-columns: 1fr;
      }
    }


    .size-content {
      display: none;
    }

    .size-content.active {
      display: block;
    }

    .size-type-btn {
      transition: all 0.2s ease;
    }

    .size-type-btn.active {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white !important;
      border-color: #667eea !important;
    }

    .size-select-btn {
      transition: all 0.2s ease;
      background: white;
      color: #374151;
      border: 2px solid #d1d5db;
    }

    .size-select-btn.active {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border-color: #667eea;
    }

    .size-select-btn:hover {
      border-color: #9ca3af;
    }

    .addon-card {
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .addon-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .selection-indicator {
      transition: all 0.2s ease;
      background: white;
    }

    .addon-card.border-indigo-500 .selection-indicator {
      background: #667eea;
      border-color: #667eea;
    }
  </style>
</head>

<body>

  <!-- Client Information Header -->
  <div class="client-header">
    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
      <div>
        <h1>📋 <?= htmlspecialchars($client_name) ?></h1>
        <p><?= htmlspecialchars($project_name) ?></p>
      </div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px;">
        <button onclick="viewClientDetails()" class="btn-view-details">
          <i class="fas fa-info-circle"></i>
          View Full Details
        </button>
        <button onclick="openEditModal()" class="btn-view-details" style="background: #f59e0b; color: white;">
          <i class="fas fa-edit"></i>
          Edit Client
        </button>
      </div>
    </div>

    <!-- Info Grid -->
    <div class="info-grid">
      <?php if ($reference_number): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-hashtag" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Reference Number</div>
          <div class="info-value" style="font-family: monospace;"><?= htmlspecialchars($reference_number) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($status): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-tag" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Status</div>
          <div class="info-value">
            <span class="badge badge-<?= $status === 'New Client' ? 'new' : 'old' ?>">
              <?= htmlspecialchars($status) ?>
            </span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($business_type): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-building" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Business Type</div>
          <div class="info-value">
            <?= $business_type === 'Non-Project' ? 'Individual' : htmlspecialchars($business_type) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($client_contact): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-phone" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Contact</div>
          <div class="info-value"><?= htmlspecialchars($client_contact) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($client_email): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-envelope" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Email</div>
          <div class="info-value" style="font-size: 12px;"><?= htmlspecialchars($client_email) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($client_address): ?>
        <div class="info-card">
          <div class="info-icon">
            <i class="fas fa-map-marker-alt" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label">Address</div>
          <div class="info-value" style="font-size: 12px;"><?= htmlspecialchars($client_address) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($quotationDone): ?>
    <div style="max-width:1400px; margin:0 auto; padding:0 30px;">
      <div
        style="background:#fef3c7; border-left:4px solid #f59e0b; padding:16px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-lock" style="color:#f59e0b; font-size:20px;"></i>
        <div>
          <strong style="color:#92400e;">Quotation Locked</strong>
          <p style="color:#92400e; margin-top:4px; font-size:14px;">
            The Quotation stage is marked as "Done". No new items can be added to this quotation.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Search Form -->
  <div class="search-container">
    <div class="search-card">
      <form method="get" class="search-form" id="searchForm">
        <!-- Hidden fields -->
        <input type="hidden" name="id" value="<?= htmlspecialchars($client_id) ?>">
        <input type="hidden" name="name" value="<?= urlencode($client_name) ?>">
        <input type="hidden" name="email" value="<?= urlencode($client_email) ?>">
        <input type="hidden" name="address" value="<?= urlencode($client_address) ?>">
        <input type="hidden" name="contact" value="<?= urlencode($client_contact) ?>">
        <input type="hidden" name="category" value="all">
        <input type="hidden" name="family" value="all">

        <!-- Search input group with wrapper for suggestions -->
        <div class="search-wrapper">
          <div class="search-input-group">
            <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($search) ?>"
              placeholder="Search item code or name…" class="search-input" autocomplete="off" />

            <!-- Loading spinner -->
            <div id="searchLoading" class="search-loading" style="display: none;">
              <div class="spinner"></div>
            </div>

            <button type="submit" class="btn btn-search">
              <i class="fas fa-search"></i>
              Search
            </button>
          </div>

          <!-- Suggestions Dropdown -->
          <div id="searchSuggestions" class="search-suggestions"></div>
        </div>

        <!-- Computation buttons - Smart display based on data -->
        <?php
        // Check what types of computations exist for this client
        $hasCustomized = false;
        $hasFixed = false;

        $checkCustomized = $conn->prepare("SELECT COUNT(*) as count FROM quotation_entries WHERE client_id = ? AND admin_id = ?");
        $checkCustomized->bind_param("ii", $client_id, $admin_id);
        $checkCustomized->execute();
        $customizedResult = $checkCustomized->get_result()->fetch_assoc();
        $hasCustomized = $customizedResult['count'] > 0;

        $checkFixed = $conn->prepare("SELECT COUNT(*) as count FROM quotation_fixed_sizes WHERE client_id = ? AND admin_id = ?");
        $checkFixed->bind_param("ii", $client_id, $admin_id);
        $checkFixed->execute();
        $fixedResult = $checkFixed->get_result()->fetch_assoc();
        $hasFixed = $fixedResult['count'] > 0;

        // Determine which button(s) to show
        if ($hasCustomized && $hasFixed) {
          // Both types exist - show unified computation list
          ?>
          <a href="computation-list?client_id=<?= urlencode($client_id) ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
            class="btn btn-computation"
            style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-calculator"></i>
            View All Computations
          </a>
          <?php
        } elseif ($hasCustomized) {
          // Only customized exists
          ?>
          <a href="computation-list?client_id=<?= urlencode($client_id) ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
            class="btn btn-computation"
            style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-calculator"></i>
            Customized Computation
          </a>
          <?php
        } elseif ($hasFixed) {
          // Only fixed exists - now redirects to unified computation_list
          ?>
          <a href="computation-list?client_id=<?= urlencode($client_id) ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
            class="btn btn-computation"
            style="background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <i class="fas fa-ruler-combined"></i>
            Fixed Size Computation
          </a>
          <?php
        } else {
          // No computations yet
          ?>
          <span class="text-gray-500 italic" style="padding: 12px 20px; font-size: 14px;">
            <i class="fas fa-info-circle"></i>
            No computations added yet
          </span>
          <?php
        }
        ?>
      </form>
    </div>
  </div>

  <!-- Search Results / Product Display -->
  <div class="content-container">
    <?php if ($search !== ''): ?>
      <?php if (count($items) === 0): ?>
        <div style="background: white; padding: 40px; border-radius: 12px; text-align: center;">
          <i class="fas fa-search" style="font-size: 48px; color: #ddd; margin-bottom: 15px;"></i>
          <p style="color: #666;">No items found for "<?= htmlspecialchars($search) ?>".</p>
          <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=all&family=all"
            class="btn btn-search" style="margin-top: 20px; display: inline-flex;">
            <i class="fas fa-arrow-left"></i>
            Back to Products
          </a>
        </div>
      <?php else: ?>
        <!-- Show search results message -->
        <div
          style="background: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
          <div>
            <i class="fas fa-search" style="color: #3b1f0f; margin-right: 8px;"></i>
            <strong>Search Results:</strong> Found <?= count($items) ?> item(s) for "<?= htmlspecialchars($search) ?>"
          </div>
          <a href="?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&category=all&family=all"
            class="btn btn-search" style="padding: 8px 16px; font-size: 13px;">
            <i class="fas fa-times"></i>
            Clear Search
          </a>
        </div>

        <!-- Display Search Results as Cards -->
        <div class="products-grid-quotation">
          <?php foreach ($items as $item):
            $image_path = !empty($item['item_image_path'])
              ? CLIENT_ASSET . '/images/products/' . htmlspecialchars($item['item_image_path'])
              : '';
            $price = $business_type === 'Project' ? $item['project_price'] : $item['non_project_price'];

            // Fetch dimension label name
            $dimension_label_name = '';
            if (!empty($item['dimension_label_fk'])) {
              $label_stmt = $conn->prepare("SELECT dimension_label_name FROM dimension_label WHERE dimension_label_id = ?");
              $label_stmt->bind_param("i", $item['dimension_label_fk']);
              $label_stmt->execute();
              $label_result = $label_stmt->get_result();
              if ($label_row = $label_result->fetch_assoc()) {
                $dimension_label_name = $label_row['dimension_label_name'];
              }
              $label_stmt->close();
            }
            ?>
            <div class="product-card-quotation">
              <div class="product-image-quotation">
                <?php if (!empty($image_path)): ?>
                  <img src="<?= $image_path ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" />
                <?php else: ?>
                  <div class="no-image">
                    <i class="fas fa-image"></i>
                  </div>
                <?php endif; ?>

                <?php if (!empty($dimension_label_name)): ?>
                  <span class="product-badge-quotation">
                    <?= htmlspecialchars($dimension_label_name) ?>
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
                      <?= htmlspecialchars($item['item_material']) ?>
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
      <?php endif; ?>
    <?php else: ?>
      <!-- Display products with filters when no search -->
      <?php include $includes ['quotation-products-display']; ?>
    <?php endif; ?>
  </div>

  <!-- Modal for viewing full client details -->
  <div id="clientDetailModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>
          <i class="fas fa-user-circle" style="color: #3b1f0f;"></i> Client Details
        </h2>
        <button onclick="closeClientModal()" class="modal-close">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div id="clientModalContent">
        <div class="detail-row">
          <div class="detail-label">Reference Number:</div>
          <div class="detail-value" style="font-family: monospace; color: #3b82f6;">
            <?= htmlspecialchars($reference_number) ?>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Client Name:</div>
          <div class="detail-value" id="view-clientname"><?= htmlspecialchars($client_name) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Project Name:</div>
          <div class="detail-value" id="view-nameproject"><?= htmlspecialchars($project_name) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Status:</div>
          <div class="detail-value" id="view-status">
            <span class="badge badge-<?= $status === 'New Client' ? 'new' : 'old' ?>">
              <?= htmlspecialchars($status) ?>
            </span>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Business Type:</div>
          <div class="detail-value" id="view-business-type">
            <?= $business_type === 'Non-Project' ? 'Individual' : htmlspecialchars($business_type) ?>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Phone:</div>
          <div class="detail-value" id="view-contact"><?= htmlspecialchars($client_contact) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Email:</div>
          <div class="detail-value" id="view-email"><?= htmlspecialchars($client_email) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Address:</div>
          <div class="detail-value" id="view-address"><?= htmlspecialchars($client_address) ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Gender:</div>
          <div class="detail-value" id="view-gender"><?= htmlspecialchars($gender ?? '') ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Classification:</div>
          <div class="detail-value" id="view-client-class"><?= htmlspecialchars($client_class ?? '') ?></div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Client Type:</div>
          <div class="detail-value" id="view-client-type"><?= htmlspecialchars($client_type ?? '') ?></div>
        </div>
        <?php if ($project_scope): ?>
          <div class="detail-row">
            <div class="detail-label">Project Scope:</div>
            <div class="detail-value" id="view-project-scope"><?= nl2br(htmlspecialchars($project_scope)) ?></div>
          </div>
        <?php endif; ?>
        <?php if ($scope_of_work): ?>
          <div class="detail-row">
            <div class="detail-label">Scope of Work:</div>
            <div class="detail-value" id="view-scope-of-work"><?= nl2br(htmlspecialchars($scope_of_work)) ?></div>
          </div>
        <?php endif; ?>
        <div class="detail-row">
          <div class="detail-label">House State:</div>
          <div class="detail-value" id="view-house-state">
            <?php if ($house_state): ?>
              <?php
              $hsBg = '#fef3c7';
              $hsColor = '#92400e';
              if ($house_state === 'Bare/Empty Lot') {
                $hsBg = '#dbeafe';
                $hsColor = '#1e40af';
              } elseif ($house_state === 'Construction Started') {
                $hsBg = '#fee2e2';
                $hsColor = '#991b1b';
              } elseif ($house_state === 'Renovation') {
                $hsBg = '#ede9fe';
                $hsColor = '#5b21b6';
              }
              ?>
              <span
                style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
                <?= htmlspecialchars($house_state) ?>
              </span>
            <?php else: ?>
              <span style="color:#9ca3af;">—</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Permit Required:</div>
          <div class="detail-value" id="view-permit-required">
            <?php if ($permit_required): ?>
              <?php
              $prBg = '#fef3c7';
              $prColor = '#92400e';
              if ($permit_required === 'Yes') {
                $prBg = '#fee2e2';
                $prColor = '#991b1b';
              } elseif ($permit_required === 'No') {
                $prBg = '#d1fae5';
                $prColor = '#065f46';
              }
              ?>
              <span
                style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:<?= $prBg ?>; color:<?= $prColor ?>;">
                <?= htmlspecialchars($permit_required) ?>
              </span>
            <?php else: ?>
              <span style="color:#9ca3af;">—</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Target Move-in:</div>
          <div class="detail-value" id="view-target-movein">
            <?= $target_movein_date ? '<i class="fas fa-calendar-check" style="color:#10b981;"></i> ' . date('F d, Y', strtotime($target_movein_date)) : '<span style="color:#9ca3af;">—</span>' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== EDIT CLIENT MODAL ===== -->
  <div id="editClientModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="modal-header">
        <h2 style="color: #3b1f0f;">
          <i class="fas fa-edit" style="color: #f59e0b;"></i> Edit Client Info
        </h2>
        <button onclick="closeEditModal()" class="modal-close">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Success / Error alerts inside modal -->
      <div id="editAlertSuccess"
        style="display:none; background:#d1fae5; border-left:4px solid #10b981; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> Client updated successfully!
      </div>
      <div id="editAlertError"
        style="display:none; background:#fee2e2; border-left:4px solid #ef4444; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
        <i class="fas fa-exclamation-circle"></i> <span id="editErrorMsg">Error updating client.</span>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; padding-top:8px;">

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Client Name</label>
          <input type="text" id="edit-clientname" class="form-input-edit" value="<?= htmlspecialchars($client_name) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Project Name</label>
          <input type="text" id="edit-nameproject" class="form-input-edit"
            value="<?= htmlspecialchars($project_name) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Status</label>
          <select id="edit-status"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="New Client" <?= $status === 'New Client' ? 'selected' : '' ?>>New Client</option>
            <option value="Old Client" <?= $status === 'Old Client' ? 'selected' : '' ?>>Old Client</option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Business Type</label>
          <select id="edit-business-type"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="Non-Project" <?= $business_type === 'Non-Project' ? 'selected' : '' ?>>Individual</option>
            <option value="Project" <?= $business_type === 'Project' ? 'selected' : '' ?>>Project</option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Contact Number</label>
          <input type="text" id="edit-contact" class="form-input-edit" value="<?= htmlspecialchars($client_contact) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Email</label>
          <input type="email" id="edit-email" class="form-input-edit" value="<?= htmlspecialchars($client_email) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Client Classification</label>
          <select id="edit-client-class"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="VIP">VIP</option>
            <option value="Regular">Regular</option>
            <option value="Walk-in">Walk-in</option>
            <option value="Returning">Returning</option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Gender</label>
          <select id="edit-gender"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
            <option value="Prefer not to say">Prefer not to say</option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px; grid-column: span 2;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Address</label>
          <textarea id="edit-address" rows="2"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%; resize:vertical;"><?= htmlspecialchars($client_address) ?></textarea>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px; grid-column: span 2;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Project Scope</label>
          <input type="text" id="edit-project-scope" value="<?= htmlspecialchars($project_scope) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        </div>

        <div style="display:flex; flex-direction:column; gap:6px; grid-column: span 2;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Scope of Work</label>
          <textarea id="edit-scope-of-work" rows="3"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%; resize:vertical;"><?= htmlspecialchars($scope_of_work) ?></textarea>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">State of the House</label>
          <select id="edit-house-state"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="">— Select —</option>
            <option value="Bare/Empty Lot" <?= $house_state === 'Bare/Empty Lot' ? 'selected' : '' ?>>Bare / Empty Lot
            </option>
            <option value="Existing Structure" <?= $house_state === 'Existing Structure' ? 'selected' : '' ?>>Existing
              Structure (No renovation yet)</option>
            <option value="Renovation" <?= $house_state === 'Renovation' ? 'selected' : '' ?>>Existing Structure (For
              Renovation)</option>
            <option value="Construction Started" <?= $house_state === 'Construction Started' ? 'selected' : '' ?>>
              Construction Already Started</option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Permit Required?</label>
          <select id="edit-permit-required"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
            <option value="">— Select —</option>
            <option value="Yes" <?= $permit_required === 'Yes' ? 'selected' : '' ?>>Yes — Permit Required</option>
            <option value="No" <?= $permit_required === 'No' ? 'selected' : '' ?>>No — Not Required</option>
            <option value="Unsure" <?= $permit_required === 'Unsure' ? 'selected' : '' ?>>Unsure — Needs Assessment
            </option>
          </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px;">
          <label style="font-size:13px; font-weight:600; color:#374151;">Target Move-in Date</label>
          <input type="date" id="edit-target-movein" value="<?= htmlspecialchars($target_movein_date) ?>"
            style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <label
            style="display:flex; align-items:center; gap:8px; margin-top:4px; font-size:13px; color:#6b7280; cursor:pointer; font-weight:normal;">
            <input type="checkbox" id="edit-no-movein-date" onchange="toggleEditMoveInDate(this)"
              <?= empty($target_movein_date) ? 'checked' : '' ?>
              style="width:15px; height:15px; cursor:pointer; accent-color:#3b82f6;">
            None / Not yet determined
          </label>
        </div>

      </div>

      <div
        style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e5e7eb;">
        <button onclick="closeEditModal()"
          style="padding:10px 20px; background:#6b7280; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px;">
          Cancel
        </button>
        <button onclick="saveClientEdit()"
          style="padding:10px 24px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px;">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </div>
  </div>

  <!-- JavaScript Section -->
  <script>
    const dimensionData = <?= !empty($item['dimension']) ? json_encode($item['dimension']) : 'null' ?>;
    const labelData = <?= !empty($item['labels']) ? json_encode($item['labels']) : 'null' ?>;

    function updateDimensions() {
      // Don't run if no dimension/label data (fixed modular items)
      if (!dimensionData || !labelData) {
        return;
      }
      const mode = document.getElementById('unit_mode').value;

      // After setting the labels:
      document.getElementById('form_width_label').value = document.getElementById('width_label').textContent;
      document.getElementById('form_height_label').value = document.getElementById('height_label').textContent;
      document.getElementById('form_length_label').value = document.getElementById('length_label').textContent;
      // Color
      let sel = document.querySelector('.ring-indigo-500') || document.getElementById('item_main_image');
      document.getElementById('form_color_image').value = sel.getAttribute('data-full') || sel.src;

      // Assign values
      const width = parseFloat(mode === 'linear' ? dimensionData.item_width_linear : dimensionData.item_width_sqm);
      const height = parseFloat(mode === 'linear' ? dimensionData.item_height_linear : dimensionData.item_height_sqm);
      const length = parseFloat(mode === 'linear' ? dimensionData.item_length_linear : dimensionData.item_length_sqm);

      document.getElementById('width_input').value = isNaN(width) ? '' : width.toString();
      document.getElementById('height_input').value = isNaN(height) ? '' : height.toString();
      document.getElementById('length_input').value = isNaN(length) ? '' : length.toString();

      // Labels
      // 2) Compute & write the visible labels
      const wLab = mode === 'linear'
        ? labelData.item_width_label_linear
        : labelData.item_width_label_sqm;
      const hLab = mode === 'linear'
        ? labelData.item_height_label_linear
        : labelData.item_height_label_sqm;
      const lLab = mode === 'linear'
        ? labelData.item_length_label_linear
        : labelData.item_length_label_sqm;

      // Display the label text
      document.getElementById('width_label').textContent = wLab;
      document.getElementById('height_label').textContent = hLab;
      document.getElementById('length_label').textContent = lLab;

      // Update the hidden input values for submission
      document.getElementById('form_width_label').value = wLab;
      document.getElementById('form_height_label').value = hLab;
      document.getElementById('form_length_label').value = lLab;

      // Color (yellow for linear, blue for sqm)
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
        // Revert to original
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

      // Handle required addons - ensure they're always checked
      const requiredCheckboxes = document.querySelectorAll('input[name^="addon_selected"]:disabled');
      requiredCheckboxes.forEach(checkbox => {
        // Ensure required addons are always checked
        checkbox.checked = true;

        // Add hidden input to ensure value is submitted even when disabled
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = checkbox.name;
        hiddenInput.value = checkbox.value;
        checkbox.parentNode.appendChild(hiddenInput);
      });

      // Show first category by default
      const firstCategory = document.querySelector('.category-tab');
      if (firstCategory) {
        const categoryName = firstCategory.getAttribute('data-category');
        showCategory(categoryName);
      }
    });

    // Category navigation
    let currentCategory = null;

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
      // Hide all category contents
      document.querySelectorAll('.category-content').forEach(el => {
        el.classList.add('hidden');
      });

      // Remove active class from all tabs
      document.querySelectorAll('.category-tab').forEach(tab => {
        tab.classList.remove('active');
      });

      // Show selected category content
      const content = document.getElementById('category-content-' + categoryName);
      if (content) {
        content.classList.remove('hidden');
      }

      // Add active class to clicked tab
      const tab = document.querySelector(`.category-tab[data-category="${categoryName}"]`);
      if (tab) {
        tab.classList.add('active');
      }

      currentCategory = categoryName;
    }

    // Client details modal functions
    function viewClientDetails() {
      document.getElementById('clientDetailModal').classList.add('active');
    }
    function closeClientModal() {
      document.getElementById('clientDetailModal').classList.remove('active');
    }
    document.getElementById('clientDetailModal').addEventListener('click', function (e) {
      if (e.target === this) closeClientModal();
    });

    // ── Edit modal ──
    function openEditModal() {
      closeClientModal();
      document.getElementById('editClientModal').classList.add('active');
      // Sync checkbox state with date input on open
      const dateInput = document.getElementById('edit-target-movein');
      const checkbox = document.getElementById('edit-no-movein-date');
      if (checkbox && dateInput) {
        checkbox.checked = !dateInput.value;
        dateInput.disabled = !dateInput.value;
      }
    }

    function toggleEditMoveInDate(checkbox) {
      const dateInput = document.getElementById('edit-target-movein');
      if (checkbox.checked) {
        dateInput.value = '';
        dateInput.disabled = true;
      } else {
        dateInput.disabled = false;
      }
    }
    function closeEditModal() {
      document.getElementById('editClientModal').classList.remove('active');
      document.getElementById('editAlertSuccess').style.display = 'none';
      document.getElementById('editAlertError').style.display = 'none';
    }
    document.getElementById('editClientModal').addEventListener('click', function (e) {
      if (e.target === this) closeEditModal();
    });

    async function saveClientEdit() {
      const payload = {
        client_id: <?= intval($client_id) ?>,
        clientname: document.getElementById('edit-clientname').value.trim(),
        nameproject: document.getElementById('edit-nameproject').value.trim(),
        status: document.getElementById('edit-status').value,
        business_type: document.getElementById('edit-business-type').value,
        contact: document.getElementById('edit-contact').value.trim(),
        email: document.getElementById('edit-email').value.trim(),
        address: document.getElementById('edit-address').value.trim(),
        gender: document.getElementById('edit-gender').value,
        client_class: document.getElementById('edit-client-class').value,
        project_scope: document.getElementById('edit-project-scope').value.trim(),
        scope_of_work: document.getElementById('edit-scope-of-work').value.trim(),
        house_state: document.getElementById('edit-house-state').value,
        permit_required: document.getElementById('edit-permit-required').value,
        target_movein_date: document.getElementById('edit-target-movein').value,
      };

      try {
        const res = await fetch('<?= BASE_URL ?>update-client-info', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
          // Update view modal fields
          document.getElementById('view-clientname').textContent = data.clientname;
          document.getElementById('view-nameproject').textContent = data.nameproject;
          document.getElementById('view-contact').textContent = data.contact;
          document.getElementById('view-email').textContent = data.email;
          document.getElementById('view-address').textContent = data.address;
          document.getElementById('view-business-type').textContent =
            data.business_type === 'Non-Project' ? 'Individual' : data.business_type;

          const genderEl = document.getElementById('view-gender');
          if (genderEl) genderEl.textContent = data.gender || '—';
          const classEl = document.getElementById('view-client-class');
          if (classEl) classEl.textContent = data.client_class || '—';
          const typeEl = document.getElementById('view-client-type');
          if (typeEl) typeEl.textContent = data.client_type || '—';

          // Update status badge
          const statusEl = document.getElementById('view-status');
          if (statusEl) {
            const isNew = data.status === 'New Client';
            statusEl.innerHTML = `<span class="badge badge-${isNew ? 'new' : 'old'}">${data.status}</span>`;
          }

          // Update project scope
          const scopeEl = document.getElementById('view-project-scope');
          if (scopeEl) scopeEl.innerHTML = data.project_scope ? data.project_scope.replace(/\n/g, '<br>') : '';

          // Update scope of work
          const sowEl = document.getElementById('view-scope-of-work');
          if (sowEl) sowEl.innerHTML = data.scope_of_work ? data.scope_of_work.replace(/\n/g, '<br>') : '';

          // Update house state badge
          const houseStateEl = document.getElementById('view-house-state');
          if (houseStateEl) {
            const hsBgMap = {
              'Bare/Empty Lot': ['#dbeafe', '#1e40af'],
              'Construction Started': ['#fee2e2', '#991b1b'],
              'Renovation': ['#ede9fe', '#5b21b6'],
              'Existing Structure': ['#fef3c7', '#92400e'],
            };
            const [bg, color] = hsBgMap[data.house_state] || ['#f3f4f6', '#6b7280'];
            houseStateEl.innerHTML = data.house_state
              ? `<span style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:${bg}; color:${color};">${data.house_state}</span>`
              : '<span style="color:#9ca3af;">—</span>';
          }

          // Update permit required badge
          const permitEl = document.getElementById('view-permit-required');
          if (permitEl) {
            const prBgMap = {
              'Yes': ['#fee2e2', '#991b1b'],
              'No': ['#d1fae5', '#065f46'],
              'Unsure': ['#fef3c7', '#92400e'],
            };
            const [bg, color] = prBgMap[data.permit_required] || ['#f3f4f6', '#6b7280'];
            permitEl.innerHTML = data.permit_required
              ? `<span style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:${bg}; color:${color};">${data.permit_required}</span>`
              : '<span style="color:#9ca3af;">—</span>';
          }

          // Update target move-in date
          const moveinEl = document.getElementById('view-target-movein');
          if (moveinEl) {
            if (data.target_movein_date) {
              const d = new Date(data.target_movein_date + 'T00:00:00');
              const formatted = d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
              moveinEl.innerHTML = `<i class="fas fa-calendar-check" style="color:#10b981;"></i> ${formatted}`;
            } else {
              moveinEl.innerHTML = '<span style="color:#9ca3af;">—</span>';
            }
          }

          // Update the header
          document.querySelector('.client-header h1').textContent = '📋 ' + data.clientname;
          document.querySelector('.client-header > div > div > p').textContent = data.nameproject;

          // Update info grid cards (business type and status)
          document.querySelectorAll('.info-card').forEach(card => {
            const label = card.querySelector('.info-label');
            const value = card.querySelector('.info-value');
            if (!label || !value) return;
            if (label.textContent.trim() === 'Business Type') {
              value.textContent = data.business_type === 'Non-Project' ? 'Individual' : data.business_type;
            }
            if (label.textContent.trim() === 'Status') {
              const isNew = data.status === 'New Client';
              value.innerHTML = `<span class="badge badge-${isNew ? 'new' : 'old'}">${data.status}</span>`;
            }
          });

          // Update URL so reload keeps correct data
          const url = new URL(window.location.href);
          url.searchParams.set('name', data.clientname);
          url.searchParams.set('email', data.email);
          url.searchParams.set('address', data.address);
          url.searchParams.set('contact', data.contact);
          history.replaceState(null, '', url.toString());

          // Sync to sessionStorage so computation_list picks up the latest
          const clientId = url.searchParams.get('id');
          sessionStorage.setItem('client_' + clientId, JSON.stringify({
            clientname: data.clientname,
            email: data.email,
            address: data.address,
            contact: data.contact,
          }));

          document.getElementById('editAlertSuccess').style.display = 'block';
          document.getElementById('editAlertError').style.display = 'none';
          setTimeout(() => {
            document.getElementById('editAlertSuccess').style.display = 'none';
          }, 3000);
        } else {
          document.getElementById('editErrorMsg').textContent = data.error || 'Unknown error';
          document.getElementById('editAlertError').style.display = 'block';
          document.getElementById('editAlertSuccess').style.display = 'none';
        }
      } catch (err) {
        document.getElementById('editErrorMsg').textContent = err.message;
        document.getElementById('editAlertError').style.display = 'block';
      }
    }

    // Close modal when clicking outside
    document.getElementById('productModal')?.addEventListener('click', function (e) {
      if (e.target === this) {
        closeProductModal();
      }
    });

    // Close modal on ESC key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeProductModal();
      }
    });

    // On page load, check sessionStorage for fresher client data
    (function syncFromSession() {
      const urlParams = new URLSearchParams(window.location.search);
      const clientId = urlParams.get('id');
      if (!clientId) return;
      const stored = sessionStorage.getItem('client_' + clientId);
      if (!stored) return;
      const data = JSON.parse(stored);

      // Update URL
      const url = new URL(window.location.href);
      url.searchParams.set('name', data.clientname);
      url.searchParams.set('email', data.email);
      url.searchParams.set('address', data.address);
      url.searchParams.set('contact', data.contact);
      history.replaceState(null, '', url.toString());
    })();

    // Add tooltips for truncated text
    document.addEventListener('DOMContentLoaded', function () {
      const infoValues = document.querySelectorAll('.info-value');
      infoValues.forEach(el => {
        if (el.scrollWidth > el.clientWidth) {
          el.title = el.textContent.trim();
          el.style.cursor = 'help';
        }
      });
    });

    // View product details - opens modal
    function viewProductDetails(itemCode) {
      window.location.href = `quotation-product-details?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&search=${encodeURIComponent(itemCode)}`;
    }

    // Search Suggestions Functionality
    (function () {
      const searchInput = document.getElementById('searchInput');
      const searchSuggestions = document.getElementById('searchSuggestions');
      const searchLoading = document.getElementById('searchLoading');
      const searchForm = document.getElementById('searchForm');

      let searchTimeout = null;
      let currentRequest = null;

      if (!searchInput) return;

      // Debounced search function
      function performSearch(query) {
        if (query.length < 2) {
          searchSuggestions.classList.remove('active');
          return;
        }

        // Show loading
        searchLoading.style.display = 'block';

        // Cancel previous request
        if (currentRequest) {
          currentRequest.abort();
        }

        // Create new request
        currentRequest = new XMLHttpRequest();
        const businessType = '<?= htmlspecialchars($business_type) ?>';
        const url = `<?= BASE_URL ?>search-suggestions?q=${encodeURIComponent(query)}&business_type=${encodeURIComponent(businessType)}`;

        currentRequest.open('GET', url, true);

        currentRequest.onload = function () {
          searchLoading.style.display = 'none';

          if (this.status === 200) {
            try {
              const suggestions = JSON.parse(this.responseText);
              displaySuggestions(suggestions);
            } catch (e) {
              console.error('Error parsing suggestions:', e);
            }
          }
        };

        currentRequest.onerror = function () {
          searchLoading.style.display = 'none';
        };

        currentRequest.send();
      }

      // Display suggestions
      function displaySuggestions(suggestions) {
        if (suggestions.length === 0) {
          searchSuggestions.innerHTML = '<div class="no-suggestions"><i class="fas fa-search"></i> No products found</div>';
          searchSuggestions.classList.add('active');
          return;
        }

        let html = '';
        suggestions.forEach(item => {
          const imagePath = item.item_image_path
            ? `<?= CLIENT_ASSET ?>/images/products/${item.item_image_path}`
            : '';

          html += `
        <div class="suggestion-item" onclick="selectSuggestion('${item.item_code}')">
          ${imagePath
              ? `<img src="${imagePath}" alt="${item.item_name}" class="suggestion-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
               <div class="suggestion-image-placeholder" style="display:none;"><i class="fas fa-image"></i></div>`
              : `<div class="suggestion-image-placeholder"><i class="fas fa-image"></i></div>`
            }
          <div class="suggestion-details">
            <div class="suggestion-name">${escapeHtml(item.item_name)}</div>
            <div class="suggestion-code">${escapeHtml(item.item_code)}</div>
            ${item.item_material ? `<div class="suggestion-material">${escapeHtml(item.item_material)}</div>` : ''}
          </div>
          <div class="suggestion-price">₱${item.price}</div>
        </div>
      `;
        });

        searchSuggestions.innerHTML = html;
        searchSuggestions.classList.add('active');
      }

      // Helper function to escape HTML
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      // Select suggestion and open modal
      window.selectSuggestion = function (itemCode) {
        searchSuggestions.classList.remove('active');
        window.location.href = `quotation-product-details?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&search=${encodeURIComponent(itemCode)}`;
      };

      // Input event listener with debounce
      searchInput.addEventListener('input', function (e) {
        clearTimeout(searchTimeout);
        const query = e.target.value.trim();

        if (query.length < 2) {
          searchSuggestions.classList.remove('active');
          searchLoading.style.display = 'none';
          return;
        }

        searchTimeout = setTimeout(() => {
          performSearch(query);
        }, 300); // 300ms debounce
      });

      // Focus event
      searchInput.addEventListener('focus', function () {
        if (this.value.trim().length >= 2) {
          performSearch(this.value.trim());
        }
      });

      // Click outside to close
      document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
          searchSuggestions.classList.remove('active');
        }
      });

      // Prevent form submission when selecting from suggestions
      searchSuggestions.addEventListener('mousedown', function (e) {
        e.preventDefault();
      });

      // Handle keyboard navigation (optional enhancement)
      let selectedIndex = -1;

      searchInput.addEventListener('keydown', function (e) {
        const items = searchSuggestions.querySelectorAll('.suggestion-item');

        if (e.key === 'ArrowDown') {
          e.preventDefault();
          selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
          updateSelection(items);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          selectedIndex = Math.max(selectedIndex - 1, -1);
          updateSelection(items);
        } else if (e.key === 'Enter') {
          if (selectedIndex >= 0 && items[selectedIndex]) {
            e.preventDefault();
            items[selectedIndex].click();
          }
        } else if (e.key === 'Escape') {
          searchSuggestions.classList.remove('active');
          selectedIndex = -1;
        }
      });

      function updateSelection(items) {
        items.forEach((item, index) => {
          if (index === selectedIndex) {
            item.style.background = '#f8f9fa';
            item.scrollIntoView({ block: 'nearest' });
          } else {
            item.style.background = '';
          }
        });
      }
    })();

    // Load variant in modal (refresh modal content)
    function loadVariant(itemCode) {
      window.location.href = `quotation-product-details?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>&search=${encodeURIComponent(itemCode)}`;
    }
  </script>
</body>

</html>