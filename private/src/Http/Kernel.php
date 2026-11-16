<?php

declare(strict_types=1);

namespace DynaPulse\Http;

use DynaPulse\Http\Middleware\AdminMiddleware;
use DynaPulse\Http\Middleware\AuthMiddleware;
use DynaPulse\Http\Middleware\CsrfMiddleware;
use DynaPulse\Http\Middleware\GuestMiddleware;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\UserRepository;
use DynaPulse\Service\DomainContext;
use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;
use Throwable;

final class Kernel
{
    /** Short middleware names used in routes.php. */
    private const MIDDLEWARE = [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'admin' => AdminMiddleware::class,
        'csrf' => CsrfMiddleware::class,
    ];

    public function __construct(
        private Config $config,
        private Session $session,
        private Router $router,
        private Container $container,
        private View $view,
        private UserRepository $users,
        private DomainRepository $domains,
        private DomainContext $domainContext,
    ) {
    }

    public function handle(Request $request): Response
    {
        Context::$config = $this->config;
        Context::$request = $request;
        Context::$session = $this->session;
        Context::$user = null;
        Context::$domainRow = null;
        Context::$cspNonce = base64_encode(random_bytes(18));

        $this->session->start();
        $this->resolveUser();

        try {
            $match = $this->router->dispatch($request);
            $handler = $this->handler($match);

            $pipeline = array_reduce(
                array_reverse($match->middleware),
                fn (callable $next, string $middleware): callable => function (Request $request) use ($middleware, $next): Response {
                    /** @var object $instance */
                    $instance = $this->container->get(self::MIDDLEWARE[$middleware] ?? $middleware);

                    return $instance->handle($request, $next);
                },
                function (Request $request) use ($handler, $match): Response {
                    return $handler($request, $match->params);
                },
            );

            $response = $pipeline($request);
        } catch (HttpException $e) {
            $response = $this->handleHttpException($e, $request);
        } catch (Throwable $e) {
            Logger::exception($e);
            $response = $this->renderError(
                500,
                'Something went wrong',
                $this->config->isDebug()
                    ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                    : 'The request could not be completed. The error has been written to the DynaPulse log.',
                $request,
            );
        }

        return $this->finalize($response, $request);
    }

    private function handler(RouteMatch $match): callable
    {
        $handler = $match->handler;

        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $controller = $this->container->get($handler[0]);
            $method = $handler[1];
        } elseif (is_callable($handler)) {
            return $handler;
        } else {
            throw new HttpException(500, 'Route handler is not callable.');
        }

        return function (Request $request, array $params = []) use ($controller, $method): Response {
            /** @var Response $response */
            $response = $controller->{$method}($request, $params);

            return $response;
        };
    }

    private function resolveUser(): void
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return;
        }

        $row = $this->users->find($userId);

        if ($row === null || (int) $row['active'] !== 1) {
            $this->session->destroy();
            Context::$user = null;

            return;
        }

        $domainRow = $this->domains->findByDomain((string) $row['domain']);
        if ($domainRow === null && (int) ($row['domain_id'] ?? 0) > 0 && $row['domain'] !== 'all') {
            $domainRow = null;
        }

        Context::$user = $this->users->toAuthUser($row, $domainRow !== null ? (int) $domainRow['id'] : 0);
        Context::$domainRow = $this->domainContext->current(Context::$user);
        Context::$domains = Context::$user->isAdmin() ? $this->domains->all() : [];
    }

    private function handleHttpException(HttpException $e, Request $request): Response
    {
        $status = $e->statusCode();

        if ($status === 401) {
            return Response::redirect('/login');
        }

        if ($request->wantsJson()) {
            return Response::json(['error' => $e->getMessage()], $status)->withHeaders($e->headers());
        }

        $titles = [
            403 => 'Access denied',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Session expired',
            429 => 'Slow down',
        ];

        return $this->renderError($status, $titles[$status] ?? 'Request failed', $e->getMessage(), $request);
    }

    private function renderError(int $status, string $title, string $message, Request $request): Response
    {
        try {
            $html = $this->view->render('errors/error', [
                'title' => $title,
                'status' => $status,
                'message' => $message,
                'user' => Context::$user,
            ], Context::$user !== null ? 'app' : 'minimal');

            return Response::html($html, $status);
        } catch (Throwable) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
                . '<div style="font-family:system-ui;padding:48px;max-width:640px;margin:auto">'
                . '<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
                . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
                . '<p><a href="/">Back to DynaPulse</a></p></div>',
                $status,
            );
        }
    }

    private function finalize(Response $response, Request $request): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-" . Context::$cspNonce . "'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; media-src 'self'; connect-src 'self'; font-src 'self'; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (!isset($response->headers()[$name])) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
