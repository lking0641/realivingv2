// coordinator_timeline.js
// Extracted from coordinator_timeline.php.
// Reads dynamic (PHP-sourced) data from window.CoordTimelineData, which is set
// by a tiny inline <script> block left in the PHP file right before this file
// is loaded. Everything else here is static logic with no PHP embedded in it.
//
// NOTE (Tailwind conversion): the PHP file no longer defines custom CSS
// classes (.cal-day, .range-start, .rd-dates.empty, etc.) or CSS custom
// properties (--adm-ink, --fab, ...). Anywhere this file used to toggle those
// class names or read a var(--x) color, it now applies literal Tailwind
// utility classes / hex colors instead, so make sure any new class string
// you add here is a complete, literal Tailwind class (so the Tailwind JIT
// scanner picks it up from this file).

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const SMONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Palette (mirrors the hex values baked into the Tailwind classes in the PHP file)
const COLOR = {
    ink: '#0B0B0B',
    soft: '#6B6B6B',
    muted: '#9A9A9A',
    surface: '#FFFFFF',
    line: '#E2E2E2',
    bg: '#F5F5F5',
    fab: '#3b82f6', // blue-500
    del: '#8b5cf6', // violet-500
    ins: '#10b981', // emerald-500
};

// Tailwind class groups used to toggle "filled" vs "empty" state on text nodes
const CS_VAL_FILLED = ['font-bold', 'text-[#0B0B0B]'];
const CS_VAL_EMPTY = ['font-normal', 'text-[#6B6B6B]'];

const RD_DATES_FILLED = ['font-bold', 'text-[#0B0B0B]'];
const RD_DATES_EMPTY = ['font-medium', 'text-[#6B6B6B]'];

let cal = {
    slugGroup: '', phase: '', label: '', calKey: '',
    viewYear: 0, viewMonth: 0,
    startDate: null, endDate: null,
    picking: 'start'
};

function openCalendar(slugGroup, phase, label, calKey) {
    cal.slugGroup = slugGroup;
    cal.phase = phase;
    cal.label = label;
    cal.calKey = calKey;

    let es = '', ee = '';
    if (slugGroup === 'overall') {
        es = document.getElementById('h_overall_start').value;
        ee = document.getElementById('h_overall_end').value;
    } else {
        es = document.getElementById('h_' + slugGroup + '_' + phase + '_start').value;
        ee = document.getElementById('h_' + slugGroup + '_' + phase + '_end').value;
    }

    cal.startDate = es ? parseYMD(es) : null;
    cal.endDate = ee ? parseYMD(ee) : null;
    cal.picking = 'start';

    const nav = cal.startDate || new Date();
    cal.viewYear = nav.getFullYear();
    cal.viewMonth = nav.getMonth();

    document.getElementById('calTitleText').textContent = label + ' — Date Range';
    renderCal();
    updateStatus();
    openOverlay(document.getElementById('calOverlay'));
}

function parseYMD(s) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
}
function toYMD(d) {
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}
function fmt(d) {
    return SMONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}
function sameDay(a, b) {
    return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}
function daysBetween(a, b) { return Math.round(Math.abs(b - a) / 86400000) + 1; }

// Show/hide the fixed overlay panels (they use Tailwind's `hidden` utility
// in the markup instead of a custom .open class, so we toggle `hidden` and
// swap `flex` in/out to restore the centering layout).
function openOverlay(el) {
    el.classList.remove('hidden');
    el.classList.add('flex');
}
function closeOverlayEl(el) {
    el.classList.add('hidden');
    el.classList.remove('flex');
}

