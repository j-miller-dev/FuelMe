<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WeeklyReviewService;
use Illuminate\Console\Command;

class WeeklyReviewCommand extends Command
{
    protected $signature = 'review:weekly {--force : rebuild even if this week already has one}';
    protected $description = 'Write the weekly review for each user once it is Sunday evening in their timezone';

    public function handle(WeeklyReviewService $svc): int
    {
        foreach (User::with('profile')->get() as $u) {
            if (! $u->profile) {
                continue;
            }
            $local = now($u->profile->tz);
            if (! $this->option('force') && ! ($local->dayOfWeek === 0 && $local->hour >= 18)) {
                continue;
            }
            $svc->generate($u, $local->format('Y-m-d'), (bool) $this->option('force'));
            $this->info("Review ready for user {$u->id}");
        }

        return self::SUCCESS;
    }
}
