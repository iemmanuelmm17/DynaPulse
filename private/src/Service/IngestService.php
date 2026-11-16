<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\NetSapiens\CdrMapper;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Time;

/**
 * Two ingestion paths into the same table:
 *   - webhook  : NetSapiens "cdr" event subscription posts completed calls
 *   - polling  : the CDR endpoint is queried on a schedule (safety net)
 * Both converge on calls.status = pending, which the worker then processes.
 */
final class IngestService
{
    public function __construct(
        private NsGateway $ns,
        private DomainRepository $domains,
        private CallRepository $calls,
        private SettingsRepository $settings,
        private CallProcessor $processor,
    ) {
    }

    /**
     * @param array<string, mixed> $cdr
     * @return array{status:string, call_id:?int, domain:string, message:string}
     */
    public function ingestCdr(string $domainName, array $cdr, ?bool $processInline = null): array
    {
        $domainName = strtolower(trim($domainName));

        if ($domainName === '') {
            $domainName = strtolower(trim((string) ($cdr['call-orig-domain'] ?? $cdr['call-term-domain'] ?? '')));
        }

        if ($domainName === '') {
            return ['status' => 'ignored', 'call_id' => null, 'domain' => '', 'message' => 'No domain on payload.'];
        }

        $exactDomain = self::exactDomainSpelling($cdr, $domainName);
        $domain = $this->domains->findByDomain($domainName);

        // The stored name may be a lower-cased copy of the real one; NetSapiens
        // rejects the lower-cased spelling on some endpoints (transcriptions).
        if ($domain !== null && $exactDomain !== '' && (string) $domain['domain'] !== $exactDomain) {
            $this->domains->adoptDomainName((int) $domain['id'], $exactDomain);
            $domain['domain'] = $exactDomain;
            Logger::info('ingest.domain_spelling_adopted', ['domain' => $exactDomain]);
        }

        // The posted leg may be the external carrier (inbound: orig) while the
        // domain DynaPulse knows is on the other leg. Try the sibling leg before
        // declaring the CDR foreign.
        if ($domain === null) {
            $candidates = [
                strtolower(trim((string) ($cdr['call-term-domain'] ?? ''))),
                strtolower(trim((string) ($cdr['call-orig-domain'] ?? ''))),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate === '' || $candidate === $domainName) {
                    continue;
                }

                $found = $this->domains->findByDomain($candidate);
                if ($found !== null) {
                    $domainName = $candidate;
                    $domain = $found;
                    break;
                }
            }
        }

        if ($domain === null) {
            if (!$this->settings->bool('auto_import_domains')) {
                return ['status' => 'ignored', 'call_id' => null, 'domain' => $domainName, 'message' => 'Domain is not configured.'];
            }

            $domain = $this->domains->ensure(
                $exactDomain !== '' ? $exactDomain : $domainName,
                $exactDomain !== '' ? $exactDomain : $domainName,
                (string) \DynaPulse\Http\Context::config('app.timezone', 'UTC'),
            );
            Logger::info('ingest.domain_imported', ['domain' => $domainName]);
        }

        if ((int) ($domain['enabled'] ?? 1) !== 1) {
            return ['status' => 'ignored', 'call_id' => null, 'domain' => $domainName, 'message' => 'Domain is disabled.'];
        }

        $mapped = CdrMapper::map($cdr, $domainName);
        $existing = $this->calls->findByCallId((int) $domain['id'], (string) $mapped['call_id']);

        // Re-polling with raw=yes replaces our synthetic "cdr-<hash>" call id with
        // the real one. Match the existing row by job id and re-key it, so the
        // same call is not stored twice and its id in the UI stays valid.
        if ($existing === null && (string) ($mapped['transcript_job_id'] ?? '') !== '') {
            $byJob = $this->calls->findByJobId((int) $domain['id'], (string) $mapped['transcript_job_id']);

            if ($byJob !== null) {
                $this->calls->adoptMapped((int) $byJob['id'], $mapped);
                $existing = $byJob;
                Logger::info('ingest.call_id_adopted', [
                    'call_id' => $byJob['id'],
                    'from' => (string) $byJob['call_id'],
                    'to' => (string) $mapped['call_id'],
                ]);
            }
        }

        $call = $this->calls->upsertFromCdr((int) $domain['id'], $mapped);

        $callId = (int) ($call['id'] ?? 0);
        $isNew = $existing === null;

        Logger::info('ingest.cdr', [
            'domain' => $domainName,
            'call_id' => $mapped['call_id'],
            'new' => $isNew,
            'job_id' => $mapped['transcript_job_id'],
        ]);

