<?php

namespace App\Services;

use App\Domain\Watch\ActivityDedupe;
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

    /**
     * One row per real session: when two devices recorded the same one, Polar wins over COROS (see ActivityDedupe). Losers are not shown as sessions,
     * but the kept one says which other device also recorded it.
     *
     * @return list<array<string,mixed>> newest first
     */
    public function activities(User $user, int $n = 10, int $days = 90): array
    {
        $rows = $this->kept($user, $days);

        return array_slice(array_map(fn ($r) => $r['row'], $rows), 0, $n);
    }

    /** @return list<array{row:array<string,mixed>,start:int}> the sessions that count, newest first */
    public function kept(User $user, int $days): array
    {
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $all = DB::table('watch_activities')->where('user_id', $user->id)->where('local_date', '>=', $from)->orderByDesc('start_local')->get();
        $res = ActivityDedupe::resolve($all->map(fn ($a) => ['id' => (int) $a->id, 'start' => strtotime($a->start_local . ' UTC'), 'minutes' => $a->minutes, 'source' => $a->source])->all());
        $also = [];
        foreach ($res['duplicates'] as $dropped => $keptId) {
            $also[$keptId][] = $all->firstWhere('id', $dropped)->source ?: 'another device';
        }
        $out = [];
        foreach ($all as $a) {
            if (in_array((int) $a->id, $res['keep'], true)) {
                $out[] = ['start' => strtotime($a->start_local . ' UTC'), 'row' => ['id' => (int) $a->id, 'date' => (string) $a->local_date, 'name' => $a->name, 'type' => $a->type, 'minutes' => $a->minutes, 'avgHr' => $a->avg_hr,
                    'maxHr' => $a->max_hr, 'load' => $a->load, 'source' => $a->source, 'alsoRecordedBy' => array_values(array_unique($also[(int) $a->id] ?? []))]];
            }
        }

        return $out;
    }
}
