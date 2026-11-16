<?php

declare(strict_types=1);

/**
 * DynaPulse setup doctor.
 *
 * Open https://your-host/dp-check.php after copying the files.
 * It verifies PHP, extensions, paths, .env, storage and (optionally) booting
 * the application. It never prints secret values.
 *
 * DELETE THIS FILE once the portal works.
 */

$rows = [];
$add = static function (string $label, bool $ok, string $detail = '') use (&$rows): void {
    $rows[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

$publicRoot = __DIR__;

/* ------------------------------------------------------------------ PHP */
$add('PHP is executing', true, 'version ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
$add(
    'PHP version',
    version_compare(PHP_VERSION, '8.1.0', '>='),
    version_compare(PHP_VERSION, '8.5.0', '>=')
        ? 'matches the 8.5 target'
        : 'DynaPulse targets 8.5; this server runs ' . PHP_VERSION,
);

foreach (['pdo_sqlite', 'curl', 'openssl', 'mbstring', 'json', 'zip', 'fileinfo'] as $extension) {
    $required = $extension !== 'zip';
    $loaded = extension_loaded($extension);
    $add(
        'Extension ' . $extension,
        $loaded || !$required,
        $loaded ? 'loaded' : ($required ? 'MISSING (required)' : 'missing — XLSX exports will be unavailable, CSV still works'),
    );
}

$add('64-bit integers', PHP_INT_SIZE >= 8, PHP_INT_SIZE . '-byte ints');

/* -------------------------------------------------------------- private */
$candidates = [];

$envPath = getenv('DYN_PULSE_PRIVATE');
if (is_string($envPath) && $envPath !== '') {
    $candidates[] = $envPath;
}

$override = $publicRoot . DIRECTORY_SEPARATOR . 'private_path.php';
if (is_file($override)) {
    $value = require $override;
    if (is_string($value) && $value !== '') {
        $candidates[] = $value;
    }
}

$candidates[] = dirname($publicRoot) . DIRECTORY_SEPARATOR . 'private';
$candidates[] = dirname($publicRoot, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';
$candidates[] = dirname($publicRoot, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'dynapulse';
$candidates[] = dirname($publicRoot, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';
$candidates[] = dirname($publicRoot, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'dynapulse';
$candidates[] = dirname($publicRoot) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DynaPulse';
$candidates[] = dirname($publicRoot) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'dynapulse';

$privatePath = null;
$searched = [];

foreach (array_unique($candidates) as $candidate) {
    $normalized = rtrim((string) $candidate, '/\\');
    $searched[] = $normalized;

    if ($privatePath !== null) {
        continue;
    }

    foreach ([$normalized, $normalized . DIRECTORY_SEPARATOR . 'private'] as $probe) {
        if (is_file($probe . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
            $privatePath = (string) realpath($probe);
            break;
        }
    }
}

$add('Public folder', true, $publicRoot);
$add(
    'Private folder',
    $privatePath !== null,
    $privatePath ?? 'NOT FOUND — create public\\private_path.php returning the absolute path to D:\\private\\dynapulse',
);

if ($privatePath !== null) {
    $envFile = $privatePath . DIRECTORY_SEPARATOR . '.env';
    $envExists = is_file($envFile) && is_readable($envFile);
    $add('.env file', $envExists, $envExists ? $envFile : 'missing — copy .env.example to ' . $envFile);

    if ($envExists) {
        $contents = (string) file_get_contents($envFile);

        // Read KEY=value the way the application does: the value may be quoted
        // and may carry a trailing " # comment", while a line that holds only a
        // comment (KEY=   # note) means "not set" — never the comment text.
        $envValue = static function (string $name) use ($contents): string {
            if (preg_match('/^' . preg_quote($name, '/') . '[ \t]*=[ \t]*(.*)$/m', $contents, $match) !== 1) {
                return '';
            }

            $value = trim($match[1]);

            if ($value === '' || str_starts_with($value, '#')) {
                return '';
            }

            $value = trim((string) preg_replace('/[ \t]+#.*$/', '', $value));

            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }

            return $value;
        };

        $key = $envValue('APP_KEY');
        $add('APP_KEY', strlen($key) >= 16, strlen($key) >= 16 ? 'set (' . strlen($key) . ' chars)' : 'missing — run: php bin/console.php key:generate');

        $appUrl = $envValue('APP_URL');
        $add('APP_URL', $appUrl !== '' && str_starts_with($appUrl, 'http'), $appUrl !== '' ? $appUrl : 'missing — alert email links will be wrong');

        foreach (['NS_BASE_URL', 'NS_API_KEY', 'NS_CLIENT_ID', 'NS_CLIENT_SECRET', 'WEBHOOK_SECRET', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $name) {
            $value = $envValue($name);
            $add($name, $value !== '', $value !== '' ? 'set' : 'empty');
        }
    }

    foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/db', 'storage/tmp'] as $directory) {
        $path = $privatePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $add('Writable ' . $directory, $writable, $exists ? $path : 'missing — create it and give the IIS app pool Modify rights');
    }

    $storageWritable = is_dir($privatePath . DIRECTORY_SEPARATOR . 'storage')
        && @file_put_contents($privatePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '.dp-write-test', 'ok') !== false;

    if ($storageWritable) {
        @unlink($privatePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '.dp-write-test');
    }

    $dbPath = $privatePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'dynapulse.sqlite';
    $dbExists = is_file($dbPath);

    if (extension_loaded('pdo_sqlite')) {
        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
            $add('SQLite database', true, $dbPath . ' (' . (int) $tables . ' tables' . ($dbExists ? '' : ', will be created on first request') . ')');
        } catch (Throwable $e) {
            $add('SQLite database', false, $dbPath . ' — ' . $e->getMessage());
        }
    } else {
        $add('SQLite database', false, 'pdo_sqlite is not loaded, so the database cannot open');
    }

    if (isset($_GET['boot']) && $_GET['boot'] === '1') {
        try {
            $bootstrap = require $privatePath . DIRECTORY_SEPARATOR . 'bootstrap.php';
            $add('Bootstrap', true, 'application booted');

            if (is_object($bootstrap) && method_exists($bootstrap, 'get')) {
                $kernel = $bootstrap->get(DynaPulse\Http\Kernel::class);
                $add('Kernel', $kernel !== null, get_class($kernel));

                $url = (string) \DynaPulse\Http\Context::config('app.url', '');
                $add('Resolved APP_URL', $url !== '', $url !== '' ? $url : 'not set');
            }
        } catch (Throwable $e) {
            $add('Bootstrap', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

$base = $_SERVER['DOCUMENT_ROOT'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '';

/* ----------------------------------------------------------- URL prefix */
// Same idea as DynaPulse\Http\Request: the folder below the document root is
// the URL prefix the portal is reachable at (/dynapulse/public for example).
$docRoot = rtrim(str_replace('\\', '/', (string) $base), '/');
$scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$urlPrefix = '';

if ($docRoot !== '' && $scriptFile !== '') {
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptFile)), '/');
    if (stripos($scriptDir, $docRoot . '/') === 0) {
        $urlPrefix = substr($scriptDir, strlen($docRoot));
    }
}

$uriPath = '/' . trim(rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/')), '/');
$prefixMatches = $uriPath === ($urlPrefix === '' ? '' : $urlPrefix)
    || str_starts_with($uriPath, ($urlPrefix === '' ? '' : $urlPrefix) . '/')
    || $uriPath === '/';
/* ------------------------------------------------------------ web server */
$software = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
$isApache = stripos($software, 'apache') !== false;
$isIis = stripos($software, 'microsoft-iis') !== false || stripos($software, 'iis') !== false;
$rewriteInstalled = function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null;
$htaccessPresent = is_file(__DIR__ . DIRECTORY_SEPARATOR . '.htaccess');
$webConfigPresent = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'web.config');

$add('Web server', true, $software !== '' ? $software : 'not reported');

if ($isApache) {
    $add(
        'Apache mod_rewrite',
        $rewriteInstalled !== false,
        $rewriteInstalled === true
            ? 'loaded'
            : ($rewriteInstalled === null
                ? 'cannot be read from PHP — if /login gives "The requested URL was not found", mod_rewrite is off'
                : 'NOT loaded — uncomment "LoadModule rewrite_module" in httpd.conf and restart Apache'),
    );
    $add(
        'public/.htaccess (pretty URLs)',
        $htaccessPresent,
        $htaccessPresent
            ? 'present — routes like /login are rewritten to index.php'
            : 'MISSING — without it only "/" works; every other URL returns Apache\'s 404 page. Copy .htaccess into ' . __DIR__,
    );
} elseif ($isIis) {
    $add(
        'public/web.config (URL Rewrite)',
        $webConfigPresent,
        $webConfigPresent ? 'present — the IIS URL Rewrite module must be installed' : 'MISSING — copy web.config into ' . __DIR__,
    );
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DynaPulse setup doctor</title>
<style>
    :root { color-scheme: dark; }
    body { margin: 0; padding: 32px 16px; background: #070b14; color: #e6ecf7; font: 14px/1.55 "Segoe UI", system-ui, sans-serif; }
    .wrap { max-width: 880px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    p.sub { color: #8b9ab2; margin: 0 0 22px; }
    .card { background: #0f172a; border: 1px solid rgba(148,163,184,.16); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 7px 0; border-bottom: 1px solid rgba(148,163,184,.1); vertical-align: top; font-size: 13px; }
    tr:last-child td { border-bottom: 0; }
    td.state { width: 26px; }
    .ok { color: #22c55e; }
    .bad { color: #ef4444; }
    .warn { color: #f59e0b; }
    td.label { width: 210px; color: #b6c2d6; }
    code, .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #a5b4fc; word-break: break-all; }
    a.btn { display: inline-block; margin-top: 12px; padding: 8px 14px; border-radius: 9px; background: #4f46e5; color: #fff; text-decoration: none; font-weight: 600; font-size: 13px; }
    ul { margin: 10px 0 0 18px; padding: 0; color: #b6c2d6; font-size: 13px; }
    li { margin-bottom: 6px; }
    .note { color: #8b9ab2; font-size: 12px; margin-top: 12px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>DynaPulse setup doctor</h1>
    <p class="sub">Checks the server, the file layout and the application bootstrap. Secret values are never printed.</p>

    <div class="card">
        <table>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="state <?= $row['ok'] ? 'ok' : 'bad' ?>"><?= $row['ok'] ? '&#10003;' : '&#10007;' ?></td>
                    <td class="label"><?= $e($row['label']) ?></td>
                    <td><span class="<?= $row['ok'] ? '' : 'warn' ?>"><?= $e($row['detail']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if (!$prefixMatches): ?>
            <p class="note">The browser opened <span class="mono"><?= $e($uriPath) ?></span>, which is not inside the portal folder
                <span class="mono"><?= $e($urlPrefix) ?></span>. Open
                <span class="mono"><?= $e($urlPrefix === '' ? '' : $urlPrefix) ?>/</span> instead and the links will line up.</p>
        <?php endif; ?>

        <?php if ($privatePath !== null && (!isset($_GET['boot']) || $_GET['boot'] !== '1')): ?>
            <a class="btn" href="?boot=1">Run a full bootstrap test</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Request</strong>
        <table>
            <tr><td class="label">Document root</td><td><span class="mono"><?= $e($base) ?></span></td></tr>
            <tr><td class="label">Request URI</td><td><span class="mono"><?= $e($uri) ?></span></td></tr>
            <tr><td class="label">Private folders searched</td><td><span class="mono"><?= $e(implode(' | ', array_unique($searched))) ?></span></td></tr>
            <tr><td class="label">Script name</td><td><span class="mono"><?= $e($scriptName !== '' ? $scriptName : 'not reported') ?></span></td></tr>
            <tr><td class="label">URL prefix</td><td><span class="mono <?= $prefixMatches ? '' : 'warn' ?>"><?= $e($urlPrefix === '' ? 'served from the site root ( / )' : $urlPrefix) ?></span></td></tr>
            <tr><td class="label">URL rewriting</td><td><?= $isApache ? ($rewriteInstalled === true ? 'Apache mod_rewrite loaded' : 'Apache — mod_rewrite status unknown') . ($htaccessPresent ? ', .htaccess present' : ', .htaccess MISSING') : ($isIis ? 'IIS — ' . ($webConfigPresent ? 'web.config present' : 'web.config MISSING') : 'not applicable') ?></td></tr>
        </table>
    </div>

    <div class="card">
        <strong>If something is red</strong>
        <ul>
            <li><strong>Private folder NOT FOUND</strong> — create <span class="mono">public\private_path.php</span> containing
                <span class="mono">&lt;?php return 'D:\\private\\dynapulse';</span></li>
            <li><strong>Writable storage ... false</strong> — grant the IIS application pool
                (<span class="mono">IIS AppPool\&lt;pool name&gt;</span>) <em>Modify</em> on <span class="mono">D:\private\dynapulse\storage</span></li>
            <li><strong>pdo_sqlite missing</strong> — enable <span class="mono">extension=pdo_sqlite</span> and <span class="mono">extension=sqlite3</span> in php.ini, then restart IIS</li>
            <li><strong>Everything returns HTTP 500.19 before this page loads</strong> — the IIS URL Rewrite module is missing.
                Either install it, or temporarily rename <span class="mono">web.config</span> and open this page again.</li>
            <li><strong>Apache answers “The requested URL was not found on this server”</strong> for
                <span class="mono">/login</span> (but the portal loads at the folder URL) — <span class="mono">.htaccess</span>
                is missing from this folder, or <span class="mono">AllowOverride None</span> is set for it in
                <span class="mono">httpd.conf</span>.</li>
            <li><strong>Bootstrap fails</strong> — the exact exception is printed above; also check
                <span class="mono">D:\private\dynapulse\storage\logs</span></li>
        </ul>
        <p class="note">Delete dp-check.php once the portal is running.</p>
    </div>
</div>
</body>
</html>
