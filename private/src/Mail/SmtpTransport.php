<?php

declare(strict_types=1);

namespace DynaPulse\Mail;

use DynaPulse\Support\Logger;

/**
 * Direct SMTP client (STARTTLS + AUTH LOGIN/PLAIN). Works with
 * smtp.office365.com:587 as long as SMTP AUTH is enabled for the mailbox.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private int $timeout = 25,
    ) {
    }

    public function send(MailMessage $message): void
    {
        if ($message->recipients() === []) {
            throw new MailException('No recipients for this message.');
        }

        $mime = MimeRenderer::render($message);
        $payload = $mime['headers'] . "\r\n" . $mime['body'];

        try {
            $this->connect();
            $this->authenticateIfNeeded();

            $this->command('MAIL FROM:<' . $message->fromAddress() . '>', [250]);

            $accepted = 0;
            $lastError = '';
            foreach ($message->recipients() as $recipient) {
                try {
                    $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
                    $accepted++;
                } catch (MailException $e) {
                    $lastError = $e->getMessage();
                    Logger::warning('mail.recipient_rejected', ['recipient' => $recipient, 'error' => $lastError]);
                }
            }

            if ($accepted === 0) {
                throw new MailException('Every recipient was rejected by the server. ' . $lastError);
            }

            $this->command('DATA', [354]);
            $this->write(self::dotStuff($payload) . "\r\n.\r\n");
            $this->expect([250]);
            $this->quit();
        } catch (MailException $e) {
            $this->quit();
            throw $e;
        }
    }

    private function connect(): void
    {
        $host = $this->host;
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $socket = @stream_socket_client(
            $scheme . $host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new MailException(sprintf('Cannot connect to %s:%d — %s', $host, $this->port, $errstr ?: 'connection failed'));
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        $this->expect([220]);
        $this->ehlo();

        if ($this->encryption === 'starttls') {
            $this->command('STARTTLS', [220]);

            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }

            if (!@stream_socket_enable_crypto($this->socket, true, $method)) {
                throw new MailException('STARTTLS negotiation failed. Try MAIL_ENCRYPTION=ssl with port 465.');
            }

            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $identity = gethostname() ?: 'localhost';
        $identity = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $identity) ?: 'localhost';
        $this->command('EHLO ' . $identity, [250], true);
    }

    private function authenticateIfNeeded(): void
    {
        if ($this->username === '') {
            return;
        }

        // AUTH LOGIN first (what Office 365 expects), AUTH PLAIN as fallback.
        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        } catch (MailException $loginError) {
            if (!str_contains($loginError->getMessage(), '504') && !str_contains($loginError->getMessage(), '535')) {
                throw $loginError;
            }

            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235]);
        }
    }

    private function command(string $command, array $expected, bool $multiline = false): string
    {
        $this->write($command . "\r\n");

        return $this->expect($expected, $multiline);
    }

    /** @param list<int> $expected */
    private function expect(array $expected, bool $multiline = false): string
    {
        $response = '';
        $lines = [];

        for ($i = 0; $i < 50; $i++) {
            $line = $this->readLine();
            $lines[] = $line;
            $response .= $line . "\n";

            // "250-..." continues, "250 ..." ends the reply.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr(trim($lines[0] ?? ''), 0, 3);

        if (!in_array($code, $expected, true)) {
            $safe = array_map(static fn (string $l): string => preg_replace('/AUTH\s+\S+/i', 'AUTH ***', $l) ?? $l, $lines);
            throw new MailException(sprintf('SMTP error: %s', trim(implode(' | ', $safe))));
        }

        return $response;
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new MailException('SMTP connection is not open.');
        }

        $line = fgets($this->socket, 4096);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            $reason = ($meta['timed_out'] ?? false) ? 'timeout' : 'connection closed';

            throw new MailException('SMTP read failed: ' . $reason);
        }

        return rtrim($line, "\r\n");
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new MailException('SMTP connection is not open.');
        }

        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new MailException('SMTP write failed.');
        }
    }

    private function quit(): void
    {
        if ($this->socket === null) {
            return;
        }

        @fwrite($this->socket, "QUIT\r\n");
        @fclose($this->socket);
        $this->socket = null;
    }

    private static function dotStuff(string $payload): string
    {
        $payload = str_replace(["\r\n", "\r"], "\n", $payload);
        $lines = explode("\n", $payload);

        foreach ($lines as $index => $line) {
            if ($line !== '' && $line[0] === '.') {
                $lines[$index] = '.' . $line;
            }
        }

        return implode("\r\n", $lines);
    }
}
