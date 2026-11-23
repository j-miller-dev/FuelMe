<?php

namespace App\Console\Commands;

use App\Domain\Foods\LocalDay;
use App\Domain\Notify\ReminderPlanner;
use App\Models\User;
use App\Services\FuelService;
use App\Services\PlanService;
use App\Services\Push\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifySessionFuelCommand extends Command
{
    protected $signature = 'notify:session-fuel';

    protected $description = 'Send the pre-session meal reminder (to people who turned it on) when its time comes';

    public function handle(PushService $push, FuelService $fuel, PlanService $plans): int
    {
        if (! PushService::configured()) {
            return self::SUCCESS;
        }
        foreach (DB::table('push_subscriptions')->distinct()->pluck('user_id') as $id) {
            $user = User::with('profile')->find($id);
            if (! $user || ! $user->profile || ! $push->prefs($user)['session_fuel']) {
                continue;
            }
            $p = $user->profile;
            $local = now($p->tz);
            $today = LocalDay::of(now(), $p->tz, (int) $p->rollover_hour);
            $nowMin = $local->hour * 60 + $local->minute;
            $day = $plans->forDay($user, $today)['day'];
            $f = $fuel->forToday($user, $today, $nowMin, ['kcal' => (float) $day['kcal'], 'protein' => (float) $day['protein'], 'carbs' => (float) $day['carbs'], 'fat' => (float) $day['fat']]);
            if ($f === null || empty($f['timeline'])) {
                continue;
            }
            foreach (ReminderPlanner::due($f['timeline'], $nowMin) as $step) {
                $t = ReminderPlanner::text((string) $f['next']['label'], (string) $f['next']['start']);
                $n = $push->send($user, ReminderPlanner::key($today, (string) $f['next']['key'], $step['kind']), $t['title'], $t['body'], '/', 'session-fuel');
                $this->line("user {$id}: {$step['title']} sent to {$n} device(s)");
            }
        }

        return self::SUCCESS;
    }
}
