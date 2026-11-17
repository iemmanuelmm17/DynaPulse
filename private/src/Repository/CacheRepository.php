<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

/** Tiny key/value cache (used for NetSapiens access tokens). */
final class CacheRepository
{
    public function __construct(private Database $db)
    {
    }

    public function get(string $key): ?string
    {
        $row = $this->db->first(
            'SELECT value, expires_at FROM api_cache WHERE key = :k',
            ['k' => $key],
        );

        if ($row === null) {
            return null;
        }

        if ($row['expires_at'] !== null && (string) $row['expires_at'] < Time::now()) {
            $this->forget($key);

            return null;
        }

        return (string) $row['value'];
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->db->execute(
            'INSERT INTO api_cache (key, value, expires_at) VALUES (:k, :v, :e)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
            ['k' => $key, 'v' => $value, 'e' => Time::addSeconds(Time::now(), max(30, $ttlSeconds))],
        );
    }

    public function forget(string $key): void
    {
        $this->db->execute('DELETE FROM api_cache WHERE key = :k', ['k' => $key]);
    }

    public function prune(): void
    {
        $this->db->execute('DELETE FROM api_cache WHERE expires_at IS NOT NULL AND expires_at < :now', ['now' => Time::now()]);
    }
}
