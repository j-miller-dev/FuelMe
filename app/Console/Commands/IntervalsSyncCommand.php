<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Intervals\IntervalsSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IntervalsSyncCommand extends Command
{
    protected $signature = 'intervals:sync';

    protected $description = 'Pull watch wellness and activities from Intervals.icu for every connected user';

    public function handle(IntervalsSync $sync): int
    {
        foreach (DB::table('intervals_connections')->pluck('user_id') as $id) {
            $user = User::with('profile')->find($id);
            if (! $user || ! $user->profile) {
                continue;
            }
            $r = $sync->sync($user);
            $this->line(sprintf('user %d: %s, %d days, %d activities%s', $id, $r['ok'] ? 'ok' : 'failed', $r['days'], $r['activities'], $r['error'] ? ' (' . $r['error'] . ')' : ''));
        }

        return self::SUCCESS;
    }
}
