<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Recovery\Baseline;
use App\Domain\Recovery\RecoverySignal;
use App\Domain\Recovery\SleepStats;
use App\Domain\Training\Deload;
use App\Domain\Training\LoadTrend;
use App\Models\User;
use App\Services\Hevy\StrengthService;
use Illuminate\Support\Facades\DB;

/**
 * Builds the recovery picture from your watch data (via Intervals.icu), your own check-in and your training. Everything is compared with YOUR baseline,
 * every flag comes with its reason, and nothing here changes a calorie target. Not medical advice.
 */
class RecoveryService
{
    public const DEFAULT_SLEEP_TARGET_MIN = 480;
    private const HISTORY_DAYS = 70;

    public function __construct(private WatchService $watch, private DeloadService $deloads, private StrengthService $strength) {}

    public function connected(User $user): bool
    {
        return DB::table('watch_days')->where('user_id', $user->id)->exists();
    }

    /** @return array<string,mixed> */
    public function overview(User $user, bool $withLift = true): array
    {
        $p = $user->profile;
        $today = LocalDay::of(now(), $p->tz, (int) $p->rollover_hour);
        $from = date('Y-m-d', strtotime($today . ' -' . self::HISTORY_DAYS . ' days'));
        $rows = DB::table('watch_days')->where('user_id', $user->id)->where('local_date', '>=', $from)->get();
        $hrv = $rhr = $nights = [];
        foreach ($rows as $r) {
            $d = (string) $r->local_date;
            $r->hrv !== null && $hrv[$d] = (float) $r->hrv;
            $r->resting_hr !== null && $rhr[$d] = (float) $r->resting_hr;
            $r->sleep_secs !== null && $nights[$d] = (int) $r->sleep_secs;
        }
        $target = (int) ($p->sleep_target_min ?: self::DEFAULT_SLEEP_TARGET_MIN);
        $checkins = DB::table('readiness_logs')->where('user_id', $user->id)->where('local_date', '>=', date('Y-m-d', strtotime($today . ' -7 days')))->get()->keyBy(fn ($r) => (string) $r->local_date);
        $ci = fn (string $d) => isset($checkins[$d]) ? ['sleep' => (int) $checkins[$d]->sleep, 'soreness' => (int) $checkins[$d]->soreness, 'energy' => (int) $checkins[$d]->energy] : null;

        $deloadDates = $this->deloads->dates($user, $from, date('Y-m-d', strtotime($today . ' +7 days')));
        $inDeload = isset($deloadDates[$today]) || $this->deloads->isDeload($user, $today);
        $load = $this->load($user, $today, $from, $deloadDates);
        $ratios = array_filter([$load['srpe']['ratio'], $load['watch']['ratio']], fn ($x) => $x !== null);
        $ratio = $ratios ? max($ratios) : null;

        $hrvNow = Baseline::compare($hrv, $today, true, 0.03);
        $rhrNow = Baseline::compare($rhr, $today, false, 1.5);
        $sleepNow = SleepStats::compute($nights, $today, $target);
        $signal = RecoverySignal::evaluate($hrvNow, $rhrNow, $sleepNow, $ci($today), $ratio, $inDeload);

        // How many of the last 7 days had two or more flags (without the load flag, which is only known for today).
        $bad = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            $s = RecoverySignal::evaluate(Baseline::compare($hrv, $d, true, 0.03), Baseline::compare($rhr, $d, false, 1.5), SleepStats::compute($nights, $d, $target), $ci($d), null);
            $bad += $s['flagCount'] >= 2 ? 1 : 0;
        }

        $mondays = $this->deloads->mondays($user);
        $weeksSince = Deload::weeksSince($mondays, $today);
        $stalled = 0;
        if ($withLift && $this->strength->connected($user)) {
            $stalled = count(array_filter($this->strength->overview($user)['exercises'] ?? [], fn ($e) => in_array($e['status'], ['stalled', 'down'], true)));
        }

