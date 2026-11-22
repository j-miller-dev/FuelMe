<?php

use App\Domain\Recipes\MarkdownRecipeParser as P;

const NOTE = <<<'MD'
---
title: Garlic Chicken Rice Bowl
servings: 4
tags: [dinner, meal-prep]
---
# Garlic chicken rice bowl

Serves 4 · prep 15 min

## Ingredients
- 600 g chicken breast
- [ ] 2 cups cooked **basmati** rice
- 1½ tbsp [[Olive oil|olive oil]]
- 1/2 cup frozen peas
- 3 cloves garlic, crushed

### Sauce
1. 2 tbsp soy sauce
2. 1 tsp honey

## Method
1. Cook the chicken for 10 minutes.
2. Add 200 g of nothing that should be read.
MD;

it('reads title, servings, tags and ingredients from a typical Obsidian note', function () {
    $r = P::parse(NOTE, 'fallback');
    expect($r['title'])->toBe('Garlic Chicken Rice Bowl')
        ->and($r['servings'])->toBe(4.0)
        ->and($r['tags'])->toBe(['dinner', 'meal-prep'])
        ->and(array_column($r['ingredients'], 'line'))->toBe([
            '600 g chicken breast', '2 cups cooked basmati rice', '1.5 tbsp olive oil', '0.5 cup frozen peas',
            '3 cloves garlic, crushed', '2 tbsp soy sauce', '1 tsp honey',
        ])
        ->and($r['ingredients'][5]['group'])->toBe('Sauce')
        ->and($r['ingredients'][0]['group'])->toBeNull()
        ->and($r['warnings'])->toBe([]);
});

it('stops at the method and never reads steps as ingredients', function () {
    $lines = array_column(P::parse(NOTE)['ingredients'], 'line');
    expect(implode('|', $lines))->not->toContain('nothing that should be read');
});

it('falls back to the filename title and finds servings in the body', function () {
    $r = P::parse("## Ingredients\n- 2 eggs\n\n**Makes:** 6 muffins\n", 'Egg muffins');
    expect($r['title'])->toBe('Egg muffins')->and($r['servings'])->toBe(6.0)->and($r['ingredients'])->toHaveCount(1);
});

it('uses amount-led bullets when there is no Ingredients heading, and says so', function () {
    $r = P::parse("# Quick oats\n\n- 50 g rolled oats\n- 250 ml milk\n- Use a big bowl\n\n## Method\n- 2 minutes in the microwave\n");
    expect(array_column($r['ingredients'], 'line'))->toBe(['50 g rolled oats', '250 ml milk'])
        ->and($r['warnings'][0])->toContain('No "Ingredients" heading');
});

it('warns when nothing can be read', function () {
    $r = P::parse("# Notes to self\n\nbuy more coffee\n");
    expect($r['ingredients'])->toBe([])->and($r['warnings'])->toContain('No ingredients found in this note.');
});

it('cleans list syntax, links, emphasis and fractions', function (string $in, string $out) {
    expect(P::cleanLine($in))->toBe($out);
})->with([
    ['- [x] 1 1/2 cups *plain* flour', '1.5 cups plain flour'],
    ['* 250g [Greek yoghurt](https://example.com)', '250g Greek yoghurt'],
    ['3. ½ tsp salt', '0.5 tsp salt'],
    ['- 2½ cups water', '2.5 cups water'],
    ['- **500 g** beef mince <br>', '500 g beef mince'],
]);

it('ignores silly serving counts', function () {
    expect(P::parse("---\nservings: 5000\n---\n## Ingredients\n- 1 egg\n")['servings'])->toBeNull();
});
