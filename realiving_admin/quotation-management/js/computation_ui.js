// ── computation_ui.js ──
// Handles discount, tracker mode, computation lock, manual cost, and item name save.
// Requires: computation_core.js

document.addEventListener('DOMContentLoaded', () => {

  // ── Discount: live recalc + persist ──
  const discountInput = document.getElementById('discount');
  if (discountInput) {
    // Live preview while typing
    discountInput.addEventListener('input', () => recalcSummary());

    // Save to DB on change
    discountInput.addEventListener('change', async (e) => {
      const discPct = parseFloat(e.target.value) || 0;
      try {
        const res  = await fetch(`${APP.baseUrl}update-discount`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            client_id: APP.clientId,
            discount:  discPct
          })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Server error');
        recalcSummary();
      } catch (err) {
        alert('Could not save discount: ' + err.message);
      }
    });
  }

  // ── Tracker Mode: save on change ──
  const trackerMode = document.getElementById('tracker-mode');
  if (trackerMode) {
    trackerMode.addEventListener('change', async function () {
      const mode = this.value;
      try {
        const res  = await fetch(`${APP.baseUrl}update-tracker-mode`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            client_id:    APP.clientId,
            tracker_mode: mode
          })
        });
        const data = await res.json();
        if (data.success) {
          alert('Tracker mode updated to: ' + mode);
        } else {
          alert('Failed to update tracker mode');
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Error updating tracker mode');
      }
    });
  }

});

// ── toggleComputationLock: lock/unlock computation ──
async function toggleComputationLock() {
  const lockedEl  = document.getElementById('computation-locked');
  const clientId  = parseInt(document.getElementById('page-client-id').value);
  const newLocked = lockedEl.value === '1' ? 0 : 1;

  const msg = newLocked === 1
    ? 'Lock computation? Auto-recalculation will stop. You can then manually edit Total Cost and Remaining Balance.'
    : 'Unlock computation? This will resume auto-calculation from the item entries.';
  if (!confirm(msg)) return;

  try {
    const res  = await fetch(`${APP.baseUrl}update-computation-lock`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_id: clientId,
        locked:    newLocked
      })
    });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      alert('Error: ' + (data.error || 'Could not toggle lock'));
    }
  } catch (err) {
    alert('Error: ' + err.message);
  }
}

// ── saveManualCost: saves manually entered total cost and remaining balance ──
async function saveManualCost() {
  const clientId       = parseInt(document.getElementById('page-client-id').value);
  const totalInput     = document.getElementById('manual-total-cost');
  const remainingInput = document.getElementById('manual-remaining-balance');
  const totalCost      = totalInput     ? parseFloat(totalInput.value)     || 0 : 0;
  const remaining      = remainingInput ? parseFloat(remainingInput.value) || 0 : 0;

  try {
    const res  = await fetch(`${APP.baseUrl}update-manual-cost`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_id:           clientId,
        total_project_cost:  totalCost,
        remaining_balance:   remaining
      })
    });
    const data = await res.json();
    if (!data.success) {
      alert('Save failed: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    alert('Error: ' + err.message);
  }
}

// ── saveItemName: saves inline item name edit ──
async function saveItemName(el) {
  const entryId = el.dataset.entryId;
  const newName = el.textContent.trim();
  if (!newName) return;

  await fetch(`${APP.baseUrl}update-computation`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      entry_id: entryId,
      field:    'item_name',
      value:    newName
    })
  });
}

// ── Search & Area Filter ──
function initComputationFilters() {
  const searchInput = document.getElementById('computation-search');
  const areaSelect  = document.getElementById('area-filter');
  if (!searchInput && !areaSelect) return;

  function applyFilters() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const selectedArea = areaSelect?.value || '';

    document.querySelectorAll('.area-card').forEach(card => {
      const areaMatches = !selectedArea || selectedArea === card.dataset.area;
      let anyRowVisible = false;

      card.querySelectorAll('tr[data-entry-id], tr[data-fixed-id]').forEach(row => {
        const nameEl = row.querySelector('.item-name-edit, .fixed-item-name');
        const name   = nameEl ? nameEl.textContent.trim().toLowerCase() : '';
        const matches = !query || name.includes(query);

        row.style.display = matches ? '' : 'none';

        // hide/show the addon sub-row that follows each item row
        const addonRow = row.nextElementSibling;
        if (addonRow && addonRow.querySelector('td[colspan]')) {
          addonRow.style.display = matches ? '' : 'none';
        }
        if (matches) anyRowVisible = true;
      });

      card.style.display = (areaMatches && (!query || anyRowVisible)) ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  areaSelect?.addEventListener('change', applyFilters);
}

document.addEventListener('DOMContentLoaded', initComputationFilters);