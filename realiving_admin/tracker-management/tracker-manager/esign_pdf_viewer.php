<?php
// esign_pdf_viewer.php
// Place at: realiving_admin/tracker_management/esign_pdf_viewer.php
session_start();
include '../../connection/connection.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$approval_id = isset($_GET['approval_id']) ? intval($_GET['approval_id']) : 0;

if (!$approval_id) {
    echo 'Invalid request';
    exit();
}

// Get file info
$stmt = $conn->prepare("SELECT file_path, file_name FROM stage_approvals WHERE id = ?");
$stmt->bind_param("i", $approval_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) {
    echo 'File not found';
    exit();
}

// Get signature
$sigStmt = $conn->prepare("SELECT e_signature, full_name FROM account WHERE id = ?");
$sigStmt->bind_param("i", $admin_id);
$sigStmt->execute();
$sigRow = $sigStmt->get_result()->fetch_assoc();
$rawSig = $sigRow['e_signature'] ?? '';
$cleanSig = preg_replace('#^(\.\./)+#', '', $rawSig);
$sigSrc = '../../' . htmlspecialchars($cleanSig);
$userName = htmlspecialchars($sigRow['full_name'] ?? 'Approver');

$absFilePath = dirname(dirname(dirname(__FILE__))) . '/' . preg_replace('#^(\.\./)+#', '', $file['file_path']);
$pdfSrc = '../../' . htmlspecialchars($file['file_path']) . '?v=' . (file_exists($absFilePath) ? filemtime($absFilePath) : time());
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place E-Signature</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: #1e1e1e;
            color: #fff;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Top toolbar */
        .toolbar {
            background: #2d2d2d;
            border-bottom: 1px solid #444;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #3a3a3a;
            border-radius: 8px;
            padding: 4px 10px;
        }

        .page-controls button {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background .2s;
        }

        .page-controls button:hover:not(:disabled) {
            background: #555;
        }

        .page-controls button:disabled {
            opacity: 0.3;
            cursor: default;
        }

        .page-label {
            font-size: 13px;
            color: #ddd;
            min-width: 70px;
            text-align: center;
        }

        .sig-size-control {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #3a3a3a;
            border-radius: 8px;
            padding: 4px 12px;
            font-size: 12px;
            color: #ccc;
        }

        .sig-size-control input[type=range] {
            width: 90px;
            accent-color: #c49a78;
        }

        .btn-confirm {
            background: linear-gradient(135deg, #7a4528, #c49a78);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: opacity .2s;
        }

        .btn-confirm:hover {
            opacity: 0.9;
        }

        .btn-cancel {
            background: #444;
            color: #ccc;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            cursor: pointer;
            transition: background .2s;
        }

        .btn-cancel:hover {
            background: #555;
        }

        /* Instruction bar */
        .instruction {
            background: #3b1f0f;
            color: #fde68a;
            font-size: 12px;
            font-weight: 600;
            padding: 7px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        /* PDF scroll area */
        .pdf-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: auto;
            background: #3a3a3a;
            display: flex;
            justify-content: center;
            padding: 20px;
        }

        /* Canvas wrapper — position relative so marker is absolute inside */
        .canvas-wrap {
            position: relative;
            display: inline-block;
            cursor: crosshair;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5);
        }

        canvas#pdfCanvas {
            display: block;
            background: #fff;
        }

        /* Signature marker */
        #sigMarker {
            display: none;
            position: absolute;
            cursor: grab;
            border: 2px dashed #c49a78;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.05);
            z-index: 10;
        }

        #sigMarker img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            pointer-events: none;
            display: block;
        }

        #sigMarker.dragging {
            cursor: grabbing;
        }

        /* Resize handle */
        #resizeHandle {
            position: absolute;
            bottom: -7px;
            right: -7px;
            width: 16px;
            height: 16px;
            background: #7a4528;
            border-radius: 3px;
            cursor: se-resize;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 11;
        }

        /* Name label under signature */
        #sigLabel {
            position: absolute;
            bottom: -20px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #3b1f0f;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 3px;
            padding: 1px 4px;
            pointer-events: none;
            white-space: nowrap;
        }

        /* Scrollbar styling */
        .pdf-scroll::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .pdf-scroll::-webkit-scrollbar-track {
            background: #2d2d2d;
        }

        .pdf-scroll::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 4px;
        }
    </style>
