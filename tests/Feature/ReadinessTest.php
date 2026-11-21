<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function checkin($t, $u, array $o = [], string $date = null)
{
    return $t->actingAs($u)->postJson('/readiness', array_merge(['sleep' => 4, 'soreness' => 2, 'energy' => 4], $o, $date ? ['local_date' => $date] : []));
}

it('saves one check-in per day, replaces it, and shows it on Today', function () {
    $u = loggedInUser();
    checkin($this, $u)->assertCreated();
    checkin($this, $u, ['energy' => 2, 'notes' => 'late night'])->assertCreated();
    expect(DB::table('readiness_logs')->where('user_id', $u->id)->count())->toBe(1);
    $r = $this->actingAs($u)->get('/')->viewData('page')['props']['readiness'];
    expect($r['today'])->toMatchArray(['sleep' => 4, 'energy' => 2, 'notes' => 'late night']);
});

it('rejects scores outside 1 to 5 and future dates', function () {
    $u = loggedInUser();
    checkin($this, $u, ['sleep' => 6])->assertStatus(422);
    checkin($this, $u, ['soreness' => 0])->assertStatus(422);
    checkin($this, $u, [], date('Y-m-d', strtotime('+2 days')))->assertStatus(422);
});

it('can remove a check-in (it is optional)', function () {
    $u = loggedInUser();
    checkin($this, $u)->assertCreated();
    $date = DB::table('readiness_logs')->value('local_date');
    $this->actingAs($u)->deleteJson("/readiness/{$date}")->assertOk();
    expect(DB::table('readiness_logs')->count())->toBe(0);
});

it('shows no insights until there are 8 days, then only comparisons with enough pairs', function () {
    $u = loggedInUser();
    $tz = $u->profile->tz;
    $this->travelTo(Carbon::parse('2026-11-23 07:00', $tz));
    for ($i = 1; $i <= 7; $i++) {
        checkin($this, $u, ['sleep' => ($i % 5) + 1, 'energy' => ($i % 5) + 1], date('Y-m-d', strtotime("2026-11-23 -{$i} days")));
    }
    expect($this->actingAs($u)->get('/review')->viewData('page')['props']['insights'])->toBe([]);

    checkin($this, $u, ['sleep' => 3, 'energy' => 3], '2026-11-15');
    $ins = $this->actingAs($u)->get('/review')->viewData('page')['props']['insights'];
    $keys = array_column($ins, 'key');
    // soreness never varied, so there is nothing to compare and no sleep/soreness insight is made up
    expect($keys)->toContain('sleep_energy')->not->toContain('sleep_soreness')->not->toContain('carbs_energy');
    $se = collect($ins)->firstWhere('key', 'sleep_energy');
    expect($se['n'])->toBe(8)->and($se['caveats'][0])->toContain('not proof');
});

it('offers options after three poor days before a hard session, and not otherwise', function () {
    $u = loggedInUser();
    $this->travelTo(Carbon::parse('2026-11-23 07:00', $u->profile->tz));   // Monday: BJJ then HIT lifting later
    foreach ([0, 1, 2] as $back) {
        checkin($this, $u, ['sleep' => 2, 'soreness' => 4], date('Y-m-d', strtotime("2026-11-23 -{$back} days")))->assertCreated();
    }
    $hook = $this->actingAs($u)->get('/')->viewData('page')['props']['readiness']['hook'];
    expect($hook)->not->toBeNull()->and($hook['options'])->toHaveCount(3);

    checkin($this, $u, ['sleep' => 5, 'soreness' => 1], '2026-11-23');
    expect($this->actingAs($u)->get('/')->viewData('page')['props']['readiness']['hook'])->toBeNull();
});
