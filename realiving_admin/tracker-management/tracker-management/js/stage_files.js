// stage-files.js
// Relies on these globals being defined BEFORE this file is loaded
// (see the small inline <script> block in stage_files.php):
//   STAGE_ID, CLIENT_ID, STAGE_NAME, BASE_URL_JS, IS_APPROVAL, UPLOAD_BTN_ICON_LABEL

// ── Upload mode helpers ──────────────────────────────────────────
function onUploadModeChange() {
    const isChunk = document.getElementById('uploadModeToggle').checked;
    const label = document.getElementById('uploadModeLabel');
    const hint = document.getElementById('uploadFileHint');
    if (isChunk) {
        label.innerHTML = `<span class="mode-badge chunked"><i class="fas fa-layer-group"></i> Chunked</span>
    <span style="font-size:11px;color:var(--text-lt);margin-left:4px;">For large files up to 1.3GB · slower start</span>`;
        hint.textContent = 'PDF, Word, Excel, PowerPoint, Images, Video · Max 1.3GB (Chunked mode)';
    } else {
        label.innerHTML = `<span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
    <span style="font-size:11px;color:var(--text-lt);margin-left:4px;">Best for files under 50MB · faster, no 405 errors</span>`;
        hint.textContent = 'PDF, Word, Excel, PowerPoint, Images, Video · Max 50MB (Direct) or 1.3GB (Chunked)';
    }
}

function autoSuggestUploadMode(input) {
    const file = input.files[0];
    if (!file) return;
    const toggle = document.getElementById('uploadModeToggle');
    const DIRECT_LIMIT = 50 * 1024 * 1024; // 50MB
    const wasChunk = toggle.checked;
    toggle.checked = file.size > DIRECT_LIMIT;
    if (toggle.checked !== wasChunk) onUploadModeChange();
}

function directUpload(file, label, stageId, clientId, stageName, progressBar, progressPct, progressLabel, progressSub, errEl, btn, btnOrigHTML) {
    if (file.size > 50 * 1024 * 1024) {
        errEl.textContent = 'Direct upload is limited to 50MB. Please switch to Chunked mode for larger files.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = btnOrigHTML;
        return;
    }

    // Shimmer progress
    progressBar.style.width = '100%';
    progressBar.style.transition = 'none';
    progressBar.style.background = 'repeating-linear-gradient(90deg,#3b1f0f 0px,#c49a78 20px,#3b1f0f 40px)';
    progressBar.style.backgroundSize = '200% 100%';
    progressBar.style.animation = 'shimmer 1.5s infinite linear';
    progressPct.textContent = 'Uploading...';
    if (progressLabel) progressLabel.textContent = 'Sending file...';
    if (progressSub) progressSub.textContent = formatBytes(file.size) + ' · Direct upload';

    // Listen sa iframe — dito darating ang JSON response mula sa direct_upload.php
    const iframe = document.getElementById('direct_upload_frame');
    iframe.onload = function () {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const raw = iframeDoc.body ? iframeDoc.body.innerText.trim() : '';
            if (!raw) return; // initial empty load, ignore
            const data = JSON.parse(raw);
            if (data.success) {
                toast('File uploaded successfully!');
                setTimeout(() => location.reload(), 1000);
            } else {
                progressBar.style.animation = 'none';
                progressBar.style.width = '0%';
                errEl.textContent = data.error || 'Upload failed.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = btnOrigHTML;
            }
        } catch (e) {
            progressBar.style.animation = 'none';
            progressBar.style.width = '0%';
            errEl.textContent = 'Upload failed. Please try chunked mode.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = btnOrigHTML;
        }
    };

    // Pure native <form method="POST" enctype="multipart/form-data"> submit
    // Hindi na fetch, hindi na XHR — browser mismo ang mag-send
    document.getElementById('directUploadForm').submit();
}

function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1024).toFixed(0) + ' KB';
}
// ────────────────────────────────────────────────────────────────

