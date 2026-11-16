<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Support\Logger;

/**
 * Pure keyword matching. Three modes:
 *   phrase — plain substring, case-insensitive by default
 *   word   — whole words only (unicode aware)
 *   regex  — power user mode, validated before use
 */
final class KeywordMatcher
{
    public const DEFAULT_FIELDS = ['summary', 'topics', 'transcript'];

    /**
     * @param list<array<string, mixed>> $keywords
     * @param array<string, mixed> $call
     * @return list<array{keyword_id:?int, keyword_label:string, field:string, snippet:string, severity:string}>
     */
    public function match(array $keywords, array $call): array
    {
        $haystacks = $this->haystacks($call);
        $matches = [];

        foreach ($keywords as $keyword) {
            $pattern = trim((string) ($keyword['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }

            $type = (string) ($keyword['match_type'] ?? 'phrase');
            $caseSensitive = (bool) ($keyword['case_sensitive'] ?? false);
            $fields = (array) ($keyword['fields'] ?? []);

            if ($fields === []) {
                $fields = self::DEFAULT_FIELDS;
            }

            foreach ($fields as $field) {
                $haystack = $haystacks[$field] ?? '';

                if ($haystack === '') {
                    continue;
                }

                $hit = $this->search($pattern, $type, $haystack, $caseSensitive);

                if ($hit === null) {
                    continue;
                }

                $matches[] = [
                    'keyword_id' => isset($keyword['id']) ? (int) $keyword['id'] : null,
                    'keyword_label' => (string) ($keyword['label'] ?? $pattern),
                    'field' => $field,
                    'snippet' => $this->snippet($haystack, $hit['offset'], $hit['length']),
                    'severity' => (string) ($keyword['severity'] ?? 'medium'),
                ];

                break; // one match per keyword per call
            }
        }

        return $matches;
    }

    public function isValidRegex(string $pattern): bool
    {
        $regex = self::normaliseRegex($pattern, false);

        return $regex !== null && @preg_match($regex, 'test') !== false;
    }

    /**
     * @return array{offset:int, length:int}|null
     */
    public function search(string $pattern, string $type, string $haystack, bool $caseSensitive = false): ?array
    {
        if ($pattern === '' || $haystack === '') {
            return null;
        }

        if ($type === 'regex') {
            $regex = self::normaliseRegex($pattern, $caseSensitive);

            if ($regex === null) {
                Logger::warning('keyword.invalid_regex', ['pattern' => $pattern]);

                return null;
            }

            $result = @preg_match($regex, $haystack, $matches, PREG_OFFSET_CAPTURE);
            if ($result === false || $result === 0 || !isset($matches[0])) {
                return null;
            }

            return ['offset' => (int) $matches[0][1], 'length' => max(1, strlen((string) $matches[0][0]))];
        }

        if ($type === 'word') {
            $regex = '~(?<![\p{L}\p{N}_])' . preg_quote($pattern, '~') . '(?![\p{L}\p{N}_])~u' . ($caseSensitive ? '' : 'i');

            $result = @preg_match($regex, $haystack, $matches, PREG_OFFSET_CAPTURE);
            if ($result === false || $result === 0 || !isset($matches[0])) {
                return null;
            }

            return ['offset' => (int) $matches[0][1], 'length' => max(1, strlen((string) $matches[0][0]))];
        }

        $offset = $caseSensitive ? mb_strpos($haystack, $pattern, 0, 'UTF-8') : mb_stripos($haystack, $pattern, 0, 'UTF-8');

        if ($offset === false) {
            return null;
        }

        return ['offset' => $offset, 'length' => mb_strlen($pattern, 'UTF-8')];
    }

    /** @param array<string, mixed> $call @return array<string, string> */
    public function haystacks(array $call): array
    {
        $topics = $call['transcript_topics'] ?? [];
        if (is_string($topics)) {
            $decoded = json_decode($topics, true);
            $topics = is_array($decoded) ? $decoded : [$topics];
        }

        return [
            'summary' => trim((string) ($call['transcript_summary'] ?? '')),
            'topics' => implode(', ', array_map('strval', (array) $topics)),
            'transcript' => trim((string) ($call['transcript_text'] ?? '')),
            'caller_number' => trim((string) ($call['caller_number'] ?? '')),
            'called_number' => trim((string) ($call['called_number'] ?? '')),
        ];
    }

    public function snippet(string $haystack, int $offset, int $length, int $context = 90): string
    {
        // Work on characters, not bytes, so offsets from regex/strpos stay sane.
        $before = mb_substr($haystack, 0, $offset, 'UTF-8');
        $charOffset = mb_strlen($before, 'UTF-8');
        $charLength = max(1, mb_strlen(mb_substr($haystack, $offset, $length, 'UTF-8'), 'UTF-8'));

        $start = max(0, $charOffset - $context);
        $end = min(mb_strlen($haystack, 'UTF-8'), $charOffset + $charLength + $context);

        $snippet = mb_substr($haystack, $start, $end - $start, 'UTF-8');
        $snippet = preg_replace('/\s+/u', ' ', $snippet) ?? $snippet;

        return ($start > 0 ? '… ' : '') . trim($snippet) . ($end < mb_strlen($haystack, 'UTF-8') ? ' …' : '');
    }

    private static function normaliseRegex(string $pattern, bool $caseSensitive): ?string
    {
        $pattern = trim($pattern);
        $first = $pattern[0] ?? '';

        if ($first !== '' && !ctype_alnum($first) && $first !== '\\' && strrpos($pattern, $first) > 0) {
            $flags = substr($pattern, strrpos($pattern, $first) + 1);
            $flags = preg_replace('/[^imsxuad]/', '', $flags) ?? '';
            if (!$caseSensitive && !str_contains($flags, 'i')) {
                $flags .= 'i';
            }
            if (!str_contains($flags, 'u')) {
                $flags .= 'u';
            }

            return $pattern . $flags;
        }

        return '~' . str_replace('~', '\~', $pattern) . '~u' . ($caseSensitive ? '' : 'i');
    }
}
