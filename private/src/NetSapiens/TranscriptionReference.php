<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

/**
 * The authoritative Call Intelligence lookup for one CDR.
 *
 * NetSapiens embeds it in the CDR as "prefilled-transcription-api", e.g.
 *
 *   /ns-api/v2/domains/1180.DynaCloud/transcriptions
 *       ?id=112174179&date=202611
 *       &orig_callid=768181475%40172.16.10.228
 *       &term_callid=20261116153414064248-cac18a7984653a998c54034ec629e12c
 *
 * Using it as-is means the domain keeps its exact case (NetSapiens rejects
 * "1180.dynacloud" on some builds) and the call ids are the ones the vendor
 * actually filed the job under.
 */
final class TranscriptionReference
{
    public function __construct(
        public readonly string $domain,
        public readonly string $jobId,
        public readonly string $month,
        public readonly string $origCallId,
        public readonly string $termCallId,
    ) {
    }

    /** @param array<string, mixed> $cdr */
    public static function fromCdr(array $cdr): ?self
    {
        foreach (['prefilled-transcription-api', 'prefilled_transcription_api', 'transcription-api', 'transcription_api'] as $key) {
            $raw = $cdr[$key] ?? null;

            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $reference = self::parse($raw);

            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    public static function parse(string $raw): ?self
    {
        // The value is JSON-escaped and often HTML-escaped ("&amp;") when copied
        // out of a web UI, so decode before looking at the query string.
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($raw === '') {
            return null;
        }

        if (preg_match('#/domains/([^/]+)/transcriptions#i', $raw, $matches) === 1) {
            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $query = (string) (parse_url($raw, PHP_URL_QUERY) ?: '');

            return self::fromQuery(rawurldecode($matches[1]), $query);
        }

        // Some deployments post only "?id=…&date=…&orig_callid=…".
        if (str_contains($raw, '=') && !str_contains($raw, '/')) {
            return self::fromQuery('', ltrim($raw, '?'));
        }

        return null;
    }

    private static function fromQuery(string $domain, string $query): ?self
    {
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);

        $jobId = trim((string) ($params['id'] ?? $params['jobid'] ?? $params['job-id'] ?? ''));
        $month = (string) preg_replace('/[^0-9]/', '', (string) ($params['date'] ?? ''));
        $orig = trim((string) ($params['orig_callid'] ?? $params['orig-callid'] ?? ''));
        $term = trim((string) ($params['term_callid'] ?? $params['term-callid'] ?? ''));
        $call = trim((string) ($params['callid'] ?? $params['call-id'] ?? ''));

        if ($term === '' && $call !== '') {
            $term = $call;
        }

        if ($jobId === '' && $orig === '' && $term === '') {
            return null;
        }

        return new self($domain, $jobId, $month, $orig, $term);
    }
}
