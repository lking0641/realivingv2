// ── computation_fixed.js ──
// Handles fixed size entry row edits, deletes, addon edits, deletes, linking, and on-load recalc.
// Requires: computation_core.js

// ── recalcFixedAddonRow: recalculates totals for one fixed addon row ──
function recalcFixedAddonRow(row, computedUnit, finalQty) {
  const price         = parseFloat(row.querySelector('[data-field="price"]')?.value)        || 0;
  const laborCost     = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)   || 0;
  const jackup        = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value) || 0;
  const isStableMat   = parseInt(row.dataset.isStableMat    || 0);
  const multiplyValue = parseFloat(row.dataset.multiplyValue  || 0);
  const minReqUnit    = parseFloat(row.dataset.minRequiredUnit || 0);

  const effectiveUnit = computedUnit !== null ? Math.max(computedUnit, 0) : 1;
  const jackAmt       = price * (jackup / 100);
  const laborUnit     = (minReqUnit > 0 && effectiveUnit < minReqUnit) ? 1 : effectiveUnit;

  const rawMats   = isStableMat
    ? (price * finalQty) + (jackAmt * finalQty)
    : (price * effectiveUnit * finalQty) + (jackAmt * finalQty);
  const totalMats  = multiplyValue > 0 ? rawMats * multiplyValue : rawMats;
  const totalLabor = laborCost * laborUnit * finalQty;
  const sub        = totalMats + totalLabor;
  const ppi        = finalQty > 0 ? sub / finalQty : sub;

  const totMatsCell  = row.querySelector('.fixed-addon-tot-mats');
  const totLaborCell = row.querySelector('.fixed-addon-tot-labor');
  const ppiCell      = row.querySelector('.addon-price-per-item-fixed');
  const subCell      = row.querySelector('.addon-subtotal-fixed');

  if (totMatsCell)  totMatsCell.textContent  = totalMats.toFixed(2);
  if (totLaborCell) totLaborCell.textContent = totalLabor.toFixed(2);
  if (ppiCell)      ppiCell.textContent      = ppi.toFixed(2);
  if (subCell)      subCell.textContent      = sub.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {

  // ── Seed prevQty for all fixed entry rows ──
  document.querySelectorAll('tr[data-fixed-id]').forEach(tr => {
    const qInput = tr.querySelector('[data-field="quantity"]');
    if (qInput) qInput.dataset.prevQty = qInput.value;
  });

  // ── On-load: recalculate all fixed addon rows ──
  document.querySelectorAll('tr[data-fixed-addon-id]').forEach(row => {
    const requiredUnit = parseFloat(row.dataset.requiredUnit || 0);
    const maxQuantity  = parseFloat(row.dataset.maxQuantity  || 0);

    const computedUnit  = computeFixedAddonUnit(row);
    const effectiveUnit = computedUnit !== null ? Math.max(computedUnit, 0) : 1;

    let finalQty = parseFloat(row.querySelector('[data-field="quantity"]')?.value) || 1;
    if (requiredUnit > 0 && maxQuantity > 0 && effectiveUnit > 0) {
      finalQty = Math.ceil(effectiveUnit / requiredUnit) * maxQuantity;
      const qtyInput = row.querySelector('[data-field="quantity"]');
      if (qtyInput) {
        qtyInput.value             = finalQty;
        qtyInput.readOnly          = true;
        qtyInput.style.background  = '#f0fdf4';
        qtyInput.style.borderColor = '#86efac';
        qtyInput.style.color       = '#15803d';
        qtyInput.style.fontWeight  = '600';
      }
    }

    recalcFixedAddonRow(row, computedUnit, finalQty);
  });

  // ── Edit: .edit-input-fixed change listener ──
  document.querySelectorAll('.edit-input-fixed').forEach(input => {
    input.addEventListener('change', async (e) => {
      const tr      = e.target.closest('tr');
      const fixedId = tr.dataset.fixedId;
      const field   = e.target.dataset.field;
      const value   = parseFloat(e.target.value) || 0;

      // Persist to DB
      await fetch(`${APP.baseUrl}update-fixed-computation`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fixed_id: fixedId, field, value })
      });

      // If quantity changed, scale all fixed addons proportionally
      if (field === 'quantity') {
        const oldQty = parseFloat(tr.querySelector('[data-field="quantity"]').dataset.prevQty || value) || 1;
        const newQty = value;
        const ratio  = newQty / oldQty;

        if (ratio !== 1 && oldQty > 0) {
          const addonRow = tr.nextElementSibling;
          if (addonRow) {
            addonRow.querySelectorAll('tr[data-fixed-addon-id]').forEach(async aRow => {
              const addonId    = aRow.dataset.fixedAddonId;
              const qtyInput   = aRow.querySelector('[data-field="quantity"]');
              const priceInput = aRow.querySelector('[data-field="price"]');
              const noteInput  = aRow.querySelector('[data-field="note"]');

              const newAddonQty = Math.round((parseFloat(qtyInput.value) || 0) * ratio);
              qtyInput.value    = newAddonQty;

              const price = parseFloat(priceInput.value) || 0;
              const note  = noteInput.value;
              const sub   = newAddonQty * price;
              aRow.querySelector('.addon-subtotal-fixed').textContent = sub.toFixed(2);

              await fetch(`${APP.baseUrl}update-fixed-addon`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ addon_id: addonId, quantity: newAddonQty, price, note })
              });
            });
          }
        }
        tr.querySelector('[data-field="quantity"]').dataset.prevQty = newQty;
      }

      // Recalc fixed entry total
      const qty   = parseFloat(tr.querySelector('[data-field="quantity"]').value) || 1;
      const base  = parseFloat(tr.querySelector('[data-field="base_price"]').value) || 0;
      const total = base * qty;
      const ppi   = qty > 0 ? total / qty : total;

      tr.querySelector('.price-per-item-fixed').textContent = ppi.toFixed(2);
      tr.querySelector('.total-amount-fixed').textContent   = total.toFixed(2);

      recalcSummary();
    });
  });

  // ── Delete: .delete-fixed-entry click listener ──
  document.querySelectorAll('.delete-fixed-entry').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.fixedId;
      if (!confirm('Delete this fixed size entry and all its add-ons?')) return;

      await fetch(`${APP.baseUrl}delete-fixed-entry`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fixed_id: id })
      });

      const row  = document.querySelector(`tr[data-fixed-id="${id}"]`);
      const next = row.nextElementSibling;
      row.remove();
      if (next && next.querySelector('td[colspan]')) next.remove();

      recalcSummary();
    });
  });

  // ── Edit: .fixed-addon-input change listener ──
  document.querySelectorAll('.fixed-addon-input').forEach(input => {
    input.addEventListener('change', async () => {
      const row     = input.closest('tr');
      const addonId = row.dataset.fixedAddonId;
      const price     = parseFloat(row.querySelector('[data-field="price"]')?.value)        || 0;
      const laborCost = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)   || 0;
      const note      = row.querySelector('[data-field="note"]')?.value || '';
      const jackup    = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value) || 0;
      const u1 = parseFloat(row.querySelector('[data-field="user_dim_value_1"]')?.value) || 0;
      const u2 = parseFloat(row.querySelector('[data-field="user_dim_value_2"]')?.value) || 0;
      const u3 = parseFloat(row.querySelector('[data-field="user_dim_value_3"]')?.value) || 0;

      const requiredUnit = parseFloat(row.dataset.requiredUnit    || 0);
      const maxQuantity  = parseFloat(row.dataset.maxQuantity     || 0);

      const computedUnit  = computeFixedAddonUnit(row);
      const effectiveUnit = computedUnit !== null ? Math.max(computedUnit, 0) : 1;

      let finalQty = parseFloat(row.querySelector('[data-field="quantity"]')?.value) || 1;
      if (requiredUnit > 0 && maxQuantity > 0 && effectiveUnit > 0) {
        finalQty = Math.ceil(effectiveUnit / requiredUnit) * maxQuantity;
        const qtyInput = row.querySelector('[data-field="quantity"]');
        if (qtyInput) {
          qtyInput.value             = finalQty;
          qtyInput.readOnly          = true;
          qtyInput.style.background  = '#f0fdf4';
          qtyInput.style.borderColor = '#86efac';
          qtyInput.style.color       = '#15803d';
          qtyInput.style.fontWeight  = '600';
        }
      }

      recalcFixedAddonRow(row, computedUnit, finalQty);

      const res  = await fetch(`${APP.baseUrl}update-fixed-addon`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          addon_id: addonId,
          quantity: finalQty,
          price, labor_cost: laborCost, note,
          addon_jackup: jackup,
          user_dim_value_1: u1,
          user_dim_value_2: u2,
          user_dim_value_3: u3,
          computed_area: computedUnit ?? 0
        })
      });
      const data = await res.json();
      if (!data.success) {
        console.error('update_fixed_addon failed:', data);
        alert('Save failed: ' + (data.error || 'Unknown error'));
        return;
      }

      recalcSummary();
    });
  });

  // ── Delete: .delete-fixed-addon click listener ──
  document.querySelectorAll('.delete-fixed-addon').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (!confirm('Delete this add-on?')) return;

      const addonId = btn.dataset.fixedAddonId;
      const res = await fetch(`${APP.baseUrl}delete-fixed-addon`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ addon_id: addonId })
      });
      if (!res.ok) return alert('Could not delete add-on.');

      document.querySelector(`tr[data-fixed-addon-id="${addonId}"]`)?.remove();
      recalcSummary();
    });
  });

  // ── LINK FIXED ADDON: icon click → show dropdown of dimension addons in same fixed entry ──
  function getDimAddonsForFixedEntry(fixedId) {
    const results  = [];
    const parentTr = document.querySelector(`tr[data-fixed-id="${fixedId}"]`);
    if (!parentTr) return results;
    const addonContainer = parentTr.nextElementSibling;
    if (!addonContainer) return results;

    addonContainer.querySelectorAll('tr[data-fixed-addon-id]').forEach(aRow => {
      if (parseInt(aRow.dataset.hasDimension || 0) === 1) {
        const name = aRow.querySelector('td:nth-child(2)')?.textContent?.trim() || '';
        const cu   = parseFloat(aRow.querySelector('.fixed-addon-computed-unit')?.textContent?.trim() || 0) || 0;
        results.push({ id: aRow.dataset.fixedAddonId, name, computedUnit: cu });
      }
    });
    return results;
  }

  document.querySelectorAll('.link-fixed-addon-icon').forEach(icon => {
    icon.addEventListener('click', function () {
      const addonId       = this.dataset.addonId;
      const currentLinked = this.dataset.linkedId || '0';
      const myRow         = document.querySelector(`tr[data-fixed-addon-id="${addonId}"]`);
      if (!myRow) return;

      const addonContainerTr = myRow.closest('tr.bg-gray-50, tr[class*="bg-gray"]');
      const fixedTr          = addonContainerTr?.previousElementSibling;
      const fixedId          = fixedTr?.dataset?.fixedId;

      const select = myRow.querySelector('.link-fixed-addon-select');
      if (!select) return;

      const dimAddons = fixedId ? getDimAddonsForFixedEntry(fixedId) : [];
      select.innerHTML = '<option value="">— Unlink —</option>';
      dimAddons.forEach(da => {
        const opt       = document.createElement('option');
        opt.value       = da.id;
        opt.textContent = `${da.name} (unit: ${da.computedUnit.toFixed(3)})`;
        if (String(da.id) === String(currentLinked)) opt.selected = true;
        select.appendChild(opt);
      });

      select.style.display = select.style.display === 'inline-block' ? 'none' : 'inline-block';
    });
  });

  document.querySelectorAll('.link-fixed-addon-select').forEach(sel => {
    sel.addEventListener('change', async function () {
      const addonId  = this.dataset.addonId;
      const linkedId = this.value;

      await fetch(`${APP.baseUrl}link-fixed-addon`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ addon_id: addonId, linked_id: linkedId || null })
      });

      const icon = document.querySelector(`.link-fixed-addon-icon[data-addon-id="${addonId}"]`);
      if (icon) { icon.dataset.linkedId = linkedId; icon.style.color = linkedId ? '#10b981' : '#9ca3af'; }

      const myRow = document.querySelector(`tr[data-fixed-addon-id="${addonId}"]`);
      if (myRow) {
        myRow.dataset.linkedDimId = linkedId;
        const qtyInput = myRow.querySelector('[data-field="quantity"]');
        if (linkedId) {
          recalcLinkedFixedAddon(myRow);
          if (qtyInput) {
            qtyInput.readOnly          = true;
            qtyInput.style.background  = '#f0fdf4';
            qtyInput.style.borderColor = '#86efac';
            qtyInput.style.color       = '#15803d';
            qtyInput.style.fontWeight  = '600';
            qtyInput.title = 'Auto-calculated based on linked accessory';
          }
        } else {
          if (qtyInput) {
            qtyInput.readOnly          = false;
            qtyInput.style.background  = '';
            qtyInput.style.borderColor = '';
            qtyInput.style.color       = '';
            qtyInput.style.fontWeight  = '';
            qtyInput.title = '';
          }
        }
      }

      this.style.display = 'none';
      location.reload();
    });
  });

  // ── recalcLinkedFixedAddon: recalcs a no-dim fixed addon linked to a dim fixed addon ──
  window.recalcLinkedFixedAddon = function (row) {
    const linkedId = row.dataset.linkedDimId;
    if (!linkedId || linkedId === '0') return;

    const linkedRow = document.querySelector(`tr[data-fixed-addon-id="${linkedId}"]`);
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

    recalcFixedAddonRow(row, null, newQty);

    const price     = parseFloat(row.querySelector('[data-field="price"]')?.value)        || 0;
    const laborCost = parseFloat(row.querySelector('[data-field="labor_cost"]')?.value)   || 0;
    const jackup    = parseFloat(row.querySelector('[data-field="addon_jackup"]')?.value) || 0;

    fetch(`${APP.baseUrl}update-fixed-addon`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        addon_id: row.dataset.fixedAddonId,
        quantity: newQty, price, labor_cost: laborCost,
        note: row.querySelector('[data-field="note"]')?.value || '',
        addon_jackup: jackup,
        user_dim_value_1: 0, user_dim_value_2: 0, user_dim_value_3: 0,
        computed_area: 0
      })
    }).catch(err => console.error('Error saving linked fixed addon qty:', err));
  };

  // ── Cascade: when a fixed dimension addon changes, update linked no-dim fixed addons ──
  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('fixed-addon-input')) return;
    const changedRow = e.target.closest('tr[data-fixed-addon-id]');
    if (!changedRow || !parseInt(changedRow.dataset.hasDimension || 0)) return;

    const changedId = changedRow.dataset.fixedAddonId;
    setTimeout(() => {
      document.querySelectorAll(`tr[data-fixed-addon-id][data-linked-dim-id="${changedId}"]`).forEach(linkedRow => {
        recalcLinkedFixedAddon(linkedRow);
      });
      recalcSummary();
    }, 200);
  });

  // ── On-load: trigger recalc for already-linked fixed addons ──
  setTimeout(() => {
    document.querySelectorAll('tr[data-fixed-addon-id]').forEach(row => {
      if (parseInt(row.dataset.hasDimension || 0) === 0 &&
          row.dataset.linkedDimId && row.dataset.linkedDimId !== '0') {
        recalcLinkedFixedAddon(row);
      }
    });
    recalcSummary();
  }, 600);

});