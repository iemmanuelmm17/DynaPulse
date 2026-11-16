<?php

declare(strict_types=1);

namespace DynaPulse\Http\Middleware;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;

final class AdminMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Context::can('admin')) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Administrator access required'], 403);
            }

            throw new HttpException(403, 'Administrator access is required for that page.');
        }

        return $next($request);
    }
}
