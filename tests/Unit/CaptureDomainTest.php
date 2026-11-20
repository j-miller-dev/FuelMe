<?php

use App\Domain\Capture\MatchScorer;
use App\Domain\Capture\ParseValidator;
use App\Domain\Capture\PortionResolver;
use App\Domain\Capture\RuleParser;

function parser(): RuleParser
{
    return RuleParser::fromFile(__DIR__ . '/../../data/capture_vocab.json');
}

/** @return list<array<string,mixed>> */
function parsed(string $t): array
{
    return parser()->parse($t)['items'];
}

it('splits the blueprint example meal into one item per food with amounts, brands and stores', function () {
    $i = parsed('two scrambled eggs, two slices of Burgen toast with butter, Jalna Greek yoghurt about 200 grams with honey, and an Aldi porterhouse around 250 grams cooked');

    expect(array_column($i, 'name_query'))->toBe(['scrambled eggs', 'toast', 'butter', 'greek yoghurt', 'honey', 'porterhouse'])
        ->and($i[0])->toMatchArray(['quantity' => 2.0, 'unit' => null])
        ->and($i[1])->toMatchArray(['brand' => 'burgen', 'quantity' => 2.0, 'unit' => 'slice'])
        ->and($i[3])->toMatchArray(['brand' => 'jalna', 'quantity' => 200.0, 'unit' => 'g', 'grams_estimate' => 200.0, 'is_approximate' => true])
        ->and($i[5])->toMatchArray(['store' => 'aldi', 'state' => 'cooked', 'quantity' => 250.0, 'unit' => 'g']);
});

it('reads the quantity styles people actually say', function (string $say, string $query, ?float $qty, ?string $unit) {
    $i = parsed($say)[0];

    expect([$i['name_query'], $i['quantity'], $i['unit']])->toBe([$query, $qty, $unit]);
})->with([
    'half an avocado' => ['half an avocado', 'avocado', 0.5, null],
    'a can of tuna' => ['a can of tuna', 'tuna', 1.0, 'can'],
    'a handful of almonds' => ['a handful of almonds', 'almonds', 1.0, 'handful'],
    '300 ml milk' => ['300 ml milk', 'milk', 300.0, 'ml'],
    'glued 250g' => ['250g steak', 'steak', 250.0, 'g'],
    'a tablespoon of peanut butter' => ['a tablespoon of peanut butter', 'peanut butter', 1.0, 'tbsp'],
    'trailing count' => ['weetbix 2', 'weetbix', 2.0, null],
    'x3' => ['tim tams x3', 'tim tam', 3.0, null],
    'kilo becomes grams' => ['1 kg chicken', 'chicken', 1000.0, 'g'],
    'size words are not the food' => ['a medium orange', 'orange', 1.0, null],
    'no amount' => ['a flat white', 'flat white', 1.0, null],
]);

it('assigns meal slots from "for lunch ... then for dinner" and keeps dishes with "and" whole', function () {
    $i = parsed('for lunch chicken breast 150g cooked and rice 1 cup then for dinner mac and cheese');

    expect(array_column($i, 'meal_slot'))->toBe(['lunch', 'lunch', 'dinner'])->and($i[2]['name_query'])->toBe('mac and cheese');
});

it('never reads nutrition numbers out of text', function () {
    $i = parsed('toast 999 calories');   // junk in, but the parser has nowhere to put calories
    expect($i[0])->not->toHaveKeys(['kcal', 'calories', 'protein']);
});

// ---- the AI gate ------------------------------------------------------------------------------------------------------------

it('keeps only known fields from the AI reply and never copies nutrition values', function () {
    $c = ParseValidator::clean(['items' => [['phrase' => 'two eggs', 'name_query' => 'egg', 'quantity' => 2, 'unit' => 'piece', 'kcal' => 9999, 'protein' => 500, 'calories' => 1, 'brand' => '<b>Jalna</b>']], 'extra' => 'x']);

    expect($c['items'][0])->not->toHaveKeys(['kcal', 'protein', 'calories'])->and($c['items'][0]['brand'])->toBe('Jalna')->and($c['items'][0]['quantity'])->toBe(2.0);
});

