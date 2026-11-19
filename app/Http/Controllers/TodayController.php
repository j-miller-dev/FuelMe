<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Models\FoodLog;
use App\Models\TargetHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        if (! $profile) {
            return redirect()->route('onboarding');
        }

        $today = LocalDay::of(now(), $profile->tz, $profile->rollover_hour);
        $date = $request->query('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= $today && $date > date('Y-m-d', strtotime($today . ' -400 days')) ? $date : $today;

        $target = TargetHistory::where('user_id', $user->id)->latest('effective_from')->latest('id')->firstOrFail();
        $key = LocalDay::dayKey($date);
        $day = $target->plan_json['plan']['days'][$key] ?? null;

        $logs = FoodLog::where('user_id', $user->id)->where('local_date', $date)->orderBy('logged_at')->orderBy('id')->get();
        $prev = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevLogs = FoodLog::where('user_id', $user->id)->where('local_date', $prev)->get(['kcal']);

        return Inertia::render('Today', [
            'date' => $date,
            'isToday' => $date === $today,
            'dayClass' => $day['class'] ?? null,
            'target' => [
                'kcal' => (int) round($day['kcal'] ?? $target->kcal), 'protein' => (int) round($day['protein'] ?? $target->protein),
                'carbs' => (int) round($day['carbs'] ?? $target->carbs), 'fat' => (int) round($day['fat'] ?? $target->fat),
            ],
            'logs' => $logs->map->toPayload()->values(),
            'previous' => ['date' => $prev, 'count' => $prevLogs->count(), 'kcal' => (int) round($prevLogs->sum('kcal'))],
            'calibrating' => true, // until M7's expenditure filter reports a settled estimate
            'serverHour' => (int) now($profile->tz)->format('G'),
        ]);
    }
}
