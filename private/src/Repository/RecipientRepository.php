<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class RecipientRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forDomain(int $domainId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM recipients WHERE domain_id = :d';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY email ASC';

        return $this->db->select($sql, ['d' => $domainId]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT r.*, d.domain AS domain_name FROM recipients r
             JOIN domains d ON d.id = r.domain_id
             ORDER BY d.domain ASC, r.email ASC',
        );
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM recipients WHERE id = :id', ['id' => $id]);

        return $row ?: null;
    }

    /** @return list<string> */
    public function alertEmails(int $domainId): array
    {
        $rows = $this->db->select(
            'SELECT email FROM recipients WHERE domain_id = :d AND active = 1 AND receive_alerts = 1 ORDER BY email',
            ['d' => $domainId],
        );

        return array_values(array_unique(array_map(static fn (array $r): string => strtolower((string) $r['email']), $rows)));
    }

    /** @return list<string> */
    public function reportEmails(int $domainId): array
    {
        $rows = $this->db->select(
            'SELECT email FROM recipients WHERE domain_id = :d AND active = 1 AND receive_reports = 1 ORDER BY email',
            ['d' => $domainId],
        );

        return array_values(array_unique(array_map(static fn (array $r): string => strtolower((string) $r['email']), $rows)));
    }

    public function upsert(int $domainId, string $email, string $name = '', bool $alerts = true, bool $reports = true): int
    {
        $existing = $this->db->first(
            'SELECT id FROM recipients WHERE domain_id = :d AND lower(email) = lower(:e)',
            ['d' => $domainId, 'e' => $email],
        );

        if ($existing !== null) {
            $this->db->update('recipients', [
                'name' => $name,
                'receive_alerts' => (int) $alerts,
                'receive_reports' => (int) $reports,
                'active' => 1,
            ], 'id = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('recipients', [
            'domain_id' => $domainId,
            'name' => $name,
            'email' => $email,
            'receive_alerts' => (int) $alerts,
            'receive_reports' => (int) $reports,
            'active' => 1,
            'created_at' => Time::now(),
        ]);
    }

    public function toggle(int $id, string $field): void
    {
        if (!in_array($field, ['active', 'receive_alerts', 'receive_reports'], true)) {
            return;
        }

        $this->db->execute(
            sprintf('UPDATE recipients SET %s = CASE WHEN %s = 1 THEN 0 ELSE 1 END WHERE id = :id', $field, $field),
            ['id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM recipients WHERE id = :id', ['id' => $id]);
    }
}
