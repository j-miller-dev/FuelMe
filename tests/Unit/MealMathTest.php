<?php

use App\Domain\Foods\MealMath;

// Hand-checked: 500 g raw chicken breast (110 kcal, 23P, 0C, 1.5F per 100 g) + 200 g raw rice (360 kcal, 7P, 80C, 0.6F per 100 g).
//   kcal    500*1.10 + 200*3.60 = 550 + 720 = 1270      protein 115 + 14 = 129
//   carbs   0 + 160 = 160                               fat 7.5 + 1.2 = 8.7
//   weight  700 g raw, 30% lost cooking -> 490 g cooked
function chickenAndRice(): array
{
    return [
        ['per100' => ['kcal' => 110.0, 'protein' => 23.0, 'carbs' => 0.0, 'fat' => 1.5, 'fibre' => null], 'grams' => 500.0],
        ['per100' => ['kcal' => 360.0, 'protein' => 7.0, 'carbs' => 80.0, 'fat' => 0.6, 'fibre' => null], 'grams' => 200.0],
    ];
}

it('computes macros per portion from raw ingredients', function () {
    $c = MealMath::compute(chickenAndRice(), 2, 0.30);

    expect($c['total'])->toMatchArray(['kcal' => 1270.0, 'protein' => 129.0, 'carbs' => 160.0, 'fat' => 8.7])
        ->and($c['raw_grams'])->toBe(700.0)->and($c['cooked_grams'])->toBe(490.0)
        ->and($c['portion'])->toMatchArray(['grams' => 245.0, 'kcal' => 635.0, 'protein' => 64.5, 'carbs' => 80.0, 'fat' => 4.35])
        ->and($c['per100']['kcal'])->toBe(259.18)   // 1270 / 490 * 100
        ->and($c['incomplete'])->toBe(0);
});

it('does not change the macros when cooking loses weight, only the weight', function () {
    $dry = MealMath::compute(chickenAndRice(), 2, 0.0);
    $cooked = MealMath::compute(chickenAndRice(), 2, 0.30);

    expect($cooked['portion']['kcal'])->toBe($dry['portion']['kcal'])
        ->and($cooked['portion']['grams'])->toBeLessThan($dry['portion']['grams'])
        ->and($cooked['per100']['kcal'])->toBeGreaterThan($dry['per100']['kcal']);   // denser per 100 g once water is gone
});

it('counts an ingredient with no data as zero and says so', function () {
    $c = MealMath::compute([...chickenAndRice(), ['per100' => null, 'grams' => 100.0]], 1, 0);

    expect($c['incomplete'])->toBe(1)->and($c['total']['kcal'])->toBe(1270.0)->and($c['raw_grams'])->toBe(800.0);
});

it('rejects a nonsense yield or cooking loss', function () {
    expect(fn () => MealMath::compute(chickenAndRice(), 0, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MealMath::compute(chickenAndRice(), 1, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MealMath::compute(chickenAndRice(), 1, -0.1))->toThrow(InvalidArgumentException::class);
});

it('turns a weight into portions and tracks what is left in a batch', function () {
    expect(MealMath::portionsForGrams(250, 400))->toBe(0.625)
        ->and(MealMath::remaining(8, 3.5))->toBe(4.5)
        ->and(MealMath::remaining(2, 5))->toBe(0.0);
});
