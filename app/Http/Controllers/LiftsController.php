<?php

namespace App\Http\Controllers;

use App\Services\Hevy\LiftsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** /lifting/best (your records) and /lifting/exercise/{id} (one exercise: records, trend, your notes). Hevy data is read-only; notes are your own words. */
class LiftsController extends Controller
{
    public function board(Request $request, LiftsService $lifts): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $connected = DB::table('hevy_connections')->where('user_id', $user->id)->exists() || DB::table('hevy_workouts')->where('user_id', $user->id)->exists();

        return Inertia::render('BestLifts', ['connected' => $connected, 'board' => $connected ? $lifts->board($user) : null]);
    }

    public function show(Request $request, LiftsService $lifts, string $id): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $data = $lifts->exercise($user, $id);
        abort_if($data === null, 404);

        return Inertia::render('Exercise', ['exercise' => $data, 'kinds' => LiftsService::KINDS]);
    }

    public function storeNote(Request $request, LiftsService $lifts, string $id): RedirectResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(LiftsService::KINDS)], 'body' => ['required', 'string', 'max:600']]);
        $body = trim($d['body']);
        if ($body === '') {
            return back()->withErrors(['body' => 'Write something first.']);
        }
        abort_unless($lifts->exercise($request->user(), $id) !== null, 404);
        $lifts->addNote($request->user(), $id, $d['kind'], $body);

        return back();
    }

    public function updateNote(Request $request, int $note): RedirectResponse
    {
        $d = $request->validate(['kind' => ['sometimes', Rule::in(LiftsService::KINDS)], 'body' => ['sometimes', 'string', 'max:600']]);
        $row = DB::table('exercise_notes')->where('user_id', $request->user()->id)->where('id', $note);
        abort_unless($row->exists(), 404);
        $upd = array_filter(['kind' => $d['kind'] ?? null, 'body' => isset($d['body']) ? trim($d['body']) : null], fn ($v) => $v !== null);
        if (isset($upd['body']) && $upd['body'] === '') {
            return back()->withErrors(['body' => 'A note cannot be empty. Delete it instead.']);
        }
        if ($upd) {
            $row->update($upd + ['updated_at' => now()]);
        }

        return back();
    }

    public function destroyNote(Request $request, int $note): RedirectResponse
    {
        abort_unless(DB::table('exercise_notes')->where('user_id', $request->user()->id)->where('id', $note)->delete() > 0, 404);

        return back();
    }

    /** Your smallest weight jump for this exercise (a dumbbell rack, a pin stack). DELETE goes back to the default guessed from the name. */
    public function saveStep(Request $request, LiftsService $lifts, string $id): RedirectResponse
    {
        $d = $request->validate(['step_kg' => ['required', 'numeric', 'min:0.25', 'max:25']]);
        abort_unless($lifts->exercise($request->user(), $id) !== null, 404);
        DB::table('exercise_prefs')->updateOrInsert(['user_id' => $request->user()->id, 'template_id' => $id], ['step_kg' => round((float) $d['step_kg'] * 4) / 4, 'updated_at' => now(), 'created_at' => now()]);

        return back();
    }

    public function clearStep(Request $request, string $id): RedirectResponse
    {
        DB::table('exercise_prefs')->where('user_id', $request->user()->id)->where('template_id', $id)->delete();

        return back();
    }
}
