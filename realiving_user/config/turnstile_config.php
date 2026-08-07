<?php
// turnstile_config.php - Cloudflare Turnstile Configuration

if (!defined('TURNSTILE_SITE_KEY')) {
    define('TURNSTILE_SITE_KEY', '0x4AAAAAAEH5MIkDRSAThLti');
    define('TURNSTILE_SECRET_KEY', '0x4AAAAAAEH5MHd9_lqt90aRyS1yeut3c0Q');

    define('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    define('TURNSTILE_SCRIPT_URL', 'https://challenges.cloudflare.com/turnstile/v0/api.js');
}
?>