<?php

declare(strict_types=1);

use DynaPulse\Http\Context;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

if (!function_exists('e')) {
    /** HTML-escape any scalar for output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = (string) Context::config('app.url', '');
        $base = $base !== '' ? rtrim($base, '/') : '';

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('path_url')) {
    /** Relative URL, safe behind any reverse proxy / subfolder. */
    function path_url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $public = (string) Context::config('paths.public', '');
        $file = $public . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return path_url('assets/' . ltrim($path, '/')) . '?v=' . $version;
    }
}

if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return \DynaPulse\Http\Context::$cspNonce;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Context::csrfToken()) . '">';
    }
}

if (!function_exists('user')) {
    function user(): ?DynaPulse\Http\AuthUser
    {
        return Context::$user;
    }
}

if (!function_exists('can')) {
    function can(string $ability): bool
    {
        return Context::can($ability);
    }
}

if (!function_exists('flashes')) {
    /** @return list<array{type:string,message:string,title:?string}> */
    function flashes(): array
    {
        return Context::$session?->pullFlashes() ?? [];
    }
}

if (!function_exists('tz_date')) {
    function tz_date(?string $utc, string $format = 'M j, Y g:i A', ?string $timezone = null): string
    {
        return Time::display($utc, $timezone ?? Context::timezone(), $format);
    }
}

if (!function_exists('fmt_duration')) {
    function fmt_duration(?int $seconds): string
    {
        return Time::humanDuration($seconds);
    }
}

if (!function_exists('initials')) {
    function initials(?string $name, string $fallback = 'DP'): string
    {
        return Str::initials($name, $fallback);
    }
}

if (!function_exists('pretty_phone')) {
    function pretty_phone(?string $number): string
    {
        return Str::prettyPhone($number);
    }
}

if (!function_exists('excerpt')) {
    function excerpt(?string $text, int $limit = 160): string
    {
        return Str::limit($text, $limit);
    }
}

if (!function_exists('severity_class')) {
    function severity_class(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical' => 'badge badge--critical',
            'high' => 'badge badge--danger',
            'medium' => 'badge badge--warning',
            'low' => 'badge badge--info',
            default => 'badge',
        };
    }
}

if (!function_exists('sentiment_class')) {
    function sentiment_class(?string $label): string
    {
        return match (strtolower((string) $label)) {
            'positive' => 'sentiment sentiment--positive',
            'negative' => 'sentiment sentiment--negative',
            'mixed' => 'sentiment sentiment--mixed',
            'neutral' => 'sentiment sentiment--neutral',
            default => 'sentiment sentiment--none',
        };
    }
}

if (!function_exists('active_if')) {
    function active_if(string $prefix, string $class = 'is-active'): string
    {
        $path = Context::$request?->path() ?? '/';

        if ($prefix === '/') {
            return $path === '/' ? $class : '';
        }

        return str_starts_with($path, $prefix) ? $class : '';
    }
}

if (!function_exists('query_with')) {
    /** @param array<string, mixed> $overrides */
    function query_with(array $overrides): string
    {
        $query = array_merge(Context::$request?->queryAll() ?? [], $overrides);
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}

if (!function_exists('is_route')) {
    function is_route(string $path): bool
    {
        return (Context::$request?->path() ?? '/') === $path;
    }
}

if (!function_exists('icon')) {
    /**
     * Inline SVG icon set (stroke based, 20x20). Keeping the paths in one place
     * avoids an icon font or a sprite request.
     */
    function icon(string $name, string $class = 'icon'): string
    {
        $paths = [
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'chart' => '<path d="M3 3v18h18"/><path d="M7 15l3-4 3 3 4-6"/>',
            'layers' => '<path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
            'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.9 4.9 1.4 1.4"/><path d="m17.7 17.7 1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m4.9 19.1 1.4-1.4"/><path d="m17.7 6.3 1.4-1.4"/>',
            'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
            'play' => '<path d="M6 3.5v17l14-8.5-14-8.5z"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
            'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'trash' => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'filter' => '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3z"/>',
            'alert' => '<path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'wave' => '<path d="M2 12h3l2-7 4 14 3-9 2 2h6"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-5L5.5 5z"/>',
        ];

        $path = $paths[$name] ?? $paths['grid'];

        return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
}
