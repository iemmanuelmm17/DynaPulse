<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Http\Context;
use DynaPulse\Http\View;
use DynaPulse\Mail\MailException;
use DynaPulse\Mail\MailMessage;
use DynaPulse\Mail\Mailer;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\NotificationRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

/** Renders and delivers the queued keyword-alert emails. */
final class NotificationSender
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private Mailer $mailer,
        private CallRepository $calls,
        private MatchRepository $matches,
        private NotificationRepository $notifications,
        private SettingsRepository $settings,
        private View $view,
        private Signer $signer,
    ) {
    }

    /** @return array{sent:int, failed:int, blocked:int, skipped:int} */
    public function sendPending(int $limit = 20): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0];

        foreach ($this->notifications->pending($limit) as $notification) {
            $result = $this->sendNotification($notification);

            match ($result) {
                'sent' => $stats['sent']++,
                'failed' => $stats['failed']++,
                'blocked' => $stats['blocked']++,
                default => $stats['skipped']++,
            };
        }

        return $stats;
    }

    /** @param array<string, mixed> $notification */
    public function sendNotification(array $notification): string
    {
        $notificationId = (int) $notification['id'];
        $call = $this->calls->find((int) $notification['call_id']);

        if ($call === null) {
            $this->notifications->markFailed($notificationId, 'Call no longer exists.', false);

            return 'skipped';
        }

        if (!$this->mailer->configured()) {
            $this->notifications->markBlocked($notificationId, 'SMTP is not configured: ' . implode(', ', $this->mailer->missingSettings()));
            $this->calls->saveProcessed((int) $call['id'], ['alert_status' => 'blocked']);

            return 'blocked';
        }

        $recipients = json_decode((string) $notification['recipients'], true);
        $recipients = is_array($recipients) ? array_values(array_filter($recipients, 'is_string')) : [];

        if ($recipients === []) {
            $this->notifications->markFailed($notificationId, 'No recipients on the notification.', false);

            return 'failed';
        }

        try {
            $message = $this->buildMessage($call, $recipients, (string) $notification['subject']);
            $this->mailer->send($message);

            $this->notifications->markSent($notificationId);
            $this->calls->saveProcessed((int) $call['id'], ['alert_status' => 'sent']);

            Logger::info('alert.sent', [
                'call_id' => (int) $call['id'],
                'notification_id' => $notificationId,
                'recipients' => count($recipients),
            ]);

            return 'sent';
        } catch (MailException $e) {
            $attempts = (int) ($notification['attempts'] ?? 0) + 1;
            $retry = $attempts < self::MAX_ATTEMPTS;
            $this->notifications->markFailed($notificationId, $e->getMessage(), $retry);

            if (!$retry) {
                $this->calls->saveProcessed((int) $call['id'], ['alert_status' => 'failed']);
            }

            Logger::warning('alert.send_failed', [
                'call_id' => (int) $call['id'],
                'attempt' => $attempts,
                'retry' => $retry,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /** @param array<string, mixed> $call @param list<string> $recipients */
    public function buildMessage(array $call, array $recipients, string $subject = ''): MailMessage
    {
        $matches = $this->matches->forCall((int) $call['id']);
        $timezone = (string) ($call['domain_timezone'] ?? Context::timezone());
        $appName = (string) Context::config('app.name', 'DynaPulse');
        $ttl = 30 * 86400;

        $audioUrl = null;
        if ($this->signer->enabled() && (bool) Context::config('security.public_audio_links', true) && (int) $call['has_recording'] === 1) {
            $params = $this->signer->sign(['call' => (int) $call['id']], $ttl);
            $audioUrl = url('/calls/' . (int) $call['id'] . '/audio?' . http_build_query($params));
        }

        $portalUrl = url('/calls/' . (int) $call['id']);

        $html = $this->view->render('emails/alert', [
            'call' => $call,
            'matches' => $matches,
            'timezone' => $timezone,
            'appName' => $appName,
            'audioUrl' => $audioUrl,
            'portalUrl' => $portalUrl,
            'includeTranscript' => $this->settings->bool('alert_include_transcript'),
            'domainLabel' => (string) ($call['domain_label'] ?? $call['domain_name'] ?? ''),
            'alertCount' => count($matches),
        ], null);

        return MailMessage::create()
            ->to($recipients)
            ->subject($subject !== '' ? $subject : $appName . ' keyword alert')
            ->html($html)
            ->text($this->buildText($call, $matches, $timezone, $audioUrl, $portalUrl, $appName));
    }

    /**
     * @param array<string, mixed> $call
     * @param list<array<string, mixed>> $matches
     */
    public function buildText(array $call, array $matches, string $timezone, ?string $audioUrl, string $portalUrl, string $appName): string
    {
        $lines = [
            $appName . ' keyword alert',
            str_repeat('=', 40),
            'Domain:   ' . ($call['domain_name'] ?? ''),
            'Caller:   ' . Str::prettyPhone((string) $call['caller_number']),
            'Called:   ' . Str::prettyPhone((string) $call['called_number']),
            'Date:     ' . Time::display((string) $call['started_at'], $timezone) . ' (' . $timezone . ')',
            'Duration: ' . Time::humanDuration((int) $call['duration_seconds']),
            '',
            'Triggered by',
            str_repeat('-', 40),
        ];

        foreach ($matches as $match) {
            $lines[] = sprintf(
                '• %s (%s, matched in %s): %s',
                $match['keyword_label'],
                $match['severity'],
                $match['field'],
                $match['snippet'],
            );
        }

        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = str_repeat('-', 40);
        $lines[] = (string) $call['transcript_summary'];

        if ((array) $call['transcript_topics'] !== []) {
            $lines[] = '';
            $lines[] = 'Topics: ' . implode(', ', (array) $call['transcript_topics']);
        }

        if ($audioUrl !== null) {
            $lines[] = '';
            $lines[] = 'Listen to the recording: ' . $audioUrl;
        }

        $lines[] = 'Open in the portal: ' . $portalUrl;
        $lines[] = '';
        $lines[] = 'Sent ' . gmdate('Y-m-d H:i:s') . ' UTC';

        return implode("\n", $lines);
    }
}
