<?php
// esign_modal.php
// Place at: realiving_admin/tracker_management/esign_modal.php
// Include this at the bottom of stage_files.php before </body>
?>

<!-- ═══════════════════════════════════════════════════════════════
     E-SIGN MODAL
════════════════════════════════════════════════════════════════ -->
<div id="esignModal" class="modal-overlay">
    <div class="modal-box" style="max-width:960px; width:98%; padding:20px; max-height:95vh; display:flex; flex-direction:column; gap:14px;">

        <!-- Header -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-shrink:0;">
            <div>
                <div class="modal-title"><i class="fas fa-file-signature" style="color:#3b1f0f;"></i> Approve File</div>
                <div class="modal-sub" style="margin:2px 0 0;">Optionally place your e-signature on the PDF before approving.</div>
            </div>
            <button onclick="closeEsignModal()" style="background:none;border:none;cursor:pointer;font-size:20px;color:#9c7b6a;padding:4px 8px;flex-shrink:0;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Toggle -->
        <div style="display:flex; align-items:center; gap:12px; background:#faf8f5; border:1px solid #e2d9ce; border-radius:10px; padding:12px 16px; flex-shrink:0;">
            <label style="margin:0; flex:1; font-size:13px; font-weight:700; color:#5c4033; cursor:pointer; display:flex; align-items:center; gap:8px;" for="esignToggle">
                <i class="fas fa-pen-nib" style="color:#7a4528;"></i>
                Add my E-Signature to this PDF
            </label>
            <!-- Toggle switch -->
            <div style="position:relative; width:44px; height:24px; flex-shrink:0;">
                <input type="checkbox" id="esignToggle" style="opacity:0;width:0;height:0;position:absolute;"
                       onchange="onEsignToggle(this.checked)">
                <div id="toggleSlider" onclick="document.getElementById('esignToggle').click();"
                     style="position:absolute;inset:0;background:#ccc;border-radius:24px;cursor:pointer;transition:.3s;">
                    <div id="toggleThumb" style="position:absolute;width:18px;height:18px;background:#fff;border-radius:50%;top:3px;left:3px;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.2);"></div>
                </div>
            </div>
        </div>

        <!-- PDF Viewer iframe (shown when toggle ON) -->
        <div id="esignIframeWrap" style="display:none; flex:1; min-height:420px; border-radius:10px; overflow:hidden; border:2px solid #e2d9ce; flex-shrink:0;">
            <iframe id="esignIframe"
                    src=""
                    style="width:100%; height:100%; min-height:420px; border:none; display:block;"
                    title="PDF E-Signature Viewer">
            </iframe>
        </div>

        <!-- Status bar — shows after placement confirmed -->
        <div id="esignStatusBar" style="display:none; background:#d1fae5; border:1px solid #a7f3d0; border-radius:8px; padding:10px 16px; align-items:center; gap:10px; flex-shrink:0;">
            <i class="fas fa-check-circle" style="color:#065f46;"></i>
            <span style="font-size:13px; color:#065f46; font-weight:600; flex:1;">
                Signature placed on page <strong id="esignPlacedPage">1</strong>. Click "Confirm Approval" to proceed.
            </span>
            <button class="btn" style="background:#fff;color:#065f46;border:1px solid #a7f3d0;font-size:11px;" onclick="resetEsignPlacement()">
                <i class="fas fa-redo"></i> Reposition
            </button>
        </div>

        <!-- Action buttons -->
        <div style="display:flex; justify-content:flex-end; gap:8px; flex-shrink:0;">
            <button class="btn-cancel" onclick="closeEsignModal()">Cancel</button>
            <button id="esignSubmitBtn" class="btn-submit"
                    onclick="submitApproval()"
                    style="background:linear-gradient(135deg,#3b1f0f,#7a4528);">
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
    document.getElementById('esignToggle').checked    = false;
    document.getElementById('esignIframeWrap').style.display = 'none';
    document.getElementById('esignStatusBar').style.display  = 'none';
    document.getElementById('toggleSlider').style.background = '#ccc';
    document.getElementById('toggleThumb').style.transform   = 'translateX(0)';

    const btn = document.getElementById('esignSubmitBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';

    document.getElementById('esignModal').classList.add('show');
}

// ── Toggle e-sign section ─────────────────────────────────────────
function onEsignToggle(checked) {
    const slider = document.getElementById('toggleSlider');
    const thumb  = document.getElementById('toggleThumb');
    const wrap   = document.getElementById('esignIframeWrap');

    if (checked) {
        slider.style.background    = '#3b1f0f';
        thumb.style.transform      = 'translateX(20px)';
        wrap.style.display         = 'block';

        // Load iframe with PDF viewer
        const iframe = document.getElementById('esignIframe');
        iframe.src   = '<?= BASE_URL ?>esign-pdf-viewer?approval_id=' + _currentApprovalId;

        // Reset placement
        _signX = null; _signY = null;
        document.getElementById('esignStatusBar').style.display = 'none';
    } else {
        slider.style.background = '#ccc';
        thumb.style.transform   = 'translateX(0)';
        wrap.style.display      = 'none';
        _signX = null; _signY = null;
        document.getElementById('esignStatusBar').style.display = 'none';
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
    bar.style.display = 'flex';

    // Scroll modal to bottom so user sees Confirm button
    const modalBox = document.querySelector('#esignModal .modal-box');
    if (modalBox) modalBox.scrollTop = modalBox.scrollHeight;
}

// ── Close iframe viewer from iframe ──────────────────────────────
function closeEsignViewer() {
    document.getElementById('esignToggle').checked    = false;
    onEsignToggle(false);
}

// ── Reset placement ───────────────────────────────────────────────
function resetEsignPlacement() {
    _signX = null; _signY = null;
    document.getElementById('esignStatusBar').style.display = 'none';
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
    document.getElementById('esignModal').classList.remove('show');
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