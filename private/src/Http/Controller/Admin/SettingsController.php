<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller\Admin;

use DynaPulse\Http\Controller\BaseController;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Mail\MailException;
use DynaPulse\Mail\Mailer;
use DynaPulse\NetSapiens\NsException;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\AuditRepository;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;
use DynaPulse\Support\Str;
use DynaPulse\Support\Time;

final class SettingsController extends BaseController
{
    public function __construct(
        View $view,
        private SettingsRepository $settings,
        private DomainRepository $domains,
        private CallRepository $calls,
        private Mailer $mailer,
        private NsGateway $ns,
        private AuditRepository $audit,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $subscriptions = [];
        $subscriptionError = null;
        try {
            $subscriptions = $this->ns->subscriptions();
        } catch (NsException $e) {
            $subscriptionError = $e->getMessage();
        }

        $settings = $this->settings->all();
        $webhookUrl = url('/webhook/netsapiens/' . (string) $this->config->get('ingest.webhook_secret', ''));
        $matchedSubscription = null;
        foreach ($subscriptions as $subscription) {
            if (($subscription['id'] ?? '') === ($settings['webhook_subscription_id'] ?? '')
                || ($subscription['post-url'] ?? '') === $webhookUrl
            ) {
                $matchedSubscription = $subscription;
                break;
            }
        }

        $storage = (string) $this->config->get('paths.storage');
        $checks = [
            ['Application key', strlen((string) $this->config->get('app.key', '')) >= 16, 'Set APP_KEY in .env so email audio links can be signed.'],
            ['Database', is_writable(dirname((string) $this->config->get('db.database'))), (string) $this->config->get('db.database')],
            ['Storage writable', is_writable($storage), $storage],
            ['NetSapiens API key', (string) $this->config->get('ns.api_key', '') !== '', 'NS_API_KEY is required to read recordings and CDRs.'],
            ['NetSapiens OAuth client', (string) $this->config->get('ns.client_id', '') !== '' && (string) $this->config->get('ns.client_secret', '') !== '', 'Needed to validate manager logins.'],
            ['Outlook SMTP', $this->mailer->configured(), 'MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS must be set.'],
            ['Webhook secret', (string) $this->config->get('ingest.webhook_secret', '') !== '', 'WEBHOOK_SECRET protects the ingestion endpoint.'],
            ['Event subscription', $matchedSubscription !== null, 'Create the NetSapiens cdr subscription so alerts arrive in real time.'],
        ];

        return $this->render('admin/settings', [
            'title' => 'Settings',
            'subtitle' => 'Alerting, ingestion, mail and environment health',
            'settings' => $settings,
            'mail' => [
                'host' => (string) $this->config->get('mail.host'),
                'port' => (int) $this->config->get('mail.port'),
                'encryption' => (string) $this->config->get('mail.encryption'),
                'username' => (string) $this->config->get('mail.username'),
                'from' => (string) $this->config->get('mail.from_address'),
                'from_name' => (string) $this->config->get('mail.from_name'),
                'reply_to' => (string) $this->config->get('mail.reply_to', ''),
                'password_set' => (string) $this->config->get('mail.password', '') !== '',
                'configured' => $this->mailer->configured(),
                'missing' => $this->mailer->missingSettings(),
            ],
            'ns' => [
                'base_url' => (string) $this->config->get('ns.base_url'),
                'client_id' => (string) $this->config->get('ns.client_id'),
                'client_secret_set' => (string) $this->config->get('ns.client_secret', '') !== '',
                'api_key_set' => (string) $this->config->get('ns.api_key', '') !== '',
                'timeout' => (int) $this->config->get('ns.timeout', 30),
                'lookback' => (int) $this->config->get('ingest.lookback_minutes', 10),
            ],
            'env' => [
                'env_file' => (string) $this->config->get('paths.private') . DIRECTORY_SEPARATOR . '.env',
                'database' => (string) $this->config->get('db.database'),
                'storage' => (string) $this->config->get('paths.storage'),
                'php' => PHP_VERSION,
                'log_file' => 'app-' . gmdate('Y-m-d') . '.log',
            ],
            'webhook' => [
                'url' => $webhookUrl,
                'secret' => (string) $this->config->get('ingest.webhook_secret'),
                'subscription' => $matchedSubscription,
                'error' => $subscriptionError,
                'count' => count($subscriptions),
            ],
            'domains' => $this->domains->all(true),
            'ingestion' => [
                'latest_call' => $this->calls->latestStartedAt(),
                'domains' => $this->domains->all(),
            ],
            'checks' => $checks,
            'appVersion' => '1.0.0',
            'timezone' => (string) $this->config->get('app.timezone'),
            'logTail' => $this->tailLog(),
        ]);
    }