function renderCal() {
    const y = cal.viewYear, m = cal.viewMonth;
    document.getElementById('calMonthYear').textContent = MONTHS[m] + ' ' + y;

    const firstDow = new Date(y, m, 1).getDay();
    const lastDay = new Date(y, m + 1, 0).getDate();
    const today = new Date(); today.setHours(0, 0, 0, 0);

    const container = document.getElementById('calDays');
    container.innerHTML = '';

    const BASE_DAY_CLASSES = ['rounded-lg', 'border-0', 'bg-transparent', 'cursor-pointer', 'text-[13px]',
        'font-medium', 'text-[#0B0B0B]', 'flex', 'items-center', 'justify-center', 'transition-all',
        'relative', 'font-sans', 'aspect-square'];

    for (let i = 0; i < firstDow; i++) {
        const e = document.createElement('div');
        e.className = 'aspect-square invisible';
        container.appendChild(e);
    }

    for (let day = 1; day <= lastDay; day++) {
        const date = new Date(y, m, day);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.classList.add(...BASE_DAY_CLASSES);
        btn.textContent = day;

        const isStart = sameDay(date, cal.startDate);
        const isEnd = sameDay(date, cal.endDate);
        const isToday = sameDay(date, today);
        const isInRange = cal.startDate && cal.endDate && !isStart && !isEnd && date > cal.startDate && date < cal.endDate;

        if (isToday && !isStart && !isEnd) {
            btn.classList.add('font-bold', 'text-[#6B6B6B]');
            const dot = document.createElement('span');
            dot.className = 'absolute bottom-[3px] left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-[#6B6B6B]';
            btn.appendChild(dot);
        }

        if (isInRange) {
            btn.classList.add('bg-[#E2E2E2]', 'rounded-none');
        }

        if (isStart || isEnd) {
            btn.classList.add('bg-[#0B0B0B]', 'text-white', 'font-bold', 'z-10');
            if (isStart && isEnd) {
                btn.classList.add('rounded-lg');
            } else if (isStart) {
                btn.classList.add('rounded-l-lg', 'rounded-r-none');
            } else {
                btn.classList.add('rounded-r-lg', 'rounded-l-none');
            }
        } else {
            btn.classList.add('hover:bg-[#E2E2E2]');
        }

        btn.onclick = () => onDayClick(date);
        container.appendChild(btn);
    }
}

function onDayClick(date) {
    // Single-date mode: pick and auto-confirm
    if (cal._singleMode) {
        cal.startDate = date;
        cal.endDate = date;
        renderCal();
        updateStatus();
        confirmCalendar();
        return;
    }
    if (cal.picking === 'start') {
        cal.startDate = date;
        cal.endDate = null;
        cal.picking = 'end';
    } else {
        if (cal.startDate && date < cal.startDate) {
            // Clicked before start: restart from this date
            cal.startDate = date;
            cal.endDate = null;
            cal.picking = 'end';
        } else {
            // end >= start (same day allowed)
            cal.endDate = date;
            cal.picking = 'start'; // both set
        }
    }
    renderCal();
    updateStatus();
}

function setValState(el, filled) {
    el.classList.remove(...CS_VAL_FILLED, ...CS_VAL_EMPTY);
    el.classList.add(...(filled ? CS_VAL_FILLED : CS_VAL_EMPTY));
}

function updateStatus() {
    const csStart = document.getElementById('csStart');
    const csEnd = document.getElementById('csEnd');
    const hint = document.getElementById('calHint');
    const confirm = document.getElementById('calConfirm');

    if (cal.startDate) { csStart.textContent = fmt(cal.startDate); setValState(csStart, true); }
    else { csStart.textContent = 'Not selected'; setValState(csStart, false); }

    if (cal.endDate) { csEnd.textContent = fmt(cal.endDate); setValState(csEnd, true); }
    else { csEnd.textContent = 'Not selected'; setValState(csEnd, false); }

    if (!cal.startDate) {
        hint.innerHTML = 'Tap a date to set the <strong>start</strong>';
        confirm.disabled = true;
    } else if (!cal.endDate) {
        hint.innerHTML = 'Now tap the <strong>end</strong> date — same day is OK';
        confirm.disabled = true;
    } else {
        const d = daysBetween(cal.startDate, cal.endDate);
        hint.innerHTML = '<strong>' + d + ' day' + (d !== 1 ? 's' : '') + '</strong> selected \u2713';
        confirm.disabled = false;
    }
}

function changeMonth(delta) {
    cal.viewMonth += delta;
    if (cal.viewMonth > 11) { cal.viewMonth = 0; cal.viewYear++; }
    if (cal.viewMonth < 0) { cal.viewMonth = 11; cal.viewYear--; }
    renderCal();
}

function clearCalendar() {
    cal.startDate = null;
    cal.endDate = null;
    cal.picking = 'start';
    renderCal();
    updateStatus();
}

