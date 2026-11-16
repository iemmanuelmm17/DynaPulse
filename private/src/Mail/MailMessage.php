<?php

declare(strict_types=1);

namespace DynaPulse\Mail;

final class MailMessage
{
    /** @var list<string> */
    private array $to = [];

    /** @var list<string> */
    private array $cc = [];

    /** @var list<array{name:string, content:string, mime:string}> */
    private array $attachments = [];

    private string $fromAddress = '';

    private string $fromName = '';

    private string $replyTo = '';

    private string $subject = '';

    private string $html = '';

    private string $text = '';

    /** @var array<string, string> */
    private array $headers = [];

    public static function create(): self
    {
        return new self();
    }

    /** @param list<string> $addresses */
    public function to(array $addresses): self
    {
        $this->to = array_values(array_unique(array_filter(array_map('trim', $addresses))));

        return $this;
    }

    /** @param list<string> $addresses */
    public function cc(array $addresses): self
    {
        $this->cc = array_values(array_unique(array_filter(array_map('trim', $addresses))));

        return $this;
    }

    public function from(string $address, string $name = ''): self
    {
        $this->fromAddress = trim($address);
        $this->fromName = $name;

        return $this;
    }

    public function replyTo(string $address): self
    {
        $this->replyTo = trim($address);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function attach(string $name, string $content, string $mime = 'text/plain'): self
    {
        $this->attachments[] = ['name' => $name, 'content' => $content, 'mime' => $mime];

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc)));
    }

    /** @return list<string> */
    public function toAddresses(): array
    {
        return $this->to;
    }

    public function fromAddress(): string
    {
        return $this->fromAddress;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function replyToAddress(): string
    {
        return $this->replyTo;
    }

    public function subjectLine(): string
    {
        return $this->subject;
    }

    public function htmlBody(): string
    {
        return $this->html;
    }

    public function textBody(): string
    {
        return $this->text;
    }

    /** @return list<array{name:string, content:string, mime:string}> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @return array<string, string> */
    public function extraHeaders(): array
    {
        return $this->headers;
    }

    public function hasContent(): bool
    {
        return $this->html !== '' || $this->text !== '';
    }
}
