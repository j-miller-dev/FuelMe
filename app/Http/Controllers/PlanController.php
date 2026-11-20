<?php

namespace App\Http\Controllers;

use App\Models\TargetHistory;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function show(Request $request, PlanService $plans): Response|RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;

        if (! $profile) {
            return redirect()->route('onboarding');
        }

        $target = TargetHistory::where('user_id', $user->id)->latest('effective_from')->latest('id')->firstOrFail();
        $goal = $user->goal;
        $plan = $target->plan_json;
        $fresh = $plans->base($user);   // planned over your CURRENT training week, so editing the week shows here at once

        return Inertia::render('Plan', [
            'target' => [
                'effectiveFrom' => $target->effective_from->toDateString(),
                'reason' => $target->reason,
                'kcal' => $target->kcal,
                'protein' => $target->protein,
                'fat' => $target->fat,
                'carbs' => $target->carbs,
            ],
            'goal' => ['phase' => $goal->phase, 'ratePctPerWeek' => $goal->rate_pct_per_week],
            'prior' => $plan['prior'],
            'days' => $fresh['days'],
            'weekly' => $fresh['weekly'],
            'warnings' => $fresh['warnings'],
            'why' => $plan['why'],
            'weightKg' => $plan['inputs']['weightKg'],
            'style' => $plan['inputs']['style'],
            'todayKey' => strtolower(now($profile->tz)->format('D')),
        ]);
    }
}
