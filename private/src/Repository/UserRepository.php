<?php

declare(strict_types=1);

namespace DynaPulse\Repository;

use DynaPulse\Database\Database;
use DynaPulse\Http\AuthUser;
use DynaPulse\Support\Time;

final class UserRepository
{
    public const ROLES = ['admin', 'manager', 'viewer'];

    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM users ORDER BY role = "admin" DESC, domain, username');
    }

    /** @return list<array<string, mixed>> */
    public function forDomain(string $domain): array
    {
        return $this->db->select('SELECT * FROM users WHERE domain = :d ORDER BY username', ['d' => $domain]);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        return $row ?: null;
    }

    public function findByCredentials(string $domain, string $username): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM users WHERE lower(domain) = lower(:d) AND lower(username) = lower(:u)',
            ['d' => $domain, 'u' => $username],
        );

        return $row ?: null;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users');
    }

    public function countAdmins(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
    }

    public function create(string $domain, string $username, string $email, string $displayName, string $role): int
    {
        return $this->db->insert('users', [
            'domain' => $domain,
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'role' => in_array($role, self::ROLES, true) ? $role : 'manager',
            'active' => 1,
            'created_at' => Time::now(),
        ]);
    }

    public function touchLogin(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET last_login_at = :t WHERE id = :id',
            ['t' => Time::now(), 'id' => $id],
        );
    }

    public function update(int $id, array $data): void
    {
        $allowed = [];

        if (array_key_exists('role', $data) && in_array($data['role'], self::ROLES, true)) {
            $allowed['role'] = $data['role'];
        }
        if (array_key_exists('active', $data)) {
            $allowed['active'] = (int) $data['active'];
        }
        if (array_key_exists('email', $data)) {
            $allowed['email'] = (string) $data['email'];
        }
        if (array_key_exists('display_name', $data)) {
            $allowed['display_name'] = (string) $data['display_name'];
        }

        if ($allowed === []) {
            return;
        }

        $this->db->update('users', $allowed, 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    public function toAuthUser(array $row, int $domainId): AuthUser
    {
        return new AuthUser(
            id: (int) $row['id'],
            domain: (string) $row['domain'],
            username: (string) $row['username'],
            email: (string) ($row['email'] ?? ''),
            displayName: (string) ($row['display_name'] ?? '') !== '' ? (string) $row['display_name'] : (string) $row['username'],
            role: (string) ($row['role'] ?? 'manager'),
            domainId: $domainId,
        );
    }
}
