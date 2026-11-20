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
            'training' => ['catalogue' => $training->catalogue(), 'kg' => round($training->kg($user), 1), 'sessions' => $training->sessions($user, $date, true), 'explain' => $dp['explain'], 'fuel' => $date === $today ? $fuel->forToday($user, $date, (int) $local->format('G') * 60 + (int) $local->format('i'), $remaining) : null],
            'target' => [
                'kcal' => (int) round($day['kcal'] ?? $target->kcal), 'protein' => (int) round($day['protein'] ?? $target->protein),
                'carbs' => (int) round($day['carbs'] ?? $target->carbs), 'fat' => (int) round($day['fat'] ?? $target->fat),
            ],
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
}
