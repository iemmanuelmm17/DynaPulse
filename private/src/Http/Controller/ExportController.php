<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Service\Exporter;
use DynaPulse\Service\FilterBuilder;

final class ExportController extends BaseController
{
    public function __construct(
        View $view,
        private Exporter $exporter,
        private FilterBuilder $filters,
        private CallRepository $calls,
    ) {
        parent::__construct($view);
    }

    public function calls(Request $request, array $params = []): Response
    {
        $format = $this->format($params);
        $filters = $this->filters->fromRequest($request, $this->domainId(), Context::timezone());

        $file = $this->exporter->callsFile($filters, $format);

        return Response::download($file['content'], $file['filename'], $file['mime']);
    }

    public function alerts(Request $request, array $params = []): Response
    {
        $format = $this->format($params);
        $filters = $this->filters->fromRequest($request, $this->domainId(), Context::timezone());

        $file = $this->exporter->alertsFile($filters, $format);

        return Response::download($file['content'], $file['filename'], $file['mime']);
    }

    public function transcript(Request $request, array $params = []): Response
    {
        $call = $this->calls->find((int) ($params['id'] ?? 0));

        if ($call === null) {
            throw new HttpException(404, 'That call no longer exists.');
        }

        if (!$this->user()->isAdmin() && (int) $call['domain_id'] !== (int) $this->domainId()) {
            throw new HttpException(403);
        }

        if ((int) $call['has_transcript'] !== 1) {
            throw new HttpException(404, 'No transcript is available for this call.');
        }

        $content = $this->exporter->transcriptText($call);
        $name = 'dynapulse_transcript_' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $call['call_id']) . '.txt';

        return Response::download($content, $name, 'text/plain; charset=UTF-8');
    }

    private function format(array $params): string
    {
        $format = strtolower((string) ($params['format'] ?? 'csv'));

        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new HttpException(404);
        }

        return $format;
    }
}
