<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class AuditRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $meta */
    public function log(string $action, ?int $userId = null, ?int $domainId = null, array $meta = [], string $ip = ''): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'domain_id' => $domainId,
            'action' => $action,
            'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '',
            'ip' => $ip,
            'created_at' => Time::now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 25): array
    {
        return $this->db->select(
            'SELECT a.*, u.username, u.domain AS user_domain FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT :limit',
            ['limit' => $limit],
        );
    }
}
