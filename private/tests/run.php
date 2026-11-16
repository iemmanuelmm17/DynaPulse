<?php

declare(strict_types=1);

/**
 * DynaPulse test-suite (no external dependencies).
 *
 *   php private/tests/run.php
 *
 * Boots a throwaway SQLite database under storage/tmp, wires the real services
 * against the in-memory NetSapiens double and exercises the paths that matter:
 * CDR mapping, keyword matching, signing, ingestion → processing → alert queue.
 */

use DynaPulse\Database\Database;
use DynaPulse\Database\Migrator;
use DynaPulse\Http\Container;
use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\NetSapiens\CdrMapper;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\NetSapiens\Transcription;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\NotificationRepository;
use DynaPulse\Repository\RecipientRepository;
use DynaPulse\Service\CallProcessor;
use DynaPulse\Service\IngestService;
use DynaPulse\Service\KeywordMatcher;
use DynaPulse\Service\NsDiagnostics;
use DynaPulse\Service\Signer;
use DynaPulse\Service\XlsxWriter;
use DynaPulse\Support\Config;
use DynaPulse\Support\Env;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;
use DynaPulse\Tests\Support\FakeNs;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'autoload.php';

$root = dirname(__DIR__);

foreach (['storage', 'storage/logs', 'storage/tmp'] as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

$databaseFile = $root . '/storage/tmp/tests-' . getmypid() . '.sqlite';
@unlink($databaseFile);

$config = Config::fromEnvironment($root);
$config->set('app.key', str_repeat('t', 48));
$config->set('app.timezone', 'UTC');
$config->set('app.name', 'DynaPulse');
$config->set('app.url', 'https://dynapulse.test');
$config->set('db.database', $databaseFile);
$config->set('paths.storage', $root . '/storage');
$config->set('paths.logs', $root . '/storage/logs');
$config->set('paths.tmp', $root . '/storage/tmp');
$config->set('paths.views', $root . '/views');
$config->set('paths.public', dirname($root) . '/public');
$config->set('ingest.process_inline', true);

Context::$config = $config;

Logger::boot($root . '/storage/logs', 'error');

$db = new Database($databaseFile);
$migrator = new Migrator($db, $root . DIRECTORY_SEPARATOR . 'migrations');
$migrator->run();

$fakeNs = new FakeNs();

$container = new Container();
$container->set(Config::class, $config);
$container->set(Database::class, $db);
$container->set(NsGateway::class, $fakeNs);
$container->set(Migrator::class, $migrator);
$container->set(View::class, new View($root . DIRECTORY_SEPARATOR . 'views'));

/* ------------------------------------------------------------------------- */
/* tiny harness                                                              */
/* ------------------------------------------------------------------------- */

$passed = 0;
$failed = 0;
$failures = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed, $failures;

    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  ok    {$name}" . PHP_EOL);
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        fwrite(STDOUT, "  FAIL  {$name}" . PHP_EOL . '        ' . $e->getMessage() . PHP_EOL);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '') . 'missing "' . $needle . '"');
    }
}

fwrite(STDOUT, 'DynaPulse tests' . PHP_EOL . str_repeat('-', 60) . PHP_EOL);

/* ------------------------------------------------------------------------- */
/* support                                                                   */
/* ------------------------------------------------------------------------- */

test('Time converts UTC to a domain timezone', function (): void {
    assertSame('Jan 15, 2026 7:00 AM', Time::display('2026-01-15 12:00:00', 'America/New_York'));
    assertSame('202601', Time::yearMonth('2026-01-15 12:00:00', 'America/New_York'));
});

test('Str phone and email helpers', function (): void {
    assertContains('555', Str::prettyPhone('+14155550123'));
    assertSame('4155550123', Str::phoneFromUri('sip:4155550123@acme.com'));
    assertSame(['a@x.com', 'b@y.com'], Str::splitEmails('A@x.com, b@y.com; not-an-email'));
    assertTrue(Str::severityRank('critical') > Str::severityRank('high'), 'severity ranks must be ordered');
});

test('.env values that hold only a comment stay empty', function (): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dynapulse-env-' . getmypid() . '.env';
    file_put_contents($file, implode(PHP_EOL, [
        '# leading comment',
        'APP_KEY=                # REQUIRED: generate me',
        'APP_NAME="Dyna Pulse"',
        'MAIL_HOST=smtp.office365.com   # trailing note',
        'POLL_ENABLED=true',
    ]));

    try {
        Env::load($file);
        assertSame('', (string) Env::get('APP_KEY', ''), 'a comment must not become the value');
        assertSame('Dyna Pulse', Env::get('APP_NAME'));
        assertSame('smtp.office365.com', Env::get('MAIL_HOST'));
        assertSame('true', Env::get('POLL_ENABLED'));
    } finally {
        @unlink($file);
    }
});