</head>

<body>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <!-- Page controls -->
            <div class="page-controls">
                <button id="prevBtn" onclick="changePage(-1)" disabled>&#8592;</button>
                <span class="page-label">Page <span id="curPage">1</span> / <span id="totPage">1</span></span>
                <button id="nextBtn" onclick="changePage(1)" disabled>&#8594;</button>
            </div>

            <!-- Signature size slider -->
            <div class="sig-size-control">
                <span>Sig size:</span>
                <input type="range" id="sigSizeSlider" min="60" max="300" value="140"
                    oninput="resizeFromSlider(this.value)">
                <span id="sigSizeLabel">140px</span>
            </div>
        </div>

        <div class="toolbar-right">
            <button class="btn-cancel" onclick="window.parent.closeEsignViewer()">Cancel</button>
            <button class="btn-confirm" id="confirmBtn" onclick="confirmPlacement()" disabled>
                &#10003; Confirm Placement
            </button>
        </div>
    </div>

    <!-- Instruction -->
    <div class="instruction">
        &#128073; Click anywhere on the PDF to place your signature. Then drag to reposition or use the slider to
        resize.
    </div>

    <!-- PDF Scroll Area -->
    <div class="pdf-scroll" id="pdfScroll">
        <div class="canvas-wrap" id="canvasWrap" onclick="handleCanvasClick(event)">
            <canvas id="pdfCanvas"></canvas>

            <!-- Signature marker -->
            <div id="sigMarker">
                <img src="<?= $sigSrc ?>" alt="signature">
                <div id="resizeHandle">
                    <svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                        <path d="M1 7L7 1M4 7L7 4" stroke="white" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div id="sigLabel"><?= $userName ?></div>
            </div>
        </div>
    </div>

    <!-- PDF.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const PDF_URL = '<?= $pdfSrc ?>';
        const SIG_SRC = '<?= $sigSrc ?>';
        const APPROVAL_ID = <?= $approval_id ?>;

        let pdfDoc = null;
        let currentPage = 1;
        let totalPages = 1;
        let renderTask = null;

        // Signature state
        let sigX = 0, sigY = 0;         // px relative to canvas top-left
        let sigW = 140, sigH = 56;      // px
        let placed = false;
        let dragging = false;
        let resizing = false;
        let dragOffX = 0, dragOffY = 0;
        let resStartX = 0, resStartW = 0;

        const canvas = document.getElementById('pdfCanvas');
        const ctx = canvas.getContext('2d');
        const marker = document.getElementById('sigMarker');
        const resHandle = document.getElementById('resizeHandle');
        const confirmBtn = document.getElementById('confirmBtn');

        // ── Load PDF ─────────────────────────────────────────────────────
        pdfjsLib.getDocument(PDF_URL).promise.then(function (doc) {
            pdfDoc = doc;
            totalPages = doc.numPages;
            document.getElementById('totPage').textContent = totalPages;
            document.getElementById('nextBtn').disabled = (totalPages <= 1);
            renderPage(1);
        }).catch(function (err) {
            document.querySelector('.instruction').textContent = 'Error loading PDF: ' + err.message;
        });

        function renderPage(pageNum) {
            if (renderTask) renderTask.cancel();
            pdfDoc.getPage(pageNum).then(function (page) {
                const scrollW = document.getElementById('pdfScroll').clientWidth - 60;
                const viewport0 = page.getViewport({ scale: 1 });
                const scale = Math.min(scrollW / viewport0.width, 1.5);
                const viewport = page.getViewport({ scale });

                canvas.width = viewport.width;
                canvas.height = viewport.height;

                renderTask = page.render({ canvasContext: ctx, viewport });
                renderTask.promise.then(function () {
                    currentPage = pageNum;
                    document.getElementById('curPage').textContent = currentPage;
                    document.getElementById('prevBtn').disabled = (currentPage <= 1);
                    document.getElementById('nextBtn').disabled = (currentPage >= totalPages);
                    // Re-draw marker if placed
                    if (placed) updateMarkerDisplay();
                }).catch(function () { });
            });
        }

        function changePage(dir) {
            const newPage = currentPage + dir;
            if (newPage < 1 || newPage > totalPages) return;
            placed = false;
            marker.style.display = 'none';
            confirmBtn.disabled = true;
            renderPage(newPage);
        }

        // ── Click to place ────────────────────────────────────────────────
        function handleCanvasClick(e) {
            if (e.target === resHandle || e.target.closest('#resizeHandle')) return;
            if (dragging || resizing) return;

            const wrap = document.getElementById('canvasWrap');
            const rect = wrap.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            const clickY = e.clientY - rect.top;

            sigX = clickX - sigW / 2;
            sigY = clickY - sigH / 2;
            sigX = Math.max(0, Math.min(canvas.width - sigW, sigX));
            sigY = Math.max(0, Math.min(canvas.height - sigH, sigY));

            placed = true;
            updateMarkerDisplay();
            confirmBtn.disabled = false;
        }

        function updateMarkerDisplay() {
            marker.style.display = 'block';
            marker.style.left = sigX + 'px';
            marker.style.top = sigY + 'px';
            marker.style.width = sigW + 'px';
            marker.style.height = sigH + 'px';
        }

        // ── Drag ─────────────────────────────────────────────────────────
        marker.addEventListener('mousedown', function (e) {
            if (e.target === resHandle || e.target.closest('#resizeHandle')) return;
            e.preventDefault();
            dragging = true;
            dragOffX = e.clientX - sigX;
            dragOffY = e.clientY - sigY;
            marker.classList.add('dragging');
        });

        marker.addEventListener('touchstart', function (e) {
            if (e.target === resHandle || e.target.closest('#resizeHandle')) return;
            e.preventDefault();
            dragging = true;
            dragOffX = e.touches[0].clientX - sigX;
            dragOffY = e.touches[0].clientY - sigY;
        }, { passive: false });

        // ── Resize ───────────────────────────────────────────────────────
        resHandle.addEventListener('mousedown', function (e) {
            e.preventDefault(); e.stopPropagation();
            resizing = true;
            resStartX = e.clientX;
            resStartW = sigW;
        });

        resHandle.addEventListener('touchstart', function (e) {
            e.preventDefault(); e.stopPropagation();
            resizing = true;
            resStartX = e.touches[0].clientX;
            resStartW = sigW;
        }, { passive: false });

        // ── Move handler ─────────────────────────────────────────────────
        function onMove(clientX, clientY) {
            if (dragging) {
                const wrap = document.getElementById('canvasWrap');
                sigX = clientX - dragOffX;
                sigY = clientY - dragOffY;
                sigX = Math.max(0, Math.min(canvas.width - sigW, sigX));
                sigY = Math.max(0, Math.min(canvas.height - sigH, sigY));
                updateMarkerDisplay();
            }
            if (resizing) {
                const diff = clientX - resStartX;
                sigW = Math.max(60, Math.min(400, resStartW + diff));
                sigH = Math.round(sigW * 0.4);
                updateMarkerDisplay();
            }
        }

        document.addEventListener('mousemove', function (e) { onMove(e.clientX, e.clientY); });
        document.addEventListener('touchmove', function (e) { onMove(e.touches[0].clientX, e.touches[0].clientY); }, { passive: false });
        document.addEventListener('mouseup', function () { dragging = false; resizing = false; marker.classList.remove('dragging'); });
        document.addEventListener('touchend', function () { dragging = false; resizing = false; });

        // ── Slider resize ────────────────────────────────────────────────
        function resizeFromSlider(val) {
            sigW = parseInt(val);
            sigH = Math.round(sigW * 0.4);
            document.getElementById('sigSizeLabel').textContent = val + 'px';
            if (placed) updateMarkerDisplay();
        }

        // ── Confirm — send result to parent ─────────────────────────────
        function confirmPlacement() {
            const xPct = ((sigX + sigW / 2) / canvas.width) * 100;
            const yPct = ((sigY + sigH / 2) / canvas.height) * 100;
            const wPct = (sigW / canvas.width) * 100;
            const hPct = (sigH / canvas.height) * 100;

            window.parent.receiveEsignPlacement({
                x_pct: xPct,
                y_pct: yPct,
                w_pct: wPct,
                h_pct: hPct,
                page: currentPage,
                approval_id: APPROVAL_ID,
            });
        }
    </script>
</body>

</html>