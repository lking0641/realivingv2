<?php
// loginpage/google_login.php
session_start();
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/google_oauth_config.php';

// Clear any leftover linking state from a previous attempt
unset($_SESSION['google_link_admin_id']);

// CSRF protection: random state, checked on callback
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state']  = $state;
$_SESSION['google_oauth_action'] = 'login'; // tells the callback this is a LOGIN attempt

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account', // always show account chooser
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $auth_url);
exit();