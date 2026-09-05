<?php
// esign_modal.php
// Place at: realiving_admin/tracker_management/esign_modal.php
// Include this at the bottom of stage_files.php before </body>
?>

<!-- ═══════════════════════════════════════════════════════════════
     E-SIGN MODAL
════════════════════════════════════════════════════════════════ -->
<style>
    @keyframes esignPopIn {
        from { transform: scale(.96); opacity: 0; }
        to   { transform: scale(1);   opacity: 1; }
    }
    #esignModal.flex .esign-box {
        animation: esignPopIn .2s ease both;
    }
</style>

<div id="esignModal" class="hidden fixed inset-0 bg-black/50 z-[1000] items-center justify-center p-4">
    <div class="esign-box bg-white rounded-2xl shadow-2xl w-full max-w-[960px] max-h-[95vh] p-5 flex flex-col gap-3.5">

        <!-- Header -->
        <div class="flex justify-between items-start flex-shrink-0">
            <div>
                <div class="text-[17px] font-bold text-[#0B0B0B] flex items-center gap-2">
                    <i class="fas fa-file-signature"></i> Approve File
                </div>
                <div class="text-[13px] text-[#6B6B6B] mt-0.5">Optionally place your e-signature on the PDF before
                    approving.</div>
            </div>
            <button onclick="closeEsignModal()"
                class="bg-transparent border-none cursor-pointer text-xl text-[#9A9A9A] hover:text-[#0B0B0B] px-2 py-1 flex-shrink-0 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Toggle -->
        <div
            class="flex items-center gap-3 bg-[#F5F5F5] border border-[#E2E2E2] rounded-[10px] px-4 py-3 flex-shrink-0">
            <label for="esignToggle"
                class="flex-1 text-[13px] font-bold text-[#0B0B0B] cursor-pointer flex items-center gap-2 m-0">
                <i class="fas fa-pen-nib text-[#6B6B6B]"></i>
                Add my E-Signature to this PDF
            </label>
            <!-- Toggle switch -->
            <div class="relative w-11 h-6 flex-shrink-0">
                <input type="checkbox" id="esignToggle" class="opacity-0 w-0 h-0 absolute"
                    onchange="onEsignToggle(this.checked)">
                <div id="toggleSlider" onclick="document.getElementById('esignToggle').click();"
                    class="absolute inset-0 bg-[#ccc] rounded-full cursor-pointer transition-colors duration-300">
                    <div id="toggleThumb"
                        class="absolute w-[18px] h-[18px] bg-white rounded-full top-[3px] left-[3px] shadow-[0_1px_3px_rgba(0,0,0,.2)] transition-transform duration-300">
                    </div>
                </div>
            </div>
        </div>

        <!-- PDF Viewer iframe (shown when toggle ON) -->
        <div id="esignIframeWrap"
            class="hidden flex-1 min-h-[420px] rounded-[10px] overflow-hidden border-2 border-[#E2E2E2] flex-shrink-0">
            <iframe id="esignIframe" src="" class="w-full h-full min-h-[420px] border-none block"
                title="PDF E-Signature Viewer">
            </iframe>
        </div>

        <!-- Status bar — shows after placement confirmed -->
        <div id="esignStatusBar"
            class="hidden bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-2.5 items-center gap-2.5 flex-shrink-0">
            <i class="fas fa-check-circle text-emerald-700"></i>
            <span class="text-[13px] text-emerald-700 font-semibold flex-1">
                Signature placed on page <strong id="esignPlacedPage">1</strong>. Click "Confirm Approval" to proceed.
            </span>
            <button
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold bg-white text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors"
                onclick="resetEsignPlacement()">
                <i class="fas fa-redo"></i> Reposition
            </button>
        </div>

        <!-- Action buttons -->
        <div class="flex justify-end gap-2 flex-shrink-0">
            <button onclick="closeEsignModal()"
                class="bg-[#F5F5F5] text-[#6B6B6B] px-4 py-2 rounded-md cursor-pointer font-semibold text-[13px] hover:bg-[#E2E2E2] transition-colors border border-[#E2E2E2]">
                Cancel
            </button>
            <button id="esignSubmitBtn" onclick="submitApproval()"
                class="inline-flex items-center gap-2 bg-[#0B0B0B] text-white px-4 py-2 rounded-md cursor-pointer font-semibold text-[13px] hover:bg-[#2a2a2a] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-check-circle"></i> Confirm Approval
            </button>
        </div>
    </div>
</div>

<script>
// ── E-sign modal state ────────────────────────────────────────────
let _currentApprovalId = null;
let _signX    = null;
let _signY    = null;
let _signWPct = null;
let _signHPct = null;
let _signPage = 1;

// ── Open modal ────────────────────────────────────────────────────
function approveFile(approvalId) {
    _currentApprovalId = approvalId;
    _signX = null; _signY = null; _signPage = 1;

    // Reset UI
    document.getElementById('esignToggle').checked = false;
    document.getElementById('esignIframeWrap').classList.add('hidden');
    document.getElementById('esignStatusBar').classList.add('hidden');
    document.getElementById('esignStatusBar').classList.remove('flex');
    document.getElementById('toggleSlider').classList.remove('bg-[#0B0B0B]');
    document.getElementById('toggleSlider').classList.add('bg-[#ccc]');
    document.getElementById('toggleThumb').style.transform = 'translateX(0)';

    const btn = document.getElementById('esignSubmitBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';

    const modal = document.getElementById('esignModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

// ── Toggle e-sign section ─────────────────────────────────────────
function onEsignToggle(checked) {
    const slider = document.getElementById('toggleSlider');
    const thumb  = document.getElementById('toggleThumb');
    const wrap   = document.getElementById('esignIframeWrap');

    if (checked) {
        slider.classList.remove('bg-[#ccc]');
        slider.classList.add('bg-[#0B0B0B]');
        thumb.style.transform = 'translateX(20px)';
        wrap.classList.remove('hidden');

        // Load iframe with PDF viewer
        const iframe = document.getElementById('esignIframe');
        iframe.src   = '<?= BASE_URL ?>esign-pdf-viewer?approval_id=' + _currentApprovalId;

        // Reset placement
        _signX = null; _signY = null;
        document.getElementById('esignStatusBar').classList.add('hidden');
        document.getElementById('esignStatusBar').classList.remove('flex');
    } else {
        slider.classList.remove('bg-[#0B0B0B]');
        slider.classList.add('bg-[#ccc]');
        thumb.style.transform = 'translateX(0)';
        wrap.classList.add('hidden');
        _signX = null; _signY = null;
        document.getElementById('esignStatusBar').classList.add('hidden');
        document.getElementById('esignStatusBar').classList.remove('flex');
    }
}

// ── Receive placement from iframe ─────────────────────────────────
function receiveEsignPlacement(data) {
    _signX    = data.x_pct;
    _signY    = data.y_pct;
    _signWPct = data.w_pct;
    _signHPct = data.h_pct;
    _signPage = data.page;

    // Show status bar
    const bar = document.getElementById('esignStatusBar');
    document.getElementById('esignPlacedPage').textContent = _signPage;
    bar.classList.remove('hidden');
    bar.classList.add('flex');

    // Scroll modal to bottom so user sees Confirm button
    const modalBox = document.querySelector('#esignModal .esign-box');
    if (modalBox) modalBox.scrollTop = modalBox.scrollHeight;
}

// ── Close iframe viewer from iframe ──────────────────────────────
function closeEsignViewer() {
    document.getElementById('esignToggle').checked = false;
    onEsignToggle(false);
}

// ── Reset placement ───────────────────────────────────────────────
function resetEsignPlacement() {
    _signX = null; _signY = null;
    document.getElementById('esignStatusBar').classList.add('hidden');
    document.getElementById('esignStatusBar').classList.remove('flex');
    // Reload iframe
    const iframe = document.getElementById('esignIframe');
    iframe.src   = '<?= BASE_URL ?>esign-pdf-viewer?approval_id=' + _currentApprovalId;
}

// ── Submit approval ───────────────────────────────────────────────
async function submitApproval() {
    const applySign = document.getElementById('esignToggle').checked;

    if (applySign && (_signX === null || _signY === null)) {
        toast('Please place your signature on the PDF first, then click "Confirm Placement".', true);
        return;
    }

    const btn = document.getElementById('esignSubmitBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    try {
        const res  = await fetch('<?= BASE_URL ?>apply-esign', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                approval_id: _currentApprovalId,
                action:      'approved',
                note:        '',
                apply_sign:  applySign,
                sign_x_pct:  _signX   ?? 0,
                sign_y_pct:  _signY   ?? 0,
                sign_w_pct:  _signWPct ?? 20,
                sign_h_pct:  _signHPct ?? 8,
                sign_page:   _signPage,
            })
        });
        const data = await res.json();

        if (data.success) {
            closeEsignModal();
            toast('File approved!' + (applySign ? ' E-signature applied ✓' : ''));
            setTimeout(() => location.reload(), 1200);
        } else {
            toast('Error: ' + (data.error || 'Failed'), true);
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';
        }
    } catch(e) {
        toast('Connection error. Please try again.', true);
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';
    }
}

// ── Close modal ───────────────────────────────────────────────────
function closeEsignModal() {
    const modal = document.getElementById('esignModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    _currentApprovalId = null;
    _signX = null; _signY = null;
    // Unload iframe to stop PDF.js
    document.getElementById('esignIframe').src = '';
}

// Close on overlay click
document.getElementById('esignModal').addEventListener('click', function(e) {
    if (e.target === this) closeEsignModal();
});
</script>