function confirmCalendar() {
    if (!cal.startDate || !cal.endDate) return;

    const startYMD = toYMD(cal.startDate);
    const endYMD = toYMD(cal.endDate);
    const days = daysBetween(cal.startDate, cal.endDate);
    const sg = cal.slugGroup;

    // ── Single-date mode (stage deadlines) ──
    if (cal._singleMode) {
        const slug = cal._singleSlug;
        const field = cal._singleField;
        const hiddenEl = document.getElementById('h_' + slug + '_' + field);
        if (hiddenEl) hiddenEl.value = startYMD;

        const datesEl = document.getElementById('rd-dates-' + slug + '-' + field);
        if (datesEl) {
            datesEl.classList.remove(...RD_DATES_EMPTY);
            datesEl.classList.add(...RD_DATES_FILLED);
            datesEl.innerHTML = '<span>' + fmt(cal.startDate) + '</span>';
        }
        document.getElementById('rd-' + slug + '-' + field)?.classList.add('bg-white');

        // Update duration display if both start and end are now set
        const startVal = document.getElementById('h_' + slug + '_start')?.value;
        const endVal = document.getElementById('h_' + slug + '_end')?.value;
        if (startVal && endVal) {
            const d1 = parseYMD(startVal), d2 = parseYMD(endVal);
            if (d2 >= d1) {
                const dur = daysBetween(d1, d2);
                const durEl = document.getElementById('sd-dur-' + slug);
                if (durEl) {
                    durEl.textContent = dur + ' day' + (dur !== 1 ? 's' : '');
                    durEl.classList.remove('hidden');
                    durEl.classList.add('flex', 'items-center', 'gap-1.5');
                }
            }
        }

        cal._singleMode = false;
        closeCalendar();
        return;
    }

    const phase = cal.phase;
    const ck = cal.calKey;
    const phaseColors = { fab: COLOR.fab, del: COLOR.del, ins: COLOR.ins, overall: COLOR.soft };
    const pColor = phaseColors[phase] || COLOR.soft;

    if (sg === 'overall') {
        document.getElementById('h_overall_start').value = startYMD;
        document.getElementById('h_overall_end').value = endYMD;

        const ord = document.getElementById('ord-overall');
        ord.classList.add('bg-white');
        ord.innerHTML =
            '<div class="flex flex-col gap-1">' +
            '<div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B]"><i class="fas fa-play-circle" style="color:#10b981;"></i> Start Date</div>' +
            '<div class="text-[17px] font-bold text-[#0B0B0B]" id="ord-start-overall">' + fmt(cal.startDate) + '</div>' +
            '</div>' +
            '<div class="text-[#6B6B6B] text-[22px]"><i class="fas fa-arrow-right"></i></div>' +
            '<div class="flex flex-col gap-1">' +
            '<div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B]"><i class="fas fa-stop-circle" style="color:#ef4444;"></i> End Date</div>' +
            '<div class="text-[17px] font-bold text-[#0B0B0B]" id="ord-end-overall">' + fmt(cal.endDate) + '</div>' +
            '</div>' +
            '<div class="bg-[#0B0B0B] text-white px-4 py-[5px] rounded-full text-xs font-bold ml-auto whitespace-nowrap" id="ord-dur-overall">' +
            '<i class="fas fa-clock"></i> ' + days + ' day' + (days !== 1 ? 's' : '') +
            '</div>';
    } else {
        document.getElementById('h_' + sg + '_' + phase + '_start').value = startYMD;
        document.getElementById('h_' + sg + '_' + phase + '_end').value = endYMD;

        const rdDates = document.getElementById('rd-dates-' + ck);
        rdDates.classList.remove(...RD_DATES_EMPTY);
        rdDates.classList.add(...RD_DATES_FILLED);
        rdDates.innerHTML =
            '<span>' + SMONTHS[cal.startDate.getMonth()] + ' ' + cal.startDate.getDate() + '</span>' +
            '<span class="text-[#6B6B6B] text-[11px]">\u2192</span>' +
            '<span>' + fmt(cal.endDate) + '</span>';

        const rdDur = document.getElementById('rd-dur-' + ck);
        rdDur.innerHTML = '<i class="fas fa-clock" style="color:' + pColor + ';"></i> ' + days + ' day' + (days !== 1 ? 's' : '');
        rdDur.classList.remove('hidden');
        document.getElementById('rd-' + ck).classList.add('bg-white');
    }

    closeCalendar();
}

function closeCalendar() { closeOverlayEl(document.getElementById('calOverlay')); }
function overlayClick(e) { if (e.target === document.getElementById('calOverlay')) closeCalendar(); }

