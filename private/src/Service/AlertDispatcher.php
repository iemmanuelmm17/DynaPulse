<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\NotificationRepository;
use DynaPulse\Repository\RecipientRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

/**
 * Decides whether a processed call deserves an email, to whom, and queues it.
 * One grouped message per call so a call matching five keywords doesn't send
 * five emails.
 */
final class AlertDispatcher
{
    public function __construct(
        private MatchRepository $matches,
        private RecipientRepository $recipients,
        private NotificationRepository $notifications,
        private SettingsRepository $settings,
        private CallRepository $calls,
    ) {
    }

    /** @return array{status:string, notification_id:?int, recipients:list<string>} */
    public function dispatch(array $call): array
    {
        $callId = (int) $call['id'];
        $matches = $this->matches->forCall($callId);

        if ($matches === []) {
            return ['status' => 'none', 'notification_id' => null, 'recipients' => []];
        }

        if (!$this->settings->bool('alerts_enabled')) {
            Logger::info('alert.suppressed', ['call_id' => $callId, 'reason' => 'alerts_disabled']);

            return ['status' => 'disabled', 'notification_id' => null, 'recipients' => []];
        }

        $threshold = Str::severityRank($this->settings->get('alert_min_severity', 'low'));
        $qualifying = array_values(array_filter(
            $matches,
            static fn (array $match): bool => Str::severityRank((string) $match['severity']) >= $threshold,
        ));

        if ($qualifying === []) {
            return ['status' => 'below_threshold', 'notification_id' => null, 'recipients' => []];
        }

        $domainId = (int) $call['domain_id'];
        $recipients = array_values(array_unique(array_merge(
            $this->recipients->alertEmails($domainId),
            array_map('strtolower', Str::splitEmails($this->settings->get('alert_extra_recipients'))),
        )));

        if ($recipients === []) {
            Logger::warning('alert.no_recipients', ['call_id' => $callId, 'domain_id' => $domainId]);

            return ['status' => 'no_recipients', 'notification_id' => null, 'recipients' => []];
        }

        $labels = array_values(array_unique(array_map(static fn (array $m): string => (string) $m['keyword_label'], $qualifying)));
        $subject = sprintf(
            '[%s] Alert: %s — %s → %s',
            (string) \DynaPulse\Http\Context::config('app.name', 'DynaPulse'),
            Str::limit(implode(', ', $labels), 60),
            Str::prettyPhone((string) $call['caller_number']),
            Str::prettyPhone((string) $call['called_number']),
        );

        $notificationId = $this->notifications->queue(
            $callId,
            $domainId,
            $recipients,
            $subject,
            'alert',
        );

        if ($notificationId !== null) {
            $this->calls->saveProcessed($callId, ['alert_status' => 'queued']);
            Logger::info('alert.queued', [
                'call_id' => $callId,
                'notification_id' => $notificationId,
                'recipients' => count($recipients),
                'keywords' => $labels,
                'at' => Time::now(),
            ]);
        }

        return ['status' => 'queued', 'notification_id' => $notificationId, 'recipients' => $recipients];
    }
}
