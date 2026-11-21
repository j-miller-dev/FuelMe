<?php

use App\Domain\Training\WorkoutTextParser as P;

it('parses the blueprint example into two sessions without turning rounds into minutes', function () {
    $r = P::parse('rolled five rounds, hard, about an hour with drilling, then 40 minutes heavy lifting');
    expect($r['items'])->toHaveCount(2)->and($r['unparsed'])->toBe([]);
    [$a, $b] = $r['items'];
    expect($a)->toMatchArray(['type' => 'bjj', 'intensity' => 'hard', 'minutes' => 60, 'approximate' => true, 'needs' => []])
        ->and($b)->toMatchArray(['type' => 'lifting', 'intensity' => 'heavy', 'minutes' => 40, 'approximate' => false, 'needs' => []]);
});

it('leaves minutes empty rather than guessing when only rounds are given', function () {
    $r = P::parse('rolled five rounds hard');
    expect($r['items'][0])->toMatchArray(['type' => 'bjj', 'intensity' => 'hard', 'minutes' => null, 'needs' => ['minutes']]);
});

it('understands several ways of saying a duration', function (string $text, int $min) {
    expect(P::parse("bjj {$text}")['items'][0]['minutes'])->toBe($min);
})->with([['an hour', 60], ['half an hour', 30], ['an hour and a half', 90], ['1.5 hours', 90], ['1 hour 20 minutes', 80], ['two hours', 120], ['forty five minutes', 45], ['90 mins', 90], ['an hour and 15 minutes', 75]]);

it('treats drilling alone as light technique work and rolling as standard', function () {
    expect(P::parse('drilling for an hour')['items'][0]['intensity'])->toBe('light')
        ->and(P::parse('rolled for an hour')['items'][0]['intensity'])->toBe('standard');
});

it('uses your lifting default unless you say otherwise', function () {
    expect(P::parse('lifting 40 minutes')['items'][0]['intensity'])->toBe('hit')
        ->and(P::parse('lifting 40 minutes', 'standard')['items'][0]['intensity'])->toBe('standard')
        ->and(P::parse('light lifting 40 minutes')['items'][0]['intensity'])->toBe('light');
});

it('makes unknown activities a custom entry that needs a name and a MET from you', function () {
    $i = P::parse('went for a walk for 45 minutes')['items'][0];
    expect($i['type'])->toBe('custom')->and($i['minutes'])->toBe(45)->and($i['needs'])->toContain('met')->and($i['name'])->toContain('walk');
});

it('splits "x and y" only when both sides are activities', function () {
    expect(P::parse('bjj 60 minutes and lifting 30 minutes')['items'])->toHaveCount(2)
        ->and(P::parse('bjj with drilling and rolling for an hour')['items'])->toHaveCount(1);
});

it('reads RPE, a start time and yesterday', function () {
    $i = P::parse('yesterday did hit lifting at 6:30am for 40 minutes rpe 9')['items'][0];
    expect($i)->toMatchArray(['type' => 'lifting', 'intensity' => 'hit', 'minutes' => 40, 'rpe' => 9.0, 'start' => '06:30', 'day' => 'yesterday']);
    expect(P::parse('bjj at 6pm for an hour')['items'][0]['start'])->toBe('18:00');
    expect(P::parse('bjj 3 rounds at 5 for an hour')['items'][0]['start'])->toBeNull();   // a bare "at 5" is not a time
});

it('returns what it cannot place as unparsed and handles empty text', function () {
    expect(P::parse('felt great today')['items'])->toBe([])->and(P::parse('felt great today')['unparsed'])->toBe(['felt great today'])
        ->and(P::parse('')['items'])->toBe([]);
});

it('maps HIIT and Norwegian 4x4', function () {
    expect(P::parse('tabata 20 minutes')['items'][0])->toMatchArray(['type' => 'hiit', 'intensity' => 'vigorous', 'minutes' => 20])
        ->and(P::parse('norwegian 4x4 on the bike 40 min')['items'][0]['type'])->toBe('norwegian_4x4');
});
