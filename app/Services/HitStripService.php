<?php

namespace App\Services;

use App\Domain\Progress\HitRules;
use App\Models\TargetHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The last few COMPLETED days against their targets, for the strip on Today. A day's target is the plan for that day as it stands now
 * (your current plan, replanned for that week's real sessions). A target that changed since then changes how an old day
 * is judged, so this is a quick glance, not an audit.
 */
class HitStripService
{
    public const DAYS = 5;

    public function __construct(private PlanService $plans) {}

    /** @return array{days:list<array<string,mixed>>,summary:array{logged:int,onTarget:int,total:int}} */
    public function forUser(User $user, string $today): array
    {
        $dates = [];
        for ($i = self::DAYS; $i >= 1; $i--) {
            $dates[] = date('Y-m-d', strtotime($today . " -{$i} days"));
        }

        return $this->forDates($user, $dates);
    }

    /** @param list<string> $all dates, oldest first; days before your first target are skipped @return array{days:list<array<string,mixed>>,summary:array{logged:int,onTarget:int,total:int}} */
    public function forDates(User $user, array $all): array
    {
        $first = TargetHistory::where('user_id', $user->id)->min('effective_from');
        $dates = array_values(array_filter($all, fn ($d) => $first && $d >= substr((string) $first, 0, 10)));
        if (! $dates) {
            return ['days' => [], 'summary' => ['logged' => 0, 'onTarget' => 0, 'total' => 0]];
        }

        $sums = DB::table('food_logs')->where('user_id', $user->id)->whereIn('local_date', $dates)->groupBy('local_date')
            ->selectRaw('local_date, sum(kcal) as kcal, sum(protein) as protein, sum(carbs) as carbs, sum(fat) as fat')->get()->keyBy('local_date');
        $rules = $user->profile->hit_rules;

        $days = [];
        $evals = [];
        foreach ($dates as $d) {
            $t = $this->plans->forDay($user, $d)['day'];
            $target = ['kcal' => (float) $t['kcal'], 'protein' => (float) $t['protein'], 'carbs' => (float) $t['carbs'], 'fat' => (float) $t['fat']];
            $s = $sums[$d] ?? null;
            $eaten = ['kcal' => (float) ($s->kcal ?? 0), 'protein' => (float) ($s->protein ?? 0), 'carbs' => (float) ($s->carbs ?? 0), 'fat' => (float) ($s->fat ?? 0)];
            $e = HitRules::evaluate($eaten, $target, $rules);
            $evals[] = $e;
            $days[] = ['date' => $d, 'logged' => $e['logged'], 'hits' => $e['hits'], 'eaten' => array_map('intval', array_map('round', $eaten)), 'target' => array_map('intval', array_map('round', $target))];
        }

        return ['days' => $days, 'summary' => HitRules::summary($evals)];
    }
}
