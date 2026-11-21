<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Models\FoodLog;
use App\Models\TargetHistory;
use App\Services\FuelService;
use App\Services\HitStripService;
use App\Services\PlanService;
use App\Services\ProgressService;
use App\Services\TrainingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function show(Request $request, ProgressService $progress, PlanService $plans, TrainingService $training, FuelService $fuel, HitStripService $strip): Response|RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        if (! $profile) {
            return redirect()->route('onboarding');
        }

        $today = LocalDay::of(now(), $profile->tz, $profile->rollover_hour);
        $date = $request->query('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= $today && $date > date('Y-m-d', strtotime($today . ' -400 days')) ? $date : $today;

        $overview = $progress->overview($user);
        $target = TargetHistory::where('user_id', $user->id)->latest('effective_from')->latest('id')->firstOrFail();
        // The target for THIS date: the current plan, replanned if this week's real sessions differ from the usual week.
        $dp = $plans->forDay($user, $date);
        $day = $dp['day'];

        $logs = FoodLog::where('user_id', $user->id)->where('local_date', $date)->orderBy('logged_at')->orderBy('id')->get();
        $eaten = ['kcal' => (float) $logs->sum('kcal'), 'protein' => (float) $logs->sum('protein'), 'carbs' => (float) $logs->sum('carbs'), 'fat' => (float) $logs->sum('fat')];
        $remaining = ['kcal' => (float) $day['kcal'] - $eaten['kcal'], 'protein' => (float) $day['protein'] - $eaten['protein'], 'carbs' => (float) $day['carbs'] - $eaten['carbs'], 'fat' => (float) $day['fat'] - $eaten['fat']];
        $local = now($profile->tz);
        $prev = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevLogs = FoodLog::where('user_id', $user->id)->where('local_date', $prev)->get(['kcal']);

        return Inertia::render('Today', [
            'date' => $date,
            'isToday' => $date === $today,
            'dayClass' => $day['class'] ?? null,
            'training' => ['catalogue' => $training->catalogue(), 'kg' => round($training->kg($user), 1), 'sessions' => $training->sessions($user, $date, true), 'explain' => $dp['explain'], 'fuel' => $date === $today ? $this->fuelPlan($fuel, $plans, $user, $today, (int) $local->format('G') * 60 + (int) $local->format('i'), $remaining) : null],
            'target' => [
                'kcal' => (int) round($day['kcal'] ?? $target->kcal), 'protein' => (int) round($day['protein'] ?? $target->protein),
                'carbs' => (int) round($day['carbs'] ?? $target->carbs), 'fat' => (int) round($day['fat'] ?? $target->fat),
            ],
            'estimate' => ($e = \Illuminate\Support\Facades\DB::table('day_estimates')->where('user_id', $user->id)->where('local_date', $date)->first()) ? ['kind' => $e->kind, 'kcal' => (int) round((float) $e->kcal)] : null,
            'consistency' => $overview['consistency'] ?? null,
            'water' => $this->water($user, $date, $profile->sex),
            'strip' => $strip->forUser($user, $today),
            'logs' => $logs->map->toPayload()->values(),
            'previous' => ['date' => $prev, 'count' => $prevLogs->count(), 'kcal' => (int) round($prevLogs->sum('kcal'))],
            'calibrating' => $overview['summary']['calibrating'] ?? true,
            // The undereating nudge shows on today only, so history pages are not cluttered. Never shaming: it just offers 2-3 usual foods.
            'nudge' => $date === $today && ($overview['undereating']['flag'] ?? false) ? $overview['undereating'] : null,
            'weighedToday' => (bool) \Illuminate\Support\Facades\DB::table('weight_logs')->where('user_id', $user->id)->where('local_date', $today)->exists(),
            'serverHour' => (int) now($profile->tz)->format('G'),
        ]);
    }

    /** Today's next session; when none is left today, tomorrow's first one (so the night before shows the plan). */
    private function fuelPlan(FuelService $fuel, PlanService $plans, $user, string $today, int $nowMin, array $remaining): ?array
    {
        $f = $fuel->forToday($user, $today, $nowMin, $remaining);
        if ($f !== null) {
            return $f + ['when' => 'today'];
        }
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $day = $plans->forDay($user, $tomorrow)['day'];
        $f = $fuel->forToday($user, $tomorrow, $nowMin - 1440, ['kcal' => (float) $day['kcal'], 'protein' => (float) $day['protein'], 'carbs' => (float) $day['carbs'], 'fat' => (float) $day['fat']]);

        return $f === null ? null : $f + ['when' => 'tomorrow'];
    }

    /** @return array{ml:int,targetMl:int,lastId:?int} */
    private function water($user, string $date, string $sex): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('water_logs')->where('user_id', $user->id)->where('local_date', $date);

        return ['ml' => (int) (clone $rows)->sum('ml'), 'targetMl' => \App\Domain\Training\Hydration::dailyFluidMl($sex), 'lastId' => (clone $rows)->orderByDesc('id')->value('id')];
    }
}