        return [
            'connected' => $rows->isNotEmpty(), 'today' => $today, 'signal' => $signal, 'hrv' => $hrvNow, 'rhr' => $rhrNow, 'sleep' => $sleepNow,
            'days' => $this->watch->days($user, 14), 'load' => $load,
            'deload' => ['now' => $inDeload, 'thisWeek' => Deload::mondayOf($today), 'nextWeek' => date('Y-m-d', strtotime(Deload::mondayOf($today) . ' +7 days')), 'nextWeekOn' => in_array(date('Y-m-d', strtotime(Deload::mondayOf($today) . ' +7 days')), $mondays, true),
                'weeksSince' => $weeksSince, 'recent' => array_slice(array_reverse($mondays), 0, 6), 'suggestion' => Deload::suggest($stalled, $bad, $ratio, $weeksSince, $inDeload)],
            'assumptions' => [
                'Your usual range is your 60-day baseline plus or minus half its standard deviation (an assumption, not a clinical threshold).',
                'HRV is whatever your watch reports (usually rMSSD); different watches are not comparable, so FUEL only compares you with yourself.',
                'The sleep target is ' . intdiv($target, 60) . ' h ' . str_pad((string) ($target % 60), 2, '0', STR_PAD_LEFT) . ' min unless you change it. Sleep here is duration only, not quality.',
                'Training load is two separate scales (session RPE x minutes, and your watch\'s own load). They overlap if you rated a session you also recorded, so they are not added together.',
                'Where Polar and COROS recorded the same session, Polar is used.',
                'A red, amber or green signal is a suggestion from the flags listed. It is not a diagnosis. If you feel unwell, rest and see a GP.',
            ],
        ];
    }

    /** @return array{srpe:array<string,mixed>,watch:array<string,mixed>,weeks:list<array<string,mixed>>} */
    private function load(User $user, string $today, string $from, array $deloadDates): array
    {
        $srpe = [];
        foreach (DB::table('workout_logs')->where('user_id', $user->id)->where('status', 'done')->where('local_date', '>=', $from)->whereNotNull('rpe')->whereNotNull('minutes')->get() as $w) {
            $d = (string) $w->local_date;
            $srpe[$d] = ($srpe[$d] ?? 0) + (float) $w->rpe * (int) $w->minutes;
        }
        $watch = [];
        foreach ($this->watch->kept($user, self::HISTORY_DAYS) as $k) {
            if ($k['row']['load'] !== null) {
                $watch[$k['row']['date']] = ($watch[$k['row']['date']] ?? 0) + (int) $k['row']['load'];
            }
        }
        $weeks = [];
        $thisMon = Deload::mondayOf($today);
        for ($w = 5; $w >= 0; $w--) {
            $mon = date('Y-m-d', strtotime($thisMon . " -{$w} weeks"));
            $sr = $wa = 0.0;
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime($mon . " +{$i} days"));
                $sr += $srpe[$d] ?? 0;
                $wa += $watch[$d] ?? 0;
            }
            $weeks[] = ['weekStart' => $mon, 'srpe' => (int) round($sr), 'watch' => (int) round($wa), 'deload' => in_array($mon, $this->deloads->mondays($user), true)];
        }

        return ['srpe' => LoadTrend::ratio($srpe, $today, $deloadDates), 'watch' => LoadTrend::ratio($watch, $today, $deloadDates), 'weeks' => $weeks];
    }

    /** A small version for the Today screen, or null when there is no watch data and no deload to mention. */
    public function compact(User $user): ?array
    {
        if (! $this->connected($user)) {
            return null;
        }
        $o = $this->overview($user, false);

        return ['level' => $o['signal']['level'], 'headline' => $o['signal']['headline'], 'reasons' => array_slice($o['signal']['flags'], 0, 2), 'deload' => $o['deload']['now']];
    }
}
