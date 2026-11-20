<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Capture\CaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scores meal capture against tests/Eval/golden.json on the REAL food database (BLUEPRINT 6.9). Each case is one phrase, the food names
 * that count as right (regexes), and optionally the expected grams. Prints top-1 / top-3 / quantity accuracy, how many items would need
 * a swap, and latency. Runs in basic mode unless a key is set and --ai is passed.
 */
class CaptureEval extends Command
{
    protected $signature = 'capture:eval {--file=tests/Eval/golden.json} {--ai : use the AI parser if ANTHROPIC_API_KEY is set} {--show-fails} {--min-top1= : exit non-zero below this top-1 percentage}';
    protected $description = 'Measure meal-capture accuracy on the golden set (needs the AFCD and Open Food Facts imports)';

    public function handle(CaptureService $capture): int
    {
        $cases = json_decode((string) file_get_contents(base_path($this->option('file'))), true, 512, JSON_THROW_ON_ERROR);
        if (! $this->option('ai')) {
            config(['fuel.capture.api_key' => null]);
        }
        if (DB::table('foods')->where('source', 'afcd')->count() < 500) {
            $this->error('Import AFCD first: php artisan foods:import-afcd');

            return self::FAILURE;
        }

        $user = User::create(['name' => 'eval', 'email' => 'eval-' . Str::random(8) . '@example.invalid', 'password' => Str::random(40)]);
        $n = $top1 = $top3 = $qtyN = $qtyOk = $swap = 0;
        $lat = [];
        $fails = [];
        try {
            foreach ($cases as $c) {
                $t = hrtime(true);
                $r = $capture->run($user, $c['say'], null);
                $lat[] = (hrtime(true) - $t) / 1e6;
                $n++;
                $item = $r['items'][0] ?? null;
                $names = $item ? array_map(fn ($x) => $x['name'] . ' ' . ($x['brand'] ?? ''), $item['candidates']) : [];
                $ok = fn (string $name) => (bool) array_filter((array) $c['match'], fn ($re) => preg_match('/' . $re . '/iu', $name));
                $chosen = $item && $item['chosen'] ? ($names[0] ?? '') : '';
                $hit1 = $chosen !== '' && $ok($chosen);
                $hit3 = (bool) array_filter(array_slice($names, 0, 3), $ok);
                $top1 += $hit1;
                $top3 += $hit3;
                $q = null;
                if (isset($c['g']) && $item && $item['chosen']) {
                    $qtyN++;
                    $q = abs($item['chosen']['grams'] - $c['g']) / $c['g'] <= 0.15;
                    $qtyOk += $q;
                }
                $swap += ($item === null || $item['confidence'] !== 'green') ? 1 : 0;
                if (! $hit1 || $q === false) {
                    $fails[] = sprintf('%-52s -> %-46s %s%s', mb_substr($c['say'], 0, 52), mb_substr($chosen ?: '(none)', 0, 46), $item['chosen']['grams'] ?? '-', $q === false ? "  (wanted {$c['g']} g)" : '');
                }
            }
        } finally {
            $user->delete();   // cascades its capture sessions
        }

        sort($lat);
        $p = fn (float $f) => $lat[(int) min(count($lat) - 1, floor($f * count($lat)))] ?? 0;
        $pct = fn ($a, $b) => $b ? round(100 * $a / $b, 1) : 0.0;
        $this->table(['metric', 'value', 'target'], [
            ['cases', $n, ''], ['top-1 match', $pct($top1, $n) . '%', '>= 80%'], ['top-3 recall', $pct($top3, $n) . '%', '>= 95%'],
            ['quantity within 15%', $pct($qtyOk, $qtyN) . "% of $qtyN", '>= 90%'], ['items needing a look (amber/red)', $pct($swap, $n) . '%', ''],
            ['latency p50 / p95 (ms, retrieval only in basic mode)', round($p(0.5)) . ' / ' . round($p(0.95)), 'retrieval <= 300'],
        ]);
        if ($this->option('show-fails')) {
            $this->line("\nMisses:");
            array_map(fn ($f) => $this->line('  ' . $f), $fails);
        }
        $min = $this->option('min-top1');

        return $min !== null && $pct($top1, $n) < (float) $min ? self::FAILURE : self::SUCCESS;
    }
}
