<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Services\ReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The optional, skippable 10-second morning check-in. */
class ReadinessController extends Controller
{
    public function store(Request $request, ReadinessService $svc): JsonResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'sleep' => ['required', 'integer', 'between:1,5'], 'soreness' => ['required', 'integer', 'between:1,5'], 'energy' => ['required', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:280'],
            'local_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after:' . date('Y-m-d', strtotime($today . ' -14 days'))],
        ]);
        $date = $d['local_date'] ?? $today;
        $svc->save($user, $date, (int) $d['sleep'], (int) $d['soreness'], (int) $d['energy'], $d['notes'] ?? null);

        return response()->json(['saved' => $svc->forDate($user, $date), 'hook' => $svc->hook($user, $today)], 201);
    }

    public function destroy(Request $request, string $date): JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        DB::table('readiness_logs')->where('user_id', $request->user()->id)->where('local_date', $date)->delete();

        return response()->json(['deleted' => true]);
    }
}
