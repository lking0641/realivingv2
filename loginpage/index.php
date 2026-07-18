<?php
//index.php
session_start();
require_once __DIR__ . '/../config/app_config.php';
include $includes['connection'];

// Check for timeout message
$timeout_message = "";
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $timeout_message = "Your session has expired due to inactivity. Please login again.";
}

// Prevent page caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$error_message = $timeout_message; // Use timeout message if available

// Function to get redirect URL based on role
function getRedirectUrl($role, $conn = null, $user_id = null) {
    // Check is_head for designer and technical_designer
    $is_head = false;
    if ($conn && $user_id && in_array($role, ['designer', 'technical_designer'])) {
        $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
        $headCheck->bind_param("i", $user_id);
        $headCheck->execute();
        $headRow = $headCheck->get_result()->fetch_assoc();
        $is_head = !empty($headRow['is_head']);
    }

    if ($role === 'designer') {
        return $is_head
            ? BASE_URL . 'realiving_admin/tracker_management/all_clients_tracker_list.php'
            : BASE_URL . 'realiving_admin/tracker_site_visit/designer_clients_list.php';
    }

    if ($role === 'technical_designer') {
        return $is_head
            ? BASE_URL . 'realiving_admin/tracker_management/all_clients_tracker_list.php'
            : BASE_URL . 'realiving_admin/tracker_technical/td_layout_list.php';
    }

    $redirects = [
        'general_manager' => BASE_URL . 'realiving_admin/manager_tracker/manager_status_tracker.php',
        'operational_manager' => BASE_URL . 'realiving_admin/manager_tracker/manager_status_tracker.php',
        'sales' => BASE_URL . 'sales-dashboard',
        'accounting' => BASE_URL . 'realiving_admin/tracker_management/all_clients_tracker_list.php',
        'project_coordinator' => BASE_URL . 'realiving_admin/tracker_management/all_clients_tracker_list.php',
        'admin1' => BASE_URL . 'admin_mainpage/mainpage.php'
    ];

    return isset($redirects[$role]) ? $redirects[$role] : BASE_URL . 'admin/admin_mainpage/mainpage';
}

// Redirect to appropriate page if already logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_role'])) {
    header("Location: " . getRedirectUrl($_SESSION['admin_role'], $conn, $_SESSION['admin_id']));
    exit();
}