async function uploadChunkWithRetry(fd, maxRetries = 8) {
    const delays = [1000, 2000, 3000, 5000, 8000, 10000, 15000, 20000];

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            const data = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', BASE_URL_JS + 'chunk-upload', true);

                const timeout = setTimeout(() => {
                    xhr.abort();
                    reject(new Error('timeout'));
                }, 60000);

                xhr.onload = () => {
                    clearTimeout(timeout);
                    if (xhr.status === 405 || xhr.status === 503 || xhr.status === 502) {
                        reject(new Error('HTTP ' + xhr.status));
                        return;
                    }
                    if (xhr.status !== 200) {
                        reject(new Error('HTTP ' + xhr.status));
                        return;
                    }
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (e) {
                        reject(new Error('parse_error'));
                    }
                };

                xhr.onerror = () => { clearTimeout(timeout); reject(new Error('network')); };
                xhr.send(fd);
            });

            return data;

        } catch (e) {
            const waitMs = delays[attempt - 1] || 20000;
            if (attempt < maxRetries) {
                console.warn(`Chunk attempt ${attempt}/${maxRetries} failed: ${e.message}, retrying in ${waitMs}ms`);
                await new Promise(r => setTimeout(r, waitMs));
            } else {
                throw e;
            }
        }
    }
}

// Upload
function openUploadModal(prefillLabel = '') {
    _uploadAborted = false;
    document.getElementById('uploadLabel').value = prefillLabel;
    document.getElementById('uploadFile').value = '';
    document.getElementById('uploadError').style.display = 'none';
    document.getElementById('uploadProgressWrap').style.display = 'none';
    document.getElementById('uploadProgressBar').style.width = '0%';
    document.getElementById('uploadProgressPct').textContent = '0%';
    document.getElementById('uploadModal').classList.add('show');
}
let _uploadAborted = false;

function closeUploadModal() {
    _uploadAborted = true;
    document.getElementById('uploadModal').classList.remove('show');
    document.getElementById('uploadProgressWrap').style.display = 'none';
    document.getElementById('uploadProgressBar').style.width = '0%';
    document.getElementById('uploadProgressPct').textContent = '0%';
    document.getElementById('uploadLabel').value = '';
    document.getElementById('uploadFile').value = '';
    document.getElementById('uploadError').style.display = 'none';
    const btn = document.getElementById('uploadSubmitBtn');
    btn.disabled = false;
    btn.innerHTML = UPLOAD_BTN_ICON_LABEL;
}

