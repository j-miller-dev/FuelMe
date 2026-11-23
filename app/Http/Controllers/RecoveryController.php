<?php

namespace App\Http\Controllers;

use App\Services\DeloadService;
use App\Services\RecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** /recovery: your recovery signal (explained), sleep, HRV and resting HR against your own baseline, training load, and deload weeks. */
class RecoveryController extends Controller
{
    public function show(Request $request, RecoveryService $recovery): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Recovery', ['data' => $recovery->overview($user)]);
    }

    public function sleepTarget(Request $request): RedirectResponse
    {
        $d = $request->validate(['hours' => ['required', 'numeric', 'min:5', 'max:11']]);
        $request->user()->profile()->update(['sleep_target_min' => (int) round(((float) $d['hours']) * 60 / 5) * 5]);

        return back();
    }

    /** Label (or un-label) the week that contains `date` as a deload. */
    public function deload(Request $request, DeloadService $deloads): RedirectResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'on' => ['required', 'boolean']]);
        $deloads->set($request->user(), $d['date'], (bool) $d['on']);

        return back();
    }
}
