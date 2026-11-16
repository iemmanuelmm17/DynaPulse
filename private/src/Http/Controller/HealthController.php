<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Config;
use DynaPulse\Support\Time;

/** Small unauthenticated probe for uptime monitors. Contains no secrets. */
final class HealthController extends BaseController
{
    public function __construct(
        \DynaPulse\Http\View $view,
        private CallRepository $calls,
        private SettingsRepository $settings,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $db = true;
        $pending = 0;

        try {
            $pending = $this->calls->pendingCount();
        } catch (\Throwable) {
            $db = false;
        }

        $payload = [
            'status' => $db ? 'ok' : 'degraded',
            'app' => (string) $this->config->get('app.name', 'DynaPulse'),
            'time' => gmdate('c'),
            'database' => $db,
            'pending_calls' => $pending,
            'mail_configured' => $this->config->mailIsConfigured(),
            'api_configured' => (string) $this->config->get('ns.api_key', '') !== '',
            'webhook_configured' => (string) $this->settings->get('webhook_subscription_id') !== '',
            'last_poll' => Time::now(),
        ];

        return Response::json($payload, $db ? 200 : 503);
    }
}
