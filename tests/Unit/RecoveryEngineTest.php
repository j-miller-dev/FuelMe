<?php

use App\Domain\Recovery\Baseline;
use App\Domain\Recovery\RecoverySignal;
use App\Domain\Recovery\SleepStats;
use App\Domain\Training\Deload;
use App\Domain\Training\LoadTrend;
use App\Domain\Watch\ActivityDedupe;

const REC_TODAY = '2026-11-23';
function recDay(int $back): string { return date('Y-m-d', strtotime(REC_TODAY . " -{$back} days")); }
/** baseline days 7..66 alternate $a/$b, recent days 0..6 all $recent */
function recSeries(float $a, float $b, float $recent, int $recentDays = 7): array
{
    $s = [];
    for ($i = 0; $i < $recentDays; $i++) { $s[recDay($i)] = $recent; }
    for ($i = 7; $i < 67; $i++) { $s[recDay($i)] = $i % 2 ? $a : $b; }

    return $s;
}

it('flags resting HR only when the 7-day average leaves your own usual range', function () {
    // baseline 50/54 alternating: mean 52, sd ~2.02, usual range 52 +/- 1.01
    $up = Baseline::compare(recSeries(50, 54, 58), REC_TODAY, false, 1.5);
    expect($up['status'])->toBe('above')->and($up['baseline'])->toBe(52.0)->and($up['recent'])->toBe(58.0)->and($up['pct'])->toBe(11.5);
    expect(Baseline::compare(recSeries(50, 54, 52.5), REC_TODAY, false, 1.5)['status'])->toBe('within');
});

it('works HRV out on a log scale and says "below" for a clear drop', function () {
    $r = Baseline::compare(recSeries(60, 70, 45), REC_TODAY, true, 0.03);
    expect($r['status'])->toBe('below')->and($r['pct'])->toBeLessThan(-20)->and($r['baseline'])->toBeBetween(64.0, 65.0);
});

it('does not guess without enough readings', function () {
    $few = recSeries(50, 54, 52, 3);
    expect(Baseline::compare($few, REC_TODAY, false, 1.5))->toMatchArray(['status' => 'unknown']);
    $short = [];
    for ($i = 0; $i < 7; $i++) { $short[recDay($i)] = 50; }
    for ($i = 7; $i < 12; $i++) { $short[recDay($i)] = 50; }
    expect(Baseline::compare($short, REC_TODAY, false, 1.5)['status'])->toBe('unknown');
});

it('measures sleep against your target and adds up the shortfall over the last 7 nights', function () {
    $nights = [recDay(0) => 6 * 3600, recDay(1) => 7 * 3600, recDay(2) => 8 * 3600, recDay(3) => 6 * 3600, recDay(4) => 7 * 3600];   // target 480
    $s = SleepStats::compute($nights, REC_TODAY, 480);
    expect($s['lastMin'])->toBe(360)->and($s['performancePct'])->toBe(75)->and($s['short'])->toBeTrue()
        ->and($s['debt7Min'])->toBe(120 + 60 + 0 + 120 + 60)->and($s['nights7'])->toBe(5)->and($s['debtFlag'])->toBeTrue();
    $ok = SleepStats::compute([recDay(0) => 9 * 3600], REC_TODAY, 480);
    expect($ok['performancePct'])->toBe(100)->and($ok['short'])->toBeFalse()->and($ok['debtFlag'])->toBeFalse();   // one night is too few to call a debt
});

it('turns flags into green, amber or red and always says why', function () {
    $within = ['status' => 'within'];
    $sleepOk = SleepStats::compute([recDay(0) => 8 * 3600], REC_TODAY, 480);
    $green = RecoverySignal::evaluate($within, $within, $sleepOk, ['sleep' => 4, 'soreness' => 2, 'energy' => 4], 1.0);
    expect($green['level'])->toBe('green')->and($green['flags'])->toBe([]);

    $hrvLow = ['status' => 'below', 'pct' => -18.2];
    $amber = RecoverySignal::evaluate($hrvLow, $within, $sleepOk, null, null);
    expect($amber['level'])->toBe('amber')->and($amber['flags'][0])->toContain('18%');

    $rhrUp = ['status' => 'above', 'recent' => 58.0, 'baseline' => 52.0];
    $red = RecoverySignal::evaluate($hrvLow, $rhrUp, $sleepOk, null, null);
    expect($red['level'])->toBe('red')->and($red['flagCount'])->toBe(2)->and($red['suggestion'])->toContain('Your call');
});