test('KeywordMatcher: phrase, whole word, regex, case, snippets', function (): void {
    $matcher = new KeywordMatcher();
    $call = [
        'transcript_summary' => 'The customer asked for a refund and mentioned a chargeback.',
        'transcript_topics' => ['Billing', 'Refund'],
        'transcript_text' => 'Agent: I can process that refund today.',
        'transcript_segments' => [],
        'caller_number' => '+14155550123',
        'called_number' => '+18005550100',
    ];

    $matches = $matcher->match([
        ['id' => 1, 'label' => 'Refund', 'pattern' => 'refund', 'match_type' => 'phrase', 'severity' => 'high', 'fields' => ['summary'], 'case_sensitive' => 0],
        ['id' => 2, 'label' => 'Chargeback', 'pattern' => 'chargeback', 'match_type' => 'word', 'severity' => 'critical', 'fields' => ['summary'], 'case_sensitive' => 0],
        ['id' => 3, 'label' => 'Churn risk', 'pattern' => '/cancel|churn/i', 'match_type' => 'regex', 'severity' => 'medium', 'fields' => ['summary', 'transcript'], 'case_sensitive' => 0],
        ['id' => 4, 'label' => 'Caller', 'pattern' => '4155550123', 'match_type' => 'phrase', 'severity' => 'low', 'fields' => ['caller_number'], 'case_sensitive' => 0],
        ['id' => 5, 'label' => 'Miss', 'pattern' => 'nonsense', 'match_type' => 'phrase', 'severity' => 'low', 'fields' => ['summary'], 'case_sensitive' => 0],
    ], $call);

    $labels = array_column($matches, 'keyword_label');
    assertTrue(in_array('Refund', $labels, true), 'phrase should match');
    assertTrue(in_array('Chargeback', $labels, true), 'whole word should match');
    assertTrue(in_array('Caller', $labels, true), 'phone field should match');
    assertTrue(!in_array('Miss', $labels, true), 'unmatched pattern must not match');

    assertSame(null, $matcher->search('refund', 'phrase', 'REFUND policy', true), 'case sensitive search must not match lower case');
    assertTrue($matcher->search('refund', 'phrase', 'REFUND policy', false) !== null, 'case insensitive search should match');
    assertTrue($matcher->isValidRegex('/refund|chargeback/i'), 'valid regex accepted');
    assertTrue(!$matcher->isValidRegex('/(unclosed/'), 'invalid regex rejected');
});

test('CdrMapper normalises a v2 CDR', function (): void {
    $cdr = [
        'call-orig-call-id' => 'call-123',
        'call-term-call-id' => 'call-123-term',
        'call-orig-from-user' => '4155550123',
        'call-orig-from-name' => 'Jane Doe',
        'call-orig-to-user' => '8005550100',
        'call-orig-domain' => 'carrier.example',
        'call-term-domain' => 'acme.test',
        'call-term-user' => 'Dana Support',
        'call-start-datetime' => '2026-02-03T15:04:05Z',
        'call-answer-datetime' => '2026-02-03T15:04:10Z',
        'call-disconnect-datetime' => '2026-02-03T15:07:05Z',
        'call-total-duration-seconds' => '180',
        'call-talking-duration-seconds' => '150',
        'call-direction' => 'Inbound',
        'call-intelligence-job-id' => 'job-1',
        'call-intelligence-percent-positive' => '20',
        'call-intelligence-percent-neutral' => '30',
        'call-intelligence-percent-negative' => '50',
        'call-intelligence-topics-top' => ['Billing', 'Refund'],
    ];

    $mapped = CdrMapper::map($cdr, 'acme.test');

    assertSame('call-123', $mapped['call_id']);
    assertSame('Inbound', $mapped['direction']);
    assertSame('4155550123', $mapped['caller_number']);
    assertSame('8005550100', $mapped['called_number']);
    assertSame(180, $mapped['duration_seconds']);
    assertSame('2026-02-03 15:04:05', $mapped['started_at']);
    assertSame('job-1', $mapped['transcript_job_id']);
    assertSame(50, $mapped['sentiment_negative']);
    assertSame(['Billing', 'Refund'], $mapped['topics_hint']);
});

