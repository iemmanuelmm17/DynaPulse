<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DateTimeImmutable;
use DynaPulse\Http\Context;
use DynaPulse\Http\View;
use DynaPulse\Mail\MailMessage;
use DynaPulse\Mail\Mailer;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\RecipientRepository;
use DynaPulse\Repository\ReportRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Repository\UserRepository;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

final class ReportService
{
    public function __construct(
        private CallRepository $calls,
        private MatchRepository $matches,
        private ReportRepository $schedules,
        private DomainRepository $domains,
        private RecipientRepository $recipients,
        private UserRepository $users,
        private SettingsRepository $settings,
        private Exporter $exporter,
        private FilterBuilder $filters,
        private View $view,
        private Mailer $mailer,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function build(array $filters): array
    {
        $timezone = (string) ($filters['timezone'] ?? 'UTC');
        $stats = $this->calls->stats($filters);
        $total = max(1, (int) $stats['calls']);

        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        $days = 1;
        if ($from !== '' && $to !== '') {
            $days = max(1, min(60, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1));
        }

        return [
            'stats' => $stats,
            'sentiment' => [
                'positive' => (int) round($stats['positive'] / $total * 100),
                'neutral' => (int) round($stats['neutral'] / $total * 100),
                'negative' => (int) round($stats['negative'] / $total * 100),
                'mixed' => (int) round($stats['mixed'] / $total * 100),
            ],
            'topKeywords' => $this->matches->topKeywords($filters, 8),
            'topTopics' => $this->calls->topTopics($filters, 10),
            'series' => $this->calls->dailySeries($filters, $timezone, $days),
            'recentAlerts' => $this->matches->query($filters, 8, 0)['rows'],
            'recentCalls' => $this->calls->query($filters, 8, 0)['rows'],
            'range' => ['from' => $from, 'to' => $to, 'label' => $this->filters->rangeLabel($filters)],
            'generatedAt' => Time::now(),
        ];
    }

    /** @return array{due:int, sent:int, failed:int, skipped:int, results:list<array<string, mixed>>} */
    public function sendDue(): array
    {
        $summary = ['due' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];

        foreach ($this->schedules->dueSchedules() as $schedule) {
            $summary['due']++;
            $result = $this->sendSchedule($schedule);
            $summary['results'][] = $result;

            match ($result['status']) {
                'sent' => $summary['sent']++,
                'failed' => $summary['failed']++,
                default => $summary['skipped']++,
            };
        }

        return $summary;
    }

    /** @return array{status:string, schedule:string, recipients:list<string>, calls:int, alerts:int, error:string} */
    public function sendSchedule(array $schedule): array
    {
        $scheduleId = (int) $schedule['id'];
        $nextRun = $this->nextRunAt($schedule);
        $appTimezone = (string) Context::config('app.timezone', 'UTC');

        $domain = null;
        if (($schedule['domain_id'] ?? null) !== null) {
            $domain = $this->domains->find((int) $schedule['domain_id']);
        }

        $timezone = $domain !== null ? (string) $domain['timezone'] : $appTimezone;
        $filters = $this->filters->period(max(1, (int) $schedule['period_days']), $timezone);
        $filters['domain_id'] = $domain !== null ? (int) $domain['id'] : null;

        $recipients = $this->resolveRecipients($schedule);
        $report = $this->build($filters);
        $calls = (int) $report['stats']['calls'];
        $alerts = (int) $report['stats']['alert_calls'];

        if ($recipients === []) {
            $this->schedules->recordRun($scheduleId, 'no_recipients', $calls, $alerts, [], 'No recipients configured.');
            $this->schedules->markRun($scheduleId, $nextRun, 'No recipients configured.');

            return ['status' => 'no_recipients', 'schedule' => (string) $schedule['name'], 'recipients' => [], 'calls' => $calls, 'alerts' => $alerts, 'error' => 'No recipients configured.'];
        }

        if (!$this->mailer->configured()) {
            $error = 'SMTP is not configured (see .env).';
            $this->schedules->recordRun($scheduleId, 'blocked', $calls, $alerts, $recipients, $error);
            $this->schedules->markRun($scheduleId, $nextRun, $error);

            return ['status' => 'blocked', 'schedule' => (string) $schedule['name'], 'recipients' => $recipients, 'calls' => $calls, 'alerts' => $alerts, 'error' => $error];
        }

        try {
            $appName = (string) Context::config('app.name', 'DynaPulse');
            $domainLabel = $domain !== null ? (string) ($domain['label'] ?: $domain['domain']) : 'All domains';

            $html = $this->view->render('emails/report', [
                'schedule' => $schedule,
                'report' => $report,
                'domainLabel' => $domainLabel,
                'timezone' => $timezone,
                'appName' => $appName,
                'portalUrl' => url('/reports'),
            ], null);

            $text = $this->reportText($schedule, $report, $domainLabel, $appName);

            $message = MailMessage::create()
                ->to($recipients)
                ->subject(sprintf(
                    '[%s] %s report — %s — %s',
                    $appName,
                    ucfirst((string) $schedule['frequency']),
                    $domainLabel,
                    $report['range']['label'],
                ))
                ->html($html)
                ->text($text);

            if ((int) $schedule['include_csv'] === 1) {
                $callsFile = $this->exporter->callsFile($filters, XlsxWriter::available() ? 'xlsx' : 'csv');
                $alertsFile = $this->exporter->alertsFile($filters, XlsxWriter::available() ? 'xlsx' : 'csv');
                $message->attach($callsFile['filename'], $callsFile['content'], $callsFile['mime']);
                $message->attach($alertsFile['filename'], $alertsFile['content'], $alertsFile['mime']);
            }

            $this->mailer->send($message);
            $this->schedules->recordRun($scheduleId, 'sent', $calls, $alerts, $recipients);
            $this->schedules->markRun($scheduleId, $nextRun);

            Logger::info('report.sent', [
                'schedule' => $schedule['name'],
                'recipients' => count($recipients),
                'calls' => $calls,
                'alerts' => $alerts,
            ]);

            return ['status' => 'sent', 'schedule' => (string) $schedule['name'], 'recipients' => $recipients, 'calls' => $calls, 'alerts' => $alerts, 'error' => ''];
        } catch (\Throwable $e) {
            Logger::exception($e, 'report.failed');
            $this->schedules->recordRun($scheduleId, 'failed', $calls, $alerts, $recipients, $e->getMessage());
            $this->schedules->markRun($scheduleId, $nextRun, $e->getMessage());

            return ['status' => 'failed', 'schedule' => (string) $schedule['name'], 'recipients' => $recipients, 'calls' => $calls, 'alerts' => $alerts, 'error' => $e->getMessage()];
        }
    }

    /** @return list<string> */
    public function resolveRecipients(array $schedule): array
    {
        $recipients = array_values(array_unique(array_map('strtolower', (array) ($schedule['recipients'] ?? []))));

        if ($recipients === [] && ($schedule['domain_id'] ?? null) !== null) {
            $recipients = $this->recipients->reportEmails((int) $schedule['domain_id']);
        }

        if ($recipients === []) {
            $recipients = array_map('strtolower', Str::splitEmails($this->settings->get('default_report_recipients')));
        }

        if ($recipients === []) {
            foreach ($this->users->all() as $user) {
                if ($user['role'] === 'admin' && $user['email'] !== '' && (int) $user['active'] === 1) {
                    $recipients[] = strtolower((string) $user['email']);
                }
            }
        }

        return array_values(array_unique(array_filter($recipients, static fn (string $email): bool => Str::emailValid($email))));
    }

    public function nextRunAt(array $schedule, ?DateTimeImmutable $now = null): string
    {
        $timezone = Time::zone((string) Context::config('app.timezone', 'UTC'));
        $now = $now ?? new DateTimeImmutable('now', $timezone);

        [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) $schedule['send_time'])), 2, 0);
        $hour = max(0, min(23, $hour));
        $minute = max(0, min(59, $minute));

        $frequency = (string) $schedule['frequency'];

        if ($frequency === 'daily') {
            $candidate = $now->setTime($hour, $minute);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
        } elseif ($frequency === 'monthly') {
            $day = max(1, min(28, (int) $schedule['day_of_month']));
            $candidate = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), $day)->setTime($hour, $minute);

            if ($candidate <= $now) {
                $nextMonth = $now->modify('first day of next month');
                $candidate = $nextMonth->setDate(
                    (int) $nextMonth->format('Y'),
                    (int) $nextMonth->format('n'),
                    $day,
                )->setTime($hour, $minute);
            }
        } else {
            $weekday = max(1, min(7, (int) $schedule['weekday']));
            $candidate = $now->modify('monday this week')->setTime($hour, $minute);

            while ((int) $candidate->format('N') !== $weekday) {
                $candidate = $candidate->modify('+1 day');
            }

            if ($candidate <= $now) {
                $candidate = $candidate->modify('+7 days');
            }
        }

        return $candidate->setTimezone(Time::zone('UTC'))->format('Y-m-d H:i:s');
    }

    private function reportText(array $schedule, array $report, string $domainLabel, string $appName): string
    {
        $stats = $report['stats'];
        $lines = [
            $appName . ' — ' . ucfirst((string) $schedule['frequency']) . ' report',
            'Domain: ' . $domainLabel,
            'Period: ' . $report['range']['label'],
            '',
            'Calls reviewed:      ' . $stats['calls'],
            'With transcript:     ' . $stats['with_transcript'],
            'With recording:      ' . $stats['with_recording'],
            'Calls with matches:  ' . $stats['alert_calls'],
            'Keyword matches:     ' . $stats['matches'],
            'Average duration:    ' . Time::humanDuration((int) $stats['avg_duration']),
            '',
            'Sentiment: positive ' . $report['sentiment']['positive'] . '%, neutral ' . $report['sentiment']['neutral']
                . '%, negative ' . $report['sentiment']['negative'] . '%',
            '',
            'Top keywords',
        ];

        if ($report['topKeywords'] === []) {
            $lines[] = '  (none)';
        }
        foreach ($report['topKeywords'] as $keyword) {
            $lines[] = sprintf('  %s — %d match(es) [%s]', $keyword['label'], $keyword['count'], $keyword['severity']);
        }

        $lines[] = '';
        $lines[] = 'Top topics';
        if ($report['topTopics'] === []) {
            $lines[] = '  (none)';
        }
        foreach ($report['topTopics'] as $topic) {
            $lines[] = sprintf('  %s — %d call(s)', $topic['topic'], $topic['count']);
        }

        $lines[] = '';
        $lines[] = 'Open the portal: ' . url('/reports');
        $lines[] = 'Generated ' . Time::display($report['generatedAt']) . ' UTC';

        return implode("\n", $lines);
    }
}
