<?php

namespace App\Services;

use App\Domain\Progress\Achievements;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Gathers the numbers the pure Achievements rules need. Read-only apart from remembering what you swiped away. */
class AchievementsService
{
    public function __construct(private HitStripService $strip) {}

    /** @param list<array<string,mixed>> $cards session cards newest first (may be empty without Hevy) @return list<array<string,mixed>> */
    public function forUser(User $user, string $today, array $cards): array
    {
        $dismissed = DB::table('achievement_dismissals')->where('user_id', $user->id)->pluck('key')->all();
        $start = date('Y-m-d', strtotime('monday this week', strtotime($today . ' -7 days')));
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
        $week = $today <= date('Y-m-d', strtotime($end . ' +' . Achievements::WEEK_SHOWN_DAYS . ' days')) ? $this->week($user, $start, $end) : null;

        return Achievements::build(['today' => $today, 'cards' => $cards, 'week' => $week, 'dismissed' => $dismissed]);
    }

    /** @param list<string> $keys */
    public function dismiss(User $user, array $keys): void
    {
        foreach (array_unique($keys) as $k) {
            DB::table('achievement_dismissals')->insertOrIgnore(['user_id' => $user->id, 'key' => mb_substr($k, 0, 100), 'created_at' => now()]);
        }
    }

    /** @return array<string,mixed> */
    private function week(User $user, string $start, string $end): array
    {
        // training: every planned session in the week done. Weeks with no rows say nothing (an untouched template day has no row to judge).
        $rows = DB::table('workout_logs')->where('user_id', $user->id)->get(['local_date', 'status']);
        $weeks = [];
        foreach ($rows as $r) {
            if (! in_array($r->status, ['planned', 'done', 'skipped'], true)) {
                continue;
            }
            $mon = date('Y-m-d', strtotime('monday this week', strtotime((string) $r->local_date)));
            $weeks[$mon]['planned'] = ($weeks[$mon]['planned'] ?? 0) + 1;
            $weeks[$mon]['done'] = ($weeks[$mon]['done'] ?? 0) + ($r->status === 'done' ? 1 : 0);
        }
        $full = fn (array $x) => ($x['planned'] ?? 0) >= 2 && $x['done'] === $x['planned'];
        $firstFull = $full($weeks[$start] ?? []) && ! count(array_filter($weeks, fn ($x, $m) => $m < $start && $full($x), ARRAY_FILTER_USE_BOTH));

        $dates = array_map(fn ($i) => date('Y-m-d', strtotime($start . " +{$i} days")), range(0, 6));
        $days = $this->strip->forDates($user, $dates)['days'];
        $logged = array_filter($days, fn ($d) => $d['logged']);

        $target = (int) ($user->profile->sleep_target_min ?: RecoveryService::DEFAULT_SLEEP_TARGET_MIN);
        $nights = DB::table('watch_days')->where('user_id', $user->id)->whereBetween('local_date', [$start, $end])->whereNotNull('sleep_secs')->pluck('sleep_secs')->all();
        $on = count(array_filter($nights, fn ($s) => $s / 60 >= 0.9 * $target));

        return ['start' => $start, 'end' => $end, 'planned' => $weeks[$start]['planned'] ?? 0, 'done' => $weeks[$start]['done'] ?? 0, 'firstFull' => $firstFull,
            'loggedDays' => count($logged), 'proteinDays' => count(array_filter($logged, fn ($d) => $d['hits']['protein'])), 'sleepNights' => count($nights), 'sleepOn' => $on];
    }
}
