<?php

declare(strict_types=1);

namespace DynaPulse\Http\Middleware;

use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;

final class GuestMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Context::$user !== null) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
