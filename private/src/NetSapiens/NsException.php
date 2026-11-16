<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

use RuntimeException;

final class NsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $status);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isAuthError(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || in_array($this->status, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
