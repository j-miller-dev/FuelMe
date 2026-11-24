<?php

namespace App\Http\Controllers;

use App\Services\Hevy\SessionCardsService;
use App\Services\Hevy\StrengthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** /lifting/sessions: one card per synced lifting session, grouped by month. */
class LiftSessionsController extends Controller
{
    public function index(Request $request, SessionCardsService $cards, StrengthService $strength): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Sessions', [
            'connected' => $strength->connected($user),
            'months' => $strength->connected($user) ? $cards->months($user) : [],
            'notes' => [
                'Everything on a card comes from the sets you logged in Hevy. Warm-ups are left out of sets and volume.',
                'Volume is kg x reps of sets that have both. Bodyweight sets count as sets, not volume.',
                'PR means this session beat everything you logged before for that lift (heaviest weight or estimated 1RM, an estimate). The first time you log a lift is a baseline, never a PR.',
                '"vs last time" compares total volume with your previous session of the same name. A lighter day can be the plan, so it is a description, not a score.',
            ],
        ]);
    }
}