async function submitUpload() {
    const label = document.getElementById('uploadLabel').value.trim();
    const file = document.getElementById('uploadFile').files[0];
    const err = document.getElementById('uploadError');
    err.style.display = 'none';

    if (!label) { err.textContent = 'Please enter a file label.'; err.style.display = 'block'; return; }
    if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
    if (file.size > 1.3 * 1024 * 1024 * 1024) {
        err.textContent = 'File exceeds 1.3GB limit.';
        err.style.display = 'block';
        return;
    }

    _uploadAborted = false;
    const btn = document.getElementById('uploadSubmitBtn');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    cancelBtn.textContent = 'Cancel Upload';

    // Direct upload path
    const toggleEl = document.getElementById('uploadModeToggle');
    const isDirectMode = toggleEl && !toggleEl.checked;
    if (isDirectMode) {
        document.getElementById('uploadProgressWrap').style.display = 'block';
        document.getElementById('uploadProgressLabel').textContent = 'Uploading...';
        document.getElementById('uploadProgressPct').textContent = '0%';
        const btnOrigHTML = UPLOAD_BTN_ICON_LABEL;
        await directUpload(
            file, label,
            STAGE_ID, CLIENT_ID, STAGE_NAME,
            document.getElementById('uploadProgressBar'),
            document.getElementById('uploadProgressPct'),
            document.getElementById('uploadProgressLabel'),
            document.getElementById('uploadProgressSub'),
            err, btn, btnOrigHTML
        );
        return;
    }

    const MIN_CHUNK = 512 * 1024;        // 512KB  floor
    const MAX_CHUNK = 32 * 1024 * 1024; // 32MB   ceiling
    const TARGET_MS = 8000;               // aim ~8s per chunk
    const SERVER_OH = 250;                // ~250ms Hostinger overhead

    let CHUNK_SIZE = 2 * 1024 * 1024;    // start at 2MB
    const uploadId = 'uid_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

    function adjustChunkSize(elapsedMs, bytesSent) {
        const netMs = Math.max(elapsedMs - SERVER_OH, 50);
        const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
        const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
        CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
        console.log(`Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
    }

    document.getElementById('uploadProgressWrap').style.display = 'block';
    document.getElementById('uploadProgressLabel').textContent = 'Starting upload...';
    document.getElementById('uploadProgressPct').textContent = '0%';

    try {
        let bytesSent = 0;
        let chunkIndex = 0;

        while (bytesSent < file.size) {
            if (_uploadAborted) return;

            const start = bytesSent;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            const isLast = end >= file.size;

            const fd = new FormData();
            fd.append('chunk', chunk);
            fd.append('chunk_index', chunkIndex);
            fd.append('total_chunks', -1);
            fd.append('is_last', isLast ? 'true' : 'false');
            fd.append('upload_id', uploadId);
            fd.append('original_name', file.name);
            fd.append('stage_id', STAGE_ID);
            fd.append('client_id', CLIENT_ID);
            fd.append('stage_name', STAGE_NAME);
            fd.append('label', label);

            const t0 = performance.now();
            let data;
            try {
                data = await uploadChunkWithRetry(fd);
            } catch (retryErr) {
                if (!_uploadAborted) {
                    const msg = retryErr?.message?.includes('405')
                        ? 'Server rejected the upload (405). Please wait a moment and try again.'
                        : 'Connection error after 5 attempts. Please try again.';
                    err.textContent = msg;
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = UPLOAD_BTN_ICON_LABEL;
                }
                return;
            }
            const elapsed = performance.now() - t0;

            if (!data.success) {
                err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                err.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = UPLOAD_BTN_ICON_LABEL;
                return;
            }

            bytesSent += (end - start);
            chunkIndex++;

            const pct = Math.round((bytesSent / file.size) * 100);
            document.getElementById('uploadProgressBar').style.width = pct + '%';
            document.getElementById('uploadProgressPct').textContent = pct + '%';
            document.getElementById('uploadProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;
            document.getElementById('uploadProgressSub').textContent = formatBytes(bytesSent) + ' of ' + formatBytes(file.size) + ' sent';

            if (!isLast) {
                adjustChunkSize(elapsed, end - start);
                await new Promise(r => setTimeout(r, 300));
            }

            if (data.done) {
                toast('File uploaded successfully!');
                setTimeout(() => location.reload(), 1000);
                return;
            }
        }
    } catch (e) {
        if (!_uploadAborted) {
            err.textContent = 'Connection error. Please try again.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = UPLOAD_BTN_ICON_LABEL;
        }
    }
}


// Reject modal
function openRejectModal(approvalId) {
    document.getElementById('rejectApprovalId').value = approvalId;
    document.getElementById('rejectNote').value = '';
    document.getElementById('rejectError').style.display = 'none';
    document.getElementById('rejectModal').classList.add('show');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
}
async function submitRejection() {
    const id = document.getElementById('rejectApprovalId').value;
    const note = document.getElementById('rejectNote').value.trim();
    const err = document.getElementById('rejectError');
    if (!note) { err.textContent = 'A rejection note is required.'; err.style.display = 'block'; return; }
    try {
        const res = await fetch(BASE_URL_JS + 'approve-reject-stage', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ approval_id: parseInt(id), action: 'rejected', note })
        });
        const data = await res.json();
        if (data.success) { closeRejectModal(); toast('File rejected.'); setTimeout(() => location.reload(), 1000); }
        else { err.textContent = data.error || 'Failed.'; err.style.display = 'block'; }
    } catch (e) { err.textContent = 'An error occurred.'; err.style.display = 'block'; }
}

// Delete
async function deleteFile(approvalId) {
    if (!confirm('Delete this file? This cannot be undone.')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'delete-stage-file', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ approval_id: approvalId, stage_id: STAGE_ID })
        });
        const data = await res.json();
        if (data.success) { toast('File deleted.'); setTimeout(() => location.reload(), 800); }
        else toast('Error: ' + (data.error || 'Failed'), true);
    } catch (e) { toast('An error occurred', true); }
}

// Mark done
async function markDone() {
    if (!confirm('Mark this stage as Done?')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'update-tracker-status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stage_id: STAGE_ID, status: 'Done' })
        });
        const data = await res.json();
        if (data.success) { toast('Stage marked as Done!'); setTimeout(() => location.reload(), 900); }
        else toast('Error: ' + (data.error || 'Failed'), true);
    } catch (e) { toast('An error occurred', true); }
}

async function cancelDone() {
    if (!confirm('Revert this stage back to Ongoing?')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'update-tracker-status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stage_id: STAGE_ID, status: 'Ongoing' })
        });
        const data = await res.json();
        if (data.success) { toast('Stage reverted to Ongoing.'); setTimeout(() => location.reload(), 900); }
        else toast('Error: ' + (data.error || 'Failed'), true);
    } catch (e) { toast('An error occurred', true); }
}

// Receipt modal
let _receiptAborted = false;
function openReceiptModal(poId, poLabel) {
    _receiptAborted = false;
    document.getElementById('receiptLinkedPoId').value = poId;
    document.getElementById('receiptPoLabel').textContent = poLabel;
    document.getElementById('receiptLabel').value = '';
    document.getElementById('receiptFile').value = '';
    document.getElementById('receiptError').style.display = 'none';
    document.getElementById('receiptProgressWrap').style.display = 'none';
    document.getElementById('receiptProgressBar').style.width = '0%';
    document.getElementById('receiptProgressPct').textContent = '0%';
    document.getElementById('receiptModal').classList.add('show');
}
function closeReceiptModal() {
    _receiptAborted = true;
    document.getElementById('receiptModal').classList.remove('show');
}
async function submitReceipt() {
    const label = document.getElementById('receiptLabel').value.trim();
    const file = document.getElementById('receiptFile').files[0];
    const linkedPo = document.getElementById('receiptLinkedPoId').value;
    const err = document.getElementById('receiptError');
    err.style.display = 'none';

    if (!label) { err.textContent = 'Please enter a receipt label.'; err.style.display = 'block'; return; }
    if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
    if (file.size > 1.3 * 1024 * 1024 * 1024) {
        err.textContent = 'File exceeds 1.3GB limit.'; err.style.display = 'block'; return;
    }

    _receiptAborted = false;
    const btn = document.getElementById('receiptSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

    const MIN_CHUNK = 512 * 1024;
    const MAX_CHUNK = 32 * 1024 * 1024;
    const TARGET_MS = 8000;
    const SERVER_OH = 250;

    let CHUNK_SIZE = 2 * 1024 * 1024;
    const uploadId = 'rcpt_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

    function adjustChunkSize(elapsedMs, bytesSent) {
        const netMs = Math.max(elapsedMs - SERVER_OH, 50);
        const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
        const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
        CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
        console.log(`Receipt Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
    }

    document.getElementById('receiptProgressWrap').style.display = 'block';
    document.getElementById('receiptProgressLabel').textContent = 'Starting upload...';
    document.getElementById('receiptProgressPct').textContent = '0%';

    try {
        let bytesSent = 0;
        let chunkIndex = 0;

        while (bytesSent < file.size) {
            if (_receiptAborted) return;

            const start = bytesSent;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            const isLast = end >= file.size;

            const fd = new FormData();
            fd.append('chunk', chunk);
            fd.append('chunk_index', chunkIndex);
            fd.append('total_chunks', -1);
            fd.append('is_last', isLast ? 'true' : 'false');
            fd.append('upload_id', uploadId);
            fd.append('original_name', file.name);
            fd.append('stage_id', STAGE_ID);
            fd.append('client_id', CLIENT_ID);
            fd.append('stage_name', STAGE_NAME);
            fd.append('label', label);
            fd.append('linked_po_id', linkedPo);

            const t0 = performance.now();
            let data;
            try {
                data = await uploadChunkWithRetry(fd);
            } catch (retryErr) {
                if (!_receiptAborted) {
                    const msg = retryErr?.message?.includes('405')
                        ? 'Server rejected the upload (405). Please wait a moment and try again.'
                        : 'Connection error after 5 attempts. Please try again.';
                    err.textContent = msg;
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
                }
                return;
            }
            const elapsed = performance.now() - t0;

            if (!data.success) {
                err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                err.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
                return;
            }

            bytesSent += (end - start);
            chunkIndex++;

            const pct = Math.round((bytesSent / file.size) * 100);
            document.getElementById('receiptProgressBar').style.width = pct + '%';
            document.getElementById('receiptProgressPct').textContent = pct + '%';
            document.getElementById('receiptProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;

            if (!isLast) {
                adjustChunkSize(elapsed, end - start);
                await new Promise(r => setTimeout(r, 300));
            }

            if (data.done) {
                closeReceiptModal();
                toast('Receipt uploaded and linked to PO!');
                setTimeout(() => location.reload(), 1000);
                return;
            }
        }
    } catch (e) {
        if (!_receiptAborted) {
            err.textContent = 'Connection error. Please try again.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload Receipt';
        }
    }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});

function filterCategory(cat, btn) {
    // Update active button
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Show/hide file cards
    document.querySelectorAll('.file-card[data-category]').forEach(card => {
        if (cat === 'all' || card.dataset.category === cat) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// ── Internal P.O to Accounting approval functions ──────────────────
async function requestInternalPoApproval() {
    try {
        const res = await fetch(BASE_URL_JS + 'internal-po-review', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'request_approval', client_id: CLIENT_ID, stage_id: STAGE_ID })
        });
        const data = await res.json();
        if (data.success) { toast('Approval requested!'); setTimeout(() => location.reload(), 900); }
        else toast(data.error || 'Failed', true);
    } catch (e) { toast('An error occurred', true); }
}

async function reviewInternalPo(approvalId, action, reviewer) {
    if (!confirm(action === 'approve' ? 'Approve all files for this stage?' : 'Reject?')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'internal-po-review', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, approval_id: approvalId, client_id: CLIENT_ID, stage_id: STAGE_ID, remark: '' })
        });
        const data = await res.json();
        if (data.success) { toast(action === 'approve' ? 'Approved!' : 'Rejected.'); setTimeout(() => location.reload(), 900); }
        else toast(data.error || 'Failed', true);
    } catch (e) { toast('An error occurred', true); }
}