        $inline = $processInline ?? (bool) \DynaPulse\Http\Context::config('ingest.process_inline', true);

        if ($inline && $callId > 0 && ($call['status'] ?? 'pending') === 'pending') {
            $result = $this->processor->process($callId);

            return [
                'status' => $result === 'complete' ? 'processed' : ($result === 'retry' ? 'queued-for-retry' : $result),
                'call_id' => $callId,
                'domain' => $domainName,
                'message' => $isNew ? 'New call recorded.' : 'Existing call updated.',
            ];
        }

        return [
            'status' => 'queued',
            'call_id' => $callId,
            'domain' => $domainName,
            'message' => $isNew ? 'New call queued.' : 'Existing call queued.',
        ];
    }

    /**
     * The domain name exactly as NetSapiens spells it, when one of the CDR's
     * domain fields matches the domain we store (case-insensitive).
     *
     * @param array<string, mixed> $cdr
     */
    public static function exactDomainSpelling(array $cdr, string $known): string
    {
        foreach (['call-term-domain', 'call-orig-domain', 'domain'] as $key) {
            $candidate = $cdr[$key] ?? null;

            if (is_string($candidate) && trim($candidate) !== '' && strcasecmp(trim($candidate), $known) === 0) {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @return array{polled:int, errors:list<string>, calls:int, domains:int}
     */
    public function pollAll(?int $hours = null): array
    {
        $summary = ['polled' => 0, 'errors' => [], 'calls' => 0, 'domains' => 0];

        foreach ($this->domains->pollable() as $domain) {
            $summary['domains']++;

            try {
                $result = $this->pollDomain($domain, $hours);
                $summary['calls'] += $result['calls'];
                $summary['polled']++;
                $summary['errors'] = array_merge($summary['errors'], $result['errors']);
            } catch (NsException $e) {
                $summary['errors'][] = $domain['domain'] . ': ' . $e->getMessage();
                $this->domains->touchPolled((int) $domain['id'], $e->getMessage());
                Logger::warning('poll.domain_failed', ['domain' => $domain['domain'], 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $domain
     * @return array{calls:int, new:int, errors:list<string>}
     */
    public function pollDomain(array $domain, ?int $hours = null): array
    {
        $domainName = (string) $domain['domain'];
        $timezone = (string) ($domain['timezone'] ?? 'UTC');
        $lookback = (int) \DynaPulse\Http\Context::config('ingest.lookback_minutes', 10);
        $initialHours = max(1, $hours ?? (int) $this->settings->int('poll_window_hours', 24));

        $now = Time::now();
        $last = $domain['last_polled_at'] ?? null;

        $start = $last !== null && $last !== ''
            ? Time::addMinutes((string) $last, -$lookback)
            : Time::addMinutes($now, -($initialHours * 60));

        $startIso = Time::toLocal($start, $timezone)->format('Y-m-d\TH:i:sP') . '[' . $timezone . ']';
        $endIso = Time::toLocal($now, $timezone)->format('Y-m-d\TH:i:sP') . '[' . $timezone . ']';

        $calls = 0;
        $new = 0;
        $errors = [];
        $pageSize = 500;
        $maxPages = 10;
        $startRow = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $rows = $this->ns->cdrs($domainName, $startIso, $endIso, $pageSize, $startRow);

            foreach ($rows as $cdr) {
                if (!is_array($cdr)) {
                    continue;
                }

                try {
                    $mapped = CdrMapper::map($cdr, $domainName);
                    $existing = $this->calls->findByCallId((int) $domain['id'], (string) $mapped['call_id']);
                    $this->calls->upsertFromCdr((int) $domain['id'], $mapped);
                    $calls++;
                    if ($existing === null) {
                        $new++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'CDR ' . ($cdr['call-orig-call-id'] ?? '?') . ': ' . $e->getMessage();
                    Logger::warning('poll.cdr_failed', ['domain' => $domainName, 'error' => $e->getMessage()]);
                }
            }

            if (count($rows) < $pageSize) {
                break;
            }

            $startRow += $pageSize;
        }

        $this->domains->touchPolled((int) $domain['id'], $errors === [] ? null : implode(' | ', array_slice($errors, 0, 3)));

        Logger::info('poll.domain', [
            'domain' => $domainName,
            'window_start' => $startIso,
            'window_end' => $endIso,
            'calls' => $calls,
            'new' => $new,
            'errors' => count($errors),
        ]);

        return ['calls' => $calls, 'new' => $new, 'errors' => $errors];
    }
}
