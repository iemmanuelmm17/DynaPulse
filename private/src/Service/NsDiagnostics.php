<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\NetSapiens\CdrMapper;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\NetSapiens\TranscriptionReference;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Support\Config;
use DynaPulse\Support\Time;

/**
 * "Why are there no calls?" — answers it step by step:
 *   1. can the API key read /domains at all
 *   2. which local domains exist, and are they actually polled
 *   3. does /domains/{domain}/cdrs return rows for the last N hours
 *
 * Everything is returned as printable lines so the CLI (and the tests) can
 * show exactly what NetSapiens answered.
 */
final class NsDiagnostics
{
    public function __construct(
        private NsGateway $ns,
        private DomainRepository $domains,
        private Config $config,
    ) {
    }

    /** @return array{ok: bool, lines: list<string>} */
    public function run(int $hours = 24, ?string $domain = null): array
    {
        $hours = max(1, min(720, $hours));
        $lines = [];
        $ok = true;

        $baseUrl = rtrim((string) $this->config->get('ns.base_url', ''), '/');
        $apiKey = (string) $this->config->get('ns.api_key', '');
        $clientId = (string) $this->config->get('ns.client_id', '');
        $secret = (string) $this->config->get('ns.client_secret', '');

        $lines[] = 'NetSapiens connectivity test';
        $lines[] = '  NS_BASE_URL    ' . ($baseUrl !== '' ? $baseUrl : '(empty)');
        $lines[] = '  NS_API_KEY     ' . self::mask($apiKey);
        $lines[] = '  NS_CLIENT_ID   ' . ($clientId !== '' ? $clientId : '(empty — nobody can sign in)');
        $lines[] = '  NS_CLIENT_SEC  ' . self::mask($secret);
        $lines[] = '  NS_VERIFY_SSL  ' . ((bool) $this->config->get('ns.verify_ssl', true) ? 'true' : 'false');
        $lines[] = '';

        if ($baseUrl === '' || $apiKey === '') {
            $lines[] = 'NS_BASE_URL and NS_API_KEY must be set in .env — nothing can be pulled without them.';

            return ['ok' => false, 'lines' => $lines];
        }

        /* ---------------------------------------------------------- /domains */
        $lines[] = '[1/3] GET /domains  (does the API key work?)';

        try {
            $remote = $this->ns->domains(200);
        } catch (NsException $e) {
            $lines[] = '      FAILED — ' . $e->getMessage();
            $lines[] = '      body: ' . self::snippet($e->responseBody);
            $lines[] = '';
            $lines[] = '      ' . match (true) {
                $e->status === 401, $e->status === 403 => 'The key was rejected. Check NS_API_KEY, and that the key may read /domains.',
                $e->status === 404 => 'URL wrong: NS_BASE_URL must be the API root, e.g. https://ns-api.provider.com/ns-api/v2',
                $e->status === 0 => 'No HTTP response: DNS, firewall or TLS problem from this server (NS_VERIFY_SSL=false for a self-signed lab cert).',
                default => 'NetSapiens answered HTTP ' . $e->status . '.',
            };

            return ['ok' => false, 'lines' => $lines];
        }

        $names = [];
        foreach ($remote as $row) {
            $name = trim((string) ($row['domain'] ?? $row['domain-name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $lines[] = '      ok — ' . count($remote) . ' domain(s) visible to this key';
        if ($names !== []) {
            $lines[] = '      ' . implode(', ', array_slice($names, 0, 12)) . (count($names) > 12 ? ', ...' : '');
        }

        /* ---------------------------------------------------- local domains */
        $lines[] = '';
        $lines[] = '[2/3] Local domains (what the poller iterates over)';

        $local = $this->domains->all();
        if ($local === []) {
            $lines[] = '      none configured — import them with:  php bin/console.php domains:sync';
            $ok = false;
        }

        $probe = [];
        foreach ($local as $row) {
            $name = (string) $row['domain'];
            $enabled = (int) ($row['enabled'] ?? 0) === 1;
            $polling = (int) ($row['poll_enabled'] ?? 0) === 1;
            $error = trim((string) ($row['last_poll_error'] ?? ''));
            $lastPoll = (string) ($row['last_polled_at'] ?? '');

            $lines[] = sprintf(
                '      %-30s %-9s poll %-4s calls %-5d last poll %s',
                $name,
                $enabled ? 'enabled' : 'DISABLED',
                $polling ? 'on' : 'off',
                (int) ($row['call_count'] ?? 0),
                $lastPoll !== '' ? $lastPoll : 'never',
            );

            if ($error !== '') {
                $lines[] = '        last error: ' . $error;
            }

            if ($enabled && $polling) {
                $probe[] = $name;
            }
        }

        if ($domain !== null && $domain !== '') {
            $probe = [$domain];
        }

        if ($probe === []) {
            $lines[] = '';
            $lines[] = '      No pollable domain (enabled + polling on), so `poll` has nothing to fetch.';
            $lines[] = '      Probe one explicitly with --domain=your.domain';

            return ['ok' => false, 'lines' => $lines];
        }

        /* ------------------------------------------------------ CDR window */
        $lines[] = '';
        $lines[] = '[3/3] GET /domains/{domain}/cdrs  (last ' . $hours . 'h)';

        $totalRows = 0;

        foreach ($probe as $name) {
            $row = $this->domains->findByDomain($name);
            $timezone = (string) ($row['timezone'] ?? $this->config->get('app.timezone', 'UTC'));

            $now = Time::now();
            $startIso = Time::toLocal(Time::addMinutes($now, -($hours * 60)), $timezone)->format('Y-m-d\TH:i:sP') . '[' . $timezone . ']';
            $endIso = Time::toLocal($now, $timezone)->format('Y-m-d\TH:i:sP') . '[' . $timezone . ']';

            $lines[] = '      ' . $name;
            $lines[] = '        window ' . $startIso . '  ->  ' . $endIso;

            try {
                $rows = $this->ns->cdrs($name, $startIso, $endIso, 5, 0);
            } catch (NsException $e) {
                $ok = false;
                $lines[] = '        FAILED — ' . $e->getMessage();
                $lines[] = '        body: ' . self::snippet($e->responseBody);
                $lines[] = '        ' . match (true) {
                    $e->status === 403 => 'The key may not read CDRs for this domain (check the domain scope of the API key).',
                    $e->status === 404 => 'NetSapiens does not know that domain name — it must match the domain exactly as stored in NS.',
                    default => 'See the body above; HTTP 400 there usually means the datetime format was rejected.',
                };

                continue;
            }

            $totalRows += count($rows);
            $lines[] = '        ' . count($rows) . ' CDR row(s) returned';

            if ($rows === []) {
                $ok = false;
                $lines[] = '        Nothing in that window. Check, in this order:';
                $lines[] = '          - calls did complete in the last ' . $hours . 'h on this domain';
                $lines[] = '          - the domain name is spelled exactly like in NetSapiens';
                $lines[] = '          - try a wider window:  php bin/console.php ns:test --hours=168';

                continue;
            }

            $mapped = CdrMapper::map($rows[0], $name);

            $sample = $rows[0];
            $reference = TranscriptionReference::fromCdr($sample);
            $origId = trim((string) ($sample['call-orig-call-id'] ?? ''));
            $termId = trim((string) ($sample['call-term-call-id'] ?? ''));
            $jobId = trim((string) ($sample['call-intelligence-job-id'] ?? ''));

            $lines[] = '        transcript identifiers on that CDR (requested with raw=yes):';
            $lines[] = '          call-orig-call-id  ' . ($origId !== '' ? $origId : '(absent or null)');
            $lines[] = '          call-term-call-id  ' . ($termId !== '' ? $termId : '(absent or null)');
            $lines[] = '          job id             ' . ($jobId !== '' ? $jobId : '(absent or null)');
            $lines[] = '          prefilled link     ' . ($reference !== null
                ? $reference->domain . ' / ' . $reference->jobId . ' / ' . $reference->month
                : '(absent)');

            if ($origId === '' && $termId === '' && $reference === null) {
                $lines[] = '          → NetSapiens returned none of them, so this domain is not producing';
                $lines[] = '            Call Intelligence jobs: enable recording transcription for it.';
            }

            $lines[] = '        sample CDR → call_id ' . $mapped['call_id'];
            $lines[] = '          started  ' . ((string) ($mapped['started_at'] ?? '') !== '' ? (string) $mapped['started_at'] : '(no call-start-datetime!)');
            $lines[] = '          duration ' . (int) $mapped['duration_seconds'] . 's, ' . $mapped['direction'];
            $lines[] = '          parties  ' . $mapped['caller_number'] . ' -> ' . $mapped['called_number'];
        }

        $lines[] = '';
        $lines[] = $totalRows > 0
            ? 'NetSapiens is returning CDRs. Import them with:  php bin/console.php poll ' . $hours
            : 'No CDR rows came back, so `poll` will not add any calls until the window/domain is right.';

        if ($totalRows === 0) {
            $ok = false;
        }

        return ['ok' => $ok, 'lines' => $lines];
    }

    private static function mask(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }

        return str_repeat('*', min(8, max(0, strlen($value) - 4)))
            . substr($value, -4) . '  (' . strlen($value) . ' chars)';
    }

    private static function snippet(mixed $body, int $limit = 320): string
    {
        if ($body === null) {
            return '(empty response)';
        }

        $text = is_string($body) ? $body : (string) json_encode($body);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return '(empty response)';
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }
}
