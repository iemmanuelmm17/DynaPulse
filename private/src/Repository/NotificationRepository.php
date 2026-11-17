<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class NotificationRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param list<string> $recipients */
    public function queue(int $callId, int $domainId, array $recipients, string $subject, string $kind = 'alert'): ?int
    {
        if ($recipients === []) {
            return null;
        }

        $existing = $this->db->first(
            'SELECT id, status FROM notifications WHERE call_id = :c AND kind = :k',
            ['c' => $callId, 'k' => $kind],
        );

        if ($existing !== null) {
            // Retry failed/blocked rows, never resend something that went out.
            if (in_array($existing['status'], ['failed', 'blocked'], true)) {
                $this->db->update('notifications', [
                    'recipients' => json_encode(array_values($recipients)),
                    'subject' => $subject,
                    'status' => 'queued',
                    'error' => '',
                ], 'id = :id', ['id' => (int) $existing['id']]);

                return (int) $existing['id'];
            }

            return (int) $existing['id'];
        }

        return $this->db->insert('notifications', [
            'call_id' => $callId,
            'domain_id' => $domainId,
            'kind' => $kind,
            'recipients' => json_encode(array_values($recipients)),
            'subject' => $subject,
            'status' => 'queued',
            'created_at' => Time::now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function pending(int $limit = 20): array
    {
        return $this->db->select(
            "SELECT n.*, c.caller_number, c.called_number, c.started_at, d.domain AS domain_name
             FROM notifications n
             JOIN calls c ON c.id = n.call_id
             JOIN domains d ON d.id = n.domain_id
             WHERE n.status = 'queued'
             ORDER BY n.id ASC LIMIT :limit",
            ['limit' => $limit],
        );
    }

    /** @return list<array<string, mixed>> */
    public function forCall(int $callId): array
    {
        return $this->db->select('SELECT * FROM notifications WHERE call_id = :c ORDER BY id DESC', ['c' => $callId]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 10): array
    {
        return $this->db->select(
            "SELECT n.*, c.caller_number, c.called_number, d.domain AS domain_name
             FROM notifications n
             JOIN calls c ON c.id = n.call_id
             JOIN domains d ON d.id = n.domain_id
             ORDER BY n.id DESC LIMIT :limit",
            ['limit' => $limit],
        );
    }

    public function markSent(int $id): void
    {
        $this->db->execute(
            "UPDATE notifications SET status = 'sent', sent_at = :t, attempts = attempts + 1, error = '' WHERE id = :id",
            ['t' => Time::now(), 'id' => $id],
        );
    }

    public function markFailed(int $id, string $error, bool $retry = true): void
    {
        $this->db->execute(
            'UPDATE notifications SET status = :status, error = :err, attempts = attempts + 1 WHERE id = :id',
            ['status' => $retry ? 'queued' : 'failed', 'err' => mb_substr($error, 0, 900), 'id' => $id],
        );
    }

    public function markBlocked(int $id, string $reason): void
    {
        $this->db->execute(
            "UPDATE notifications SET status = 'blocked', error = :err WHERE id = :id",
            ['err' => mb_substr($reason, 0, 900), 'id' => $id],
        );
    }

    public function sentCount(int $days = 30): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notifications WHERE status = 'sent' AND created_at >= :since",
            ['since' => Time::daysAgo($days)],
        );
    }

    public function failedCount(int $days = 7): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notifications WHERE status IN ('failed', 'blocked') AND created_at >= :since",
            ['since' => Time::daysAgo($days)],
        );
    }
}
