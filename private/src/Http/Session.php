<?php

declare(strict_types=1);

namespace DynaPulse\Http;

/**
 * Thin, testable wrapper over PHP sessions. Flash messages survive exactly one
 * redirect and are exposed to the layout as toasts.
 */
final class Session
{
    private bool $started = false;

    /** @param array<string, mixed> $store */
    public function __construct(
        private array $store = [],
        private string $name = 'dynapulse',
        private string $savePath = '',
        private int $lifetimeMinutes = 480,
        private bool $secure = false,
    ) {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;

            return;
        }

        if ($this->savePath !== '' && is_dir($this->savePath)) {
            session_save_path($this->savePath);
        }

        session_name($this->name);
        $basePath = Context::basePath();

        session_set_cookie_params([
            'lifetime' => 0,
            // Scoped to the sub-folder when the portal is not at the site root.
            'path' => $basePath === '' ? '/' : $basePath . '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->secure,
        ]);
        session_start();

        $this->store = $_SESSION ?? [];
        $this->started = true;

        $this->expireIdle();
    }

    private function expireIdle(): void
    {
        $last = (int) ($this->store['_last_seen'] ?? 0);
        $expired = $last > 0 && (time() - $last) > ($this->lifetimeMinutes * 60);

        if ($expired) {
            $this->store = [];
            $this->persist();
        }

        $this->store['_last_seen'] = time();
        $this->persist();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
        $this->persist();
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
        $this->persist();
    }

    public function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->store['_csrf'] = bin2hex(random_bytes(24));
        $this->persist();
    }

    public function destroy(): void
    {
        $this->store = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $this->started = false;
    }

    public function userId(): ?int
    {
        $id = $this->get('user_id');

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public function login(int $userId): void
    {
        $this->regenerate();
        $this->put('user_id', $userId);
        $this->put('_last_seen', time());
    }

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(24));
            $this->put('_csrf', $token);
        }

        return $token;
    }

    public function flash(string $type, string $message, ?string $title = null): void
    {
        $flashes = $this->get('_flash', []);
        if (!is_array($flashes)) {
            $flashes = [];
        }
        $flashes[] = ['type' => $type, 'message' => $message, 'title' => $title];
        $this->put('_flash', $flashes);
    }

    /** @return list<array{type:string,message:string,title:?string}> */
    public function pullFlashes(): array
    {
        $flashes = $this->get('_flash', []);
        $this->forget('_flash');

        return is_array($flashes) ? array_values($flashes) : [];
    }

    public function previousUrl(?string $default = null): ?string
    {
        $url = $this->get('_previous_url');

        return is_string($url) && $url !== '' ? $url : $default;
    }

    public function storePreviousUrl(?string $url): void
    {
        if ($url !== null && $url !== '') {
            $this->put('_previous_url', $url);
        }
    }

    public function persist(): void
    {
        if (PHP_SAPI !== 'cli') {
            $_SESSION = $this->store;
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->store;
    }
}
