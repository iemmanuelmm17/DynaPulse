<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
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

        if (!$hasTranscript && $jobId !== '') {
            try {
                $origCallId = trim((string) ($call['orig_call_id'] ?? ''));
                $termCallId = trim((string) ($call['term_call_id'] ?? ''));

                if ($origCallId === '' && $termCallId === '') {
                    $origCallId = (string) ($call['call_id'] ?? '');
                }

                $payload = $this->fetchTranscription(
                    (string) $domain['domain'],
                    $jobId,
                    isset($call['started_at']) ? (string) $call['started_at'] : null,
                    $timezone,
                    $origCallId,
                    $termCallId,
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
                    Logger::warning('processor.transcription_failed', ['call_id' => $callId, 'error' => $e->getMessage()]);
                }
            }
        } elseif (!$hasTranscript) {
            $updates['transcript_status'] = 'unavailable';
        }

        // ---- Recording ---------------------------------------------------------
        if ((int) $call['has_recording'] === 0 && $call['recording_status'] !== 'none') {
            try {
                $recording = $this->ns->recording((string) $domain['domain'], (string) ($call['orig_call_id'] ?: $call['call_id']));

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
                Logger::warning('processor.recording_failed', ['call_id' => $callId, 'error' => $e->getMessage()]);
            }
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
        ?string $startedAt,
        string $timezone,
        string $origCallId,
        string $termCallId,
    ): array {
        $month = Time::yearMonth($startedAt, $timezone);

        try {
            return $this->ns->transcription($domainName, $jobId, $month, $origCallId, $termCallId);
        } catch (NsException $e) {
            $utcMonth = Time::yearMonth($startedAt, 'UTC');

            if ($e->isNotFound() && $utcMonth !== $month) {
                return $this->ns->transcription($domainName, $jobId, $utcMonth, $origCallId, $termCallId);
            }

            throw $e;
        }
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
