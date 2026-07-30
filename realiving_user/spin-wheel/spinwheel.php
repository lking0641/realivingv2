<?php
//spinwheel.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_name("Realivinguser");
session_start();
include $includes['connection'];

// ── Check if promo is active ──
$spinwheel_status = $conn->query("SELECT is_active FROM spinwheel_settings WHERE id = 1")->fetch_assoc();
$spinwheel_active = $spinwheel_status && $spinwheel_status['is_active'] == 1;

if (!$spinwheel_active) {
    header("Location: " . BASE_URL);
    exit();
}

$form_success = false;
$form_error = "";
$lookup_error = '';
$otp_error = '';
$otp_step = false; // are we on the OTP verification step?

// ── Returning user lookup ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spinwheel_lookup_submit'])) {
    $lookup_email = trim($_POST['lookup_email']);
    $lookup_esc = $conn->real_escape_string($lookup_email);
    $reg = $conn->query("SELECT id, has_spun FROM spinwheel_registrations WHERE email = '$lookup_esc' LIMIT 1")->fetch_assoc();
    if (!$reg) {
        $lookup_error = "Email not found. Please register first.";
    } elseif ($reg['has_spun']) {
        $spin_token = base64_encode($reg['id'] . ':' . hash_hmac('sha256', $reg['id'], 'spinwheel_secret_key'));
        header("Location: " . BASE_URL . "spinwheel-spin?token=" . urlencode($spin_token));
        exit();
    } else {
        $spin_token = base64_encode($reg['id'] . ':' . hash_hmac('sha256', $reg['id'], 'spinwheel_secret_key'));
        header("Location: " . BASE_URL . "spinwheel-spin?token=" . urlencode($spin_token));
        exit();
    }
}

// ── STEP 1: Form submitted → validate → send OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spinwheel_register_submit'])) {
    $full_name    = trim($_POST['full_name']);
    $phone        = trim($_POST['phone']);
    $email        = trim($_POST['email']);
    $company_name = trim($_POST['company_name']);
    $position     = trim($_POST['position'] ?? '');

    $is_student = isset($_POST['is_student']);
    if ($is_student) $position = 'Student';

    if (!isset($_POST['philcon_confirm'])) {
        $form_error = "This promo is exclusive to Philcon Event attendees. Please confirm your attendance.";
    } elseif (empty($full_name) || empty($phone) || empty($email) || empty($company_name) || empty($position)) {
        $form_error = "All fields are required.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $form_error = "Phone number must be 11 digits starting with 09.";
    } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        $form_error = "Only @gmail.com email addresses are allowed.";
    } else {
        $email_esc = $conn->real_escape_string($email);
        $check = $conn->prepare("SELECT id FROM spinwheel_registrations WHERE email = ?");
        $check->bind_param("s", $email_esc);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $form_error = "This email is already registered.";
        } else {
            // Generate OTP and store in session
            $otp = strval(random_int(100000, 999999));
            $_SESSION['sw_otp']          = $otp;
            $_SESSION['sw_otp_email']    = $email;
            $_SESSION['sw_otp_expires']  = time() + 300; // 5 min
            $_SESSION['sw_pending']      = [
                'full_name'    => $full_name,
                'phone'        => $phone,
                'email'        => $email,
                'company_name' => $company_name,
                'position'     => $position,
            ];

            // Send OTP email using existing PHPMailer setup
            require_once ROOT_PATH . 'vendor/autoload.php';

            $mail = new PHPMailer(true);
            $mail_sent = false;
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'realivingwebsite@gmail.com';
                $mail->Password   = 'foudsaptlzlwbvst';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->setFrom('realivingwebsite@gmail.com', 'Realiving');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Your Spin to Win OTP Code - Realiving';
                $mail->Body = '
                <div style="font-family:Arial,sans-serif;background:#f9f9f9;padding:24px;border-radius:10px;max-width:480px;margin:0 auto;">
                    <h2 style="color:#2f1200;text-align:center;margin-bottom:8px;">🎡 Spin to Win - Email Verification</h2>
                    <p style="color:#555;text-align:center;margin-bottom:24px;">Enter this code to verify your Gmail and complete your registration.</p>
                    <div style="background:#fff;border:2px dashed #c4905c;border-radius:12px;padding:24px;text-align:center;margin-bottom:24px;">
                        <div style="font-size:40px;font-weight:900;letter-spacing:10px;color:#2f1200;">' . $otp . '</div>
                        <p style="color:#aaa;font-size:12px;margin-top:8px;">This code expires in <strong>5 minutes</strong></p>
                    </div>
                    <p style="color:#888;font-size:12px;text-align:center;">If you did not request this, please ignore this email.</p>
                </div>';
                $mail->send();
                $mail_sent = true;
            } catch (Exception $e) {
                $form_error = "Could not send OTP to your Gmail. Please check your email address and try again.";
            }

            if ($mail_sent) {
                $otp_step = true; // show OTP form
            }
        }
        $check->close();
    }
}