// Auto-login with remember_token from cookie
if (!isset($_SESSION['admin_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, email, role, remember_token FROM account WHERE remember_token IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (password_verify($token, $row['remember_token'])) {
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_email'] = $row['email'];
                $_SESSION['admin_role'] = $row['role'];

                header("Location: " . getRedirectUrl($row['role'], $conn, $row['id']));
exit();
            }
        }
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
    $password_input = isset($_POST['password']) ? $_POST['password'] : '';
    $remember_me = isset($_POST['remember']);

    if (!empty($email) && !empty($password_input)) {
        $stmt = $conn->prepare("SELECT id, full_name, email, password, role FROM account WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();

            if (password_verify($password_input, $row['password'])) {
                // Set session variables
$_SESSION['admin_id'] = $row['id'];
$_SESSION['admin_name'] = $row['full_name'];
$_SESSION['admin_email'] = $row['email'];
$_SESSION['admin_role'] = $row['role'];
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time(); // Initialize last activity

// Set user as online
$update_online = $conn->prepare("UPDATE account SET is_online = 1, last_activity = NOW() WHERE id = ?");
$update_online->bind_param("i", $row['id']);
$update_online->execute();
$update_online->close();
$_SESSION['admin_name'] = $row['full_name'];
$_SESSION['admin_email'] = $row['email'];
$_SESSION['admin_role'] = $row['role'];
$_SESSION['login_time'] = time();

// Set user as online
$update_online = $conn->prepare("UPDATE account SET is_online = 1, last_activity = NOW() WHERE id = ?");
$update_online->bind_param("i", $row['id']);
$update_online->execute();
$update_online->close();

                // Handle remember me functionality
                if ($remember_me) {
                    $token = bin2hex(random_bytes(32));
                    $hashed_token = password_hash($token, PASSWORD_DEFAULT);

                    $update_stmt = $conn->prepare("UPDATE account SET remember_token = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_token, $row['id']);
                    $update_stmt->execute();
                    $update_stmt->close();

                    setcookie("remember_token", $token, time() + (30 * 24 * 60 * 60), "/", "", false, true);
                }

                // Redirect based on role
                header("Location: " . getRedirectUrl($row['role'], $conn, $row['id']));
exit();
            } else {
                $error_message = "Incorrect password. Please try again.";
            }
        } else {
            $error_message = "No account found with that email.";
        }

        $stmt->close();
    } else {
        $error_message = "Please fill in all fields.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Login - Realiving</title>

    <!-- Compiled Tailwind build (npm run dev). Requires "./loginpage/**/*.php"
         to be present in tailwind.config.js's content array, and npm run dev
         restarted after that change, or the classes below won't compile. -->
    <link rel="stylesheet" href="<?php echo BASE_ASSET; ?>assets/css/output.css">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

    <style>
        :root{
            --rl-ink:#1a1a1a;
            --rl-ink-soft:#6b6b6b;
            --rl-line:rgba(255,255,255,0.4);
        }
        body{ font-family:'Inter',sans-serif; }

        @keyframes rl-rise{
            from{ opacity:0; transform:translateY(14px); }
            to{ opacity:1; transform:translateY(0); }
        }
        .rl-rise{ animation: rl-rise 0.7s cubic-bezier(0.16,1,0.3,1) both; }
        .rl-rise-1{ animation-delay: 0.05s; }
        .rl-rise-2{ animation-delay: 0.15s; }
        .rl-rise-3{ animation-delay: 0.25s; }

        @media (prefers-reduced-motion: reduce){
            .rl-rise{ animation:none; }
        }

        input:-webkit-autofill{
            -webkit-box-shadow: 0 0 0 1000px rgba(255,255,255,0.5) inset;
            -webkit-text-fill-color: var(--rl-ink);
        }
    </style>

    <script>
        // Simple loading function
        function showLoading(event) {
            event.preventDefault();
            const loadingBtn = document.getElementById("loading-button");
            const loginForm = document.getElementById("login-form");

            loadingBtn.classList.remove("hidden");

            setTimeout(function() {
                loginForm.submit();
            }, 1000);
        }

        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const passwordIcon = document.getElementById('password-icon');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                passwordIcon.classList.remove('ri-eye-line');
                passwordIcon.classList.add('ri-eye-off-line');
            } else {
                passwordField.type = 'password';
                passwordIcon.classList.remove('ri-eye-off-line');
                passwordIcon.classList.add('ri-eye-line');
            }
        }
    </script>
</head>

<body class="relative min-h-screen flex items-center justify-center p-4 sm:p-6 overflow-hidden" style="background:#0d1114;">

    <!-- ═══════════════════════════════
         FULL-BLEED BACKGROUND
    ═══════════════════════════════ -->
    <div class="absolute inset-0 bg-cover bg-center"
         style="background-image:url('<?php echo BASE_URL; ?>loginpage/images/realiving_bg.png'); filter:contrast(1.05) saturate(1.02);"></div>
    <div class="absolute inset-0" style="background:linear-gradient(180deg, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.3) 40%, rgba(0,0,0,0.55) 100%);"></div>
    <div class="absolute inset-0" style="background:radial-gradient(ellipse 620px 620px at 50% 48%, rgba(0,0,0,0.35) 0%, transparent 70%);"></div>

    <!-- ═══════════════════════════════
         LOGIN CARD — glass panel, lets the photo carry the color
    ═══════════════════════════════ -->
    <div class="relative z-10 w-full max-w-md">

        <div class="flex justify-center mb-9 rl-rise rl-rise-1">
            <img src="<?php echo CLIENT_ASSET; ?>/images/logo/realiving_logo_hd.png" alt="Realiving Logo" class="h-12 object-contain drop-shadow-[0_6px_16px_rgba(0,0,0,0.45)]" />
        </div>

        <div class="relative rounded-2xl p-8 sm:p-12 rl-rise rl-rise-2"
             style="background:transparent;
                    box-shadow: 0 40px 80px -20px rgba(0,0,0,0.55);">

            <div class="mb-9 text-center">
                <div class="flex items-center justify-center gap-2.5 text-[10px] font-semibold tracking-[3px] uppercase mb-3.5" style="color:rgba(255,255,255,0.85); text-shadow:0 1px 3px rgba(0,0,0,0.3);">
                    <span class="w-5 h-px" style="background:rgba(255,255,255,0.5);"></span> Employee Access <span class="w-5 h-px" style="background:rgba(255,255,255,0.5);"></span>
                </div>
                <h1 class="text-[28px] font-semibold leading-tight" style="color:#ffffff; letter-spacing:-0.02em; text-shadow:0 2px 8px rgba(0,0,0,0.35);">
                    Welcome back
                </h1>
                <p class="text-[13px] mt-2" style="color:rgba(255,255,255,0.8); text-shadow:0 1px 3px rgba(0,0,0,0.3);">
                    Sign in to access your dashboard
                </p>
            </div>

            <!-- Error/Timeout Message -->
<?php if (!empty($error_message)): ?>
    <?php
    $is_timeout = (isset($_GET['timeout']) && $_GET['timeout'] == 1);
    $bg_color = $is_timeout ? 'bg-amber-50/90' : 'bg-red-50/90';
    $border_color = $is_timeout ? 'border-amber-500' : 'border-red-500';
    $text_color = $is_timeout ? 'text-amber-700' : 'text-red-700';
    $icon = $is_timeout ? 'ri-time-line' : 'ri-error-warning-line';
    $icon_color = $is_timeout ? 'text-amber-500' : 'text-red-500';
    ?>
    <div class="mb-6 p-4 <?php echo $bg_color; ?> border-l-4 <?php echo $border_color; ?> rounded-lg">
        <div class="flex gap-3">
            <i class="<?php echo $icon; ?> <?php echo $icon_color; ?> mt-0.5"></i>
            <p class="text-[13px] <?php echo $text_color; ?> font-medium">
                <?php echo htmlspecialchars($error_message); ?>
            </p>
        </div>
    </div>
<?php endif; ?>

            <!-- Loading Button -->
            <div id="loading-button" class="hidden mb-6">
                <div class="w-full py-4 text-white rounded-xl flex items-center justify-center text-[11px] font-bold tracking-[2px] uppercase" style="background:rgba(20,20,20,0.75); border:1px solid rgba(255,255,255,0.2);">
                    <svg class="animate-spin h-4 w-4 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Authenticating…</span>
                </div>
            </div>

            <!-- Login Form -->
            <form id="login-form" method="POST" action="" onsubmit="showLoading(event)" class="space-y-5 rl-rise rl-rise-3">

                <div>
                    <label for="email" class="block text-[10px] font-semibold tracking-[1.5px] uppercase mb-2" style="color:rgba(255,255,255,0.9); text-shadow:0 1px 2px rgba(0,0,0,0.3);">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none" style="color:rgba(255,255,255,0.65);">
                            <i class="ri-mail-line"></i>
                        </div>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            required
                            autocomplete="email"
                            placeholder="admin@example.com"
                            class="w-full pl-12 pr-4 py-3.5 text-[14px] rounded-xl placeholder:text-white/50 focus:outline-none transition-all duration-200"
                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.4); color:#ffffff;"
                            onfocus="this.style.borderColor='rgba(255,255,255,0.85)'; this.style.background='rgba(255,255,255,0.12)'; this.style.boxShadow='0 0 0 4px rgba(255,255,255,0.1)';"
                            onblur="this.style.borderColor='rgba(255,255,255,0.4)'; this.style.background='rgba(255,255,255,0.06)'; this.style.boxShadow='none';" />
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-[10px] font-semibold tracking-[1.5px] uppercase mb-2" style="color:rgba(255,255,255,0.9); text-shadow:0 1px 2px rgba(0,0,0,0.3);">
                        Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none" style="color:rgba(255,255,255,0.65);">
                            <i class="ri-lock-line"></i>
                        </div>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="w-full pl-12 pr-12 py-3.5 text-[14px] rounded-xl placeholder:text-white/50 focus:outline-none transition-all duration-200"
                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.4); color:#ffffff;"
                            onfocus="this.style.borderColor='rgba(255,255,255,0.85)'; this.style.background='rgba(255,255,255,0.12)'; this.style.boxShadow='0 0 0 4px rgba(255,255,255,0.1)';"
                            onblur="this.style.borderColor='rgba(255,255,255,0.4)'; this.style.background='rgba(255,255,255,0.06)'; this.style.boxShadow='none';" />
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center">
                            <button type="button" onclick="togglePassword()" class="transition-colors focus:outline-none" style="color:rgba(255,255,255,0.65);" onmouseover="this.style.color='#ffffff';" onmouseout="this.style.color='rgba(255,255,255,0.65)';">
                                <i id="password-icon" class="ri-eye-line"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" id="remember" name="remember" class="h-4 w-4 rounded" style="accent-color:#ffffff; border:1px solid rgba(255,255,255,0.5);">
                        <span class="text-[13px]" style="color:rgba(255,255,255,0.85); text-shadow:0 1px 2px rgba(0,0,0,0.25);">Remember me</span>
                    </label>
                </div>

                <button
                    type="submit"
                    class="w-full py-4 rounded-xl font-bold text-[11px] tracking-[2px] uppercase transition-all duration-300 hover:-translate-y-0.5 flex items-center justify-center gap-2"
                    style="background:rgba(255,255,255,0.95); color:#141414;
                           box-shadow:0 16px 32px -12px rgba(0,0,0,0.4);"
                    onmouseover="this.style.background='#ffffff'; this.style.boxShadow='0 20px 38px -10px rgba(0,0,0,0.45)';"
                    onmouseout="this.style.background='rgba(255,255,255,0.95)'; this.style.boxShadow='0 16px 32px -12px rgba(0,0,0,0.4)';">
                    <i class="ri-login-box-line text-[13px]"></i>
                    Sign in to Dashboard
                </button>
            </form>

            <div class="text-center mt-8 pt-6" style="border-top:1px solid rgba(255,255,255,0.25);">
                <p class="text-[11px] flex items-center justify-center gap-1.5" style="color:rgba(255,255,255,0.75); text-shadow:0 1px 2px rgba(0,0,0,0.3);">
                    <i class="ri-shield-check-line"></i> Protected Admin Area
                </p>
            </div>
        </div>

        <p class="text-center text-[11px] mt-7 tracking-wide" style="color:rgba(255,255,255,0.6);">
            &copy; 2026 Realiving Design Center Corporation
        </p>
    </div>

</body>
</html>