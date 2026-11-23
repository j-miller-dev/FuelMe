<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushKeysCommand extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Print a new VAPID key pair for push notifications (set them as VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY, then keep them)';

    public function handle(): int
    {
        $k = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY=' . $k['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $k['privateKey']);
        $this->newLine();
        $this->comment('Put both in the server environment and redeploy. The private key is a secret. If you change the pair later, everyone has to turn notifications on again.');

        return self::SUCCESS;
    }
}
