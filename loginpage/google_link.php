<?php
// loginpage/google_link.php
// Starts the "link my Google account" flow. Requires the user to already
// be logged in via password — Google login is never used to create or
// discover accounts, only to attach itself to one you're already inside.
session_start();
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/google_oauth_config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . 'login');
    exit();
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state']   = $state;
$_SESSION['google_oauth_action']  = 'link';               // tells the callback this is a LINK action
$_SESSION['google_link_admin_id'] = $_SESSION['admin_id']; // lock which account we're linking to

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit();