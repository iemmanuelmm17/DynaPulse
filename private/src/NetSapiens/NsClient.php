<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;

final class NsClient implements NsGateway
{
    public function __construct(private Config $config)
    {
    }

    public function apiRoot(): string
    {
        $base = rtrim((string) $this->config->get('ns.base_url', 'https://ns-api.com'), '/');

        if (preg_match('#/ns-api/v\d+$#', $base)) {
            return $base;
        }

        return $base . '/ns-api/v2';
    }

    public function host(): string
    {
        return (string) (parse_url($this->apiRoot(), PHP_URL_HOST) ?: '');
    }

    /** @return array<string, mixed> */
    public function token(string $username, string $password): array
    {
        $clientId = (string) $this->config->get('ns.client_id', '');
        $clientSecret = (string) $this->config->get('ns.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new NsException('NS_CLIENT_ID / NS_CLIENT_SECRET are not configured. Manager sign-in needs them.', 500);
        }

        return $this->request('POST', '/tokens', [], [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function cdrs(string $domain, string $startIso, string $endIso, int $limit = 500, int $start = 0): array
    {
        $result = $this->request('GET', '/domains/' . rawurlencode($domain) . '/cdrs', [
            'datetime-start' => $startIso,
            'datetime-end' => $endIso,
            'limit' => $limit,
            'start' => $start === 0 ? null : $start,
        ]);

        return self::listOf($result);
    }

    public function transcription(string $domain, string $jobId, string $yearMonth, string $callId): array
    {
        return $this->request('GET', '/domains/' . rawurlencode($domain) . '/transcriptions', [
            'id' => $jobId,
            'date' => $yearMonth,
            'callid' => $callId,
        ]);
    }

    public function recording(string $domain, string $callId): ?array
    {
        try {
            $result = $this->request('GET', '/domains/' . rawurlencode($domain) . '/recordings/' . rawurlencode($callId));
        } catch (NsException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }

        foreach (self::listOf($result) as $recording) {
            if (is_array($recording) && ($recording['file-access-url'] ?? '') !== '') {
                return $recording;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public function createSubscription(array $payload): array
    {
        return $this->request('POST', '/subscriptions', [], $payload);
    }

    public function subscriptions(): array
    {
        return self::listOf($this->request('GET', '/subscriptions'));
    }

    public function deleteSubscription(string $id): void
    {
        $this->request('DELETE', '/subscriptions/' . rawurlencode($id));
    }

    public function domains(int $limit = 200): array
    {
        return self::listOf($this->request('GET', '/domains', ['limit' => $limit]));
    }

    /** Fetch a user record; used to enrich manager profiles after login. */
    public function user(string $domain, string $user): ?array
    {
        try {
            return $this->request('GET', '/domains/' . rawurlencode($domain) . '/users/' . rawurlencode($user));
        } catch (NsException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string, string> Extra headers required to download a recording URL. */
    public function headersForRecordingUrl(string $url): array
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $apiHost = $this->host();

        if ($host !== '' && $apiHost !== '' && strcasecmp($host, $apiHost) === 0) {
            return ['Authorization' => 'Bearer ' . (string) $this->config->get('ns.api_key', '')];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $token = null): array
    {
        $url = $this->apiRoot() . $path;
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $bearer = $token ?? (string) $this->config->get('ns.api_key', '');
        if ($bearer === '') {
            throw new NsException('NS_API_KEY is not configured, DynaPulse cannot read the NetSapiens API.', 500);
        }

        $attempt = 0;
        $maxAttempts = 3;

        while (true) {
            $attempt++;
            [$status, $raw, $error] = $this->perform($method, $url, $body, $bearer);

            if ($error !== null) {
                if ($attempt < $maxAttempts) {
                    usleep(400000 * $attempt);
                    continue;
                }

                throw new NsException('NetSapiens request failed: ' . $error, 0);
            }

            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if ($raw !== '' && !is_array($decoded)) {
                $decoded = ['raw' => $raw];
            }

            if ($status >= 200 && $status < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            $message = self::errorMessage($decoded, $status);

            if ($status === 429 || $status >= 500) {
                if ($attempt < $maxAttempts) {
                    usleep(500000 * $attempt);
                    continue;
                }
            }

            Logger::warning('netsapiens.request_failed', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'error' => $message,
            ]);

            throw new NsException($message, $status, $raw);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0:int, 1:string, 2:?string}
     */
    private function perform(string $method, string $url, ?array $body, string $bearer): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return [0, '', 'cannot initialise cURL'];
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'User-Agent: DynaPulse/1.0',
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config->get('ns.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) $this->config->get('ns.verify_ssl', true),
            CURLOPT_SSL_VERIFYHOST => $this->config->get('ns.verify_ssl', true) ? 2 : 0,
            CURLOPT_ENCODING => '',
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $started = microtime(true);
        $raw = curl_exec($handle);
        $error = $raw === false ? (curl_error($handle) ?: 'unknown cURL error') : null;
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        Logger::debug('netsapiens.request', [
            'method' => $method,
            'url' => preg_replace('/\?.*$/', '', $url),
            'status' => $status,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return [$status, $raw === false ? '' : (string) $raw, $error];
    }

    /** @param mixed $result @return list<array<string, mixed>> */
    private static function listOf(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }

        // Some endpoints wrap the payload in a named key.
        foreach ($result as $value) {
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [$result];
    }

    private static function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded)) {
            foreach (['message', 'error', 'detail'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                    return sprintf('%s (HTTP %d)', $decoded[$key], $status);
                }
            }
        }

        return match ($status) {
            401 => 'NetSapiens rejected the API credentials (HTTP 401).',
            403 => 'NetSapiens denied access to that resource (HTTP 403).',
            404 => 'Not found in NetSapiens (HTTP 404).',
            429 => 'NetSapiens rate limit reached (HTTP 429).',
            default => sprintf('NetSapiens returned HTTP %d.', $status),
        };
    }
}
