// ── computation_addons.js ──
// Handles customized addon row edits, deletes, linking, and on-load recalc.
// Requires: computation_core.js

// ── computeAddonUnit: calculates unit for a customized addon row (used on change) ──
function computeAddonUnit(row) {
  const hasDim  = parseInt(row.dataset.hasDimension || 0);
  const dimType = row.dataset.dimType || '';
  if (!hasDim) return null;

  const allInputs = row.querySelectorAll('.addon-input[data-dim-index]');
  const axes = [];
  allInputs.forEach(inp => {
    axes.push({
      standard: parseFloat(inp.dataset.standard) || 0,
      userVal:  parseFloat(inp.value) || 0
    });
  });

  let unit = 0;
  if (dimType === 'lm') {
    const axis = axes.find(a => a.standard === 0);
    unit = axis ? (axis.userVal / 1000) : 0;
  } else {
    const zeroAxes = axes.filter(a => a.standard === 0);
    if      (zeroAxes.length === 2) unit = (zeroAxes[0].userVal / 1000) * (zeroAxes[1].userVal / 1000);
    else if (zeroAxes.length === 1) unit = zeroAxes[0].userVal / 1000;
    else                            unit = 1;
  }

  const rounded  = Math.round(unit * 1000) / 1000;
  const unitCell = row.querySelector('.addon-computed-unit');
  if (unitCell) unitCell.textContent = rounded.toFixed(3);
  return rounded;
}

