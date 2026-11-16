<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Support\Time;

final class SettingsRepository
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /** @var array<string, string> */
    public const DEFAULTS = [
        'alerts_enabled' => '1',
        'alert_min_severity' => 'low',
        'alert_include_transcript' => '1',
        'alert_extra_recipients' => '',
        'default_report_recipients' => '',
        'transcript_max_attempts' => '8',
        'dashboard_days' => '30',
        'retention_days' => '365',
        'poll_window_hours' => '24',
        'processed_notice' => '',
    ];

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, ?string $default = null): string
    {
        $all = $this->all();

        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? '';
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower($this->get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :u)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['k' => $key, 'v' => $value, 'u' => Time::now()],
        );

        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, (string) $value);
        }
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $values = self::DEFAULTS;
        try {
            foreach ($this->db->select('SELECT key, value FROM settings') as $row) {
                $values[(string) $row['key']] = (string) $row['value'];
            }
        } catch (\PDOException) {
            // Table not migrated yet; defaults are enough to boot.
        }

        return $this->cache = $values;
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
