<?php

// The dependency-free runner scripts are the source of truth for CLAUDE.md ("must pass before every commit").
// Running them inside Pest keeps one `composer test` entry point, including the 40-person TDEE simulation CI gate (BLUEPRINT 10).

it('passes engine script', function (string $script) {
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$script).' 2>&1';
    exec($cmd, $out, $code);
    expect($code)->toBe(0, implode("\n", $out));
})->with([
    'macro_tests.php',
    'simulate.php',
    '../Domain/domain_tests.php',
    '../Domain/goal_tests.php',
    '../Domain/prior_tests.php',
    '../Domain/rates_tests.php',
]);