// ── STEP 2: OTP submitted → verify → register ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spinwheel_otp_submit'])) {
    $entered_otp = trim($_POST['otp_code']);
    $otp_step = true; // stay on OTP screen on error

    if (empty($_SESSION['sw_otp']) || empty($_SESSION['sw_pending'])) {
        $otp_error = "Session expired. Please go back and fill the form again.";
        $otp_step = false;
    } elseif (time() > $_SESSION['sw_otp_expires']) {
        $otp_error = "OTP has expired. Please go back and register again.";
        unset($_SESSION['sw_otp'], $_SESSION['sw_pending'], $_SESSION['sw_otp_email'], $_SESSION['sw_otp_expires']);
        $otp_step = false;
    } elseif ($entered_otp !== $_SESSION['sw_otp']) {
        $otp_error = "Incorrect OTP. Please check your Gmail and try again.";
    } else {
        // OTP correct — save registration
        $p = $_SESSION['sw_pending'];
        $full_name_esc    = $conn->real_escape_string($p['full_name']);
        $phone_esc        = $conn->real_escape_string($p['phone']);
        $email_esc        = $conn->real_escape_string($p['email']);
        $company_name_esc = $conn->real_escape_string($p['company_name']);
        $position_esc     = $conn->real_escape_string($p['position']);

        $stmt = $conn->prepare("INSERT INTO spinwheel_registrations (full_name, phone, email, company_name, position) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $full_name_esc, $phone_esc, $email_esc, $company_name_esc, $position_esc);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $claim_token = strtoupper(substr(hash_hmac('sha256', $new_id . 'claim', 'claim_secret_key'), 0, 12));
            $conn->query("UPDATE spinwheel_registrations SET claim_token='$claim_token' WHERE id=$new_id");
            unset($_SESSION['sw_otp'], $_SESSION['sw_pending'], $_SESSION['sw_otp_email'], $_SESSION['sw_otp_expires']);
            $spin_token = base64_encode($new_id . ':' . hash_hmac('sha256', $new_id, 'spinwheel_secret_key'));
            header("Location: " . BASE_URL . "spinwheel-spin?token=" . urlencode($spin_token));
            exit();
        } else {
            $otp_error = "Something went wrong saving your registration. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spin to Win - Registration | Realiving Design Center</title>
    <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">
    <link
        href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap&font-display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="<?= CLIENT_ASSET ?>/css/style.css?v=1.0.3">
    <style>
        body {
            margin: 0;
            font-family: 'Montserrat', sans-serif;
            background: #fafafa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .register-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(47, 18, 0, 0.12);
            max-width: 480px;
            width: 100%;
            padding: 32px 28px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .register-header .wheel-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 12px;
            display: block;
        }

        .register-header h1 {
            font-size: 22px;
            font-weight: 800;
            color: #2f1200;
            margin: 0 0 6px;
            letter-spacing: 1px;
        }

        .register-header p {
            font-size: 13px;
            color: #777;
            margin: 0;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #2f1200;
            margin-bottom: 6px;
        }

        .form-group label i {
            color: #c4905c;
            margin-right: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Montserrat', sans-serif;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2f1200;
            box-shadow: 0 0 0 3px rgba(47, 18, 0, 0.08);
        }

        .form-group input.input-error {
            border-color: #e63946;
        }

        .form-group input.input-success {
            border-color: #2a9d8f;
        }

        /* Validation message span */
        .field-msg {
            display: block;
            margin-top: 5px;
            font-size: 11px;
            font-weight: 600;
            min-height: 14px;
        }

        .field-msg.error {
            color: #e63946;
        }

        .field-msg.success {
            color: #2a9d8f;
        }

        .field-msg.hint {
            color: #999;
            font-weight: 400;
        }

        .submit-btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #c4905c 0%, #2f1200 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 1px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            margin-top: 8px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(47, 18, 0, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .philcon-confirm-group{
      background:#fff8f0;
      border:1px solid #f0d9bf;
      border-radius:8px;
      padding:12px 14px;
    }

    .philcon-checkbox-label{
      display:flex;
      align-items:flex-start;
      gap:10px;
      cursor:pointer;
      font-size:12px;
      color:#5a4633;
      line-height:1.6;
    }

    .philcon-checkbox-label input[type="checkbox"]{
      width:18px;
      height:18px;
      flex-shrink:0;
      margin-top:1px;
      accent-color:#2f1200;
      cursor:pointer;
    }

    .philcon-checkbox-label strong{
      color:#c4905c;
    }

    /* Warning Modal */
    .warning-modal-overlay{
      display:none;
      position:fixed;
      inset:0;
      background:rgba(47,18,0,0.5);
      backdrop-filter:blur(4px);
      z-index:99999;
      align-items:center;
      justify-content:center;
      padding:20px;
    }

    .warning-modal-overlay.show{
      display:flex;
    }

    .warning-modal{
      background:#fff;
      border-radius:16px;
      max-width:380px;
      width:100%;
      padding:28px 24px;
      text-align:center;
      box-shadow:0 20px 60px rgba(0,0,0,0.3);
      animation:warningPop 0.3s ease;
    }

    @keyframes warningPop{
      from{ opacity:0; transform:scale(0.9); }
      to{ opacity:1; transform:scale(1); }
    }

    .warning-modal .warning-icon{
      width:60px;
      height:60px;
      margin:0 auto 14px;
      border-radius:50%;
      background:#fff3e0;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:30px;
      color:#e76f51;
    }

    .warning-modal h3{
      font-size:17px;
      font-weight:800;
      color:#2f1200;
      margin:0 0 10px;
    }

    .warning-modal p{
      font-size:13px;
      color:#666;
      line-height:1.7;
      margin:0 0 20px;
    }

    .warning-modal button{
      background:linear-gradient(135deg, #c4905c 0%, #2f1200 100%);
      color:#fff;
      border:none;
      border-radius:8px;
      padding:11px 28px;
      font-family:'Montserrat', sans-serif;
      font-weight:700;
      font-size:13px;
      letter-spacing:1px;
      cursor:pointer;
      transition:transform 0.2s;
    }

    .warning-modal button:hover{
      transform:translateY(-2px);
    }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }

        .alert-error {
            background: #fdecea;
            color: #b3261e;
            border: 1px solid #f5c2bf;
        }

        /* Success State */
        .success-state {
            text-align: center;
        }

        .success-state .check-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #e8f5e9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #2e7d32;
        }

        .success-state h2 {
            font-size: 20px;
            font-weight: 800;
            color: #2f1200;
            margin: 0 0 10px;
        }

        .success-state p {
            font-size: 13px;
            color: #666;
            line-height: 1.8;
            margin: 0 0 8px;
        }

        .success-state .highlight {
            color: #c4905c;
            font-weight: 700;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #2f1200;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            border-bottom: 2px solid #c4905c;
            padding-bottom: 2px;
        }

        .back-link-top {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 18px;
            color: #2f1200;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            transition: color 0.2s, gap 0.2s;
        }

        .back-link-top:hover {
            color: #c4905c;
            gap: 10px;
        }

        @media (max-width:480px) {
            .register-card {
                padding: 16px 14px;
            }
            .register-header h1 {
                font-size: 18px;
                letter-spacing: 0.5px;
            }
            .register-header p {
                font-size: 12px;
            }
            .register-header .wheel-icon {
                width: 48px;
                height: 48px;
            }
        }

        @media (max-width:360px) {
            .register-card {
                padding: 14px 10px;
            }
            .register-header h1 {
                font-size: 16px;
            }
            .form-group input {
                font-size: 13px;
                padding: 10px 12px;
            }
            .submit-btn {
                font-size: 13px;
                letter-spacing: 0.5px;
            }
            .back-link-top {
                font-size: 11px;
            }
        }

        @media (max-width:480px) {
            body {
                padding: 12px;
                align-items: flex-start;
                padding-top: 20px;
            }
        }
    </style>
