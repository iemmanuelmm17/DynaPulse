<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\ReportRepository;
use DynaPulse\Service\FilterBuilder;
use DynaPulse\Service\ReportService;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

final class ReportController extends BaseController
{
    public function __construct(
        View $view,
        private ReportService $reports,
        private ReportRepository $schedules,
        private DomainRepository $domains,
        private KeywordRepository $keywords,
        private FilterBuilder $filters,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $timezone = Context::timezone();
        $filters = $this->filters->fromRequest($request, $this->domainId(), $timezone);

        $user = $this->user();
        $allSchedules = $this->schedules->schedules();
        $visible = array_values(array_filter($allSchedules, function (array $schedule) use ($user): bool {
            if ($user->isAdmin()) {
                return true;
            }

            return (int) ($schedule['domain_id'] ?? 0) === (int) $this->domainId();
        }));

        $keywordOptions = $this->domainId() !== null
            ? $this->keywords->forDomain((int) $this->domainId(), false)
            : $this->keywords->all();

        return $this->render('reports/index', [
            'title' => 'Reports',
            'subtitle' => $this->filters->rangeLabel($filters) . ' · ' . ($this->domainId() === null ? 'All domains' : (Context::$domainRow['label'] ?? '')),
            'report' => $this->reports->build($filters),
            'filters' => $filters,
            'schedules' => $visible,
            'runs' => $this->schedules->runs(10),
            'keywords' => $keywordOptions,
            'domains' => $user->isAdmin() ? $this->domains->all(true) : [],
            'canManage' => Context::can('manage'),
            'timezone' => $timezone,
            'exportQuery' => http_build_query(array_filter($request->queryAll(), static fn ($v): bool => $v !== '' && $v !== null)),
            'defaultRecipients' => (string) \DynaPulse\Http\Context::config('app.name', ''),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $name = trim((string) $request->post('name', ''));
        $frequency = (string) $request->post('frequency', 'weekly');
        $sendTime = (string) $request->post('send_time', '07:00');
        $recipients = Str::splitEmails((string) $request->post('recipients', ''));

        if ($name === '' || mb_strlen($name) > 90) {
            throw new HttpException(422, 'Give the report a name (up to 90 characters).');
        }

        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new HttpException(422, 'Choose daily, weekly or monthly.');
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendTime)) {
            throw new HttpException(422, 'Send time must look like 07:30.');
        }

        if ($recipients === []) {
            throw new HttpException(422, 'Add at least one recipient email address.');
        }

        $domainId = null;
        if ($this->user()->isAdmin()) {
            $requested = (string) $request->post('domain_id', '');
            if ($requested !== '' && $requested !== 'all') {
                $domainId = (int) $requested;
            }
        } else {
            $domainId = $this->requireDomainId();
        }

        $schedule = [
            'name' => $name,
            'domain_id' => $domainId,
            'frequency' => $frequency,
            'weekday' => min(7, max(1, (int) $request->post('weekday', 1))),
            'day_of_month' => min(28, max(1, (int) $request->post('day_of_month', 1))),
            'send_time' => $sendTime,
            'period_days' => min(90, max(1, (int) $request->post('period_days', $frequency === 'daily' ? 1 : ($frequency === 'weekly' ? 7 : 30)))),
            'recipients' => $recipients,
            'include_csv' => $request->post('include_csv') === '1' ? 1 : 0,
            'active' => 1,
        ];

        $schedule['next_run_at'] = $this->reports->nextRunAt($schedule + ['id' => 0]);
        $this->schedules->createSchedule($schedule);

        return $this->redirect('/reports', sprintf(
            '%s scheduled · next run %s.',
            $name,
            Time::display($schedule['next_run_at'], (string) Context::config('app.timezone', 'UTC')),
        ), 'success');
    }

    public function runNow(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $schedule = $this->findSchedule((int) ($params['id'] ?? 0));
        $result = $this->reports->sendSchedule($schedule);

        $type = match ($result['status']) {
            'sent' => 'success',
            'failed' => 'error',
            default => 'warning',
        };

        $message = match ($result['status']) {
            'sent' => sprintf('Report sent to %d recipient(s).', count($result['recipients'])),
            'no_recipients' => 'No recipients are configured for that report.',
            'blocked' => 'SMTP is not configured, so the report could not be sent.',
            default => 'Send failed: ' . $result['error'],
        };

        return $this->redirect('/reports', $message, $type);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $schedule = $this->findSchedule((int) ($params['id'] ?? 0));
        $this->schedules->deleteSchedule((int) $schedule['id']);

        return $this->redirect('/reports', sprintf('%s deleted.', $schedule['name']), 'success');
    }

    /** @return array<string, mixed> */
    private function findSchedule(int $id): array
    {
        $schedule = $this->schedules->findSchedule($id);

        if ($schedule === null) {
            throw new HttpException(404, 'That schedule no longer exists.');
        }

        if (!$this->user()->isAdmin() && (int) ($schedule['domain_id'] ?? 0) !== (int) $this->domainId()) {
            throw new HttpException(403, 'That schedule belongs to another domain.');
        }

        return $schedule;
    }
}
