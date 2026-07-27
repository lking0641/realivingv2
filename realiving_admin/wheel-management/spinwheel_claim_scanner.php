<?php
// spinwheel_claim_scanner.php
include $includes ['mainbody'];
require_role(['admin1','admin2','admin3','admin4','admin5','admin6','superadmin','sales','designer']);

$reg = null;
$ref = '';
$lookup_error = '';

// Manual token lookup OR redirect from mark_claimed
if (isset($_GET['ref'])) {
    $ref = strtoupper(trim($_GET['ref']));
    $ref_esc = $conn->real_escape_string($ref);
    $reg = $conn->query("SELECT * FROM spinwheel_registrations WHERE claim_token = '$ref_esc' LIMIT 1")->fetch_assoc();
    if (!$reg) $lookup_error = "Token not found. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Scanner | Spin to Win</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <!-- ZXing QR scanner library -->
    <script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; min-height: 100vh; }
        .page-wrap { max-width: 640px; margin: 0 auto; padding: 32px 16px 60px; }

        /* Header */
        .page-header { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }
        .page-header .icon-box { width: 48px; height: 48px; background: linear-gradient(135deg, #c4905c, #2f1200); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .page-header h1 { font-size: 22px; font-weight: 800; color: #1e293b; }
        .page-header p { font-size: 13px; color: #94a3b8; margin-top: 2px; }

        /* Scanner card */
        .scanner-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 20px; }
        .scanner-card h2 { font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* Video preview */
        .video-wrap { position: relative; width: 100%; aspect-ratio: 1; background: #0f172a; border-radius: 12px; overflow: hidden; }
        #preview { width: 100%; height: 100%; object-fit: cover; display: block; }
        .scan-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .scan-frame { width: 55%; aspect-ratio: 1; border: 3px solid #c4905c; border-radius: 12px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.45); }
        .scan-line { position: absolute; left: 22.5%; width: 55%; height: 2px; background: linear-gradient(90deg, transparent, #c4905c, transparent); animation: scanMove 2s ease-in-out infinite; }
        @keyframes scanMove { 0%,100% { top: 22.5%; } 50% { top: 77.5%; } }

        .scanner-status { text-align: center; margin-top: 12px; font-size: 13px; color: #64748b; min-height: 20px; }
        .scanner-status.success { color: #16a34a; font-weight: 600; }
        .scanner-status.error { color: #dc2626; font-weight: 600; }

        .btn-scanner { width: 100%; padding: 12px; border-radius: 10px; font-family: 'Inter', sans-serif; font-weight: 700; font-size: 14px; cursor: pointer; border: none; transition: all 0.2s; margin-top: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-start { background: linear-gradient(135deg, #c4905c, #2f1200); color: #fff; }
        .btn-start:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-stop { background: #f1f5f9; color: #64748b; }
        .btn-stop:hover { background: #e2e8f0; }

        /* Manual input */
        .manual-wrap { display: flex; gap: 10px; margin-top: 16px; }
        .manual-wrap input { flex: 1; padding: 11px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 13px; text-transform: uppercase; letter-spacing: 2px; transition: border-color 0.2s; }
        .manual-wrap input:focus { outline: none; border-color: #c4905c; }
        .manual-wrap button { padding: 11px 18px; background: #2f1200; color: #fff; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap; transition: opacity 0.2s; }
        .manual-wrap button:hover { opacity: 0.85; }

        .divider { display: flex; align-items: center; gap: 12px; margin: 18px 0 0; color: #cbd5e1; font-size: 12px; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }

        /* Result card */
        .result-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 24px; animation: fadeUp 0.3s ease; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .result-status { text-align: center; margin-bottom: 20px; }
        .result-status .status-emoji { font-size: 48px; display: block; margin-bottom: 8px; }
        .result-status h3 { font-size: 18px; font-weight: 800; color: #1e293b; }
        .result-status p { font-size: 13px; color: #64748b; margin-top: 4px; }

        .prize-box { background: linear-gradient(135deg, #fff8f0, #fef3e2); border: 2px solid #f0d9bf; border-radius: 12px; padding: 16px; text-align: center; margin: 16px 0; }
        .prize-box .prize-label { font-size: 11px; font-weight: 700; color: #c4905c; letter-spacing: 1px; text-transform: uppercase; }
        .prize-box .prize-name { font-size: 24px; font-weight: 800; color: #2f1200; margin-top: 4px; }

        .info-grid { display: grid; gap: 0; border: 1px solid #f1f5f9; border-radius: 10px; overflow: hidden; margin: 16px 0; }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { color: #94a3b8; font-weight: 600; font-size: 11px; text-transform: uppercase; }
        .info-row .value { color: #1e293b; font-weight: 600; text-align: right; }

        .btn-claim { width: 100%; padding: 14px; background: linear-gradient(135deg, #16a34a, #14532d); color: #fff; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-weight: 800; font-size: 15px; letter-spacing: 0.5px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 4px; }
        .btn-claim:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(22,163,74,0.3); }
        .btn-claim:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-scan-again { width: 100%; padding: 12px; background: #f8fafc; color: #64748b; border: 2px solid #e2e8f0; border-radius: 10px; font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer; margin-top: 10px; transition: all 0.2s; }
        .btn-scan-again:hover { background: #f1f5f9; border-color: #cbd5e1; }

        .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

        .claimed-badge { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 12px; padding: 16px; text-align: center; margin: 16px 0; }
        .claimed-badge i { font-size: 28px; color: #16a34a; display: block; margin-bottom: 6px; }
        .claimed-badge p { font-size: 13px; color: #15803d; font-weight: 700; }
        .claimed-badge .claimed-time { font-size: 12px; color: #86efac; font-weight: 400; margin-top: 4px; }

        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #64748b; font-size: 13px; font-weight: 600; text-decoration: none; margin-bottom: 20px; transition: color 0.2s; }
        .back-link:hover { color: #2f1200; }
    </style>
</head>
<body>
<div class="page-wrap">

    <a href="spinwheel-registrations-dashboard" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Registrations
    </a>

    <div class="page-header">
        <div class="icon-box"><i class="fa-solid fa-qrcode" style="color:#fff; font-size:22px;"></i></div>
        <div>
            <h1>Prize Claim Scanner</h1>
            <p>Scan a winner's QR code to verify and mark their prize as claimed.</p>
        </div>
    </div>

    <!-- Scanner Card -->
    <div class="scanner-card" id="scannerSection">
        <h2><i class="fa-solid fa-camera" style="color:#c4905c;"></i> QR Code Scanner</h2>
        <div class="video-wrap">
            <video id="preview" muted playsinline></video>
            <div class="scan-overlay">
                <div class="scan-frame"></div>
                <div class="scan-line"></div>
            </div>
        </div>
        <div class="scanner-status" id="scannerStatus">Press "Start Scanner" to activate camera.</div>
        <button class="btn-scanner btn-start" id="btnStart" onclick="startScanner()">
            <i class="fa-solid fa-camera"></i> Start Scanner
        </button>
        <button class="btn-scanner btn-stop" id="btnStop" onclick="stopScanner()" style="display:none;">
            <i class="fa-solid fa-stop"></i> Stop Scanner
        </button>

        <div class="divider">or enter token manually</div>

        <form method="GET" action="" class="manual-wrap">
            <input type="text" name="ref" placeholder="ENTER CLAIM TOKEN"
                maxlength="20"
                value="<?= htmlspecialchars($ref) ?>"
                id="manualInput">
            <button type="submit"><i class="fa-solid fa-search"></i> Look Up</button>
        </form>

        <?php if ($lookup_error): ?>
            <div class="alert alert-error" style="margin-top:14px;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($lookup_error) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Result Card -->
    <?php if ($reg): ?>
    <div class="result-card" id="resultCard">

        <?php if (!$reg['has_spun']): ?>
            <div class="result-status">
                <span class="status-emoji">⏳</span>
                <h3>Not Yet Spun</h3>
                <p>This person has not used the spin wheel yet.</p>
            </div>
            <div class="info-grid">
                <div class="info-row"><span class="label">Name</span><span class="value"><?= htmlspecialchars($reg['full_name']) ?></span></div>
                <div class="info-row"><span class="label">Email</span><span class="value"><?= htmlspecialchars($reg['email']) ?></span></div>
            </div>
            <button class="btn-scan-again" onclick="clearResult()"><i class="fa-solid fa-rotate-left"></i> Scan Another</button>

        <?php elseif ($reg['is_claimed']): ?>
            <div class="result-status">
                <span class="status-emoji">⚠️</span>
                <h3>Already Claimed</h3>
                <p>This prize was already claimed.</p>
            </div>
            <div class="claimed-badge">
                <i class="fa-solid fa-circle-check"></i>
                <p>Claimed on <?= date('M d, Y h:i A', strtotime($reg['claimed_at'])) ?></p>
            </div>
            <div class="info-grid">
                <div class="info-row"><span class="label">Name</span><span class="value"><?= htmlspecialchars($reg['full_name']) ?></span></div>
                <div class="info-row"><span class="label">Prize</span><span class="value"><?= htmlspecialchars($reg['spin_result']) ?></span></div>
                <div class="info-row"><span class="label">Token</span><span class="value" style="letter-spacing:2px;"><?= htmlspecialchars($reg['claim_token']) ?></span></div>
            </div>
            <button class="btn-scan-again" onclick="clearResult()"><i class="fa-solid fa-rotate-left"></i> Scan Another</button>

        <?php else: ?>
            <div class="result-status">
                <span class="status-emoji">🎁</span>
                <h3>Prize Ready to Claim!</h3>
                <p><?= htmlspecialchars($reg['full_name']) ?> has a prize waiting.</p>
            </div>
            <div class="prize-box">
                <div class="prize-label">Prize Won</div>
                <div class="prize-name"><?= htmlspecialchars($reg['spin_result']) ?></div>
            </div>
            <div class="info-grid">
                <div class="info-row"><span class="label">Name</span><span class="value"><?= htmlspecialchars($reg['full_name']) ?></span></div>
                <div class="info-row"><span class="label">Email</span><span class="value"><?= htmlspecialchars($reg['email']) ?></span></div>
                <div class="info-row"><span class="label">Company</span><span class="value"><?= htmlspecialchars($reg['company_name']) ?></span></div>
                <div class="info-row"><span class="label">Token</span><span class="value" style="letter-spacing:2px;"><?= htmlspecialchars($reg['claim_token']) ?></span></div>
            </div>
            <form method="POST" action="<?= BASE_URL ?>spinwheel-mark-claimed">
                <input type="hidden" name="claim_token" value="<?= htmlspecialchars($reg['claim_token']) ?>">
                <input type="hidden" name="redirect_ref" value="<?= htmlspecialchars($ref) ?>">
                <button type="submit" class="btn-claim" id="claimBtn"
                    onclick="this.disabled=true; this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Marking as Claimed...'; this.form.submit();">
                    <i class="fa-solid fa-check-circle"></i> MARK AS CLAIMED
                </button>
            </form>
            <button class="btn-scan-again" onclick="clearResult()"><i class="fa-solid fa-rotate-left"></i> Scan Another</button>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>

<script>
let codeReader = null;
let scanning = false;

function startScanner() {
    const statusEl = document.getElementById('scannerStatus');
    const btnStart = document.getElementById('btnStart');
    const btnStop  = document.getElementById('btnStop');

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
            const text = result.getText();
            // Extract the ref token from the URL or use raw text
            let token = text;
            try {
                const url = new URL(text);
                const ref = url.searchParams.get('ref');
                if (ref) token = ref;
            } catch(e) {
                // Not a URL, use raw text as token
            }
            statusEl.textContent = '✅ QR code detected! Loading...';
            statusEl.className = 'scanner-status success';
            stopScanner();
            window.location.href = '?ref=' + encodeURIComponent(token.toUpperCase());
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
    if (codeReader) {
        codeReader.reset();
        codeReader = null;
    }
    document.getElementById('btnStart').style.display = 'flex';
    document.getElementById('btnStop').style.display = 'none';
    document.getElementById('scannerStatus').textContent = 'Scanner stopped. Press "Start Scanner" to scan again.';
    document.getElementById('scannerStatus').className = 'scanner-status';
}

function clearResult() {
    window.location.href = '<?= BASE_URL ?>spinwheel-claim-scanner';
}

// Auto-scroll to result and collapse scanner if result exists
<?php if ($reg): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Collapse the scanner card
    const scannerCard = document.getElementById('scannerSection');
    scannerCard.style.display = 'none';

    // Show a small "scan another" toggle at top
    const toggle = document.createElement('button');
    toggle.className = 'btn-scan-again';
    toggle.style.marginBottom = '16px';
    toggle.innerHTML = '<i class="fa-solid fa-camera"></i> Scan / Look Up Another';
    toggle.onclick = function() {
        scannerCard.style.display = 'block';
        scannerCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        toggle.style.display = 'none';
    };
    document.querySelector('.page-wrap').insertBefore(toggle, scannerCard);

    // Scroll to result
    const resultCard = document.getElementById('resultCard');
    if (resultCard) {
        setTimeout(function() {
            resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
});
<?php endif; ?>
</script>
</body>
</html>