<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Hevy\HevySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncHevy implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public int $userId) {}

    public function handle(HevySync $sync): void
    {
        $user = User::with('profile')->find($this->userId);
        if ($user && $user->profile) {
            $sync->sync($user);
        }
    }
}
