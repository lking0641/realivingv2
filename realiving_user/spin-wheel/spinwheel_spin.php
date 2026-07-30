<?php
// spinwheel_spin.php
session_name("Realivinguser");
session_start();
include $includes['connection'];

// Check promo active
$spinwheel_status = $conn->query("SELECT is_active FROM spinwheel_settings WHERE id = 1")->fetch_assoc();
if (!$spinwheel_status || $spinwheel_status['is_active'] != 1) {
    header("Location: " . BASE_URL);
    exit();
}

// Validate token
$token = isset($_GET['token']) ? $_GET['token'] : '';
$reg = null;
if ($token) {
    $decoded = base64_decode($token);
    if ($decoded !== false && strpos($decoded, ':') !== false) {
        [$id, $hmac] = explode(':', $decoded, 2);
        $id = (int)$id;
        $expected = hash_hmac('sha256', $id, 'spinwheel_secret_key');
        if (hash_equals($expected, $hmac) && $id > 0) {
            $reg = $conn->query("SELECT * FROM spinwheel_registrations WHERE id = $id LIMIT 1")->fetch_assoc();
        }
    }
}

if (!$reg) {
    header("Location: " . BASE_URL . "wheel");
    exit();
}

// Load segments
$segments_result = $conn->query("SELECT * FROM spinwheel_segments WHERE is_active = 1 ORDER BY id ASC");
$segments = [];
while ($s = $segments_result->fetch_assoc()) $segments[] = $s;

// Generate claim token if missing
if (empty($reg['claim_token'])) {
    $claim_token = strtoupper(substr(hash_hmac('sha256', $reg['id'] . 'claim', 'claim_secret_key'), 0, 12));
    $conn->query("UPDATE spinwheel_registrations SET claim_token='$claim_token' WHERE id={$reg['id']}");
    $reg['claim_token'] = $claim_token;
}
$claim_url = BASE_URL . "spinwheel-verify-claim?ref=" . $reg['claim_token'];

