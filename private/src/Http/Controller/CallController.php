<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\NotificationRepository;
use DynaPulse\Service\CallProcessor;
use DynaPulse\Service\Exporter;
use DynaPulse\Service\FilterBuilder;

final class CallController extends BaseController
{
    public function __construct(
        View $view,
        private CallRepository $calls,
        private MatchRepository $matches,
        private KeywordRepository $keywords,
        private NotificationRepository $notifications,
        private CallProcessor $processor,
        private Exporter $exporter,
        private FilterBuilder $filters,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $timezone = Context::timezone();
        $filters = $this->filters->fromRequest($request, $this->domainId(), $timezone);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;
        $pageData = $this->pagination(
            $this->calls->query($filters, 1, 0, $filters['sort'])['total'],
            $perPage,
            $page,
        );

        $result = $this->calls->query($filters, $perPage, $pageData['offset'], $filters['sort']);
        $pageData['total'] = $result['total'];

        $keywordOptions = $this->domainId() !== null
            ? $this->keywords->forDomain((int) $this->domainId(), false)
            : $this->keywords->all();

        return $this->render('calls/index', [
            'title' => 'Calls',
            'subtitle' => $this->filters->rangeLabel($filters),
            'calls' => $result['rows'],
            'stats' => $this->calls->stats($filters),
            'filters' => $filters,
            'keywords' => $keywordOptions,
            'pagination' => $pageData,
            'timezone' => $timezone,
            'exportQuery' => http_build_query(array_filter($request->queryAll(), static fn ($v): bool => $v !== '' && $v !== null)),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $call = $this->findCall((int) ($params['id'] ?? 0));
        $timezone = (string) ($call['domain_timezone'] ?? Context::timezone());

        return $this->render('calls/show', [
            'title' => 'Call detail',
            'subtitle' => $call['domain_name'] . ' · ' . ($call['direction'] ?: 'Call'),
            'call' => $call,
            'matches' => $this->matches->forCall((int) $call['id']),
            'notifications' => $this->notifications->forCall((int) $call['id']),
            'timezone' => $timezone,
            'canManage' => Context::can('manage'),
        ]);
    }

    public function retry(Request $request, array $params = []): Response
    {
        $this->requireManage();
        $call = $this->findCall((int) ($params['id'] ?? 0));

        $result = $this->processor->process((int) $call['id'], true);

        $messages = [
            'complete' => ['Reprocessed the call and refreshed transcript, recording and matches.', 'success'],
            'retry' => ['NetSapiens is still preparing the transcript. DynaPulse will try again shortly.', 'info'],
            'failed' => ['Processing failed. Check the log for the NetSapiens error.', 'error'],
            'skipped' => ['The call is already up to date.', 'info'],
            'missing' => ['That call no longer exists.', 'error'],
        ];

        [$message, $type] = $messages[$result] ?? ['Processing finished.', 'success'];

        return $this->redirect('/calls/' . (int) $call['id'], $message, $type);
    }

    public function transcript(Request $request, array $params = []): Response
    {
        $call = $this->findCall((int) ($params['id'] ?? 0));

        if ((int) $call['has_transcript'] !== 1) {
            throw new HttpException(404, 'No transcript is available for this call yet.');
        }

        $content = $this->exporter->transcriptText($call);
        $name = sprintf(
            'dynapulse_transcript_%s_%s.txt',
            preg_replace('/[^A-Za-z0-9.-]/', '', (string) $call['caller_number']) ?: 'call',
            date('Ymd_His'),
        );

        return Response::download($content, $name, 'text/plain; charset=UTF-8');
    }

    /** @return array<string, mixed> */
    private function findCall(int $id): array
    {
        $call = $this->calls->find($id);

        if ($call === null) {
            throw new HttpException(404, 'That call could not be found.');
        }

        $user = $this->user();
        $scope = $this->domainId();

        if (!$user->isAdmin() && ($scope === null || (int) $call['domain_id'] !== $scope)) {
            throw new HttpException(403, 'That call belongs to another domain.');
        }

        return $call;
    }
}