test('Signer round-trips, rejects tampering and expiry', function () use ($config): void {
    $signer = new Signer($config);
    $params = ['call' => '42', 'domain' => 'acme.test'];
    $signed = $signer->sign($params, 60);

    assertTrue($signer->verify($signed), 'fresh signature must verify');

    $tampered = $signed;
    $tampered['call'] = '43';
    assertTrue(!$signer->verify($tampered), 'tampered payload must fail');

    $expired = $signer->sign($params, -120);
    assertTrue(!$signer->verify($expired), 'expired signature must fail');
});

test('XlsxWriter produces a valid zip container', function (): void {
    if (!XlsxWriter::available()) {
        return;
    }

    $binary = XlsxWriter::build('Calls', ['Call', 'Duration'], [['call-1', '180'], ['call-2', '42']], [30, 12]);

    assertTrue(strlen($binary) > 300, 'workbook should not be empty');
    assertSame("PK\x03\x04", substr($binary, 0, 4), 'workbook must start with a zip header');
});

test('Ingestion → processing → alert queue end to end', function () use ($container, $fakeNs): void {
    /** @var DomainRepository $domains */
    $domains = $container->get(DomainRepository::class);
    $domain = $domains->ensure('acme.test', 'Acme', 'America/New_York');
    $domainId = (int) $domain['id'];

    /** @var KeywordRepository $keywords */
    $keywords = $container->get(KeywordRepository::class);
    $keywords->create([
        'domain_id' => $domainId,
        'label' => 'Refund request',
        'pattern' => 'refund',
        'match_type' => 'phrase',
        'fields' => ['summary', 'transcript'],
        'severity' => 'high',
    ]);

    /** @var RecipientRepository $recipients */
    $recipients = $container->get(RecipientRepository::class);
    $recipients->upsert($domainId, 'manager@acme.test', 'Manager');

    $cdr = [
        'call-orig-call-id' => 'e2e-call-1',
        'call-term-call-id' => 'e2e-call-1-term',
        'call-orig-from-user' => '4155550199',
        'call-orig-from-name' => 'Test Caller',
        'call-orig-to-user' => '8005550111',
        'call-orig-domain' => 'carrier.example',
        'call-term-domain' => 'acme.test',
        'call-term-user' => 'Support Desk',
        'call-start-datetime' => '2026-02-04T10:00:00Z',
        'call-answer-datetime' => '2026-02-04T10:00:05Z',
        'call-disconnect-datetime' => '2026-02-04T10:03:00Z',
        'call-total-duration-seconds' => 180,
        'call-talking-duration-seconds' => 170,
        'call-intelligence-job-id' => 'job-e2e',
    ];

    $fakeNs->cdrFixtures['acme.test'] = [$cdr];
    $fakeNs->transcriptionFixtures['acme.test:job-e2e'] = [
        'call-intelligence-processing-status' => 'finished',
        'call-intelligence-summary' => 'Caller wants a refund for a duplicate charge.',
        'call-intelligence-topics-top' => ['Billing', 'Refund'],
        'call-intelligence-percent-positive' => 10,
        'call-intelligence-percent-neutral' => 20,
        'call-intelligence-percent-negative' => 70,
        'call-intelligence-segments' => [
            ['speaker' => 'Caller', 'comment' => 'I need a refund for this duplicate charge.', 'sentiment' => 'Negative', 'start-time' => '0', 'end-time' => '3'],
            ['speaker' => 'Agent', 'comment' => 'I can process that refund right away.', 'sentiment' => 'Positive', 'start-time' => '4', 'end-time' => '7'],
        ],
    ];
    $fakeNs->recordingFixtures['acme.test:e2e-call-1'] = [
        'file-access-url' => 'https://media.example/rec/e2e-call-1.mp3',
        'file-duration-seconds' => 180,
        'call-recording-status' => 'available',
        'file-size-kilobytes' => 2048,
    ];

    /** @var IngestService $ingest */
    $ingest = $container->get(IngestService::class);
    $result = $ingest->ingestCdr('acme.test', $cdr, false);
    assertSame('queued', $result['status'], 'CDR should be queued');

    /** @var CallRepository $calls */
    $calls = $container->get(CallRepository::class);
    $stored = $calls->findByCallId($domainId, 'e2e-call-1');
    assertTrue($stored !== null, 'call must be stored');

    /** @var CallProcessor $processor */
    $processor = $container->get(CallProcessor::class);
    $outcome = $processor->process((int) $stored['id'], true);
    assertSame('complete', $outcome, 'call should process completely');

    $fresh = $calls->find((int) $stored['id']);
    assertSame('complete', $fresh['status']);
    assertSame(1, (int) $fresh['has_transcript']);
    assertSame(1, (int) $fresh['has_recording']);
    assertContains('refund', (string) $fresh['transcript_summary']);
    assertSame(70, (int) $fresh['sentiment_negative']);
    assertSame('queued', $fresh['alert_status'], 'alert should be queued for the recipient');

    /** @var MatchRepository $matches */
    $matches = $container->get(MatchRepository::class);
    $found = $matches->forCall((int) $stored['id']);
    assertSame(1, count($found), 'exactly one keyword match');
    assertSame('Refund request', $found[0]['keyword_label']);

    /** @var NotificationRepository $notifications */
    $notifications = $container->get(NotificationRepository::class);
    $queued = $notifications->forCall((int) $stored['id']);
    assertSame(1, count($queued), 'one grouped email per call');
    assertSame('queued', $queued[0]['status']);
    assertContains('manager@acme.test', (string) $queued[0]['recipients']);

    // Re-ingesting the same CDR must not create a second call.
    $ingest->ingestCdr('acme.test', $cdr, false);
    /** @var \DynaPulse\Database\Database $database */
    $database = $container->get(\DynaPulse\Database\Database::class);
    $counts = $database->first('SELECT COUNT(*) AS total FROM calls WHERE domain_id = :d', ['d' => $domainId]);
    assertSame(1, (int) ($counts['total'] ?? 0), 'duplicate CDRs must merge on the call id');
});