function showInternalPoRejectForm(reviewer, approvalId) {
    document.getElementById('ipo-reject-form-' + reviewer).style.display = 'block';
    document.getElementById('ipo-reject-form-' + reviewer).dataset.approvalId = approvalId;
}
function hideInternalPoRejectForm(reviewer) {
    document.getElementById('ipo-reject-form-' + reviewer).style.display = 'none';
    document.getElementById('ipo-remark-' + reviewer).value = '';
}

async function submitInternalPoReject(approvalId, reviewer) {
    const remark = document.getElementById('ipo-remark-' + reviewer).value.trim();
    if (!remark) { toast('Please enter a remark before rejecting.', true); return; }
    try {
        const res = await fetch(BASE_URL_JS + 'internal-po-review', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject', approval_id: approvalId, client_id: CLIENT_ID, stage_id: STAGE_ID, remark: remark })
        });
        const data = await res.json();
        if (data.success) { toast('Rejected with remark.'); setTimeout(() => location.reload(), 900); }
        else toast(data.error || 'Failed', true);
    } catch (e) { toast('An error occurred', true); }
}

async function resetInternalPoApproval(approvalId) {
    if (!confirm('Reset the rejection and re-request approval?')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'internal-po-review', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reset', approval_id: approvalId, client_id: CLIENT_ID, stage_id: STAGE_ID })
        });
        const data = await res.json();
        if (data.success) { toast('Reset. You can now re-request approval.'); setTimeout(() => location.reload(), 900); }
        else toast(data.error || 'Failed', true);
    } catch (e) { toast('An error occurred', true); }
}

