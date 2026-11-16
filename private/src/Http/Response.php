<?php

declare(strict_types=1);

namespace DynaPulse\Http;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var null|callable():void */
    private $stream = null;

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return (new self(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
        ))->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return (new self('', $status))->withHeader('Location', $location);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function download(string $body, string $filename, string $contentType = 'application/octet-stream'): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

        return (new self($body))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param callable():void $stream */
    public static function stream(callable $stream): self
    {
        $response = new self();
        $response->stream = $stream;

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isStreaming(): bool
    {
        return $this->stream !== null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->stream !== null) {
            ($this->stream)();

            return;
        }

        echo $this->body;
    }
}