/* ------------------------------------------------------------------------- */
/* Call Intelligence (real NetSapiens payloads)                              */
/* ------------------------------------------------------------------------- */

/** The transcription payload NetSapiens returns for a finished job. */
$transcriptionSample = static fn (): array => [
    'call-intelligence-job-id' => 896968254,
    'call-intelligence-processing-status' => 'finished',
    'created-datetime' => '2026-11-16T15:31:45+00:00',
    'call-id' => '0_4121119925@172.16.10.205',
    'call-intelligence-features-enabled' => 'transcription,sentiment,summary,topics',
    'call-intelligence-summary' => 'Hello. No 1 is available to take your call at this time. Please leave a message. Thank you.',
    'call-intelligence-topics-top' => ['phone call', 'voicemail'],
    'call-intelligence-duration-seconds' => 10.39,
    'call-intelligence-percent-negative' => 100,
    'call-intelligence-percent-neutral' => 0,
    'call-intelligence-percent-positive' => 0,
    'call-intelligence-segments' => [
        [
            'speaker' => 'sip:14086988652@66.85.57.10',
            'comment' => 'Hello. No 1 is available to take your call at this time. Please leave a message. Thank you. To dial another extension, press 1.',
            'sentiment' => 'Negative',
            'start-time' => '00:00:00,24',
            'end-time' => '00:00:10,63',
        ],
    ],
    'call-intelligence-summary-status' => 'success',
    'call-intelligence-topics-all' => ['phone call', 'voicemail'],
    'errors' => null,
    'last-modified-datetime' => '2026-11-16T15:31:58+00:00',
];

test('Transcription::fromApi reads a real Call Intelligence payload', function () use ($transcriptionSample): void {
    $transcription = Transcription::fromApi($transcriptionSample());

    assertSame('finished', $transcription->status);
    assertSame(1, count($transcription->segments), 'one segment');
    assertSame(['phone call', 'voicemail'], $transcription->topics);
    assertContains('available to take your call', $transcription->text);
    assertContains('available to take your call', $transcription->summary);
    assertSame(0, $transcription->positive);
    assertSame(100, $transcription->negative);
    assertSame('negative', $transcription->label);
    assertContains('00:00:00,24', $transcription->transcriptForExport());

    $columns = $transcription->toColumns();
    assertSame(1, $columns['has_transcript']);
    assertSame('finished', $columns['transcript_status']);
    assertContains('voicemail', (string) $columns['transcript_topics']);
    assertContains('To dial another extension', (string) $columns['transcript_text']);
});

