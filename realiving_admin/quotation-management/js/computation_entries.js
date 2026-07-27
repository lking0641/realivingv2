// ── computation_entries.js ──
// Handles customized entry row edits, deletes, and on-load recalc.
// Requires: computation_core.js
document.addEventListener('DOMContentLoaded', () => {

  // ── Seed prevQty for all customized entry rows ──
  document.querySelectorAll('tr[data-entry-id]').forEach(tr => {
    const qInput = tr.querySelector('[data-field="quantity"]');
    if (qInput) qInput.dataset.prevQty = qInput.value;
  });

  // ── Helper: recalc one customized entry row's financials ──
  function recalcEntryRow(tr) {
    const unit    = computeUnit(tr);
    const qty     = parseFloat(tr.querySelector('[data-field="quantity"]')?.value)    || 1;
    const up      = parseFloat(tr.querySelector('[data-field="unit_price"]')?.value)  || 0;
    const lc      = parseFloat(tr.querySelector('[data-field="labor_cost"]')?.value)  || 0;
    const jackupPct = (parseFloat(tr.querySelector('[data-field="jackup"]')?.value) || 0) / 100;

    let baseMats  = unit * up * qty;
    let baseLabor = unit * lc * qty;

    const finalMats  = baseMats + (baseMats * jackupPct);
    const finalLabor = baseLabor;
    const finalTotal = finalMats + finalLabor;
    const ppi        = qty > 0 ? finalTotal / qty : finalTotal;

    const matCell  = tr.querySelector('.total-materials');
    const labCell  = tr.querySelector('.total-labor');
    const ppiCell  = tr.querySelector('.price-per-item');
    const totCell  = tr.querySelector('.total-amount');

    if (matCell)  matCell.textContent  = finalMats.toFixed(2);
    if (labCell)  labCell.textContent  = finalLabor.toFixed(2);
    if (ppiCell)  ppiCell.textContent  = ppi.toFixed(2);
    if (totCell)  totCell.textContent  = finalTotal.toFixed(2);
  }

  // ── On-load: recalculate all customized entry rows ──
  document.querySelectorAll('tr[data-entry-id]').forEach(tr => {
    recalcEntryRow(tr);
  });

  // ── Edit: .edit-input change listener ──
  document.querySelectorAll('.edit-input').forEach(input => {
    input.addEventListener('change', async (e) => {
      const tr      = e.target.closest('tr');
      const entryId = tr.dataset.entryId;
      const field   = e.target.dataset.field;
      const value   = parseFloat(e.target.value) || 0;

      // Persist to DB
      await fetch(`${APP.baseUrl}update-computation`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entry_id: entryId, field, value })
      });

      // If quantity changed, scale all addons proportionally
      if (field === 'quantity') {
        const oldQty = parseFloat(tr.querySelector('[data-field="quantity"]').dataset.prevQty || value) || 1;
        const newQty = value;
        const ratio  = newQty / oldQty;

        if (ratio !== 1 && oldQty > 0) {
          const addonRow = tr.nextElementSibling;
          if (addonRow) {
            addonRow.querySelectorAll('tr[data-addon-id]').forEach(async aRow => {
              const addonId    = aRow.dataset.addonId;
              const qtyInput   = aRow.querySelector('[data-field="quantity"]');
              const priceInput = aRow.querySelector('[data-field="price"]');
              const noteInput  = aRow.querySelector('[data-field="note"]');

              const newAddonQty = Math.round((parseFloat(qtyInput.value) || 0) * ratio);
              qtyInput.value    = newAddonQty;

              const price = parseFloat(priceInput.value) || 0;
              const note  = noteInput.value;
              const sub   = newAddonQty * price;
              aRow.querySelector('.addon-subtotal').textContent = sub.toFixed(2);

              await fetch(`${APP.baseUrl}update-addon-entry`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ addon_id: addonId, quantity: newAddonQty, price, note })
              });
            });
          }
        }
        tr.querySelector('[data-field="quantity"]').dataset.prevQty = newQty;
      }

      // Recalc row then summary
      recalcEntryRow(tr);
      recalcSummary();
    });
  });

  // ── Delete: .delete-entry click listener ──
  document.querySelectorAll('.delete-entry').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.entryId;
      if (!confirm('Delete this entry and all its add-ons?')) return;

      await fetch(`${APP.baseUrl}delete-entry`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entry_id: id })
      });

      const row  = document.querySelector(`tr[data-entry-id="${id}"]`);
      const next = row.nextElementSibling;
      row.remove();
      if (next && next.querySelector('td[colspan]')) next.remove();

      recalcSummary();
    });
  });

});