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

    /**
     * URL prefix the portal is served from, e.g. "/dynapulse/public".
     * Empty string when the public folder is the site root.
     */
    public static string $basePath = '';

    /** @var list<array<string, mixed>> Domains available to the domain switcher (admins). */
    public static array $domains = [];

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = self::normaliseBasePath($basePath);
    }

    /** "/dynapulse/public/" and "/dynapulse/public/index.php" both become "/dynapulse/public". */
    public static function normaliseBasePath(string $basePath): string
    {
        $basePath = trim(str_replace('\\', '/', $basePath));

        if ($basePath === '' || $basePath === '/' || $basePath === '.') {
            return '';
        }

        if (!str_starts_with($basePath, '/')) {
            $basePath = '/' . $basePath;
        }

        $basePath = (string) preg_replace('#/index\.php$#i', '', $basePath);
        $basePath = rtrim((string) preg_replace('#/+#', '/', $basePath), '/');

        return $basePath === '/' ? '' : $basePath;
    }

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
