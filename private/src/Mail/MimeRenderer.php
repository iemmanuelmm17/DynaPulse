<?php

declare(strict_types=1);

namespace DynaPulse\Mail;

/**
 * Builds a fully MIME compliant message (RFC 5322 / 2045). Bodies are base64
 * encoded so long transcripts and UTF-8 copy never need line folding.
 */
final class MimeRenderer
{
    /** @return array{headers:string, body:string} */
    public static function render(MailMessage $message): array
    {
        $boundary = '=dp_' . bin2hex(random_bytes(12));
        $altBoundary = '=dp_alt_' . bin2hex(random_bytes(8));

        $htmlPart = self::part('text/html; charset=UTF-8', base64_encode($message->htmlBody()));
        $textPart = self::part('text/plain; charset=UTF-8', base64_encode($message->textBody() !== '' ? $message->textBody() : strip_tags($message->htmlBody())));

        $alternative = "--{$altBoundary}\r\n" . $textPart . "\r\n--{$altBoundary}\r\n" . $htmlPart . "\r\n--{$altBoundary}--\r\n";

        if ($message->attachments() !== []) {
            $body = "--{$boundary}\r\nContent-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
                . $alternative;

            foreach ($message->attachments() as $attachment) {
                $name = self::encodeHeader($attachment['name']);
                $body .= "\r\n--{$boundary}\r\n"
                    . 'Content-Type: ' . $attachment['mime'] . '; name="' . $name . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n"
                    . chunk_split(base64_encode($attachment['content']), 76, "\r\n");
            }

            $body .= "\r\n--{$boundary}--\r\n";
            $contentType = 'multipart/mixed; boundary="' . $boundary . '"';
        } else {
            $body = $alternative;
            $contentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
        }

        $fromAddress = $message->fromAddress();
        $host = (string) (explode('@', $fromAddress)[1] ?? 'dynapulse.local');

        $headers = [
            'Date' => date('r'),
            'From' => self::address($fromAddress, $message->fromName()),
            'To' => implode(', ', array_map(static fn (string $a): string => self::address($a), $message->toAddresses())),
        ];

        if ($message->replyToAddress() !== '') {
            $headers['Reply-To'] = self::address($message->replyToAddress());
        }

        $headers['Subject'] = self::encodeHeader($message->subjectLine());
        $headers['Message-ID'] = '<' . bin2hex(random_bytes(16)) . '@' . $host . '>';
        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = $contentType;
        $headers['Content-Transfer-Encoding'] = '8bit';
        $headers['X-Mailer'] = 'DynaPulse';
        $headers['X-Auto-Response-Suppress'] = 'All';
        $headers['Auto-Submitted'] = 'auto-generated';

        foreach ($message->extraHeaders() as $name => $value) {
            $headers[$name] = $value;
        }

        $headerBlock = '';
        foreach ($headers as $name => $value) {
            $headerBlock .= $name . ': ' . $value . "\r\n";
        }

        return ['headers' => $headerBlock, 'body' => $body];
    }

    private static function part(string $contentType, string $encodedBody): string
    {
        return 'Content-Type: ' . $contentType . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split($encodedBody, 76, "\r\n");
    }

    public static function address(string $address, string $name = ''): string
    {
        $address = trim($address);
        if ($name === '') {
            return '<' . $address . '>';
        }

        return self::encodeHeader($name) . ' <' . $address . '>';
    }

    public static function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
