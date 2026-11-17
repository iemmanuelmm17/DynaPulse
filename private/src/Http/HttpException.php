<?php

declare(strict_types=1);

namespace DynaPulse\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(private int $statusCode, string $message = '', private array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($statusCode));
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'That request could not be understood.',
            401 => 'Please sign in to continue.',
            403 => 'You do not have access to that.',
            404 => 'We could not find what you were looking for.',
            405 => 'That method is not allowed here.',
            419 => 'Your session expired. Please refresh and try again.',
            422 => 'Some of the submitted values need attention.',
            429 => 'Too many attempts. Please wait a moment.',
            503 => 'The service is temporarily unavailable.',
            default => 'Something went wrong.',
        };
    }
}
