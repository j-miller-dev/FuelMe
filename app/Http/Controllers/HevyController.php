<?php

namespace App\Http\Controllers;

use App\Domain\Training\EffortRamp;
use App\Jobs\SyncHevy;
use App\Services\Hevy\HevyClient;
use App\Services\Hevy\HevyException;
use App\Services\Hevy\StrengthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /lifting: connect Hevy (read-only), sync, and see your strength numbers. The API key is stored encrypted and never sent back to the browser. */
class HevyController extends Controller
{
    public function show(Request $request, StrengthService $strength): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $conn = DB::table('hevy_connections')->where('user_id', $user->id)->first();

        return Inertia::render('Lifting', [
            'connection' => $conn ? ['last_synced_at' => $conn->last_synced_at ? \Illuminate\Support\Carbon::parse($conn->last_synced_at, 'UTC')->toIso8601String() : null, 'last_error' => $conn->last_error] : null,
            'data' => $conn ? $strength->overview($user) : null,
            'insights' => $conn ? $strength->insights($user) : [],
            'ramp' => $user->profile->effort_ramp ?: null,
            'rampNow' => EffortRamp::weekFor($user->profile->effort_ramp ?: null, now($user->profile->tz)->toDateString()),
            'today' => now($user->profile->tz)->toDateString(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $d = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:200']]);
        try {
            (new HevyClient(trim($d['api_key'])))->userInfo();
        } catch (HevyException $e) {
            return back()->withErrors(['api_key' => $e->getMessage()]);
        }
        DB::table('hevy_connections')->updateOrInsert(['user_id' => $request->user()->id], ['api_key' => Crypt::encryptString(trim($d['api_key'])), 'cursor_at' => null, 'templates_synced_at' => null, 'last_error' => null, 'updated_at' => now(), 'created_at' => now()]);
        SyncHevy::dispatch($request->user()->id);

        return back();
    }

    public function sync(Request $request): RedirectResponse
    {
        abort_unless(DB::table('hevy_connections')->where('user_id', $request->user()->id)->exists(), 404);
        SyncHevy::dispatch($request->user()->id);

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $request->user();
        DB::table('hevy_connections')->where('user_id', $user->id)->delete();
        if ($request->boolean('delete_data')) {
            DB::table('hevy_workouts')->where('user_id', $user->id)->delete();   // sets go with them (cascade)
            DB::table('hevy_templates')->where('user_id', $user->id)->delete();
        }

        return back();
    }

    /** Your own reps-in-reserve plan for lifting weeks, for example 2-3, 1-2, then 0-1. Cleared with DELETE. */
    public function saveRamp(Request $request): RedirectResponse
    {
        $ramp = EffortRamp::normalise($request->input('ramp'));
        if ($ramp === null) {
            return back()->withErrors(['ramp' => 'Each week needs a low and high number of reps in reserve from 0 to 5, low not above high, up to 8 weeks, and a start date.']);
        }
        $request->user()->profile()->update(['effort_ramp' => $ramp]);

        return back();
    }

    public function clearRamp(Request $request): RedirectResponse
    {
        $request->user()->profile()->update(['effort_ramp' => null]);

        return back();
    }
}