it('says it does not know when there is nothing to go on, and ignores load in a deload week', function () {
    $u = ['status' => 'unknown'];
    $none = SleepStats::compute([], REC_TODAY, 480);
    expect(RecoverySignal::evaluate($u, $u, $none, null, 2.0)['level'])->toBe('unknown');

    $within = ['status' => 'within'];
    $sleepOk = SleepStats::compute([recDay(0) => 8 * 3600], REC_TODAY, 480);
    expect(RecoverySignal::evaluate($within, $within, $sleepOk, null, 1.9, true)['level'])->toBe('green')
        ->and(RecoverySignal::evaluate($within, $within, $sleepOk, null, 1.9, false)['level'])->toBe('amber');
});

it('keeps Polar over COROS for the same session, and keeps different sessions apart', function () {
    $t = strtotime('2026-11-23 06:00:00');
    $r = ActivityDedupe::resolve([
        ['id' => 1, 'start' => $t + 120, 'minutes' => 45, 'source' => 'COROS'],
        ['id' => 2, 'start' => $t, 'minutes' => 44, 'source' => 'POLAR'],
        ['id' => 3, 'start' => $t + 8 * 3600, 'minutes' => 60, 'source' => 'COROS'],     // an evening session
        ['id' => 4, 'start' => $t + 600, 'minutes' => 30, 'source' => 'COROS'],           // inside the Polar one
    ]);
    expect($r['keep'])->toContain(2)->and($r['keep'])->toContain(3)->and($r['keep'])->not->toContain(1)->and($r['keep'])->not->toContain(4)
        ->and($r['duplicates'])->toBe([1 => 2, 4 => 2]);
    expect(ActivityDedupe::rank('POLAR_FLOW'))->toBe(1)->and(ActivityDedupe::rank('COROS'))->toBe(2)->and(ActivityDedupe::rank(null))->toBe(3);
});

it('compares this week with your usual and skips deload weeks in "usual"', function () {
    $daily = [];
    for ($i = 7; $i < 35; $i++) { $daily[recDay($i)] = 100; }      // usual week = 700
    for ($i = 0; $i < 7; $i++) { $daily[recDay($i)] = 150; }       // this week = 1050
    $r = LoadTrend::ratio($daily, REC_TODAY);
    expect($r)->toBe(['week' => 1050.0, 'usual' => 700.0, 'ratio' => 1.5]);

    $skip = [];
    for ($i = 7; $i < 14; $i++) { $skip[recDay($i)] = true; $daily[recDay($i)] = 20; }   // a deload week is in the past
    expect(LoadTrend::ratio($daily, REC_TODAY, $skip)['usual'])->toBe(700.0);                 // 21 normal days at 100/day, deload left out
    expect(LoadTrend::ratio([recDay(0) => 100], REC_TODAY)['ratio'])->toBeNull();               // too little history
});

it('suggests a deload only when several signs line up, and not right after one', function () {
    expect(Deload::suggest(2, 3, null, null, false)['suggest'])->toBeTrue();
    expect(Deload::suggest(1, 0, 1.0, 6, false)['suggest'])->toBeFalse();                // one weak sign, even after a long block
    $long = Deload::suggest(1, 0, null, 6, false);
    expect($long['suggest'])->toBeFalse();
    $long2 = Deload::suggest(2, 0, null, 6, false);
    expect($long2['suggest'])->toBeTrue()->and(implode(' ', $long2['reasons']))->toContain('6 weeks');
    expect(Deload::suggest(3, 5, 2.0, 1, false)['suggest'])->toBeFalse();                // you only just deloaded
    expect(Deload::suggest(3, 5, 2.0, null, true)['suggest'])->toBeFalse();              // already in one
    expect(Deload::mondayOf('2026-11-26'))->toBe('2026-11-23')->and(Deload::weeksSince(['2026-10-26'], '2026-11-26'))->toBe(4)->and(Deload::weeksSince([], '2026-11-26'))->toBeNull();
});
