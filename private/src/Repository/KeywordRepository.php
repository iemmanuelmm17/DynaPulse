<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class KeywordRepository
{
    public const MATCH_TYPES = ['phrase', 'word', 'regex'];

    /** @var list<string> */
    public const FIELDS = ['topics', 'summary', 'transcript', 'caller_number', 'called_number'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Active keywords that apply to a domain: its own plus the global ones.
     *
     * @return list<array<string, mixed>>
     */
    public function forDomain(int $domainId, bool $activeOnly = true): array
    {
        $sql = 'SELECT k.*, d.domain AS domain_name FROM keywords k
                LEFT JOIN domains d ON d.id = k.domain_id
                WHERE (k.domain_id = :d OR k.domain_id IS NULL)';
        if ($activeOnly) {
            $sql .= ' AND k.active = 1';
        }
        $sql .= ' ORDER BY k.severity DESC, k.label ASC';

        return array_map([$this, 'hydrate'], $this->db->select($sql, ['d' => $domainId]));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->db->select(
            'SELECT k.*, d.domain AS domain_name FROM keywords k
             LEFT JOIN domains d ON d.id = k.domain_id
             ORDER BY (k.domain_id IS NULL) DESC, d.domain ASC, k.label ASC',
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM keywords WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Time::now();

        return $this->db->insert('keywords', [
            'domain_id' => $data['domain_id'] ?? null,
            'label' => (string) $data['label'],
            'pattern' => (string) $data['pattern'],
            'match_type' => in_array($data['match_type'] ?? '', self::MATCH_TYPES, true) ? $data['match_type'] : 'phrase',
            'fields' => json_encode(array_values($data['fields'] ?? ['summary', 'topics', 'transcript'])),
            'severity' => in_array($data['severity'] ?? '', self::SEVERITIES, true) ? $data['severity'] : 'medium',
            'case_sensitive' => (int) ($data['case_sensitive'] ?? 0),
            'active' => (int) ($data['active'] ?? 1),
            'notes' => (string) ($data['notes'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $payload = [];

        foreach (['label', 'pattern', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (string) $data[$field];
            }
        }
        if (array_key_exists('match_type', $data) && in_array($data['match_type'], self::MATCH_TYPES, true)) {
            $payload['match_type'] = $data['match_type'];
        }
        if (array_key_exists('severity', $data) && in_array($data['severity'], self::SEVERITIES, true)) {
            $payload['severity'] = $data['severity'];
        }
        if (array_key_exists('fields', $data)) {
            $payload['fields'] = json_encode(array_values((array) $data['fields']));
        }
        if (array_key_exists('case_sensitive', $data)) {
            $payload['case_sensitive'] = (int) (bool) $data['case_sensitive'];
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = (int) (bool) $data['active'];
        }
        if (array_key_exists('domain_id', $data)) {
            $payload['domain_id'] = $data['domain_id'] !== null ? (int) $data['domain_id'] : null;
        }

        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = Time::now();
        $this->db->update('keywords', $payload, 'id = :id', ['id' => $id]);
    }

    public function toggle(int $id): void
    {
        $this->db->execute('UPDATE keywords SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = :t WHERE id = :id', [
            't' => Time::now(),
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM keywords WHERE id = :id', ['id' => $id]);
    }

    public function activeCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM keywords WHERE active = 1');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $fields = json_decode((string) ($row['fields'] ?? '[]'), true);
        $row['fields'] = is_array($fields) ? array_values(array_intersect($fields, self::FIELDS)) : [];
        $row['active'] = (int) ($row['active'] ?? 1);
        $row['case_sensitive'] = (int) ($row['case_sensitive'] ?? 0);

        return $row;
    }
}
