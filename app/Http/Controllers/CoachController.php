<?php

namespace App\Http\Controllers;

use App\Services\Coach\CoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /coach: chat with the grounded coach. Read-only: it can explain your data, never change it. */
class CoachController extends Controller
{
    public function show(Request $request, CoachService $coach): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $thread = DB::table('coach_threads')->where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')->first();
        $messages = $thread ? DB::table('coach_messages')->where('thread_id', $thread->id)->orderBy('id')->get()->map(fn ($m) => [
            'id' => (int) $m->id, 'role' => $m->role, 'content' => $m->content, 'grounded' => (bool) $m->grounded,
            'tools' => $m->tool_trace ? array_values(array_unique(array_column(json_decode($m->tool_trace, true) ?: [], 'tool'))) : [],
        ])->all() : [];

        return Inertia::render('Coach', ['enabled' => $coach->enabled(), 'overCap' => $coach->enabled() && $coach->overCap($user), 'threadId' => $thread ? (int) $thread->id : null, 'messages' => $messages,
            'maxChars' => (int) config('fuel.coach.max_chars'),
            'suggestions' => ['Why is my target what it is today?', 'Am I gaining too fast?', 'What should I eat before my next session?', 'How is my weekly check-in looking?']]);
    }

    public function ask(Request $request, CoachService $coach): JsonResponse
    {
        $user = $request->user();
        abort_unless($coach->enabled(), 404);
        $d = $request->validate(['text' => ['required', 'string', 'min:2', 'max:' . (int) config('fuel.coach.max_chars')], 'thread_id' => ['nullable', 'integer']]);
        if ($coach->overCap($user)) {
            return response()->json(['message' => 'The coach has reached this month\'s limit. Your numbers are on the Today, Targets and Progress screens.'], 429);
        }

        return response()->json($coach->ask($user, $d['thread_id'] ?? null, trim($d['text'])));
    }

    /** Delete the whole conversation (and with it the stored tool traces). */
    public function destroyThread(Request $request, int $thread): RedirectResponse
    {
        DB::table('coach_threads')->where('id', $thread)->where('user_id', $request->user()->id)->delete();

        return redirect()->route('coach');
    }
}
