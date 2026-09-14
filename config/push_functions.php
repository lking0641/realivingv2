<?php
// push_functions.php
// Reusable function for sending a web push notification to one admin user.

require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function send_push_notification($conn, $admin_id, $title, $body, $url = null)
{
    $vapidAuth = [
        'VAPID' => [
            'subject' => 'mailto:youremail@example.com',
            'publicKey' => 'BDxGPsBpYqKAxNHPmd04mhAwcgWDCfQuZjaYL5_1z6mG0GxWMA6UJsON5dRLIy9L_45JpCcHdrih2-7TCP_iSq8',
            'privateKey' => 'NRddxwOxi13Lx68UAidmnemj31xNfcWDhwebpFUsns4',
        ],
    ];

    $webPush = new WebPush($vapidAuth);

    // Get ALL subscriptions for this admin (they may have subscribed on multiple devices/browsers)
    $stmt = $conn->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE admin_id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false; // No subscription for this user — nothing to send
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'icon' => CLIENT_ASSET . '/images/logo/favicon.png',
        'url' => $url ?: BASE_URL
    ]);

    while ($row = $result->fetch_assoc()) {
        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    $success = true;
    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess()) {
            $success = false;
        }
    }

    return $success;
}