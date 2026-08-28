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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
    }

        .adm-th {
      padding: 8px 10px;
      font-size: 10px;
      font-weight: 700;
      color: var(--adm-ink);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 2px solid var(--adm-line);
    }

    body {
      font-family: 'Inter', sans-serif;
      color: var(--adm-ink);
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

<body style="background: var(--adm-bg);">
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
        style="background: var(--adm-surface); border:1px solid var(--adm-line); color: var(--adm-ink); padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; text-decoration: none;">
        <i class="fas fa-arrow-left"></i>
        Back
      </a>

      <!-- Computation Lock Toggle -->
      <button onclick="toggleComputationLock()"
        style="padding:12px 20px; background:var(--adm-ink); color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
        <i class="fas <?= $computation_locked ? 'fa-unlock' : 'fa-lock' ?>"></i>
        <?= $computation_locked ? 'Unlock Computation' : 'Lock Computation' ?>
      </button>

      <a href="export-computation?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>"
        class="btn"
        style="background: var(--adm-ink); color: white; padding: 12px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
        <i class="fas fa-file-pdf"></i>
        Export to PDF
      </a>

      <a href="export-quotation?client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>" class="btn"
        style="background: var(--adm-surface); border:1px solid var(--adm-line); color: var(--adm-ink); padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
        <i class="fas fa-file-excel"></i>
        Export Quotation to Excel
      </a>

      <!-- Tracker Mode Selector -->
      <div style="margin-left: auto;">
        <label style="font-size: 14px; color: #666; margin-right: 8px;">
          <i class="fas fa-list-ol"></i> Tracker Mode:
        </label>
        <select id="tracker-mode"
          style="padding: 10px 16px; border: 1px solid var(--adm-line); border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: white;">
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
    <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">

        <!-- Search -->
        <div style="position:relative; flex:1; min-width:240px; max-width:340px;">
          <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:13px; pointer-events:none;"></i>
          <input type="text" id="computation-search" placeholder="Search by area name..."
            style="width:100%; padding:11px 36px; border:1px solid var(--adm-line); border-radius:24px; font-size:14px; background:#f9fafb; transition:all 0.2s ease; outline:none;"
            onfocus="this.style.background='white'; this.style.borderColor='var(--adm-ink)'; this.style.boxShadow='0 0 0 3px rgba(11,11,11,0.08)';"
            onblur="this.style.background='#f9fafb'; this.style.borderColor='var(--adm-line)'; this.style.boxShadow='none';">
          <button type="button" id="clear-search-btn" title="Clear search"
            style="display:none; position:absolute; right:10px; top:50%; transform:translateY(-50%); background:#e5e7eb; color:#6b7280; border:none; width:20px; height:20px; border-radius:50%; font-size:11px; line-height:1; cursor:pointer; align-items:center; justify-content:center;">
            <i class="fas fa-times"></i>
          </button>
        </div>

        <!-- Area Filter -->
        <div style="position:relative;">
          <select id="area-filter"
            style="appearance:none; -webkit-appearance:none; padding:11px 34px 11px 16px; border:1px solid var(--adm-line); border-radius:24px; font-size:13px; font-weight:600; color:var(--adm-ink); cursor:pointer; background:#f9fafb; transition:all 0.2s ease; outline:none;"
            onfocus="this.style.borderColor='var(--adm-ink)'; this.style.boxShadow='0 0 0 3px rgba(11,11,11,0.08)';"
            onblur="this.style.borderColor='var(--adm-line)'; this.style.boxShadow='none';">
            <option value="">All Areas</option>
            <?php foreach ($areas as $area): ?>
              <option value="<?= htmlspecialchars($area) ?>"><?= htmlspecialchars($area) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-chevron-down" style="position:absolute; right:14px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:10px; pointer-events:none;"></i>
        </div>

        <!-- Sort Order Toggle -->
        <button type="button" id="sort-toggle-btn" data-order="asc"
          style="padding:11px 18px; border:1px solid var(--adm-line); border-radius:24px; font-size:13px; font-weight:600; color:var(--adm-ink); cursor:pointer; background:#f9fafb; transition:all 0.2s ease; display:inline-flex; align-items:center; gap:8px;"
          onmouseover="this.style.borderColor='var(--adm-ink)';" onmouseout="this.style.borderColor='var(--adm-line)';">
          <i class="fas fa-arrow-down-short-wide"></i>
          <span id="sort-toggle-label">Oldest First</span>
        </button>

        </div>
    </div>

    <?php if (empty($entriesArr) && empty($fixedEntriesArr)): ?>
      <div
        style="text-align:center; padding:60px 20px; background:white; border-radius:12px; border:1px solid var(--adm-line);">
        <i class="fas fa-clipboard-list" style="font-size:48px; color:#d1d5db; margin-bottom:16px; display:block;"></i>
        <p style="color:#6b7280; font-size:16px; font-weight:500;">No computations found for this client.</p>
      </div>
    <?php else: ?>

      <div id="areas-list">
      <?php foreach ($areas as $area):
        // Check if this area has any entries
        $areaCustomized = array_filter($entriesArr, fn($r) => $r['area'] === $area);
        $areaFixed = array_filter($fixedEntriesArr, fn($r) => $r['area'] === $area);
        if (empty($areaCustomized) && empty($areaFixed))
          continue;
        ?>

        <!-- ═══════════════════════════════════════ AREA CARD ═══════════════════════════════════════ -->
        <div class="area-card" data-area="<?= htmlspecialchars($area) ?>"
          style="background:var(--adm-surface); border-radius:16px; margin-bottom:24px; overflow:hidden; border:1px solid var(--adm-line);">

          <!-- Area Header -->
          <div
            style="background:var(--adm-ink); padding:14px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
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
                  style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.35); color:#ffffff; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600;">
                  <i class="fas fa-ruler"></i> <?= count($areaCustomized) ?> Customized
                </span>
              <?php endif; ?>
              <?php if (!empty($areaFixed)): ?>
                <span
                  style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:#e5e5e5; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600;">
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
      </div><!-- #areas-list -->

    <?php endif; ?>

    <!-- ── SUMMARY PANEL ── -->
      <?php include $includes['summary-pannel']; ?>

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