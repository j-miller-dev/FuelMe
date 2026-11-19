<?php

use App\Domain\Foods\FoodForm;
use App\Domain\Foods\Gtin;

// ---- GTIN ------------------------------------------------------------------------------------------------------------

it('validates GTIN check digits', function (string $code, bool $ok) {
    expect(Gtin::valid($code))->toBe($ok);
})->with([
    'real AU EAN-13 (Vegemite)' => ['9300650018860', true],
    'real AU EAN-13 (Weet-Bix)' => ['9300652010374', true],
    'real UPC-A' => ['036000291452', true],
    'real EAN-8' => ['96385074', true],
    'one digit off' => ['9300650018861', false],
    'transposed' => ['9300650018680', false],
    'too short' => ['12345', false],
    'ten digits' => ['1234567890', false],
    'letters' => ['93006500188AB', false],
]);

it('cleans scanner noise and lists the spellings of one product', function () {
    expect(Gtin::clean(' 9300 6500-18860 '))->toBe('9300650018860');
    // a UPC-A is the same product as an EAN-13 with a leading zero, and as a GTIN-14
    expect(Gtin::variants('036000291452'))->toBe(['036000291452', '0036000291452', '00036000291452'])
        ->and(Gtin::variants('0036000291452'))->toContain('036000291452')->toContain('0036000291452')
        ->and(Gtin::variants('9300650018860'))->toBe(['9300650018860', '09300650018860']);
});

// ---- FoodForm --------------------------------------------------------------------------------------------------------

function form(array $o = []): array
{
    return $o + ['basis' => 'per100', 'protein' => 10.0, 'carbs' => 20.0, 'fat' => 5.0];
}

it('takes per-100 values as typed and derives kcal when blank', function () {
    $r = FoodForm::toPer100(form());
    expect($r['per100'])->toMatchArray(['per100_kcal' => 165.0, 'per100_protein' => 10.0, 'per100_carbs' => 20.0, 'per100_fat' => 5.0])
        ->and($r['needs_check'])->toBeFalse()->and($r['warnings'])->toBe([]);
});

it('converts per-serve values to per 100 g', function () {
    // a 40 g serve with 4 g protein, 20 g carbs, 2 g fat, 150 kcal -> x2.5
    $r = FoodForm::toPer100(form(['basis' => 'serving', 'serving_grams' => 40.0, 'protein' => 4.0, 'carbs' => 20.0, 'fat' => 2.0, 'kcal' => 125.0, 'sodium_mg' => 80.0, 'fibre' => 3.0]));
    expect($r['per100'])->toMatchArray(['per100_kcal' => 312.5, 'per100_protein' => 10.0, 'per100_carbs' => 50.0, 'per100_fat' => 5.0, 'per100_fibre' => 7.5, 'per100_sodium' => 200.0]);
});

it('rejects impossible values with a plain message', function (array $over, string $needle) {
    expect(fn () => FoodForm::toPer100(form($over)))->toThrow(InvalidArgumentException::class, $needle);
})->with([
    'per-serve without a size' => [['basis' => 'serving'], 'serving size'],
    'macros over 100 g' => [['protein' => 60.0, 'carbs' => 60.0], 'more than 100 g'],
    'one macro over 100 g' => [['fat' => 120.0], 'not possible'],
    'over 900 kcal' => [['kcal' => 1200.0], '900'],
    'no energy at all' => [['protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0], 'calories'],
    'fibre over 100' => [['fibre' => 150.0], 'Fibre'],
]);

it('keeps but flags calories that disagree with the macros by over 20%', function () {
    $r = FoodForm::toPer100(form(['kcal' => 250.0]));    // macros say 165
    expect($r['needs_check'])->toBeTrue()->and($r['warnings'])->toHaveCount(1)->and($r['per100']['per100_kcal'])->toBe(250.0);
    expect(FoodForm::toPer100(form(['kcal' => 180.0]))['needs_check'])->toBeFalse();   // 9% off is fine
});
