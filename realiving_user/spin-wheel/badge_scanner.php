<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Badge Scanner</title>
    <script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px 48px;
            color: #f8fafc;
        }

        .header {
            text-align: center;
            margin-bottom: 28px;
        }

        .header .badge-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 26px;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 800;
            color: #f8fafc;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .header p {
            font-size: 13px;
            color: #94a3b8;
        }

        .https-notice {
            background: #1c1917;
            border: 1px solid #f59e0b;
            border-radius: 14px;
            padding: 14px 16px;
            width: 100%;
            max-width: 420px;
            margin-bottom: 18px;
            font-size: 13px;
            color: #fcd34d;
            line-height: 1.6;
            display: none;
        }

        .https-notice strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .scanner-card {
            background: #1e293b;
            border-radius: 20px;
            border: 1px solid #334155;
            padding: 20px;
            width: 100%;
            max-width: 420px;
            margin-bottom: 20px;
        }

        .video-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            background: #000;
            border-radius: 14px;
            overflow: hidden;
        }

        #preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .scan-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .scan-frame {
            width: 58%;
            aspect-ratio: 1;
            border: 2.5px solid #f59e0b;
            border-radius: 14px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
        }

        .corner {
            position: absolute;
            width: 22px;
            height: 22px;
        }

        .corner-tl {
            top: 21%;
            left: 21%;
            border-top: 3px solid #f59e0b;
            border-left: 3px solid #f59e0b;
            border-radius: 4px 0 0 0;
        }

        .corner-tr {
            top: 21%;
            right: 21%;
            border-top: 3px solid #f59e0b;
            border-right: 3px solid #f59e0b;
            border-radius: 0 4px 0 0;
        }

        .corner-bl {
            bottom: 21%;
            left: 21%;
            border-bottom: 3px solid #f59e0b;
            border-left: 3px solid #f59e0b;
            border-radius: 0 0 0 4px;
        }

        .corner-br {
            bottom: 21%;
            right: 21%;
            border-bottom: 3px solid #f59e0b;
            border-right: 3px solid #f59e0b;
            border-radius: 0 0 4px 0;
        }

        .scan-line {
            position: absolute;
            left: 21%;
            width: 58%;
            height: 2px;
            background: linear-gradient(90deg, transparent, #f59e0b, transparent);
            animation: scanMove 1.8s ease-in-out infinite;
        }

        @keyframes scanMove {

            0%,
            100% {
                top: 21%;
            }

            50% {
                top: 79%;
            }
        }

        .scanner-status {
            text-align: center;
            margin-top: 12px;
            font-size: 13px;
            color: #64748b;
            min-height: 18px;
        }

        .scanner-status.success {
            color: #34d399;
            font-weight: 600;
        }

        .scanner-status.error {
            color: #f87171;
            font-weight: 600;
        }

        .btn {
            width: 100%;
            padding: 13px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            border: none;
            margin-top: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: opacity 0.2s, transform 0.15s;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-start {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
        }

        .btn-start:hover {
            opacity: 0.9;
        }

        .btn-stop {
            background: #334155;
            color: #94a3b8;
        }

        .btn-stop:hover {
            background: #475569;
        }

        .btn-reset {
            background: #1e3a5f;
            color: #93c5fd;
            border: 1px solid #2563eb;
            margin-top: 12px;
        }

        .btn-save {
            background: linear-gradient(135deg, #16a34a, #14532d);
            color: #fff;
            margin-top: 12px;
        }

        .btn-save:hover {
            opacity: 0.9;
        }

        .result-card {
            background: #1e293b;
            border-radius: 20px;
            border: 1px solid #334155;
            padding: 24px;
            width: 100%;
            max-width: 420px;
            animation: fadeUp 0.35s ease;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .scan-timestamp {
            font-size: 11px;
            color: #475569;
            text-align: right;
            margin-bottom: 14px;
            letter-spacing: 0.3px;
        }

        .profile-top {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b, #b45309);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            letter-spacing: 1px;
        }

        .profile-name {
            font-size: 18px;
            font-weight: 800;
            color: #f8fafc;
            line-height: 1.2;
        }

        .profile-title {
            font-size: 13px;
            color: #f59e0b;
            margin-top: 3px;
            font-weight: 600;
        }

        .divider {
            height: 1px;
            background: #334155;
            margin: 16px 0;
        }

        .info-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .info-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 14px;
            color: #e2e8f0;
            font-weight: 500;
            margin-top: 1px;
            word-break: break-all;
        }

        .info-value a {
            color: #93c5fd;
            text-decoration: none;
        }

        .watermark {
            text-align: center;
            margin-top: 16px;
            font-size: 10px;
            color: #334155;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .raw-card {
            background: #1e293b;
            border-radius: 20px;
            border: 1px solid #334155;
            padding: 20px;
            width: 100%;
            max-width: 420px;
            margin-top: 16px;
        }

        .raw-card summary {
            font-size: 12px;
            color: #64748b;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .raw-card pre {
            margin-top: 10px;
            font-size: 11px;
            color: #94a3b8;
            white-space: pre-wrap;
            word-break: break-all;
            background: #0f172a;
            padding: 12px;
            border-radius: 8px;
            line-height: 1.7;
        }

        .no-result {
            background: #1e293b;
            border-radius: 20px;
            border: 1px solid #475569;
            padding: 20px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            color: #f87171;
            font-size: 14px;
            font-weight: 600;
            animation: fadeUp 0.3s ease;
        }

        .no-result .icon {
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
        }

        .no-result p {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 6px;
            font-weight: 400;
        }
    </style>
</head>

<body>

    <div class="header">
        <div class="badge-icon">🪪</div>
        <h1>Badge Scanner</h1>
        <p>Scan a Philconstruct event badge QR code</p>
    </div>

    <div class="https-notice" id="httpsNotice">
        <strong>⚠️ Camera requires HTTPS</strong>
        This page must be served over <strong>https://</strong> for the camera to work on mobile. Upload this file to
        your web server and open it via its HTTPS URL.
    </div>

    <div class="scanner-card">
        <div class="video-wrap">
            <video id="preview" muted playsinline></video>
            <div class="scan-overlay">
                <div class="scan-frame"></div>
                <div class="corner corner-tl"></div>
                <div class="corner corner-tr"></div>
                <div class="corner corner-bl"></div>
                <div class="corner corner-br"></div>
                <div class="scan-line"></div>
            </div>
        </div>
        <div class="scanner-status" id="scannerStatus">Press "Start Scanner" to activate camera.</div>
        <button class="btn btn-start" id="btnStart" onclick="startScanner()">📷 Start Scanner</button>
        <button class="btn btn-stop" id="btnStop" onclick="stopScanner()" style="display:none;">⏹ Stop Scanner</button>
    </div>

    <div id="resultWrap"></div>

    <script>
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            document.getElementById('httpsNotice').style.display = 'block';
        }

        let codeReader = null;
        let scanning = false;

        function startScanner() {
            const statusEl = document.getElementById('scannerStatus');
            const btnStart = document.getElementById('btnStart');
            const btnStop = document.getElementById('btnStop');
            if (!window.ZXing) {
                statusEl.textContent = 'Scanner library not loaded. Please refresh.';
                statusEl.className = 'scanner-status error';
                return;
            }
            codeReader = new ZXing.BrowserQRCodeReader();
            statusEl.textContent = 'Starting camera...';
            statusEl.className = 'scanner-status';
            btnStart.style.display = 'none';
            btnStop.style.display = 'flex';
            scanning = true;
            codeReader.decodeFromVideoDevice(null, 'preview', (result, err) => {
                if (!scanning) return;
                if (result) {
                    statusEl.textContent = '✅ Badge scanned!';
                    statusEl.className = 'scanner-status success';
                    stopScanner();
                    displayResult(result.getText());
                }
            }).catch(err => {
                statusEl.textContent = 'Camera error: ' + err.message;
                statusEl.className = 'scanner-status error';
                btnStart.style.display = 'flex';
                btnStop.style.display = 'none';
                scanning = false;
            });
        }

        function stopScanner() {
            scanning = false;
            if (codeReader) { codeReader.reset(); codeReader = null; }
            document.getElementById('btnStart').style.display = 'flex';
            document.getElementById('btnStop').style.display = 'none';
        }

        function parseVCard(raw) {
            const data = {};
            const lines = raw.split(/\r?\n/);
            for (const line of lines) {
                const lower = line.toLowerCase();
                if (lower.startsWith('fn:')) data.name = line.slice(3).trim();
                if (lower.startsWith('org:')) data.org = line.slice(4).trim();
                if (lower.startsWith('title:')) data.title = line.slice(6).trim();
                if (lower.startsWith('tel')) data.phone = line.split(':').slice(1).join(':').trim();
                if (lower.startsWith('email')) data.email = line.split(':').slice(1).join(':').trim();
                if (lower.startsWith('url')) data.url = line.split(':').slice(1).join(':').trim();
                if (lower.startsWith('adr')) data.address = line.split(':').slice(1).join(':').replace(/;/g, ' ').trim();
                if (lower.startsWith('note:')) data.note = line.slice(5).trim();
            }
            return data;
        }

        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(/\s+/);
            if (parts.length === 1) return parts[0][0].toUpperCase();
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function infoRow(icon, label, value) {
            return `<div class="info-item">
    <div class="info-icon">${icon}</div>
    <div>
      <div class="info-label">${label}</div>
      <div class="info-value">${value}</div>
    </div>
  </div>`;
        }

        function getNow() {
            const now = new Date();
            return now.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
                + ' ' + now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
        }

        function displayResult(raw) {
            const wrap = document.getElementById('resultWrap');
            const isVCard = raw.toUpperCase().includes('BEGIN:VCARD');

            if (!isVCard) {
                wrap.innerHTML = `
      <div class="no-result">
        <span class="icon">⚠️</span>
        Not a vCard badge QR
        <p>Raw: <code style="font-size:11px;">${escHtml(raw.slice(0, 80))}</code></p>
      </div>
      <button class="btn btn-reset" onclick="resetScanner()" style="max-width:420px;width:100%;">🔄 Scan Another</button>`;
                wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }

            const d = parseVCard(raw);
            const initials = getInitials(d.name);
            const timestamp = getNow();

            let rows = '';
            if (d.org) rows += infoRow('🏢', 'Company', escHtml(d.org));
            if (d.title) rows += infoRow('💼', 'Title', escHtml(d.title));
            if (d.phone) rows += infoRow('📞', 'Phone', `<a href="tel:${escHtml(d.phone)}">${escHtml(d.phone)}</a>`);
            if (d.email) rows += infoRow('✉️', 'Email', `<a href="mailto:${escHtml(d.email)}">${escHtml(d.email)}</a>`);
            if (d.url) rows += infoRow('🌐', 'Website', `<a href="${escHtml(d.url)}" target="_blank">${escHtml(d.url)}</a>`);
            if (d.address) rows += infoRow('📍', 'Address', escHtml(d.address));
            if (d.note) rows += infoRow('📝', 'Note', escHtml(d.note));

            wrap.innerHTML = `
    <div class="result-card" id="snapCard">
      <div class="scan-timestamp">Scanned: ${timestamp}</div>
      <div class="profile-top">
        <div class="avatar">${escHtml(initials)}</div>
        <div>
          <div class="profile-name">${escHtml(d.name || 'Unknown')}</div>
          ${d.title ? `<div class="profile-title">${escHtml(d.title)}</div>` : ''}
        </div>
      </div>
      <div class="divider"></div>
      <div class="info-list">${rows}</div>
      <div class="watermark">Philconstruct Badge Scanner</div>
    </div>
    <button class="btn btn-save" onclick="saveAsJpg()">💾 Save as JPG</button>
    <details class="raw-card">
      <summary>Show raw vCard data</summary>
      <pre>${escHtml(raw)}</pre>
    </details>
    <button class="btn btn-reset" onclick="resetScanner()" style="max-width:420px;width:100%;margin-top:12px;">🔄 Scan Another Badge</button>`;

            wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function saveAsJpg() {
            const card = document.getElementById('snapCard');
            if (!card || !window.html2canvas) {
                alert('Save feature not ready. Please refresh and try again.');
                return;
            }
            const btn = document.querySelector('.btn-save');
            btn.textContent = '⏳ Saving...';
            btn.disabled = true;

            html2canvas(card, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                logging: false,
                onclone: function (cloned) {
                    const c = cloned.getElementById('snapCard');
                    c.style.background = '#ffffff';
                    c.style.border = '1px solid #e2e8f0';
                    c.style.borderRadius = '20px';
                    c.style.padding = '24px';
                    c.querySelectorAll('.profile-name').forEach(el => el.style.color = '#1e293b');
                    c.querySelectorAll('.profile-title').forEach(el => el.style.color = '#d97706');
                    c.querySelectorAll('.info-label').forEach(el => el.style.color = '#94a3b8');
                    c.querySelectorAll('.info-value').forEach(el => el.style.color = '#1e293b');
                    c.querySelectorAll('.info-value a').forEach(el => el.style.color = '#2563eb');
                    c.querySelectorAll('.info-icon').forEach(el => el.style.background = '#f1f5f9');
                    c.querySelectorAll('.divider').forEach(el => el.style.background = '#e2e8f0');
                    c.querySelectorAll('.scan-timestamp').forEach(el => el.style.color = '#94a3b8');
                    c.querySelectorAll('.watermark').forEach(el => el.style.color = '#cbd5e1');
                }
            }).then(canvas => {
                const name = card.querySelector('.profile-name');
                const filename = (name ? name.textContent.trim().replace(/\s+/g, '_') : 'badge') + '_' + Date.now() + '.jpg';
                const link = document.createElement('a');
                link.download = filename;
                link.href = canvas.toDataURL('image/jpeg', 0.92);
                link.click();
                btn.innerHTML = '✅ Saved!';
                setTimeout(() => { btn.innerHTML = '💾 Save as JPG'; btn.disabled = false; }, 2000);
            }).catch(err => {
                alert('Could not save: ' + err.message);
                btn.innerHTML = '💾 Save as JPG';
                btn.disabled = false;
            });
        }

        function resetScanner() {
            document.getElementById('resultWrap').innerHTML = '';
            document.getElementById('scannerStatus').textContent = 'Press "Start Scanner" to scan another badge.';
            document.getElementById('scannerStatus').className = 'scanner-status';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>

</html>