test('The transcript lookup uses the CDR orig/term call ids', function () use ($container, $fakeNs, $transcriptionSample): void {
    /** @var DomainRepository $domains */
    $domains = $container->get(DomainRepository::class);
    $domains->ensure('trans.test', 'Trans', 'UTC');

    $origCallId = '768181475@172.16.10.228';
    $termCallId = '20261116153414064248-cac18a7984653a998c54034ec629e12c';

    $fakeNs->transcriptionFixtures['trans.test:112174179'] = $transcriptionSample();

    $cdr = [
        'call-orig-call-id' => $origCallId,
        'call-term-call-id' => $termCallId,
        'call-orig-from-user' => 1003,
        'call-orig-caller-id' => 8773962546,
        'call-orig-to-user' => 83838,
        'call-term-user' => 6001,
        'call-orig-domain' => 'trans.test',
        'call-term-domain' => 'trans.test',
        'call-direction' => 3,
        'call-start-datetime' => '2026-11-16T15:34:14+00:00',
        'call-answer-datetime' => '2026-11-16T15:34:16+00:00',
        'call-disconnect-datetime' => '2026-11-16T15:34:23+00:00',
        'call-total-duration-seconds' => 7,
        'call-talking-duration-seconds' => 7,
        'call-intelligence-job-id' => 112174179,
    ];

    /** @var IngestService $ingest */
    $ingest = $container->get(IngestService::class);
    $result = $ingest->ingestCdr('trans.test', $cdr, true);

    $queries = $fakeNs->transcriptionQueries;
    $last = end($queries);
    assertTrue(is_array($last), 'the transcription endpoint was called');
    assertSame($origCallId, $last['orig'], 'orig call id is sent');
    assertSame($termCallId, $last['term'], 'term call id is sent');
    assertSame('202611', $last['date'], 'date is YYYYMM of the call');

    /** @var CallRepository $calls */
    $calls = $container->get(CallRepository::class);
    $row = $calls->find((int) $result['call_id']);
    assertTrue($row !== null, 'call stored');
    assertSame(1, (int) $row['has_transcript'], 'transcript stored');
    assertSame('finished', (string) $row['transcript_status']);
    assertTrue(in_array('voicemail', (array) $row['transcript_topics'], true), 'topics stored');
    assertSame('negative', (string) $row['sentiment_label']);
    assertContains('To dial another extension', (string) $row['transcript_text']);
});

/* ------------------------------------------------------------------------- */
/* NetSapiens diagnostics ("why are there no calls?")                        */
/* ------------------------------------------------------------------------- */

test('NsDiagnostics reports what NetSapiens returns per domain', function () use ($container, $config, $fakeNs): void {
    $domains = $container->get(DomainRepository::class);
    $domains->ensure('diag.test', 'Diag', 'UTC');
    $domains->ensure('quiet.test', 'Quiet', 'UTC');

    $fakeNs->cdrFixtures['diag.test'] = [[
        'call-orig-call-id' => 'diag-1',
        'call-orig-from-user' => '4155550100',
        'call-orig-to-user' => '8005550100',
        'call-start-datetime' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
        'call-total-duration-seconds' => 42,
    ]];

    $config->set('ns.base_url', 'https://ns.example.test');
    $config->set('ns.api_key', 'abcdef0123456789');
    $config->set('ns.client_id', 'client');
    $config->set('ns.client_secret', 'secret');

    $report = (new NsDiagnostics($fakeNs, $domains, $config))->run(24);

    $text = implode("\n", $report['lines']);
    assertContains('GET /domains', $text);
    assertContains('diag.test', $text);
    assertContains('1 CDR row(s) returned', $text);
    assertContains('sample CDR', $text);
    // The key itself must never be printed in full.
    assertTrue(!str_contains($text, 'abcdef0123456789'), 'the API key must be masked');
    assertTrue(str_contains($text, '********6789'), 'only the last 4 chars are shown');

    // quiet.test has no fixtures: its window is empty, so the report must say so.
    assertContains('0 CDR row(s) returned', $text);
    assertTrue(!$report['ok'], 'a domain without CDRs is not a green result');
});

