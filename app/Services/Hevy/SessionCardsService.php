<?php

namespace App\Services\Hevy;

use App\Domain\Training\EffortRamp;
use App\Domain\Training\SessionCard;
use App\Domain\Training\Strength;
use App\Models\User;

/** Lifting session cards from your synced Hevy sets. Read-only; nothing here changes a plan or a target. */
class SessionCardsService
{
    public function __construct(private StrengthService $strength) {}

    /** @return list<array<string,mixed>> newest first; PR and "vs last time" are judged against ALL history before each session, so history is read in full */
    public function cards(User $user, int $showDays = 400): array
    {
        $all = $this->strength->sessions($user, 3650);
        $cards = SessionCard::buildAll($all);
        $ramp = $user->profile->effort_ramp ?: null;
        $from = date('Y-m-d', strtotime(now($user->profile->tz)->toDateString() . " -{$showDays} days"));
        $out = [];
        foreach ($cards as $i => $c) {
            if ($c['date'] < $from) {
                continue;
            }
            $wk = $all[$i]['deload'] ? null : EffortRamp::weekFor($ramp, $c['date']);
            $c['vs_plan'] = $wk ? EffortRamp::compare(Strength::hardestRirs($all[$i]['exercises']), $wk['min'], $wk['max']) + ['planned' => $wk['label']] : null;
            $out[] = $c;
        }

        return array_reverse($out);
    }

    /** @return list<array<string,mixed>> */
    public function months(User $user): array
    {
        return SessionCard::months($this->cards($user));
    }

    /** The newest session card if it is from today (local date), for the Today screen. @return array<string,mixed>|null */
    public function today(User $user): ?array
    {
        if (! $this->strength->connected($user)) {
            return null;
        }
        $today = now($user->profile->tz)->toDateString();
        $c = $this->cards($user, 2)[0] ?? null;

        return $c && $c['date'] === $today ? $c : null;
    }
}
