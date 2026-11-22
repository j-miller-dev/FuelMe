<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Hevy\HevySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HevySyncCommand extends Command
{
    protected $signature = 'hevy:sync';

    protected $description = 'Pull new and changed Hevy workouts for every connected user';

    public function handle(HevySync $sync): int
    {
        foreach (DB::table('hevy_connections')->pluck('user_id') as $id) {
            $user = User::with('profile')->find($id);
            if (! $user || ! $user->profile) {
                continue;
            }
            $r = $sync->sync($user);
            $this->line(sprintf('user %d: %s, %d workouts, %d deleted%s', $id, $r['ok'] ? 'ok' : 'failed', $r['workouts'], $r['deleted'], $r['error'] ? ' (' . $r['error'] . ')' : ''));
        }

        return self::SUCCESS;
    }
}
