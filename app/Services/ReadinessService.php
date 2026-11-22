<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\Insights;
use App\Models\TargetHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The morning check-in, the readiness hook and the personal insights. Everything here is plain arithmetic on what you logged. */
class ReadinessService
{
    public function __construct(private TrainingService $training) {}

    public function save(User $user, string $date, int $sleep, int $soreness, int $energy, ?string $notes): void
    {
        DB::table('readiness_logs')->updateOrInsert(['user_id' => $user->id, 'local_date' => $date], [
            'sleep' => $sleep, 'soreness' => $soreness, 'energy' => $energy, 'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    /** @return array{sleep:int,soreness:int,energy:int,notes:?string}|null */
    public function forDate(User $user, string $date): ?array
    {
        $r = DB::table('readiness_logs')->where('user_id', $user->id)->where('local_date', $date)->first();

        return $r ? ['sleep' => (int) $r->sleep, 'soreness' => (int) $r->soreness, 'energy' => (int) $r->energy, 'notes' => $r->notes] : null;
    }

    /** @return array{days:int,message:string,options:list<string>}|null */
    public function hook(User $user, string $today): ?array
    {
        $recent = DB::table('readiness_logs')->where('user_id', $user->id)->where('local_date', '<=', $today)->orderByDesc('local_date')->limit(Insights::HOOK_DAYS)->get()
            ->map(fn ($r) => ['date' => (string) $r->local_date, 'sleep' => (int) $r->sleep, 'soreness' => (int) $r->soreness, 'energy' => (int) $r->energy])->all();
        if (count($recent) < Insights::HOOK_DAYS) {
            return null;
        }
        // The next hard session: still to do today, otherwise tomorrow's.
        foreach ([$today, date('Y-m-d', strtotime($today . ' +1 day'))] as $d) {
            foreach ($this->training->sessions($user, $d) as $s) {
                if (in_array($s['status'], ['virtual', 'planned'], true) && in_array($s['intensity'], Insights::HARD, true)) {
                    return Insights::readinessHook($recent, ['label' => $s['label'], 'intensity' => $s['intensity']]);
                }
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> only the comparisons that have at least 8 pairs */
    public function insights(User $user): array
    {
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $since = date('Y-m-d', strtotime($today . ' -180 days'));
        $rd = DB::table('readiness_logs')->where('user_id', $user->id)->where('local_date', '>=', $since)->get()->keyBy(fn ($r) => (string) $r->local_date);
        if ($rd->count() < Insights::MIN_PAIRS) {
            return [];
        }

        $sleepEnergy = $sleepSore = [];
        foreach ($rd as $r) {
            $sleepEnergy[] = [(float) $r->sleep, (float) $r->energy];
            $sleepSore[] = [(float) $r->sleep, (float) $r->soreness];
        }

        // Yesterday's carbs per kg (only on days that were properly logged) against this morning's energy.
        $kg = max(1.0, $this->training->kg($user));
        $targets = TargetHistory::where('user_id', $user->id)->orderBy('effective_from')->orderBy('id')->get();
        $carbs = DB::table('food_logs')->where('user_id', $user->id)->where('local_date', '>=', date('Y-m-d', strtotime($since . ' -1 day')))->where('is_rough', false)
            ->groupBy('local_date')->selectRaw('local_date, sum(kcal) as kcal, sum(carbs) as carbs')->get()->keyBy(fn ($r) => (string) $r->local_date);
        $carbsEnergy = [];
        foreach ($rd as $date => $r) {
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $c = $carbs[$prev] ?? null;
            $t = $targets->last(fn ($x) => $x->effective_from->toDateString() <= $prev);
            if ($c && $t && (float) $c->kcal >= Adherence::LOGGED_AT * (float) $t->kcal) {
                $carbsEnergy[] = [(float) $c->carbs / $kg, (float) $r->energy];
            }
        }

        return array_values(array_filter([
            Insights::pair('sleep_energy', 'Sleep and energy', 'sleep quality', 'morning energy', $sleepEnergy),
            Insights::pair('sleep_soreness', 'Sleep and soreness', 'sleep quality', 'soreness', $sleepSore),
            Insights::pair('carbs_energy', 'Yesterday\'s carbs and energy', 'carbs the day before (g per kg)', 'next-morning energy', $carbsEnergy),
        ]));
    }
}