    public function save(Request $request, array $params = []): Response
    {
        $minSeverity = (string) $request->post('alert_min_severity', 'low');
        if (!in_array($minSeverity, ['low', 'medium', 'high', 'critical'], true)) {
            $minSeverity = 'low';
        }

        $extra = Str::splitEmails((string) $request->post('alert_extra_recipients', ''));
        $reportDefaults = Str::splitEmails((string) $request->post('default_report_recipients', ''));

        $this->settings->setMany([
            'alerts_enabled' => $request->post('alerts_enabled') === '1' ? '1' : '0',
            'alert_min_severity' => $minSeverity,
            'alert_include_transcript' => $request->post('alert_include_transcript') === '1' ? '1' : '0',
            'match_in_summary' => $request->post('match_in_summary') === '1' ? '1' : '0',
            'match_in_transcript' => $request->post('match_in_transcript') === '1' ? '1' : '0',
            'alert_extra_recipients' => implode(', ', $extra),
            'default_report_recipients' => implode(', ', $reportDefaults),
            'transcript_max_attempts' => (string) max(1, min(30, (int) $request->post('transcript_max_attempts', 8))),
            'dashboard_days' => (string) max(1, min(90, (int) $request->post('dashboard_days', 30))),
            'retention_days' => (string) max(0, min(3650, (int) $request->post('retention_days', 365))),
            'poll_window_hours' => (string) max(1, min(168, (int) $request->post('poll_window_hours', 24))),
            'auto_import_domains' => $request->post('auto_import_domains') === '1' ? '1' : '0',
        ]);

        $this->audit->log('settings.updated', $this->user()->id, null, [], $request->ip());

        return $this->redirect('/settings', 'Settings saved.', 'success');
    }

    public function testMail(Request $request, array $params = []): Response
    {
        $to = trim((string) $request->post('email', '')) ?: $this->user()->email;

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Enter a valid email address to send the test to.');
        }

        try {
            $this->mailer->sendTest($to);

            return $this->redirect('/settings', sprintf('Test email sent to %s. Check the inbox (and spam).', $to), 'success');
        } catch (MailException $e) {
            Logger::warning('mail.test_failed', ['error' => $e->getMessage()]);

            return $this->redirect('/settings', 'SMTP test failed: ' . $e->getMessage(), 'error');
        }
    }

    public function createSubscription(Request $request, array $params = []): Response
    {
        $secret = (string) $this->config->get('ingest.webhook_secret', '');
        if ($secret === '') {
            return $this->redirect('/settings', 'Set WEBHOOK_SECRET in .env before creating the event subscription.', 'error');
        }

        $scope = (string) $request->post('scope', 'all');
        $postUrl = url('/webhook/netsapiens/' . $secret);

        $payload = [
            'model' => 'cdr',
            'post-url' => $postUrl,
            'domain' => $scope === 'all' ? '*' : $scope,
            'user' => '*',
            'subscription-geo-support' => 'yes',
        ];

        try {
            $response = $this->ns->createSubscription($payload);
        } catch (NsException $e) {
            Logger::warning('subscription.create_failed', ['error' => $e->getMessage()]);

            return $this->redirect('/settings', 'NetSapiens rejected the subscription: ' . $e->getMessage(), 'error');
        }

        $id = (string) ($response['id'] ?? $response['subscription-id'] ?? '');
        if ($id === '') {
            return $this->redirect('/settings', 'The subscription was created but NetSapiens did not return an id. Check the event subscription list.', 'warning');
        }

        $this->settings->set('webhook_subscription_id', $id);
        $this->settings->set('webhook_created_at', Time::now());
        $this->audit->log('subscription.created', $this->user()->id, null, ['id' => $id], $request->ip());

        return $this->redirect('/settings', sprintf('Event subscription %s created. Completed calls now post to DynaPulse.', $id), 'success');
    }

    private function tailLog(int $lines = 12): string
    {
        $directory = (string) $this->config->get('paths.logs');
        $file = $directory . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';

        if (!is_file($file)) {
            return '';
        }

        $content = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return implode("\n", array_slice($content, -$lines));
    }
}
