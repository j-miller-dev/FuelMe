<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\GoalRates;
use App\Domain\Progress\EventPlanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function __construct(private ProgressService $progress) {}

    /** @return ?array<string,mixed> the saved event with a fresh assessment against today's trend */
    public function current(User $user): ?array
    {
        $e = DB::table('events')->where('user_id', $user->id)->first();
        if (! $e) {
            return null;
        }
        $p = $user->profile;
        $today = LocalDay::of(now(), $p->tz, $p->rollover_hour);
        $days = (int) round((strtotime((string) $e->event_date) - strtotime($today)) / 86400);
        $o = $this->progress->overview($user);
        $s = $o['summary'];
        $exp = GoalRates::experience((string) $p->training_age);
        $phases = $e->phases ? json_decode($e->phases, true) : [];

        return [
            'name' => $e->name, 'event_date' => (string) $e->event_date, 'target_kg' => (float) $e->target_kg, 'days' => $days, 'phases' => $phases,
            'assessment' => $days >= 1 ? EventPlanner::assess((float) $s['trendKg'], (float) $e->target_kg, $days, $exp, (float) $s['tdee'], $s['ratePctPerWeek'] === null ? null : (float) $s['ratePctPerWeek'], (float) config('fuel.kcal_per_kg')) : null,
            'timeline' => $phases ? EventPlanner::timeline((float) $s['trendKg'], $today, $phases, $exp, (float) $s['tdee'], $s['ratePctPerWeek'] === null ? null : (float) $s['ratePctPerWeek']) : [],
            'trend_kg' => $s['trendKg'], 'calibrating' => $s['calibrating'],
        ];
    }
}
