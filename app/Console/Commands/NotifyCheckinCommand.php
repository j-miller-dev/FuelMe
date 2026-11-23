<?php

namespace App\Console\Commands;

use App\Domain\Training\Deload;
use App\Models\User;
use App\Services\ProgressService;
use App\Services\Push\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyCheckinCommand extends Command
{
    protected $signature = 'notify:checkin';

    protected $description = 'On Sunday evening, tell people who turned it on that their weekly check-in has a proposal ready (once per week)';

    public function handle(PushService $push, ProgressService $progress): int
    {
        if (! PushService::configured()) {
            return self::SUCCESS;
        }
        foreach (DB::table('push_subscriptions')->distinct()->pluck('user_id') as $id) {
            $user = User::with('profile')->find($id);
            if (! $user || ! $user->profile || ! $push->prefs($user)['checkin']) {
                continue;
            }
            $local = now($user->profile->tz);
            if ($local->dayOfWeek !== 0 || $local->hour < 18 || $local->hour > 21) {
                continue;
            }
            $status = $progress->overview($user)['checkin']['status'] ?? null;
            if ($status !== 'ready') {
                continue;   // nothing to propose (needs more data, or it is too soon): do not send a notification just to say so
            }
            $n = $push->send($user, 'checkin:' . Deload::mondayOf($local->toDateString()), 'Weekly check-in ready', 'Your check-in has a proposed target. Tap to look, nothing changes until you apply it.', '/progress', 'checkin');
            $this->line("user {$id}: check-in notification sent to {$n} device(s)");
        }

        return self::SUCCESS;
    }
}
