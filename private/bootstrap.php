<?php

declare(strict_types=1);

/**
 * Boots DynaPulse and returns the service container.
 *
 * Layout assumed in production:
 *   D:\www\DynaPulse\public      <- document root (index.php, assets)
 *   D:\private\DynaPulse         <- this folder (.env, storage, src)
 */

use DynaPulse\Database\Database;
use DynaPulse\Database\Migrator;
use DynaPulse\Http\Container;
use DynaPulse\Http\Kernel;
use DynaPulse\Http\Router;
use DynaPulse\Http\Session;
use DynaPulse\Http\View;
use DynaPulse\NetSapiens\NsClient;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Support\Config;
use DynaPulse\Support\Env;
use DynaPulse\Support\Logger;

require_once __DIR__ . '/autoload.php';

Env::load(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$config = Config::fromEnvironment(__DIR__);

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

error_reporting(E_ALL);
ini_set('display_errors', $config->isDebug() ? '1' : '0');
ini_set('log_errors', '1');

Logger::boot((string) $config->get('paths.logs'), (string) $config->get('security.log_level', 'info'));

foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/tmp', 'storage/db', 'storage/recordings'] as $directory) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

$databasePath = (string) $config->get('db.database');
if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $databasePath)) {
    $databasePath = __DIR__ . DIRECTORY_SEPARATOR . $databasePath;
}
$config->set('db.database', $databasePath);
$publicPath = (string) Env::get('PUBLIC_PATH', '');
if ($publicPath === '') {
    $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $publicPath = $documentRoot !== '' ? $documentRoot : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
}
$config->set('paths.public', rtrim($publicPath, '/\\'));

$session = new Session(
    store: [],
    name: (string) $config->get('security.session_name', 'dynapulse'),
    savePath: (string) $config->get('paths.sessions'),
    lifetimeMinutes: (int) $config->get('security.session_lifetime', 480),
    secure: (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
);

$db = new Database($databasePath);

$container = new Container();
$container->set(Container::class, $container);
$container->set(Config::class, $config);
$container->set(Config::class . '.', $config);
$container->set(Session::class, $session);
$container->set(Database::class, $db);
$container->factory(NsGateway::class, static fn (Container $c): NsClient => new NsClient($c->get(Config::class)));
$container->set(View::class, new View(__DIR__ . DIRECTORY_SEPARATOR . 'views'));

$router = new Router();
require __DIR__ . DIRECTORY_SEPARATOR . 'routes.php';
$container->set(Router::class, $router);

// First run convenience: create the SQLite schema automatically.
$migrator = new Migrator($db, __DIR__ . DIRECTORY_SEPARATOR . 'migrations');
try {
    if ($db->isConfigured() === false) {
        $applied = $migrator->run();
        if ($applied !== []) {
            Logger::info('database.migrated', ['migrations' => $applied]);
        }
    }
} catch (Throwable $e) {
    Logger::exception($e, 'database.migration_failed');
    throw $e;
}

$container->set(Migrator::class, $migrator);

$container->get(Kernel::class);

return $container;