function toast(msg, err = false) {
    const el = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    el.className = 'toast show' + (err ? ' error' : '');
    setTimeout(() => el.classList.remove('show'), 3000);
}

// PO Upload Modal (linked to BOM)
let _poUploadAborted = false;
function openPOUploadModal(bomId, bomLabel, prefillLabel = '') {
    _poUploadAborted = false;
    document.getElementById('poUploadLinkedBomId').value = bomId || '';
    document.getElementById('poUploadBomLabel').textContent = bomLabel || 'All BOMs';
    document.getElementById('poUploadLabel').value = prefillLabel; // ← auto-fills the label
    document.getElementById('poUploadFile').value = '';
    document.getElementById('poUploadError').style.display = 'none';
    document.getElementById('poUploadProgressWrap').style.display = 'none';
    document.getElementById('poUploadProgressBar').style.width = '0%';
    document.getElementById('poUploadProgressPct').textContent = '0%';
    document.getElementById('poUploadModal').classList.add('show');

    // If label was prefilled (resubmit), make it readonly so user can't accidentally change it
    const labelInput = document.getElementById('poUploadLabel');
    if (prefillLabel) {
        labelInput.setAttribute('readonly', true);
        labelInput.style.background = '#f3f4f6';
        labelInput.style.color = '#6b7280';
    } else {
        labelInput.removeAttribute('readonly');
        labelInput.style.background = '';
        labelInput.style.color = '';
    }
}
function closePOUploadModal() {
    _poUploadAborted = true;
    document.getElementById('poUploadModal').classList.remove('show');
}

