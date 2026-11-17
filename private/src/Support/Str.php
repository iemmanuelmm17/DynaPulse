<?php

declare(strict_types=1);

namespace DynaPulse\Support;

final class Str
{
    public static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 6) {
            return str_repeat('•', $length);
        }

        return substr($value, 0, 3) . str_repeat('•', max(4, $length - 7)) . substr($value, -4);
    }

    public static function limit(?string $value, int $limit = 120, string $end = '…'): string
    {
        $value = trim((string) $value);

        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    /** Strip a sip: URI (or similar) down to the number / extension. */
    public static function phoneFromUri(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^sips?:/i', '', $value) ?? $value;
        $value = explode('@', $value)[0];
        $value = explode(';', $value)[0];

        return trim($value);
    }

    /** Pretty display for caller IDs: +1 (555) 010-1234, extensions stay as is. */
    public static function prettyPhone(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'Unknown';
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 4));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
        }

        return $value;
    }

    public static function initials(?string $name, string $fallback = 'DP'): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return $fallback;
        }

        $words = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials !== '' ? $initials : $fallback;
    }

    public static function emailValid(?string $email): bool
    {
        return $email !== null && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return list<string> */
    public static function splitEmails(?string $value): array
    {
        $parts = preg_split('/[\s,;]+/', (string) $value) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && self::emailValid($part)) {
                $emails[strtolower($part)] = strtolower($part);
            }
        }

        return array_values($emails);
    }

    public static function slug(?string $value, string $fallback = 'x'): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-') ?: $fallback;
    }

    public static function fileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file';

        return trim($name, '_') ?: 'file';
    }

    public static function severityRank(string $severity): int
    {
        return match (strtolower($severity)) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