test('NsDiagnostics explains a rejected key', function () use ($container, $config): void {
    $domains = $container->get(DomainRepository::class);
    $config->set('ns.base_url', 'https://ns.example.test');
    $config->set('ns.api_key', 'bad-key');

    $ns = new FakeNs();
    $ns->domainsError = new \DynaPulse\NetSapiens\NsException('NetSapiens rejected the API credentials (HTTP 401).', 401, '{"message":"Unauthorized"}');

    $report = (new NsDiagnostics($ns, $domains, $config))->run(24);
    $text = implode("\n", $report['lines']);

    assertTrue(!$report['ok'], 'a 401 must fail the diagnostic');
    assertContains('HTTP 401', $text);
    assertContains('Unauthorized', $text);
    assertContains('key was rejected', $text);

    // Missing configuration short-circuits before any HTTP call.
    $config->set('ns.base_url', '');
    $config->set('ns.api_key', '');
    $report = (new NsDiagnostics($ns, $domains, $config))->run(24);
    assertTrue(!$report['ok'], 'nothing configured means nothing can be pulled');
    assertContains('NS_BASE_URL and NS_API_KEY must be set', implode("\n", $report['lines']));
});

/* ------------------------------------------------------------------------- */
/* sub-folder deployment (/dynapulse/public)                                 */
/* ------------------------------------------------------------------------- */

test('Request strips the sub-folder prefix before routing', function (): void {
    assertSame('', Context::normaliseBasePath('/'));
    assertSame('', Context::normaliseBasePath(''));
    assertSame('/dynapulse/public', Context::normaliseBasePath('/dynapulse/public/'));
    assertSame('/dynapulse/public', Context::normaliseBasePath('/dynapulse/public/index.php'));
    assertSame('/DynaPulse/Public', Context::normaliseBasePath('\\DynaPulse\\Public\\'));

    assertSame('/calls/12', Request::stripBasePath('/dynapulse/public/calls/12', '/dynapulse/public'));
    assertSame('/', Request::stripBasePath('/dynapulse/public', '/dynapulse/public'));
    assertSame('/calls', Request::stripBasePath('/calls', ''));
    assertSame('/public/calls', Request::stripBasePath('/public/calls', '/dynapulse/public'));

    $request = new Request('GET', '/calls', [], [], [], [], [], '', '/dynapulse/public');
    assertSame('/calls', $request->path());
    assertSame('/dynapulse/public', $request->basePath());
});

test('Request detects the sub-folder from the server variables', function (): void {
    $server = $_SERVER;

    try {
        $_SERVER['DOCUMENT_ROOT'] = '/srv/www';
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/dynapulse/public/index.php';
        $_SERVER['SCRIPT_NAME'] = '/dynapulse/public/index.php';
        $_SERVER['PHP_SELF'] = '/dynapulse/public/index.php';

        assertSame('/dynapulse/public', Request::detectBasePath('/dynapulse/public/calls'));
        assertSame('/dynapulse/public', Request::detectBasePath('/dynapulse/public/'));

        // Site root deployment: nothing is stripped.
        $_SERVER['DOCUMENT_ROOT'] = '/srv/www/dynapulse/public';
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/dynapulse/public/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        assertSame('', Request::detectBasePath('/calls'));

        // IIS sometimes echoes the routed URL in SCRIPT_NAME; that must not be
        // mistaken for a prefix.
        $_SERVER['DOCUMENT_ROOT'] = '';
        $_SERVER['SCRIPT_FILENAME'] = '';
        $_SERVER['SCRIPT_NAME'] = '/login';
        $_SERVER['PHP_SELF'] = '/login';
        assertSame('', Request::detectBasePath('/login'));
    } finally {
        $_SERVER = $server;
    }
});

test('Links, assets and redirects keep the sub-folder', function (): void {
    Context::setBasePath('/dynapulse/public');

    try {
        assertSame('/dynapulse/public/', path_url(''));
        assertSame('/dynapulse/public/login', path_url('login'));
        assertSame('/dynapulse/public/login', Response::redirect('/login')->headers()['Location']);
        assertSame('/dynapulse/public/calls', Response::redirect('/dynapulse/public/calls')->headers()['Location']);
        assertSame('https://elsewhere.test/x', Response::redirect('https://elsewhere.test/x')->headers()['Location']);
        assertContains('/dynapulse/public/assets/css/app.css', asset('css/app.css'));
    } finally {
        Context::setBasePath('');
    }

    assertSame('/login', Response::redirect('/login')->headers()['Location']);
    assertSame('/', path_url(''));
});

/* ------------------------------------------------------------------------- */
/* result                                                                    */
/* ------------------------------------------------------------------------- */

@unlink($databaseFile);

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
fwrite(STDOUT, sprintf('%d passed, %d failed' . PHP_EOL, $passed, $failed));

if ($failed > 0) {
    fwrite(STDOUT, PHP_EOL . 'Failures:' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDOUT, '  - ' . $failure . PHP_EOL);
    }
    exit(1);
}

exit(0);
