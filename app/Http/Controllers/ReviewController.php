<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Services\EventService;
use App\Services\WeeklyReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /review: the weekly written review and event mode. */
class ReviewController extends Controller
{
    public function show(Request $request, WeeklyReviewService $reviews, EventService $events): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Review', ['reviews' => $reviews->recent($user), 'event' => $events->current($user),
            'today' => LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour)]);
    }

    /** Write (or rewrite) this week's review now, without waiting for Sunday evening. */
    public function generate(Request $request, WeeklyReviewService $reviews): RedirectResponse
    {
        $reviews->generate($request->user(), null, true);

        return redirect()->route('review');
    }

    public function saveEvent(Request $request): RedirectResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after:' . $today, 'before:' . date('Y-m-d', strtotime($today . ' +3 years'))],
            'target_kg' => ['required', 'numeric', 'min:30', 'max:300'],
            'phases' => ['nullable', 'array', 'max:3'],
            'phases.*.until' => ['required', 'date_format:Y-m-d', 'after:' . $today],
            'phases.*.target_kg' => ['required', 'numeric', 'min:30', 'max:300'],
        ]);
        $phases = collect($d['phases'] ?? [])->sortBy('until')->map(fn ($p) => ['until' => $p['until'], 'target_kg' => round((float) $p['target_kg'], 1)])->values()->all();

        DB::table('events')->updateOrInsert(['user_id' => $user->id], [
            'name' => $d['name'] ?? null, 'event_date' => $d['event_date'], 'target_kg' => round((float) $d['target_kg'], 1),
            'phases' => $phases ? json_encode($phases) : null, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return redirect()->route('review');
    }

    public function deleteEvent(Request $request): RedirectResponse
    {
        DB::table('events')->where('user_id', $request->user()->id)->delete();

        return redirect()->route('review');
    }
}
