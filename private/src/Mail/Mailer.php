<?php

declare(strict_types=1);

namespace DynaPulse\Mail;

use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;

final class Mailer
{
    public function __construct(private Config $config)
    {
    }

    public function configured(): bool
    {
        return $this->config->mailIsConfigured();
    }

    public function missingSettings(): array
    {
        $missing = [];
        foreach (['mail.host' => 'MAIL_HOST', 'mail.username' => 'MAIL_USERNAME', 'mail.password' => 'MAIL_PASSWORD', 'mail.from_address' => 'MAIL_FROM_ADDRESS'] as $key => $env) {
            if ((string) $this->config->get($key, '') === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public function send(MailMessage $message): void
    {
        if (!$this->configured()) {
            throw new MailException('Mail is not configured: missing ' . implode(', ', $this->missingSettings()));
        }

        if ($message->fromAddress() === '') {
            $message->from((string) $this->config->get('mail.from_address'), (string) $this->config->get('mail.from_name'));
        }

        $replyTo = (string) $this->config->get('mail.reply_to', '');
        if ($replyTo !== '' && $message->replyToAddress() === '') {
            $message->replyTo($replyTo);
        }

        if (!$message->hasContent()) {
            throw new MailException('Refusing to send an empty message.');
        }

        $transport = new SmtpTransport(
            (string) $this->config->get('mail.host'),
            (int) $this->config->get('mail.port', 587),
            (string) $this->config->get('mail.encryption', 'starttls'),
            (string) $this->config->get('mail.username'),
            (string) $this->config->get('mail.password'),
            (int) $this->config->get('mail.timeout', 25),
        );

        $transport->send($message);

        Logger::info('mail.sent', [
            'recipients' => count($message->recipients()),
            'subject' => $message->subjectLine(),
        ]);
    }

    public function sendTest(string $to): void
    {
        $appName = (string) $this->config->get('app.name', 'DynaPulse');
        $host = (string) $this->config->get('mail.host');

        $html = '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;color:#1f2937">'
            . 'This is a test message from <strong>' . htmlspecialchars($appName, ENT_QUOTES) . '</strong>.</p>'
            . '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#4b5563">'
            . 'If you can read this, the Outlook SMTP settings in your <code>.env</code> are working. Alerts and '
            . 'scheduled reports will be delivered from this mailbox.</p>'
            . '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:12px;color:#9ca3af">Sent through ' . htmlspecialchars($host, ENT_QUOTES) . '</p>';

        $message = MailMessage::create()
            ->to([$to])
            ->subject($appName . ' — SMTP test')
            ->html($html)
            ->text("This is a test message from {$appName}. If you can read this, the Outlook SMTP settings are working.");

        $this->send($message);
    }
}
