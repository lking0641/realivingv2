<?php
require_once ROOT_PATH . 'vendor/autoload.php';

use GeoIp2\Database\Reader;

function realiving_check_country()
{
    global $is_local;

    // Skip the check entirely when developing locally
    if (!empty($is_local)) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $reader = new Reader(ROOT_PATH . 'geoip/GeoLite2-Country.mmdb');
        $record = $reader->country($ip);
        $country_code = $record->country->isoCode;

        if ($country_code !== 'PH') {
            http_response_code(403);
            require ROOT_PATH . 'connection/blocked.php';
            exit();
        }
    } catch (\Exception $e) {
        // If the IP can't be looked up (e.g. private/reserved IP), 
        // we allow access rather than accidentally locking everyone out.
        // You can change this later once you confirm it's working.
    }
}

realiving_check_country();