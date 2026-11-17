<?php

declare(strict_types=1);

namespace DynaPulse\Support;

use Throwable;

/**
 * Tiny JSON-lines logger. One file per day, outside the web root, with secrets
 * stripped from the context before anything touches the disk.
 */
final class Logger
{
    /** @var array<string, int> */
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private static ?self $instance = null;

    private string $directory;

    private int $threshold;

    /** @var list<string> */
    private const REDACT = ['password', 'pass', 'secret', 'token', 'access_token', 'refresh_token', 'authorization', 'api_key', 'apikey', 'client_secret', 'sig', 'signature'];

    private function __construct(string $directory, string $level)
    {
        $this->directory = $directory;
        $this->threshold = self::LEVELS[strtolower($level)] ?? 20;
    }

    public static function boot(string $directory, string $level = 'info'): self
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return self::$instance = new self($directory, $level);
    }

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self(sys_get_temp_dir(), 'info');
        }

        return self::$instance;
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::instance()->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::instance()->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::instance()->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::instance()->log('error', $message, $context);
    }

    public static function exception(Throwable $e, string $message = 'unhandled_exception'): void
    {
        self::instance()->log('error', $message, [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => self::shortTrace($e),
        ]);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if ((self::LEVELS[$level] ?? 20) < $this->threshold) {
            return;
        }

        $record = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => self::sanitize($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        $file = $this->directory . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (PHP_SAPI === 'cli' && in_array($level, ['warning', 'error'], true)) {
            fwrite(STDERR, '[' . strtoupper($level) . '] ' . $message . ' ' . json_encode(self::sanitize($context)) . PHP_EOL);
        }
    }

    public function tail(int $lines = 50): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';
        if (!is_file($file)) {
            return '';
        }

        $handle = new \SplFileObject($file, 'r');
        $handle->seek(max(0, $handle->getIterator()->count() - $lines));
        $buffer = '';
        foreach ($handle as $line) {
            $buffer .= $line;
        }

        return $buffer;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['…' => 'nested'];
        }

        $clean = [];
        foreach ($context as $key => $value) {
            $needle = strtolower((string) $key);

            foreach (self::REDACT as $secret) {
                if (str_contains($needle, $secret)) {
                    $clean[$key] = '[redacted]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize($value, $depth + 1);
            } elseif ($value instanceof Throwable) {
                $clean[$key] = [
                    'exception' => $value::class,
                    'message' => $value->getMessage(),
                    'at' => $value->getFile() . ':' . $value->getLine(),
                ];
            } elseif (is_object($value)) {
                $clean[$key] = get_class($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                $clean[$key] = mb_substr($value, 0, 1000) . '…';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @return list<string> */
    private static function shortTrace(Throwable $e): array
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $trace[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
                . ' @ ' . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
        }

        return $trace;
    }
}
