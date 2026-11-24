<?php

namespace App\Http\Controllers;

use App\Jobs\SyncIntervals;
use App\Services\Intervals\IntervalsClient;
use App\Services\Intervals\IntervalsException;
use App\Services\WatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /watch: connect Intervals.icu (read-only) to see what your watch recorded. The API key is stored encrypted and never sent back to the browser. */
class WatchController extends Controller
{
    public function show(Request $request, WatchService $watch): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }
        $conn = DB::table('intervals_connections')->where('user_id', $user->id)->first();

        return Inertia::render('Watch', [
            'connection' => $conn ? ['last_synced_at' => $conn->last_synced_at ? Carbon::parse($conn->last_synced_at, 'UTC')->toIso8601String() : null, 'last_error' => $conn->last_error] : null,
            'days' => $watch->days($user),
            'months' => $watch->months($user),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $d = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:200']]);
        $key = trim($d['api_key']);
        try {
            (new IntervalsClient($key))->athlete();
        } catch (IntervalsException $e) {
            return back()->withErrors(['api_key' => $e->getMessage()]);
        }
        DB::table('intervals_connections')->updateOrInsert(['user_id' => $request->user()->id], ['api_key' => Crypt::encryptString($key), 'athlete_id' => '0', 'last_synced_at' => null, 'last_error' => null, 'updated_at' => now(), 'created_at' => now()]);
        SyncIntervals::dispatch($request->user()->id);

        return back();
    }

    public function sync(Request $request): RedirectResponse
    {
        abort_unless(DB::table('intervals_connections')->where('user_id', $request->user()->id)->exists(), 404);
        SyncIntervals::dispatch($request->user()->id);

        return back();
    }

    /** Forget when we last synced so the next run re-reads the whole first-run window (a year). Rows are matched by id, so nothing is duplicated. */
    public function resync(Request $request): RedirectResponse
    {
        abort_unless(DB::table('intervals_connections')->where('user_id', $request->user()->id)->exists(), 404);
        DB::table('intervals_connections')->where('user_id', $request->user()->id)->update(['last_synced_at' => null, 'updated_at' => now()]);
        SyncIntervals::dispatch($request->user()->id);

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $request->user();
        DB::table('intervals_connections')->where('user_id', $user->id)->delete();
        if ($request->boolean('delete_data')) {
            DB::table('watch_days')->where('user_id', $user->id)->delete();
            DB::table('watch_activities')->where('user_id', $user->id)->delete();
        }

        return back();
    }
}
