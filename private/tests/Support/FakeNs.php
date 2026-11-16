<?php

declare(strict_types=1);

namespace DynaPulse\Tests\Support;

use DynaPulse\NetSapiens\NsGateway;

/** In-memory NetSapiens double used by the test-suite. */
final class FakeNs implements NsGateway
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $cdrFixtures = [];

    /** @var array<string, array<string, mixed>> */
    public array $transcriptionFixtures = [];

    /** @var array<string, array<string, mixed>> */
    public array $recordingFixtures = [];

    /** @var list<array<string, mixed>> */
    public array $subscriptionFixtures = [];

    /** Throw this from domains() to simulate an API failure. */
    public ?\DynaPulse\NetSapiens\NsException $domainsError = null;

    /** Exact spellings domains() reports; falls back to the CDR fixture keys. */
    public array $domainNames = [];

    /** When set, transcription() answers 403 for every other spelling of the domain. */
    public ?string $exactDomain = null;

    public int $transcriptionCalls = 0;

    /** @return array<string, mixed> */
    public function token(string $username, string $password): array
    {
        if ($password === 'wrong') {
            throw new \DynaPulse\NetSapiens\NsException('Invalid credentials', 401);
        }

        [$user, $domain] = array_pad(explode('@', $username, 2), 2, 'example.com');

        return [
            'user' => $user,
            'domain' => $domain,
            'scope' => 'Basic User',
            'user_email' => $user . '@' . $domain,
            'displayName' => ucfirst($user),
            'access_token' => 'fake-token',
            'expires_in' => 3600,
            'refresh_token' => 'fake-refresh',
        ];
    }

    public function cdrs(string $domain, string $startIso, string $endIso, int $limit = 500, int $start = 0): array
    {
        return array_slice($this->cdrFixtures[$domain] ?? [], $start, $limit);
    }

    /** @var list<array<string, string>> Every transcription query the code asked for. */
    public array $transcriptionQueries = [];

    public function transcription(
        string $domain,
        string $jobId,
        string $yearMonth,
        string $origCallId = '',
        string $termCallId = '',
    ): array {
        $this->transcriptionCalls++;
        $this->transcriptionQueries[] = [
            'domain' => $domain,
            'job' => $jobId,
            'date' => $yearMonth,
            'orig' => $origCallId,
            'term' => $termCallId,
        ];

        if ($this->exactDomain !== null && $domain !== $this->exactDomain) {
            throw new \DynaPulse\NetSapiens\NsException(
                'Bad request. Invalid domain or callids. (HTTP 403)',
                403,
                '{"code":403,"message":"Bad request. Invalid domain or callids."}',
            );
        }

        $key = $domain . ':' . $jobId;
        if (!isset($this->transcriptionFixtures[$key])) {
            throw new \DynaPulse\NetSapiens\NsException('Transcription not ready', 404);
        }

        return $this->transcriptionFixtures[$key];
    }

    public function recording(string $domain, string $callId): ?array
    {
        return $this->recordingFixtures[$domain . ':' . $callId] ?? null;
    }

    public function createSubscription(array $payload): array
    {
        $payload['id'] = 'sub-' . count($this->subscriptionFixtures) + 1;
        $this->subscriptionFixtures[] = $payload;

        return $payload;
    }

    public function subscriptions(): array
    {
        return $this->subscriptionFixtures;
    }

    public function deleteSubscription(string $id): void
    {
        $this->subscriptionFixtures = array_values(array_filter(
            $this->subscriptionFixtures,
            static fn (array $s): bool => ($s['id'] ?? '') !== $id,
        ));
    }

    /** @return array<string, string> */
    public function headersForRecordingUrl(string $url): array
    {
        return ['Authorization' => 'Bearer fake-token'];
    }

    public function domains(int $limit = 200): array
    {
        if ($this->domainsError !== null) {
            throw $this->domainsError;
        }

        $names = $this->domainNames !== [] ? $this->domainNames : array_keys($this->cdrFixtures);

        return array_map(
            static fn (string $domain): array => ['domain' => $domain, 'description' => $domain],
            $names,
        );
    }
}