// ── AJAX: check claim status ──
if (isset($_GET['check_claim']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $fresh = $conn->query("SELECT is_claimed, claimed_at FROM spinwheel_registrations WHERE id={$reg['id']} LIMIT 1")->fetch_assoc();
    echo json_encode([
        'is_claimed' => (bool)$fresh['is_claimed'],
        'claimed_at' => $fresh['is_claimed'] ? date('M d, Y h:i A', strtotime($fresh['claimed_at'])) : null
    ]);
    exit();
}

// ── AJAX: pick winner and save ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_spin'])) {
    header('Content-Type: application/json');
    if ($reg['has_spun']) {
        echo json_encode(['error' => 'already_spun']);
        exit();
    }

    // ── Load pity settings ──
    $pity_result = $conn->query("SELECT * FROM spinwheel_pity_settings WHERE pity_threshold > 0 ORDER BY pity_threshold ASC");
    $pity_settings = [];
    while ($p = $pity_result->fetch_assoc()) $pity_settings[] = $p;

    // ── Increment global counter ──
    $conn->query("UPDATE spinwheel_global_counter SET total_spins = total_spins + 1 WHERE id = 1");

    // ── Increment all window counters ──
    if (!empty($pity_settings)) {
        $ids = implode(',', array_column($pity_settings, 'id'));
        $conn->query("UPDATE spinwheel_pity_settings SET current_window_count = current_window_count + 1 WHERE id IN ($ids)");
        // Reload after increment
        $pity_result2 = $conn->query("SELECT * FROM spinwheel_pity_settings WHERE pity_threshold > 0 ORDER BY pity_threshold ASC");
        $pity_settings = [];
        while ($p = $pity_result2->fetch_assoc()) $pity_settings[] = $p;
    }

    // ── Check pity triggers (smallest threshold first) ──
$forced_label = null;
$pity_reset_ids = []; // track kung anong prizes ang na-reset ngayon
foreach ($pity_settings as $pity) {
    if ($pity['current_window_count'] >= $pity['pity_threshold']) {
        if ($pity['window_won'] == 0) {
            $forced_label = $pity['prize_label'];
        }
        $conn->query("UPDATE spinwheel_pity_settings 
                      SET current_window_count = 0, window_won = 0 
                      WHERE id = {$pity['id']}");
        $pity_reset_ids[] = $pity['id']; // itago ang na-reset
    }
}

    // ── Pick winner ──
    $winner = null;

    if ($forced_label) {
        // Find a segment matching the forced label
        foreach ($segments as $seg) {
            if ($seg['label'] === $forced_label) {
                $winner = $seg;
                break;
            }
        }
    }

    // Build list of prizes already won in their current window (should be blocked from winning again)
    $blocked_labels = [];
    foreach ($pity_settings as $pity) {
        if ($pity['window_won'] == 1) {
            $blocked_labels[] = $pity['prize_label'];
        }
    }

    // Fallback to normal random if no forced winner or label not found
    if (!$winner) {
        // Filter out blocked segments (prizes already won in current window)
        $available_segments = array_filter($segments, function($seg) use ($blocked_labels) {
            return !in_array($seg['label'], $blocked_labels);
        });

        // Safety: if all segments are blocked, fall back to full list (shouldn't happen normally)
        if (empty($available_segments)) {
            $available_segments = $segments;
        }

        $total_weight = array_sum(array_column($available_segments, 'probability'));
        $rand = rand(1, $total_weight);
        $cumulative = 0;
        $winner = array_values($available_segments)[0];
        foreach ($available_segments as $seg) {
            $cumulative += $seg['probability'];
            if ($rand <= $cumulative) { $winner = $seg; break; }
        }
    }

    // ── If winner is a real prize (not Thank you), mark window as won ──
$thank_you_labels = ['Thank you for Joining'];
$is_real_prize = !in_array($winner['label'], $thank_you_labels);

if ($is_real_prize) {
    $fresh_pity = $conn->query("SELECT * FROM spinwheel_pity_settings WHERE pity_threshold > 0");
    while ($fp = $fresh_pity->fetch_assoc()) {
        // SKIP kung na-reset na ngayong spin na ito (fresh window pa lang)
        if (in_array($fp['id'], $pity_reset_ids)) continue;
        
        if ($winner['label'] === $fp['prize_label'] && $fp['window_won'] == 0) {
            $conn->query("UPDATE spinwheel_pity_settings 
                          SET window_won = 1 
                          WHERE id = {$fp['id']}");
        }
    }
}

    // ── Save result ──
    $prize_label = $conn->real_escape_string($winner['label']);
    $conn->query("UPDATE spinwheel_registrations 
                  SET has_spun=1, spin_result='$prize_label', spun_at=NOW() 
                  WHERE id={$reg['id']}");

    // ── Get prize index for wheel animation ──
    $prize_index = 0;
    foreach ($segments as $i => $seg) {
        if ($seg['id'] === $winner['id']) { $prize_index = $i; break; }
    }

    echo json_encode([
        'prize'       => $winner['label'],
        'prize_index' => $prize_index,
        'color'       => $winner['color'],
        'forced'      => $forced_label ? true : false
    ]);
    exit();
}

// ── Normal page load ──
$spin_done = false;
$spin_prize = '';
if ($reg['has_spun']) {
    $spin_prize = $reg['spin_result'];
}
$already_spun = $reg['has_spun'];

$already_spun = $reg['has_spun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spin the Wheel | Realiving</title>
    <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Montserrat', sans-serif; background: #fafafa; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .spin-card { background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(47,18,0,0.12); max-width: 500px; width: 100%; padding: 24px 16px; text-align: center; }
        h1 { font-size: 20px; font-weight: 800; color: #2f1200; margin-bottom: 6px; letter-spacing: 1px; }
        .sub { font-size: 13px; color: #888; margin-bottom: 24px; }
        .wheel-wrap { position: relative; width: min(300px, 80vw); height: min(300px, 80vw); margin: 0 auto 24px; overflow: visible; }
        #wheel-canvas { border-radius: 50%; box-shadow: 0 8px 30px rgba(47,18,0,0.2); display: block; }
        .pointer-wrap { position: absolute; top: -24px; left: 50%; transform: translateX(-50%); z-index: 10; display: flex; flex-direction: column; align-items: center; }
.pointer-body { width: 22px; height: 36px; background: #2f1200; clip-path: polygon(50% 100%, 0% 0%, 100% 0%); filter: drop-shadow(0 4px 8px rgba(0,0,0,0.4)); }
.pointer-base { width: 16px; height: 16px; border-radius: 50%; background: #c4905c; border: 3px solid #2f1200; margin-top: -3px; }
.center-cap { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 28px; height: 28px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #e0a87c, #7c3a00); border: 3px solid #2f1200; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
.wheel-ring { position: absolute; inset: -10px; border-radius: 50%; background: conic-gradient(#7c3a00, #c4905c, #7c3a00, #c4905c, #7c3a00); box-shadow: 0 0 0 3px #7c3a00; z-index: 1; }
        .spin-btn { padding: 14px 32px; background: #2f1200; color: #fff; border: none; border-radius: 40px; font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 14px; letter-spacing: 1px; cursor: pointer; position: relative; overflow: hidden; transition: transform 0.15s, opacity 0.15s; width: 100%; max-width: 280px; }
        .spin-btn::before { content: ''; position: absolute; top: 0; left: -60%; width: 40%; height: 100%; background: rgba(255,255,255,0.15); transform: skewX(-20deg); animation: sheen 2.5s infinite; }
        @keyframes sheen { 0% { left: -60%; } 60%, 100% { left: 140%; } }
        .spin-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(47,18,0,0.3); }
        .spin-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .prize-banner { background: linear-gradient(135deg, #c4905c, #2f1200); color: #fff; border-radius: 14px; padding: 20px; margin-top: 20px; }
        .prize-banner h2 { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
        .prize-banner p { font-size: 13px; opacity: 0.9; }
        .already-msg { padding: 20px; background: #fdf3e3; border-radius: 12px; color: #7a4a1a; font-size: 14px; }
        .back-link { display: inline-block; margin-top: 20px; color: #2f1200; font-size: 12px; font-weight: 700; letter-spacing: 1px; text-decoration: none; border-bottom: 2px solid #c4905c; padding-bottom: 2px; }

        .qr-card { background: #fff; border: 2px solid #e8d8c8; border-radius: 16px; padding: 20px; margin-top: 20px; text-align: center; }
        .qr-card h3 { font-size: 14px; font-weight: 800; color: #2f1200; margin: 0 0 4px; letter-spacing: 1px; }
        .qr-card p { font-size: 11px; color: #888; margin: 0 0 14px; line-height: 1.6; }
        .qr-box { display: inline-block; padding: 10px; background: #fff; border: 1px solid #eee; border-radius: 10px; }
        .qr-token { margin-top: 12px; font-size: 13px; font-weight: 800; color: #2f1200; letter-spacing: 3px; background: #f8f4f0; padding: 8px 16px; border-radius: 8px; display: inline-block; }
        .qr-hint { margin-top: 10px; font-size: 11px; color: #aaa; }
    </style>
</head>
<body>
<div class="spin-card">
    <h1>🎡 SPIN TO WIN</h1>
    <p class="sub">Hello, <strong><?= htmlspecialchars($reg['full_name']) ?></strong>! You get one spin.</p>

    <?php 
$is_thank_you = ($reg['spin_result'] === 'Thank you for Joining');
?>
<?php if ($already_spun && !$spin_done): ?>
    <div class="already-msg">
        <i class="fa-solid fa-circle-check" style="color:#c4905c; font-size:28px; display:block; margin-bottom:10px;"></i>
        You've already spun the wheel!<br>
        <strong>Your prize: <?= htmlspecialchars($reg['spin_result']) ?></strong><br><br>
        <?php if ($is_thank_you): ?>
            Thank you for joining our event! See you at the next one.
        <?php else: ?>
            Show the QR code below to the staff to claim your prize.
        <?php endif; ?>
    </div>

    <?php if ($is_thank_you): ?>
        <!-- Thank you — no QR needed -->
        <div style="background:#fdf3e3; border-radius:14px; padding:20px; margin-top:16px; text-align:center;">
            <div style="font-size:40px; margin-bottom:10px;">🙏</div>
            <div style="font-size:16px; font-weight:800; color:#2f1200; margin-bottom:8px;">Thank You for Joining!</div>
            <div style="font-size:13px; color:#7a4a1a; line-height:1.7;">
                We appreciate your participation.<br>
                Visit us again at our next event for more chances to win!
            </div>
        </div>
        <a href="<?= BASE_URL ?>" class="back-link">BACK TO HOME</a>

    <?php else: ?>
        <!-- Real prize — show claim status + QR -->
        <div id="claimStatusBadge" style="margin-top:16px; padding:12px 16px; border-radius:10px; font-size:13px; font-weight:700; text-align:center;
            background:<?= $reg['is_claimed'] ? '#f0fdf4' : '#fffbeb' ?>;
            color:<?= $reg['is_claimed'] ? '#16a34a' : '#d97706' ?>;
            border:1px solid <?= $reg['is_claimed'] ? '#bbf7d0' : '#fde68a' ?>;">
            <?php if ($reg['is_claimed']): ?>
                <i class="fa-solid fa-circle-check"></i> Prize already claimed on <?= date('M d, Y h:i A', strtotime($reg['claimed_at'])) ?>
            <?php else: ?>
                <i class="fa-solid fa-clock"></i> <span id="claimStatusText">Waiting to be claimed... Show QR to booth staff.</span>
            <?php endif; ?>
        </div>

        <div class="qr-card">
            <h3>🎁 YOUR CLAIM QR CODE</h3>
            <p>Show this to our booth staff to claim your prize.<br>This is unique to your registration.</p>
            <div class="qr-box">
                <div id="qrcode-static"></div>
            </div>
            <div class="qr-token"><?= htmlspecialchars($reg['claim_token']) ?></div>
            <p class="qr-hint">Can't scan? Staff can type the code above manually.</p>
        </div>
        <a href="<?= BASE_URL ?>" class="back-link">BACK TO HOME</a>

        <?php if (!$reg['is_claimed']): ?>
        <script>
        (function() {
            const badge = document.getElementById('claimStatusBadge');
            function checkClaim() {
                fetch(location.href + '&check_claim=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r => r.json())
                    .then(data => {
                        if (data.is_claimed) {
                            badge.style.background = '#f0fdf4';
                            badge.style.color = '#16a34a';
                            badge.style.border = '1px solid #bbf7d0';
                            badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> 🎉 Prize claimed on ' + data.claimed_at + '! Thank you!';
                            clearInterval(pollInterval);
                        }
                    })
                    .catch(() => {});
            }
            const pollInterval = setInterval(checkClaim, 5000);
        })();
        </script>
        <?php endif; ?>
        <script>
        new QRCode(document.getElementById('qrcode-static'), {
            text: '<?= addslashes($claim_url) ?>',
            width: 180,
            height: 180,
            colorDark: '#2f1200',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
        </script>
    <?php endif; ?>
        <!-- Already spun state (covers both fresh win and page refresh) -->
        <div class="already-msg">
            <i class="fa-solid fa-circle-check" style="color:#c4905c; font-size:36px; display:block; margin-bottom:12px;"></i>
            <strong style="font-size:16px; color:#2f1200; display:block; margin-bottom:8px;">
                🎉 You won: <?= htmlspecialchars($reg['spin_result']) ?>!
            </strong>
            Please claim your prize at our booth.<br>Show this screen or your registered email.
        </div>
        <a href="<?= BASE_URL ?>" class="back-link">BACK TO HOME</a>
        <div class="prize-banner">
            <h2>🎉 You won: <?= htmlspecialchars($spin_prize) ?>!</h2>
            <p>Please claim your prize at our booth. Show this screen or your registered email.</p>
        </div>
        <a href="<?= BASE_URL ?>" class="back-link">BACK TO HOME</a>
        <script>
        const segments = <?= json_encode($segments) ?>;
        const prize = <?= json_encode($spin_prize) ?>;
        const canvas = document.getElementById('wheel-canvas');
        const ctx = canvas.getContext('2d');
        const n = segments.length;
        const arc = (2 * Math.PI) / n;

        // Find prize index
        let prizeIdx = segments.findIndex(s => s.label === prize);
        if (prizeIdx < 0) prizeIdx = 0;

        // Spin animation to land on prize
        let startAngle = 0;
        const targetAngle = (2 * Math.PI * 5) + (2 * Math.PI - (prizeIdx * arc + arc / 2));
        let current = 0;
        const duration = 4000;
        let start = null;

        function easeOut(t) { return 1 - Math.pow(1 - t, 4); }

        function drawWheel(angle) {
    const SW = 300; // static size
    ctx.clearRect(0, 0, SW, SW);
    const SCX = SW / 2;
    const SCR = SW / 2 - 2;
    const segCount = segments.length;

    segments.forEach((seg, i) => {
        const a = angle + i * arc;
        ctx.beginPath();
        ctx.moveTo(SCX, SCX);
        ctx.arc(SCX, SCX, SCR, a, a + arc);
        ctx.closePath();
        ctx.fillStyle = seg.color;
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.save();
        ctx.translate(SCX, SCX);
        ctx.rotate(a + arc / 2);

        const baseFontSize = Math.max(7, Math.min(13, Math.round(SCR * 0.13 - segCount * 0.3)));
        ctx.font = `bold ${baseFontSize}px Montserrat, sans-serif`;
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'left';
        ctx.shadowColor = 'rgba(0,0,0,0.5)';
        ctx.shadowBlur = 3;

        const textStart = SCR * 0.22;
        const textEnd = SCR * 0.92;
        const maxWidth = textEnd - textStart;

        let label = seg.label;
        while (ctx.measureText(label).width > maxWidth && label.length > 3) {
            label = label.slice(0, -1);
        }
        if (label !== seg.label) label = label.slice(0, -1) + '…';

        ctx.fillText(label, textStart, baseFontSize * 0.35);
        ctx.restore();
    });

    ctx.beginPath();
    ctx.arc(SCX, SCX, SCR * 0.09, 0, 2 * Math.PI);
    ctx.fillStyle = '#2f1200';
    ctx.fill();
}

        function animate(ts) {
            if (!start) start = ts;
            const elapsed = ts - start;
            const progress = Math.min(elapsed / duration, 1);
            current = easeOut(progress) * targetAngle;
            drawWheel(current);
            if (progress < 1) requestAnimationFrame(animate);
            else drawWheel(targetAngle % (2 * Math.PI));
        }

        drawWheel(0);
        requestAnimationFrame(animate);
        </script>
    <?php else: ?>
        <!-- Ready to spin -->
        <div class="wheel-wrap">
            <div class="wheel-ring"></div>
            <div class="pointer-wrap">
                <div class="pointer-body"></div>
                <div class="pointer-base"></div>
            </div>
            <canvas id="wheel-canvas" width="300" height="300" style="position:relative; z-index:2;"></canvas>
            <div class="center-cap"></div>
        </div>
        <div id="spinBtnWrap">
            <button type="button" class="spin-btn" id="spinBtn" onclick="doSpin()">
                <i class="fa-solid fa-circle-play"></i> SPIN THE WHEEL
            </button>
        </div>

        <div id="prizeReveal" style="display:none; margin-top:20px;">
    <div class="prize-banner">
        <div style="font-size:36px; margin-bottom:8px;">🎉</div>
        <h2 id="prizeLabel"></h2>
        <p id="prizeSubtext">Show the QR code below to our booth staff to claim your prize.</p>
    </div>

    <!-- Only shown for real prizes -->
    <div id="qrSection">
        <div class="qr-card">
            <h3>🎁 YOUR CLAIM QR CODE</h3>
            <p>Show this to our booth staff to claim your prize.<br>This is unique to your registration.</p>
            <div class="qr-box">
                <div id="qrcode-result"></div>
            </div>
            <div class="qr-token"><?= htmlspecialchars($reg['claim_token']) ?></div>
            <p class="qr-hint">Can't scan? Staff can type the code above manually.</p>
        </div>
    </div>

    <!-- Only shown for Thank you for Joining -->
    <div id="thankYouSection" style="display:none;">
        <div style="background:#fdf3e3; border-radius:14px; padding:20px; margin-top:16px; text-align:center;">
            <div style="font-size:40px; margin-bottom:10px;">🙏</div>
            <div style="font-size:16px; font-weight:800; color:#2f1200; margin-bottom:8px;">Thank You for Joining!</div>
            <div style="font-size:13px; color:#7a4a1a; line-height:1.7;">
                We appreciate your participation in our Spin to Win promo.<br>
                Visit us again at our next event for more chances to win!
            </div>
        </div>
    </div>

    <a href="<?= BASE_URL ?>" class="back-link" style="margin-top:16px; display:inline-block;">BACK TO HOME</a>
</div>

        <script>
        const segments = <?= json_encode(array_values($segments)) ?>;
        console.log('Segments loaded:', segments);
        if (!segments || segments.length === 0) {
            document.querySelector('.wheel-wrap').innerHTML = '<div style="padding:40px; color:#c4905c; font-weight:700;">No active prize segments found.<br>Please contact the admin.</div>';
        }
        const spinToken = <?= json_encode($_GET['token'] ?? '') ?>;
        const canvas = document.getElementById('wheel-canvas');
        const ctx = canvas.getContext('2d');

        // Responsive canvas size
        const wrapSize = Math.min(300, window.innerWidth * 0.80);
        canvas.width = wrapSize;
        canvas.height = wrapSize;
        const CX = wrapSize / 2;
        const CY = wrapSize / 2;
        const CR = wrapSize / 2 - 2;

        const n = segments.length;
        const arc = (2 * Math.PI) / n;
        let currentAngle = -Math.PI / 2;
        let spinning = false;

        function drawWheel(angle) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    segments.forEach((seg, i) => {
        const startA = angle + i * arc;
        const endA = startA + arc;

        // Draw segment
        ctx.beginPath();
        ctx.moveTo(CX, CY);
        ctx.arc(CX, CY, CR, startA, endA);
        ctx.closePath();
        ctx.fillStyle = seg.color;
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Label — responsive font + smart truncation
        ctx.save();
        ctx.translate(CX, CY);
        ctx.rotate(startA + arc / 2);

        // Font size based on segment count and canvas size
        const segCount = segments.length;
        const baseFontSize = Math.max(7, Math.min(13, Math.round(CR * 0.13 - segCount * 0.3)));
        ctx.font = `bold ${baseFontSize}px Montserrat, sans-serif`;
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'left';
        ctx.shadowColor = 'rgba(0,0,0,0.5)';
        ctx.shadowBlur = 3;

        // Available width for text (from center outward)
        const textStart = CR * 0.22; // start from 22% of radius (near center)
        const textEnd = CR * 0.92;   // end at 92% of radius (near edge)
        const maxWidth = textEnd - textStart;

        // Smart truncation
        let label = seg.label;
        while (ctx.measureText(label).width > maxWidth && label.length > 3) {
            label = label.slice(0, -1);
        }
        if (label !== seg.label) label = label.slice(0, -1) + '…';

        ctx.fillText(label, textStart, baseFontSize * 0.35);
        ctx.restore();
    });

            // Spokes
            for (let i = 0; i < n; i++) {
                const spokeAngle = angle + i * arc;
                ctx.save();
                ctx.translate(CX, CY);
                ctx.rotate(spokeAngle);
                ctx.strokeStyle = 'rgba(255,255,255,0.18)';
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(0, 0);
                ctx.lineTo(CR, 0);
                ctx.stroke();
                ctx.restore();
            }

            // Outer border
            ctx.beginPath();
            ctx.arc(CX, CY, CR, 0, 2 * Math.PI);
            ctx.strokeStyle = '#2f1200';
            ctx.lineWidth = 3;
            ctx.stroke();

            // Alternating dots around rim
            const dotCount = Math.round(CR * 0.75);
            const dotR = Math.max(2, CR * 0.02);
            for (let i = 0; i < dotCount; i++) {
                const a = angle + (i / dotCount) * 2 * Math.PI;
                const bx = CX + Math.cos(a) * (CR + 1);
                const by = CY + Math.sin(a) * (CR + 1);
                ctx.beginPath();
                ctx.arc(bx, by, dotR, 0, 2 * Math.PI);
                ctx.fillStyle = i % 2 === 0 ? '#c4905c' : '#2f1200';
                ctx.fill();
            }

            // Center cap
            ctx.beginPath();
            ctx.arc(CX, CY, CR * 0.09, 0, 2 * Math.PI);
            ctx.fillStyle = '#7c3a00';
            ctx.fill();
        }

        drawWheel(currentAngle);

        function easeOut(t) { return 1 - Math.pow(1 - t, 4); }

        function spinToIndex(prizeIndex, onDone) {
            const extraSpins = (6 + Math.floor(Math.random() * 3)) * 2 * Math.PI;

            // The pointer is at the TOP of the canvas (12 o'clock position)
            // In canvas coords, top = -PI/2
            // Segment i occupies from: i*arc to (i+1)*arc, relative to currentAngle
            // Segment center = currentAngle + i*arc + arc/2
            // We want segment center to be at -PI/2 after rotation R:
            //   currentAngle + R + prizeIndex*arc + arc/2 = -PI/2 + k*2PI
            // So: R = -PI/2 - currentAngle - prizeIndex*arc - arc/2 + k*2PI
            
            // Pointer is at top (-PI/2). We need the prize segment's center to end up there.
            // After rotation by totalRotation, angle of seg center = currentAngle + totalRotation + prizeIndex*arc + arc/2
            // We want that ≡ -PI/2 (mod 2PI)
            const pointerAngle = -Math.PI / 2;
            const segCenter = prizeIndex * arc + arc / 2;

            let R = pointerAngle - currentAngle - segCenter;
            // Normalize to [0, 2PI) so wheel always spins forward
            R = ((R % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
            if (R < 0.01) R += 2 * Math.PI; // avoid near-zero (no spin)

            const totalRotation = extraSpins + R;
            const startAngle = currentAngle;
            const duration = 5000;
            let startTime = null;

            function animate(ts) {
                if (!startTime) startTime = ts;
                const elapsed = ts - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = easeOut(progress);
                const angle = startAngle + eased * totalRotation;
                drawWheel(angle);
                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    currentAngle = startAngle + totalRotation;
                    drawWheel(currentAngle);
                    onDone();
                }
            }
            requestAnimationFrame(animate);
        }

        async function doSpin() {
            if (spinning) return;
            spinning = true;

            const btn = document.getElementById('spinBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SPINNING...';

            let result = null;

            try {
                const resp = await fetch(location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'ajax_spin=1&token=' + encodeURIComponent(spinToken)
                });
                const text = await resp.text();
                console.log('Raw server response:', text);
                result = JSON.parse(text);
            } catch(e) {
                console.error('Fetch/parse error:', e);
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-play"></i> SPIN THE WHEEL';
                spinning = false;
                alert('Connection error. Please try again.');
                return;
            }

            if (!result) {
                alert('No response from server.');
                spinning = false;
                return;
            }

            if (result.error === 'already_spun') {
                location.reload();
                return;
            }

            spinToIndex(Number(result.prize_index), function() {
    document.getElementById('spinBtnWrap').style.display = 'none';
    document.getElementById('prizeReveal').style.display = 'block';

    const thankYouLabels = ['Thank you for Joining'];
    const isThankYou = thankYouLabels.includes(result.prize);

    if (isThankYou) {
        // Thank you — walang QR, walang claim
        document.getElementById('prizeLabel').textContent = '🎊 ' + result.prize + '!';
        document.getElementById('prizeSubtext').style.display = 'none';
        document.getElementById('qrSection').style.display = 'none';
        document.getElementById('thankYouSection').style.display = 'block';
    } else {
        // Real prize — show QR
        document.getElementById('prizeLabel').textContent = '🏆 You won: ' + result.prize + '!';
        document.getElementById('prizeSubtext').style.display = 'block';
        document.getElementById('qrSection').style.display = 'block';
        document.getElementById('thankYouSection').style.display = 'none';
        new QRCode(document.getElementById('qrcode-result'), {
            text: '<?= addslashes($claim_url) ?>',
            width: 180,
            height: 180,
            colorDark: '#2f1200',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
    }
});
        }
        </script>
    <?php endif; ?>
</div>
</body>
</html>