<?php

namespace App\Services\Push;

interface PushTransport
{
    /**
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @return array{ok:bool,gone:bool} gone = the subscription no longer exists (404/410) and should be removed
     */
    public function send(array $sub, string $payload): array;
}
