<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Domain\Supplements\CreatinePlan;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One-tap supplement log (creatine). Idempotent, so a retried tap never double counts. Not food: no calories, no effect on any target. */
class SupplementController extends Controller
{
    public const NAMES = ['creatine'];

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'name' => ['required', 'in:' . implode(',', self::NAMES)],
            'amount_g' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
            'idempotency_key' => ['required', 'uuid'],
            'local_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after:' . date('Y-m-d', strtotime($today . ' -30 days'))],
        ]);
        $date = $d['local_date'] ?? $today;
        // The dose is decided here from your plan (a 5 g serve while loading, otherwise your daily dose), never typed on the phone.
        $amount = $d['amount_g'] ?? self::state($user, $d['name'], $date)['serveG'];

        DB::table('supplement_logs')->insertOrIgnore(['user_id' => $user->id, 'name' => $d['name'], 'local_date' => $date, 'amount_g' => $amount,
            'idempotency_key' => $d['idempotency_key'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(self::state($user, $d['name'], $date), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('supplement_logs')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_if(! $row, 404);
        DB::table('supplement_logs')->where('id', $id)->delete();

        return response()->json(self::state($request->user(), $row->name, (string) $row->local_date));
    }

    /** Set (or reset) your creatine plan: loading first or not, how many loading days, and your daily dose afterwards. */
    public function plan(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = CreatinePlan::normalise($request->input('plan'));
        if ($plan === null) {
            return response()->json(['message' => 'Loading days must be 5 to 7 and the daily dose 3 to 5 g, with a start date.'], 422);
        }
        $user->profile->update(['creatine_plan' => $plan]);
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);

        return response()->json(self::state($user->fresh(), 'creatine', $today));
    }

    public function clearPlan(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->profile->update(['creatine_plan' => null]);
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);

        return response()->json(self::state($user->fresh(), 'creatine', $today));
    }

    /** @return array<string,mixed> */
    public static function state(User $user, string $name, string $date): array
    {
        $q = DB::table('supplement_logs')->where('user_id', $user->id)->where('name', $name);
        $day = (clone $q)->where('local_date', $date);
        $from = date('Y-m-d', strtotime($date . ' -6 days'));

        // No plan yet: plain daily use from your first logged dose (or today), at 5 g.
        $saved = $user->profile?->creatine_plan ?: null;
        $first = (clone $q)->min('local_date');
        $plan = $saved ?: CreatinePlan::defaults($first ? (string) $first : $date);
        $grams = (clone $q)->where('local_date', '>=', date('Y-m-d', strtotime(max($plan['start'], date('Y-m-d', strtotime($date . ' -60 days'))))))
            ->where('local_date', '<=', $date)->selectRaw('local_date, sum(amount_g) as g')->groupBy('local_date')->pluck('g', 'local_date')->map(fn ($g) => (float) $g)->all();
        $status = CreatinePlan::status($plan, $grams, app(TrainingService::class)->kg($user), $date);

        return [
            'takenG' => round((float) (clone $day)->sum('amount_g'), 2),
            'lastId' => (clone $day)->orderByDesc('id')->value('id'),
            // days with a dose in the 7 days ending on this date: a consistency line, deliberately not a streak
            'daysOfLast7' => (int) (clone $q)->whereBetween('local_date', [$from, $date])->distinct()->count('local_date'),
            'daysOfLast28' => (int) (clone $q)->whereBetween('local_date', [date('Y-m-d', strtotime($date . ' -27 days')), $date])->distinct()->count('local_date'),
            'defaultG' => $status['serveG'],
            'loadingG' => CreatinePlan::loadingTargetG(app(TrainingService::class)->kg($user)),
            'serveG' => $status['serveG'],
            'plan' => $plan,
            'hasPlan' => $saved !== null,
            'status' => $status,
        ];
    }
}
