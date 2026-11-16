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
use DynaPulse\Service\KeywordMatcher;

final class KeywordController extends BaseController
{
    public function __construct(
        View $view,
        private KeywordRepository $keywords,
        private DomainRepository $domains,
        private KeywordMatcher $matcher,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $domainId = $this->domainId();
        $user = $this->user();

        $keywords = $user->isAdmin() && $domainId === null
            ? $this->keywords->all()
            : $this->keywords->forDomain((int) ($domainId ?? 0), false);

        $editing = null;
        $editId = (int) $request->query('edit', 0);
        if ($editId > 0) {
            $editing = $this->keywords->find($editId);
            $this->assertAccess($editing);
        }

        return $this->render('keywords/index', [
            'title' => 'Keywords',
            'subtitle' => 'Patterns watched across transcripts, summaries, topics and numbers',
            'keywords' => $keywords,
            'editing' => $editing,
            'canManage' => Context::can('manage'),
            'domains' => $user->isAdmin() ? $this->domains->all() : [],
            'domainId' => $domainId,
            'matchTypes' => KeywordRepository::MATCH_TYPES,
            'fields' => KeywordRepository::FIELDS,
            'severities' => KeywordRepository::SEVERITIES,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $data = $this->validated($request);

        $domainId = $this->domainId();
        $scope = (string) $request->post('scope', 'domain');

        // Admins looking at "All domains" create global keywords by default;
        // managers (and admins with a domain selected) are always domain scoped.
        if ($this->user()->isAdmin() && ($scope === 'global' || $domainId === null)) {
            $data['domain_id'] = null;
        } else {
            $data['domain_id'] = $domainId ?? $this->requireDomainId();
        }

        $id = $this->keywords->create($data);

        return $this->redirect('/keywords', sprintf('Keyword “%s” is now being watched.', $data['label']), 'success');
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $keyword = $this->keywords->find((int) ($params['id'] ?? 0));
        $this->assertAccess($keyword);

        $data = $this->validated($request);
        unset($data['domain_id']);
        $this->keywords->update((int) $keyword['id'], $data);

        return $this->redirect('/keywords', sprintf('Keyword “%s” updated.', $data['label']), 'success');
    }

    public function toggle(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $keyword = $this->keywords->find((int) ($params['id'] ?? 0));
        $this->assertAccess($keyword);

        $this->keywords->toggle((int) $keyword['id']);
        $fresh = $this->keywords->find((int) $keyword['id']);

        return $this->redirect('/keywords', sprintf(
            'Keyword “%s” is %s.',
            $fresh['label'] ?? 'Keyword',
            (int) ($fresh['active'] ?? 0) === 1 ? 'active' : 'paused',
        ), 'info');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $keyword = $this->keywords->find((int) ($params['id'] ?? 0));
        $this->assertAccess($keyword);

        $this->keywords->delete((int) $keyword['id']);

        return $this->redirect('/keywords', sprintf('Keyword “%s” deleted.', $keyword['label']), 'success');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $label = trim((string) $request->post('label', ''));
        $pattern = trim((string) $request->post('pattern', ''));
        $matchType = (string) $request->post('match_type', 'phrase');
        $severity = (string) $request->post('severity', 'medium');
        $fields = array_values(array_intersect((array) $request->post('fields', []), KeywordRepository::FIELDS));

        if ($label === '' || mb_strlen($label) > 80) {
            throw new HttpException(422, 'Give the keyword a short label (up to 80 characters).');
        }

        if ($pattern === '' || mb_strlen($pattern) > 300) {
            throw new HttpException(422, 'The pattern is required and must be under 300 characters.');
        }

        if (!in_array($matchType, KeywordRepository::MATCH_TYPES, true)) {
            throw new HttpException(422, 'Unknown match type.');
        }

        if ($matchType === 'regex' && !$this->matcher->isValidRegex($pattern)) {
            throw new HttpException(422, 'That regular expression is not valid.');
        }

        if ($fields === []) {
            $fields = KeywordMatcher::DEFAULT_FIELDS;
        }

        return [
            'label' => $label,
            'pattern' => $pattern,
            'match_type' => $matchType,
            'severity' => in_array($severity, KeywordRepository::SEVERITIES, true) ? $severity : 'medium',
            'fields' => $fields,
            'case_sensitive' => $request->post('case_sensitive') === '1' ? 1 : 0,
            'active' => 1,
            'notes' => trim((string) $request->post('notes', '')),
        ];
    }

    private function assertAccess(?array $keyword): void
    {
        if ($keyword === null) {
            throw new HttpException(404, 'That keyword no longer exists.');
        }

        if ($this->user()->isAdmin()) {
            return;
        }

        $domainId = $this->domainId();
        if ($domainId !== null && (int) ($keyword['domain_id'] ?? 0) === $domainId) {
            return;
        }

        throw new HttpException(403, 'That keyword belongs to another domain.');
    }
}
