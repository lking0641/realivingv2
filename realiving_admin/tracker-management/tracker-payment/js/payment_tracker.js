// payment_tracker.js
// Config is injected by PHP as window.PAYMENT_TRACKER_CONFIG (see payment_tracker.php)
const CFG = window.PAYMENT_TRACKER_CONFIG || {};
const BASE_URL = CFG.baseUrl;
const CLIENT_ID = CFG.clientId;
const TOTAL_COST = CFG.totalCost;
const REMAINING_BAL = CFG.remainingBal;
const SUGGESTED = CFG.suggested;
const SNAPSHOT_PCT = CFG.snapshotPct;

// ── Amount input override hint ──
function onAmountInput(el) {
    const hint = document.getElementById('overrideHint');
    const v = parseFloat(el.value);
    if (!v || Math.abs(v - SUGGESTED) < 0.01) {
        hint.innerHTML = '';
        el.classList.remove('overridden');
    } else {
        el.classList.add('overridden');
        const diff = v - SUGGESTED;
        const sign = diff > 0 ? '+' : '';
        hint.innerHTML = '<span style="color:#f59e0b;font-weight:700;"><i class="fas fa-pen"></i> Overriding suggested amount (' + sign + '&#8369;' + Math.abs(diff).toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ')</span>';
    }
}
function resetSuggested() {
    const el = document.getElementById('newCollAmount');
    el.value = SUGGESTED;
    el.classList.remove('overridden');
    document.getElementById('overrideHint').innerHTML = '';
}

// ── Add new collection ──
async function submitNewCollection() {
    const label = document.getElementById('newCollLabel').value.trim();
    const amount = parseFloat(document.getElementById('newCollAmount').value);
    const errDiv = document.getElementById('addCollErr');
    errDiv.style.display = 'none';

    if (!label) { errDiv.textContent = 'Please enter a billing label.'; errDiv.style.display = 'block'; return; }
    if (!amount || amount <= 0) { errDiv.textContent = 'Please enter a valid amount greater than 0.'; errDiv.style.display = 'block'; return; }

    const btn = document.getElementById('addCollBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const res = await fetch(BASE_URL + 'add-collection-billing', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                client_id: CLIENT_ID,
                label: label,
                amount: amount,
                total_cost: TOTAL_COST,
                remaining_bal: REMAINING_BAL,
                snapshot_pct: SNAPSHOT_PCT
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Billing collection saved!', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            errDiv.textContent = data.error || 'Failed to save.';
            errDiv.style.display = 'block';
        }
    } catch (e) {
        errDiv.textContent = 'Network error. Please try again.';
        errDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Billing Entry';
    }
}

// ── Confirm / Mark as Paid ──
function openConfirm(id, label, amount) {
    document.getElementById('confirmId').value = id;
    document.getElementById('confirmMsg').innerHTML =
        'Mark <strong>' + label + '</strong> (&#8369;' +
        parseFloat(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 }) +
        ') as <strong style="color:#059669;">Paid</strong>?';
    document.getElementById('confirmModal').classList.add('open');
}
function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
}
async function doMarkPaid() {
    const id = document.getElementById('confirmId').value;
    closeConfirm();
    try {
        const res = await fetch(BASE_URL + 'update-payment-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: parseInt(id), status: 'Paid', client_id: CLIENT_ID })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Payment marked as paid!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

// ── Edit amount modal ──
function openEditModal(id, amount, label) {
    document.getElementById('editId').value = id;
    document.getElementById('editAmt').value = amount;
    document.getElementById('editModalLabel').textContent = label;
    document.getElementById('editErr').style.display = 'none';
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
async function submitEdit() {
    const id = document.getElementById('editId').value;
    const amount = parseFloat(document.getElementById('editAmt').value);
    const errDiv = document.getElementById('editErr');
    errDiv.style.display = 'none';
    if (!amount || amount <= 0) {
        errDiv.textContent = 'Please enter a valid amount.';
        errDiv.style.display = 'block';
        return;
    }
    try {
        const res = await fetch(BASE_URL + 'update-accomplishment-amount', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: parseInt(id), amount: amount, total_cost: TOTAL_COST })
        });
        const data = await res.json();
        if (data.success) {
            closeEditModal();
            showToast('Amount updated!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            errDiv.textContent = data.error || 'Update failed.';
            errDiv.style.display = 'block';
        }
    } catch (e) {
        errDiv.textContent = 'Network error.';
        errDiv.style.display = 'block';
    }
}

// Close modals on backdrop click
document.addEventListener('click', e => {
    if (e.target.id === 'confirmModal') closeConfirm();
    if (e.target.id === 'editModal') closeEditModal();
});

// ── Proof upload ──
function previewProof(input, paymentId) {
    const img = document.getElementById('proofImg_' + paymentId);
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                img.src = e.target.result;
                img.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            img.style.display = 'none';
        }
    }
}

