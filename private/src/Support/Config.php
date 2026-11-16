<?php

declare(strict_types=1);

namespace DynaPulse\Support;

/**
 * Array backed configuration with dot notation plus defaults derived from .env.
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public static function fromEnvironment(string $rootPath): self
    {
        $timezone = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        return new self([
            'app' => [
                'name' => Env::get('APP_NAME', 'DynaPulse'),
                'env' => Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'url' => rtrim(Env::get('APP_URL', 'http://localhost') ?? 'http://localhost', '/'),
                'timezone' => $timezone,
                'key' => Env::get('APP_KEY', ''),
            ],
            'paths' => [
                'root' => $rootPath,
                'private' => $rootPath,
                'storage' => $rootPath . DIRECTORY_SEPARATOR . 'storage',
                'logs' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
                'sessions' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions',
                'cache' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
                'tmp' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp',
                'recordings' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'recordings',
                'views' => $rootPath . DIRECTORY_SEPARATOR . 'views',
                'public' => dirname($rootPath, 2) . DIRECTORY_SEPARATOR . 'public',
            ],
            'db' => [
                'driver' => 'sqlite',
                'database' => Env::get('DB_DATABASE')
                    ?: $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'dynapulse.sqlite',
            ],
            'ns' => [
                'base_url' => rtrim(Env::get('NS_BASE_URL', 'https://ns-api.com') ?? 'https://ns-api.com', '/'),
                'api_key' => Env::get('NS_API_KEY', ''),
                'client_id' => Env::get('NS_CLIENT_ID', ''),
                'client_secret' => Env::get('NS_CLIENT_SECRET', ''),
                'timeout' => max(5, Env::int('NS_TIMEOUT', 30)),
                'verify_ssl' => Env::bool('NS_VERIFY_SSL', true),
            ],
            'ingest' => [
                'webhook_secret' => Env::get('WEBHOOK_SECRET', ''),
                'subscription_id' => Env::get('WEBHOOK_SUBSCRIPTION_ID', ''),
                'process_inline' => Env::bool('PROCESS_INLINE', true),
                'poll_enabled' => Env::bool('POLL_ENABLED', true),
                'lookback_minutes' => max(1, Env::int('POLL_LOOKBACK_MINUTES', 10)),
                'initial_lookback_hours' => max(1, Env::int('POLL_INITIAL_LOOKBACK_HOURS', 24)),
            ],
            'mail' => [
                'transport' => Env::get('MAIL_TRANSPORT', 'smtp'),
                'host' => Env::get('MAIL_HOST', 'smtp.office365.com'),
                'port' => Env::int('MAIL_PORT', 587),
                'encryption' => strtolower(Env::get('MAIL_ENCRYPTION', 'starttls') ?? 'starttls'),
                'username' => Env::get('MAIL_USERNAME', ''),
                'password' => Env::get('MAIL_PASSWORD', ''),
                'from_address' => Env::get('MAIL_FROM_ADDRESS', Env::get('MAIL_USERNAME', '')),
                'from_name' => Env::get('MAIL_FROM_NAME', 'DynaPulse Alerts'),
                'reply_to' => Env::get('MAIL_REPLY_TO', ''),
                'timeout' => max(5, Env::int('MAIL_TIMEOUT', 25)),
            ],
            'security' => [
                'session_name' => Env::get('SESSION_NAME', 'dynapulse') ?? 'dynapulse',
                'session_lifetime' => max(15, Env::int('SESSION_LIFETIME', 480)),
                'trusted_proxies' => Env::list('TRUSTED_PROXIES', []),
                'public_audio_links' => Env::bool('ALLOW_PUBLIC_AUDIO_LINKS', true),
                'log_level' => strtolower(Env::get('LOG_LEVEL', 'info') ?? 'info'),
                'recording_cache' => Env::bool('RECORDING_CACHE', false),
            ],
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public function isLocal(): bool
    {
        return $this->get('app.env') === 'local';
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug');
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function mailIsConfigured(): bool
    {
        return (string) $this->get('mail.host') !== ''
            && (string) $this->get('mail.username') !== ''
            && (string) $this->get('mail.password') !== ''
            && (string) $this->get('mail.from_address') !== '';
    }
}
