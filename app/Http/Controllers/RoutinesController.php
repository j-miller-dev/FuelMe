<?php

namespace App\Http\Controllers;

use App\Services\Hevy\ProgressionService;
use App\Services\Hevy\RoutinesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /lifting/routines: your Hevy routines, read-only, with your own last numbers beside each exercise. */
class RoutinesController extends Controller
{
    public function index(Request $request, RoutinesService $routines): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $connected = DB::table('hevy_connections')->where('user_id', $user->id)->exists();

        return Inertia::render('Routines', ['connected' => $connected, 'data' => $connected ? $routines->list($user) : null]);
    }

    public function show(Request $request, RoutinesService $routines, int $routine): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $data = $routines->show($user, $routine);
        abort_if($data === null, 404);

        return Inertia::render('Routine', ['routine' => $data]);
    }

    /** /lifting/progression: suggested weights and projections for every exercise in one folder of routines (default Rational Intensity). */
    public function progression(Request $request, ProgressionService $progression): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $folder = $request->query('folder');

        return Inertia::render('Progression', ['program' => $progression->program($user, is_string($folder) ? mb_substr($folder, 0, 64) : null)]);
    }
}
