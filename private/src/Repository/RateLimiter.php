<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    public function tooManyAttempts(string $identity, string $ip, int $max = 5, int $windowMinutes = 15): bool
    {
        $since = Time::addMinutes(Time::now(), -$windowMinutes);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND created_at >= :since
               AND (identity = :identity OR (identity = :identity2 AND ip = :ip))',
            [
                'since' => $since,
                'identity' => $identity,
                'identity2' => $identity,
                'ip' => $ip,
            ],
        );

        return $count >= $max;
    }

    public function record(string $identity, string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'identity' => mb_strtolower($identity),
            'ip' => $ip,
            'success' => (int) $success,
            'created_at' => Time::now(),
        ]);
    }

    public function clear(string $identity): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE identity = :i', ['i' => mb_strtolower($identity)]);
    }

    public function prune(int $days = 30): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE created_at < :cutoff', ['cutoff' => Time::daysAgo($days)]);
    }
}