</head>

<body>

    <div class="register-card">

        <?php if ($form_success): ?>
    <!-- This will never show now since we redirect immediately above -->

    

        <?php elseif ($otp_step): ?>
            <!-- OTP VERIFICATION SCREEN -->
            <div class="register-header">
                <div style="font-size:52px;margin-bottom:8px;">📧</div>
                <h1>CHECK YOUR GMAIL</h1>
                <p>We sent a <strong>6-digit OTP</strong> to <strong style="color:#c4905c;"><?= htmlspecialchars($_SESSION['sw_otp_email'] ?? '') ?></strong>.<br>Enter it below to complete your registration.</p>
            </div>

            <?php if (!empty($otp_error)): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($otp_error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="otpForm">
                <div class="form-group" style="text-align:center;">
                    <label><i class="fa-solid fa-key"></i> ENTER OTP CODE</label>
                    <input type="text" name="otp_code" id="otp_code"
                        placeholder="_ _ _ _ _ _"
                        maxlength="6"
                        style="text-align:center; font-size:28px; font-weight:800; letter-spacing:12px; padding:14px;"
                        autocomplete="one-time-code">
                    <span class="field-msg hint" id="otp_timer_msg">Code expires in <span id="otpCountdown">5:00</span></span>
                </div>
                <input type="hidden" name="spinwheel_otp_submit" value="1">
                <button type="submit" class="submit-btn" id="otpSubmitBtn">
                    <i class="fa-solid fa-check-circle"></i> VERIFY & REGISTER
                </button>
            </form>

            <p style="text-align:center; margin-top:16px; font-size:12px; color:#aaa;">
                Didn't receive it? Check your spam folder or
                <a href="<?= BASE_URL ?>spinwheel" style="color:#c4905c; font-weight:700;">go back and try again</a>.
            </p>

            <script>
            // OTP countdown timer
            (function(){
                const expires = <?= isset($_SESSION['sw_otp_expires']) ? $_SESSION['sw_otp_expires'] : (time()+300) ?>;
                const el = document.getElementById('otpCountdown');
                const msgEl = document.getElementById('otp_timer_msg');
                function tick() {
                    const left = expires - Math.floor(Date.now()/1000);
                    if (left <= 0) {
                        el.textContent = '0:00';
                        msgEl.style.color = '#e63946';
                        msgEl.innerHTML = 'OTP has expired. <a href="<?= BASE_URL ?>spinwheel" style="color:#e63946;font-weight:700;">Go back to register again.</a>';
                        document.getElementById('otpSubmitBtn').disabled = true;
                        return;
                    }
                    const m = Math.floor(left/60);
                    const s = left % 60;
                    el.textContent = m + ':' + String(s).padStart(2,'0');
                    setTimeout(tick, 1000);
                }
                tick();

                // Only allow numbers
                document.getElementById('otp_code').addEventListener('input', function(){
                    this.value = this.value.replace(/[^0-9]/g,'').slice(0,6);
                });
            })();
            </script>

        <?php else: ?>
            <!-- RETURNING USER LOOKUP -->

            <div style="margin-bottom:24px; padding:16px; background:#f8f4f0; border-radius:10px; border:1px solid #e8d8c8;">
                <p style="margin:0 0 12px; font-size:13px; font-weight:700; color:#2f1200; text-align:center;">
                    <i class="fa-solid fa-rotate-right" style="color:#c4905c;"></i> Already registered? Spin here!
                </p>
                <?php if (!empty($lookup_error)): ?>
                    <div class="alert alert-error" style="margin-bottom:12px;">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($lookup_error) ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input type="email" name="lookup_email" placeholder="Enter your registered Gmail"
                        style="flex:1; min-width:0; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:13px; font-family:'Montserrat',sans-serif; box-sizing:border-box;"
                        value="<?= isset($_POST['lookup_email']) ? htmlspecialchars($_POST['lookup_email']) : '' ?>">
                    <button type="submit" name="spinwheel_lookup_submit" value="1"
                        style="padding:10px 16px; background:linear-gradient(135deg,#c4905c,#2f1200); color:#fff; border:none; border-radius:8px; font-family:'Montserrat',sans-serif; font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; flex-shrink:0;">
                        SPIN NOW
                    </button>
                </form>
            </div>
            <hr style="border:none; border-top:1px solid #eee; margin:0 0 20px;">

            <!-- FORM STATE -->
            <div class="register-header">
                <svg class="wheel-icon" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="48" fill="#fff" stroke="#2f1200" stroke-width="3" />
                    <path d="M50 50 L50 2 A48 48 0 0 1 84 16 Z" fill="#e63946" />
                    <path d="M50 50 L84 16 A48 48 0 0 1 98 50 Z" fill="#f4a261" />
                    <path d="M50 50 L98 50 A48 48 0 0 1 84 84 Z" fill="#2a9d8f" />
                    <path d="M50 50 L84 84 A48 48 0 0 1 50 98 Z" fill="#e9c46a" />
                    <path d="M50 50 L50 98 A48 48 0 0 1 16 84 Z" fill="#264653" />
                    <path d="M50 50 L16 84 A48 48 0 0 1 2 50 Z" fill="#f4a261" />
                    <path d="M50 50 L2 50 A48 48 0 0 1 16 16 Z" fill="#e76f51" />
                    <path d="M50 50 L16 16 A48 48 0 0 1 50 2 Z" fill="#2a9d8f" />
                    <circle cx="50" cy="50" r="6" fill="#2f1200" />
                </svg>
                <h1>SPIN TO WIN REGISTRATION</h1>
        <p>Exclusive for <strong>Philcon Event</strong> attendees. Fill out the form below to join our promo and try the physical spin wheel at our booth.</p>
            </div>

            <a href="<?= BASE_URL ?>" class="back-link-top">
                <i class="fa-solid fa-arrow-left"></i> BACK TO HOME
            </a>

            <?php if (!empty($form_error)): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($form_error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="spinwheelForm" novalidate>

                <!-- Name -->
                <div class="form-group">
                    <label><i class="fa-solid fa-user"></i>NAME</label>
                    <input type="text" name="full_name" id="full_name" placeholder="Enter your full name"
                        value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    <span class="field-msg" id="full_name_msg"></span>
                </div>

                <!-- Phone -->
                <div class="form-group">
                    <label><i class="fa-solid fa-phone"></i>PHONE NUMBER</label>
                    <input type="tel" name="phone" id="phone" placeholder="09123456789" maxlength="11"
                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    <span class="field-msg hint" id="phone_msg">Format: 09XXXXXXXXX (11 digits)</span>
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i>EMAIL ADDRESS</label>
                    <input type="email" name="email" id="email" placeholder="yourname@gmail.com"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    <span class="field-msg hint" id="email_msg">Only @gmail.com addresses are accepted</span>
                </div>

                <!-- Company Name -->
                <div class="form-group">
                    <label><i class="fa-solid fa-building"></i>COMPANY NAME</label>
                    <input type="text" name="company_name" id="company_name" placeholder="Enter your company name"
                        value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>">
                    <span class="field-msg" id="company_name_msg"></span>
                </div>

                <!-- Position -->
                <div class="form-group">
                    <label><i class="fa-solid fa-id-badge"></i>POSITION</label>
                    <input type="text" name="position" id="position" placeholder="Enter your position/job title"
                        value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : ''; ?>">
                    <span class="field-msg" id="position_msg"></span>

                    <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; color:#5a4633; cursor:pointer; font-weight:500;">
                        <input type="checkbox" name="is_student" id="is_student" style="width:16px; height:16px; accent-color:#2f1200; cursor:pointer;">
                        I'm a student / not currently employed
                    </label>
                </div>

                <!-- Philcon Confirmation Checkbox -->
        <div class="form-group philcon-confirm-group">
          <label class="philcon-checkbox-label">
            <input type="checkbox" name="philcon_confirm" id="philcon_confirm">
            <span class="checkbox-text">
              I confirm that I am attending the <strong>Philcon Event</strong>. This promo is exclusive to Philcon attendees only.
            </span>
          </label>
        </div>

        <input type="hidden" name="spinwheel_register_submit" value="1">

        <button type="submit" class="submit-btn" id="submitBtn">
          <i class="fa-solid fa-paper-plane"></i> SUBMIT REGISTRATION
        </button>
            </form>

      <!-- Philcon Warning Modal -->
      <div class="warning-modal-overlay" id="philconWarningModal">
        <div class="warning-modal">
          <div class="warning-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>
          <h3>Philcon Attendees Only</h3>
          <p>This Spin to Win promo is exclusively for guests attending the <strong>Philcon Event</strong>. Please confirm the checkbox to proceed with your registration.</p>
          <button type="button" id="philconWarningCloseBtn">GOT IT</button>
        </div>
      </div>

      <script>
                (function () {
                    const form = document.getElementById('spinwheelForm');
                    const submitBtn = document.getElementById('submitBtn');

                    const fields = {
                        full_name: {
                            el: document.getElementById('full_name'),
                            msg: document.getElementById('full_name_msg'),
                            validate: function (val) {
                                if (val.trim() === '') return 'Name is required.';
                                if (val.trim().length < 2) return 'Name is too short.';
                                return '';
                            }
                        },
                        phone: {
                            el: document.getElementById('phone'),
                            msg: document.getElementById('phone_msg'),
                            hint: 'Format: 09XXXXXXXXX (11 digits)',
                            validate: function (val) {
                                if (val.trim() === '') return 'Phone number is required.';
                                if (!/^09[0-9]{9}$/.test(val.trim())) return 'Must be 11 digits starting with 09.';
                                return '';
                            }
                        },
                        email: {
                            el: document.getElementById('email'),
                            msg: document.getElementById('email_msg'),
                            hint: 'Only @gmail.com addresses are accepted',
                            validate: function (val) {
                                if (val.trim() === '') return 'Email is required.';
                                if (!/^[a-zA-Z0-9._%+-]+@gmail\.com$/.test(val.trim())) return 'Only @gmail.com emails are allowed.';
                                return '';
                            }
                        },
                        company_name: {
                            el: document.getElementById('company_name'),
                            msg: document.getElementById('company_name_msg'),
                            validate: function (val) {
                                if (val.trim() === '') return 'Company name is required.';
                                return '';
                            }
                        },
                        position: {
                            el: document.getElementById('position'),
                            msg: document.getElementById('position_msg'),
                            validate: function (val) {
                                if (isStudentCheckbox.checked) return '';
                                if (val.trim() === '') return 'Position is required.';
                                return '';
                            }
                        }
                    };

                    const isStudentCheckbox = document.getElementById('is_student');
                    const positionInput = document.getElementById('position');

                    isStudentCheckbox.addEventListener('change', function () {
                        if (this.checked) {
                            positionInput.value = 'Student';
                            positionInput.disabled = true;
                            positionInput.classList.remove('input-error');
                            positionInput.classList.add('input-success');
                            document.getElementById('position_msg').textContent = '';
                            document.getElementById('position_msg').className = 'field-msg';
                        } else {
                            positionInput.disabled = false;
                            positionInput.value = '';
                            positionInput.classList.remove('input-success');
                        }
                    });

                    function setFieldState(field, errorMsg) {
                        const { el, msg, hint } = field;
                        if (errorMsg) {
                            el.classList.add('input-error');
                            el.classList.remove('input-success');
                            msg.textContent = errorMsg;
                            msg.className = 'field-msg error';
                            return false;
                        } else {
                            el.classList.remove('input-error');
                            el.classList.add('input-success');
                            if (hint) {
                                msg.textContent = hint;
                                msg.className = 'field-msg hint';
                            } else {
                                msg.textContent = '';
                                msg.className = 'field-msg';
                            }
                            return true;
                        }
                    }

                    function validateField(name) {
                        const field = fields[name];
                        const errorMsg = field.validate(field.el.value);
                        return setFieldState(field, errorMsg);
                    }

                    // Real-time validation on input/blur
                    Object.keys(fields).forEach(function (name) {
                        const field = fields[name];
                        field.el.addEventListener('input', function () {
                            validateField(name);
                        });
                        field.el.addEventListener('blur', function () {
                            validateField(name);
                        });
                    });

                    // Philcon checkbox + warning modal
          const philconCheckbox = document.getElementById('philcon_confirm');
          const philconModal = document.getElementById('philconWarningModal');
          const philconModalCloseBtn = document.getElementById('philconWarningCloseBtn');

          philconModalCloseBtn.addEventListener('click', function(){
            philconModal.classList.remove('show');
          });

          philconModal.addEventListener('click', function(e){
            if(e.target === philconModal) philconModal.classList.remove('show');
          });

          // Validate all on submit
          form.addEventListener('submit', function(e){
            let allValid = true;
            Object.keys(fields).forEach(function(name){
              if(!validateField(name)) allValid = false;
            });

            // Check Philcon confirmation
            if(!philconCheckbox.checked){
              e.preventDefault();
              philconModal.classList.add('show');
              return;
            }

            if(!allValid){
              e.preventDefault();
              // Focus first invalid field
              for(const name in fields){
                if(fields[name].el.classList.contains('input-error')){
                  fields[name].el.focus();
                  break;
                }
              }
            } else {
              submitBtn.disabled = true;
              submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SUBMITTING...';
            }
          });
                })();
            </script>

        <?php endif; ?>

    </div>

</body>

</html>