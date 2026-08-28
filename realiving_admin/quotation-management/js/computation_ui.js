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

// ── Search, Area Filter, and Sort — all applied together via the "Filter" button ──
const FILTER_STORAGE_KEY = `computationFilters_client_${typeof APP !== 'undefined' ? APP.clientId : 'default'}`;

function saveFilterState(partial) {
  let state = {};
  try {
    state = JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY)) || {};
  } catch (e) { state = {}; }
  state = { ...state, ...partial };
  localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state));
}

function loadFilterState() {
  try {
    return JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY)) || {};
  } catch (e) {
    return {};
  }
}

// ── Debounce helper: delays a function until typing pauses ──
function debounce(fn, delay) {
  let timer;
  return function (...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

// ── Shows/hides the little × button inside the search box ──
function updateClearSearchVisibility() {
  const searchInput    = document.getElementById('computation-search');
  const clearSearchBtn = document.getElementById('clear-search-btn');
  if (clearSearchBtn) clearSearchBtn.style.display = searchInput?.value ? 'flex' : 'none';
}

// ── applyFilters: shows/hides area cards by search text + area dropdown ──
function applyFilters() {
  const searchInput  = document.getElementById('computation-search');
  const areaSelect   = document.getElementById('area-filter');
  const query        = (searchInput?.value || '').trim().toLowerCase();
  const selectedArea = areaSelect?.value || '';

  document.querySelectorAll('.area-card').forEach(card => {
    const areaName      = (card.dataset.area || '').toLowerCase();
    const areaMatches   = !selectedArea || selectedArea === card.dataset.area;
    const searchMatches = !query || areaName.includes(query);
    card.style.display  = (areaMatches && searchMatches) ? '' : 'none';
  });
}

// ── applySort: reorders area cards + item rows client-side ──
function applySort() {
  const sortToggleBtn  = document.getElementById('sort-toggle-btn');
  const areasContainer = document.getElementById('areas-list') || document.querySelector('.area-card')?.parentElement;
  if (!sortToggleBtn || !areasContainer) return;

  const order = sortToggleBtn.dataset.order || 'asc'; // 'asc' or 'desc'

  // Cache the original (as-inserted) order once, the first time this runs
  if (!window.__originalAreaCardOrder) {
    window.__originalAreaCardOrder = Array.from(document.querySelectorAll('.area-card'));
  }
  const originalAreaCards = window.__originalAreaCardOrder;

  const orderedAreaCards = order === 'asc' ? originalAreaCards : [...originalAreaCards].reverse();
  orderedAreaCards.forEach(card => areasContainer.appendChild(card));

  document.querySelectorAll('.area-card tbody').forEach(tbody => {
    const rows   = Array.from(tbody.children);
    const groups = [];
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (row.hasAttribute('data-entry-id') || row.hasAttribute('data-fixed-id')) {
        const group = [row];
        const next = rows[i + 1];
        if (next && next.querySelector('td[colspan]')) {
          group.push(next);
          i++;
        }
        groups.push(group);
      }
    }
    const ordered = order === 'asc' ? groups : [...groups].reverse();
    ordered.forEach(group => group.forEach(row => tbody.appendChild(row)));
  });
}

// ── applyAll: runs filter + sort together, saves state, refreshes chips ──
function applyAll() {
  const searchInput  = document.getElementById('computation-search');
  const areaSelect    = document.getElementById('area-filter');
  const sortToggleBtn = document.getElementById('sort-toggle-btn');

  saveFilterState({
    search: searchInput?.value || '',
    area:   areaSelect?.value  || '',
    sort:   sortToggleBtn?.dataset.order || 'asc'
  });

  applyFilters();
  applySort();
  updateClearSearchVisibility();
}

function initComputationFilters() {
  const searchInput     = document.getElementById('computation-search');
  const areaSelect      = document.getElementById('area-filter');
  const sortToggleBtn   = document.getElementById('sort-toggle-btn');
  const sortToggleLabel = document.getElementById('sort-toggle-label');
  const clearSearchBtn  = document.getElementById('clear-search-btn');
  if (!searchInput && !areaSelect) return;

  function setSortButtonUI(order) {
    if (!sortToggleBtn) return;
    sortToggleBtn.dataset.order = order;
    if (sortToggleLabel) sortToggleLabel.textContent = order === 'asc' ? 'Oldest First' : 'Newest First';
    const icon = sortToggleBtn.querySelector('i');
    if (icon) icon.className = order === 'asc' ? 'fas fa-arrow-down-short-wide' : 'fas fa-arrow-down-wide-short';
  }

  // ── Restore saved state from last visit ──
  const saved = loadFilterState();
  if (searchInput && saved.search) searchInput.value = saved.search;
  if (areaSelect && saved.area) areaSelect.value = saved.area;
  setSortButtonUI(saved.sort || 'asc');

  // Search applies live as you type (debounced)
  const debouncedApply = debounce(applyAll, 250);
  searchInput?.addEventListener('input', debouncedApply);

  // Area dropdown applies immediately on change
  areaSelect?.addEventListener('change', applyAll);

  // Sort toggle applies immediately on click
  sortToggleBtn?.addEventListener('click', () => {
    const newOrder = sortToggleBtn.dataset.order === 'asc' ? 'desc' : 'asc';
    setSortButtonUI(newOrder);
    applyAll();
  });

  clearSearchBtn?.addEventListener('click', () => {
    searchInput.value = '';
    applyAll();
  });

  // Re-apply the restored state immediately so a page reload keeps last result
  applyFilters();
  applySort();
  updateClearSearchVisibility();
}

document.addEventListener('DOMContentLoaded', initComputationFilters);