async function submitProof(paymentId, clientId) {
    const input = document.getElementById('proofFile_' + paymentId);
    if (!input || !input.files || !input.files[0]) {
        showToast('Please select a file first.', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('payment_id', paymentId);
    formData.append('client_id', clientId);
    formData.append('proof_file', input.files[0]);

    try {
        const res = await fetch(BASE_URL + 'upload-payment-proof', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Proof submitted! Awaiting accounting review.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.error || 'Upload failed.', 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

// ── Accounting approve/reject ──
let pendingApprovePaymentId = null;
let pendingApproveClientId = null;

let ntpSubmitting = false; // guard flag
let ntpMode = 'approve'; // 'approve' or 'update'

// Quick approve WITHOUT NTP requirement (for collections & non-project payments)
let pendingQuickApproveId = null;

function quickApprove(paymentId) {
    pendingQuickApproveId = paymentId;
    document.getElementById('quickApproveModal').classList.add('open');
}

function closeQuickApproveModal() {
    document.getElementById('quickApproveModal').classList.remove('open');
    pendingQuickApproveId = null;
    const btn = document.querySelector('#quickApproveModal .btn-green');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid';
    }
}

async function doQuickApprove() {
    const paymentId = pendingQuickApproveId;
    if (!paymentId) return;

    const btn = document.querySelector('#quickApproveModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...'; }

    try {
        const res = await fetch(BASE_URL + 'check-ipo-approved?client_id=' + CLIENT_ID);

        if (!res.ok) {
            throw new Error('Server returned HTTP ' + res.status);
        }

        const data = await res.json();

        if (!data.approved) {
            showToast('Cannot approve: "Internal P.O to Accounting" stage must be fully approved first.', 'error');
            closeQuickApproveModal();
            return;
        }
    } catch (e) {
        console.error('IPO verification failed:', e);
        showToast('Could not verify Internal P.O status — please refresh and try again.', 'error');
        closeQuickApproveModal();
        return;
    }

    try {
        const res = await fetch(BASE_URL + 'review-payment-proof', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: paymentId, action: 'approve' })
        });
        const data = await res.json();
        if (data.success) {
            closeQuickApproveModal();
            showToast('Payment approved and marked paid!', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid'; }
        }
    } catch (e) {
        showToast('Network error.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid'; }
    }
}

let pendingToggleAction = null;

function openToggleConfirm(action) {
    pendingToggleAction = action;
    const msg = document.getElementById('toggleConfirmMsg');
    if (action === 'merge') {
        msg.innerHTML = 'Switch this client\'s remaining balance to a single <strong>50% Retention</strong> payment? The current 40% Before Installation and 10% After Installation stages will be merged.';
    } else {
        msg.innerHTML = 'Revert this client back to the <strong>40% Before Installation / 10% After Installation</strong> split?';
    }
    document.getElementById('toggleConfirmModal').classList.add('open');
}

function closeToggleConfirm() {
    document.getElementById('toggleConfirmModal').classList.remove('open');
    pendingToggleAction = null;
}

async function doToggleSplit() {
    if (!pendingToggleAction) return;
    const btn = document.querySelector('#toggleConfirmModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }

    try {
        const res = await fetch(BASE_URL + 'toggle-payment-split', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ client_id: CLIENT_ID, action: pendingToggleAction })
        });
        const data = await res.json();
        if (data.success) {
            closeToggleConfirm();
            showToast('Payment split updated!', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            showToast(data.error || 'Failed to update split.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Continue'; }
        }
    } catch (e) {
        showToast('Network error.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Continue'; }
    }
}

async function approvePayment(paymentId) {
    try {
        const res = await fetch(BASE_URL + 'check-ipo-approved?client_id=' + CLIENT_ID);

        if (!res.ok) {
            throw new Error('Server returned HTTP ' + res.status);
        }

        const data = await res.json();

        if (!data.approved) {
            showToast('Cannot approve: "Internal P.O to Accounting" stage must be fully approved first.', 'error');
            return;
        }
    } catch (e) {
        console.error('IPO verification failed:', e);
        showToast('Could not verify Internal P.O status — please refresh and try again.', 'error');
        return;
    }

    ntpSubmitting = false;
    ntpMode = 'approve'; // set mode
    pendingApprovePaymentId = paymentId;
    pendingApproveClientId = CLIENT_ID;
    document.getElementById('ntpPaymentId').value = paymentId;
    document.getElementById('ntpClientId').value = CLIENT_ID;
    document.getElementById('ntpErr').style.display = 'none';
    document.getElementById('ntpFile').value = '';
    document.getElementById('ntpNotes').value = '';
    document.getElementById('ntpPreview').style.display = 'none';
    document.getElementById('ntpModal').classList.add('open');
}

async function doApproveWithNTP() {
    if (ntpSubmitting) return; // prevent double-submit
    ntpSubmitting = true;

    const paymentId = pendingApprovePaymentId;
    const clientId = pendingApproveClientId;
    const errDiv = document.getElementById('ntpErr');
    errDiv.style.display = 'none';

    const btn = document.querySelector('#ntpModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...'; }

    const fileInput = document.getElementById('ntpFile');
    if (!fileInput.files || !fileInput.files[0]) {
        errDiv.textContent = 'NTP file is required. Please attach the NTP document.';
        errDiv.style.display = 'block';
        ntpSubmitting = false;
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = ntpMode === 'update'
                ? '<i class="fas fa-upload"></i> Upload New NTP'
                : '<i class="fas fa-check"></i> Approve &amp; Upload NTP';
        }
        return;
    }

    // Step 1: Upload NTP first
    const notes = document.getElementById('ntpNotes').value.trim();
    const formData = new FormData();
    formData.append('payment_id', paymentId);
    formData.append('client_id', clientId);
    formData.append('notes', notes);
    formData.append('ntp_file', fileInput.files[0]);

    try {
        const res = await fetch(BASE_URL + 'upload-ntp', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) {
            errDiv.textContent = data.error || 'NTP upload failed. Please try again.';
            errDiv.style.display = 'block';
            ntpSubmitting = false;
            if (btn) { btn.disabled = false; btn.innerHTML = ntpMode === 'update' ? '<i class="fas fa-upload"></i> Upload New NTP' : '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
            return;
        }
    } catch (e) {
        errDiv.textContent = 'Network error during NTP upload.';
        errDiv.style.display = 'block';
        ntpSubmitting = false;
        if (btn) { btn.disabled = false; btn.innerHTML = ntpMode === 'update' ? '<i class="fas fa-upload"></i> Upload New NTP' : '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
        return;
    }

    // Step 2: Skip approval if this is just an NTP update
    if (ntpMode === 'update') {
        closeNTPModal();
        showToast('NTP updated successfully!', 'success');
        setTimeout(() => location.reload(), 1200);
        return;
    }

    try {
        const res = await fetch(BASE_URL + 'review-payment-proof', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: paymentId, action: 'approve' })
        });
        const data = await res.json();
        if (!data.success) {
            errDiv.textContent = data.error || 'Approval failed after NTP upload.';
            errDiv.style.display = 'block';
            ntpSubmitting = false;
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
            return;
        }
    } catch (e) {
        errDiv.textContent = 'Network error during approval.';
        errDiv.style.display = 'block';
        ntpSubmitting = false;
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
        return;
    }

    closeNTPModal();
    showToast('Payment approved and NTP uploaded!', 'success');
    setTimeout(() => location.reload(), 1200);
}

