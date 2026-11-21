<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Coach\CoachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Runs tests/Eval/coach_golden.json against the REAL model for the first user. Costs tokens; needs ANTHROPIC_API_KEY. */
class CoachEval extends Command
{
    protected $signature = 'coach:eval {--show : print every answer} {--limit=0 : only the first N prompts}';

    protected $description = 'Evaluate the coach on the golden prompts (tool choice and number grounding) with a real key';

    public function handle(CoachService $coach): int
    {
        if (! $coach->enabled()) {
            $this->error('Set ANTHROPIC_API_KEY and FUEL_COACH_ENABLED=true first.');

            return self::FAILURE;
        }
        $user = User::query()->first();
        if (! $user || ! $user->profile) {
            $this->error('Needs a user who has finished onboarding.');

            return self::FAILURE;
        }
        $cases = json_decode((string) file_get_contents(base_path('tests/Eval/coach_golden.json')), true)['cases'];
        if ((int) $this->option('limit') > 0) {
            $cases = array_slice($cases, 0, (int) $this->option('limit'));
        }
        $toolHit = $grounded = $n = 0;
        foreach ($cases as $c) {
            $r = $coach->ask($user, null, $c['q'])['message'];
            $n++;
            $ok = array_diff($c['expect_tools'], $r['tools']) === [];
            $toolHit += $ok ? 1 : 0;
            $grounded += $r['grounded'] ? 1 : 0;
            $this->line(sprintf('%s %s  [%s]', $ok ? 'OK  ' : 'MISS', $c['q'], implode(', ', $r['tools'])));
            if ($this->option('show') || ! $ok || ! $r['grounded']) {
                $this->line('     ' . str_replace("\n", ' ', $r['content']));
            }
        }
        DB::table('coach_threads')->where('user_id', $user->id)->where('title', 'like', '%')->whereIn('title', array_map(fn ($c) => mb_substr($c['q'], 0, 60), $cases))->delete();
        $this->info(sprintf('Expected tools called: %d/%d. Grounded answers: %d/%d.', $toolHit, $n, $grounded, $n));

        return self::SUCCESS;
    }
}
