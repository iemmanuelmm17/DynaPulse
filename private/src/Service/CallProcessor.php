<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\NetSapiens\TranscriptionReference;
use DynaPulse\NetSapiens\Transcription;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Time;

/**
 * Turns a pending call into a reviewable record: pulls Call Intelligence
 * (transcript/summary/topics/sentiment), the recording reference, runs keyword
 * matching and hands off to the alert dispatcher.
 */
final class CallProcessor
{
    public function __construct(
        private NsGateway $ns,
        private CallRepository $calls,
        private DomainRepository $domains,
        private KeywordRepository $keywords,
        private MatchRepository $matches,
        private SettingsRepository $settings,
        private KeywordMatcher $matcher,
        private AlertDispatcher $alerts,
    ) {
    }

    /** @var array<string, string>|null lower-cased domain => NetSapiens' exact spelling */
    private ?array $domainIndex = null;

    /** @return string complete|retry|failed|skipped|missing */
    public function process(int $callId, bool $force = false): string
    {
        $call = $this->calls->find($callId);
        if ($call === null) {
            return 'missing';
        }

        if (!$force && $call['status'] === 'complete') {
            return 'skipped';
        }

        if (!$force && !$this->calls->markProcessing($callId)) {
            return 'skipped';
        }

        $domain = $this->domains->find((int) $call['domain_id']);
        if ($domain === null) {
            $this->calls->saveProcessed($callId, [
                'status' => 'failed',
                'last_error' => 'Domain no longer exists.',
                'next_attempt_at' => null,
            ]);

            return 'failed';
        }

        $maxAttempts = max(1, $this->settings->int('transcript_max_attempts', 8));
        $attempt = (int) $call['attempts'] + 1;
        $timezone = (string) ($domain['timezone'] ?? 'UTC');
        $updates = [];
        $transcriptPending = false;

        // ---- Call Intelligence -------------------------------------------------
        $hasTranscript = (int) $call['has_transcript'] === 1;
        $jobId = trim((string) ($call['transcript_job_id'] ?? ''));

        // The CDR carries the exact lookup NetSapiens expects (domain case and
        // call ids included); it is more reliable than anything we derive.
        $rawCdr = json_decode((string) ($call['raw_cdr'] ?? '{}'), true);
        $rawCdr = is_array($rawCdr) ? $rawCdr : [];
        $nsDomain = self::nsDomain($domain, $rawCdr);
        $reference = TranscriptionReference::fromCdr($rawCdr);

        // Keep the row spelled the way NetSapiens spells it, so the whole UI and
        // every later request stop showing the lower-cased form.
        $storedDomain = (string) ($domain['domain'] ?? '');
        if ($storedDomain !== '' && $nsDomain !== $storedDomain && strcasecmp($nsDomain, $storedDomain) === 0) {
            $this->domains->adoptDomainName((int) $domain['id'], $nsDomain);
            $domain['domain'] = $nsDomain;
        }

        if (!$hasTranscript && ($jobId !== '' || $reference !== null)) {
            $effectiveJob = $reference !== null && $reference->jobId !== '' ? $reference->jobId : $jobId;
            $origCallId = $reference !== null && $reference->origCallId !== ''
                ? $reference->origCallId
                : trim((string) ($call['orig_call_id'] ?? ''));
            $termCallId = $reference !== null && $reference->termCallId !== ''
                ? $reference->termCallId
                : trim((string) ($call['term_call_id'] ?? ''));

            // Many deployments file the transcription under the CDR's single
            // call-id ("0_2654816984@172.16.10.109") instead of an orig/term split.
            $plainCallId = trim((string) ($rawCdr['call-id'] ?? $rawCdr['callid'] ?? ''));

            if (!self::usableCallId($plainCallId)) {
                $plainCallId = '';
            }

            // Never send our own cdr-<hash> placeholder: NetSapiens answers it with
            // 403 "Invalid domain or callids" and the whole lookup is abandoned.
            if (!self::usableCallId($origCallId)) {
                $origCallId = '';
            }

            if (!self::usableCallId($termCallId)) {
                $termCallId = '';
            }

            if ($effectiveJob === '') {
                // Nothing to ask for: without a Call Intelligence job id there is
                // no transcription row to address, whatever the call ids say.
                $updates['transcript_status'] = 'unavailable';
                $updates['last_error'] = 'transcription: the CDR carried no Call Intelligence job id.';
                Logger::warning('processor.transcription_unidentifiable', ['call_id' => $callId]);
            } else {
                if ($origCallId === '' && $termCallId === '' && $plainCallId === '') {
                    // The job id alone is often enough: NetSapiens also answers
                    // /transcriptions?id=<job>&date=YYYYMM with no callid at all.
                    Logger::info('processor.transcription_jobid_only', [
                        'call_id' => $callId,
                        'job_id' => $effectiveJob,
                    ]);
                }

                try {
                    $startedAt = trim((string) ($call['started_at'] ?? ''));

                    if ($startedAt === '') {
                        $startedAt = trim((string) ($rawCdr['call-start-datetime'] ?? ''));
                    }

                    $startedAt = $startedAt !== '' ? $startedAt : null;
                    $month = $reference !== null && $reference->month !== ''
                        ? $reference->month
                        : Time::yearMonth($startedAt, $timezone);

                    $payload = $this->fetchTranscription(
                        $nsDomain,
                        $effectiveJob,
                        $month,
                        Time::yearMonth($startedAt, 'UTC'),
                        $origCallId,
                        $termCallId,
                        $plainCallId,
                        (int) $domain['id'],
                    );

                    $transcription = Transcription::fromApi($payload);

                    if ($transcription->status === 'finished' || $transcription->segments !== [] || $transcription->summary !== '') {
                        $updates += $transcription->toColumns();
                    } else {
                        $transcriptPending = true;
                    }
                } catch (NsException $e) {
                    if ($e->isNotFound()) {
                        $transcriptPending = true;
                    } elseif ($e->isRetryable()) {
                        $transcriptPending = true;
                        $updates['last_error'] = 'transcription: ' . $e->getMessage();
                    } else {
                        $updates['transcript_status'] = 'error';
                        $updates['last_error'] = 'transcription: ' . $e->getMessage();
                        Logger::warning('processor.transcription_failed', [
                            'call_id' => $callId,
                            'domain' => $nsDomain,
                            'job_id' => $effectiveJob,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } elseif (!$hasTranscript) {
            $updates['transcript_status'] = 'unavailable';
        }

        // ---- Recording ---------------------------------------------------------
        $recordingCallId = trim((string) ($call['orig_call_id'] ?? '')) ?: trim((string) ($call['call_id'] ?? ''));

        if ((int) $call['has_recording'] === 0 && $call['recording_status'] !== 'none' && self::usableCallId($recordingCallId)) {
            try {
                $recording = $this->ns->recording($nsDomain, $recordingCallId);

                if ($recording !== null) {
                    $updates['recording_status'] = 'available';
                    $updates['recording_url'] = (string) ($recording['file-access-url'] ?? '');
                    $updates['recording_duration'] = (int) round((float) ($recording['file-duration-seconds'] ?? 0));
                    $updates['recording_size_kb'] = (int) ($recording['file-size-kilobytes'] ?? 0);
                    $updates['has_recording'] = 1;
                } else {
                    $updates['recording_status'] = 'none';
                }
            } catch (NsException $e) {
                $updates['recording_status'] = 'error';
                $updates['last_error'] = 'recording: ' . $e->getMessage();
                Logger::warning('processor.recording_failed', ['call_id' => $callId, 'domain' => $nsDomain, 'error' => $e->getMessage()]);
            }
        } elseif ((int) $call['has_recording'] === 0 && $call['recording_status'] !== 'none') {
            // Synthetic cdr-<hash> id: NetSapiens cannot look this up, so asking
            // only produces a guaranteed 404 in the log.
            $updates['recording_status'] = 'none';
        }

        if ($updates !== []) {
            $this->calls->saveProcessed($callId, $updates);
        }

        // ---- Retry when Call Intelligence is still working ---------------------
        if ($transcriptPending && $attempt < $maxAttempts) {
            $delay = min(600, 45 * $attempt);
            $this->calls->scheduleRetry($callId, 'Waiting for Call Intelligence (attempt ' . $attempt . '/' . $maxAttempts . ')', $delay);

            return 'retry';
        }

        if ($transcriptPending) {
            $updates['transcript_status'] = 'unavailable';
            $updates['last_error'] = 'Call Intelligence did not finish in time.';
        }

        // ---- Match + alert -----------------------------------------------------
        $fresh = $this->calls->find($callId);
        if ($fresh === null) {
            return 'missing';
        }

        $activeKeywords = $this->keywords->forDomain((int) $fresh['domain_id']);
        $found = $this->matcher->match($activeKeywords, $fresh);
        $this->matches->replaceForCall($callId, (int) $fresh['domain_id'], $found);

        $fresh = $this->calls->find($callId) ?? $fresh;
        $alertStatus = 'none';

        if ($found !== []) {
            $result = $this->alerts->dispatch($fresh);
            $alertStatus = $result['status'];
        }

        $this->calls->saveProcessed($callId, [
            'status' => 'complete',
            'next_attempt_at' => null,
            'alert_status' => $alertStatus,
            'transcript_status' => $updates['transcript_status'] ?? $fresh['transcript_status'],
            'processed_at' => Time::now(),
        ] + $updates);

        Logger::info('processor.complete', [
            'call_id' => $callId,
            'domain' => $domain['domain'],
            'matches' => count($found),
            'alert' => $alertStatus,
            'recording' => (bool) ($updates['has_recording'] ?? $fresh['has_recording']),
            'transcript' => (bool) ($updates['has_transcript'] ?? $fresh['has_transcript']),
        ]);

        return 'complete';
    }

    /**
     * NetSapiens files Call Intelligence by job id + YYYYMM + call ids. A call
     * that starts just after local midnight can belong to the previous month in
     * the domain timezone and to the current one in UTC, so both are tried.
     *
     * @return array<string, mixed>
     */
    private function fetchTranscription(
        string $domainName,
        string $jobId,
        string $month,
        string $utcMonth,
        string $origCallId,
        string $termCallId,
        string $plainCallId = '',
        int $domainRowId = 0,
    ): array {
        try {
            return $this->fetchTranscriptionOnce($domainName, $jobId, $month, $utcMonth, $origCallId, $termCallId, $plainCallId);
        } catch (NsException $e) {
            if (!self::isDomainRejection($e)) {
                throw $e;
            }

            // "Bad request. Invalid domain or callids." also fires when the domain
            // is spelled differently from the one NetSapiens knows.
            $canonical = $this->canonicalDomain($domainName);

            if ($canonical === null || $canonical === $domainName) {
                throw $e;
            }

            if ($domainRowId > 0) {
                $this->domains->adoptDomainName($domainRowId, $canonical);
            }

            Logger::info('processor.domain_respelled', [
                'stored' => $domainName,
                'netsapiens' => $canonical,
            ]);

            return $this->fetchTranscriptionOnce($canonical, $jobId, $month, $utcMonth, $origCallId, $termCallId, $plainCallId);
        }
    }

    /** @return array<string, mixed> */
    private function fetchTranscriptionOnce(
        string $domainName,
        string $jobId,
        string $month,
        string $utcMonth,
        string $origCallId,
        string $termCallId,
        string $plainCallId = '',
    ): array {
        try {
            return $this->ns->transcription($domainName, $jobId, $month, $origCallId, $termCallId, $plainCallId);
        } catch (NsException $e) {
            if ($e->isNotFound() && $utcMonth !== '' && $utcMonth !== $month) {
                return $this->ns->transcription($domainName, $jobId, $utcMonth, $origCallId, $termCallId, $plainCallId);
            }

            throw $e;
        }
    }

    /** NetSapiens answers 403 "Bad request. Invalid domain or callids." for a mis-spelled domain. */
    private static function isDomainRejection(NsException $e): bool
    {
        if ($e->status !== 403) {
            return false;
        }

        $body = (string) ($e->responseBody ?? '') . ' ' . $e->getMessage();

        return stripos($body, 'invalid domain') !== false;
    }

    /** Exact spelling of the domain as NetSapiens lists it, or null when it is not visible. */
    private function canonicalDomain(string $domainName): ?string
    {
        if ($this->domainIndex === null) {
            $this->domainIndex = [];

            try {
                foreach ($this->ns->domains() as $row) {
                    $name = trim((string) ($row['domain'] ?? $row['domain-name'] ?? $row['name'] ?? ''));

                    if ($name !== '') {
                        $this->domainIndex[strtolower($name)] = $name;
                    }
                }
            } catch (NsException) {
                $this->domainIndex = [];
            }
        }

        return $this->domainIndex[strtolower($domainName)] ?? null;
    }

    /** Our own placeholder id ("cdr-<hash>") can never be used against the API. */
    private static function usableCallId(string $callId): bool
    {
        $callId = trim($callId);

        return $callId !== '' && !str_starts_with($callId, 'cdr-');
    }

    /**
     * NetSapiens domain names are case sensitive on some endpoints
     * ("1180.DynaCloud" vs the lower-cased spelling we store for uniqueness).
     *
     * @param array<string, mixed> $domainRow
     * @param array<string, mixed> $cdr
     */
    private static function nsDomain(array $domainRow, array $cdr): string
    {
        $stored = (string) ($domainRow['domain'] ?? '');

        foreach (['call-term-domain', 'call-orig-domain', 'domain'] as $key) {
            $candidate = $cdr[$key] ?? null;

            if (is_string($candidate) && trim($candidate) !== '' && ($stored === '' || strcasecmp($candidate, $stored) === 0)) {
                return trim($candidate);
            }
        }

        return $stored;
    }

    /** @return array{processed:int, retry:int, failed:int, skipped:int} */
    public function work(int $limit = 25, int $secondsBudget = 55): array
    {
        $started = time();
        $stats = ['processed' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
        $handled = 0;

        while ($handled < $limit && (time() - $started) < $secondsBudget) {
            $due = $this->calls->dueForProcessing(1);
            if ($due === []) {
                break;
            }

            $call = $due[0];
            $result = $this->process((int) $call['id']);
            $handled++;

            match ($result) {
                'complete' => $stats['processed']++,
                'retry' => $stats['retry']++,
                'failed', 'missing' => $stats['failed']++,
                default => $stats['skipped']++,
            };
        }

        return $stats;
    }
}
