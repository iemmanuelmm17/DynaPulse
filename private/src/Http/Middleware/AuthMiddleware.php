<?php

declare(strict_types=1);

namespace DynaPulse\Http\Middleware;

use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\Session;

final class AuthMiddleware
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (Context::$user === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Authentication required'], 401);
            }

            $this->session->storePreviousUrl($request->path());

            return Response::redirect('/login?next=' . rawurlencode($request->path()));
        }

        return $next($request);
    }
}
