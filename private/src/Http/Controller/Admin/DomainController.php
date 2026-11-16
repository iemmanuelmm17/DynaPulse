<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller\Admin;

use DynaPulse\Http\Controller\BaseController;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\AuditRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Repository\UserRepository;
use DynaPulse\Service\DomainContext;
use DynaPulse\Support\Logger;

final class DomainController extends BaseController
{
    public function __construct(
        View $view,
        private DomainRepository $domains,
        private UserRepository $users,
        private SettingsRepository $settings,
        private DomainContext $domainContext,
        private AuditRepository $audit,
        private NsGateway $ns,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $domains = $this->domains->all();
        $rows = [];
        foreach ($domains as $domain) {
            $rows[] = $domain + ['stats' => $this->domains->stats((int) $domain['id'])];
        }

        $subscription = null;
        $subscriptionError = null;
        try {
            $subscription = $this->findSubscription();
        } catch (NsException $e) {
            $subscriptionError = $e->getMessage();
        }

        $timezones = timezone_identifiers_list();
        sort($timezones);

        return $this->render('admin/domains', [
            'title' => 'Domains',
            'subtitle' => count($rows) . ' domain(s) monitored by this portal',
            'domains' => $rows,
            'timezones' => $timezones,
            'defaultTimezone' => (string) \DynaPulse\Http\Context::config('app.timezone', 'UTC'),
            'subscription' => $subscription,
            'subscriptionError' => $subscriptionError,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $domain = strtolower(trim((string) $request->post('domain', '')));
        $label = trim((string) $request->post('label', ''));
        $timezone = (string) $request->post('timezone', 'UTC');

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $domain)) {
            throw new HttpException(422, 'Domain names may contain letters, numbers, dots, dashes and underscores.');
        }

        if ($this->domains->findByDomain($domain) !== null) {
            throw new HttpException(422, 'That domain is already configured.');
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $id = $this->domains->create($domain, $label !== '' ? $label : $domain, $timezone);
        $this->audit->log('domain.created', $this->user()->id, $id, ['domain' => $domain], $request->ip());

        return $this->redirect('/domains', sprintf('Domain %s added.', $domain), 'success');
    }

    public function sync(Request $request, array $params = []): Response
    {
        try {
            $remote = $this->ns->domains(200);
        } catch (NsException $e) {
            Logger::warning('domains.sync_failed', ['error' => $e->getMessage()]);

            return $this->redirect('/domains', 'NetSapiens could not be reached: ' . $e->getMessage(), 'error');
        }

        $added = 0;
        foreach ($remote as $row) {
            $domain = trim((string) ($row['domain'] ?? ''));
            if ($domain === '' || $this->domains->findByDomain($domain) !== null) {
                continue;
            }

            $timezone = (string) \DynaPulse\Http\Context::config('app.timezone', 'UTC');
            $this->domains->ensure($domain, (string) ($row['description'] ?? $domain), $timezone);
            $added++;
        }

        $this->audit->log('domain.synced', $this->user()->id, null, ['added' => $added], $request->ip());

        return $this->redirect('/domains', $added === 0
            ? 'No new domains were found in NetSapiens.'
            : sprintf('%d domain(s) imported from NetSapiens.', $added), 'success');
    }

    public function toggle(Request $request, array $params = []): Response
    {
        $domain = $this->find((int) ($params['id'] ?? 0));
        $field = (string) $request->post('field', 'enabled');
        $this->domains->toggle((int) $domain['id'], $field);

        $fresh = $this->domains->find((int) $domain['id']);
        $this->audit->log('domain.toggled', $this->user()->id, (int) $domain['id'], ['field' => $field], $request->ip());

        return $this->redirect('/domains', sprintf(
            '%s: %s is now %s.',
            $fresh['domain'] ?? 'Domain',
            $field === 'enabled' ? 'monitoring' : 'polling',
            (int) ($fresh[$field] ?? 0) === 1 ? 'on' : 'off',
        ), 'info');
    }

    public function switch(Request $request, array $params = []): Response
    {
        $value = (string) $request->post('domain_id', 'all');

        if ($value === 'all' || $value === '') {
            $this->domainContext->switchTo(null);
        } else {
            $domain = $this->domains->find((int) $value);
            if ($domain === null) {
                throw new HttpException(404, 'That domain no longer exists.');
            }
            $this->domainContext->switchTo((int) $domain['id']);
        }

        $previous = $request->server('HTTP_REFERER');
        $target = '/';
        if (is_string($previous)) {
            $path = parse_url($previous, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/')) {
                $target = $path;
            }
        }

        return Response::redirect($target);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $domain = $this->find((int) ($params['id'] ?? 0));
        $this->domains->delete((int) $domain['id']);
        $this->audit->log('domain.deleted', $this->user()->id, null, ['domain' => $domain['domain']], $request->ip());

        $current = $this->domainContext->current($this->user());
        if ($current !== null && (string) $current['domain'] === (string) $domain['domain']) {
            $this->domainContext->switchTo(null);
        }

        return $this->redirect('/domains', sprintf('Domain %s and its stored calls were deleted.', $domain['domain']), 'success');
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $domain = $this->domains->find($id);

        if ($domain === null) {
            throw new HttpException(404, 'That domain no longer exists.');
        }

        return $domain;
    }

    private function findSubscription(): ?array
    {
        $id = (string) $this->settings->get('webhook_subscription_id');
        $url = url('/webhook/netsapiens/' . (string) \DynaPulse\Http\Context::config('ingest.webhook_secret', ''));

        foreach ($this->ns->subscriptions() as $subscription) {
            if (($subscription['id'] ?? '') === $id || ($subscription['post-url'] ?? '') === $url) {
                return $subscription;
            }
        }

        return null;
    }
}