// ── recalcAddonRow: recalculates totals for one customized addon row ──
function recalcAddonRow(row, computedUnit, finalQty) {
  const price         = parseFloat(row.querySelector('[data-field="price"]')?.value)       || 0;
  const laborCost     = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)  || 0;
  const jackup        = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value)|| 0;
  const isStableMat   = parseInt(row.dataset.isStableMat   || 0);
  const multiplyValue = parseFloat(row.dataset.multiplyValue || 0);
  const minReqUnit    = parseFloat(row.dataset.minRequiredUnit || 0);

  const effectiveUnit = computedUnit !== null ? Math.round(computedUnit * 1000) / 1000 : 1;
  const jackAmt       = price * (jackup / 100);
  const laborUnit     = (minReqUnit > 0 && effectiveUnit < minReqUnit) ? 1 : effectiveUnit;

  const rawMats   = isStableMat
    ? (price * finalQty) + (jackAmt * finalQty)
    : (price * effectiveUnit * finalQty) + (jackAmt * finalQty);
  const totalMats  = multiplyValue > 0 ? rawMats * multiplyValue : rawMats;
  const totalLabor = laborCost * laborUnit * finalQty;
  const sub        = totalMats + totalLabor;
  const ppi        = finalQty > 0 ? sub / finalQty : sub;

  const totMatsCell  = row.querySelector('.addon-tot-mats');
  const totLaborCell = row.querySelector('.addon-tot-labor');
  const ppiCell      = row.querySelector('.addon-price-per-item');
  const subCell      = row.querySelector('.addon-subtotal');

  if (totMatsCell)  totMatsCell.textContent  = totalMats.toFixed(2);
  if (totLaborCell) totLaborCell.textContent = totalLabor.toFixed(2);
  if (ppiCell)      ppiCell.textContent      = ppi.toFixed(2);
  if (subCell)      subCell.textContent      = sub.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {

  // ── On-load: recalculate all customized addon rows ──
  document.querySelectorAll('tr[data-addon-id]').forEach(row => {
    const requiredUnit = parseFloat(row.dataset.requiredUnit || 0);
    const maxQuantity  = parseFloat(row.dataset.maxQuantity  || 0);

    const computedUnit = computeAddonUnitOnLoad(row);
    const effectiveUnit = computedUnit !== null ? Math.round(computedUnit * 1000) / 1000 : 1;

    const autoQty = addonAutoQuantity(requiredUnit, maxQuantity, effectiveUnit);
    let finalQty  = parseFloat(row.querySelector('[data-field="quantity"]')?.value) || 1;
    if (autoQty !== null) {
      finalQty = autoQty;
      const qtyInput = row.querySelector('[data-field="quantity"]');
      if (qtyInput) qtyInput.value = autoQty;
    }

    recalcAddonRow(row, computedUnit, finalQty);
  });

  // ── Edit: .addon-input change listener ──
  document.querySelectorAll('.addon-input').forEach(input => {
    input.addEventListener('change', async () => {
      const row      = input.closest('tr');
      const addonId  = row.dataset.addonId;
      const price     = parseFloat(row.querySelector('[data-field="price"]')?.value)       || 0;
      const laborCost = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)  || 0;
      const note      = row.querySelector('[data-field="note"]')?.value || '';
      const jackup    = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value)|| 0;
      const u1        = parseFloat(row.querySelector('[data-field="user_dim_value_1"]')?.value) || 0;
      const u2        = parseFloat(row.querySelector('[data-field="user_dim_value_2"]')?.value) || 0;
      const u3        = parseFloat(row.querySelector('[data-field="user_dim_value_3"]')?.value) || 0;

      const requiredUnit = parseFloat(row.dataset.requiredUnit || 0);
      const maxQuantity  = parseFloat(row.dataset.maxQuantity  || 0);

      const computedUnit  = computeAddonUnit(row);
      const effectiveUnit = computedUnit !== null ? Math.round(computedUnit * 1000) / 1000 : 1;

      const autoQty = addonAutoQuantity(requiredUnit, maxQuantity, effectiveUnit);
      let finalQty  = parseFloat(row.querySelector('[data-field="quantity"]')?.value) || 1;
      if (autoQty !== null) {
        finalQty = autoQty;
        const qtyInput = row.querySelector('[data-field="quantity"]');
        if (qtyInput) qtyInput.value = autoQty;
      }

      recalcAddonRow(row, computedUnit, finalQty);

      await fetch(`${APP.baseUrl}update-addon-entry`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          addon_id: addonId,
          quantity: finalQty,
          price,
          labor_cost: laborCost,
          note,
          addon_jackup: jackup,
          user_dim_value_1: u1,
          user_dim_value_2: u2,
          user_dim_value_3: u3,
          computed_area: computedUnit ?? 0
        })
      });

      recalcSummary();
    });
  });

  // ── Delete: .delete-addon click listener ──
  document.querySelectorAll('.delete-addon').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (!confirm('Delete this add-on?')) return;

      const addonId = btn.dataset.addonId;
      const res = await fetch(`${APP.baseUrl}delete-addon`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ addon_id: addonId })
      });
      if (!res.ok) return alert('Could not delete add-on.');

      const addonRow = document.querySelector(`tr[data-addon-id="${addonId}"]`);
      addonRow.remove();
      recalcSummary();
    });
  });

  // ── LINK ADDON: icon click → show dropdown of dimension addons in same entry ──
  function getDimAddonsForEntry(entryId) {
    const results  = [];
    const parentTr = document.querySelector(`tr[data-entry-id="${entryId}"]`);
    if (!parentTr) return results;
    const addonContainer = parentTr.nextElementSibling;
    if (!addonContainer) return results;

    addonContainer.querySelectorAll('tr[data-addon-id]').forEach(aRow => {
      if (parseInt(aRow.dataset.hasDimension || 0) === 1) {
        const name = aRow.querySelector('td:nth-child(2)')?.textContent?.trim() || '';
        const cu   = parseFloat(aRow.querySelector('.addon-computed-unit')?.textContent?.trim() || 0) || 0;
        results.push({ id: aRow.dataset.addonId, name, computedUnit: cu });
      }
    });
    return results;
  }

  document.querySelectorAll('.link-addon-icon').forEach(icon => {
    icon.addEventListener('click', function () {
      const addonId       = this.dataset.addonId;
      const currentLinked = this.dataset.linkedId || '0';
      const myRow         = document.querySelector(`tr[data-addon-id="${addonId}"]`);
      if (!myRow) return;

      const addonContainerTr = myRow.closest('tr.bg-gray-50, tr[class*="bg-gray"]');
      const entryTr          = addonContainerTr?.previousElementSibling;
      const entryId          = entryTr?.dataset?.entryId;

      const select = myRow.querySelector('.link-addon-select');
      if (!select) return;

      const dimAddons = entryId ? getDimAddonsForEntry(entryId) : [];
      select.innerHTML = '<option value="">— Unlink —</option>';
      dimAddons.forEach(da => {
        const opt     = document.createElement('option');
        opt.value     = da.id;
        opt.textContent = `${da.name} (unit: ${da.computedUnit.toFixed(3)})`;
        if (String(da.id) === String(currentLinked)) opt.selected = true;
        select.appendChild(opt);
      });

      select.style.display = select.style.display === 'inline-block' ? 'none' : 'inline-block';
    });
  });

  document.querySelectorAll('.link-addon-select').forEach(sel => {
    sel.addEventListener('change', async function () {
      const addonId  = this.dataset.addonId;
      const linkedId = this.value;

      await fetch(`${APP.baseUrl}link-addon`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ addon_id: addonId, linked_id: linkedId || null })
      });

      const icon = document.querySelector(`.link-addon-icon[data-addon-id="${addonId}"]`);
      if (icon) { icon.dataset.linkedId = linkedId; icon.style.color = linkedId ? '#10b981' : '#9ca3af'; }

      const myRow = document.querySelector(`tr[data-addon-id="${addonId}"]`);
      if (myRow) {
        myRow.dataset.linkedDimId = linkedId;
        const qtyInput = myRow.querySelector('[data-field="quantity"]');
        if (linkedId) {
          recalcLinkedAddon(myRow);
          if (qtyInput) {
            qtyInput.readOnly           = true;
            qtyInput.style.background   = '#f0fdf4';
            qtyInput.style.borderColor  = '#86efac';
            qtyInput.style.color        = '#15803d';
            qtyInput.style.fontWeight   = '600';
            qtyInput.title = 'Auto-calculated based on linked accessory';
          }
        } else {
          if (qtyInput) {
            qtyInput.readOnly           = false;
            qtyInput.style.background   = '';
            qtyInput.style.borderColor  = '';
            qtyInput.style.color        = '';
            qtyInput.style.fontWeight   = '';
            qtyInput.title = '';
          }
        }
      }

      this.style.display = 'none';
      location.reload();
    });
  });

  // ── recalcLinkedAddon: recalcs a no-dimension addon linked to a dimension addon ──
  window.recalcLinkedAddon = function (row) {
    const linkedId  = row.dataset.linkedDimId;
    if (!linkedId || linkedId === '0') return;

    const linkedRow = document.querySelector(`tr[data-addon-id="${linkedId}"]`);
    if (!linkedRow) return;

    const maxQuantity = parseFloat(row.dataset.maxQuantity || 0);
    if (maxQuantity <= 0) return;

    const linkedQty = parseFloat(linkedRow.querySelector('[data-field="quantity"]')?.value || 1) || 1;
    const newQty    = linkedQty * maxQuantity;

    const qtyInput = row.querySelector('[data-field="quantity"]');
    if (qtyInput) {
      qtyInput.value             = newQty;
      qtyInput.readOnly          = true;
      qtyInput.style.background  = '#f0fdf4';
      qtyInput.style.borderColor = '#86efac';
      qtyInput.style.color       = '#15803d';
      qtyInput.style.fontWeight  = '600';
      qtyInput.title = 'Auto-calculated based on linked accessory';
    }

    recalcAddonRow(row, null, newQty);

    const price     = parseFloat(row.querySelector('[data-field="price"]')?.value)       || 0;
    const laborCost = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)  || 0;
    const jackup    = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value)|| 0;

    fetch(`${APP.baseUrl}update-addon-entry`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        addon_id: row.dataset.addonId,
        quantity: newQty, price, labor_cost: laborCost,
        note: row.querySelector('[data-field="note"]')?.value || '',
        addon_jackup: jackup,
        user_dim_value_1: 0, user_dim_value_2: 0, user_dim_value_3: 0,
        computed_area: 0
      })
    }).catch(err => console.error('Error saving linked addon qty:', err));
  };

  // ── Cascade: when a dimension addon changes, update linked no-dim addons ──
  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('addon-input')) return;
    const changedRow = e.target.closest('tr[data-addon-id]');
    if (!changedRow || !parseInt(changedRow.dataset.hasDimension || 0)) return;

    const changedId = changedRow.dataset.addonId;
    setTimeout(() => {
      document.querySelectorAll(`tr[data-addon-id][data-linked-dim-id="${changedId}"]`).forEach(linkedRow => {
        recalcLinkedAddon(linkedRow);
      });
      recalcSummary();
    }, 200);
  });

  // ── On-load: trigger recalc for already-linked addons ──
  setTimeout(() => {
    document.querySelectorAll('tr[data-addon-id]').forEach(row => {
      if (parseInt(row.dataset.hasDimension || 0) === 0 &&
          row.dataset.linkedDimId && row.dataset.linkedDimId !== '0') {
        recalcLinkedAddon(row);
      }
    });
    recalcSummary();
  }, 500);

});