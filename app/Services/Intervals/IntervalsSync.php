<?php

namespace App\Services\Intervals;

use App\Domain\Watch\WatchMapper;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Pulls daily watch data (sleep, resting HR, HRV, steps) and activities from Intervals.icu. The first run (or a full re-read) reads the last 365 days; later runs
 * re-read the last 14 days, because a night's sleep often lands hours after you wake up. Re-running is safe (rows are matched by date or id).
 * Display and insight data only: nothing here changes a calorie target.
 */
class IntervalsSync
{
    public const FIRST_DAYS = 365;
    public const REFRESH_DAYS = 14;

    public function client(object $conn): IntervalsClient
    {
        return new IntervalsClient(Crypt::decryptString($conn->api_key), (string) $conn->athlete_id);
    }

    /** @return array{ok:bool,days:int,activities:int,error:?string} */
    public function sync(User $user, ?IntervalsClient $client = null): array
    {
        $conn = DB::table('intervals_connections')->where('user_id', $user->id)->first();
        if (! $conn) {
            return ['ok' => false, 'days' => 0, 'activities' => 0, 'error' => 'Not connected.'];
        }
        $client ??= $this->client($conn);
        $today = now($user->profile->tz ?? config('fuel.default_timezone'))->toDateString();
        $oldest = date('Y-m-d', strtotime($today . ' -' . ($conn->last_synced_at ? self::REFRESH_DAYS : self::FIRST_DAYS) . ' days'));
        $newest = date('Y-m-d', strtotime($today . ' +1 day'));
        $days = $acts = 0;
        try {
            foreach ($client->wellness($oldest, $newest) as $w) {
                $m = is_array($w) ? WatchMapper::wellness($w) : null;
                if ($m) {
                    $d = $m['local_date'];
                    unset($m['local_date']);
                    DB::table('watch_days')->updateOrInsert(['user_id' => $user->id, 'local_date' => $d], $m + ['updated_at' => now(), 'created_at' => now()]);
                    $days++;
                }
            }
            foreach ($client->activities($oldest, $newest) as $a) {
                $m = is_array($a) ? WatchMapper::activity($a) : null;
                if ($m) {
                    $id = $m['ext_id'];
                    unset($m['ext_id']);
                    DB::table('watch_activities')->updateOrInsert(['user_id' => $user->id, 'ext_id' => $id], $m + ['updated_at' => now(), 'created_at' => now()]);
                    $acts++;
                }
            }
            DB::table('intervals_connections')->where('user_id', $user->id)->update(['last_synced_at' => now(), 'last_error' => null, 'updated_at' => now()]);

            return ['ok' => true, 'days' => $days, 'activities' => $acts, 'error' => null];
        } catch (IntervalsException $e) {
            DB::table('intervals_connections')->where('user_id', $user->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 200), 'updated_at' => now()]);

            return ['ok' => false, 'days' => $days, 'activities' => $acts, 'error' => $e->getMessage()];
        }
    }
}
