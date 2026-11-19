<?php

use App\Domain\Foods\AfcdMapper;
use App\Domain\Foods\FoodSearch;
use App\Domain\Foods\OffMapper;
use App\Domain\Foods\ServingResolver;

// ---- AFCD mapping -------------------------------------------------------------------------------------------------

it('converts kJ to kcal and tolerates blanks', function () {
    expect(AfcdMapper::kjToKcal(1236))->toBe(295.41)   // 1236 / 4.184
        ->and(AfcdMapper::kjToKcal('418.4'))->toBe(100.0)
        ->and(AfcdMapper::kjToKcal(null))->toBeNull()
        ->and(AfcdMapper::kjToKcal(''))->toBeNull();
});

it('reads preparation state from the AFCD name', function (string $name, ?string $state) {
    expect(AfcdMapper::state($name))->toBe($state);
})->with([
    ['Chicken, breast, lean flesh, raw', 'raw'],
    ['Chicken, breast, lean flesh, fried, no added fat', 'cooked'],
    ['Rice, white, boiled or rice cooker, no added salt', 'cooked'],
    ['Rice, white, uncooked', 'dry'],
    ['Milk, cow, powder, skim', 'dry'],
    ['Banana, frozen', null],
]);

it('scores data quality by derivation', function () {
    expect(AfcdMapper::quality('Analysed'))->toBeGreaterThan(AfcdMapper::quality('Borrowed'))
        ->and(AfcdMapper::quality('Borrowed'))->toBeGreaterThan(AfcdMapper::quality('Imputed'));
});

it('normalises spreadsheet headers and nutrient keys', function () {
    expect(AfcdMapper::normaliseHeader("Protein \n(g)"))->toBe('protein (g)')
        ->and(AfcdMapper::nutrientKey('vitamin c (mg)'))->toBe('vitamin_c_mg');
});

// ---- Open Food Facts mapping --------------------------------------------------------------------------------------

function offRow(array $over = []): array
{
    return $over + [
        'code' => '9300633500100', 'product_name' => 'Weet-Bix', 'brands' => 'Sanitarium, Other', 'stores' => 'Coles,Woolworths',
        'categories_en' => 'Plant-based foods, Breakfasts, Cereals', 'quantity' => '575 g', 'serving_size' => '2 biscuits (30 g)', 'serving_quantity' => '30',
        'energy-kcal_100g' => '350', 'proteins_100g' => '11.5', 'carbohydrates_100g' => '67', 'fat_100g' => '1.4',
        'fiber_100g' => '11', 'sugars_100g' => '3', 'saturated-fat_100g' => '0.3', 'sodium_100g' => '0.28',
    ];
}

it('maps a good OFF row', function () {
    $m = OffMapper::map(offRow());
    expect($m)->not->toHaveKey('reject')
        ->and($m['food']['source'])->toBe('off')->and($m['food']['barcode'])->toBe('9300633500100')
        ->and($m['food']['brand'])->toBe('Sanitarium')->and($m['food']['per100_sodium'])->toBe(280.0)
        ->and($m['food']['needs_check'])->toBeFalse()->and($m['food']['is_liquid'])->toBeFalse()
        ->and(json_decode($m['food']['stores']))->toBe(['Coles', 'Woolworths'])
        ->and($m['serving'])->toBe(['label' => '2 biscuits (30 g)', 'grams' => 30.0]);
});

it('rejects OFF rows that fail validation', function (array $over, string $why) {
    expect(OffMapper::map(offRow($over)))->toBe(['reject' => $why]);
})->with([
    'no macros' => [['proteins_100g' => ''], 'missing macros'],
    'bad barcode' => [['code' => '123'], 'bad barcode'],
    'no name' => [['product_name' => '', 'generic_name' => ''], 'no name'],
    'macro > 100 g' => [['fat_100g' => '120'], 'macro out of range'],
    'macros sum > 100 g' => [['proteins_100g' => '40', 'carbohydrates_100g' => '40', 'fat_100g' => '30'], 'nonsense energy or macros'],
    'kcal off by > 20%' => [['energy-kcal_100g' => '600'], 'kcal disagrees with macros'],
]);

it('flags a 10-20% kcal disagreement instead of rejecting it', function () {
    // macros give 4*11.5+4*67+9*1.4 = 326.6; 370 is 13% away
    $m = OffMapper::map(offRow(['energy-kcal_100g' => '370']));
    expect($m['food']['needs_check'])->toBeTrue()->and($m['food']['quality'])->toBeLessThan(OffMapper::map(offRow())['food']['quality']);
});

