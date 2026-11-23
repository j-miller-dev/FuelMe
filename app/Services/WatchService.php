<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Reads what the watch recorded (via Intervals.icu) for display. Never feeds a calorie target. */
class WatchService
{
    /** @return array{sleepMin:?int,restingHr:?int,hrv:?float,steps:?int}|null */
    public function day(User $user, string $date): ?array
    {
        $r = DB::table('watch_days')->where('user_id', $user->id)->where('local_date', $date)->first();

        return $r ? $this->shape($r) : null;
    }

    /** @return array{sleepMin:?int,restingHr:?int,hrv:?float,steps:?int} */
    private function shape(object $r): array
    {
        return ['sleepMin' => $r->sleep_secs !== null ? (int) round($r->sleep_secs / 60) : null, 'restingHr' => $r->resting_hr !== null ? (int) $r->resting_hr : null,
            'hrv' => $r->hrv !== null ? (float) $r->hrv : null, 'steps' => $r->steps !== null ? (int) $r->steps : null];
    }

    /** @return list<array<string,mixed>> newest first */
    public function days(User $user, int $n = 14): array
    {
        return DB::table('watch_days')->where('user_id', $user->id)->orderByDesc('local_date')->limit($n)->get()->map(fn ($r) => ['date' => (string) $r->local_date] + $this->shape($r))->all();
    }

    /** @return list<array<string,mixed>> newest first */
    public function activities(User $user, int $n = 10): array
    {
        return DB::table('watch_activities')->where('user_id', $user->id)->orderByDesc('start_local')->limit($n)->get()
            ->map(fn ($a) => ['id' => (int) $a->id, 'date' => (string) $a->local_date, 'name' => $a->name, 'type' => $a->type, 'minutes' => $a->minutes, 'avgHr' => $a->avg_hr, 'maxHr' => $a->max_hr, 'load' => $a->load, 'source' => $a->source])->all();
    }
}
