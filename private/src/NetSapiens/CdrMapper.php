<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

/**
 * Normalises a NetSapiens v2 CDR object (event subscription "cdr" or the
 * /domains/{domain}/cdrs endpoint) into DynaPulse call columns.
 *
 * Caller/called mapping follows the CDR semantics: the orig leg describes who
 * called whom, the term leg describes the internal destination that answered.
 */
final class CdrMapper
{
    /**
     * @param array<string, mixed> $cdr
     * @return array<string, mixed>
     */
    public static function map(array $cdr, string $domain): array
    {
        $origCallId = self::text($cdr, ['call-orig-call-id', 'call-orig-callid', 'orig_callid']);
        $termCallId = self::text($cdr, ['call-term-call-id', 'call-term-callid', 'term_callid']);
        $plainCallId = self::text($cdr, ['call-id', 'callid']);

        $callId = $origCallId !== '' ? $origCallId : ($plainCallId !== '' ? $plainCallId : $termCallId);
        if ($callId === '') {
            $callId = 'cdr-' . substr(hash('sha256', (string) json_encode($cdr)), 0, 32);
        }

        $callerNumber = self::firstNonEmpty([
            self::text($cdr, ['call-orig-from-user']),
            Str::phoneFromUri(self::text($cdr, ['call-orig-from-uri'])),
            self::text($cdr, ['call-orig-caller-id']),
            Str::phoneFromUri(self::text($cdr, ['call-orig-request-user'])),
        ]);

        $calledNumber = self::firstNonEmpty([
            self::text($cdr, ['call-orig-to-user']),
            Str::phoneFromUri(self::text($cdr, ['call-orig-to-uri'])),
            Str::phoneFromUri(self::text($cdr, ['call-term-to-uri'])),
            self::text($cdr, ['call-term-user']),
        ]);

        $started = Time::parse(self::text($cdr, ['call-start-datetime', 'call-batch-start-datetime']));
        $answered = Time::parse(self::text($cdr, ['call-answer-datetime', 'call-batch-answer-datetime']));
        $ended = Time::parse(self::text($cdr, ['call-disconnect-datetime']));

        $duration = self::int($cdr, ['call-total-duration-seconds', 'call-batch-total-duration-seconds']);
        if ($duration === 0 && $started !== null && $ended !== null) {
            $duration = max(0, strtotime($ended . ' UTC') - strtotime($started . ' UTC'));
        }

        return [
            'call_id' => $callId,
            'orig_call_id' => $origCallId !== '' ? $origCallId : $callId,
            'term_call_id' => $termCallId !== '' ? $termCallId : null,
            'direction' => self::direction($cdr, $domain),
            'caller_number' => $callerNumber,
            'caller_name' => self::text($cdr, ['call-orig-from-name', 'call-orig-name']),
            'called_number' => $calledNumber,
            'called_name' => self::text($cdr, ['call-term-user', 'call-orig-to-user']),
            'answered_by' => self::text($cdr, ['call-term-user', 'call-term-from-user']),
            'started_at' => $started,
            'answered_at' => $answered,
            'ended_at' => $ended,
            'duration_seconds' => $duration,
            'talk_seconds' => self::int($cdr, ['call-talking-duration-seconds']),
            'hold_seconds' => self::int($cdr, ['call-on-hold-duration-seconds', 'call-batch-on-hold-duration-seconds']),
            'disconnect_reason' => self::text($cdr, ['call-disconnect-reason-text', 'call-disconnect-reason']),
            'disposition' => self::text($cdr, ['call-disposition']),
            'transcript_job_id' => self::text($cdr, ['call-intelligence-job-id']),
            'sentiment_positive' => self::int($cdr, ['call-intelligence-percent-positive']),
            'sentiment_neutral' => self::int($cdr, ['call-intelligence-percent-neutral']),
            'sentiment_negative' => self::int($cdr, ['call-intelligence-percent-negative']),
            'topics_hint' => self::topics(self::value($cdr, 'call-intelligence-topics-top')),
            'raw' => $cdr,
        ];
    }

    /** @param array<string, mixed> $cdr */
    public static function direction(array $cdr, string $domain): string
    {
        $origDomain = self::text($cdr, ['call-orig-domain']);
        $termDomain = self::text($cdr, ['call-term-domain']);
        $domain = strtolower($domain);

        if ($origDomain !== '' && $termDomain !== '' && strcasecmp($origDomain, $termDomain) === 0) {
            return 'Internal';
        }

        if ($termDomain !== '' && strcasecmp($termDomain, $domain) === 0) {
            return 'Inbound';
        }

        if ($origDomain !== '' && strcasecmp($origDomain, $domain) === 0) {
            return 'Outbound';
        }

        // Newer builds ship a human readable string.
        $direction = self::text($cdr, ['call-direction', 'call-type']);
        if ($direction !== '' && !is_numeric($direction)) {
            return match (strtolower($direction)) {
                'inbound', 'in' => 'Inbound',
                'outbound', 'out' => 'Outbound',
                'missed' => 'Missed',
                'internal' => 'Internal',
                default => ucfirst(strtolower($direction)),
            };
        }

        // Last resort: compare the legs with the domain being processed.
        $origUser = self::text($cdr, ['call-orig-user']);
        $termUser = self::text($cdr, ['call-term-user']);
        if ($origUser !== '' && $termUser !== '') {
            return 'Internal';
        }

        return 'Unknown';
    }

    /** @param mixed $value @return list<string> */
    private static function topics(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $t): bool => trim($t) !== ''));
        }

        if (is_string($value) && $value !== '') {
            $parts = preg_split('/\s*[,;|]\s*/', $value) ?: [];

            return array_values(array_filter(array_map('trim', $parts), static fn (string $t): bool => $t !== ''));
        }

        return [];
    }

    /** @param array<string, mixed> $cdr */
    private static function value(array $cdr, string $key): mixed
    {
        return $cdr[$key] ?? null;
    }

    /** @param array<string, mixed> $cdr @param list<string> $keys */
    private static function text(array $cdr, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $cdr[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '' && strtolower($value) !== 'null') {
                    return $value;
                }
            } elseif (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $cdr @param list<string> $keys */
    private static function int(array $cdr, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $cdr[$key] ?? null;
            if (is_numeric($value)) {
                return (int) round((float) $value);
            }
        }

        return 0;
    }

    /** @param list<string> $values */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && strtolower($value) !== 'unknown') {
                return $value;
            }
        }

        return '';
    }
}