function openUpdateNTP(paymentId) {
    ntpSubmitting = false;
    ntpMode = 'update'; // set mode
    pendingApprovePaymentId = paymentId;
    pendingApproveClientId = CLIENT_ID;
    document.getElementById('ntpPaymentId').value = paymentId;
    document.getElementById('ntpClientId').value = CLIENT_ID;
    document.getElementById('ntpErr').style.display = 'none';
    document.getElementById('ntpFile').value = '';
    document.getElementById('ntpNotes').value = '';
    document.getElementById('ntpPreview').style.display = 'none';

    // Change modal title/button for update mode
    document.querySelector('#ntpModal h3').innerHTML = '<i class="fas fa-sync-alt" style="color:#3b82f6;"></i> Update Notice to Proceed (NTP)';
    document.querySelector('#ntpModal .modal-sub').innerHTML = 'Upload a new NTP file to replace the current one.';
    document.querySelector('#ntpModal .btn-green').innerHTML = '<i class="fas fa-upload"></i> Upload New NTP';
    document.getElementById('ntpModal').classList.add('open');
}

function closeNTPModal() {
    document.getElementById('ntpModal').classList.remove('open');
    pendingApprovePaymentId = null;
    pendingApproveClientId = null;
    ntpSubmitting = false;
    ntpMode = 'approve'; // reset mode
    // Reset modal title back to default
    document.querySelector('#ntpModal h3').innerHTML = '<i class="fas fa-file-signature" style="color:#059669;"></i> Upload Notice to Proceed (NTP)';
    document.querySelector('#ntpModal .modal-sub').innerHTML = 'An NTP file is <strong style="color:#ef4444;">required</strong> before this payment can be approved. Please attach the NTP document below.';
    document.querySelector('#ntpModal .btn-green').innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP';
}

