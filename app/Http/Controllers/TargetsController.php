<?php

namespace App\Http\Controllers;

use App\Domain\Progress\HitRules;
use App\Http\Requests\TargetsRequest;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The recommendation next to your own adjustments. Overrides live on the profile and never edit the recommendation. */
class TargetsController extends Controller
{
    public function show(Request $request, PlanService $plans): Response|RedirectResponse
    {
        $user = $request->user();
        $p = $user->profile;
        if (! $p) {
            return redirect()->route('onboarding');
        }

        $rec = $plans->base($user, false);
        $mine = $plans->base($user, true);
        $kg = $plans->inputs($user, false)['kg'];
        $pick = fn (array $w) => ['kcal' => (int) $w['kcalAvg'], 'protein' => (int) $w['protein'], 'fat' => (int) $w['fat'], 'carbs' => (int) $w['carbsAvg'], 'proteinGPerKg' => (float) $w['proteinGPerKg']];

        return Inertia::render('Targets', [
            'recommended' => $pick($rec['weekly']),
            'yours' => $pick($mine['weekly']),
            'warnings' => $mine['warnings'],
            'overrides' => [
                'protein_g_override' => $p->protein_g_override,
                'fat_min_g_per_kg' => $p->fat_min_g_per_kg,
                'kcal_offset' => (int) $p->kcal_offset,
                'protein_g_per_kg_profile' => $p->protein_g_per_kg_override,
            ],
            'hitRules' => HitRules::normalise($p->hit_rules),
            'hitDefaults' => HitRules::DEFAULTS,
            'style' => $p->lifting_style,
            'weightKg' => round($kg, 1),
            'saved' => (bool) $request->session()->get('saved'),
        ]);
    }

    public function update(TargetsRequest $request): RedirectResponse
    {
        $d = $request->validated();
        $profile = $request->user()->profile;
        abort_unless($profile, 404);

        $profile->update([
            'protein_g_override' => $d['protein_g_override'] ?? null,
            'fat_min_g_per_kg' => $d['fat_min_g_per_kg'] ?? null,
            'kcal_offset' => (int) ($d['kcal_offset'] ?? 0),
            'hit_rules' => isset($d['hit_rules']) ? HitRules::normalise($d['hit_rules']) : null,
        ]);

        return redirect()->route('targets')->with('saved', true);
    }
}
