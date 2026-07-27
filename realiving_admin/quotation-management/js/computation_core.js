// ── computation_core.js ──
// Pure utility functions shared across all computation JS files.
// Load this FIRST before any other computation JS.

// ── computeUnit: calculates area/unit for a customized entry row ──
function computeUnit(tr) {
  const w = parseFloat(tr.querySelector('[data-field="width"]').value) || 0;
  const h = parseFloat(tr.querySelector('[data-field="height"]').value) || 0;
  const l = parseFloat(tr.querySelector('[data-field="length"]').value) || 0;

  const isLinear = tr.dataset.unitMode === 'linear';
  const flags = {
    width:  parseInt(isLinear ? tr.dataset.itemWidthLinear  : tr.dataset.itemWidthSqm,  10),
    height: parseInt(isLinear ? tr.dataset.itemHeightLinear : tr.dataset.itemHeightSqm, 10),
    length: parseInt(isLinear ? tr.dataset.itemLengthLinear : tr.dataset.itemLengthSqm, 10),
  };

  let unit = 0;
  if (isLinear) {
    if      (flags.width  === 0) unit = w / 1000;
    else if (flags.height === 0) unit = h / 1000;
    else                         unit = l / 1000;
  } else {
    const vals = [];
    if (flags.width  === 0) vals.push(w / 1000);
    if (flags.height === 0) vals.push(h / 1000);
    if (flags.length === 0) vals.push(l / 1000);
    unit = (vals.length === 2) ? (vals[0] * vals[1]) : 1;
  }

  const display = unit.toFixed(3).replace(/\.?0+$/, '');
  tr.querySelector('.unit-cell').innerHTML = `
    ${display}
    <br><span class="text-xs text-gray-500">(${tr.dataset.unitMode})</span>
  `;
  return unit;
}

// ── computeFixedAddonUnit: calculates unit for fixed size addon rows ──
function computeFixedAddonUnit(row) {
  const hasDim  = parseInt(row.dataset.hasDimension || 0);
  const dimType = row.dataset.dimType || '';
  if (!hasDim) return null;

  const allInputs = row.querySelectorAll('.fixed-addon-input[data-dim-index]');
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

  const rounded   = Math.round(unit * 1000) / 1000;
  const unitCell  = row.querySelector('.fixed-addon-computed-unit');
  if (unitCell) unitCell.textContent = rounded > 0 ? rounded.toFixed(3) : '—';
  return rounded;
}

// ── computeAddonUnitOnLoad: same as above but for customized addon rows ──
function computeAddonUnitOnLoad(row) {
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

// ── addonAutoQuantity: auto-qty formula for dimension-based addons ──
function addonAutoQuantity(requiredUnit, maxQuantity, computedUnit) {
  if (requiredUnit > 0 && maxQuantity > 0 && computedUnit > 0) {
    return Math.ceil(computedUnit / requiredUnit) * maxQuantity;
  }
  return null;
}

// ── updateProjectCost: persists final total + updates header KPI cards ──
async function updateProjectCost(finalTotal) {
  try {
    const response = await fetch(`${APP.baseUrl}update-project-cost`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_id: APP.clientId,
        total_cost: finalTotal
      })
    });
    const data = await response.json();
    if (data.success) {
      const totalCostEl  = document.querySelector('.info-value[data-kpi="total_cost"]');
      const remainingEl  = document.querySelector('.info-value[data-kpi="remaining_balance"]');
      const roundedTotal = Math.round(finalTotal * 100) / 100;
      const roundedRem   = Math.round(data.remaining_balance * 100) / 100;
      if (totalCostEl) totalCostEl.textContent = '₱' + roundedTotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      if (remainingEl)  remainingEl.textContent = '₱' + roundedRem.toLocaleString('en-PH',   { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  } catch (error) {
    console.error('Error updating project cost:', error);
  }
}

// ── recalcSummary: reads all totals from DOM and updates summary panel ──
async function recalcSummary() {
  if (document.getElementById('computation-locked')?.value === '1') return;

  let grandMats   = 0;
  let grandLabor  = 0;
  let grandFixed  = 0;
  let grandAddons = 0;

  document.querySelectorAll('tr[data-entry-id]').forEach(tr => {
    grandMats  += parseFloat(tr.querySelector('.total-materials')?.textContent.replace(/,/g, '')) || 0;
    grandLabor += parseFloat(tr.querySelector('.total-labor')?.textContent.replace(/,/g, ''))     || 0;
  });

  document.querySelectorAll('tr[data-fixed-id]').forEach(tr => {
    grandFixed += parseFloat(tr.querySelector('.total-amount-fixed')?.textContent.replace(/,/g, '')) || 0;
  });

  document.querySelectorAll('.addon-subtotal').forEach(td => {
    grandAddons += parseFloat(td.textContent.replace(/,/g, '')) || 0;
  });
  document.querySelectorAll('.addon-subtotal-fixed').forEach(td => {
    grandAddons += parseFloat(td.textContent.replace(/,/g, '')) || 0;
  });

  const subtotal     = grandMats + grandLabor + grandFixed + grandAddons;
  const discPct      = (parseFloat(document.getElementById('discount')?.value) || 0) / 100;
  const afterDiscount = subtotal * (1 - discPct);

  const businessType = document.getElementById('business-type')?.value;
  let generalReq = 0, vat = 0, final = afterDiscount;

  if (businessType === 'Project') {
    generalReq          = afterDiscount * 0.10;
    const subtotalWithGR = afterDiscount + generalReq;
    vat                  = subtotalWithGR * 0.12;
    final                = subtotalWithGR + vat;

    const grEl   = document.getElementById('general-req');
    const grSubEl = document.getElementById('subtotal-with-gr');
    const vatEl  = document.getElementById('vat');
    if (grEl)    grEl.textContent    = generalReq.toFixed(2);
    if (grSubEl) grSubEl.textContent = subtotalWithGR.toFixed(2);
    if (vatEl)   vatEl.textContent   = vat.toFixed(2);
  }

  document.getElementById('grand-materials').textContent  = grandMats.toFixed(2);
  document.getElementById('grand-labor').textContent      = grandLabor.toFixed(2);
  document.getElementById('grand-fixed').textContent      = grandFixed.toFixed(2);
  document.getElementById('grand-addons').textContent     = grandAddons.toFixed(2);
  document.getElementById('subtotal').textContent         = subtotal.toFixed(2);
  document.getElementById('after-discount').textContent   = afterDiscount.toFixed(2);
  document.getElementById('final-total').textContent      = (Math.round(final * 100) / 100).toFixed(2);

  const discountAmount = subtotal * discPct;
  const badge   = document.getElementById('discount-saved-badge');
  const amtLine = document.getElementById('discount-amount-line');
  if (discPct > 0 && discountAmount > 0) {
    if (badge)   { badge.textContent   = '- ₱' + discountAmount.toFixed(2) + ' saved'; badge.style.display   = 'inline-block'; }
    if (amtLine) { amtLine.textContent = '- ₱' + discountAmount.toFixed(2) + ' off';  amtLine.style.display = 'block'; }
  } else {
    if (badge)   badge.style.display   = 'none';
    if (amtLine) amtLine.style.display = 'none';
  }

  await updateProjectCost(final);
}