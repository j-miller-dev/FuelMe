<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\MacroPlanner;
use App\Domain\Nutrition\Replanner;
use App\Models\TargetHistory;
use App\Models\User;

/**
 * The macro plan as it stands NOW: your latest calorie target and body weight, planned over your CURRENT training template (so editing the
 * template needs no new target row), and, for a given date, replanned when that week's real sessions differ from the template.
 */
class PlanService
{
    public function __construct(private TargetService $targets, private TrainingService $training) {}

    /**
     * The inputs to plan with. $withOverrides = false gives the app's own RECOMMENDATION (what the weekly check-in works from);
     * true layers your adjustments on top: fixed protein grams (or g/kg), a fat floor and a kcal offset. Overrides never edit the recommendation.
     *
     * @return array{latest:TargetHistory,in:array<string,mixed>,kcal:float,kg:float}
     */
    public function inputs(User $user, bool $withOverrides = true): array
    {
        $latest = TargetHistory::where('user_id', $user->id)->latest('effective_from')->latest('id')->firstOrFail();
        $p = $user->profile;
        $plan = $latest->plan_json;
        $kg = (float) ($plan['inputs']['weightKg'] ?? 75.0);
        $kcal = (float) ($plan['plan']['weekly']['kcalTargetUsed'] ?? $latest->kcal);
        $perKg = $p->protein_g_per_kg_override;
        $fatMin = null;

        if ($withOverrides) {
            $kcal += (int) $p->kcal_offset;
            // Fixed grams win over g/kg. The planner works in g/kg, so convert with the same weight it will use.
            $perKg = $p->protein_g_override ? $p->protein_g_override / max($kg, 1.0) : $perKg;
            $fatMin = $p->fat_min_g_per_kg;
        }

        return [
            'latest' => $latest, 'kg' => $kg, 'kcal' => $kcal,
            'in' => ['sex' => $p->sex, 'body_fat_pct' => $p->body_fat_pct, 'lifting_style' => $p->lifting_style, 'phase' => $user->goal->phase,
                'protein_g_per_kg' => $perKg, 'fat_min_g_per_kg' => $fatMin, 'carb_mode' => $p->carb_mode],
        ];
    }

    /** @return array{days:array,weekly:array,warnings:array} the plan over the template week */
    public function base(User $user, bool $withOverrides = true): array
    {
        $i = $this->inputs($user, $withOverrides);

        return $this->targets->replan($i['in'], $i['kg'], $i['kcal'], $this->training->templateWeek($user));
    }

    /**
     * The target for one date. When that week's real sessions match the template this IS the base plan. Otherwise the rest of the week is
     * replanned (days before $date keep their base targets) so the weekly carb total is unchanged.
     *
     * @return array{day:array,base:array,changed:bool,explain:?string,warnings:array}
     */
    public function forDay(User $user, string $date): array
    {
        $i = $this->inputs($user);
        $base = $this->targets->replan($i['in'], $i['kg'], $i['kcal'], $this->training->templateWeek($user));
        $key = LocalDay::dayKey($date);
        $eff = $this->training->effectiveWeek($user, $date);

        if ($eff == $this->normalise($this->training->templateWeek($user))) {
            return ['day' => $base['days'][$key], 'base' => $base['days'][$key], 'changed' => false, 'explain' => null, 'warnings' => $base['warnings']];
        }

        $plan = $this->targets->replan($i['in'], $i['kg'], $i['kcal'], $eff);
        $idx = (int) date('N', strtotime($date)) - 1;
        $past = array_slice(MacroPlanner::DAYS, 0, $idx);
        $r = Replanner::remaining($base['days'], $plan['days'], $past, $i['kg'], (float) $base['weekly']['ffmKg']);
        $day = $r['days'][$key];

        return ['day' => $day, 'base' => $base['days'][$key], 'changed' => true, 'explain' => $this->explain($user, $date, $base['days'][$key], $day), 'warnings' => $plan['warnings']];
    }

    /** The template week reduced to the same keys the effective week uses, so the two compare with ==. */
    private function normalise(array $week): array
    {
        return array_map(fn ($sessions) => array_map(fn ($s) => ['type' => $s['type'], 'intensity' => $s['intensity'], 'minutes' => $s['minutes']] + (isset($s['customMet']) ? ['customMet' => $s['customMet']] : []), $sessions), $week);
    }

    /** "Today +120 kcal because you added Norwegian 4x4." Built from the real difference, never generated. */
    private function explain(User $user, string $date, array $base, array $day): ?string
    {
        $delta = (int) round($day['kcal'] - $base['kcal']);
        $planned = array_values(array_filter($this->training->template($user), fn ($t) => $t['day'] === LocalDay::dayKey($date)));
        $sig = fn ($s) => $s['type'] . '|' . $s['intensity'] . '|' . $s['minutes'];
        $plannedSig = array_map($sig, $planned);
        $actual = $this->training->sessions($user, $date);
        $actualSig = array_map($sig, $actual);
        $added = array_values(array_filter($actual, fn ($s) => ! in_array($sig($s), $plannedSig, true)));
        $dropped = array_values(array_filter($planned, fn ($s) => ! in_array($sig($s), $actualSig, true)));
        if ($delta === 0 && ! $added && ! $dropped) {
            return null;
        }
        $name = fn ($s) => $s['label'] ?? (\App\Domain\Nutrition\ExerciseCalculator::CATALOGUE[$s['type']]['label'] ?? $s['type']);
        $why = [];
        if ($added) {
            $why[] = 'you added ' . implode(' and ', array_map($name, $added));
        }
        if ($dropped) {
            $why[] = 'you are not doing ' . implode(' and ', array_map($name, $dropped));
        }
        if (! $why) {
            return $delta === 0 ? null : sprintf('Today %+d kcal because of this week\'s training.', $delta);
        }

        $head = $delta === 0 ? 'Today stays the same' : sprintf('Today %+d kcal', $delta);

        return sprintf('%s because %s. The rest of the week adjusts so your weekly total stays the same.', $head, implode(' and ', $why));
    }
}
