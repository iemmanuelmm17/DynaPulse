<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Service\IngestService;
use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;

/**
 * Receives NetSapiens "cdr" event subscription posts.
 * Validated by the secret in the URL, plus the optional X-Correlation-ID.
 */
final class WebhookController extends BaseController
{
    public function __construct(
        View $view,
        private IngestService $ingest,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function receive(Request $request, array $params = []): Response
    {
        $expected = (string) $this->config->get('ingest.webhook_secret', '');
        $provided = (string) ($params['secret'] ?? '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            Logger::warning('webhook.rejected', ['reason' => 'bad_secret', 'ip' => $request->ip()]);

            return Response::json(['ok' => false, 'error' => 'Invalid webhook secret.'], 403);
        }

        $subscriptionId = (string) $this->config->get('ingest.subscription_id', '');
        $correlation = (string) $request->header('x-correlation-id', '');

        if ($subscriptionId !== '' && $correlation !== '' && !hash_equals($subscriptionId, $correlation)) {
            Logger::warning('webhook.rejected', ['reason' => 'bad_correlation', 'correlation' => $correlation]);

            return Response::json(['ok' => false, 'error' => 'Subscription mismatch.'], 403);
        }

        $length = (int) $request->header('content-length', '0');
        if ($length > 8 * 1024 * 1024) {
            return Response::json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        $payload = json_decode($request->rawBody(), true);

        if (!is_array($payload)) {
            return Response::json(['ok' => false, 'error' => 'Expected a JSON array of CDR objects.'], 400);
        }

        // NetSapiens posts an array; accept a single object too.
        $records = array_is_list($payload) ? $payload : [$payload];
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            $records = $payload['data'];
        }

        $accepted = 0;
        $results = [];

        foreach (array_slice($records, 0, 500) as $cdr) {
            if (!is_array($cdr)) {
                continue;
            }

            $domain = (string) ($cdr['domain'] ?? $cdr['call-orig-domain'] ?? $cdr['call-term-domain'] ?? '');

            try {
                $result = $this->ingest->ingestCdr($domain, $cdr);
                $results[] = $result;
                if ($result['status'] !== 'ignored') {
                    $accepted++;
                }
            } catch (\Throwable $e) {
                Logger::exception($e, 'webhook.ingest_failed');
                $results[] = ['status' => 'error', 'message' => $e->getMessage(), 'domain' => $domain];
            }
        }

        return Response::json([
            'ok' => true,
            'received' => count($records),
            'accepted' => $accepted,
            'results' => array_map(static fn (array $r): array => [
                'status' => $r['status'],
                'message' => $r['message'] ?? '',
            ], $results),
        ]);
    }
}
