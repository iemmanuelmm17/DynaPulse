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
// D:\www\dynapulse\public -> D:\private\dynapulse (Windows is case-insensitive,
// both spellings are listed so the same files work on Linux/macOS too).
$candidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';
$candidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'dynapulse';
$candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse'; // D:\private\DynaPulse
$candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';

$privatePath = null;
foreach ($candidates as $candidate) {
    $candidate = rtrim((string) $candidate, '/\\');

    // Accept both the app folder itself and a repository root that contains
    // bootstrap.php inside its own /private subfolder.
    foreach ([$candidate, $candidate . DIRECTORY_SEPARATOR . 'private'] as $probe) {
        if (is_file($probe . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
            $privatePath = realpath($probe);
            break 2;
        }
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
    $debug = in_array($flag, ['1', 'true', 'yes', 'on'], true)
        || (isset($_GET['debug']) && $_GET['debug'] === '1');

    // Keep a copy outside the web root so the failure survives a refresh.
    $logFile = $privatePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'startup.log';
    if (is_dir(dirname($logFile)) && is_writable(dirname($logFile))) {
        @file_put_contents(
            $logFile,
            '[' . gmdate('c') . '] ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL
            . $e->getTraceAsString() . PHP_EOL . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logHint = is_file($logFile) ? $logFile : $privatePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>DynaPulse startup error</title><style>'
        . 'body{margin:0;padding:40px 16px;background:#070b14;color:#e6ecf7;font:14px/1.6 "Segoe UI",system-ui,sans-serif}'
        . '.wrap{max-width:840px;margin:0 auto}.card{background:#0f172a;border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:22px 24px}'
        . 'h1{font-size:20px;margin:0 0 4px;color:#fca5a5}p.sub{color:#8b9ab2;margin:0 0 18px}'
        . 'code,pre{font-family:ui-monospace,Consolas,monospace;font-size:12px}'
        . 'pre{background:#070b14;border:1px solid rgba(148,163,184,.18);border-radius:10px;padding:14px;overflow:auto;color:#b6c2d6;white-space:pre-wrap}'
        . 'code{color:#a5b4fc;word-break:break-all}ul{color:#b6c2d6;margin:10px 0 0 18px;padding:0}li{margin-bottom:6px}'
        . 'a{color:#a5b4fc}</style></head><body><div class="wrap"><div class="card">'
        . '<h1>DynaPulse could not start</h1>'
        . '<p class="sub">The bootstrap stopped before the portal could handle this request.</p>'
        . '<p><strong>' . $esc($e::class) . '</strong><br>' . $esc($e->getMessage()) . '</p>'
        . '<p><code>' . $esc($e->getFile() . ':' . $e->getLine()) . '</code></p>';

    if ($debug) {
        echo '<pre>' . $esc($e->getTraceAsString()) . '</pre>';
    }

    echo '<ul>'
        . '<li>Open <a href="dp-check.php">dp-check.php</a> for a guided check of PHP, paths, storage and the database.</li>'
        . '<li>Logs: <code>' . $esc($logHint) . '</code></li>'
        . '<li>Add <code>?debug=1</code> to this URL to see the stack trace.</li>'
        . '<li>Most common causes: the <code>storage</code> folder is not writable by the IIS app pool, '
        . '<code>pdo_sqlite</code> is not enabled, or the SQLite file cannot be created.</li>'
        . '</ul></div></div></body></html>';
}
