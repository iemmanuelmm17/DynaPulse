<?php

declare(strict_types=1);

namespace DynaPulse\Http\Middleware;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;

final class CsrfMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $expected = Context::$session?->csrfToken() ?? '';
        $provided = (string) ($request->post('_token') ?? $request->header('x-csrf-token', '') ?? '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new HttpException(419, 'Your session expired. Refresh the page and try again.');
        }

        return $next($request);
    }
}
