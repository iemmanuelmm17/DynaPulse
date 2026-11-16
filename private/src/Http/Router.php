<?php

declare(strict_types=1);

namespace DynaPulse\Http;

final class Router
{
    /** @var list<array{methods:list<string>, pattern:string, regex:string, handler:mixed, middleware:list<string>}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string}|callable $handler @param list<string> $middleware */
    public function get(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add(['GET'], $pattern, $handler, $middleware);
    }

    /** @param array{0:class-string,1:string}|callable $handler @param list<string> $middleware */
    public function post(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add(['POST'], $pattern, $handler, $middleware);
    }

    /** @param list<string> $methods @param array{0:class-string,1:string}|callable $handler @param list<string> $middleware */
    public function add(array $methods, string $pattern, mixed $handler, array $middleware = []): void
    {
        $pattern = '/' . trim($pattern, '/');
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern,
        ) ?? $pattern;

        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): RouteMatch
    {
        $path = $request->path();
        $method = $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $methods = $route['methods'];
            if (!in_array($method, $methods, true) && !($method === 'HEAD' && in_array('GET', $methods, true))) {
                $allowed = array_merge($allowed, $methods);
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return new RouteMatch($route['handler'], $route['middleware'], $params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404);
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return array_column($this->routes, 'pattern');
    }
}