it('drops impossible secondary nutrients but keeps the row', function () {
    $m = OffMapper::map(offRow(['fiber_100g' => '99999', 'sugars_100g' => '-3', 'saturated-fat_100g' => '250', 'sodium_100g' => '5000']));
    expect($m)->not->toHaveKey('reject')
        ->and($m['food']['per100_fibre'])->toBeNull()->and($m['food']['per100_sugar'])->toBeNull()
        ->and($m['food']['per100_sat_fat'])->toBeNull()->and($m['food']['per100_sodium'])->toBeNull()
        ->and($m['food']['per100_protein'])->toBe(11.5);
});

it('falls back to kJ for energy and salt for sodium, and spots liquids', function () {
    $m = OffMapper::map(offRow(['energy-kcal_100g' => '', 'energy_100g' => '1464', 'sodium_100g' => '', 'salt_100g' => '0.7', 'quantity' => '1 L']));
    expect($m['food']['per100_kcal'])->toBe(349.9)->and($m['food']['per100_sodium'])->toBe(280.0)->and($m['food']['is_liquid'])->toBeTrue();
});

// ---- Servings -----------------------------------------------------------------------------------------------------

it('resolves quantities to grams using Australian metric measures', function () {
    expect(ServingResolver::resolve(150, 'g', false, null, [])['grams'])->toBe(150.0)
        ->and(ServingResolver::resolve(1.5, 'kg', false, null, [])['grams'])->toBe(1500.0)
        ->and(ServingResolver::resolve(300, 'ml', true, 1.03, [])['grams'])->toBe(300.0)      // liquid rows are per mL
        ->and(ServingResolver::resolve(1, 'cup', false, 1.03, [])['grams'])->toBe(257.5)       // 250 mL x density
        ->and(ServingResolver::resolve(1, 'tbsp', false, 1.0, [])['grams'])->toBe(20.0);
});

it('never invents a weight', function () {
    expect(ServingResolver::resolve(1, 'cup', false, null, [])['grams'])->toBeNull()          // solid, no density, no cup serving
        ->and(ServingResolver::resolve(2, null, false, null, [])['grams'])->toBeNull()        // count with no stored serving
        ->and(ServingResolver::resolve(2, null, false, null, [['label' => '1 biscuit', 'grams' => 15.0]])['grams'])->toBe(30.0)
        ->and(ServingResolver::resolve(2, null, false, null, [['label' => '50 g', 'grams' => 50.0]])['grams'])->toBeNull()   // a weight alone names no unit
        ->and(ServingResolver::resolve(null, null, false, null, [])['grams'])->toBeNull();
});

// ---- Query building -----------------------------------------------------------------------------------------------

it('counts per unit named by the serving label, not per serving', function (string $label, float $grams, float $qty, ?float $expect) {
    expect(ServingResolver::resolve($qty, null, false, null, [['label' => $label, 'grams' => $grams]])['grams'])->toBe($expect);
})->with([
    'two biscuits per serving' => ['2 biscuits (31 g)', 31.0, 2.0, 31.0],
    'weight first, unit in brackets' => ['33 g (2 biscuits)', 33.0, 2.0, 33.0],
    'one slice' => ['1 slice (38 g)', 38.0, 2.0, 76.0],
    'no digits means one' => ['medium banana', 118.0, 1.0, 118.0],
    'plain serving' => ['1 serving (100 g)', 100.0, 2.0, 200.0],
    'weight only' => ['50 g', 50.0, 2.0, null],
    'volume only' => ['250 ml', 250.0, 2.0, null],
]);

it('finds a solid cup only from a serving that names a cup', function () {
    expect(ServingResolver::resolve(2, 'cup', false, null, [['label' => '1 cup (125 g)', 'grams' => 125.0]])['grams'])->toBe(250.0)
        ->and(ServingResolver::resolve(1, 'cup', false, null, [['label' => '2 biscuits (31 g)', 'grams' => 31.0]])['grams'])->toBeNull();
});

it('singularises plurals', function (string $in, string $out) {
    expect(FoodSearch::singular($in))->toBe($out);
})->with([['eggs', 'egg'], ['berries', 'berry'], ['tomatoes', 'tomato'], ['peaches', 'peach'], ['hummus', 'hummus'], ['rice', 'rice'], ['oats', 'oat'], ['chicken breasts', 'chicken breast']]);

it('builds an injection-proof prefix tsquery', function () {
    expect(FoodSearch::tsQuery(['chicken breast', 'x']))->toBe('(chicken & breast:*) | (x:*)')
        ->and(FoodSearch::tsQuery(["weet-bix", "a'; DROP TABLE foods;--"]))->toBe('(weet & bix:*) | (a & drop & table & foods:*)')
        ->and(FoodSearch::tsQuery(['!!!']))->toBe('')
        ->and(FoodSearch::tokens('Crème brûlée'))->toBe(['creme', 'brulee']);
});
