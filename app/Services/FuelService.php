<?php

namespace App\Services;

use App\Domain\Foods\QuickFoods;
use App\Domain\Training\DayFuel;
use App\Domain\Training\MealComposer;
use App\Domain\Training\PreSessionTimeline;
use App\Http\Controllers\MealController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The "next up" peri-workout card: pre/during/post targets for the day's sessions plus concrete meals from YOUR meals and favourites. */
class FuelService
{
    public function __construct(private TrainingService $training, private QuickFoods $quick, private MealController $meals) {}

    /**
     * @param array{kcal:float,protein:float,carbs:float,fat:float} $remaining what is left of today's targets
     * @return array<string,mixed>|null null when no session is still to come today
     */
    public function forToday(User $user, string $date, int $nowMinutes, array $remaining): ?array
    {
        $sessions = array_values(array_filter($this->training->sessions($user, $date), fn ($s) => in_array($s['status'], ['virtual', 'planned', 'done'], true)));
        if (! $sessions) {
            return null;
        }
        $kg = $this->training->kg($user);
        $feed = array_map(fn ($s) => ['key' => $s['key'], 'type' => $s['type'], 'intensity' => $s['intensity'], 'minutes' => $s['minutes'], 'start' => $s['start'], 'name' => $s['label']], $sessions);

        // The next session still to come: not done, and not already finished.
        $byKey = array_column($sessions, null, 'key');
        $next = null;
        foreach (DayFuel::plan($kg, $feed, 2.0) as $f) {
            $s = $byKey[$f['key']];
            if ($s['status'] === 'done') {
                continue;
            }
            if ($f['endMin'] === null || $f['endMin'] > $nowMinutes) {
                $next = $f;
                break;
            }
        }
        if ($next === null) {
            return null;
        }

        [$cands, $hasTags] = $this->candidates($user);
        $leads = [];
        foreach ([1, 2, 3] as $h) {
            $plan = collect(DayFuel::plan($kg, $feed, (float) $h))->firstWhere('key', $next['key']);
            $pre = $plan['pre'];
            $composer = isset($pre['carbsG']) ? MealComposer::compose(['carbsG' => (float) $pre['carbsG'], 'proteinG' => (float) $pre['proteinG'], 'fatMaxG' => (float) $pre['fatMaxG'], 'fibreMaxG' => (float) $pre['fibreMaxG']],
                $this->forWindow($cands, 'pre'), $remaining) : null;
            $leads[$h] = ['pre' => $pre, 'composer' => $composer];
            if ($h === 2) {
                $post = $plan['post'];
                $postCarbs = $post['carbsG'] ?? ($post['carbsGPerHour'][0] ?? 0);
                $postMeal = MealComposer::compose(['carbsG' => (float) $postCarbs, 'proteinG' => (float) $post['proteinG'], 'fatMaxG' => null, 'fibreMaxG' => null], $this->forWindow($cands, 'post'), $remaining);
            }
        }
        $plan2 = collect(DayFuel::plan($kg, $feed, 2.0))->firstWhere('key', $next['key']);
        $s = $byKey[$next['key']];

        // A clock-time plan (needs a start time): the ~3 h meal, a small quick-carb top-up, fluids and the after meal.
        $timeline = null;
        if ($next['startMin'] !== null) {
            $main = $leads[3]['pre'];
            $top = collect(DayFuel::plan($kg, $feed, 0.5))->firstWhere('key', $next['key'])['pre'];
            $timeline = PreSessionTimeline::build((int) $next['startMin'], (int) $s['minutes'], $kg, $nowMinutes, isset($main['carbsG']) ? $main : null, isset($top['carbsG']) ? $top : null, $plan2['post']);
        }

        return [
            'next' => ['key' => $s['key'], 'label' => $s['label'], 'type' => $s['type'], 'intensity' => $s['intensity'], 'minutes' => $s['minutes'], 'start' => $s['start'], 'netKcal' => $s['netKcal'],
                'minutesUntil' => $next['startMin'] !== null ? $next['startMin'] - $nowMinutes : null, 'sharedMealNote' => $plan2['sharedMealNote'], 'gapToNextHours' => $plan2['gapToNextHours']],
            'during' => $plan2['during'], 'post' => $plan2['post'], 'postMeal' => $postMeal ?? null, 'leads' => $leads, 'timeline' => $timeline, 'hasTags' => $hasTags,
            'later' => count(array_filter($sessions, fn ($x) => $x['status'] !== 'done' && $x['key'] !== $next['key'])),
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:bool} meals (with tags) and favourites, each as one usual portion */
    private function candidates(User $user): array
    {
        $out = [];
        $tagged = false;
        foreach (DB::table('meals')->where('user_id', $user->id)->pluck('id') as $id) {
            $m = $this->meals->one((int) $id, $user->id);
            if ($m['computed']['incomplete'] > 0) {
                continue;
            }
            $p = $m['computed']['portion'];
            $tags = array_values(array_intersect($m['tags'], ['pre', 'post']));
            $tagged = $tagged || $tags !== [];
            $out[] = ['id' => 'm' . $m['id'], 'kind' => 'meal', 'ref' => $m['id'], 'name' => $m['name'], 'unit' => 'portion', 'amount' => 1.0, 'kcal' => (float) $p['kcal'], 'protein' => (float) $p['protein'],
                'carbs' => (float) $p['carbs'], 'fat' => (float) $p['fat'], 'fibre' => (float) $p['fibre'], 'tagged' => $tags !== [], 'tags' => $tags];
        }
        foreach ($this->quick->for($user->id, now(), $user->profile->tz)['favourites'] as $h) {
            if ($h['per100']['kcal'] === null) {
                continue;
            }
            $g = (float) ($h['last_grams'] ?? ($h['servings'][0]['grams'] ?? 100));
            $f = $g / 100;
            $out[] = ['id' => 'f' . $h['id'], 'kind' => 'food', 'ref' => $h['id'], 'name' => $h['brand'] && ! str_contains(mb_strtolower($h['name']), mb_strtolower($h['brand'])) ? $h['brand'] . ' ' . $h['name'] : $h['name'],
                'unit' => $h['is_liquid'] ? 'mL' : 'g', 'amount' => $g, 'kcal' => (float) $h['per100']['kcal'] * $f, 'protein' => (float) ($h['per100']['protein'] ?? 0) * $f, 'carbs' => (float) ($h['per100']['carbs'] ?? 0) * $f,
                'fat' => (float) ($h['per100']['fat'] ?? 0) * $f, 'fibre' => (float) ($h['per100']['fibre'] ?? 0) * $f, 'tagged' => false, 'tags' => []];
        }

        return [$out, $tagged];
    }

    /** Meals tagged for this window count as tagged (preferred); untagged meals are still usable; favourites always are. */
    private function forWindow(array $cands, string $window): array
    {
        return array_map(fn ($c) => ['tagged' => in_array($window, $c['tags'] ?? [], true)] + $c, $cands);
    }
}
