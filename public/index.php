<?php

declare(strict_types=1);

/**
 * DynaPulse front controller.
 * Deploy this folder as the site root: D:\www\DynaPulse\public
 */

// 1. optional override file (next to index.php) that returns an absolute path
$candidates = [];

if (($env = getenv('DYN_PULSE_PRIVATE')) !== false && $env !== '') {
    $candidates[] = $env;
}

$override = __DIR__ . DIRECTORY_SEPARATOR . 'private_path.php';
if (is_file($override)) {
    $value = require $override;
    if (is_string($value) && $value !== '') {
        $candidates[] = $value;
    }
}

$candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private';           // repo / dev layout
$candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse'; // D:\private\DynaPulse
$candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';

$privatePath = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
        $privatePath = realpath($candidate);
        break;
    }
}

if ($privatePath === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "DynaPulse cannot find its private folder.\n\n"
        . "Expected D:\\private\\DynaPulse\\bootstrap.php.\n"
        . "Create public/private_path.php containing the absolute path, or set DYN_PULSE_PRIVATE.\n";
    exit;
}

try {
    /** @var \DynaPulse\Http\Container $container */
    $container = require $privatePath . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $kernel = $container->get(\DynaPulse\Http\Kernel::class);
    $kernel->handle(\DynaPulse\Http\Request::fromGlobals())->send();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    $flag = strtolower((string) (getenv('APP_DEBUG') ?: ($_SERVER['APP_DEBUG'] ?? '')));
    $debug = in_array($flag, ['1', 'true', 'yes', 'on'], true);
    $message = $debug
        ? htmlspecialchars($e->getMessage(), ENT_QUOTES) . '<br><small>' . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES) . '</small>'
        : 'DynaPulse could not start. Check private/storage/logs for details.';

    echo '<!doctype html><meta charset="utf-8"><title>DynaPulse error</title>'
        . '<div style="font-family:system-ui;padding:48px;max-width:720px;margin:auto">'
        . '<h1 style="color:#b91c1c">Startup error</h1><p>' . $message . '</p></div>';
}
