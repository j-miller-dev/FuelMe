<?php

namespace App\Services;

use App\Domain\Training\Deload;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The weeks you labelled as deloads. A label only changes how the app READS your data (stall checks, load baselines); it never changes your food targets. */
class DeloadService
{
    /** @return list<string> Mondays, oldest first */
    public function mondays(User $user): array
    {
        return DB::table('deload_weeks')->where('user_id', $user->id)->orderBy('week_start')->pluck('week_start')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    /** @return array<string,bool> every date inside a labelled week, from $from to $to */
    public function dates(User $user, string $from, string $to): array
    {
        $out = [];
        foreach ($this->mondays($user) as $m) {
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime($m . " +{$i} days"));
                if ($d >= $from && $d <= $to) {
                    $out[$d] = true;
                }
            }
        }

        return $out;
    }

    public function isDeload(User $user, string $date): bool
    {
        return in_array(Deload::mondayOf($date), $this->mondays($user), true);
    }

    /** Label (or un-label) the Monday-to-Sunday week that contains $date. Returns that week's Monday. */
    public function set(User $user, string $date, bool $on): string
    {
        $monday = Deload::mondayOf($date);
        if ($on) {
            DB::table('deload_weeks')->updateOrInsert(['user_id' => $user->id, 'week_start' => $monday], ['updated_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('deload_weeks')->where('user_id', $user->id)->where('week_start', $monday)->delete();
        }

        return $monday;
    }
}
