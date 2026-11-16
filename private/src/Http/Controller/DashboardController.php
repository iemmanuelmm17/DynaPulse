<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\DomainRepository;
use DynaPulse\Repository\KeywordRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Repository\NotificationRepository;
use DynaPulse\Repository\RecipientRepository;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Service\FilterBuilder;

final class DashboardController extends BaseController
{
    public function __construct(
        View $view,
        private CallRepository $calls,
        private MatchRepository $matches,
        private DomainRepository $domains,
        private KeywordRepository $keywords,
        private RecipientRepository $recipients,
        private NotificationRepository $notifications,
        private SettingsRepository $settings,
        private FilterBuilder $filters,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        $user = $this->user();
        $timezone = Context::timezone();
        $domainId = $this->domainId();
        $days = max(1, min(90, $this->settings->int('dashboard_days', 30)));

        $filters = $this->filters->fromRequest($request, $domainId, $timezone, ['days' => $days]);
        $filters['sort'] = 'recent';

        $stats = $this->calls->stats($filters);
        $total = max(1, $stats['calls']);
        $series = $this->calls->dailySeries($filters, $timezone, 14);

        $checklist = [
            'domains' => $this->domains->all(true) !== [],
            'keywords' => $this->keywords->activeCount() > 0,
            'recipients' => $this->recipients->all() !== [],
            'mail' => (bool) Context::config('mail.host') && (bool) Context::config('mail.username') && (bool) Context::config('mail.password'),
            'webhook' => (string) $this->settings->get('webhook_subscription_id') !== '',
        ];

        $lastPoll = null;
        foreach ($this->domains->all() as $domain) {
            if (($domain['last_polled_at'] ?? null) !== null && ($lastPoll === null || $domain['last_polled_at'] > $lastPoll)) {
                $lastPoll = (string) $domain['last_polled_at'];
            }
        }

        return $this->render('dashboard', [
            'title' => 'Dashboard',
            'subtitle' => $domainId === null
                ? 'All domains · last ' . $days . ' days'
                : (Context::$domainRow['label'] ?? '') . ' · last ' . $days . ' days',
            'filters' => $filters,
            'stats' => $stats,
            'sentiment' => [
                'positive' => (int) round($stats['positive'] / $total * 100),
                'neutral' => (int) round($stats['neutral'] / $total * 100),
                'negative' => (int) round($stats['negative'] / $total * 100),
                'mixed' => (int) round($stats['mixed'] / $total * 100),
            ],
            'series' => $series,
            'topKeywords' => $this->matches->topKeywords($filters, 6),
            'topTopics' => $this->calls->topTopics($filters, 10),
            'recentAlerts' => $this->matches->query($filters, 6, 0)['rows'],
            'recentCalls' => $this->calls->query($filters, 6, 0)['rows'],
            'latestCallAt' => $this->calls->latestStartedAt(),
            'pendingCalls' => $this->calls->pendingCount(),
            'notificationsFailed' => $this->notifications->failedCount(7),
            'notificationsSent' => $this->notifications->sentCount(30),
            'lastPoll' => $lastPoll,
            'checklist' => $checklist,
            'domains' => $user->isAdmin() && $domainId === null ? $this->domains->all() : [],
            'timezone' => $timezone,
        ]);
    }
}
