<?php
declare(strict_types=1);

// Minimal PSR-4 shim so the pure-PHP engine tests run with no Composer/Laravel installed.
// Once Composer exists, Pest uses the real autoloader and this file can be deleted.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\Domain\\Nutrition\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../../app/Domain/Nutrition/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) require $file;
    }
});
