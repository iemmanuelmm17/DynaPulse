<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Http\AuthUser;
use DynaPulse\Http\Session;
use DynaPulse\Repository\DomainRepository;

/**
 * Resolves which domain the current screen is scoped to. Managers are pinned to
 * their own domain, admins can switch to any domain or "All domains".
 */
final class DomainContext
{
    private ?array $resolved = null;

    private bool $resolvedFor = false;

    public function __construct(private Session $session, private DomainRepository $domains)
    {
    }

    public function current(?AuthUser $user): ?array
    {
        if ($user === null) {
            return null;
        }

        if ($this->resolvedFor) {
            return $this->resolved;
        }

        $this->resolvedFor = true;

        if ($user->isAdmin()) {
            $selected = $this->session->get('view_domain_id', 'all');

            if ($selected === 'all' || $selected === null) {
                return $this->resolved = null;
            }

            return $this->resolved = $this->domains->find((int) $selected);
        }

        $domain = $this->domains->findByDomain($user->domain);
        if ($domain === null && $user->domainId > 0) {
            $domain = $this->domains->find($user->domainId);
        }

        return $this->resolved = $domain;
    }

    public function id(?AuthUser $user): ?int
    {
        $domain = $this->current($user);

        return $domain !== null ? (int) $domain['id'] : null;
    }

    public function timezone(?AuthUser $user): string
    {
        $domain = $this->current($user);

        return (string) ($domain['timezone'] ?? 'UTC');
    }

    public function switchTo(?int $domainId): void
    {
        $this->session->put('view_domain_id', $domainId === null ? 'all' : (string) $domainId);
        $this->resolvedFor = false;
        $this->resolved = null;
    }

    public function reset(): void
    {
        $this->resolvedFor = false;
        $this->resolved = null;
    }
}
