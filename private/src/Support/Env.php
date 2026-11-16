<?php

declare(strict_types=1);

namespace DynaPulse\Support;

/**
 * Minimal, dependency free .env reader.
 *
 * Values already present in the real environment win over the file, which makes
 * per-request overrides (`set DYN_DEBUG=1 && php ...`) work on Windows.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static ?string $file = null;

    public static function load(string $path): void
    {
        self::$file = $path;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return;
        }

        // Strip a UTF-8 BOM if the file was saved by Notepad.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $raw] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }

            self::$values[$key] = self::parseValue(trim($raw));
        }
    }

    public static function file(): ?string
    {
        return self::$file;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromProcess = getenv($key);
        if ($fromProcess !== false && $fromProcess !== '') {
            return $fromProcess;
        }

        $value = self::$values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off', 'null', '' => false,
            default => $default,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== '');

        return array_values($parts);
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === '"' || $quote === "'") {
            $closing = strrpos($raw, $quote);
            $inner = $closing !== false && $closing > 0 ? substr($raw, 1, $closing - 1) : substr($raw, 1);

            return $quote === '"' ? stripcslashes($inner) : $inner;
        }

        // Unquoted: a value that is nothing but a comment (KEY=   # note) means
        // "not set", otherwise the note itself would become the value.
        if (str_starts_with($raw, '#')) {
            return '';
        }

        // Unquoted: a " #" sequence starts a comment.
        if (preg_match('/\s#/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $raw = substr($raw, 0, (int) $m[0][1]);
        }

        return trim($raw);
    }
}
