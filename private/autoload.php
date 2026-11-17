<?php

declare(strict_types=1);

/**
 * PSR-4 autoloader for DynaPulse\ -> private/src.
 * If `composer install` was run, composer's autoloader is used instead (it
 * registers the same mapping plus the helpers file declared in composer.json).
 */

$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'DynaPulse\\Tests\\' => __DIR__ . '/tests/',
        'DynaPulse\\' => __DIR__ . '/src/',
    ];

    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $base . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});

require_once __DIR__ . '/src/helpers.php';
