<?php

declare(strict_types=1);

namespace DynaPulse\Http;

/** Immutable view of the signed-in operator. */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $domain,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly int $domainId,
    ) {
    }

    public function uid(): string
    {
        return $this->username . '@' . $this->domain;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    public function can(string $ability): bool
    {
        return match ($ability) {
            'admin' => $this->isAdmin(),
            'manage' => $this->isAdmin() || $this->isManager(),
            'view' => true,
            'manage-all-domains' => $this->isAdmin(),
            default => false,
        };
    }

    public function roleLabel(): string
    {
        return ucfirst($this->role);
    }
}