function openRejectModal(paymentId) {
    document.getElementById('rejectPaymentId').value = paymentId;
    document.getElementById('rejectNote').value = '';
    document.getElementById('rejectErr').style.display = 'none';
    document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}
async function submitReject() {
    const paymentId = document.getElementById('rejectPaymentId').value;
    const note = document.getElementById('rejectNote').value.trim();
    const errDiv = document.getElementById('rejectErr');
    errDiv.style.display = 'none';
    if (!note) {
        errDiv.textContent = 'Please enter a rejection reason.';
        errDiv.style.display = 'block';
        return;
    }
    try {
        const res = await fetch(BASE_URL + 'review-payment-proof', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: parseInt(paymentId), action: 'reject', rejection_note: note })
        });
        const data = await res.json();
        if (data.success) {
            closeRejectModal();
            showToast('Proof rejected. Submitter will be notified.', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            errDiv.textContent = data.error || 'Failed.';
            errDiv.style.display = 'block';
        }
    } catch (e) {
        errDiv.textContent = 'Network error.';
        errDiv.style.display = 'block';
    }
}

// Close modals on backdrop
document.addEventListener('click', e => {
    if (e.target.id === 'rejectModal') closeRejectModal();
    if (e.target.id === 'ntpModal') closeNTPModal();
    if (e.target.id === 'quickApproveModal') closeQuickApproveModal();
    if (e.target.id === 'toggleConfirmModal') closeToggleConfirm();
});

function previewNTP(input) {
    const img = document.getElementById('ntpPreview');
    if (input.files && input.files[0] && input.files[0].type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    } else {
        img.style.display = 'none';
    }
}

// ── Lightbox ──
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightboxOverlay').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('lightboxOverlay').classList.contains('open')) { closeLightbox(); return; }
    if (document.getElementById('ntpModal').classList.contains('open')) { closeNTPModal(); return; }
    if (document.getElementById('rejectModal').classList.contains('open')) { closeRejectModal(); return; }
    if (document.getElementById('editModal').classList.contains('open')) { closeEditModal(); return; }
    if (document.getElementById('confirmModal').classList.contains('open')) { closeConfirm(); return; }
    if (document.getElementById('quickApproveModal').classList.contains('open')) { closeQuickApproveModal(); return; }
    if (document.getElementById('toggleConfirmModal').classList.contains('open')) { closeToggleConfirm(); return; }
});

// ── Toast ──
function showToast(msg, type) {
    const t = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast show ' + type;
    icon.style.color = type === 'success' ? '#10b981' : '#ef4444';
    icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
    setTimeout(() => t.classList.remove('show'), 3000);
}