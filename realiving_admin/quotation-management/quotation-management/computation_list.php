<?php
//computation_list.php
include $includes ['mainbody'];
require_role(['admin1', 'superadmin', 'sales', 'designer', 'technical_designer', 'project_coordinator']);


$admin_id = $_SESSION['admin_id'];

// Pull client info from query string
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$client_name = isset($_GET['client_name']) ? urldecode($_GET['client_name']) : '';
$client_email = isset($_GET['email']) ? urldecode($_GET['email']) : '';
$client_address = isset($_GET['address']) ? urldecode($_GET['address']) : '';
$client_contact = isset($_GET['contact']) ? urldecode($_GET['contact']) : '';

// Now fetch project_name & business_type for this client
$project_name = '';
$business_type = '';
$project_scope = '';
$scope_of_work = '';


if ($client_id) {
  $house_state = '';
  $permit_required = '';
  $target_movein_date = '';

  $stmt = $conn->prepare("
  SELECT nameproject, business_type, project_scope, scope_of_work, tracker_mode,
         computation_locked, house_state, permit_required, target_movein_date,
         gender, client_class, client_type
  FROM user_info
  WHERE id = ?
");
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $project_name = $row['nameproject'];
    $business_type = $row['business_type'];
    $project_scope = $row['project_scope'];
    $scope_of_work = $row['scope_of_work'];
    $tracker_mode = $row['tracker_mode'] ?? 'non-sequential';
    $computation_locked = (int) $row['computation_locked'];
    $house_state = $row['house_state'] ?? '';
    $permit_required = $row['permit_required'] ?? '';
    $target_movein_date = $row['target_movein_date'] ?? '';
    $gender = $row['gender'] ?? '';
    $client_class = $row['client_class'] ?? '';
    $client_type = $row['client_type'] ?? '';
  }
}

// Display-friendly business type label
$business_type_label = $business_type === 'Non-Project' ? 'Individual' : $business_type;

