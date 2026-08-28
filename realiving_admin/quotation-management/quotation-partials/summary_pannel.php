<div style="background:var(--adm-surface); border-radius:16px; padding:24px; margin-top:8px; border:1px solid var(--adm-line);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:16px; border-bottom:2px solid var(--adm-line);">
          <div style="background:var(--adm-ink); width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center;">
            <i class="fas fa-calculator" style="color:white; font-size:16px;"></i>
          </div>
          <h2 style="font-size:18px; font-weight:700; color:var(--adm-ink);">Summary</h2>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:20px;">
          <div style="background:#eff6ff; border-radius:10px; padding:14px 16px; border:1px solid #bfdbfe;">
            <div style="font-size:11px; color:#1e40af; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Grand Materials</div>
            <div style="font-size:18px; font-weight:800; color:#1e3a8a;" id="grand-materials"><?= number_format($grandMats, 2) ?></div>
          </div>
          <div style="background:#f0fdf4; border-radius:10px; padding:14px 16px; border:1px solid #bbf7d0;">
            <div style="font-size:11px; color:#15803d; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Total Labor</div>
            <div style="font-size:18px; font-weight:800; color:#14532d;" id="grand-labor"><?= number_format($grandLabor, 2) ?></div>
          </div>
          <div style="background:#f5f3ff; border-radius:10px; padding:14px 16px; border:1px solid #ddd6fe;">
            <div style="font-size:11px; color:#6d28d9; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Fixed Sizes</div>
            <div style="font-size:18px; font-weight:800; color:#4c1d95;" id="grand-fixed"><?= number_format($grandFixed, 2) ?></div>
          </div>
          <div style="background:#fff7ed; border-radius:10px; padding:14px 16px; border:1px solid #fed7aa;">
            <div style="font-size:11px; color:#c2410c; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Accessories</div>
            <div style="font-size:18px; font-weight:800; color:#7c2d12;" id="grand-addons"><?= number_format($grandAddons, 2) ?></div>
          </div>
        </div>
        <div style="background:var(--adm-bg); border-radius:10px; padding:16px 20px; border:1px solid var(--adm-line);">
          <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #e5e7eb;">
            <span style="color:#6b7280; font-size:13px;">Subtotal</span>
            <strong id="subtotal" style="color:#111; font-size:14px;"><?= number_format($rawTotal, 2) ?></strong>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #e5e7eb;">
            <span style="color:#6b7280; font-size:13px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              Discount (%)
              <input type="number" id="discount" style="width:60px; padding:4px 8px; border:2px solid var(--adm-line); border-radius:6px; font-size:13px; font-weight:600; text-align:center;"
                value="<?= htmlspecialchars($storedDiscount) ?>" min="0" max="100"
                <?= $quotationDone ? 'readonly style="background:#f5f5f5; cursor:not-allowed; width:60px; padding:4px 8px; border:2px solid var(--adm-line); border-radius:6px; font-size:13px;"' : '' ?>>
              <?php if ($storedDiscount > 0): ?>
                <span id="discount-saved-badge" style="background:#dcfce7; color:#16a34a; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700;">
                  - ₱<?= number_format($rawTotal * ($storedDiscount / 100), 2) ?> saved
                </span>
              <?php else: ?>
                <span id="discount-saved-badge" style="display:none; background:#dcfce7; color:#16a34a; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700;"></span>
              <?php endif; ?>
            </span>
            <div style="text-align:right;">
              <div><strong id="after-discount" style="color:#059669; font-size:14px;"><?= number_format($afterDiscount, 2) ?></strong></div>
              <?php if ($storedDiscount > 0): ?>
                <div id="discount-amount-line" style="font-size:11px; color:#dc2626; font-weight:600;">
                  - ₱<?= number_format($rawTotal * ($storedDiscount / 100), 2) ?> off
                </div>
              <?php else: ?>
                <div id="discount-amount-line" style="display:none; font-size:11px; color:#dc2626; font-weight:600;"></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($business_type === 'Project'): ?>
            <div style="background:var(--adm-bg); border-radius:8px; padding:12px 14px; margin:10px 0; border:1px solid var(--adm-line);">
              <div style="font-size:12px; font-weight:700; color:var(--adm-ink); margin-bottom:8px;"><i class="fas fa-tools"></i> Project Additional Charges</div>
              <div style="display:flex; justify-content:space-between; padding:3px 0; font-size:13px;">
                <span style="color:var(--adm-soft);">General Requirements (10%)</span>
                <strong id="general-req" style="color:var(--adm-ink);"><?= number_format($generalReq, 2) ?></strong>
              </div>
              <div style="display:flex; justify-content:space-between; padding:3px 0; font-size:13px;">
                <span style="color:var(--adm-soft);">Subtotal with GR</span>
                <strong id="subtotal-with-gr" style="color:var(--adm-ink);"><?= number_format($afterDiscount + $generalReq, 2) ?></strong>
              </div>
              <div style="display:flex; justify-content:space-between; padding:3px 0; font-size:13px;">
                <span style="color:var(--adm-soft);">VAT (12%)</span>
                <strong id="vat" style="color:var(--adm-ink);"><?= number_format($vat, 2) ?></strong>
              </div>
            </div>
          <?php endif; ?>
          <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 0; margin-top:4px;">
            <span style="font-size:15px; font-weight:700; color:var(--adm-ink);">Final Total</span>
            <span id="final-total" style="font-size:24px; font-weight:800; color:#059669;">₱<?= number_format($finalTotal, 2) ?></span>
          </div>
        </div>

        <!-- Hidden field to pass business_type to JavaScript -->
        <input type="hidden" id="business-type" value="<?= htmlspecialchars($business_type) ?>">
        <input type="hidden" id="computation-locked" value="<?= $computation_locked ?>">
        <input type="hidden" id="page-client-id" value="<?= $client_id ?>">