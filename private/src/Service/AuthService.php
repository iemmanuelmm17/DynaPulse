<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Http\AuthUser;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\AuditRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\RateLimiter;
use DynaPulse\Repository\UserRepository;
use DynaPulse\Support\Logger;

/**
 * Managers sign in with their NetSapiens credentials. DynaPulse validates them
 * against /tokens and keeps its own role mapping so admins can grant or revoke
 * portal access without touching the phone system.
 */
final class AuthService
{
    public function __construct(
        private NsGateway $ns,
        private UserRepository $users,
        private DomainRepository $domains,
        private RateLimiter $limiter,
        private AuditRepository $audit,
    ) {
    }

    public function attempt(string $login, string $password, string $ip): AuthUser
    {
        [$domain, $username] = self::splitLogin($login);

        if ($username === '' || $password === '') {
            throw new AuthException('Enter your extension / email and password.', 'empty');
        }

        $identity = mb_strtolower($username . '@' . $domain);

        if ($this->limiter->tooManyAttempts($identity, $ip)) {
            throw new AuthException('Too many failed attempts. Try again in a few minutes.', 'throttled');
        }

        $token = $this->validate($identity, $password, $ip);

        $nsDomain = strtolower(trim((string) ($token['domain'] ?? $domain)));
        $nsUser = (string) ($token['user'] ?? $username);

        if ($nsDomain === '' || in_array($nsDomain, ['*', '~'], true)) {
            $nsDomain = $domain !== '' ? $domain : 'all';
        }

        $email = (string) ($token['user_email'] ?? '');
        $displayName = trim((string) ($token['displayName'] ?? '')) ?: self::prettyName($nsUser);

        $domainRow = $nsDomain === 'all' ? null : $this->domains->findByDomain($nsDomain);
        if ($domainRow === null && $nsDomain !== 'all') {
            $domainRow = $this->domains->ensure($nsDomain, $nsDomain);
        }

        $domainId = $domainRow !== null ? (int) $domainRow['id'] : 0;
        $record = $this->users->findByCredentials($nsDomain, $nsUser);

        if ($record === null) {
            // Bootstrap: the very first successful login becomes the admin.
            $role = $this->users->count() === 0 ? 'admin' : 'manager';
            $id = $this->users->create($nsDomain, $nsUser, $email, $displayName, $role);
            $record = $this->users->find($id);
            Logger::info('auth.provisioned', ['user' => $nsUser, 'domain' => $nsDomain, 'role' => $role]);
        }

        if ($record === null || (int) $record['active'] !== 1) {
            throw new AuthException('This portal account has been disabled by an administrator.', 'disabled');
        }

        $this->users->touchLogin((int) $record['id']);
        $this->limiter->clear($identity);
        $this->audit->log('auth.login', (int) $record['id'], $domainId > 0 ? $domainId : null, ['domain' => $nsDomain], $ip);

        return $this->users->toAuthUser($record + ['email' => $record['email'] ?: $email], $domainId);
    }

    /** @return array<string, mixed> */
    private function validate(string $identity, string $password, string $ip): array
    {
        try {
            return $this->ns->token($identity, $password);
        } catch (NsException $e) {
            $this->limiter->record($identity, $ip, false);
            $this->audit->log('auth.failed', null, null, ['identity' => $identity, 'status' => $e->status], $ip);

            if ($e->status === 500) {
                throw new AuthException($e->getMessage(), 'config');
            }

            throw new AuthException('Those credentials were not accepted by the phone system.', 'invalid');
        }
    }

    /** @return array{0:string, 1:string} */
    public static function splitLogin(string $login): array
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            // user@domain.tld or user@domain. Keep the last @ as the separator.
            $position = strrpos($login, '@');
            $user = substr($login, 0, (int) $position);
            $domain = substr($login, (int) $position + 1);

            return [trim($domain), trim($user)];
        }

        return ['', $login];
    }

    private static function prettyName(string $username): string
    {
        $username = str_replace(['.', '_', '-'], ' ', $username);

        return ucwords(trim($username));
    }
}
