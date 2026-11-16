<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

/**
 * The slice of the NetSapiens API DynaPulse depends on. Kept as an interface so
 * the ingestion pipeline can be tested against a fake without network access.
 */
interface NsGateway
{
    /** @return array<string, mixed> */
    public function token(string $username, string $password): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function cdrs(string $domain, string $startIso, string $endIso, int $limit = 500, int $start = 0): array;

    /** @return array<string, mixed> */
    public function transcription(string $domain, string $jobId, string $yearMonth, string $callId): array;

    /** @return array<string, mixed>|null */
    public function recording(string $domain, string $callId): ?array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createSubscription(array $payload): array;

    /** @return list<array<string, mixed>> */
    public function subscriptions(): array;

    public function deleteSubscription(string $id): void;

    /** @return list<array<string, mixed>> */
    public function domains(int $limit = 200): array;

    /**
     * Auth headers required to fetch a recording file (the file-access-url often
     * lives on a media host that still expects the API key).
     *
     * @return array<string, string>
     */
    public function headersForRecordingUrl(string $url): array;
}