function toggleArea(slug) {
    const body = document.getElementById('body-' + slug);
    const chev = document.getElementById('chev-' + slug);
    if (!body) return;
    const isOpen = !body.classList.contains('hidden');
    body.classList.toggle('hidden', isOpen);
    if (chev) chev.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function switchTab(tab) {
    ['settings', 'gantt'].forEach(t => {
        const panel = document.getElementById('panel-' + t);
        panel.classList.toggle('hidden', t !== tab);
        const tabBtn = document.getElementById('tab-' + t);
        const ACTIVE = ['bg-[#0B0B0B]', 'text-white', 'border-[#0B0B0B]'];
        const INACTIVE = ['bg-white', 'text-[#6B6B6B]'];
        if (t === tab) {
            tabBtn.classList.remove(...INACTIVE);
            tabBtn.classList.add(...ACTIVE);
        } else {
            tabBtn.classList.remove(...ACTIVE);
            tabBtn.classList.add(...INACTIVE);
        }
    });
}

// ── Single-date calendar (for stage deadlines) ──────────────────────
let singleCal = { slug: '', field: '', label: '', selectedDate: null, viewYear: 0, viewMonth: 0 };

function openSingleCal(slug, field, label) {
    singleCal.slug = slug;
    singleCal.field = field;
    singleCal.label = label;

    const hiddenId = 'h_' + slug + '_' + field;
    const existing = document.getElementById(hiddenId)?.value;
    singleCal.selectedDate = existing ? parseYMD(existing) : null;

    const nav = singleCal.selectedDate || new Date();
    singleCal.viewYear = nav.getFullYear();
    singleCal.viewMonth = nav.getMonth();

    // Hijack existing calendar overlay for single-date mode
    cal.slugGroup = '__single__';
    cal.phase = field;
    cal.label = label;
    cal.calKey = slug + '-' + field;
    cal.startDate = singleCal.selectedDate;
    cal.endDate = singleCal.selectedDate; // same = single day selection
    cal.picking = 'start';
    cal.viewYear = singleCal.viewYear;
    cal.viewMonth = singleCal.viewMonth;
    cal._singleMode = true;
    cal._singleSlug = slug;
    cal._singleField = field;

    document.getElementById('calTitleText').textContent = label;
    renderCal();
    updateStatus();
    openOverlay(document.getElementById('calOverlay'));
}

function validateOverall() {
    const s = document.getElementById('h_overall_start').value;
    const e = document.getElementById('h_overall_end').value;
    if (!s || !e) {
        alert('Please tap the timeline bar above to pick your start and end dates first.');
        return false;
    }
    return true;
}

let activeFilter = 'notset';

function setFilter(filter) {
    activeFilter = filter;

    const btnNotSet = document.getElementById('btn-filter-notset');
    const btnSet = document.getElementById('btn-filter-set');
    if (!btnNotSet || !btnSet) return;

    const ON = ['bg-[#0B0B0B]', 'border-[#0B0B0B]', 'text-white'];
    const OFF = ['bg-white', 'border-[#E2E2E2]', 'text-[#6B6B6B]'];

    if (filter === 'notset') {
        btnNotSet.classList.remove(...OFF);
        btnNotSet.classList.add(...ON);
        btnSet.classList.remove(...ON);
        btnSet.classList.add(...OFF);
    } else {
        btnSet.classList.remove(...OFF);
        btnSet.classList.add(...ON);
        btnNotSet.classList.remove(...ON);
        btnNotSet.classList.add(...OFF);
    }

    filterClients();
}

function filterClients() {
    const q = (document.getElementById('clientSearch')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.client-row');
    let visible = 0;
    rows.forEach(row => {
        const hay = row.getAttribute('data-search') || '';
        const tlStatus = row.getAttribute('data-timeline') || '';
        const matchSearch = !q || hay.includes(q);
        const matchFilter = tlStatus === activeFilter;
        const show = matchSearch && matchFilter;
        row.classList.toggle('hidden', !show);
        if (show) visible++;
    });
    const noResults = document.getElementById('noResults');
    if (noResults) noResults.classList.toggle('hidden', visible !== 0);
}

// ── Group Modal ──
// Track current group assignments in JS (seeded from PHP via window.CoordTimelineData)
let currentGroupLabels = (window.CoordTimelineData && window.CoordTimelineData.currentGroupLabels) || {};

function openGroupModal(existingLabel, existingAreas) {
    const removeMode = document.getElementById('modalRemoveChecked');
    if (removeMode) removeMode.checked = false;

    // Set label input
    document.getElementById('modalGroupLabelInput').value = existingLabel || '';

    // Show/hide area rows based on context:
    // - If editing existing group (existingLabel set): show only areas IN that group
    // - If adding new group: show only UNGROUPED areas
    document.querySelectorAll('#modalAreaList label').forEach(row => {
        const cb = row.querySelector('input[type=checkbox]');
        const area = cb ? cb.dataset.area : null;
        if (!area) return;

        const areaCurrentGroup = currentGroupLabels[area] || '';

        if (existingLabel) {
            // Edit mode: show only areas that belong to this group
            const inThisGroup = areaCurrentGroup === existingLabel;
            row.classList.toggle('hidden', !inThisGroup);
            cb.checked = inThisGroup;
        } else {
            // Add mode: show only ungrouped areas
            const isUngrouped = !areaCurrentGroup;
            row.classList.toggle('hidden', !isUngrouped);
            cb.checked = false;
        }
        syncRowStyle(cb.dataset.areahash);
    });

    // Show/hide the remove option — only relevant when editing
    const removeSection = document.getElementById('modalRemoveSection');
    if (removeSection) {
        removeSection.classList.toggle('hidden', !existingLabel);
    }

    // Update modal title
    const modalTitle = document.getElementById('modalTitleText');
    if (modalTitle) {
        modalTitle.textContent = existingLabel ? 'Edit Group: ' + existingLabel : 'Add New Group';
    }

    // Lock label input if editing (don't allow renaming group here)
    const labelInput = document.getElementById('modalGroupLabelInput');
    if (existingLabel) {
        labelInput.readOnly = true;
        labelInput.classList.add('bg-gray-100', 'text-gray-500');
    } else {
        labelInput.readOnly = false;
        labelInput.classList.remove('bg-gray-100', 'text-gray-500');
    }

    openOverlay(document.getElementById('groupModal'));
}

function closeGroupModal() {
    closeOverlayEl(document.getElementById('groupModal'));
}

function syncRowStyle(hash) {
    const cb = document.getElementById('modal_chk_' + hash);
    const row = document.getElementById('modal_row_' + hash);
    if (!cb || !row) return;
    const CHECKED = ['border-[#0B0B0B]', 'bg-[#fafafa]'];
    const UNCHECKED = ['border-[#E2E2E2]', 'bg-white'];
    if (cb.checked) {
        row.classList.remove(...UNCHECKED);
        row.classList.add(...CHECKED);
    } else {
        row.classList.remove(...CHECKED);
        row.classList.add(...UNCHECKED);
    }
}

function submitGroupModal() {
    const label = document.getElementById('modalGroupLabelInput').value.trim();
    const removeMode = document.getElementById('modalRemoveChecked').checked;
    const checked = [...document.querySelectorAll('#modalAreaList input[type=checkbox]:checked')];

    if (checked.length === 0) {
        alert('Please check at least one area.');
        return;
    }
    if (!removeMode && !label) {
        alert('Please enter a group label.');
        return;
    }

    // Get ALL visible area rows in the modal (only those shown for this edit)
    const allVisible = [...document.querySelectorAll('#modalAreaList input[type=checkbox]')]
        .filter(cb => !document.getElementById('modal_row_' + cb.dataset.areahash)?.classList.contains('hidden'));

    allVisible.forEach(cb => {
        const area = cb.dataset.area;
        const hash = cb.dataset.areahash;
        const hiddenInput = document.getElementById('modal_gl_' + hash);
        const curDiv = document.getElementById('modal_cur_' + hash);

        if (removeMode) {
            // Remove mode: clear all visible (they're all in this group)
            if (hiddenInput) hiddenInput.value = '';
            currentGroupLabels[area] = '';
            if (curDiv) curDiv.innerHTML = 'No group assigned';
        } else if (cb.checked) {
            // Checked: assign to this group
            if (hiddenInput) hiddenInput.value = label;
            currentGroupLabels[area] = label;
            if (curDiv) curDiv.innerHTML = '<i class="fas fa-tag"></i> Currently: <strong>' + label + '</strong>';
        } else {
            // Unchecked: remove from group
            if (hiddenInput) hiddenInput.value = '';
            currentGroupLabels[area] = '';
            if (curDiv) curDiv.innerHTML = 'No group assigned';
        }
    });

    document.getElementById('groupModalForm').submit();
}

// Delegate edit button clicks via data attributes
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.edit-group-btn');
    if (!btn) return;
    const label = btn.getAttribute('data-label');
    const areas = JSON.parse(btn.getAttribute('data-areas') || '[]');
    openGroupModal(label, areas);
});

// Close on backdrop click — only attach if modal exists on this page
const _groupModal = document.getElementById('groupModal');
if (_groupModal) {
    _groupModal.addEventListener('click', function (e) {
        if (e.target === this) closeGroupModal();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.CoordTimelineData && !window.CoordTimelineData.hasClientId) {
        setFilter('notset');
    }
});