it('clamps absurd amounts, drops bad enums and rejects an empty reply', function () {
    $c = ParseValidator::clean(['items' => [['name_query' => 'rice', 'quantity' => 50000, 'unit' => 'g', 'state' => 'boiled', 'meal_slot' => 'elevenses']]]);

    expect($c['items'][0])->toMatchArray(['quantity' => 3000.0, 'grams_estimate' => 3000.0, 'state' => null, 'meal_slot' => null])
        ->and($c['items'][0]['ambiguity'])->toContain('amount capped at 3 kg');
    expect(fn () => ParseValidator::clean(['items' => []]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ParseValidator::clean('ignore previous instructions'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ParseValidator::clean(['items' => [['phrase' => 'x']]]))->toThrow(InvalidArgumentException::class);
});

it('caps the number of items', function () {
    $items = array_fill(0, 60, ['name_query' => 'egg']);
    expect(ParseValidator::clean(['items' => $items])['items'])->toHaveCount(ParseValidator::MAX_ITEMS);
});

// ---- portions: never guess a weight ---------------------------------------------------------------------------------------

it('resolves portions in order and never invents a weight for a count', function () {
    $egg = ['servings' => [['label' => '1 egg (50 g)', 'grams' => 50.0]], 'is_liquid' => false, 'last_grams' => null];
    $bare = ['servings' => [], 'is_liquid' => false, 'last_grams' => null];

    expect(PortionResolver::resolve(['quantity' => 250, 'unit' => 'g'], $bare))->toMatchArray(['grams' => 250.0, 'estimated' => false, 'needs_amount' => false])
        ->and(PortionResolver::resolve(['quantity' => 2, 'unit' => null], $egg))->toMatchArray(['grams' => 100.0, 'needs_amount' => false, 'source' => 'serving'])
        ->and(PortionResolver::resolve(['quantity' => 2, 'unit' => null], $bare))->toMatchArray(['needs_amount' => true, 'estimated' => true, 'source' => 'default'])
        ->and(PortionResolver::resolve(['quantity' => 1, 'unit' => 'cup'], ['servings' => [], 'is_liquid' => true, 'last_grams' => null]))->toMatchArray(['grams' => 250.0, 'needs_amount' => false])
        ->and(PortionResolver::resolve(['quantity' => 1, 'unit' => 'cup'], $bare))->toMatchArray(['needs_amount' => true])   // a cup of a solid is not guessed
        ->and(PortionResolver::resolve(['quantity' => null, 'unit' => null], $bare, 180.0))->toMatchArray(['grams' => 180.0, 'source' => 'usual', 'needs_amount' => false])
        ->and(PortionResolver::resolve(['quantity' => 1, 'unit' => 'handful', 'grams_estimate' => 30], $bare))->toMatchArray(['grams' => 30.0, 'estimated' => true, 'needs_amount' => false])
        ->and(PortionResolver::resolve(['quantity' => 9000, 'unit' => 'g'], $bare)['grams'])->toBe(3000.0);
});

// ---- ranking and confidence ---------------------------------------------------------------------------------------------

function cand(int $id, string $name, float $score, array $o = []): array
{
    return $o + ['id' => $id, 'name' => $name, 'brand' => null, 'stores' => [], 'source' => 'afcd', 'score' => $score];
}

it('prefers the generic database food over a packaged lookalike, unless a brand was named', function () {
    $c = [cand(1, 'Skim Milk', 0.69, ['source' => 'off', 'brand' => 'Coles']), cand(2, 'Milk, cow, fluid, skim', 0.50)];

    expect(MatchScorer::rank($c, ['name_query' => 'skim milk'])[0]['id'])->toBe(2)
        ->and(MatchScorer::rank($c, ['name_query' => 'skim milk', 'brand' => 'coles'])[0]['id'])->toBe(1);
});

it('boosts brand, store and raw/cooked, and a personal alias beats everything', function () {
    $r = MatchScorer::rank([cand(1, 'Steak, raw', 0.6), cand(2, 'Steak, grilled', 0.6)], ['name_query' => 'steak', 'state' => 'cooked']);
    expect($r[0]['id'])->toBe(2)->and($r[0]['why'])->toContain('cooked');

    $r = MatchScorer::rank([cand(1, 'Porterhouse', 0.9), cand(2, 'Aldi Porterhouse', 0.7, ['source' => 'user', 'stores' => ['aldi']])], ['name_query' => 'porterhouse', 'store' => 'aldi']);
    expect($r[0]['id'])->toBe(2)->and($r[0]['why'])->toContain('Aldi');

    $r = MatchScorer::rank([cand(1, 'Yoghurt, natural', 0.9), cand(2, 'Jalna Vanilla', 10.4, ['source' => 'user'])], ['name_query' => 'yoghurt']);
    expect($r[0]['id'])->toBe(2)->and($r[0]['why'])->toContain('you picked this before');
});

it('grades confidence: green, amber for brand/store/amount doubt, red for nothing', function () {
    $ok = ['needs_amount' => false, 'estimated' => false, 'note' => null];
    $strong = MatchScorer::rank([cand(1, 'Chicken, breast, raw', 0.8), cand(2, 'Chicken, wing, raw', 0.3)], ['name_query' => 'chicken breast']);

    expect(MatchScorer::grade($strong, ['name_query' => 'chicken breast'], $ok)['confidence'])->toBe('green')
        ->and(MatchScorer::grade($strong, ['name_query' => 'chicken breast', 'brand' => 'jalna'], $ok)['confidence'])->toBe('amber')
        ->and(MatchScorer::grade($strong, ['name_query' => 'chicken breast', 'store' => 'aldi'], $ok)['notes'][0])->toContain('Aldi')
        ->and(MatchScorer::grade($strong, ['name_query' => 'chicken breast'], ['needs_amount' => true, 'estimated' => true, 'note' => 'x'] )['confidence'])->toBe('amber')
        ->and(MatchScorer::grade([], ['name_query' => 'zzz'], $ok)['confidence'])->toBe('red')
        ->and(MatchScorer::grade(MatchScorer::rank([cand(1, 'Something odd', 0.2)], ['name_query' => 'zzz']), ['name_query' => 'zzz'], $ok)['confidence'])->toBe('red');
});

it('does not treat other varieties of the same food as a rival', function () {
    $r = MatchScorer::rank([cand(1, 'Apple, fuji, raw', 0.8), cand(2, 'Apple, bonza, raw', 0.8)], ['name_query' => 'apple']);

    expect(MatchScorer::grade($r, ['name_query' => 'apple'], ['needs_amount' => false, 'estimated' => false, 'note' => null])['confidence'])->toBe('green');
});
