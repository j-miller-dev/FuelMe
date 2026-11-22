<?php

use App\Models\FoodLog;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function history(User $u): void
{
    DB::table('weight_logs')->where('user_id', $u->id)->delete();
    foreach ([['2026-10-01', 73.8], ['2026-10-03', 74.1], ['2026-10-05', 74.0]] as [$d, $kg]) {
        DB::table('weight_logs')->insert(['user_id' => $u->id, 'local_date' => $d, 'kg' => $kg, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([['2026-10-04', 'lunch', 'Chicken rice', 450, 720.5, 48, 80, 20, 5], ['2026-10-04', 'dinner', '=HYPERLINK("http://evil","x")', null, 600, 30, 60, 20, null]] as [$d, $slot, $n, $g, $k, $p, $c, $f, $fi]) {
        DB::table('food_logs')->insert(['user_id' => $u->id, 'local_date' => $d, 'logged_at' => "$d 12:00:00", 'meal_slot' => $slot, 'name' => $n, 'grams' => $g, 'kcal' => $k, 'protein' => $p, 'carbs' => $c, 'fat' => $f, 'fibre' => $fi,
            'source' => 'search', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('workout_logs')->insert(['user_id' => $u->id, 'local_date' => '2026-10-04', 'status' => 'done', 'type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60, 'met' => 7.5, 'net_kcal' => 481, 'start_time' => '06:30',
        'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
}

/** Post a backup's sections the way the page does: one request per section. */
function restore($test, User $u, array $backup): array
{
    $out = [];
    foreach (['weights' => 'weights', 'food_logs' => 'food_logs', 'workouts' => 'workouts'] as $section => $kind) {
        $out[$kind] = $test->actingAs($u)->postJson('/import/rows', ['kind' => $kind, 'rows' => $backup[$section]])->assertOk()->json();
    }

    return $out;
}

it('exports a backup with your history and none of your credentials', function () {
    $u = loggedInUser();
    history($u);

    $r = $this->actingAs($u)->get('/settings/data/backup.json')->assertOk();
    $b = json_decode($r->streamedContent(), true);

    expect($b['app'])->toBe('FUEL')->and($b['version'])->toBe(1)->and(count($b['weights']))->toBe(3)->and(count($b['food_logs']))->toBe(2)->and($b['workouts'][0]['type'])->toBe('bjj')
        ->and($b['profile']['sex'])->toBe('male')->and($b['target_history'][0])->toHaveKey('plan_json');
    $raw = $r->streamedContent();
    expect($raw)->not->toContain($u->email)->and($raw)->not->toContain('password')->and($raw)->not->toContain('remember_token');
});

it('round-trips: export from one account, restore into a fresh one, same history; importing again changes nothing', function () {
    $a = loggedInUser();
    history($a);
    $backup = json_decode($this->actingAs($a)->get('/settings/data/backup.json')->streamedContent(), true);

    $b = loggedInUser();
    DB::table('weight_logs')->where('user_id', $b->id)->delete();   // a fresh account has only its onboarding weigh-in
    $first = restore($this, $b, $backup);
    $second = restore($this, $b, $backup);

    expect($first['weights'])->toMatchArray(['added' => 3, 'skipped' => 0])->and($first['food_logs'])->toMatchArray(['added' => 2])->and($first['workouts'])->toMatchArray(['added' => 1]);
    expect($second['weights']['added'])->toBe(0)->and($second['food_logs']['added'])->toBe(0)->and($second['workouts']['added'])->toBe(0);   // a second import is a no-op

    $pick = fn (User $u) => [
        DB::table('weight_logs')->where('user_id', $u->id)->orderBy('local_date')->get(['local_date', 'kg'])->map(fn ($r) => [$r->local_date, (float) $r->kg])->all(),
        DB::table('food_logs')->where('user_id', $u->id)->orderBy('meal_slot')->get(['local_date', 'meal_slot', 'name', 'grams', 'kcal', 'protein', 'carbs', 'fat', 'fibre'])->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, (array) $r))->all(),
        DB::table('workout_logs')->where('user_id', $u->id)->get(['local_date', 'type', 'intensity', 'minutes', 'start_time'])->map(fn ($r) => (array) $r)->all(),
    ];
    expect($pick($b))->toEqual($pick($a));
    // restored history does not grow "planned" copies of the training template
    expect(DB::table('workout_logs')->where('user_id', $b->id)->where('status', 'planned')->count())->toBe(0);
});

it('never overwrites a weigh-in or mixes an imported day total into a day you logged yourself', function () {
    $u = loggedInUser();
    DB::table('weight_logs')->where('user_id', $u->id)->delete();
    DB::table('weight_logs')->insert(['user_id' => $u->id, 'local_date' => '2026-10-01', 'kg' => 80.0, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('food_logs')->insert(['user_id' => $u->id, 'local_date' => '2026-10-02', 'logged_at' => now(), 'meal_slot' => 'lunch', 'name' => 'Mine', 'kcal' => 500, 'protein' => 30, 'carbs' => 50, 'fat' => 10, 'source' => 'quick', 'created_at' => now(), 'updated_at' => now()]);

    $w = $this->actingAs($u)->postJson('/import/rows', ['kind' => 'weights', 'rows' => [['date' => '2026-10-01', 'kg' => 70], ['date' => '2026-10-02', 'kg' => 79.5]]])->assertOk()->json();
    $d = $this->actingAs($u)->postJson('/import/rows', ['kind' => 'daily_totals', 'rows' => [['date' => '2026-10-02', 'kcal' => 2800], ['date' => '2026-10-03', 'kcal' => 2900, 'protein' => 150, 'carbs' => 330, 'fat' => 90]]])->assertOk()->json();

    expect($w)->toMatchArray(['added' => 1, 'skipped' => 1])->and((float) DB::table('weight_logs')->where('user_id', $u->id)->where('local_date', '2026-10-01')->value('kg'))->toBe(80.0)
        ->and($d)->toMatchArray(['added' => 1, 'skipped' => 1, 'first_date' => '2026-10-02'])
        ->and(FoodLog::where('user_id', $u->id)->where('local_date', '2026-10-02')->count())->toBe(1)
        ->and(FoodLog::where('user_id', $u->id)->where('local_date', '2026-10-03')->first())->toMatchArray(['name' => 'Imported daily total', 'kcal' => 2900.0, 'source' => 'import']);
});

it('rejects rows with bad dates and impossible numbers, naming the row, and still imports the good ones', function () {
    $u = loggedInUser();
    DB::table('weight_logs')->where('user_id', $u->id)->delete();

    $r = $this->actingAs($u)->postJson('/import/rows', ['kind' => 'weights', 'rows' => [
        ['date' => '2026-10-01', 'kg' => 74.2], ['date' => '31/12/2026', 'kg' => 74], ['date' => '2026-10-02', 'kg' => 7.4], ['date' => '2026-10-03', 'kg' => 'abc'], ['date' => '2999-01-01', 'kg' => 74], ['date' => '1990-01-01', 'kg' => 74], ['date' => '2026-02-30', 'kg' => 74],
    ]])->assertOk()->json();

    expect($r['added'])->toBe(1)->and(count($r['invalid']))->toBe(6)->and($r['invalid'][0])->toMatchArray(['row' => 2])->and($r['invalid'][1]['reason'])->toContain('outside')->and($r['invalid'][2]['reason'])->toContain('not a number');
});

it('limits a request to 1000 rows and only accepts known kinds', function () {
    $u = loggedInUser();

    $this->actingAs($u)->postJson('/import/rows', ['kind' => 'passwords', 'rows' => [['date' => '2026-10-01']]])->assertStatus(422);
    $this->actingAs($u)->postJson('/import/rows', ['kind' => 'weights', 'rows' => array_fill(0, 1001, ['date' => '2026-10-01', 'kg' => 74])])->assertStatus(422);
    $this->actingAs($u)->postJson('/import/rows', ['kind' => 'weights', 'rows' => []])->assertStatus(422);
});

it('imports history into the trend: earlier weigh-ins and day totals make the chart start earlier', function () {
    $u = loggedInUser();
    $weights = collect(range(60, 20, 2))->map(fn ($n) => ['date' => date('Y-m-d', strtotime("-{$n} days")), 'kg' => 73.0 + (60 - $n) * 0.02])->all();
    $totals = collect(range(60, 1))->map(fn ($n) => ['date' => date('Y-m-d', strtotime("-{$n} days")), 'kcal' => 3100, 'protein' => 160, 'carbs' => 390, 'fat' => 85])->all();
    $before = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data'];

    $this->actingAs($u)->postJson('/import/rows', ['kind' => 'weights', 'rows' => $weights])->assertOk();
    $this->actingAs($u)->postJson('/import/rows', ['kind' => 'daily_totals', 'rows' => $totals])->assertOk();
    $after = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data'];

    expect(count($after['series']))->toBeGreaterThan(count($before['series']) + 50)->and($after['series'][0]['date'])->toBe($weights[0]['date'])->and($after['adherence']['loggedDays7'])->toBeGreaterThanOrEqual(6);
});

it('imports workouts only when they are known activities and done, and never creates planned rows', function () {
    $u = loggedInUser();

    $r = $this->actingAs($u)->postJson('/import/rows', ['kind' => 'workouts', 'rows' => [
        ['date' => '2026-10-04', 'type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60], ['date' => '2026-10-04', 'type' => 'yoga', 'intensity' => 'x', 'minutes' => 60],
        ['date' => '2026-10-05', 'type' => 'custom', 'met' => 6.5, 'name' => 'Climbing', 'minutes' => 90], ['date' => '2026-10-06', 'status' => 'skipped', 'type' => 'bjj', 'intensity' => 'standard', 'minutes' => 60],
    ]])->assertOk()->json();

    expect($r)->toMatchArray(['added' => 2, 'skipped' => 1])->and(count($r['invalid']))->toBe(1)->and(DB::table('workout_logs')->where('user_id', $u->id)->where('status', 'planned')->count())->toBe(0)
        ->and((float) DB::table('workout_logs')->where('user_id', $u->id)->where('type', 'custom')->value('net_kcal'))->toBe(round((6.5 - 1) * 74 * 1.5));
});

it('exports CSVs that open safely in a spreadsheet: formula-looking names are defused', function () {
    $u = loggedInUser();
    history($u);

    $csv = $this->actingAs($u)->get('/settings/data/food-logs.csv')->assertOk()->streamedContent();

    expect($csv)->toContain('date,logged_at,meal,name')->and($csv)->toContain('Chicken rice')->and($csv)->toContain("\"'=HYPERLINK")->and($csv)->not->toContain(',=HYPERLINK');
    expect(ExportService::safeCell('=1+1'))->toBe("'=1+1")->and(ExportService::safeCell('+cmd'))->toBe("'+cmd")->and(ExportService::safeCell('@x'))->toBe("'@x")->and(ExportService::safeCell('Chicken'))->toBe('Chicken')->and(ExportService::safeCell(12.5))->toBe(12.5);
    $this->actingAs($u)->get('/settings/data/weights.csv')->assertOk();
    $this->actingAs($u)->get('/settings/data/workouts.csv')->assertOk();
    $this->actingAs($u)->get('/settings/data/passwords.csv')->assertNotFound();
});

it('keeps exports and imports private and signed-in only', function () {
    $this->get('/settings/data/backup.json')->assertRedirect('/login');
    $this->postJson('/import/rows', ['kind' => 'weights', 'rows' => [['date' => '2026-10-01', 'kg' => 74]]])->assertUnauthorized();

    $a = loggedInUser();
    history($a);
    $b = loggedInUser();
    $theirs = json_decode($this->actingAs($b)->get('/settings/data/backup.json')->streamedContent(), true);
    expect(collect($theirs['food_logs'])->pluck('name')->all())->not->toContain('Chicken rice');
});

it('shows the data page with your counts', function () {
    $u = loggedInUser();
    history($u);

    $this->actingAs($u)->get('/settings/data')->assertOk()->assertInertia(fn ($p) => $p->component('settings/Data')->where('counts.weights', 3)->where('counts.foodLogs', 2)->where('max', 1000));
});

it('gives weekly insights on the progress page', function () {
    $u = loggedInUser();
    DB::table('weight_logs')->insert(['user_id' => $u->id, 'local_date' => date('Y-m-d', strtotime('-12 days')), 'kg' => 74, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    foreach (range(1, 7) as $n) {
        $d = date('Y-m-d', strtotime("-{$n} days"));
        foreach (['breakfast' => 8, 'lunch' => 55, 'dinner' => 60] as $slot => $p) {
            DB::table('food_logs')->insert(['user_id' => $u->id, 'local_date' => $d, 'logged_at' => now(), 'meal_slot' => $slot, 'name' => 'Seed', 'kcal' => 1000, 'protein' => $p, 'carbs' => 100, 'fat' => 30, 'fibre' => 8, 'source' => 'quick', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    $i = $this->actingAs($u)->get('/progress')->viewData('page')['props']['data']['insights'];

    expect($i['days'])->toBe(7)->and($i['protein']['avg'])->toBe(123.0)->and($i['slots']['breakfast'])->toBe(8.0)->and(implode(' ', $i['notes']))->toContain('Breakfast averages about 8 g');
});
