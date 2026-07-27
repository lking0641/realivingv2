<?php
// partials/_customized_entries.php
// Requires: $areaCustomized, $quotationDone, $addonsStmt, $conn
// Called inside foreach ($areas as $area) loop
?>

<?php if (!empty($areaCustomized)): ?>
  <div style="margin-bottom:<?= !empty($areaFixed) ? '20px' : '0' ?>;">
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
      <div style="width:4px; height:20px; background:#3b82f6; border-radius:2px;"></div>
      <span style="font-size:12px; font-weight:700; color:#3b82f6; text-transform:uppercase; letter-spacing:0.5px;">
        <i class="fas fa-ruler"></i> Customized Items
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; min-width:900px;">
        <thead>
          <tr style="background:#eff6ff;">
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #bfdbfe;">Image</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #bfdbfe;">Item</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;" colspan="3">Dimensions</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Unit</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Qty</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Type</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Mats</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Labor</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Tot Mats</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Tot Labor</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Jack-up%</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Dim Adj%</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Price/Item</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:right; border-bottom:2px solid #bfdbfe;">Total</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #bfdbfe;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($areaCustomized as $row): ?>

            <?php
            $isLinear = ($row['unit_mode'] === 'linear');
            $isSqm    = ($row['unit_mode'] === 'sqm');

            if ($isSqm) {
              $flagW    = $row['item_width_sqm'];
              $startupW = $row['startup_width_sqm'];
              $flagH    = $row['item_height_sqm'];
              $startupH = $row['startup_height_sqm'];
              $flagL    = $row['item_length_sqm'];
              $startupL = $row['startup_length_sqm'];
            } else {
              $flagW    = $row['item_width_linear'];
              $startupW = $row['startup_width_linear'];
              $flagH    = $row['item_height_linear'];
              $startupH = $row['startup_height_linear'];
              $flagL    = $row['item_length_linear'];
              $startupL = $row['startup_length_linear'];
            }

            $rawW = $row['width'];
            $rawH = $row['height'];
            $rawL = $row['length'];

            if ($isLinear) {
              $computedUnit = 0;
              if ((int)$row['item_width_linear'] === 0)       $computedUnit = $rawW / 1000;
              elseif ((int)$row['item_height_linear'] === 0)  $computedUnit = $rawH / 1000;
              else                                            $computedUnit = $rawL / 1000;
            } elseif ($isSqm) {
              $vals = [];
              if ((int)$row['item_width_sqm']  === 0) $vals[] = $rawW / 1000;
              if ((int)$row['item_height_sqm'] === 0) $vals[] = $rawH / 1000;
              if ((int)$row['item_length_sqm'] === 0) $vals[] = $rawL / 1000;
              $computedUnit = count($vals) === 2 ? $vals[0] * $vals[1] : 1;
            } else {
              $computedUnit = 1;
            }

            $quantity  = max(1, (int)$row['quantity']);
            $baseMats  = $computedUnit * $row['unit_price'] * $quantity;
            $baseLabor = $computedUnit * $row['labor_cost'] * $quantity;
            $jackupPct = $row['jackup'] / 100;
            $jackupAmt = $baseMats * $jackupPct;

            $computed_tot_mats   = $baseMats + $jackupAmt;
            $computed_tot_labor  = $baseLabor;
            $computed_tot_amount = $computed_tot_mats + $computed_tot_labor;
            ?>

            <tr
              data-entry-id="<?= $row['entry_id'] ?>"
              data-unit-mode="<?= htmlspecialchars($row['unit_mode']) ?>"
              data-item-width-linear="<?= (int)$row['item_width_linear'] ?>"
              data-startup-width-linear="<?= (int)$row['startup_width_linear'] ?>"
              data-item-height-linear="<?= (int)$row['item_height_linear'] ?>"
              data-startup-height-linear="<?= (int)$row['startup_height_linear'] ?>"
              data-item-length-linear="<?= (int)$row['item_length_linear'] ?>"
              data-startup-length-linear="<?= (int)$row['startup_length_linear'] ?>"
              data-item-width-sqm="<?= (int)$row['item_width_sqm'] ?>"
              data-startup-width-sqm="<?= (int)$row['startup_width_sqm'] ?>"
              data-item-height-sqm="<?= (int)$row['item_height_sqm'] ?>"
              data-startup-height-sqm="<?= (int)$row['startup_height_sqm'] ?>"
              data-item-length-sqm="<?= (int)$row['item_length_sqm'] ?>"
              data-startup-length-sqm="<?= (int)$row['startup_length_sqm'] ?>">

              <!-- IMAGE -->
              <td class="px-4 py-2">
                <?php
                $displayImage = '';
                $imageFolder  = '';
                if (!empty($row['color_image_path'])) {
                  $displayImage = $row['color_image_path'];
                  $imageFolder  = file_exists(PAGES_PATH . 'images/product_colors/' . $displayImage)
                    ? CLIENT_ASSET . '/images/product_colors/'
                    : CLIENT_ASSET . '/images/products/';
                } elseif (!empty($row['item_image_path'])) {
                  $displayImage = $row['item_image_path'];
                  $imageFolder  = CLIENT_ASSET . '/images/products/';
                }
                ?>
                <?php if ($displayImage): ?>
                  <div class="flex flex-col items-center">
                    <img src="<?= $imageFolder . htmlspecialchars($displayImage) ?>"
                      class="w-12 h-12 object-cover rounded"
                      alt="<?= htmlspecialchars($row['color_label']) ?>">
                    <span class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($row['color_label']) ?></span>
                  </div>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>

              <!-- ITEM NAME -->
              <td class="px-4 py-2" style="min-width:150px; max-width:200px;">
                <div
                  contenteditable="<?= $quotationDone ? 'false' : 'true' ?>"
                  data-entry-id="<?= $row['entry_id'] ?>"
                  class="item-name-edit"
                  style="font-size:13px; font-weight:500; color:#1f2937; outline:none; border-bottom:1px dashed transparent; transition:border-color 0.2s; min-width:120px; max-width:190px; word-break:break-word; white-space:normal; overflow-wrap:break-word; display:block; padding:2px 4px; border-radius:4px; <?= $quotationDone ? 'cursor:not-allowed; color:#9ca3af;' : 'cursor:text;' ?>"
                  onmouseover="if(this.contentEditable==='true') this.style.borderBottomColor='#9ca3af';"
                  onmouseout="if(document.activeElement!==this) this.style.borderBottomColor='transparent';"
                  onfocus="this.style.borderBottomColor='#6366f1'; this.style.background='#f5f3ff';"
                  onblur="this.style.borderBottomColor='transparent'; this.style.background=''; saveItemName(this);">
                  <?= htmlspecialchars($row['item_name'] ?? '') ?>
                </div>
              </td>

              <!-- WIDTH -->
              <td class="px-2 py-2">
                <details class="mb-2">
                  <summary class="cursor-pointer text-sm font-medium text-gray-700"
                    style="max-width:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:list-item;">
                    <?= htmlspecialchars($row['width_label']) ?>
                  </summary>
                  <div class="mt-1 text-xs text-gray-600 space-y-1">
                    <div>Startup: <?= htmlspecialchars($startupW) ?></div>
                    <div>Standard: <?= htmlspecialchars($flagW) ?></div>
                  </div>
                </details>
                <input type="number" step="0.01"
                  class="edit-input w-[60px] text-center border rounded px-1 py-1"
                  data-field="width"
                  value="<?= htmlspecialchars($rawW) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- HEIGHT -->
              <td class="px-2 py-2">
                <details class="mb-2">
                  <summary class="cursor-pointer text-sm font-medium text-gray-700"
                    style="max-width:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:list-item;">
                    <?= htmlspecialchars($row['height_label']) ?>
                  </summary>
                  <div class="mt-1 text-xs text-gray-600 space-y-1">
                    <div>Startup: <?= htmlspecialchars($startupH) ?></div>
                    <div>Standard: <?= htmlspecialchars($flagH) ?></div>
                  </div>
                </details>
                <input type="number" step="0.01"
                  class="edit-input w-[60px] text-center border rounded px-1 py-1"
                  data-field="height"
                  value="<?= htmlspecialchars($rawH) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- LENGTH -->
              <td class="px-2 py-2">
                <details class="mb-2">
                  <summary class="cursor-pointer text-sm font-medium text-gray-700"
                    style="max-width:70px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:list-item;">
                    <?= htmlspecialchars($row['length_label']) ?>
                  </summary>
                  <div class="mt-1 text-xs text-gray-600 space-y-1">
                    <div>Startup: <?= htmlspecialchars($startupL) ?></div>
                    <div>Standard: <?= htmlspecialchars($flagL) ?></div>
                  </div>
                </details>
                <input type="number" step="0.01"
                  class="edit-input w-[60px] text-center border rounded px-1 py-1"
                  data-field="length"
                  value="<?= htmlspecialchars($rawL) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- COMPUTED UNIT -->
              <td class="unit-cell">
                <?= rtrim(rtrim(number_format($computedUnit, 3, '.', ''), '0'), '.') ?>
                <span class="text-xs text-gray-500 ml-1">(<?= htmlspecialchars($row['unit_mode']) ?>)</span>
              </td>

              <!-- QUANTITY -->
              <td class="px-4 py-2">
                <input type="number" step="1"
                  class="edit-input w-[35px] text-center border rounded px-1 py-1"
                  data-field="quantity"
                  value="<?= htmlspecialchars($row['quantity']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- UNIT TYPE -->
              <td class="px-4 py-2 text-center"><?= htmlspecialchars($row['unit_type']) ?></td>

              <!-- UNIT PRICE (MATS) -->
              <td class="px-2 py-2">
                <input type="number" step="0.01"
                  class="edit-input text-center border rounded px-1 py-1"
                  data-field="unit_price"
                  value="<?= htmlspecialchars($row['unit_price']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- LABOR COST -->
              <td class="px-2 py-2">
                <input type="number" step="0.01"
                  class="edit-input text-center border rounded px-1 py-1"
                  data-field="labor_cost"
                  value="<?= htmlspecialchars($row['labor_cost']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- TOTAL MATERIALS -->
              <td class="total-materials"><?= number_format($computed_tot_mats, 2) ?></td>

              <!-- TOTAL LABOR -->
              <td class="total-labor"><?= number_format($computed_tot_labor, 2) ?></td>

              <!-- JACK-UP -->
              <td class="px-2 py-2">
                <input type="number" step="0.01"
                  class="edit-input text-center border rounded px-1 py-1"
                  data-field="jackup"
                  value="<?= htmlspecialchars($row['jackup']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- MARK-UP -->
              <td class="px-2 py-2">
                <input type="number" step="0.01"
                  class="edit-input text-center border rounded px-1 py-1"
                  data-field="mark_up"
                  value="<?= htmlspecialchars($row['mark_up']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- PRICE PER ITEM -->
              <td class="price-per-item" style="text-align:center; font-size:12px; color:#374151;">
                <?php
                $qty1         = max(1, (int)$row['quantity']);
                $pricePerItem = $computed_tot_amount / $qty1;
                ?>
                <?= number_format($pricePerItem, 2) ?>
              </td>

              <!-- TOTAL AMOUNT -->
              <td class="total-amount"><?= number_format($computed_tot_amount, 2) ?></td>

              <!-- ACTIONS -->
              <td class="px-2 py-2 text-center">
                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                  <a href="edit-quotation-entry?entry_id=<?= $row['entry_id'] ?>&client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
                    style="display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#dbeafe; color:#1e40af; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; <?= $quotationDone ? 'opacity:0.5; pointer-events:none;' : '' ?>">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                  <button
                    class="delete-entry btn btn-sm text-red-600 hover:text-red-800"
                    data-entry-id="<?= $row['entry_id'] ?>"
                    style="padding:4px 10px; font-size:11px; font-weight:700;"
                    <?= $quotationDone ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                    <i class="fas fa-trash"></i> Delete
                  </button>
                </div>
              </td>
            </tr>

            <!-- ADDONS ROW -->
            <?php
            $addonsStmt = $conn->prepare("
              SELECT a.id, a.quotation_entry_id, a.addon_id, a.quantity, a.price, a.labor_cost, a.note,
                     a.addon_jackup, a.user_dim_value_1, a.user_dim_value_2, a.user_dim_value_3, a.computed_area,
                     a.linked_dimension_addon_id,
                     p.addon_name, p.addon_image_path,
                     p.has_dimension, p.dimension_type,
                     p.dimension_label_1, p.dimension_label_2, p.dimension_label_3,
                     p.dimension_value_1, p.dimension_value_2, p.dimension_value_3,
                     p.multiply_value,
                     p.required_unit     AS eff_required_unit,
                     p.max_quantity      AS eff_max_quantity,
                     p.is_stable_mat     AS eff_is_stable_mat,
                     p.min_required_unit AS eff_min_required_unit,
                     p.labor_cost_jack_up AS default_jackup
              FROM quotation_entry_addons a
              JOIN product_addons p ON a.addon_id = p.id
              WHERE a.quotation_entry_id = ?
            ");
            $addonsStmt->bind_param("i", $row['entry_id']);
            $addonsStmt->execute();
            $addons = $addonsStmt->get_result();
            ?>

            <tr class="bg-gray-50">
              <td colspan="17" class="px-4 py-2">
                <?php if ($addons->num_rows): ?>
                  <table class="table-auto w-full text-xs border border-gray-300">
                    <thead class="bg-gray-100 text-gray-600">
                      <tr>
                        <th class="px-2 py-1 text-left">Image</th>
                        <th class="px-2 py-1 text-left">Accessory</th>
                        <th class="px-2 py-1 text-center">Dimensions</th>
                        <th class="px-2 py-1 text-center">Computed Unit</th>
                        <th class="px-2 py-1 text-center">Qty</th>
                        <th class="px-2 py-1 text-center">Price</th>
                        <th class="px-2 py-1 text-center">Labor Cost</th>
                        <th class="px-2 py-1 text-left">Note</th>
                        <th class="px-2 py-1 text-center">Jack-up%</th>
                        <th class="px-2 py-1 text-center">Tot Mats</th>
                        <th class="px-2 py-1 text-center">Tot Labor</th>
                        <th class="px-2 py-1 text-center">Price/Item</th>
                        <th class="px-2 py-1 text-right">Total</th>
                        <th class="px-2 py-1 text-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
  <?php
  // Sort addons: dimension addons first, then linked non-dim addons right after their parent
  $allAddons = $addons->fetch_all(MYSQLI_ASSOC);
  $sorted = [];
  $linked = [];

  foreach ($allAddons as $a) {
    if (!empty($a['linked_dimension_addon_id'])) {
      $linked[(int)$a['linked_dimension_addon_id']][] = $a;
    }
  }
  foreach ($allAddons as $a) {
    if (empty($a['linked_dimension_addon_id'])) {
      $sorted[] = $a;
      // Append any addons linked to this one right after, keyed by row id
      if (!empty($linked[(int)$a['id']])) {
        foreach ($linked[(int)$a['id']] as $child) {
          $sorted[] = $child;
        }
      }
    }
  }
  foreach ($sorted as $addon):
    $addonMeta = [
                          'required_unit'     => $addon['eff_required_unit']    ?? 0,
                          'max_quantity'      => $addon['eff_max_quantity']     ?? 0,
                          'is_stable_mat'     => $addon['eff_is_stable_mat']    ?? 0,
                          'min_required_unit' => $addon['eff_min_required_unit'] ?? 0,
                          'multiply_value'    => $addon['multiply_value']       ?? 0,
                        ];
                        $isAutoQty    = (!$addon['has_dimension'] && !empty($addon['linked_dimension_addon_id']));
                        $isDimAutoQty = ($addon['has_dimension'] && ($addonMeta['required_unit'] > 0) && ($addonMeta['max_quantity'] > 0));
                        $isLocked     = $isAutoQty || $isDimAutoQty;

                        $aComputedArea = floatval($addon['computed_area'] ?? 0);
                        $aEffUnit      = $aComputedArea > 0 ? $aComputedArea : 1;
                        $aJackAmt      = floatval($addon['price']) * (floatval($addon['addon_jackup'] ?? 0) / 100);
                        $aTotMats      = ($addon['price'] * $aEffUnit * $addon['quantity']) + ($aJackAmt * $addon['quantity']);
                        $aTotLabor     = floatval($addon['labor_cost'] ?? 0) * $aEffUnit * $addon['quantity'];
                        $aTotal        = $aTotMats + $aTotLabor;
                        $aPricePerItem = $addon['quantity'] > 0 ? $aTotal / $addon['quantity'] : $aTotal;
                      ?>
                        <tr data-addon-id="<?= $addon['id'] ?>"
                          data-has-dimension="<?= (int)($addon['has_dimension'] ?? 0) ?>"
                          data-linked-dim-id="<?= intval($addon['linked_dimension_addon_id'] ?? 0) ?>"
                          data-dim-type="<?= htmlspecialchars($addon['dimension_type'] ?? '') ?>"
                          data-dim-standard-1="<?= floatval($addon['dimension_value_1'] ?? 0) ?>"
                          data-dim-standard-2="<?= floatval($addon['dimension_value_2'] ?? 0) ?>"
                          data-dim-standard-3="<?= floatval($addon['dimension_value_3'] ?? 0) ?>"
                          data-required-unit="<?= floatval($addonMeta['required_unit']) ?>"
                          data-max-quantity="<?= floatval($addonMeta['max_quantity']) ?>"
                          data-is-stable-mat="<?= (int)$addonMeta['is_stable_mat'] ?>"
                          data-min-required-unit="<?= floatval($addonMeta['min_required_unit']) ?>"
                          data-multiply-value="<?= floatval($addonMeta['multiply_value']) ?>">

                          <td class="px-2 py-1">
                            <?php if (!empty($addon['addon_image_path'])): ?>
                              <img src="<?= CLIENT_ASSET ?>/images/product_addons/<?= htmlspecialchars($addon['addon_image_path']) ?>"
                                class="w-8 h-8 object-cover rounded"
                                alt="<?= htmlspecialchars($addon['addon_name']) ?>">
                            <?php endif; ?>
                          </td>

                          <td class="px-2 py-1">
                            <?= htmlspecialchars($addon['addon_name']) ?>
                            <?php if (!$addon['has_dimension']): ?>
                              <span class="link-addon-icon"
                                data-addon-id="<?= $addon['id'] ?>"
                                data-linked-id="<?= intval($addon['linked_dimension_addon_id'] ?? 0) ?>"
                                title="Link to a dimension accessory"
                                style="cursor:pointer; margin-left:6px; color:<?= $addon['linked_dimension_addon_id'] ? '#10b981' : '#9ca3af' ?>; font-size:13px;">🔗</span>
                              <select class="link-addon-select"
                                data-addon-id="<?= $addon['id'] ?>"
                                style="display:none; font-size:11px; padding:2px 4px; border:1px solid #d1d5db; border-radius:4px; margin-top:4px;">
                                <option value="">— Unlink —</option>
                              </select>
                            <?php endif; ?>
                          </td>

                          <!-- DIMENSION INPUTS -->
                          <td class="px-2 py-1">
                            <?php if ($addon['has_dimension']): ?>
                              <div style="display:flex; flex-direction:column; gap:3px; min-width:120px;">
                                <?php
                                $addonDimLabels    = [$addon['dimension_label_1'], $addon['dimension_label_2'], $addon['dimension_label_3']];
                                $addonDimStandards = [$addon['dimension_value_1'],  $addon['dimension_value_2'],  $addon['dimension_value_3']];
                                $addonUserVals     = [$addon['user_dim_value_1'],   $addon['user_dim_value_2'],   $addon['user_dim_value_3']];
                                $addonFields       = ['user_dim_value_1', 'user_dim_value_2', 'user_dim_value_3'];
                                foreach ([0, 1, 2] as $di):
                                  if (!$addonDimLabels[$di]) continue;
                                ?>
                                  <div style="display:flex; align-items:center; gap:4px;">
                                    <span style="font-size:10px; color:#6b7280; min-width:30px;"><?= htmlspecialchars($addonDimLabels[$di]) ?>:</span>
                                    <input type="number" step="0.01"
                                      class="addon-input border text-center py-0.5 text-xs"
                                      style="width:55px;"
                                      data-field="<?= $addonFields[$di] ?>"
                                      data-dim-index="<?= $di ?>"
                                      data-standard="<?= floatval($addonDimStandards[$di]) ?>"
                                      value="<?= floatval($addonUserVals[$di]) ?>"
                                      <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                                    <span style="font-size:9px; color:#9ca3af;">(std: <?= $addonDimStandards[$di] ?>)</span>
                                  </div>
                                <?php endforeach; ?>
                                <div style="font-size:9px; color:#6b7280; margin-top:2px;">Type: <?= htmlspecialchars($addon['dimension_type']) ?></div>
                              </div>
                            <?php else: ?>
                              <span style="color:#d1d5db; font-size:11px;">—</span>
                            <?php endif; ?>
                          </td>

                          <!-- COMPUTED UNIT -->
                          <td class="px-2 py-1 text-center addon-computed-unit" style="font-size:11px; color:#374151;">
                            <?= ($addon['has_dimension'] && $addon['computed_area']) ? number_format($addon['computed_area'], 3) : '—' ?>
                          </td>

                          <!-- QUANTITY -->
                          <td class="px-2 py-1 text-center">
                            <input type="number" min="1"
                              class="addon-input border text-center w-12 py-0.5 text-xs"
                              data-field="quantity"
                              value="<?= $addon['quantity'] ?>"
                              <?php if ($quotationDone || $isLocked): ?>
                                readonly
                                style="background:#f0fdf4; cursor:not-allowed; border-color:#86efac; color:#15803d; font-weight:600;"
                                title="<?= $isDimAutoQty ? 'Auto-calculated from dimension' : 'Auto-calculated based on linked accessory' ?>"
                              <?php endif; ?>>
                            <?php if ($isLocked): ?>
                              <div style="font-size:9px; color:#15803d; margin-top:2px;">⚡ Auto</div>
                            <?php endif; ?>
                          </td>

                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="addon-input border text-center w-16 py-0.5 text-xs"
                              data-field="price" value="<?= $addon['price'] ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="addon-input border text-center w-16 py-0.5 text-xs"
                              data-field="labor_cost" value="<?= $addon['labor_cost'] ?? 0 ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1">
                            <input type="text"
                              class="addon-input border w-full py-0.5 text-xs"
                              data-field="note" value="<?= htmlspecialchars($addon['note']) ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="addon-input border text-center py-0.5 text-xs"
                              style="width:50px;"
                              data-field="addon_jackup"
                              value="<?= floatval($addon['addon_jackup'] ?? $addon['default_jackup'] ?? 0) ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1 text-center addon-tot-mats"><?= number_format($aTotMats, 2) ?></td>
                          <td class="px-2 py-1 text-center addon-tot-labor"><?= number_format($aTotLabor, 2) ?></td>
                          <td class="px-2 py-1 text-center addon-price-per-item"><?= number_format($aPricePerItem, 2) ?></td>
                          <td class="px-2 py-1 text-right addon-subtotal"><?= number_format($aTotal, 2) ?></td>
                          <td class="px-2 py-1 text-center">
                            <button type="button"
                              class="delete-addon inline-block text-red-600 hover:text-red-800"
                              data-addon-id="<?= $addon['id'] ?>"
                              <?= $quotationDone ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                              Delete
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <div class="text-gray-400 italic text-sm">No add-ons for this item.</div>
                <?php endif; ?>
              </td>
            </tr>

          <?php endforeach; // end areaCustomized loop ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>