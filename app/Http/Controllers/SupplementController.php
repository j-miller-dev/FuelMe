<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One-tap supplement log (creatine). Idempotent, so a retried tap never double counts. Not food: no calories, no effect on any target. */
class SupplementController extends Controller
{
    public const NAMES = ['creatine'];
    public const DEFAULT_G = ['creatine' => 5.0];

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

        DB::table('supplement_logs')->insertOrIgnore(['user_id' => $user->id, 'name' => $d['name'], 'local_date' => $date, 'amount_g' => $d['amount_g'] ?? self::DEFAULT_G[$d['name']],
            'idempotency_key' => $d['idempotency_key'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(self::state($user->id, $d['name'], $date), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('supplement_logs')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_if(! $row, 404);
        DB::table('supplement_logs')->where('id', $id)->delete();

        return response()->json(self::state($request->user()->id, $row->name, (string) $row->local_date));
    }

    /** @return array{takenG:float,lastId:?int,daysOfLast7:int,defaultG:float} */
    public static function state(int $userId, string $name, string $date): array
    {
        $q = DB::table('supplement_logs')->where('user_id', $userId)->where('name', $name);
        $day = (clone $q)->where('local_date', $date);
        $from = date('Y-m-d', strtotime($date . ' -6 days'));

        return [
            'takenG' => round((float) (clone $day)->sum('amount_g'), 2),
            'lastId' => (clone $day)->orderByDesc('id')->value('id'),
            // days with a dose in the 7 days ending on this date: a consistency line, deliberately not a streak
            'daysOfLast7' => (int) (clone $q)->whereBetween('local_date', [$from, $date])->distinct()->count('local_date'),
            'defaultG' => self::DEFAULT_G[$name],
        ];
    }
}
