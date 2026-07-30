<?php
// spinwheel_verify_claim.php
session_name("Realivinguser");
session_start();
include $includes['connection'];

// Check promo active
$spinwheel_status = $conn->query("SELECT is_active FROM spinwheel_settings WHERE id = 1")->fetch_assoc();
if (!$spinwheel_status || $spinwheel_status['is_active'] != 1) {
    header("Location: " . BASE_URL);
    exit();
}

$reg = null;
$error = '';
$ref_esc = '';

if (isset($_GET['ref'])) {
    $ref = strtoupper(trim($_GET['ref']));
    $ref_esc = $conn->real_escape_string($ref);
    $reg = $conn->query("SELECT * FROM spinwheel_registrations WHERE claim_token = '$ref_esc' LIMIT 1")->fetch_assoc();
    if (!$reg) {
        $error = "Invalid or unrecognized claim code.";
    }
} else {
    header("Location: " . BASE_URL);
    exit();
}

// AJAX: check claim status (must be before HTML output)
if (isset($_GET['check_claim']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $fresh = $conn->query("SELECT is_claimed, claimed_at FROM spinwheel_registrations WHERE claim_token = '$ref_esc' LIMIT 1")->fetch_assoc();
    echo json_encode([
        'is_claimed' => (bool)$fresh['is_claimed'],
        'claimed_at' => $fresh['is_claimed'] ? date('M d, Y h:i A', strtotime($fresh['claimed_at'])) : null
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prize Claim | Realiving</title>
    <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #fafafa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .claim-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(47, 18, 0, 0.12);
            max-width: 480px;
            width: 100%;
            padding: 32px 28px;
        }

        .back-link-top {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 24px;
            color: #2f1200;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            transition: color 0.2s, gap 0.2s;
        }
        .back-link-top:hover { color: #c4905c; gap: 10px; }

        .claim-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .claim-header .icon-wrap {
            width: 68px;
            height: 68px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
        }
        .icon-wrap.won     { background: #fff8f0; }
        .icon-wrap.error   { background: #fdecea; }
        .icon-wrap.waiting { background: #fffbeb; }

        .claim-header h1 {
            font-size: 20px;
            font-weight: 800;
            color: #2f1200;
            margin-bottom: 6px;
            letter-spacing: 1px;
        }
        .claim-header p {
            font-size: 13px;
            color: #888;
            line-height: 1.6;
        }

        .info-grid {
            border: 1px solid #f1f0ef;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f0ef;
            font-size: 13px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl {
            font-size: 11px;
            font-weight: 700;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .info-row .val {
            font-weight: 700;
            color: #2f1200;
            text-align: right;
            max-width: 65%;
        }

        .prize-box {
            background: linear-gradient(135deg, #fff8f0, #fef3e2);
            border: 2px solid #f0d9bf;
            border-radius: 14px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .prize-box .prize-eyebrow {
            font-size: 11px;
            font-weight: 700;
            color: #c4905c;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .prize-box .prize-name {
            font-size: 26px;
            font-weight: 800;
            color: #2f1200;
        }

        .status-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        .status-badge.claimed {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .status-badge.unclaimed {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .status-badge i { font-size: 18px; flex-shrink: 0; }

        .divider {
            border: none;
            border-top: 1px solid #f0ece8;
            margin: 4px 0 20px;
        }

        @media (max-width: 480px) {
            body { padding: 12px; align-items: flex-start; padding-top: 20px; }
            .claim-card { padding: 20px 16px; }
            .claim-header h1 { font-size: 17px; }
            .prize-box .prize-name { font-size: 21px; }
        }
    </style>
</head>
<body>

<div class="claim-card">

    <a href="<?= BASE_URL ?>" class="back-link-top">
        <i class="fa-solid fa-arrow-left"></i> BACK TO HOME
    </a>

    <?php if ($error): ?>

        <div class="claim-header">
            <div class="icon-wrap error">❌</div>
            <h1>INVALID CODE</h1>
            <p>We couldn't find a registration matching this claim code. Please double-check the QR code or token and try again.</p>
        </div>

    <?php elseif (!$reg['has_spun']): ?>

        <div class="claim-header">
            <div class="icon-wrap waiting">⏳</div>
            <h1>NOT YET SPUN</h1>
            <p>This registration hasn't used the spin wheel yet.</p>
        </div>
        <div class="info-grid">
            <div class="info-row">
                <span class="lbl">Name</span>
                <span class="val"><?= htmlspecialchars($reg['full_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Company</span>
                <span class="val"><?= htmlspecialchars($reg['company_name']) ?></span>
            </div>
        </div>

    <?php else: ?>

        <div class="claim-header">
            <div class="icon-wrap won">🎁</div>
            <h1>PRIZE CLAIM</h1>
            <p>Registration details for <strong><?= htmlspecialchars($reg['full_name']) ?></strong>.</p>
        </div>

        <div class="prize-box">
            <div class="prize-eyebrow">🏆 Prize Won</div>
            <div class="prize-name"><?= htmlspecialchars($reg['spin_result']) ?></div>
        </div>

        <?php if ($reg['is_claimed']): ?>
            <div class="status-badge claimed">
                <i class="fa-solid fa-circle-check"></i>
                <span>Claimed on <?= date('M d, Y h:i A', strtotime($reg['claimed_at'])) ?></span>
            </div>
        <?php else: ?>
            <div class="status-badge unclaimed" id="claimStatusBadge">
                <i class="fa-solid fa-clock"></i>
                <span id="claimStatusText">Not yet claimed — show this to booth staff.</span>
            </div>
        <?php endif; ?>

        <hr class="divider">

        <div class="info-grid">
            <div class="info-row">
                <span class="lbl">Name</span>
                <span class="val"><?= htmlspecialchars($reg['full_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Email</span>
                <span class="val"><?= htmlspecialchars($reg['email']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Company</span>
                <span class="val"><?= htmlspecialchars($reg['company_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Position</span>
                <span class="val"><?= htmlspecialchars($reg['position']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Spun on</span>
                <span class="val"><?= date('M d, Y h:i A', strtotime($reg['spun_at'])) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Claim Token</span>
                <span class="val" style="letter-spacing:2px; font-size:12px;"><?= htmlspecialchars($reg['claim_token']) ?></span>
            </div>
        </div>

        <?php if (!$reg['is_claimed']): ?>
        <script>
        (function() {
            const badge = document.getElementById('claimStatusBadge');
            const statusText = document.getElementById('claimStatusText');

            function checkClaim() {
                fetch(location.href + '&check_claim=1', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.is_claimed) {
                        badge.className = 'status-badge claimed';
                        badge.querySelector('i').className = 'fa-solid fa-circle-check';
                        statusText.textContent = '🎉 Claimed on ' + data.claimed_at + '! Thank you!';
                        clearInterval(pollInterval);
                    }
                })
                .catch(() => {});
            }

            const pollInterval = setInterval(checkClaim, 1500);
        })();
        </script>
        <?php endif; ?>

    <?php endif; ?>

</div>
</body>
</html>