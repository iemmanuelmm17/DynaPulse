<?php

declare(strict_types=1);

namespace DynaPulse\Http;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $post @param array<string, mixed> $server @param array<string, mixed> $files @param array<string, string> $headers */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $server = [],
        private array $files = [],
        private array $headers = [],
        private string $rawBody = '',
        private string $basePath = '',
    ) {
        $this->method = strtoupper($method);
        $this->path = '/' . trim($path, '/');
        $this->basePath = Context::normaliseBasePath($basePath);
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Support method override for HTML forms.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        // The portal may live in a sub-folder (D:\www\dynapulse\public), so the
        // request path has to lose that prefix before the router sees it.
        $basePath = self::detectBasePath($path);
        $path = self::stripBasePath($path, $basePath);

        // /index.php and /index.php/login are the same routes as / and /login.
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $headers,
            (string) file_get_contents('php://input'),
            $basePath,
        );
    }

    /** URL prefix the app is served from ("" when the public folder is the site root). */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Work out the sub-folder from what the web server tells us. Every candidate
     * is only accepted when it really is a prefix of the requested path, so a
     * wrong guess can never break a normal deployment.
     */
    public static function detectBasePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        foreach (self::basePathCandidates() as $candidate) {
            $prefix = $candidate['path'];

            if ($prefix === '' || $prefix === '/') {
                continue;
            }

            if (str_starts_with($path, $prefix . '/')) {
                return $prefix;
            }

            // A whole-URL match is only trusted when the source really looks like
            // a folder; on IIS SCRIPT_NAME may echo the requested route instead.
            if ($candidate['exact'] && $path === $prefix) {
                return $prefix;
            }
        }

        return '';
    }

    public static function stripBasePath(string $path, string $basePath): string
    {
        $path = '/' . ltrim($path, '/');
        $basePath = Context::normaliseBasePath($basePath);

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * @return list<array{path: string, exact: bool}>
     *   `exact` = a candidate equal to the whole request path is believable,
     *   because the source described a folder rather than a route.
     */
    private static function basePathCandidates(): array
    {
        $candidates = [];

        // 1. Explicit override: DYN_PULSE_BASE or public/base_path.php.
        $forced = getenv('DYN_PULSE_BASE');
        if (is_string($forced) && trim($forced) !== '') {
            $candidates[] = ['path' => trim($forced), 'exact' => true];
        }

        // 2. Physical location of the running script relative to the document root.
        //    Most reliable signal on IIS, Apache and nginx.
        $documentRoot = rtrim(self::normalisePath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $scriptFile = self::normalisePath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        if ($documentRoot !== '' && $scriptFile !== '') {
            $scriptDir = rtrim(self::normalisePath(dirname($scriptFile)), '/');

            if (strcasecmp($scriptDir, $documentRoot) === 0) {
                $candidates[] = ['path' => '', 'exact' => true];
            } elseif ($scriptDir !== '' && stripos($scriptDir, $documentRoot . '/') === 0) {
                $candidates[] = [
                    'path' => substr($scriptDir, strlen($documentRoot)),
                    'exact' => true,
                ];
            }
        }

        // 3. Whatever the web server reports as the script name / self path.
        foreach (['SCRIPT_NAME', 'PHP_SELF', 'ORIG_SCRIPT_NAME'] as $key) {
            $value = (string) ($_SERVER[$key] ?? '');

            if ($value === '') {
                continue;
            }

            $value = self::normalisePath($value);

            if (str_ends_with(strtolower($value), '.php')) {
                $candidates[] = ['path' => dirname($value), 'exact' => true];
            } elseif (str_ends_with($value, '/')) {
                // Directory request such as /dynapulse/public/
                $candidates[] = ['path' => rtrim($value, '/'), 'exact' => true];
            } else {
                // May be the routed URL, so only a strict prefix is acceptable.
                $candidates[] = ['path' => $value, 'exact' => false];
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate['path'] = Context::normaliseBasePath($candidate['path']);

            if (!in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    private static function normalisePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '') {
            return '';
        }

        return (string) preg_replace('#/+#', '/', $path);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function postAll(): array
    {
        return $this->post;
    }

    /** Read from the JSON body, falling back to form data. */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        return $json[$key] ?? $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function ip(): string
    {
        $trusted = (array) (Context::config('security.trusted_proxies') ?? []);

        if ($trusted !== []) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                $candidate = trim(explode(',', $forwarded)[0]);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) $this->header('user-agent', '');
    }

    public function wantsJson(): bool
    {
        $accept = strtolower((string) $this->header('accept', ''));

        return str_contains($accept, 'application/json') || str_contains((string) $this->header('x-requested-with', ''), 'xmlhttprequest');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return strtolower((string) $this->header('x-forwarded-proto', '')) === 'https';
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }
}
