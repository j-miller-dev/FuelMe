<?php
// Runs all engine checks. Exit code != 0 on any failure. Usage: php tests/Engine/run.php
$scripts = ['macro_tests.php', 'simulate.php', '../Domain/domain_tests.php'];
$fail = 0;
foreach ($scripts as $s) {
    echo "=== $s ===\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $s), $code);
    if ($code !== 0) $fail++;
}
echo $fail ? "\nENGINE TESTS FAILED ($fail)\n" : "\nENGINE TESTS PASSED\n";
exit($fail ? 1 : 0);
