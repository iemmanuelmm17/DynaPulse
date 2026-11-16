<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Support\Config;

/** HMAC signed URLs: the "Listen" button in an alert email works without a session. */
final class Signer
{
    private string $key;

    public function __construct(Config $config)
    {
        $this->key = (string) $config->get('app.key', '');
    }

    public function enabled(): bool
    {
        return strlen($this->key) >= 16;
    }

    public function signature(string $value, int $expiresAt): string
    {
        return hash_hmac('sha256', $value . '|' . $expiresAt, $this->key);
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, string>
     */
    public function sign(array $params, int $ttlSeconds): array
    {
        $expiresAt = time() + $ttlSeconds;
        $payload = self::payload($params) . '|' . $expiresAt;

        return $params + [
            'exp' => (string) $expiresAt,
            'sig' => hash_hmac('sha256', $payload, $this->key),
        ];
    }

    /** @param array<string, string|int> $params */
    public function verify(array $params): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $exp = (int) ($params['exp'] ?? 0);
        $sig = (string) ($params['sig'] ?? '');

        if ($exp <= 0 || $sig === '' || $exp < time() - 60) {
            return false;
        }

        unset($params['exp'], $params['sig']);
        $payload = self::payload($params) . '|' . $exp;

        return hash_equals(hash_hmac('sha256', $payload, $this->key), $sig);
    }

    /** @param array<string, string|int> $params */
    private static function payload(array $params): string
    {
        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode('&', $parts);
    }
}
