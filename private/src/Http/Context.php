<?php

declare(strict_types=1);

namespace DynaPulse\Http;

use DynaPulse\Support\Config;

/**
 * Request scoped singleton so helpers (e(), csrf_field(), can()) can reach the
 * current context without threading it through every template.
 */
final class Context
{
    public static ?Config $config = null;

    public static ?Request $request = null;

    public static ?Session $session = null;

    public static ?AuthUser $user = null;

    /** @var array<string, mixed>|null Selected domain row (null = all domains). */
    public static ?array $domainRow = null;

    /** Per-request nonce for the few inline scripts the layout needs. */
    public static string $cspNonce = '';

    /** @var list<array<string, mixed>> Domains available to the domain switcher (admins). */
    public static array $domains = [];

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config?->get($key, $default) ?? $default;
    }

    public static function user(): ?AuthUser
    {
        return self::$user;
    }

    public static function csrfToken(): string
    {
        return self::$session?->csrfToken() ?? '';
    }

    public static function can(string $ability): bool
    {
        return self::$user?->can($ability) ?? false;
    }

    public static function timezone(): string
    {
        return (string) (self::$domainRow['timezone'] ?? self::config('app.timezone', 'UTC'));
    }

    public static function domainId(): ?int
    {
        return self::$domainRow !== null ? (int) self::$domainRow['id'] : null;
    }

    public static function domainName(): ?string
    {
        return self::$domainRow !== null ? (string) self::$domainRow['domain'] : null;
    }
}
