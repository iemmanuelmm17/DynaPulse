<?php

declare(strict_types=1);

use DynaPulse\Http\Controller\Admin\DomainController;
use DynaPulse\Http\Controller\Admin\SettingsController;
use DynaPulse\Http\Controller\Admin\UserController;
use DynaPulse\Http\Controller\AlertController;
use DynaPulse\Http\Controller\AudioController;
use DynaPulse\Http\Controller\AuthController;
use DynaPulse\Http\Controller\CallController;
use DynaPulse\Http\Controller\DashboardController;
use DynaPulse\Http\Controller\ExportController;
use DynaPulse\Http\Controller\HealthController;
use DynaPulse\Http\Controller\KeywordController;
use DynaPulse\Http\Controller\RecipientController;
use DynaPulse\Http\Controller\ReportController;
use DynaPulse\Http\Controller\WebhookController;
use DynaPulse\Http\Router;

/** @var Router $router */

$router->get('/', [DashboardController::class, 'index'], ['auth']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth']);

$router->get('/calls', [CallController::class, 'index'], ['auth']);
$router->get('/calls/{id}', [CallController::class, 'show'], ['auth']);
$router->post('/calls/{id}/retry', [CallController::class, 'retry'], ['auth', 'csrf']);
$router->get('/calls/{id}/transcript', [CallController::class, 'transcript'], ['auth']);
$router->get('/calls/{id}/audio', [AudioController::class, 'stream'], []);

$router->get('/alerts', [AlertController::class, 'index'], ['auth']);

$router->get('/keywords', [KeywordController::class, 'index'], ['auth']);
$router->post('/keywords', [KeywordController::class, 'store'], ['auth', 'csrf']);
$router->post('/keywords/{id}', [KeywordController::class, 'update'], ['auth', 'csrf']);
$router->post('/keywords/{id}/toggle', [KeywordController::class, 'toggle'], ['auth', 'csrf']);
$router->post('/keywords/{id}/delete', [KeywordController::class, 'destroy'], ['auth', 'csrf']);

$router->get('/recipients', [RecipientController::class, 'index'], ['auth']);
$router->post('/recipients', [RecipientController::class, 'store'], ['auth', 'csrf']);
$router->post('/recipients/{id}/toggle', [RecipientController::class, 'toggle'], ['auth', 'csrf']);
$router->post('/recipients/{id}/delete', [RecipientController::class, 'destroy'], ['auth', 'csrf']);

$router->get('/reports', [ReportController::class, 'index'], ['auth']);
$router->post('/reports/schedules', [ReportController::class, 'store'], ['auth', 'csrf']);
$router->post('/reports/schedules/{id}/run', [ReportController::class, 'runNow'], ['auth', 'csrf']);
$router->post('/reports/schedules/{id}/delete', [ReportController::class, 'destroy'], ['auth', 'csrf']);

$router->get('/exports/calls.{format}', [ExportController::class, 'calls'], ['auth']);
$router->get('/exports/alerts.{format}', [ExportController::class, 'alerts'], ['auth']);
$router->get('/exports/call/{id}.txt', [ExportController::class, 'transcript'], ['auth']);

$router->get('/domains', [DomainController::class, 'index'], ['auth', 'admin']);
$router->post('/domains', [DomainController::class, 'store'], ['auth', 'admin', 'csrf']);
$router->post('/domains/sync', [DomainController::class, 'sync'], ['auth', 'admin', 'csrf']);
$router->post('/domains/switch', [DomainController::class, 'switch'], ['auth', 'csrf']);
$router->post('/domains/{id}/toggle', [DomainController::class, 'toggle'], ['auth', 'admin', 'csrf']);
$router->post('/domains/{id}/delete', [DomainController::class, 'destroy'], ['auth', 'admin', 'csrf']);

$router->get('/users', [UserController::class, 'index'], ['auth', 'admin']);
$router->post('/users/{id}', [UserController::class, 'update'], ['auth', 'admin', 'csrf']);

$router->get('/settings', [SettingsController::class, 'index'], ['auth', 'admin']);
$router->post('/settings', [SettingsController::class, 'save'], ['auth', 'admin', 'csrf']);
$router->post('/settings/test-mail', [SettingsController::class, 'testMail'], ['auth', 'admin', 'csrf']);
$router->post('/settings/webhook', [SettingsController::class, 'createSubscription'], ['auth', 'admin', 'csrf']);

$router->post('/webhook/netsapiens/{secret}', [WebhookController::class, 'receive'], []);
$router->get('/health', [HealthController::class, 'index'], []);