async function submitPOUpload() {
    const label = document.getElementById('poUploadLabel').value.trim();
    const file = document.getElementById('poUploadFile').files[0];
    const linkedBom = document.getElementById('poUploadLinkedBomId').value;
    const err = document.getElementById('poUploadError');
    err.style.display = 'none';

    if (!label) { err.textContent = 'Please enter a PO label.'; err.style.display = 'block'; return; }
    if (!file) { err.textContent = 'Please select a file.'; err.style.display = 'block'; return; }
    if (file.size > 1.3 * 1024 * 1024 * 1024) {
        err.textContent = 'File exceeds 1.3GB limit.'; err.style.display = 'block'; return;
    }

    _poUploadAborted = false;
    const btn = document.getElementById('poUploadSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

    const MIN_CHUNK = 512 * 1024;
    const MAX_CHUNK = 32 * 1024 * 1024;
    const TARGET_MS = 8000;
    const SERVER_OH = 250;

    let CHUNK_SIZE = 2 * 1024 * 1024;
    const uploadId = 'po_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);

    function adjustChunkSize(elapsedMs, bytesSent) {
        const netMs = Math.max(elapsedMs - SERVER_OH, 50);
        const mbps = (bytesSent / 1024 / 1024) / (netMs / 1000);
        const ideal = Math.round(mbps * (TARGET_MS / 1000) * 1024 * 1024);
        CHUNK_SIZE = Math.min(MAX_CHUNK, Math.max(MIN_CHUNK, ideal));
        console.log(`PO Speed: ${mbps.toFixed(2)} MB/s → next chunk: ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB`);
    }

    document.getElementById('poUploadProgressWrap').style.display = 'block';
    document.getElementById('poUploadProgressLabel').textContent = 'Starting upload...';
    document.getElementById('poUploadProgressPct').textContent = '0%';

    try {
        let bytesSent = 0;
        let chunkIndex = 0;

        while (bytesSent < file.size) {
            if (_poUploadAborted) return;

            const start = bytesSent;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            const isLast = end >= file.size;

            const fd = new FormData();
            fd.append('chunk', chunk);
            fd.append('chunk_index', chunkIndex);
            fd.append('total_chunks', -1);
            fd.append('is_last', isLast ? 'true' : 'false');
            fd.append('upload_id', uploadId);
            fd.append('original_name', file.name);
            fd.append('stage_id', STAGE_ID);
            fd.append('client_id', CLIENT_ID);
            fd.append('stage_name', STAGE_NAME);
            fd.append('label', label);
            fd.append('linked_bom_id', linkedBom);

            const t0 = performance.now();
            let data;
            try {
                data = await uploadChunkWithRetry(fd);
            } catch (retryErr) {
                if (!_poUploadAborted) {
                    const msg = retryErr?.message?.includes('405')
                        ? 'Server rejected the upload (405). Please wait a moment and try again.'
                        : 'Connection error after 5 attempts. Please try again.';
                    err.textContent = msg;
                    err.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
                }
                return;
            }
            const elapsed = performance.now() - t0;

            if (!data.success) {
                err.textContent = data.error || 'Upload failed on chunk ' + (chunkIndex + 1);
                err.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
                return;
            }

            bytesSent += (end - start);
            chunkIndex++;

            const pct = Math.round((bytesSent / file.size) * 100);
            document.getElementById('poUploadProgressBar').style.width = pct + '%';
            document.getElementById('poUploadProgressPct').textContent = pct + '%';
            document.getElementById('poUploadProgressLabel').textContent = `Chunk ${chunkIndex} · ${(CHUNK_SIZE / 1024 / 1024).toFixed(1)}MB each`;

            if (!isLast) {
                adjustChunkSize(elapsed, end - start);
                await new Promise(r => setTimeout(r, 300));
            }

            if (data.done) {
                closePOUploadModal();
                toast('Purchase Order submitted for approval!');
                setTimeout(() => location.reload(), 1000);
                return;
            }
        }
    } catch (e) {
        if (!_poUploadAborted) {
            err.textContent = 'Connection error. Please try again.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Submit for Approval';
        }
    }
}

// Update BOM order status
async function updateBomOrderStatus(bomId, status) {
    const labels = { pending: 'Not Ordered', partially_ordered: 'Partially Ordered', ordered: 'Fully Ordered' };
    if (!confirm('Mark this BOM as: ' + labels[status] + '?')) return;
    try {
        const res = await fetch(BASE_URL_JS + 'update-bom-order-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bom_id: bomId, status: status, client_id: CLIENT_ID })
        });
        const data = await res.json();
        if (data.success) { toast('Order status updated!'); setTimeout(() => location.reload(), 800); }
        else toast('Error: ' + (data.error || 'Failed'), true);
    } catch (e) { toast('An error occurred', true); }
}