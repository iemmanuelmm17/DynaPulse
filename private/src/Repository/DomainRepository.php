<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class DomainRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT d.*,
                   (SELECT COUNT(*) FROM calls c WHERE c.domain_id = d.id) AS call_count,
                   (SELECT COUNT(*) FROM recipients r WHERE r.domain_id = d.id AND r.active = 1) AS recipient_count,
                   (SELECT COUNT(*) FROM keywords k WHERE (k.domain_id = d.id OR k.domain_id IS NULL) AND k.active = 1) AS keyword_count
                FROM domains d';

        if ($onlyEnabled) {
            $sql .= ' WHERE d.enabled = 1';
        }

        $sql .= ' ORDER BY d.domain ASC';

        return $this->db->select($sql);
    }

    /** @return list<array<string, mixed>> */
    public function pollable(): array
    {
        return $this->db->select('SELECT * FROM domains WHERE enabled = 1 AND poll_enabled = 1 ORDER BY id');
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM domains WHERE id = :id', ['id' => $id]);

        return $row ?: null;
    }

    public function findByDomain(string $domain): ?array
    {
        $row = $this->db->first('SELECT * FROM domains WHERE domain = :d', ['d' => $domain]);

        return $row ?: null;
    }

    public function ensure(string $domain, string $label = '', string $timezone = 'UTC'): array
    {
        $existing = $this->findByDomain($domain);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->db->insert('domains', [
            'domain' => $domain,
            'label' => $label !== '' ? $label : $domain,
            'timezone' => $timezone,
            'enabled' => 1,
            'poll_enabled' => 1,
            'last_polled_at' => null,
            'created_at' => Time::now(),
        ]);

        return $this->find($id) ?? [];
    }

    public function create(string $domain, string $label, string $timezone): int
    {
        return $this->db->insert('domains', [
            'domain' => $domain,
            'label' => $label,
            'timezone' => $timezone,
            'enabled' => 1,
            'poll_enabled' => 1,
            'created_at' => Time::now(),
        ]);
    }

    public function update(int $id, array $data): void
    {
        $allowed = array_intersect_key($data, array_flip(['label', 'timezone', 'enabled', 'poll_enabled']));
        if ($allowed === []) {
            return;
        }

        $this->db->update('domains', $allowed, 'id = :id', ['id' => $id]);
    }

    public function toggle(int $id, string $field): void
    {
        if (!in_array($field, ['enabled', 'poll_enabled'], true)) {
            return;
        }

        $this->db->execute(
            sprintf('UPDATE domains SET %s = CASE WHEN %s = 1 THEN 0 ELSE 1 END WHERE id = :id', $field, $field),
            ['id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM domains WHERE id = :id', ['id' => $id]);
    }

    public function touchPolled(int $id, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE domains SET last_polled_at = :at, last_poll_error = :err WHERE id = :id',
            ['at' => Time::now(), 'err' => $error ?? '', 'id' => $id],
        );
    }

    /** @return array<string, mixed> */
    public function stats(int $domainId): array
    {
        return [
            'calls' => (int) $this->db->scalar('SELECT COUNT(*) FROM calls WHERE domain_id = :d', ['d' => $domainId]),
            'alerts' => (int) $this->db->scalar('SELECT COUNT(*) FROM calls WHERE domain_id = :d AND match_count > 0', ['d' => $domainId]),
            'recipients' => (int) $this->db->scalar('SELECT COUNT(*) FROM recipients WHERE domain_id = :d AND active = 1', ['d' => $domainId]),
            'keywords' => (int) $this->db->scalar('SELECT COUNT(*) FROM keywords WHERE (domain_id = :d OR domain_id IS NULL) AND active = 1', ['d' => $domainId]),
        ];
    }
}
