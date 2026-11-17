<?php

declare(strict_types=1);

namespace DynaPulse\Database;

use DynaPulse\Support\Time;
use RuntimeException;

final class Migrator
{
    public function __construct(private Database $db, private string $directory)
    {
    }

    /** @return list<string> */
    public function applied(): array
    {
        try {
            $rows = $this->db->select('SELECT version FROM schema_migrations ORDER BY version');
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_diff($this->files(), $applied));
    }

    /** @return list<string> */
    public function run(): array
    {
        $executed = [];

        $this->ensureBookkeeping();

        foreach ($this->pending() as $version) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $version;
            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException('Cannot read migration ' . $version);
            }

            $this->db->transaction(function () use ($sql, $version, &$executed): void {
                foreach ($this->split($sql) as $statement) {
                    $this->db->pdo()->exec($statement);
                }

                $this->db->execute(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :a)',
                    ['v' => $version, 'a' => Time::now()],
                );

                $executed[] = $version;
            });
        }

        return $executed;
    }

    /** First run has no bookkeeping table yet; create it before recording anything. */
    private function ensureBookkeeping(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )',
        );
    }

    /** @return list<string> */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = array_values(array_filter(
            scandir($this->directory) ?: [],
            static fn (string $file): bool => str_ends_with($file, '.sql'),
        ));

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function split(string $sql): array
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $clean = [];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $clean[] = $statement;
        }

        return $clean;
    }
}
