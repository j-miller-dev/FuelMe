<?php

use App\Domain\Progress\Insights;

function insDay(?float $kcal, float $protein = 150.0, float $fibre = 30.0, float $withFibre = null): array
{
    return ['date' => 'd', 'kcal' => $kcal, 'target' => 3000.0, 'protein' => $protein, 'proteinTarget' => 165.0, 'fibre' => $fibre, 'kcalWithFibre' => $withFibre ?? ($kcal ?? 0.0), 'kcalLogged' => $kcal ?? 0.0];
}

it('says protein is on track, or points at the light meals when it is not', function () {
    $ok = Insights::compute(array_fill(0, 7, insDay(3000.0, 160.0)), ['breakfast' => ['days' => 7, 'protein' => 7 * 30.0], 'lunch' => ['days' => 7, 'protein' => 7 * 55.0], 'dinner' => ['days' => 7, 'protein' => 7 * 60.0]], 30.0, 12, 8);
    expect($ok['protein'])->toMatchArray(['avg' => 160.0, 'target' => 165.0])->and($ok['notes'][0])->toContain('on track')->and($ok['slots']['breakfast'])->toBe(30.0);

    $low = Insights::compute(array_fill(0, 7, insDay(2500.0, 110.0)), ['breakfast' => ['days' => 7, 'protein' => 7 * 8.0], 'lunch' => ['days' => 7, 'protein' => 7 * 50.0], 'dinner' => ['days' => 7, 'protein' => 7 * 52.0]], 30.0, 12, 8);
    expect($low['notes'][0])->toContain('67%')->and(implode(' ', $low['notes']))->toContain('Breakfast averages about 8 g of protein');
});

it('only talks about fibre when most of the food has fibre data, and compares with the guide', function () {
    $days = array_fill(0, 7, insDay(3000.0, 160.0, 20.0));   // 20 g against a 42 g guide
    expect(Insights::compute($days, [], 30.0, 7, 7)['fibre'])->toMatchArray(['avg' => 20.0, 'guide' => 42.0]);
    expect(implode(' ', Insights::compute($days, [], 30.0, 7, 7)['notes']))->toContain('Fibre averaged 20 g');

    $unknown = array_fill(0, 7, insDay(3000.0, 160.0, 20.0, 600.0));   // only 20% of calories have fibre data
    expect(Insights::compute($unknown, [], 30.0, 7, 7)['fibre'])->toBeNull();
});

it('is kind about consistency and does not judge a thin week', function () {
    $few = Insights::compute([insDay(null), insDay(null), insDay(3000.0), insDay(null), insDay(3000.0), insDay(null), insDay(null)], [], 30.0, 4, 3);

    expect($few['days'])->toBe(2)->and($few['protein'])->toBeNull()->and(implode(' ', $few['notes']))->toContain('a few more days')->and(implode(' ', $few['notes']))->toContain('not a failure');
    $good = Insights::compute(array_fill(0, 7, insDay(3000.0, 165.0)), [], 30.0, 14, 8);
    expect(implode(' ', $good['notes']))->toContain('solid week');
});
