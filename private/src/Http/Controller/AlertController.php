<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Service\FilterBuilder;

final class AlertController extends BaseController
{
    public function __construct(
        View $view,
        private MatchRepository $matches,
        private KeywordRepository $keywords,
        private FilterBuilder $filters,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $timezone = Context::timezone();
        $filters = $this->filters->fromRequest($request, $this->domainId(), $timezone);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 30;
        $total = $this->matches->count($filters);
        $pagination = $this->pagination($total, $perPage, $page);

        $rows = $this->matches->query($filters, $perPage, $pagination['offset'])['rows'];

        $keywordOptions = $this->domainId() !== null
            ? $this->keywords->forDomain((int) $this->domainId(), false)
            : $this->keywords->all();

        return $this->render('alerts/index', [
            'title' => 'Alerts',
            'subtitle' => $this->filters->rangeLabel($filters),
            'matches' => $rows,
            'filters' => $filters,
            'keywords' => $keywordOptions,
            'pagination' => $pagination,
            'timezone' => $timezone,
            'topKeywords' => $this->matches->topKeywords($filters, 6),
            'exportQuery' => http_build_query(array_filter($request->queryAll(), static fn ($v): bool => $v !== '' && $v !== null)),
        ]);
    }
}
