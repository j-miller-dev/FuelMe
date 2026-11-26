<?php

use App\Domain\Training\RoutineFolders;

function rfFolders(): array
{
    return [
        ['key' => '1', 'title' => 'Rational Intensity P1: Optimized (Tension)'], ['key' => '2', 'title' => 'Rational Intensity P2: Overload'], ['key' => '3', 'title' => 'Rational Intensity P3: Heavy Duty'],
        ['key' => '4', 'title' => 'Rational Intensity'], ['key' => '5', 'title' => 'HIT: HOME EDITION'], ['key' => '6', 'title' => 'PHASE 1: ANATOMICAL ADAPTATION'], ['key' => 'none', 'title' => 'No folder'],
    ];
}

it('shows only the current program folders by default, and hides the old ones', function () {
    expect(RoutineFolders::visible(rfFolders(), null))->toBe(['shown' => ['1', '2', '3'], 'auto' => true]);
});

it('shows everything rather than an empty page when nothing matches the automatic rule', function () {
    $other = [['key' => 'a', 'title' => 'HIT'], ['key' => 'b', 'title' => 'Push Pull']];

    expect(RoutineFolders::visible($other, null))->toBe(['shown' => ['a', 'b'], 'auto' => true]);
});

it('uses the folders you chose, ignores ones that no longer exist, and falls back to automatic if none are left', function () {
    expect(RoutineFolders::visible(rfFolders(), ['1', '5', 'gone']))->toBe(['shown' => ['1', '5'], 'auto' => false])
        ->and(RoutineFolders::visible(rfFolders(), ['gone']))->toBe(['shown' => ['1', '2', '3'], 'auto' => true]);
});
