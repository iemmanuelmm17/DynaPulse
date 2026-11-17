<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\RecipientRepository;

final class RecipientController extends BaseController
{
    public function __construct(
        View $view,
        private RecipientRepository $recipients,
        private DomainRepository $domains,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $user = $this->user();
        $domainId = $this->domainId();

        $recipients = $user->isAdmin() && $domainId === null
            ? $this->recipients->all()
            : $this->recipients->forDomain((int) ($domainId ?? 0));

        return $this->render('recipients/index', [
            'title' => 'Recipients',
            'subtitle' => 'Who receives keyword alerts and scheduled reports',
            'recipients' => $recipients,
            'canManage' => Context::can('manage'),
            'domains' => $user->isAdmin() ? $this->domains->all() : [],
            'domainId' => $domainId,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $email = strtolower(trim((string) $request->post('email', '')));
        $name = trim((string) $request->post('name', ''));
        $alerts = $request->post('receive_alerts', '1') === '1';
        $reports = $request->post('receive_reports', '1') === '1';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Enter a valid email address.');
        }

        $domainId = $this->domainId();
        if ($this->user()->isAdmin() && $request->post('domain_id')) {
            $requested = (int) $request->post('domain_id');
            if ($this->domains->find($requested) === null) {
                throw new HttpException(422, 'That domain does not exist.');
            }
            $domainId = $requested;
        }

        if ($domainId === null) {
            throw new HttpException(422, 'Choose a domain before adding recipients.');
        }

        $this->recipients->upsert($domainId, $email, $name, $alerts, $reports);

        return $this->redirect('/recipients', sprintf('%s will now receive %s.', $email, $this->describe($alerts, $reports)), 'success');
    }

    public function toggle(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $recipient = $this->find((int) ($params['id'] ?? 0));
        $field = (string) $request->post('field', 'active');

        $this->recipients->toggle((int) $recipient['id'], $field);
        $fresh = $this->recipients->find((int) $recipient['id']);

        $labels = ['active' => 'status', 'receive_alerts' => 'alert subscription', 'receive_reports' => 'report subscription'];
        $value = (int) ($fresh[$field] ?? 0) === 1 ? 'on' : 'off';

        return $this->redirect('/recipients', sprintf('%s: %s is now %s.', $fresh['email'] ?? 'Recipient', $labels[$field] ?? $field, $value), 'info');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireManage();

        $recipient = $this->find((int) ($params['id'] ?? 0));
        $this->recipients->delete((int) $recipient['id']);

        return $this->redirect('/recipients', sprintf('%s removed from the list.', $recipient['email']), 'success');
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $recipient = $this->recipients->find($id);

        if ($recipient === null) {
            throw new HttpException(404, 'That recipient no longer exists.');
        }

        if (!$this->user()->isAdmin()) {
            $domainId = $this->domainId();
            if ($domainId === null || (int) $recipient['domain_id'] !== $domainId) {
                throw new HttpException(403, 'That recipient belongs to another domain.');
            }
        }

        return $recipient;
    }

    private function describe(bool $alerts, bool $reports): string
    {
        if ($alerts && $reports) {
            return 'alerts and reports';
        }

        return $alerts ? 'keyword alerts' : ($reports ? 'scheduled reports' : 'nothing (subscriptions are off)');
    }
}
