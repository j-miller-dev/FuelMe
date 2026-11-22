<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Domain\Training\Hydration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One-tap fluid log. Idempotent, so a retried tap never double counts. */
class WaterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'ml' => ['required', 'integer', 'min:50', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'local_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after:' . date('Y-m-d', strtotime($today . ' -30 days'))],
        ]);
        $date = $d['local_date'] ?? $today;

        DB::table('water_logs')->insertOrIgnore(['user_id' => $user->id, 'local_date' => $date, 'ml' => $d['ml'], 'idempotency_key' => $d['idempotency_key'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->state($user->id, $date, $user->profile->sex), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('water_logs')->where('user_id', $user->id)->where('id', $id)->first();
        abort_if(! $row, 404);
        DB::table('water_logs')->where('id', $id)->delete();

        return response()->json($this->state($user->id, (string) $row->local_date, $user->profile->sex));
    }

    private function state(int $userId, string $date, string $sex): array
    {
        $q = DB::table('water_logs')->where('user_id', $userId)->where('local_date', $date);

        return ['ml' => (int) (clone $q)->sum('ml'), 'targetMl' => Hydration::dailyFluidMl($sex), 'lastId' => (clone $q)->orderByDesc('id')->value('id')];
    }
}
