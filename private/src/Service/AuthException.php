<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