// Check if Quotation stage is Done
$quotationDone = false;
if ($client_id) {
  $quotationStmt = $conn->prepare("
        SELECT status 
        FROM project_tracker 
        WHERE client_id = ? AND stage_name = 'Quotation'
    ");
  $quotationStmt->bind_param("i", $client_id);
  $quotationStmt->execute();
  $quotationResult = $quotationStmt->get_result()->fetch_assoc();
  if ($quotationResult && $quotationResult['status'] === 'Done') {
    $quotationDone = true;
  }
}

// fetch only entries for this client AND this admin
$q = $conn->prepare("
  SELECT
    e.id AS entry_id,
    e.item_image_path,
    e.color_image,
    e.color_image_path,
    e.color_label,
    e.dimension_msmt_id, e.dimension_label_id, e.jackup,
    e.width, e.height, e.length,
    e.width_label, e.height_label, e.length_label,
    d.item_width_linear, d.startup_width_linear,
    d.item_height_linear, d.startup_height_linear,
    d.item_length_linear, d.startup_length_linear,
    d.item_width_sqm,      d.startup_width_sqm,
    d.item_height_sqm,     d.startup_height_sqm,
    d.item_length_sqm,     d.startup_length_sqm,
    e.unit_price, e.labor_cost, e.mark_up,
    e.quantity, e.area,
    e.unit_type,
    e.unit_mode,
    e.computed_unit,
    e.computed_tot_mats,
    e.computed_tot_labor,
    e.computed_tot_amount,
    e.entry_item_id,
    COALESCE(e.item_name, i.item_name) AS item_name
  FROM quotation_entries AS e
  LEFT JOIN items      AS i ON e.entry_item_id     = i.item_id
  LEFT JOIN dimension_measurement AS d ON e.dimension_msmt_id = d.dimension_msmt_id
  WHERE e.client_id = ?
    AND e.admin_id  = ?
  ORDER BY e.created_at ASC
");
$q->bind_param("ii", $client_id, $admin_id);
// 1a) Execute and pull all entries into an array
$q->execute();
$result = $q->get_result();
$entriesArr = [];
while ($r = $result->fetch_assoc()) {
  $entriesArr[] = $r;
}

// Fetch FIXED SIZE quotations for this client and admin
$qFixed = $conn->prepare("
  SELECT
    qfs.id,
    qfs.item_id,
    qfs.item_code,
    qfs.item_name,
    qfs.item_image_path,
    qfs.color_image_path,
    qfs.color_label,
    qfs.fixed_size_id,
    qfs.selected_color_type,
    qfs.selected_color_id,
    qfs.base_price,
    qfs.quantity,
    qfs.unit_type,
    qfs.area,
    ifs.size_label,
    ifs.size_width,
    ifs.size_height,
    ifs.size_length,
    ifs.measurement_unit,
    dl.dimension_label_name,
    dl.item_width_label_linear,
    dl.item_height_label_linear,
    dl.item_length_label_linear
  FROM quotation_fixed_sizes AS qfs
  LEFT JOIN item_fixed_sizes AS ifs ON qfs.fixed_size_id = ifs.fixed_size_id
  LEFT JOIN dimension_label AS dl ON ifs.dimension_label_fk = dl.dimension_label_id
  WHERE qfs.client_id = ?
    AND qfs.admin_id  = ?
  ORDER BY qfs.created_at ASC
");
$qFixed->bind_param("ii", $client_id, $admin_id);
$qFixed->execute();
$resultFixed = $qFixed->get_result();
$fixedEntriesArr = [];
while ($r = $resultFixed->fetch_assoc()) {
  $fixedEntriesArr[] = $r;
}

// 1b) Build a list of distinct areas for grouping (BOTH customized and fixed)
$areasStmt = $conn->prepare("
  SELECT area, MIN(created_at) AS first_seen
  FROM (
    SELECT area, created_at FROM quotation_entries
     WHERE client_id = ? AND admin_id = ?
    UNION ALL
    SELECT area, created_at FROM quotation_fixed_sizes
     WHERE client_id = ? AND admin_id = ?
  ) AS combined
  GROUP BY area
  ORDER BY first_seen ASC
");
$areasStmt->bind_param("iiii", $client_id, $admin_id, $client_id, $admin_id);
$areasStmt->execute();
$areasRes = $areasStmt->get_result();

$areas = [];
while ($a = $areasRes->fetch_assoc()) {
  $areas[] = $a['area'];
}


$addonsStmt = $conn->prepare("
  SELECT
    a.id               AS addon_entry_id,
    a.quantity,
    a.price,
    a.labor_cost,
    a.note,
    a.addon_jackup,
    a.user_dim_value_1,
    a.user_dim_value_2,
    a.user_dim_value_3,
    a.computed_area,
    p.addon_name,
    p.addon_image_path,
    p.has_dimension,
    p.dimension_type,
    p.dimension_label_1,
    p.dimension_label_2,
    p.dimension_label_3,
    p.dimension_value_1,
    p.dimension_value_2,
    p.dimension_value_3,
    p.labor_cost_jack_up AS default_jackup
  FROM quotation_entry_addons AS a
  JOIN product_addons        AS p ON a.addon_id = p.id
  WHERE a.quotation_entry_id = ?
");

// Prepared statement for FIXED SIZE addons
$fixedAddonsStmt = $conn->prepare("
  SELECT
    a.id,
    a.addon_id,
    a.addon_category,
    a.linked_dimension_addon_id,
    a.quantity,
    a.price,
    a.labor_cost,
    a.note,
    a.addon_jackup,
    a.user_dim_value_1,
    a.user_dim_value_2,
    a.user_dim_value_3,
    a.computed_area,
    p.addon_name,
    p.addon_image_path,
    p.has_dimension,
    p.dimension_type,
    p.dimension_label_1,
    p.dimension_label_2,
    p.dimension_label_3,
    p.dimension_value_1,
    p.dimension_value_2,
    p.dimension_value_3,
    p.multiply_value,
    p.required_unit     AS eff_required_unit,
    p.max_quantity      AS eff_max_quantity,
    p.is_stable_mat     AS eff_is_stable_mat,
    p.min_required_unit AS eff_min_required_unit,
    p.labor_cost_jack_up AS default_jackup
  FROM quotation_fixed_size_addons AS a
  JOIN product_addons AS p ON a.addon_id = p.id
  WHERE a.quotation_fixed_size_id = ?
");

// ── Compute Grand Totals (Customized + Fixed) ──
$grandMats = 0;
$grandLabor = 0;
$grandAddons = 0;
$grandFixed = 0;

// Calculate customized totals
foreach ($entriesArr as $row) {
  $grandMats += $row['computed_tot_mats'];
  $grandLabor += $row['computed_tot_labor'];

  // pull addon total for this entry
  $addonsStmt->bind_param("i", $row['entry_id']);
  $addonsStmt->execute();
  $res = $addonsStmt->get_result();
  $sub = 0;
  while ($a = $res->fetch_assoc()) {
    $sub += $a['quantity'] * ($a['price'] + ($a['labor_cost'] ?? 0));
  }
  $grandAddons += $sub;
}

// Calculate FIXED SIZE totals
foreach ($fixedEntriesArr as $row) {
  $grandFixed += $row['base_price'] * $row['quantity'];

  // pull addon total for this fixed entry
  $fixedAddonsStmt->bind_param("i", $row['id']);
  $fixedAddonsStmt->execute();
  $res = $fixedAddonsStmt->get_result();
  $sub = 0;
  while ($a = $res->fetch_assoc()) {
    $sub += $a['quantity'] * ($a['price'] + ($a['labor_cost'] ?? 0));
  }
  $grandAddons += $sub;
}



// fetch stored discount %
$discStmt = $conn->prepare("
  SELECT discount
    FROM user_info
   WHERE id = ?
");
$discStmt->bind_param("i", $client_id);
$discStmt->execute();
$discRes = $discStmt->get_result()->fetch_assoc();
$storedDiscount = $discRes['discount'] ?? 0;

// total before discount (Customized + Fixed)
$rawTotal = $grandMats + $grandLabor + $grandAddons + $grandFixed;
// apply stored discount %
$afterDiscount = $rawTotal * (1 - ($storedDiscount / 100));

// If business_type is "Project", add 10% General Requirements and 12% VAT
$generalReq = 0;
$vat = 0;
$finalTotal = $afterDiscount;

if ($business_type === 'Project') {
  $generalReq = $afterDiscount * 0.10;  // 10% General Requirements
  $subtotalWithGR = $afterDiscount + $generalReq;
  $vat = $subtotalWithGR * 0.12;        // 12% VAT
  $finalTotal = $subtotalWithGR + $vat;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Computation List</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .client-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      position: relative;
      overflow: hidden;
    }

    .client-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.03)"/><circle cx="10" cy="60" r="0.5" fill="rgba(255,255,255,0.03)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
      opacity: 0.3;
    }

    .info-card {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.2);
      transition: all 0.3s ease;
    }

    .info-card:hover {
      background: rgba(255, 255, 255, 0.25);
      transform: translateY(-2px);
    }

    .info-icon {
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(5px);
      transition: all 0.3s ease;
    }

    .info-card:hover .info-icon {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }

    .client-badge {
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.3);
      animation: fadeInDown 0.6s ease-out;
    }

    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .pulse-icon {
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0% {
        transform: scale(1);
      }

      50% {
        transform: scale(1.05);
      }

      100% {
        transform: scale(1);
      }
    }

    /* Optional hover for swatches */
    img[data-full] {
      transition: transform 0.2s ease;
    }

    img[data-full]:hover {
      transform: scale(1.05);
    }

    table th,
    table td {
      font-size: 12px;
    }

    .edit-input {
      font-size: 12px;
      padding: 2px 4px;
    }

    .edit-input {
      font-size: 11px;
      padding: 2px 4px;
      width: 56px;
    }

    table th {
      font-size: 11px;
      padding: 6px;
    }
  </style>
</head>

<body>
  <!-- Client Information Header -->
  <?php include $includes ['client-header']; ?>

  <div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Quotation Lock Warning -->
    <?php if ($quotationDone): ?>
      <div
        style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <i class="fas fa-lock" style="color: #f59e0b; font-size: 20px;"></i>
          <div>
            <strong style="color: #92400e;">Quotation Locked</strong>
            <p style="color: #92400e; margin-top: 4px; font-size: 14px;">
              The Quotation stage is marked as "Done". All computations are now locked and cannot be edited.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
      <a href="quotation-items?id=<?= $client_id ?>&name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
        class="btn"
        style="background: #6b7280; color: white; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; text-decoration: none;">
        <i class="fas fa-arrow-left"></i>
        Back
      </a>

      <!-- Computation Lock Toggle -->
      <button onclick="toggleComputationLock()"
        style="padding:12px 20px; background:<?= $computation_locked ? '#ef4444' : '#10b981' ?>; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
        <i class="fas <?= $computation_locked ? 'fa-unlock' : 'fa-lock' ?>"></i>
        <?= $computation_locked ? 'Unlock Computation' : 'Lock Computation' ?>
      </button>

      <a href="export-computation?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>"
        class="btn"
        style="background: #ef4444; color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
        <i class="fas fa-file-pdf"></i>
        Export to PDF
      </a>

      <a href="export-quotation?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>" class="btn"
        style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <i class="fas fa-file-excel"></i>
        Export Quotation to Excel
      </a>

      <!-- Tracker Mode Selector -->
      <div style="margin-left: auto;">
        <label style="font-size: 14px; color: #666; margin-right: 8px;">
          <i class="fas fa-list-ol"></i> Tracker Mode:
        </label>
        <select id="tracker-mode"
          style="padding: 10px 16px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: white;">
          <option value="non-sequential" <?= ($tracker_mode ?? 'non-sequential') === 'non-sequential' ? 'selected' : '' ?>>
            Non-Sequential
          </option>
          <option value="sequential" <?= ($tracker_mode ?? 'non-sequential') === 'sequential' ? 'selected' : '' ?>>
            Sequential
          </option>
        </select>
      </div>
    </div>

    <!-- Search & Filter Toolbar -->
    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; align-items:center;">
      <div style="position:relative; flex:1; min-width:220px; max-width:320px;">
        <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:13px;"></i>
        <input type="text" id="computation-search" placeholder="Search item name..."
          style="width:100%; padding:10px 12px 10px 32px; border:2px solid #e9ecef; border-radius:8px; font-size:14px;">
      </div>
      <select id="area-filter"
        style="padding:10px 16px; border:2px solid #e9ecef; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; background:white;">
        <option value="">All Areas</option>
        <?php foreach ($areas as $area): ?>
          <option value="<?= htmlspecialchars($area) ?>"><?= htmlspecialchars($area) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (empty($entriesArr) && empty($fixedEntriesArr)): ?>
      <div
        style="text-align:center; padding:60px 20px; background:white; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.06);">
        <i class="fas fa-clipboard-list" style="font-size:48px; color:#d1d5db; margin-bottom:16px; display:block;"></i>
        <p style="color:#6b7280; font-size:16px; font-weight:500;">No computations found for this client.</p>
      </div>
    <?php else: ?>

      <?php foreach ($areas as $area):
        // Check if this area has any entries
        $areaCustomized = array_filter($entriesArr, fn($r) => $r['area'] === $area);
        $areaFixed = array_filter($fixedEntriesArr, fn($r) => $r['area'] === $area);
        if (empty($areaCustomized) && empty($areaFixed))
          continue;
        ?>

        <!-- ═══════════════════════════════════════ AREA CARD ═══════════════════════════════════════ -->
        <div class="area-card" data-area="<?= htmlspecialchars($area) ?>"
          style="background:white; border-radius:16px; box-shadow:0 2px 12px rgba(0,0,0,0.08); margin-bottom:24px; overflow:hidden; border:1px solid #e5e7eb;">

          <!-- Area Header -->
          <div
            style="background:linear-gradient(135deg,#3b1f0f,#6b3a26); padding:14px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <div
                style="background:rgba(255,255,255,0.15); width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-map-marker-alt" style="color:white; font-size:16px;"></i>
              </div>
              <div>
                <div
                  style="color:rgba(255,255,255,0.7); font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.8px;">
                  Area</div>
                <div style="color:white; font-size:17px; font-weight:700;"><?= htmlspecialchars($area) ?></div>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <?php if (!empty($areaCustomized)): ?>
                <span
                  style="background:rgba(59,130,246,0.25); border:1px solid rgba(59,130,246,0.4); color:#bfdbfe; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600;">
                  <i class="fas fa-ruler"></i> <?= count($areaCustomized) ?> Customized
                </span>
              <?php endif; ?>
              <?php if (!empty($areaFixed)): ?>
                <span
                  style="background:rgba(139,92,246,0.25); border:1px solid rgba(139,92,246,0.4); color:#ddd6fe; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600;">
                  <i class="fas fa-ruler-combined"></i> <?= count($areaFixed) ?> Fixed Size
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div style="padding:16px 20px;">

            <!-- ── CUSTOMIZED ENTRIES ── -->
            <?php include $includes['customized-entries']; ?>

            <!-- ── FIXED SIZE ENTRIES ── -->
            <?php include $includes['fixed-entries']; ?>

          </div><!-- end area card body padding -->
        </div><!-- end area card -->

      <?php endforeach; // end areas loop 
      ?>

      <!-- ── SUMMARY PANEL ── -->
      <?php include $includes['summary-pannel']; ?>

    <?php endif; ?>

  </div><!-- closes max-w-7xl div -->

  <!-- Modals -->
  <?php include $includes['modals']; ?>

  <!-- APP config — must come first, inline pa rin dahil may PHP values -->
  <script>
    const APP = {
      clientId: <?= intval($client_id) ?>,
      businessType: <?= json_encode($business_type) ?>,
      baseUrl: <?= json_encode(BASE_URL) ?>
    };
  </script>
  <!-- JS files — load order matters -->
  <script src="<?= BASE_URL ?>computation-core"></script>
  <script src="<?= BASE_URL ?>computation-entries?v=1.0.2"></script>
  <script src="<?= BASE_URL ?>computation-addons"></script>
  <script src="<?= BASE_URL ?>computation-fixed"></script>
  <script src="<?= BASE_URL ?>computation-ui"></script>

</body>

</html>