<?php

declare(strict_types=1);

namespace DynaPulse\Http;

final class RouteMatch
{
    /** @param array{0:class-string,1:string}|callable $handler @param list<string> $middleware @param array<string,string> $params */
    public function __construct(
        public readonly mixed $handler,
        public readonly array $middleware = [],
        public readonly array $params = [],
    ) {
    }
}
