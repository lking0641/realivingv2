<?php
// partials/_fixed_entries.php
// Requires: $areaFixed, $quotationDone, $fixedAddonsStmt, $client_id, $client_name, $client_email, $client_address, $client_contact
// Called inside foreach ($areas as $area) loop
?>

<?php if (!empty($areaFixed)): ?>
  <div>
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
      <div style="width:4px; height:20px; background:#8b5cf6; border-radius:2px;"></div>
      <span style="font-size:12px; font-weight:700; color:#8b5cf6; text-transform:uppercase; letter-spacing:0.5px;">
        <i class="fas fa-ruler-combined"></i> Fixed Size Items
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; min-width:700px;">
        <thead>
          <tr style="background:#f5f3ff;">
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #ddd6fe;">Image</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #ddd6fe;">Item</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #ddd6fe;">Size</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:left; border-bottom:2px solid #ddd6fe;">Color</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #ddd6fe;">Base Price</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #ddd6fe;">Qty</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #ddd6fe;">Type</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #ddd6fe;">Price/Item</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:right; border-bottom:2px solid #ddd6fe;">Total</th>
            <th style="padding:8px 10px; font-size:10px; font-weight:700; color:#6d28d9; text-transform:uppercase; letter-spacing:0.5px; text-align:center; border-bottom:2px solid #ddd6fe;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($areaFixed as $row):
            $baseTotal = $row['base_price'] * $row['quantity'];
          ?>

            <tr data-fixed-id="<?= $row['id'] ?>">

              <!-- IMAGE -->
              <td class="px-4 py-2">
                <?php
                $displayImage = '';
                $imageFolder  = '';
                if (!empty($row['color_image_path'])) {
                  $displayImage = $row['color_image_path'];
                  $imageFolder  = file_exists(CLIENT_ASSET . '/images/product_colors/' . $displayImage)
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
              <td class="px-4 py-2 text-sm"><?= htmlspecialchars($row['item_name'] ?? '') ?></td>

              <!-- SIZE INFO -->
              <td class="px-2 py-2 text-sm">
                <div class="font-semibold"><?= htmlspecialchars($row['size_label'] ?? 'Size') ?></div>
                <?php if ($row['dimension_label_name']): ?>
                  <div class="text-xs text-gray-500"><?= htmlspecialchars($row['dimension_label_name']) ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-600 mt-1">
                  <?php if ($row['size_width']): ?>
                    <div><?= htmlspecialchars($row['item_width_label_linear'] ?? 'W') ?>: <?= $row['size_width'] ?><?= $row['measurement_unit'] ?></div>
                  <?php endif; ?>
                  <?php if ($row['size_height']): ?>
                    <div><?= htmlspecialchars($row['item_height_label_linear'] ?? 'H') ?>: <?= $row['size_height'] ?><?= $row['measurement_unit'] ?></div>
                  <?php endif; ?>
                  <?php if ($row['size_length']): ?>
                    <div><?= htmlspecialchars($row['item_length_label_linear'] ?? 'L') ?>: <?= $row['size_length'] ?><?= $row['measurement_unit'] ?></div>
                  <?php endif; ?>
                </div>
              </td>

              <!-- COLOR -->
              <td class="px-2 py-2 text-sm"><?= htmlspecialchars($row['color_label']) ?></td>

              <!-- BASE PRICE -->
              <td class="px-2 py-2">
                <input type="number" step="0.01"
                  class="edit-input-fixed text-center border rounded px-1 py-1"
                  data-field="base_price"
                  value="<?= htmlspecialchars($row['base_price']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- QUANTITY -->
              <td class="px-4 py-2">
                <input type="number" step="1"
                  class="edit-input-fixed w-[35px] text-center border rounded px-1 py-1"
                  data-field="quantity"
                  value="<?= htmlspecialchars($row['quantity']) ?>"
                  <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
              </td>

              <!-- UNIT TYPE -->
              <td class="px-4 py-2 text-center"><?= htmlspecialchars($row['unit_type']) ?></td>

              <!-- PRICE PER ITEM -->
              <td class="price-per-item-fixed" style="text-align:center; font-size:12px; color:#374151;">
                <?php
                $qty1Fixed        = max(1, (int)$row['quantity']);
                $pricePerItemFixed = $baseTotal / $qty1Fixed;
                ?>
                <?= number_format($pricePerItemFixed, 2) ?>
              </td>

              <!-- TOTAL -->
              <td class="total-amount-fixed"><?= number_format($baseTotal, 2) ?></td>

              <!-- ACTIONS -->
              <td class="px-2 py-2 text-center">
                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                  <a href="edit_quotation_entry.php?fixed_id=<?= $row['id'] ?>&client_id=<?= $client_id ?>&client_name=<?= urlencode($client_name) ?>&email=<?= urlencode($client_email) ?>&address=<?= urlencode($client_address) ?>&contact=<?= urlencode($client_contact) ?>"
                    style="display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#ede9fe; color:#5b21b6; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; <?= $quotationDone ? 'opacity:0.5; pointer-events:none;' : '' ?>">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                  <button class="delete-fixed-entry btn btn-sm text-red-600 hover:text-red-800"
                    data-fixed-id="<?= $row['id'] ?>"
                    style="padding:4px 10px; font-size:11px; font-weight:700;"
                    <?= $quotationDone ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                    <i class="fas fa-trash"></i> Delete
                  </button>
                </div>
              </td>
            </tr>

            <!-- FIXED ADDONS ROW -->
            <?php
            $fixedAddonsStmt->bind_param("i", $row['id']);
            $fixedAddonsStmt->execute();
            $addons = $fixedAddonsStmt->get_result();
            ?>

            <tr class="bg-gray-50">
              <td colspan="10" class="px-4 py-2">
                <?php if ($addons->num_rows): ?>
                  <table class="table-auto w-full text-xs border border-gray-300">
                    <thead class="bg-gray-100 text-gray-600">
                      <tr>
                        <th class="px-2 py-1 text-left">Image</th>
                        <th class="px-2 py-1 text-left">Accessory</th>
                        <th class="px-2 py-1 text-left">Category</th>
                        <th class="px-2 py-1 text-center">Dimensions</th>
                        <th class="px-2 py-1 text-center">Computed Unit</th>
                        <th class="px-2 py-1 text-center">Qty</th>
                        <th class="px-2 py-1 text-center">Price</th>
                        <th class="px-2 py-1 text-center">Labor Cost</th>
                        <th class="px-2 py-1 text-center">Jack-up%</th>
                        <th class="px-2 py-1 text-left">Note</th>
                        <th class="px-2 py-1 text-center">Tot Mats</th>
                        <th class="px-2 py-1 text-center">Tot Labor</th>
                        <th class="px-2 py-1 text-center">Price/Item</th>
                        <th class="px-2 py-1 text-right">Total</th>
                        <th class="px-2 py-1 text-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
  <?php
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
      if (!empty($linked[(int)$a['id']])) {
        foreach ($linked[(int)$a['id']] as $child) {
          $sorted[] = $child;
        }
      }
    }
  }
  foreach ($sorted as $addon):
    $fixedAddonMeta = [
                          'required_unit'     => $addon['eff_required_unit']    ?? 0,
                          'max_quantity'      => $addon['eff_max_quantity']     ?? 0,
                          'is_stable_mat'     => $addon['eff_is_stable_mat']    ?? 0,
                          'min_required_unit' => $addon['eff_min_required_unit'] ?? 0,
                          'multiply_value'    => $addon['multiply_value']       ?? 0,
                        ];
                        $fixedIsDimAutoQty = ($addon['has_dimension'] && ($fixedAddonMeta['required_unit'] > 0) && ($fixedAddonMeta['max_quantity'] > 0));
                        $fixedIsLocked     = $fixedIsDimAutoQty;

                        $fComputedUnit = floatval($addon['computed_area'] ?? 0);
                        $fTotMats  = 0;
                        $fTotLabor = 0;

                        if ($addon['has_dimension'] && $fComputedUnit > 0) {
                          $fEffUnit  = $fComputedUnit;
                          $fJackAmt  = floatval($addon['price']) * (floatval($addon['addon_jackup'] ?? 0) / 100);
                          $fMinReq   = floatval($fixedAddonMeta['min_required_unit']);
                          $fLaborUnit = ($fMinReq > 0 && $fEffUnit < $fMinReq) ? 1 : $fEffUnit;
                          $fMul      = floatval($fixedAddonMeta['multiply_value']);
                          $fRawMats  = $fixedAddonMeta['is_stable_mat']
                            ? ($addon['price'] * $addon['quantity']) + ($fJackAmt * $addon['quantity'])
                            : ($addon['price'] * $fEffUnit * $addon['quantity']) + ($fJackAmt * $addon['quantity']);
                          $fTotMats  = $fMul > 0 ? $fRawMats * $fMul : $fRawMats;
                          $fTotLabor = floatval($addon['labor_cost'] ?? 0) * $fLaborUnit * $addon['quantity'];
                        } else {
                          $fJackAmt  = floatval($addon['price']) * (floatval($addon['addon_jackup'] ?? 0) / 100);
                          $fRawMats  = ($addon['price'] * $addon['quantity']) + ($fJackAmt * $addon['quantity']);
                          $fMul      = floatval($fixedAddonMeta['multiply_value']);
                          $fTotMats  = $fMul > 0 ? $fRawMats * $fMul : $fRawMats;
                          $fTotLabor = floatval($addon['labor_cost'] ?? 0) * $addon['quantity'];
                        }

                        $fTotal = $fTotMats + $fTotLabor;
                        $fPpi   = $addon['quantity'] > 0 ? $fTotal / $addon['quantity'] : $fTotal;
                      ?>
                        <tr data-fixed-addon-id="<?= $addon['id'] ?>"
                          data-linked-dim-id="<?= intval($addon['linked_dimension_addon_id'] ?? 0) ?>"
                          data-has-dimension="<?= (int)($addon['has_dimension'] ?? 0) ?>"
                          data-dim-type="<?= htmlspecialchars($addon['dimension_type'] ?? '') ?>"
                          data-dim-standard-1="<?= floatval($addon['dimension_value_1'] ?? 0) ?>"
                          data-dim-standard-2="<?= floatval($addon['dimension_value_2'] ?? 0) ?>"
                          data-dim-standard-3="<?= floatval($addon['dimension_value_3'] ?? 0) ?>"
                          data-required-unit="<?= floatval($fixedAddonMeta['required_unit']) ?>"
                          data-max-quantity="<?= floatval($fixedAddonMeta['max_quantity']) ?>"
                          data-is-stable-mat="<?= (int)$fixedAddonMeta['is_stable_mat'] ?>"
                          data-min-required-unit="<?= floatval($fixedAddonMeta['min_required_unit']) ?>"
                          data-multiply-value="<?= floatval($fixedAddonMeta['multiply_value']) ?>">

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
                              <span class="link-fixed-addon-icon"
                                data-addon-id="<?= $addon['id'] ?>"
                                data-linked-id="<?= intval($addon['linked_dimension_addon_id'] ?? 0) ?>"
                                title="Link to a dimension accessory"
                                style="cursor:pointer; margin-left:6px; color:<?= $addon['linked_dimension_addon_id'] ? '#10b981' : '#9ca3af' ?>; font-size:13px;">🔗</span>
                              <select class="link-fixed-addon-select"
                                data-addon-id="<?= $addon['id'] ?>"
                                style="display:none; font-size:11px; padding:2px 4px; border:1px solid #d1d5db; border-radius:4px; margin-top:4px;">
                                <option value="">— Unlink —</option>
                              </select>
                            <?php endif; ?>
                          </td>

                          <td class="px-2 py-1"><?= htmlspecialchars($addon['addon_category']) ?></td>

                          <!-- DIMENSION INPUTS -->
                          <td class="px-2 py-1">
                            <?php if ($addon['has_dimension']): ?>
                              <div style="display:flex; flex-direction:column; gap:3px; min-width:120px;">
                                <?php
                                $fDimLabels    = [$addon['dimension_label_1'], $addon['dimension_label_2'], $addon['dimension_label_3']];
                                $fDimStandards = [$addon['dimension_value_1'],  $addon['dimension_value_2'],  $addon['dimension_value_3']];
                                $fUserVals     = [$addon['user_dim_value_1'],   $addon['user_dim_value_2'],   $addon['user_dim_value_3']];
                                $fDimFields    = ['user_dim_value_1', 'user_dim_value_2', 'user_dim_value_3'];
                                foreach ([0, 1, 2] as $di):
                                  if (!$fDimLabels[$di]) continue;
                                ?>
                                  <div style="display:flex; align-items:center; gap:4px;">
                                    <span style="font-size:10px; color:#6b7280; min-width:30px;"><?= htmlspecialchars($fDimLabels[$di]) ?>:</span>
                                    <input type="number" step="0.01"
                                      class="fixed-addon-input border text-center py-0.5 text-xs"
                                      style="width:55px;"
                                      data-field="<?= $fDimFields[$di] ?>"
                                      data-dim-index="<?= $di ?>"
                                      data-standard="<?= floatval($fDimStandards[$di]) ?>"
                                      value="<?= floatval($fUserVals[$di]) ?>"
                                      <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                                    <span style="font-size:9px; color:#9ca3af;">(std: <?= $fDimStandards[$di] ?>)</span>
                                  </div>
                                <?php endforeach; ?>
                                <div style="font-size:9px; color:#6b7280; margin-top:2px;">Type: <?= htmlspecialchars($addon['dimension_type']) ?></div>
                              </div>
                            <?php else: ?>
                              <span style="color:#d1d5db; font-size:11px;">—</span>
                            <?php endif; ?>
                          </td>

                          <!-- COMPUTED UNIT -->
                          <td class="px-2 py-1 text-center fixed-addon-computed-unit" style="font-size:11px; color:#374151;">
                            <?= ($addon['has_dimension'] && $fComputedUnit) ? number_format($fComputedUnit, 3) : '—' ?>
                          </td>

                          <!-- QUANTITY -->
                          <td class="px-2 py-1 text-center">
                            <input type="number" min="1"
                              class="fixed-addon-input border text-center w-12 py-0.5 text-xs"
                              data-field="quantity"
                              value="<?= $addon['quantity'] ?>"
                              <?php if ($quotationDone || $fixedIsLocked): ?>
                                readonly
                                style="background:#f0fdf4; cursor:not-allowed; border-color:#86efac; color:#15803d; font-weight:600;"
                                title="<?= $fixedIsDimAutoQty ? 'Auto-calculated from dimension' : '' ?>"
                              <?php endif; ?>>
                            <?php if ($fixedIsLocked): ?>
                              <div style="font-size:9px; color:#15803d; margin-top:2px;">⚡ Auto</div>
                            <?php endif; ?>
                          </td>

                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="fixed-addon-input border text-center w-16 py-0.5 text-xs"
                              data-field="price" value="<?= $addon['price'] ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="fixed-addon-input border text-center w-16 py-0.5 text-xs"
                              data-field="labor_cost" value="<?= $addon['labor_cost'] ?? 0 ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1 text-center">
                            <input type="number" step="0.01"
                              class="fixed-addon-input border text-center py-0.5 text-xs"
                              style="width:50px;"
                              data-field="addon_jackup"
                              value="<?= floatval($addon['addon_jackup'] ?? 0) ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>
                          <td class="px-2 py-1">
                            <input type="text"
                              class="fixed-addon-input border w-full py-0.5 text-xs"
                              data-field="note" value="<?= htmlspecialchars($addon['note']) ?>"
                              <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed;"' : '' ?>>
                          </td>

                          <td class="px-2 py-1 text-center fixed-addon-tot-mats"><?= number_format($fTotMats, 2) ?></td>
                          <td class="px-2 py-1 text-center fixed-addon-tot-labor"><?= number_format($fTotLabor, 2) ?></td>
                          <td class="px-2 py-1 text-center addon-price-per-item-fixed"><?= number_format($fPpi, 2) ?></td>
                          <td class="px-2 py-1 text-right addon-subtotal-fixed"><?= number_format($fTotal, 2) ?></td>

                          <td class="px-2 py-1 text-center">
                            <button type="button"
                              class="delete-fixed-addon inline-block text-red-600 hover:text-red-800"
                              data-fixed-addon-id="<?= $addon['id'] ?>"
                              <?= $quotationDone ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                              Delete
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <div class="text-gray-400 italic text-sm">No accessories for this item.</div>
                <?php endif; ?>
              </td>
            </tr>

          <?php endforeach; // end areaFixed loop ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>