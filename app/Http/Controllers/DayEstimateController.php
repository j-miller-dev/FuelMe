<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Busy day": record a whole day as roughly on target / under / over without logging every meal.
 * The server sets the kcal from that day's target (the client never sends a number), and the progress model treats
 * the day as a wide-error intake, never as an exact one.
 */
class DayEstimateController extends Controller
{
    private const FACTORS = ['on_target' => 1.0, 'under' => 0.8, 'over' => 1.2];

    public function store(Request $request, PlanService $plans): JsonResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'kind' => ['required', 'in:on_target,under,over'],
            'local_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after:' . date('Y-m-d', strtotime($today . ' -400 days'))],
        ]);
        $date = $d['local_date'] ?? $today;
        $kcal = round((float) $plans->forDay($user, $date)['day']['kcal'] * self::FACTORS[$d['kind']], 2);

        DB::table('day_estimates')->updateOrInsert(
            ['user_id' => $user->id, 'local_date' => $date],
            ['kind' => $d['kind'], 'kcal' => $kcal, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['saved' => ['date' => $date, 'kind' => $d['kind'], 'kcal' => (int) round($kcal)]], 201);
    }

    public function destroy(Request $request, string $date): JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        DB::table('day_estimates')->where('user_id', $request->user()->id)->where('local_date', $date)->delete();

        return response()->json(['deleted' => true]);
    }
}
