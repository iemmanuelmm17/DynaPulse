<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Http\Request;
use DynaPulse\Repository\SettingsRepository;
use DynaPulse\Support\Time;

/** Builds the shared WHERE payload used by lists, dashboards, exports and reports. */
final class FilterBuilder
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    /** @param array<string, mixed> $defaults @return array<string, mixed> */
    public function fromRequest(Request $request, ?int $domainId, string $timezone, array $defaults = []): array
    {
        $days = max(1, (int) ($defaults['days'] ?? $this->settings->int('dashboard_days', 30)));

        $from = $this->date($request->query('from')) ?? Time::toLocal(Time::daysAgo($days), $timezone)->format('Y-m-d');
        $to = $this->date($request->query('to')) ?? Time::toLocal(Time::now(), $timezone)->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $filters = [
            'domain_id' => $domainId ?: null,
            'date_from' => Time::startOfDay($from, $timezone),
            'date_to' => Time::endOfDay($to, $timezone),
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
            'q' => $this->text($request->query('q')),
            'sentiment' => $this->enum($request->query('sentiment'), ['positive', 'neutral', 'negative', 'mixed']),
            'direction' => $this->enum($request->query('direction'), ['Inbound', 'Outbound', 'Internal', 'Missed']),
            'keyword_id' => $this->positiveInt($request->query('keyword')),
            'severity' => $this->enum($request->query('severity'), ['low', 'medium', 'high', 'critical']),
            'has_recording' => $this->flag($request->query('recording')),
            'has_transcript' => $this->flag($request->query('transcript')),
            'alert_only' => $request->query('alerts') === '1',
            'sort' => $this->enum($request->query('sort'), ['recent', 'oldest', 'alerts', 'duration', 'sentiment']) ?? 'recent',
        ];

        if ($filters['q'] !== null && mb_strlen($filters['q']) > 120) {
            $filters['q'] = mb_substr($filters['q'], 0, 120);
        }

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public function forExport(array $filters): array
    {
        unset($filters['sort']);

        return $filters;
    }

    public function rangeLabel(array $filters): string
    {
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        if ($from === '' || $to === '') {
            return 'All time';
        }

        if ($from === $to) {
            return date('M j, Y', strtotime($from) ?: time());
        }

        return date('M j, Y', strtotime($from) ?: time()) . ' – ' . date('M j, Y', strtotime($to) ?: time());
    }

    public function period(int $days, string $timezone): array
    {
        $to = Time::toLocal(Time::now(), $timezone)->format('Y-m-d');
        $from = Time::toLocal(Time::daysAgo($days), $timezone)->format('Y-m-d');

        return [
            'date_from' => Time::startOfDay($from, $timezone),
            'date_to' => Time::endOfDay($to, $timezone),
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
        ];
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        foreach ($allowed as $option) {
            if (strcasecmp($option, $value) === 0) {
                return $option;
            }
        }

        return null;
    }

    private function flag(mixed $value): ?bool
    {
        return match ($value) {
            '1', 1, true => true,
            '0', 0, 'false', false => false,
            default => null,
        };
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
