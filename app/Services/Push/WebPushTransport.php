<?php

namespace App\Services\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Sends one encrypted Web Push message (VAPID-signed). The payload is encrypted for the device; the push service only sees that a message exists. */
class WebPushTransport implements PushTransport
{
    public function send(array $sub, string $payload): array
    {
        $cfg = config('fuel.push');
        $web = new WebPush(['VAPID' => ['subject' => $cfg['subject'], 'publicKey' => $cfg['public_key'], 'privateKey' => $cfg['private_key']]], ['TTL' => 3600, 'urgency' => 'normal'], 10);
        $report = $web->sendOneNotification(Subscription::create(['endpoint' => $sub['endpoint'], 'publicKey' => $sub['p256dh'], 'authToken' => $sub['auth'], 'contentEncoding' => 'aes128gcm']), $payload);

        return ['ok' => $report->isSuccess(), 'gone' => $report->isSubscriptionExpired()];
    }
}
