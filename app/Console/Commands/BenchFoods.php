<?php

namespace App\Console\Commands;

use App\Domain\Foods\FoodSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchFoods extends Command
{
    protected $signature = 'foods:bench {--rows=200000 : Synthetic foods to add} {--runs=20 : Repetitions of each query} {--keep : Keep the synthetic rows}';
    protected $description = 'Measure food-search latency (p50/p95) on a realistically large table (BLUEPRINT 10: p95 < 150 ms at 200k rows)';

    private const QUERIES = ['chicken breast', 'capsicum', 'mince', 'weetbix 2', '2 eggs', '300ml milk', 'oats', 'greek yoghurt', 'tim tam',
        'banana', 'protein bar', 'cheese', 'chiken brest', 'up&go', '150g rice', 'sourdough bread'];

    public function handle(FoodSearch $search): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Benchmark needs PostgreSQL.');

            return self::FAILURE;
        }
        $rows = (int) $this->option('rows');
        DB::table('foods')->where('source_id', 'like', 'bench-%')->delete();

        $this->info("Adding $rows synthetic rows ...");
        DB::statement(<<<'SQL'
            INSERT INTO foods (source, source_id, barcode, name, brand, category, state, per100_kcal, per100_protein, per100_carbs, per100_fat, quality, use_count, created_at, updated_at)
            SELECT 'off', 'bench-' || g, '99' || lpad(g::text, 12, '0'),
                   (ARRAY['Chicken','Beef','Pork','Lamb','Oat','Rice','Wheat','Corn','Soy','Almond','Cheese','Greek','Natural','Vanilla','Choc','Protein','Banana','Apple','Berry','Mango','Tomato','Potato','Pumpkin','Carrot','Egg','Milk','Tim','Sourdough','Wholemeal','Multigrain'])[1 + (random()*29)::int]
                     || ' ' || (ARRAY['breast','mince','bar','bites','crisps','biscuits','yoghurt','drink','shake','bread','wraps','pasta','sauce','soup','salad','cereal','muesli','flakes','powder','snack'])[1 + (random()*19)::int]
                     || ' ' || (ARRAY['original','light','low fat','high protein','no added sugar','family pack','gluten free','organic','zero','twin pack'])[1 + (random()*9)::int],
                   (ARRAY['Woolworths','Coles','Aldi','Sanitarium','Arnotts','Bega','Dairy Farmers','Chobani','Uncle Tobys','Mars','Nestle','Kellogg''s','Tip Top','Maggi','SPC'])[1 + (random()*14)::int],
                   'Foods, Snacks, Packaged', 'as_sold', 50 + random()*400, random()*30, random()*70, random()*30, 60, 0, now(), now()
            FROM generate_series(1, ?) g
        SQL, [$rows]);
        DB::statement('ANALYZE foods');

        $times = [];
        foreach (range(1, (int) $this->option('runs')) as $_) {
            foreach (self::QUERIES as $q) {
                $t = hrtime(true);
                $search->search($q, null);
                $times[] = (hrtime(true) - $t) / 1e6;
            }
        }
        sort($times);
        $pct = fn (float $p) => $times[(int) min(count($times) - 1, floor($p * count($times)))];
        $total = DB::table('foods')->count();
        $this->table(['rows', 'queries', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms'], [[
            $total, count($times), round($pct(0.5), 1), round($pct(0.95), 1), round($pct(0.99), 1), round(end($times), 1),
        ]]);
        $ok = $pct(0.95) < 150;
        $this->line($ok ? '<info>PASS</info> p95 < 150 ms' : '<error>FAIL</error> p95 >= 150 ms');

        if (!$this->option('keep')) {
            DB::table('foods')->where('source_id', 'like', 'bench-%')->delete();
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
