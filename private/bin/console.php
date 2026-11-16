<?php

declare(strict_types=1);

/**
 * DynaPulse CLI.
 *
 *   php private/bin/console.php migrate
 *   php private/bin/console.php key:generate [--force]
 *   php private/bin/console.php ns:test [--domain=acme.com] [--hours=24]
 *   php private/bin/console.php transcript:test --call=123 [--apply] [--raw]
 *   php private/bin/console.php calls:reprocess [--limit=50]
 *   php private/bin/console.php calls:audit [--limit=500] [--show=3]
 *   php private/bin/console.php domains:sync
 *   php private/bin/console.php poll [hours]
 *   php private/bin/console.php process [--limit=25] [--budget=50] [--call=ID]
 *   php private/bin/console.php notify [--limit=20]
 *   php private/bin/console.php reports:run
 *   php private/bin/console.php prune
 *   php private/bin/console.php repair [--hours=168] [--limit=200]
 *   php private/bin/console.php version
 *   php private/bin/console.php update [--branch=arena/c8955fd9-dynapulse]
 *   php private/bin/console.php status
 *   php private/bin/console.php subscription:create [--scope=domain]
 *   php private/bin/console.php test-mail --to=you@example.com
 *
 * Commands are idempotent: running them from Windows Task Scheduler on a short
 * cadence is safe, and overlapping runs simply find less work to do.
 */

use DynaPulse\Database\Database;
use DynaPulse\Database\Migrator;
use DynaPulse\Http\Container;
use DynaPulse\Http\Context;
use DynaPulse\Mail\MailException;
use DynaPulse\Mail\Mailer;
use DynaPulse\NetSapiens\CdrMapper;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\Transcription;
use DynaPulse\NetSapiens\TranscriptionReference;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Service\CallProcessor;
use DynaPulse\Service\IngestService;
use DynaPulse\Service\NotificationSender;
use DynaPulse\Service\NsDiagnostics;
use DynaPulse\Service\ReportService;
use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;
use DynaPulse\Support\Zip;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
$build = trim((string) @file_get_contents($base . DIRECTORY_SEPARATOR . 'VERSION'));
$build = $build !== '' ? $build : 'unknown';

// Keep in sync with private/VERSION: a mismatch means only some of the files
// were replaced, which is the usual reason "the fix isn't working".
const DP_CODE_BUILD = '2026.11.16.8';

/** @var Container $container */
$container = require $base . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = $container->get(Config::class);
Context::$config = $config;

if ($build !== DP_CODE_BUILD) {
    fwrite(STDERR, '! mixed installation: private/VERSION says "' . $build . '" but this console.php is build '
        . DP_CODE_BUILD . ' — re-copy private\\src, private\\bin, private\\views and private\\VERSION.' . PHP_EOL);
}

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'help';

$options = [];
$arguments = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $argument, $matches)) {
        $options[$matches[1]] = $matches[2] ?? '1';
    } else {
        $arguments[] = $argument;
    }
}

/**
 * Re-read the call ids / job id out of a stored CDR. Returns true when the row
 * had to be changed, so both `calls:reprocess` and `repair` can report it.
 *
 * @param array<string, mixed> $row
 */
function dp_repair_identifiers(CallRepository $calls, int $id, array $row): bool
{
    $rawCdr = json_decode((string) ($row['raw_cdr'] ?? '{}'), true);
    $rawCdr = is_array($rawCdr) ? $rawCdr : [];

    if ($rawCdr === []) {
        return false;
    }

    $storedOrig = trim((string) ($row['orig_call_id'] ?? ''));
    $storedTerm = trim((string) ($row['term_call_id'] ?? ''));
    $storedJob = trim((string) ($row['transcript_job_id'] ?? ''));

    $reference = TranscriptionReference::fromCdr($rawCdr);
    $mapped = CdrMapper::map($rawCdr, (string) ($row['domain_name'] ?? ''));
    $orig = trim((string) $mapped['orig_call_id']);
    $term = trim((string) ($mapped['term_call_id'] ?? ''));
    $job = trim($reference?->jobId ?? (string) ($mapped['transcript_job_id'] ?? ''));

    if ($orig === '' || str_starts_with($orig, 'cdr-')) {
        return false;
    }

    $termChanged = $term !== '' && $term !== $storedTerm;
    $jobChanged = $job !== '' && $job !== $storedJob;

    if ($orig === $storedOrig && !$termChanged && !$jobChanged) {
        return false;
    }

    $calls->patchIdentifiers($id, $orig, $term, $job);

    return true;
}


/**
 * Recursive copy used by `update`; returns the number of files written.
 */
function dp_copy_tree(string $from, string $to): int
{
    if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
        throw new RuntimeException('Cannot create ' . $to);
    }

    $copied = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        $target = $to . DIRECTORY_SEPARATOR . $items->getSubPathname();

        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException('Cannot create ' . $target);
            }

            continue;
        }

        if (!@copy($item->getPathname(), $target)) {
            throw new RuntimeException('Cannot write ' . $target);
        }

        $copied++;
    }

    return $copied;
}

