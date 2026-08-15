<?php
// loginpage/google_callback.php
// Shared callback for BOTH "Sign in with Google" and "Link Google Account".
// The $_SESSION['google_oauth_action'] value set by google_login.php or
// google_link.php tells us which flow we're finishing.
session_start();
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/google_oauth_config.php';
require_once __DIR__ . '/redirect_helper.php';
include $includes['connection'];

// ── 1. Validate state (CSRF check) ─────────────────────
if (
    !isset($_GET['state'], $_SESSION['google_oauth_state']) ||
    !hash_equals($_SESSION['google_oauth_state'], $_GET['state'])
) {
    unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_action'], $_SESSION['google_link_admin_id']);
    header('Location: ' . BASE_URL . 'login?error=invalid_state');
    exit();
}

$action        = $_SESSION['google_oauth_action'] ?? 'login';
$link_admin_id = $_SESSION['google_link_admin_id'] ?? null;

unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_action'], $_SESSION['google_link_admin_id']);

// ── 2. Handle user cancelling / Google error ───────────
if (!isset($_GET['code'])) {
    if ($action === 'link' && $link_admin_id) {
        header('Location: ' . BASE_URL . 'login?error=google_denied'); // safe fallback if session got weird
    } else {
        header('Location: ' . BASE_URL . 'login?error=google_denied');
    }
    exit();
}

// ── 3. Exchange authorization code for tokens ──────────
$token_response = google_curl_post('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (!isset($token_response['id_token'])) {
    header('Location: ' . BASE_URL . 'login?error=token_exchange_failed');
    exit();
}

// ── 4. Verify the ID token with Google's tokeninfo endpoint ──
$verify_ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token_response['id_token']));
curl_setopt($verify_ch, CURLOPT_RETURNTRANSFER, true);
$verify_raw = curl_exec($verify_ch);
curl_close($verify_ch);
$claims = json_decode($verify_raw, true);

if (!$claims || !isset($claims['sub'], $claims['email'])) {
    header('Location: ' . BASE_URL . 'login?error=token_verify_failed');
    exit();
}

// ── 5. Check audience matches OUR client ID ─────────────
if ($claims['aud'] !== GOOGLE_CLIENT_ID) {
    header('Location: ' . BASE_URL . 'login?error=audience_mismatch');
    exit();
}

// ── 6. Require verified email ──────────────────────────
if (empty($claims['email_verified']) || $claims['email_verified'] !== 'true') {
    header('Location: ' . BASE_URL . 'login?error=email_not_verified');
    exit();
}

$google_sub   = $claims['sub'];
$google_email = strtolower(trim($claims['email']));

// ════════════════════════════════════════════════════════
//  BRANCH: LINKING an already-logged-in account
// ════════════════════════════════════════════════════════
if ($action === 'link') {

    // Must still be logged in, and must be the SAME account that started this
    if (!isset($_SESSION['admin_id']) || (string)$_SESSION['admin_id'] !== (string)$link_admin_id) {
        header('Location: ' . BASE_URL . 'login?error=session_expired');
        exit();
    }

    // Reject if this Google account is already linked to a DIFFERENT staff account
    $check = $conn->prepare("SELECT id FROM account WHERE google_sub = ? AND id != ?");
    $check->bind_param('si', $google_sub, $link_admin_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        header('Location: ' . BASE_URL . 'sales-dashboard?google_error=already_linked');
        exit();
    }
    $check->close();

    // Attach this Google identity to the current account
    $link_stmt = $conn->prepare("UPDATE account SET google_sub = ?, google_email = ? WHERE id = ?");
    $link_stmt->bind_param('ssi', $google_sub, $google_email, $link_admin_id);
    $link_stmt->execute();
    $link_stmt->close();

    // Redirect back to their dashboard with a success flag
    $roleStmt = $conn->prepare("SELECT role FROM account WHERE id = ?");
    $roleStmt->bind_param('i', $link_admin_id);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();

    header('Location: ' . getRedirectUrl($roleRow['role'], $conn, $link_admin_id) . '?google_linked=1');
    exit();
}

// ════════════════════════════════════════════════════════
//  BRANCH: LOGGING IN via Google
//  Only matches by google_sub — NO email fallback.
//  A Google account must already be explicitly linked.
// ════════════════════════════════════════════════════════
$stmt = $conn->prepare("SELECT id, full_name, email, role, account_status FROM account WHERE google_sub = ? LIMIT 1");
$stmt->bind_param('s', $google_sub);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // Not linked to any account — do NOT log in, do NOT create one.
    header('Location: ' . BASE_URL . 'login?error=google_not_linked');
    exit();
}

$row = $result->fetch_assoc();

if ($row['account_status'] === 'suspended') {
    // Suspended accounts can't log in via Google either
    header('Location: ' . BASE_URL . 'login?error=account_suspended');
    exit();
}

// Regenerate session ID to prevent session fixation
session_regenerate_id(true);

$_SESSION['admin_id']      = $row['id'];
$_SESSION['admin_name']    = $row['full_name'];
$_SESSION['admin_email']   = $row['email'];
$_SESSION['admin_role']    = $row['role'];
$_SESSION['login_time']    = time();
$_SESSION['last_activity'] = time();

// Generate a fresh session token — this invalidates any OTHER
// device/browser currently logged into this same account
$session_token = bin2hex(random_bytes(32));
$_SESSION['session_token'] = $session_token;

$update_online = $conn->prepare("UPDATE account SET is_online = 1, last_activity = NOW(), active_session_token = ? WHERE id = ?");
$update_online->bind_param("si", $session_token, $row['id']);
$update_online->execute();
$update_online->close();

header("Location: " . getRedirectUrl($row['role'], $conn, $row['id']));
exit();

// ── Helper: POST request to Google's token endpoint ────
function google_curl_post($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}