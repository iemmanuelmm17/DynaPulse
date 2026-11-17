<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class MatchRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param list<array{keyword_id:?int, keyword_label:string, field:string, snippet:string, severity:string}> $matches */
    public function replaceForCall(int $callId, int $domainId, array $matches): void
    {
        $this->db->transaction(function () use ($callId, $domainId, $matches): void {
            $this->db->execute('DELETE FROM matches WHERE call_id = :id', ['id' => $callId]);

            foreach ($matches as $match) {
                $this->db->insert('matches', [
                    'call_id' => $callId,
                    'domain_id' => $domainId,
                    'keyword_id' => $match['keyword_id'],
                    'keyword_label' => $match['keyword_label'],
                    'field' => $match['field'],
                    'snippet' => $match['snippet'],
                    'severity' => $match['severity'],
                    'created_at' => Time::now(),
                ]);
            }

            $this->db->execute(
                'UPDATE calls SET match_count = :count WHERE id = :id',
                ['count' => count($matches), 'id' => $callId],
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function forCall(int $callId): array
    {
        return $this->db->select(
            'SELECT * FROM matches WHERE call_id = :id ORDER BY severity DESC, id ASC',
            ['id' => $callId],
        );
    }

    /**
     * @param list<int> $callIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forCalls(array $callIds): array
    {
        if ($callIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($callIds), '?'));
        $rows = $this->db->select(
            "SELECT * FROM matches WHERE call_id IN ({$placeholders}) ORDER BY severity DESC, id ASC",
            array_values($callIds),
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['call_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function query(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->filterSql($filters);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM matches m JOIN calls c ON c.id = m.call_id WHERE {$where}",
            $params,
        );

        $rows = $this->db->select(
            "SELECT m.*, c.started_at, c.caller_number, c.called_number, c.transcript_summary,
                    c.sentiment_label, c.has_recording, d.domain AS domain_name, d.timezone AS domain_timezone
             FROM matches m
             JOIN calls c ON c.id = m.call_id
             JOIN domains d ON d.id = c.domain_id
             WHERE {$where}
             ORDER BY c.started_at DESC, m.id DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);

        return (int) $this->db->scalar("SELECT COUNT(*) FROM matches m JOIN calls c ON c.id = m.call_id WHERE {$where}", $params);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array{label:string, severity:string, count:int}>
     */
    public function topKeywords(array $filters, int $limit = 8): array
    {
        [$where, $params] = $this->filterSql($filters);

        $rows = $this->db->select(
            "SELECT m.keyword_label AS label, m.severity, COUNT(*) AS total
             FROM matches m JOIN calls c ON c.id = m.call_id
             WHERE {$where}
             GROUP BY m.keyword_label, m.severity
             ORDER BY total DESC LIMIT :limit",
            $params + ['limit' => $limit],
        );

        return array_map(static fn (array $r): array => [
            'label' => (string) $r['label'],
            'severity' => (string) $r['severity'],
            'count' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string, mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function filterSql(array $filters): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        if (!empty($filters['domain_id'])) {
            $clauses[] = 'c.domain_id = :domain_id';
            $params['domain_id'] = (int) $filters['domain_id'];
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'c.started_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'c.started_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['keyword_id'])) {
            $clauses[] = 'm.keyword_id = :keyword_id';
            $params['keyword_id'] = (int) $filters['keyword_id'];
        }

        if (!empty($filters['severity'])) {
            $clauses[] = 'm.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }

        if (!empty($filters['q'])) {
            $clauses[] = '(m.keyword_label LIKE :q OR m.snippet LIKE :q OR c.caller_number LIKE :q OR c.called_number LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