$option = static fn (string $name, ?string $default = null): ?string => $options[$name] ?? $default;
$number = static function (string $name, int $default, int $min, int $max) use ($option): int {
    $value = $option($name);
    if ($value === null || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
};

$out = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
$fail = static function (string $line, int $code = 1) use ($out): never {
    fwrite(STDERR, $line . PHP_EOL);
    exit($code);
};

/** Update a single KEY=value line in the .env file, creating it when missing. */
$writeEnv = static function (string $file, string $key, string $value): bool {
    $line = $key . '=' . $value;
    $contents = is_file($file) ? (string) file_get_contents($file) : '';

    if ($contents === '') {
        $contents = "# DynaPulse environment (generated by console.php)\n" . $line . "\n";

        return (bool) file_put_contents($file, $contents, LOCK_EX);
    }

    $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
    if (preg_match($pattern, $contents)) {
        $contents = (string) preg_replace($pattern, $line, $contents, 1);
    } else {
        $contents = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
    }

    return (bool) file_put_contents($file, $contents, LOCK_EX);
};

try {
    switch ($command) {
        case 'update':
            $branch = (string) ($option('branch') ?? $config->get('app.update_branch', 'main'));
            $url = (string) ($option('url') ?? ('https://codeload.github.com/iemmanuelmm17/DynaPulse/zip/refs/heads/' . $branch));

            $tmpRoot = (string) $config->get('paths.tmp', $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp');
            $stamp = date('Ymd-His');
            $zipPath = $tmpRoot . DIRECTORY_SEPARATOR . 'update-' . $stamp . '.zip';
            $extractDir = $tmpRoot . DIRECTORY_SEPARATOR . 'update-' . $stamp;
            $localZip = $options['zip'] ?? null;
            $downloaded = false;

            $out('DynaPulse update — build ' . $build);
            $out('  from   ' . ($localZip !== null ? $localZip : $url));
            $out('  into   ' . $base);
            $out('');

            if (!is_dir($tmpRoot) && !@mkdir($tmpRoot, 0775, true) && !is_dir($tmpRoot)) {
                $fail('Cannot create ' . $tmpRoot);
            }

            if ($localZip !== null) {
                $out('[1/4] local archive');

                if (!is_file($localZip)) {
                    $fail('No such file: ' . $localZip);
                }

                $zipPath = $localZip;
                $out('      ' . number_format((int) @filesize($zipPath) / 1024, 0) . ' KB');
            } else {
                $out('[1/4] download');
                $handle = curl_init($url);

                $curlOptions = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 180,
                    CURLOPT_USERAGENT => 'DynaPulse-update',
                ];

                // XAMPP's php.ini usually has no CA bundle configured, which makes
                // curl fail with "unable to get local issuer certificate".
                $caFile = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile'));
                $candidates = array_filter([
                    $caFile,
                    dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
                    dirname(PHP_BINARY) . '/cacert.pem',
                    'C:/xampp/php/extras/ssl/cacert.pem',
                    'C:/xampp/apache/bin/curl-ca-bundle.crt',
                    'C:/ProgramData/Composer/cacert.pem',
                ]);

                foreach ($candidates as $candidate) {
                    if (is_string($candidate) && is_file($candidate)) {
                        $curlOptions[CURLOPT_CAINFO] = $candidate;
                        $out('      CA bundle ' . $candidate);

                        break;
                    }
                }

                curl_setopt_array($handle, $curlOptions);
                $body = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);

                if (!is_string($body) || $body === '') {
                    $out('      FAILED — ' . ($error !== '' ? $error : 'empty response'));

                    if (stripos((string) $error, 'certificate') !== false) {
                        $out('');
                        $out('      PHP has no CA bundle for HTTPS. Either point php.ini at one');
                        $out('      (curl.cainfo = C:\xampp\php\extras\ssl\cacert.pem), or download the zip');
                        $out('      in the browser / PowerShell and pass it in:');
                        $out('        php bin\console.php update --zip=%TEMP%\dp.zip');
                    }

                    break;
                }

                if ($status !== 200) {
                    $fail('Download failed: HTTP ' . $status . ' — check the branch name (' . $branch . ').');
                }

                file_put_contents($zipPath, $body);
                $downloaded = true;
                $out('      ' . number_format(strlen($body) / 1024, 0) . ' KB');
            }

            $out('[2/4] unpack');

            try {
                $unpacked = Zip::extract($zipPath, $extractDir);
            } catch (Throwable $e) {
                $fail('Cannot unpack ' . $zipPath . ': ' . $e->getMessage());
            }

            $out('      ' . $unpacked . ' file(s)');

            $roots = glob($extractDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            $root = $roots[0] ?? '';

            if ($root === '' || !is_file($root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console.php')) {
                $fail('The archive does not contain private/bin/console.php.');
            }

            $out('      ' . basename($root));

            $out('[3/4] replace src, bin, views, tests, migrations, bootstrap files');
            $trees = ['src', 'bin', 'views', 'tests', 'migrations'];
            $files = ['VERSION', 'bootstrap.php', 'autoload.php', 'routes.php'];
            $written = 0;

            foreach ($trees as $tree) {
                $from = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $tree;

                if (!is_dir($from)) {
                    continue;
                }

                $written += dp_copy_tree($from, $base . DIRECTORY_SEPARATOR . $tree);
                $out('      ' . $tree);
            }

            foreach ($files as $file) {
                $from = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $file;

                if (is_file($from) && @copy($from, $base . DIRECTORY_SEPARATOR . $file)) {
                    $out('      ' . $file);
                }
            }

            $out('      ' . $written . ' file(s) written — .env and storage/ untouched');

            $publicPath = (string) $config->get('paths.public', '');

            if ($publicPath !== '' && is_dir($publicPath) && is_dir($root . DIRECTORY_SEPARATOR . 'public')) {
                $publicWritten = dp_copy_tree($root . DIRECTORY_SEPARATOR . 'public', $publicPath);
                $out('      public/ (' . $publicWritten . ' file(s) into ' . $publicPath . ')');
            }

            $out('[4/4] migrate');
            /** @var Migrator $updater */
            $updater = $container->get(Migrator::class);
            $updater->run();
            $out('      database up to date');

            $newBuild = trim((string) @file_get_contents($base . DIRECTORY_SEPARATOR . 'VERSION'));
            $out('');
            $out('Updated: ' . $build . ' → ' . ($newBuild !== '' ? $newBuild : '(unknown)'));
            $out('Run it again any time; .env and storage are never touched.');

            if ($downloaded) {
                @unlink($zipPath);
            }

            break;

        case 'version':
        case '--version':
            $out('DynaPulse build ' . $build . '   (code ' . DP_CODE_BUILD . ')');
            $out('  php        ' . PHP_VERSION . '  (' . PHP_SAPI . ')');
            $out('  private    ' . $base);
            $out('  database   ' . (string) $config->get('db.database', ''));
            $out('  features   raw CDRs (raw=yes), prefilled-transcription, domain re-spelling, per-domain audit');

            /** @var Database $versionDb */
            $versionDb = $container->get(Database::class);

            try {
                $calls = (int) (($versionDb->first('SELECT COUNT(*) AS n FROM calls') ?? [])['n'] ?? 0);
                $domains = (int) (($versionDb->first('SELECT COUNT(*) AS n FROM domains') ?? [])['n'] ?? 0);
                $out('  stored     ' . $calls . ' call(s), ' . $domains . ' domain(s)');
            } catch (Throwable $e) {
                $out('  stored     database not migrated yet — run: php bin/console.php migrate');
            }

            break;

        case 'help':
        case '--help':
        case '-h':
            $out('DynaPulse console — build ' . $build);
            $out('');
            $out('  migrate                       apply pending database migrations');
            $out('  key:generate [--force]        write a random APP_KEY into .env');
            $out('  poll [hours]                  pull recent CDRs for every pollable domain');
            $out('  ns:test [--domain] [--hours]  check NetSapiens credentials and CDR access');
            $out('  transcript:test --call=ID     fetch one call\'s Call Intelligence payload (--apply saves it)');
            $out('                                add --cdr to print the stored CDR identity, --raw for the payload');
            $out('  calls:reprocess [--limit]     retry calls that have a job id but no stored transcript');
            $out('  calls:audit [--limit] [--show] which identifiers the stored CDRs actually carry');
            $out('  domains:sync                  import the domains visible to the API key');
            $out('  process [--limit] [--budget]  work through the processing queue');
            $out('                                add --call=ID to process one call and print the outcome');
            $out('  notify [--limit]              send queued alert emails');
            $out('  reports:run                   send report schedules that are due');
            $out('  prune                         delete records older than the retention window');
            $out('  repair [--hours] [--limit]    poll, audit, retry the transcripts, drain the queue');
            $out('  status                        print counters and the last poll outcome');
            $out('  version                       print this build and what it supports');
            $out('  update [--branch=B] [--url=U] download this branch and replace the code in place');
            $out('  subscription:create [--scope] create the NetSapiens cdr event subscription');
            $out('  test-mail --to=EMAIL          send an SMTP test through the Outlook mailbox');
            break;

        case 'migrate':
            $migrator = $container->get(Migrator::class);
            $pending = $migrator->pending();

            if ($pending === []) {
                $out('Database is up to date (' . count($migrator->applied()) . ' migration(s) applied).');
                break;
            }

            foreach ($migrator->run() as $version) {
                $out('  applied ' . $version);
            }
            $out('Done.');
            break;

        case 'key:generate':
            $file = $base . DIRECTORY_SEPARATOR . '.env';
            $current = (string) $config->get('app.key', '');

            if ($current !== '' && $option('force') === null) {
                $fail('APP_KEY is already set. Re-run with --force to replace it (existing email links will stop working).');
            }

            $key = bin2hex(random_bytes(32));
            if (!$writeEnv($file, 'APP_KEY', $key)) {
                $fail('Could not write ' . $file . '. Add this line manually:' . PHP_EOL . 'APP_KEY=' . $key);
            }

            $config->set('app.key', $key);
            Logger::info('console.key_generated');
            $out('APP_KEY written to ' . $file . '.');
            $out('Signed "Listen" links in alert emails are now enabled.');
            break;

        case 'poll':
            /** @var IngestService $ingest */
            $ingest = $container->get(IngestService::class);
            $hours = isset($arguments[0]) && is_numeric($arguments[0]) ? max(1, (int) $arguments[0]) : null;
            $results = $ingest->pollAll($hours);

            $out(sprintf(
                'Polled %d of %d domain(s)%s: %d CDR row(s) seen, %d error(s).',
                (int) $results['polled'],
                (int) $results['domains'],
                $hours !== null ? ' over ' . $hours . 'h' : '',
                (int) $results['calls'],
                count($results['errors']),
            ));
            foreach ($results['errors'] as $error) {
                $out('  ! ' . $error);
            }
            break;

        case 'process':
            /** @var CallProcessor $processor */
            $processor = $container->get(CallProcessor::class);

            $single = $option('call');

            if ($single !== null && is_numeric($single)) {
                /** @var CallRepository $singleCalls */
                $singleCalls = $container->get(CallRepository::class);
                $id = (int) $single;
                $before = $singleCalls->find($id);

                if ($before === null) {
                    $fail('No call with id ' . $id . ' in the database.');
                }

                $out('Call #' . $id . ' — ' . (string) ($before['domain_name'] ?? '')
                    . ' · ' . (string) ($before['started_at'] ?? '')
                    . ' · status ' . (string) ($before['status'] ?? '')
                    . ' · ' . (int) ($before['attempts'] ?? 0) . ' attempt(s)');

                if (dp_repair_identifiers($singleCalls, $id, $before)) {
                    $out('  repaired the call ids / job id from the stored CDR');
                }

                $out('  processing…');
                $result = $processor->process($id, true);
                $row = $singleCalls->find($id) ?? [];

                $out('');
                $out('  result             ' . $result);
                $out('  has_transcript     ' . (int) ($row['has_transcript'] ?? 0));
                $out('  transcript_status  ' . (string) ($row['transcript_status'] ?? ''));
                $out('  attempts           ' . (int) ($row['attempts'] ?? 0));
                $out('  recording_status   ' . (string) ($row['recording_status'] ?? ''));
                $out('  matches            ' . (int) ($row['match_count'] ?? 0));
                $out('  last_error         ' . ((string) ($row['last_error'] ?? '') !== '' ? (string) $row['last_error'] : '(none)'));

                break;
            }

            $result = $processor->work($number('limit', 25, 1, 500), $number('budget', 50, 5, 300));

            $out(sprintf(
                'Processed %d call(s): %d scheduled for retry, %d failed, %d skipped.',
                (int) $result['processed'],
                (int) $result['retry'],
                (int) $result['failed'],
                (int) $result['skipped'],
            ));
            break;

        case 'notify':
            /** @var NotificationSender $sender */
            $sender = $container->get(NotificationSender::class);
            $result = $sender->sendPending($number('limit', 20, 1, 200));

            $out(sprintf(
                'Notifications: %d sent, %d failed, %d blocked, %d skipped.',
                (int) ($result['sent'] ?? 0),
                (int) ($result['failed'] ?? 0),
                (int) ($result['blocked'] ?? 0),
                (int) ($result['skipped'] ?? 0),
            ));
            break;

        case 'reports:run':
            /** @var ReportService $reports */
            $reports = $container->get(ReportService::class);
            $result = $reports->sendDue();

            $out(sprintf(
                'Report schedules: %d due, %d sent, %d failed, %d skipped.',
                (int) $result['due'],
                (int) $result['sent'],
                (int) $result['failed'],
                (int) $result['skipped'],
            ));
            foreach ($result['results'] as $line) {
                $out(sprintf(
                    '  %-32s %-8s calls %-4d alerts %-4d %s',
                    (string) ($line['schedule'] ?? '?'),
                    (string) ($line['status'] ?? '?'),
                    (int) ($line['calls'] ?? 0),
                    (int) ($line['alerts'] ?? 0),
                    (string) ($line['error'] ?? ''),
                ));
            }
            break;

        case 'prune':
            /** @var SettingsRepository $settings */
            $settings = $container->get(SettingsRepository::class);
            $days = $settings->int('retention_days', 365);
            if ($days <= 0) {
                $out('Retention is disabled (retention_days = 0). Nothing pruned.');
                break;
            }

            /** @var CallRepository $calls */
            $calls = $container->get(CallRepository::class);
            $deleted = $calls->prune($days);
            Logger::info('console.pruned', ['days' => $days, 'calls' => $deleted]);
            $out(sprintf('Deleted %d call(s) older than %d days.', $deleted, $days));
            break;

        case 'status':
            /** @var Database $db */
            $db = $container->get(Database::class);
            $count = static function (string $sql) use ($db): int {
                $row = $db->first($sql);

                return $row === null || $row === [] ? 0 : (int) array_values($row)[0];
            };

            $out('DynaPulse status — ' . Time::now() . '   (build ' . $build . ')');
            $out(sprintf('  domains            %d (%d pollable)', $count('SELECT COUNT(*) FROM domains'), $count('SELECT COUNT(*) FROM domains WHERE enabled = 1 AND poll_enabled = 1')));
            $out(sprintf('  calls              %d', $count('SELECT COUNT(*) FROM calls')));
            $out(sprintf('    pending          %d', $count("SELECT COUNT(*) FROM calls WHERE status = 'pending'")));
            $out(sprintf('    processing       %d', $count("SELECT COUNT(*) FROM calls WHERE status = 'processing'")));
            $out(sprintf('    failed           %d', $count("SELECT COUNT(*) FROM calls WHERE status = 'failed'")));
            $out(sprintf('  matches            %d', $count('SELECT COUNT(*) FROM matches')));
            $out(sprintf('  notifications      %d (%d queued, %d failed)', $count('SELECT COUNT(*) FROM notifications'), $count("SELECT COUNT(*) FROM notifications WHERE status = 'queued'"), $count("SELECT COUNT(*) FROM notifications WHERE status = 'failed'")));
            $out(sprintf('  report schedules   %d (%d due)', $count('SELECT COUNT(*) FROM report_schedules'), $count("SELECT COUNT(*) FROM report_schedules WHERE active = 1 AND next_run_at <= datetime('now')")));
            $out(sprintf('  keywords           %d active', $count('SELECT COUNT(*) FROM keywords WHERE active = 1')));
            $out(sprintf('  recipients         %d active', $count('SELECT COUNT(*) FROM recipients WHERE active = 1')));

            /** @var SettingsRepository $settings */
            $settings = $container->get(SettingsRepository::class);
            $out(sprintf('  retention          %s days', $settings->get('retention_days')));
            $out(sprintf('  alerts enabled     %s', $settings->bool('alerts_enabled') ? 'yes' : 'no'));
            $out(sprintf('  mail configured    %s', $container->get(Mailer::class)->configured() ? 'yes' : 'no'));
            $out(sprintf('  latest call        %s', (string) (($db->first('SELECT MAX(started_at) AS latest FROM calls') ?? [])['latest'] ?? '') ?: 'never'));

            $dueNow = $count("SELECT COUNT(*) FROM calls WHERE status IN ('pending', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= datetime('now'))");
            $unavailable = $count("SELECT COUNT(*) FROM calls WHERE has_transcript = 0 AND transcript_status = 'unavailable'");
            $lastProcessed = (string) (($db->first('SELECT MAX(processed_at) AS last FROM calls') ?? [])['last'] ?? '');
            $out(sprintf('  queue              %d due now, last worker pass %s', $dueNow, $lastProcessed !== '' ? $lastProcessed : 'never'));

            if ($unavailable > 0) {
                $out(sprintf('    unavailable      %d call(s) whose CDR carried no Call Intelligence ids', $unavailable));
            }

            if ($dueNow > 0 && $lastProcessed === '') {
                $out('    ! nothing has been processed yet — run: php bin/console.php process');
            }

            $out('');
            $out('  domains:');
            $rows = $container->get(DomainRepository::class)->all();

            if ($rows === []) {
                $out('    (none configured — import them with: php bin/console.php domains:sync)');
            }

            foreach ($rows as $row) {
                $out(sprintf(
                    '    %-30s %-9s poll %-4s calls %-5d last poll %s%s',
                    (string) $row['domain'],
                    (int) ($row['enabled'] ?? 0) === 1 ? 'enabled' : 'disabled',
                    (int) ($row['poll_enabled'] ?? 0) === 1 ? 'on' : 'off',
                    (int) ($row['call_count'] ?? 0),
                    (string) ($row['last_polled_at'] ?? '') !== '' ? (string) $row['last_polled_at'] : 'never',
                    (string) ($row['last_poll_error'] ?? '') !== '' ? PHP_EOL . '        error: ' . (string) $row['last_poll_error'] : '',
                ));
            }
            break;

        case 'ns:test':
        case 'api:test':
            /** @var NsDiagnostics $diagnostics */
            $diagnostics = $container->get(NsDiagnostics::class);
            $probeDomain = (string) ($option('domain') ?? '');
            $report = $diagnostics->run($number('hours', 24, 1, 720), $probeDomain !== '' ? $probeDomain : null);

            foreach ($report['lines'] as $line) {
                $out((string) $line);
            }

            if (!$report['ok']) {
                exit(1);
            }
            break;

        case 'domains:sync':
            /** @var NsGateway $ns */
            $ns = $container->get(NsGateway::class);
            /** @var DomainRepository $domainRepo */
            $domainRepo = $container->get(DomainRepository::class);

            $remote = $ns->domains(200);
            $added = [];
            $renamed = [];

            foreach ($remote as $row) {
                $name = trim((string) ($row['domain'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $existing = $domainRepo->findByDomain($name);

                if ($existing !== null) {
                    // Adopt NetSapiens' exact spelling ("1180.DynaCloud").
                    if ((string) $existing['domain'] !== $name) {
                        $domainRepo->adoptDomainName((int) $existing['id'], $name);
                        $renamed[json_encode([(string) $existing['domain'], $name]) ?: $name] = $name;
                    }

                    continue;
                }

                $domainRepo->ensure($name, (string) ($row['description'] ?? $name), (string) $config->get('app.timezone', 'UTC'));
                $added[] = $name;
            }

            $out(sprintf('%d domain(s) visible to the API key, %d imported, %d re-spelled.', count($remote), count($added), count($renamed)));
            foreach ($added as $name) {
                $out('  + ' . $name);
            }
            foreach ($renamed as $key => $name) {
                $out('  ~ ' . implode(' -> ', (array) json_decode($key, true)) . '  (now uses the NetSapiens spelling)');
            }
            if ($added === [] && $renamed === []) {
                $out('  Nothing new — toggle the domains you monitor under Administration → Domains.');
            }
            break;

        case 'transcript:test':
            $callId = (int) ($option('call') ?? 0);

            if ($callId <= 0) {
                $fail('Usage: php bin/console.php transcript:test --call=123 [--apply] [--raw]');
            }

            /** @var CallRepository $callRepo */
            $callRepo = $container->get(CallRepository::class);
            /** @var NsGateway $ns */
            $ns = $container->get(NsGateway::class);

            $call = $callRepo->find($callId);

            if ($call === null) {
                $fail('No call with id ' . $callId . ' in the database.');
            }

            $jobId = trim((string) ($call['transcript_job_id'] ?? ''));
            $origCallId = trim((string) ($call['orig_call_id'] ?? ''));
            $termCallId = trim((string) ($call['term_call_id'] ?? ''));

            if ($origCallId === '' && $termCallId === '') {
                $origCallId = (string) ($call['call_id'] ?? '');
            }

            $timezone = (string) ($call['domain_timezone'] ?? $config->get('app.timezone', 'UTC'));
            $date = Time::yearMonth((string) ($call['started_at'] ?? ''), $timezone);

            $rawCdr = json_decode((string) ($call['raw_cdr'] ?? '{}'), true);
            $rawCdr = is_array($rawCdr) ? $rawCdr : [];
            $reference = TranscriptionReference::fromCdr($rawCdr);

            // Precedence: the CDR's own prefilled link, then the CDR fields, then the
            // row we stored at ingest. Our synthetic cdr-<hash> ids are never used.
            $cdrOrig = trim((string) ($rawCdr['call-orig-call-id'] ?? ''));
            $cdrTerm = trim((string) ($rawCdr['call-term-call-id'] ?? ''));
            $cdrJob = trim((string) ($rawCdr['call-intelligence-job-id'] ?? ''));
            $cdrStart = trim((string) ($rawCdr['call-start-datetime'] ?? ''));
            $cdrDomain = '';
            $cdrDomainKey = 'domain';

            foreach (['call-term-domain', 'call-orig-domain', 'domain'] as $key) {
                $candidate = trim((string) ($rawCdr[$key] ?? ''));

                if ($candidate !== '') {
                    $cdrDomain = $candidate;
                    $cdrDomainKey = $key;
                    break;
                }
            }

            $resolve = static function (array $pairs): array {
                $synthetic = null;

                foreach ($pairs as [$value, $source]) {
                    $value = trim((string) $value);

                    if ($value === '') {
                        continue;
                    }

                    if (str_starts_with($value, 'cdr-')) {
                        $synthetic ??= [$value, $source . ' — our synthetic id, unusable'];

                        continue;
                    }

                    return [$value, $source];
                }

                return $synthetic ?? ['', 'nothing'];
            };

            [$probeDomain, $domainSource] = $resolve([
                [$reference?->domain ?? '', "the CDR's prefilled link"],
                [$cdrDomain, 'the CDR (' . $cdrDomainKey . ')'],
                [(string) ($call['domain_name'] ?? ''), 'the stored row'],
            ]);

            [$probeJob, $jobSource] = $resolve([
                [$reference?->jobId ?? '', "the CDR's prefilled link"],
                [$cdrJob, 'the CDR (call-intelligence-job-id)'],
                [$jobId, 'the row (transcript_job_id)'],
            ]);

            [$probeDate, $dateSource] = $resolve([
                [$reference?->month ?? '', "the CDR's prefilled link"],
                [$cdrStart !== '' ? Time::yearMonth($cdrStart, 'UTC') : '', 'the CDR start (call-start-datetime, UTC)'],
                [$date, 'the row start (' . $timezone . ')'],
            ]);

            [$probeOrig, $origSource] = $resolve([
                [$reference?->origCallId ?? '', "the CDR's prefilled link"],
                [$cdrOrig, 'the CDR (call-orig-call-id)'],
                [$origCallId !== '' ? $origCallId : (string) ($call['call_id'] ?? ''), 'the row (orig_call_id)'],
            ]);

            [$probeTerm, $termSource] = $resolve([
                [$reference?->termCallId ?? '', "the CDR's prefilled link"],
                [$cdrTerm, 'the CDR (call-term-call-id)'],
                [$termCallId, 'the row (term_call_id)'],
            ]);

            $out('Call #' . $callId . ' — ' . (string) ($call['domain_name'] ?? '') . ' · started '
                . (string) ($call['started_at'] ?? '(unknown)') . ' UTC');
            $out('  transcript status  ' . (string) ($call['transcript_status'] ?? '')
                . '   ·   has_transcript ' . (int) ($call['has_transcript'] ?? 0)
                . ($reference !== null ? '   ·   the CDR carries a prefilled-transcription-api' : ''));

            if ($reference === null) {
                $out('  no prefilled-transcription-api in the stored CDR — the lookup below is built');
                $out('  from the CDR fields and the NetSapiens spelling of the domain.');
            }

            $out('');
            $out('  resolved lookup');
            foreach ([
                'domain' => [$probeDomain, $domainSource],
                'job id' => [$probeJob, $jobSource],
                'date' => [$probeDate, $dateSource],
                'orig_callid' => [$probeOrig, $origSource],
                'term_callid' => [$probeTerm, $termSource],
            ] as $label => [$value, $source]) {
                $out(sprintf('    %-12s %s   [%s]', $label, $value !== '' ? $value : '(empty)', $source));
            }

            $domainName = (string) ($call['domain_name'] ?? '');
            if ($probeDomain !== '' && $domainName !== '' && $probeDomain !== $domainName) {
                $out('');
                $out('  note: the stored row says "' . $domainName . '" but the lookup uses "' . $probeDomain . '";');
                $out('        NetSapiens matches domains exactly, so the row gets re-spelled automatically.');
            }

            if ($option('cdr') !== null) {
                $identity = [];
                foreach (['id', 'call-orig-call-id', 'call-term-call-id', 'call-orig-domain', 'call-term-domain', 'call-intelligence-job-id', 'call-intelligence-processing-status'] as $key) {
                    $value = $rawCdr[$key] ?? null;
                    $identity[] = $key . ' = ' . (is_scalar($value) ? (string) $value : json_encode($value));
                }
                $out('');
                $out('  stored CDR identity:');
                foreach ($identity as $line) {
                    $out('    ' . $line);
                }
                $out('  raw_cdr keys: ' . implode(', ', array_slice(array_keys($rawCdr), 0, 40)));
            }

            if ($probeJob === '') {
                $out('');
                $out('Without a job id there is nothing to fetch: Call Intelligence was not enabled for');
                $out('this call when the CDR was written. Reprocess after enabling it on the domain.');
                break;
            }

            // NetSapiens matches the domain exactly; ask it how the domain is spelled.
            if ($probeDomain !== '') {
                try {
                    $listed = null;

                    foreach ($ns->domains() as $nsRow) {
                        $nsName = trim((string) ($nsRow['domain'] ?? $nsRow['domain-name'] ?? $nsRow['name'] ?? ''));

                        if ($nsName !== '' && strcasecmp($nsName, $probeDomain) === 0) {
                            $listed = $nsName;

                            break;
                        }
                    }

                    if ($listed !== null && $listed !== $probeDomain) {
                        $out('');
                        $out('  NetSapiens lists the domain as "' . $listed . '" — probing with that spelling.');
                        $probeDomain = $listed;
                    } elseif ($listed === null) {
                        $out('');
                        $out('  warning: /domains does not list "' . $probeDomain . '" for this API key.');
                    }
                } catch (NsException) {
                    // The domain list is a nicety; the probe below is the real answer.
                }
            }

            $out('');
            $out('GET /domains/' . $probeDomain . '/transcriptions?id=' . $probeJob . '&date=' . $probeDate
                . '&orig_callid=' . rawurlencode($probeOrig) . '&term_callid=' . rawurlencode($probeTerm));

            try {
                $payload = $ns->transcription($probeDomain, $probeJob, $probeDate, $probeOrig, $probeTerm);
            } catch (NsException $e) {
                $out('  FAILED — ' . $e->getMessage() . ' (HTTP ' . $e->status . ')');
                $body = trim((string) preg_replace('/\s+/', ' ', (string) $e->responseBody));
                $out('  body: ' . ($body !== '' ? substr($body, 0, 400) : '(empty)'));
                $out('');
                $out('  404 means NetSapiens has no transcription row for that job/date/call id yet.');
                $out('  400 means the identifiers were rejected.');
                $out('  403 "Bad request. Invalid domain or callids." means the domain is spelled differently');
                $out('      from the one NetSapiens knows, or one of the call ids is missing.');
                $out('  403 with any other body means the API key cannot read that domain.');
                break;
            }

            $transcription = Transcription::fromApi($payload);

            $out('  processing status  ' . ($transcription->status !== '' ? $transcription->status : '(not reported)'));
            $out('  segments           ' . count($transcription->segments));
            $out('  summary            ' . ($transcription->summary !== '' ? Str::limit($transcription->summary, 160) : '(empty)'));
            $out('  topics             ' . ($transcription->topics !== [] ? implode(', ', $transcription->topics) : '(none)'));
            $out('  sentiment          ' . $transcription->label . ' (+' . $transcription->positive . ' / =' . $transcription->neutral . ' / -' . $transcription->negative . ')');
            $out('  transcript text    ' . strlen($transcription->text) . ' chars');

            foreach (array_slice($transcription->segments, 0, 3) as $segment) {
                $out('    [' . $segment['start'] . '] ' . $segment['speaker'] . ': ' . Str::limit($segment['comment'], 120));
            }

            if ($option('raw') !== null) {
                $out('');
                $out('  raw payload: ' . Str::limit((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1500));
            }

            if ($option('apply') !== null) {
                /** @var CallProcessor $processor */
                $processor = $container->get(CallProcessor::class);
                $result = $processor->process($callId, true);
                $fresh = $callRepo->find($callId) ?? [];

                $out('');
                $out('  reprocessed: ' . $result);
                $out('  has_transcript      ' . (int) ($fresh['has_transcript'] ?? 0));
                $out('  transcript_status   ' . (string) ($fresh['transcript_status'] ?? ''));
                $out('  matches             ' . (int) ($fresh['match_count'] ?? 0));
                $out('  last_error          ' . ((string) ($fresh['last_error'] ?? '') !== '' ? (string) $fresh['last_error'] : '—'));
            }
            break;

        case 'repair':
            $hours = (int) $number('hours', 168, 1, 8760);
            $limit = (int) $number('limit', 200, 1, 500);

            /** @var IngestService $repairIngest */
            $repairIngest = $container->get(IngestService::class);
            /** @var CallRepository $repairCalls */
            $repairCalls = $container->get(CallRepository::class);
            /** @var CallProcessor $repairProcessor */
            $repairProcessor = $container->get(CallProcessor::class);

            $out('DynaPulse repair — build ' . $build);
            $out('  Re-fetching ' . $hours . 'h of CDRs (raw=yes), then auditing, retrying the transcripts and');
            $out('  draining the queue. Safe to re-run.');
            $out('');

            $out('[1/4] poll ' . $hours);
            $polled = $repairIngest->pollAll($hours);
            $out(sprintf(
                '      %d CDR row(s) seen across %d domain(s), %d error(s).',
                (int) $polled['calls'],
                (int) $polled['polled'],
                count($polled['errors']),
            ));

            foreach ($polled['errors'] as $error) {
                $out('      ! ' . $error);
            }

            $coverage = $repairCalls->transcriptCoverage(300);
            $sampled = max(1, (int) $coverage['sampled']);
            $ratio = static fn (int $part): string => sprintf('%3d%%', (int) round($part * 100 / $sampled));

            $out('');
            $out('[2/4] identifiers on the ' . (int) $coverage['sampled'] . ' newest CDR(s)');
            $out('      call-orig-call-id       ' . $ratio($coverage['orig']));
            $out('      call-term-call-id       ' . $ratio($coverage['term']));
            $out('      call-intelligence-job   ' . $ratio($coverage['job']));
            $out('      prefilled link          ' . $ratio($coverage['prefilled']));
            $out('      plain call-id           ' . $ratio($coverage['plain']));

            uasort($coverage['by_domain'], static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

            foreach ($coverage['by_domain'] as $domainName => $stats) {
                $domainPct = static fn (int $part): string => sprintf(
                    '%3d%%',
                    $stats['calls'] > 0 ? (int) round($part * 100 / $stats['calls']) : 0,
                );

                $out(sprintf(
                    '      %-28s job %s  link %s  ids %s  (%d calls)',
                    $domainName !== '' ? $domainName : '(no domain)',
                    $domainPct($stats['job']),
                    $domainPct($stats['link']),
                    $domainPct($stats['ids']),
                    $stats['calls'],
                ));
            }

            $out('');
            $out('[3/4] calls:reprocess --limit=' . $limit);
            $ids = $repairCalls->idsWithoutTranscript($limit);

            if ($ids === []) {
                $out('      nothing to retry.');
            }

            $recovered = 0;

            foreach ($ids as $id) {
                $before = $repairCalls->find($id);

                if ($before !== null && dp_repair_identifiers($repairCalls, $id, $before)) {
                    $out('      #' . $id . ' repaired the call ids / job id from the stored CDR');
                }

                $repairProcessor->process($id, true);
                $after = $repairCalls->find($id) ?? [];

                if ((int) ($after['has_transcript'] ?? 0) === 1) {
                    $recovered++;
                }
            }

            $out(sprintf('      %d of %d transcript(s) recovered.', $recovered, count($ids)));

            $out('');
            $out('[4/4] process --limit=' . $limit);
            $worked = $repairProcessor->work($limit, 55);
            $out(sprintf(
                '      %d processed, %d scheduled for retry, %d failed, %d skipped.',
                (int) $worked['processed'],
                (int) $worked['retry'],
                (int) $worked['failed'],
                (int) $worked['skipped'],
            ));

            $out('');
            $out('Done. Reload the Calls page.');

            if ((int) $coverage['orig'] === 0 && (int) $coverage['prefilled'] === 0 && (int) $coverage['plain'] === 0) {
                $out('');
                $out('The CDRs NetSapiens returned carry no call ids at all, so the poll cannot help:');
                $out('  php bin/console.php ns:test --hours=48');
                $out('That prints the identifiers found on a sample CDR, per domain.');
            }

            break;

        case 'calls:audit':
            /** @var CallRepository $auditCalls */
            $auditCalls = $container->get(CallRepository::class);
            /** @var Database $auditDb */
            $auditDb = $container->get(Database::class);

            $coverage = $auditCalls->transcriptCoverage($number('limit', 500, 1, 5000));
            $show = $number('show', 3, 0, 20);
            $count = (int) $coverage['sampled'];

            if ($count === 0) {
                $fail('No calls stored yet — nothing to audit.');
            }

            $total = (int) (($auditDb->first('SELECT COUNT(*) AS n FROM calls') ?? [])['n'] ?? 0);
            $unavailable = (int) (($auditDb->first(
                "SELECT COUNT(*) AS n FROM calls WHERE has_transcript = 0 AND transcript_status = 'unavailable'",
            ) ?? [])['n'] ?? 0);

            $percent = static fn (int $part): string => sprintf('%3d%%', (int) round($part * 100 / $count));

            $out('Call audit — ' . $count . ' newest of ' . $total . ' call(s), ' . Time::now() . '  (build ' . $build . ')');
            $out('');
            $out('  what the stored CDRs carry');
            $out(sprintf('    raw CDR kept              %5d  %s', $coverage['raw'], $percent($coverage['raw'])));
            $out(sprintf('    call-orig-call-id         %5d  %s', $coverage['orig'], $percent($coverage['orig'])));
            $out(sprintf('    call-term-call-id         %5d  %s', $coverage['term'], $percent($coverage['term'])));
            $out(sprintf('    call-intelligence-job-id  %5d  %s', $coverage['job'], $percent($coverage['job'])));
            $out(sprintf('    prefilled-transcription   %5d  %s', $coverage['prefilled'], $percent($coverage['prefilled'])));
            $out(sprintf('    call-id (single leg)      %5d  %s', $coverage['plain'], $percent($coverage['plain'])));
            $out(sprintf('    no id of any kind         %5d  %s', count($coverage['missing']), $percent(count($coverage['missing']))));
            $out('');
            $out(sprintf('  transcripts marked unavailable across the table: %d', $unavailable));

            if ($coverage['job_null'] > 0) {
                $out(sprintf('  call-intelligence-job-id is present but empty on %d of %d CDR(s)', $coverage['job_null'], $count));
            }

            if ($coverage['by_domain'] !== []) {
                uasort($coverage['by_domain'], static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
                $out('');
                $out('  per domain');
                $out(sprintf('    %-30s %5s  %6s %6s %6s %6s', 'domain', 'calls', 'job id', 'link', 'ids', 'none'));

                foreach ($coverage['by_domain'] as $domainName => $stats) {
                    $pct = static fn (int $part): string => sprintf(
                        '%3d%%',
                        $stats['calls'] > 0 ? (int) round($part * 100 / $stats['calls']) : 0,
                    );

                    $out(sprintf(
                        '    %-30s %5d  %6s %6s %6s %6s',
                        $domainName !== '' ? $domainName : '(no domain)',
                        $stats['calls'],
                        $pct($stats['job']),
                        $pct($stats['link']),
                        $pct($stats['ids']),
                        $pct($stats['none']),
                    ));
                }
            }

            if ($coverage['keys'] !== []) {
                $keys = $coverage['keys'];
                arsort($keys);
                $out('');
                $out('  raw CDR keys (most common first)');

                foreach (array_slice($keys, 0, 25, true) as $key => $hits) {
                    $out(sprintf('    %-34s %5d', $key, $hits));
                }
            }

            if ($show > 0 && $coverage['missing'] !== []) {
                $out('');
                $out('  calls with no usable id');

                foreach (array_slice($coverage['missing'], 0, $show) as $entry) {
                    $row = $entry['row'];
                    $raw = $entry['raw'];

                    $out('');
                    $out(sprintf(
                        '    #%-6s %-24s %s',
                        (string) $row['id'],
                        (string) ($row['domain_name'] ?? ''),
                        (string) ($row['started_at'] ?? ''),
                    ));
                    $out('      job id   ' . (trim((string) ($row['transcript_job_id'] ?? '')) !== '' ? (string) $row['transcript_job_id'] : '(none)'));
                    $out('      row id   ' . (string) ($row['call_id'] ?? '') . '   orig ' . ((string) ($row['orig_call_id'] ?? '') !== '' ? (string) $row['orig_call_id'] : '(empty)'));
                    $out('      keys     ' . ($raw !== [] ? implode(', ', array_slice(array_keys($raw), 0, 30)) : '(raw CDR is empty)'));
                    $out('      payload  ' . ($raw !== [] ? Str::limit((string) json_encode($raw, JSON_UNESCAPED_SLASHES), 320) : '{}'));
                }
            }

            $jobless = [];

            foreach ($coverage['by_domain'] as $domainName => $stats) {
                if ($stats['job'] === 0) {
                    $jobless[$domainName] = $stats['calls'];
                }
            }

            $missingCount = count($coverage['missing']);
            $fixable = $count - $missingCount;
            $missingEmpty = 0;

            foreach ($coverage['missing'] as $entry) {
                if ($entry['raw'] === []) {
                    $missingEmpty++;
                }
            }

            $out('');
            $out('  what this means');

            if ($fixable > 0) {
                $out(sprintf('    %d call(s) carry a usable identifier — reprocess repairs those:', $fixable));
                $out('      php bin/console.php calls:reprocess --limit=100');
            }

            if ($jobless !== []) {
                $out('');
                $out('    Call Intelligence produced no job id for these calls, so NetSapiens has no');
                $out('    transcript to fetch at all — nothing in the CDR can be used:');

                foreach ($jobless as $domainName => $calls) {
                    $out(sprintf('      %s — %d call(s)', $domainName !== '' ? $domainName : '(no domain)', $calls));
                }

                $out('');
                $out('    If those CDRs were stored before this build requested raw=yes, they are');
                $out('    trimmed copies — refresh them and look again:');
                $out('      php bin/console.php poll 168   then   php bin/console.php calls:audit');
                $out('');
                $out('    In NetSapiens, check for each of those domains that Call Intelligence');
                $out('    (recording transcription) is enabled, and that the calls actually have');
                $out('    recordings. A domain where it works has a non-null call-intelligence-job-id');
                $out('    and a prefilled-transcription-api on the CDR.');
            }

            if ($missingEmpty > 0) {
                $out('');
                $out(sprintf('    %d call(s) kept no raw CDR at all: re-ingest with php bin/console.php poll 168', $missingEmpty));
            }

            break;

        case 'calls:reprocess':
            /** @var CallRepository $callRepo */
            $callRepo = $container->get(CallRepository::class);
            /** @var CallProcessor $processor */
            $processor = $container->get(CallProcessor::class);

            $ids = $callRepo->idsWithoutTranscript($number('limit', 50, 1, 500));

            if ($ids === []) {
                $out('Every call that has a Call Intelligence job already has its transcript.');
                break;
            }

            $out(count($ids) . ' call(s) have a job id but no stored transcript. Reprocessing…');

            $fixed = 0;
            foreach ($ids as $id) {
                $before = $callRepo->find($id);

                // Old rows may still carry our synthetic cdr-<hash> id; the stored
                // CDR often knows the real ones, so put them back before the retry.
                if ($before !== null && dp_repair_identifiers($callRepo, $id, $before)) {
                    $out('  #' . $id . ' repaired the call ids / job id from the stored CDR');
                }

                $result = $processor->process($id, true);
                $row = $callRepo->find($id) ?? [];
                $has = (int) ($row['has_transcript'] ?? 0) === 1;

                if ($has) {
                    $fixed++;
                }

                $out(sprintf(
                    '  #%-6d %-8s transcript %s%s',
                    $id,
                    $result,
                    $has ? 'stored' : 'still missing',
                    (string) ($row['last_error'] ?? '') !== '' ? '  (' . Str::limit((string) $row['last_error'], 80) . ')' : '',
                ));
            }

            $out(sprintf('%d of %d transcript(s) recovered.', $fixed, count($ids)));
            break;

        case 'subscription:create':
            /** @var SettingsRepository $settings */
            $settings = $container->get(SettingsRepository::class);
            $secret = (string) $config->get('ingest.webhook_secret', '');
            if ($secret === '') {
                /** @var Database $db */
                $db = $container->get(Database::class);
                $secret = (string) ($db->first('SELECT value FROM settings WHERE key = :k', ['k' => 'webhook_secret'])['value'] ?? '');
            }

            if ($secret === '') {
                $fail('WEBHOOK_SECRET is not set. Add it to .env (long random string) and retry.');
            }

            $postUrl = rtrim((string) $config->get('app.url'), '/') . '/webhook/netsapiens/' . rawurlencode($secret);
            if (!str_starts_with($postUrl, 'https://') && $option('allow-http') === null) {
                $fail('NetSapiens requires a public HTTPS post-url. Current app.url gives: ' . $postUrl . ' (use --allow-http for a lab server).');
            }

            $payload = [
                'model' => 'cdr',
                'post-url' => $postUrl,
                'domain' => $option('scope', 'all'),
                'user' => '*',
                'subscription-geo-support' => 'yes',
            ];

            /** @var NsGateway $ns */
            $ns = $container->get(NsGateway::class);
            $response = $ns->createSubscription($payload);
            $id = (string) ($response['id'] ?? $response['subscription-id'] ?? '');

            if ($id === '') {
                $fail('NetSapiens did not return a subscription id: ' . json_encode($response));
            }

            $settings->set('webhook_subscription_id', $id);
            $settings->set('webhook_created_at', Time::now());
            Logger::info('console.subscription_created', ['id' => $id]);
            $out('Subscription ' . $id . ' created. Posts go to ' . $postUrl);
            break;

        case 'test-mail':
            $to = (string) $option('to', '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $fail('Usage: php private/bin/console.php test-mail --to=you@example.com');
            }

            /** @var Mailer $mailer */
            $mailer = $container->get(Mailer::class);
            if (!$mailer->configured()) {
                $fail('SMTP is not configured. Missing: ' . implode(', ', $mailer->missingSettings()));
            }

            $mailer->sendTest($to);
            $out('Test email sent to ' . $to . '.');
            break;

        default:
            $fail('Unknown command "' . $command . '". Run without arguments for the list.', 2);
    }
} catch (NsException $e) {
    Logger::warning('console.ns_error', ['command' => $command, 'error' => $e->getMessage()]);
    $fail('NetSapiens error: ' . $e->getMessage());
} catch (MailException $e) {
    Logger::warning('console.mail_error', ['command' => $command, 'error' => $e->getMessage()]);
    $fail('Mail error: ' . $e->getMessage());
} catch (Throwable $e) {
    Logger::exception($e, 'console.' . $command);
    $fail('Error: ' . $e->getMessage() . PHP_EOL . '  at ' . $e->getFile() . ':' . $e->getLine());
}

exit(0);
