<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class CallRepository
{
    private const MERGE_TEXT_FIELDS = [
        'caller_number', 'caller_name', 'called_number', 'called_name', 'answered_by',
        'disconnect_reason', 'disposition', 'recording_url', 'last_error',
    ];

    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $call */
    public function upsertFromCdr(int $domainId, array $call): array
    {
        $existing = $this->db->first(
            'SELECT * FROM calls WHERE domain_id = :d AND call_id = :c',
            ['d' => $domainId, 'c' => $call['call_id']],
        );

        $now = Time::now();

        if ($existing === null) {
            $id = $this->db->insert('calls', [
                'domain_id' => $domainId,
                'call_id' => $call['call_id'],
                'orig_call_id' => $call['orig_call_id'] ?? null,
                'term_call_id' => $call['term_call_id'] ?? null,
                'direction' => $call['direction'] ?? 'Unknown',
                'caller_number' => $call['caller_number'] ?? '',
                'caller_name' => $call['caller_name'] ?? '',
                'called_number' => $call['called_number'] ?? '',
                'called_name' => $call['called_name'] ?? '',
                'answered_by' => $call['answered_by'] ?? '',
                'started_at' => $call['started_at'] ?? null,
                'answered_at' => $call['answered_at'] ?? null,
                'ended_at' => $call['ended_at'] ?? null,
                'duration_seconds' => (int) ($call['duration_seconds'] ?? 0),
                'talk_seconds' => (int) ($call['talk_seconds'] ?? 0),
                'hold_seconds' => (int) ($call['hold_seconds'] ?? 0),
                'disconnect_reason' => $call['disconnect_reason'] ?? '',
                'disposition' => $call['disposition'] ?? '',
                'transcript_job_id' => (string) ($call['transcript_job_id'] ?? ''),
                'sentiment_positive' => (int) ($call['sentiment_positive'] ?? 0),
                'sentiment_neutral' => (int) ($call['sentiment_neutral'] ?? 0),
                'sentiment_negative' => (int) ($call['sentiment_negative'] ?? 0),
                'raw_cdr' => json_encode($call['raw'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}',
                'status' => 'pending',
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->find($id) ?? [];
        }

        $merged = [
            'updated_at' => $now,
            'orig_call_id' => $existing['orig_call_id'] ?: ($call['orig_call_id'] ?? null),
            'term_call_id' => $existing['term_call_id'] ?: ($call['term_call_id'] ?? null),
            'direction' => ($existing['direction'] ?? 'Unknown') !== 'Unknown'
                ? $existing['direction']
                : ($call['direction'] ?? 'Unknown'),
            'started_at' => self::minDate($existing['started_at'] ?? null, $call['started_at'] ?? null),
            'answered_at' => $existing['answered_at'] ?: ($call['answered_at'] ?? null),
            'ended_at' => self::maxDate($existing['ended_at'] ?? null, $call['ended_at'] ?? null),
            'duration_seconds' => max((int) $existing['duration_seconds'], (int) ($call['duration_seconds'] ?? 0)),
            'talk_seconds' => max((int) $existing['talk_seconds'], (int) ($call['talk_seconds'] ?? 0)),
            'hold_seconds' => max((int) $existing['hold_seconds'], (int) ($call['hold_seconds'] ?? 0)),
        ];

        foreach (self::MERGE_TEXT_FIELDS as $field) {
            $value = $call[$field] ?? '';
            if ($value !== '' && $value !== null) {
                $merged[$field] = $value;
            }
        }

        if (($call['transcript_job_id'] ?? '') !== '') {
            $merged['transcript_job_id'] = (string) $call['transcript_job_id'];
        }

        // A later CDR leg can only improve the sentiment data.
        foreach (['sentiment_positive', 'sentiment_neutral', 'sentiment_negative'] as $field) {
            if ((int) ($call[$field] ?? 0) > (int) ($existing[$field] ?? 0)) {
                $merged[$field] = (int) $call[$field];
            }
        }

        if (($call['raw'] ?? []) !== []) {
            $merged['raw_cdr'] = json_encode($call['raw'], JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        // Give a finished call another pass when a transcript id shows up late.
        if ($existing['status'] === 'complete'
            && $merged['transcript_job_id'] !== ''
            && (int) $existing['has_transcript'] === 0
            && $existing['transcript_status'] !== 'unavailable'
        ) {
            $merged['status'] = 'pending';
            $merged['next_attempt_at'] = $now;
        }

        $this->db->update('calls', $merged, 'id = :id', ['id' => (int) $existing['id']]);

        return $this->find((int) $existing['id']) ?? [];
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first(
            'SELECT c.*, d.domain AS domain_name, d.label AS domain_label, d.timezone AS domain_timezone
             FROM calls c JOIN domains d ON d.id = c.domain_id WHERE c.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : self::hydrate($row);
    }

    public function findByCallId(int $domainId, string $callId): ?array
    {
        $id = $this->db->scalar(
            'SELECT id FROM calls WHERE domain_id = :d AND call_id = :c',
            ['d' => $domainId, 'c' => $callId],
        );

        return $id === null ? null : $this->find((int) $id);
    }

    /** @return list<array<string, mixed>> */
    public function dueForProcessing(int $limit = 20): array
    {
        $rows = $this->db->select(
            "SELECT c.*, d.domain AS domain_name, d.timezone AS domain_timezone
             FROM calls c JOIN domains d ON d.id = c.domain_id
             WHERE c.status = 'pending' AND (c.next_attempt_at IS NULL OR c.next_attempt_at <= :now)
             ORDER BY c.id ASC LIMIT :limit",
            ['now' => Time::now(), 'limit' => $limit],
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    public function markProcessing(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE calls SET status = 'processing', attempts = attempts + 1, updated_at = :t
             WHERE id = :id AND status = 'pending'",
            ['t' => Time::now(), 'id' => $id],
        );

        return $affected > 0;
    }

    /** @param array<string, mixed> $data */
    public function saveProcessed(int $id, array $data): void
    {
        $data['updated_at'] = Time::now();
        $this->db->update('calls', $data, 'id = :id', ['id' => $id]);
    }

    public function scheduleRetry(int $id, string $reason, int $delaySeconds): void
    {
        $this->db->execute(
            "UPDATE calls SET status = 'pending', last_error = :err, next_attempt_at = :next, updated_at = :t WHERE id = :id",
            [
                'err' => $reason,
                'next' => Time::addSeconds(Time::now(), $delaySeconds),
                't' => Time::now(),
                'id' => $id,
            ],
        );
    }

    public function pendingCount(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM calls WHERE status IN ('pending', 'processing')",
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function query(array $filters, int $limit = 50, int $offset = 0, string $sort = 'recent'): array
    {
        [$where, $params] = $this->filterSql($filters);
        $order = $this->orderSql($sort);

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM calls c WHERE ' . $where, $params);

        $rows = $this->db->select(
            "SELECT c.*, d.domain AS domain_name, d.timezone AS domain_timezone
             FROM calls c JOIN domains d ON d.id = c.domain_id
             WHERE {$where} {$order} LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return [
            'rows' => array_map([self::class, 'hydrate'], $rows),
            'total' => $total,
        ];
    }

    /** @param array<string, mixed> $filters */
    /**
     * Calls that carry a Call Intelligence job id but never got their transcript
     * stored (wrong identifiers, API hiccup, interrupted run). Used by
     * `console.php calls:reprocess`.
     *
     * @return list<int>
     */
    public function idsWithoutTranscript(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $rows = $this->db->select(
            "SELECT id FROM calls
             WHERE has_transcript = 0
               AND transcript_job_id <> ''
               AND transcript_status IN ('pending', 'unavailable', 'error')
             ORDER BY COALESCE(started_at, created_at) DESC
             LIMIT " . $limit,
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** Newest started_at in the table, or null when nothing was ever ingested. */
    public function latestStartedAt(): ?string
    {
        $value = $this->db->scalar('SELECT MAX(started_at) FROM calls');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function stats(array $filters): array
    {
        [$where, $params] = $this->filterSql($filters);

        $row = $this->db->first(
            "SELECT COUNT(*) AS calls,
                    SUM(CASE WHEN has_transcript = 1 THEN 1 ELSE 0 END) AS with_transcript,
                    SUM(CASE WHEN has_recording = 1 THEN 1 ELSE 0 END) AS with_recording,
                    SUM(CASE WHEN match_count > 0 THEN 1 ELSE 0 END) AS alert_calls,
                    SUM(match_count) AS matches,
                    COALESCE(AVG(NULLIF(duration_seconds, 0)), 0) AS avg_duration,
                    COALESCE(SUM(talk_seconds), 0) AS talk_seconds,
                    SUM(CASE WHEN sentiment_label = 'positive' THEN 1 ELSE 0 END) AS positive,
                    SUM(CASE WHEN sentiment_label = 'neutral' THEN 1 ELSE 0 END) AS neutral,
                    SUM(CASE WHEN sentiment_label = 'negative' THEN 1 ELSE 0 END) AS negative,
                    SUM(CASE WHEN sentiment_label = 'mixed' THEN 1 ELSE 0 END) AS mixed,
                    SUM(CASE WHEN alert_status = 'sent' THEN 1 ELSE 0 END) AS alerts_sent
             FROM calls c WHERE {$where}",
            $params,
        ) ?? [];

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'with_transcript' => (int) ($row['with_transcript'] ?? 0),
            'with_recording' => (int) ($row['with_recording'] ?? 0),
            'alert_calls' => (int) ($row['alert_calls'] ?? 0),
            'matches' => (int) ($row['matches'] ?? 0),
            'avg_duration' => (int) round((float) ($row['avg_duration'] ?? 0)),
            'talk_seconds' => (int) ($row['talk_seconds'] ?? 0),
            'positive' => (int) ($row['positive'] ?? 0),
            'neutral' => (int) ($row['neutral'] ?? 0),
            'negative' => (int) ($row['negative'] ?? 0),
            'mixed' => (int) ($row['mixed'] ?? 0),
            'alerts_sent' => (int) ($row['alerts_sent'] ?? 0),
        ];
    }

    /**
     * Daily buckets in the requested timezone (UTC storage -> local display).
     *
     * @param array<string, mixed> $filters
     * @return list<array{date:string, label:string, calls:int, alerts:int, negative:int}>
     */
    public function dailySeries(array $filters, string $timezone, int $days = 14): array
    {
        [$where, $params] = $this->filterSql($filters);

        $rows = $this->db->select(
            "SELECT started_at, match_count, sentiment_label FROM calls c
             WHERE {$where} AND started_at IS NOT NULL ORDER BY started_at ASC LIMIT 20000",
            $params,
        );

        $buckets = [];
        $start = new \DateTimeImmutable('today', Time::zone($timezone));
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $start->modify("-{$i} days");
            $buckets[$day->format('Y-m-d')] = [
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('M j'),
                'calls' => 0,
                'alerts' => 0,
                'negative' => 0,
            ];
        }

        foreach ($rows as $row) {
            $key = Time::toLocal((string) $row['started_at'], $timezone)->format('Y-m-d');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['calls']++;
            if ((int) $row['match_count'] > 0) {
                $buckets[$key]['alerts']++;
            }
            if ($row['sentiment_label'] === 'negative') {
                $buckets[$key]['negative']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array{topic:string, count:int}>
     */
    public function topTopics(array $filters, int $limit = 10): array
    {
        [$where, $params] = $this->filterSql($filters);

        $rows = $this->db->select(
            "SELECT transcript_topics FROM calls c
             WHERE {$where} AND has_transcript = 1 AND transcript_topics <> '[]' LIMIT 5000",
            $params,
        );

        $counts = [];
        foreach ($rows as $row) {
            $topics = json_decode((string) $row['transcript_topics'], true);
            if (!is_array($topics)) {
                continue;
            }
            foreach (array_unique($topics) as $topic) {
                $topic = trim((string) $topic);
                if ($topic === '') {
                    continue;
                }
                $key = mb_strtolower($topic);
                $counts[$key] ??= ['topic' => $topic, 'count' => 0];
                $counts[$key]['count']++;
            }
        }

        usort($counts, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice(array_values($counts), 0, $limit);
    }

    /** @param array<string, mixed> $filters */
    public function countMatchingAlerts(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);

        return (int) $this->db->scalar("SELECT COUNT(*) FROM calls c WHERE {$where} AND match_count > 0", $params);
    }

    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM calls WHERE started_at IS NOT NULL AND started_at < :cutoff',
            ['cutoff' => Time::daysAgo($days)],
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filterSql(array $filters): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        if (!empty($filters['domain_id'])) {
            $clauses[] = 'c.domain_id = :domain_id';
            $params['domain_id'] = (int) $filters['domain_id'];
        }

        if (!empty($filters['domains']) && is_array($filters['domains'])) {
            $placeholders = [];
            foreach (array_values($filters['domains']) as $i => $domainId) {
                $key = 'dom' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $domainId;
            }
            $clauses[] = 'c.domain_id IN (' . implode(', ', $placeholders) . ')';
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
            $clauses[] = 'EXISTS (SELECT 1 FROM matches m WHERE m.call_id = c.id AND m.keyword_id = :keyword_id)';
            $params['keyword_id'] = (int) $filters['keyword_id'];
        }

        if (!empty($filters['severity'])) {
            $clauses[] = "EXISTS (SELECT 1 FROM matches m WHERE m.call_id = c.id AND m.severity = :severity)";
            $params['severity'] = (string) $filters['severity'];
        }

        if (!empty($filters['sentiment'])) {
            $clauses[] = 'c.sentiment_label = :sentiment';
            $params['sentiment'] = (string) $filters['sentiment'];
        }

        if (!empty($filters['direction'])) {
            $clauses[] = 'c.direction = :direction';
            $params['direction'] = (string) $filters['direction'];
        }

        if (array_key_exists('has_recording', $filters) && $filters['has_recording'] !== null && $filters['has_recording'] !== '') {
            $clauses[] = 'c.has_recording = :has_recording';
            $params['has_recording'] = (int) (bool) $filters['has_recording'];
        }

        if (array_key_exists('has_transcript', $filters) && $filters['has_transcript'] !== null && $filters['has_transcript'] !== '') {
            $clauses[] = 'c.has_transcript = :has_transcript';
            $params['has_transcript'] = (int) (bool) $filters['has_transcript'];
        }

        if (!empty($filters['alert_only'])) {
            $clauses[] = 'c.match_count > 0';
        }

        if (!empty($filters['status'])) {
            $clauses[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['q'])) {
            $clauses[] = '(c.caller_number LIKE :q OR c.called_number LIKE :q OR c.caller_name LIKE :q
                           OR c.called_name LIKE :q OR c.transcript_summary LIKE :q OR c.transcript_text LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function orderSql(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'ORDER BY c.started_at ASC, c.id ASC',
            'alerts' => 'ORDER BY c.match_count DESC, c.started_at DESC',
            'duration' => 'ORDER BY c.duration_seconds DESC, c.started_at DESC',
            'sentiment' => "ORDER BY CASE c.sentiment_label WHEN 'negative' THEN 0 WHEN 'mixed' THEN 1 WHEN 'neutral' THEN 2 ELSE 3 END, c.started_at DESC",
            default => 'ORDER BY c.started_at DESC, c.id DESC',
        };
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function hydrate(array $row): array
    {
        $row['transcript_topics'] = json_decode((string) ($row['transcript_topics'] ?? '[]'), true) ?: [];
        $row['transcript_segments'] = json_decode((string) ($row['transcript_segments'] ?? '[]'), true) ?: [];
        $row['has_transcript'] = (int) ($row['has_transcript'] ?? 0);
        $row['has_recording'] = (int) ($row['has_recording'] ?? 0);
        $row['match_count'] = (int) ($row['match_count'] ?? 0);

        return $row;
    }

    private static function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }

        return min($a, $b);
    }

    private static function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }

        return max($a, $b);
    }
}
