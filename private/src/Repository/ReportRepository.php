<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class ReportRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function schedules(): array
    {
        $rows = $this->db->select(
            'SELECT s.*, d.domain AS domain_name FROM report_schedules s
             LEFT JOIN domains d ON d.id = s.domain_id
             ORDER BY s.active DESC, s.name ASC',
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    public function findSchedule(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM report_schedules WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<array<string, mixed>> */
    public function dueSchedules(): array
    {
        $rows = $this->db->select(
            "SELECT s.*, d.domain AS domain_name FROM report_schedules s
             LEFT JOIN domains d ON d.id = s.domain_id
             WHERE s.active = 1 AND (s.next_run_at IS NULL OR s.next_run_at <= :now)
             ORDER BY s.next_run_at ASC",
            ['now' => Time::now()],
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @param array<string, mixed> $data */
    public function createSchedule(array $data): int
    {
        return $this->db->insert('report_schedules', [
            'name' => (string) $data['name'],
            'domain_id' => $data['domain_id'] ?? null,
            'frequency' => (string) ($data['frequency'] ?? 'weekly'),
            'weekday' => (int) ($data['weekday'] ?? 1),
            'day_of_month' => (int) ($data['day_of_month'] ?? 1),
            'send_time' => (string) ($data['send_time'] ?? '07:00'),
            'period_days' => (int) ($data['period_days'] ?? 7),
            'recipients' => json_encode(array_values($data['recipients'] ?? [])),
            'include_csv' => (int) ($data['include_csv'] ?? 1),
            'active' => (int) ($data['active'] ?? 1),
            'next_run_at' => $data['next_run_at'] ?? null,
            'created_at' => Time::now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateSchedule(int $id, array $data): void
    {
        $payload = [];

        foreach (['name', 'frequency', 'send_time'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (string) $data[$field];
            }
        }
        foreach (['weekday', 'day_of_month', 'period_days', 'include_csv', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (int) $data[$field];
            }
        }
        if (array_key_exists('domain_id', $data)) {
            $payload['domain_id'] = $data['domain_id'] !== null ? (int) $data['domain_id'] : null;
        }
        if (array_key_exists('recipients', $data)) {
            $payload['recipients'] = json_encode(array_values((array) $data['recipients']));
        }
        if (array_key_exists('next_run_at', $data)) {
            $payload['next_run_at'] = $data['next_run_at'];
        }
        if (array_key_exists('last_error', $data)) {
            $payload['last_error'] = (string) $data['last_error'];
        }

        if ($payload === []) {
            return;
        }

        $this->db->update('report_schedules', $payload, 'id = :id', ['id' => $id]);
    }

    public function markRun(int $id, string $nextRunAt, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE report_schedules SET last_run_at = :ran, next_run_at = :next, last_error = :err WHERE id = :id',
            ['ran' => Time::now(), 'next' => $nextRunAt, 'err' => (string) $error, 'id' => $id],
        );
    }

    public function deleteSchedule(int $id): void
    {
        $this->db->execute('DELETE FROM report_schedules WHERE id = :id', ['id' => $id]);
    }

    /** @param list<string> $recipients */
    public function recordRun(int $scheduleId, string $status, int $calls, int $alerts, array $recipients, string $error = ''): void
    {
        $this->db->insert('report_runs', [
            'schedule_id' => $scheduleId,
            'ran_at' => Time::now(),
            'status' => $status,
            'calls_count' => $calls,
            'alerts_count' => $alerts,
            'recipients' => json_encode(array_values($recipients)),
            'error' => mb_substr($error, 0, 900),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function runs(int $limit = 20): array
    {
        return $this->db->select(
            'SELECT r.*, s.name AS schedule_name FROM report_runs r
             LEFT JOIN report_schedules s ON s.id = r.schedule_id
             ORDER BY r.ran_at DESC LIMIT :limit',
            ['limit' => $limit],
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function hydrate(array $row): array
    {
        $row['recipients'] = json_decode((string) ($row['recipients'] ?? '[]'), true) ?: [];
        $row['active'] = (int) ($row['active'] ?? 1);
        $row['include_csv'] = (int) ($row['include_csv'] ?? 1);

        return $row;
    }
}
