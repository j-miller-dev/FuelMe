<?php

namespace App\Http\Controllers;

use App\Domain\Nutrition\GoalRates;
use App\Http\Requests\OnboardingRequest;
use App\Models\WeightLog;
use App\Services\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        $editing = $request->routeIs('profile.edit');

        if ($profile && ! $editing) {
            return redirect()->route('home');
        }

        $goal = $user->goal;
        $weight = WeightLog::where('user_id', $user->id)->latest('local_date')->value('kg');

        return Inertia::render('Onboarding', [
            'editing' => $editing,
            'defaults' => $profile ? [
                'sex' => $profile->sex,
                'dob' => $profile->dob->toDateString(),
                'height_cm' => $profile->height_cm,
                'weight_kg' => $weight,
                'body_fat_pct' => $profile->body_fat_pct,
                'job' => $profile->job,
                'shift' => $profile->shift,
                'training_age' => $profile->training_age,
                'lifting_style' => $profile->lifting_style,
                'energy_unit' => $profile->energy_unit,
                'phase' => $goal?->phase ?? 'maintain',
                'rate_pct_per_week' => $goal?->rate_pct_per_week,
                'protein_g_per_kg' => $profile->protein_g_per_kg_override,
            ] : null,
            // Defaults the UI shows next to each phase, straight from the engine (no duplicated numbers in TS).
            'rateDefaults' => collect(['novice', 'intermediate', 'advanced'])->mapWithKeys(fn ($e) => [
                $e => ['bulk' => GoalRates::default('bulk', $e), 'cut' => GoalRates::default('cut', $e), 'maintain' => 0.0],
            ]),
        ]);
    }

    public function store(OnboardingRequest $request, OnboardingService $service): RedirectResponse
    {
        $service->apply($request->user(), $request->validated(), $request->user()->profile ? 'profile_edit' : 'onboarding');

        return redirect()->route('home');
    }
}
