<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use DynaPulse\Repository\CallRepository;
use DynaPulse\Repository\MatchRepository;
use DynaPulse\Support\Time;

final class Exporter
{
    public function __construct(
        private CallRepository $calls,
        private MatchRepository $matches,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{headers:list<string>, rows:list<list<mixed>>}
     */
    public function callRows(array $filters, int $limit = 10000): array
    {
        $timezone = (string) ($filters['timezone'] ?? 'UTC');
        $result = $this->calls->query($filters, $limit, 0, (string) ($filters['sort'] ?? 'recent'));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $result['rows']);
        $matchMap = $this->matches->forCalls($ids);

        $headers = [
            'Started', 'Domain', 'Direction', 'Caller', 'Caller name', 'Called', 'Answered by',
            'Duration (s)', 'Talk (s)', 'Sentiment', 'Positive %', 'Neutral %', 'Negative %',
            'Keywords', 'Summary', 'Topics', 'Recording', 'Call ID',
        ];

        $rows = [];
        foreach ($result['rows'] as $call) {
            $matches = $matchMap[(int) $call['id']] ?? [];
            $rows[] = [
                Time::display((string) $call['started_at'], $timezone, 'Y-m-d H:i:s'),
                (string) ($call['domain_name'] ?? ''),
                (string) $call['direction'],
                (string) $call['caller_number'],
                (string) $call['caller_name'],
                (string) $call['called_number'],
                (string) $call['answered_by'],
                (int) $call['duration_seconds'],
                (int) $call['talk_seconds'],
                ucfirst((string) $call['sentiment_label']),
                (int) $call['sentiment_positive'],
                (int) $call['sentiment_neutral'],
                (int) $call['sentiment_negative'],
                implode(', ', array_map(static fn (array $m): string => (string) $m['keyword_label'], $matches)),
                (string) $call['transcript_summary'],
                implode(', ', (array) $call['transcript_topics']),
                (int) $call['has_recording'] === 1 ? 'yes' : 'no',
                (string) $call['call_id'],
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{headers:list<string>, rows:list<list<mixed>>}
     */
    public function alertRows(array $filters, int $limit = 10000): array
    {
        $timezone = (string) ($filters['timezone'] ?? 'UTC');
        $result = $this->matches->query($filters, $limit, 0);

        $headers = ['Matched at', 'Call time', 'Domain', 'Keyword', 'Severity', 'Field', 'Caller', 'Called', 'Snippet', 'Call ID'];
        $rows = [];

        foreach ($result['rows'] as $match) {
            $rows[] = [
                Time::display((string) $match['created_at'], $timezone, 'Y-m-d H:i:s'),
                Time::display((string) $match['started_at'], $timezone, 'Y-m-d H:i:s'),
                (string) ($match['domain_name'] ?? ''),
                (string) $match['keyword_label'],
                ucfirst((string) $match['severity']),
                (string) $match['field'],
                (string) $match['caller_number'],
                (string) $match['called_number'],
                (string) $match['snippet'],
                (string) ($match['call_id'] ?? ''),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // UTF-8 BOM so Excel opens accented characters correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ',', '"', '\\', "\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static function ($value): string {
                if (is_string($value)) {
                    // Neutralise CSV formula injection.
                    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                        return "'" . $value;
                    }

                    return $value;
                }

                return (string) $value;
            }, $row), ',', '"', '\\', "\r\n");
        }

        rewind($handle);
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $contents;
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function toXlsx(string $sheetName, array $headers, array $rows): string
    {
        $widths = [];
        foreach ($headers as $index => $header) {
            $width = max(12, mb_strlen($header) + 4);
            foreach (array_slice($rows, 0, 200) as $row) {
                $width = max($width, min(60, mb_strlen((string) ($row[$index] ?? '')) + 2));
            }
            $widths[] = $width;
        }

        return XlsxWriter::build($sheetName, $headers, $rows, $widths);
    }

    /**
     * @param array<string, mixed> $call
     * @return array{filename:string, content:string, mime:string}
     */
    public function callsFile(array $filters, string $format): array
    {
        $data = $this->callRows($filters);
        $stamp = date('Ymd_His');
        $base = 'dynapulse_calls_' . $stamp;

        if ($format === 'xlsx') {
            if (!XlsxWriter::available()) {
                return ['filename' => $base . '.csv', 'content' => $this->toCsv($data['headers'], $data['rows']), 'mime' => 'text/csv'];
            }

            return [
                'filename' => $base . '.xlsx',
                'content' => $this->toXlsx('Calls', $data['headers'], $data['rows']),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        return ['filename' => $base . '.csv', 'content' => $this->toCsv($data['headers'], $data['rows']), 'mime' => 'text/csv'];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{filename:string, content:string, mime:string}
     */
    public function alertsFile(array $filters, string $format): array
    {
        $data = $this->alertRows($filters);
        $stamp = date('Ymd_His');
        $base = 'dynapulse_alerts_' . $stamp;

        if ($format === 'xlsx') {
            if (!XlsxWriter::available()) {
                return ['filename' => $base . '.csv', 'content' => $this->toCsv($data['headers'], $data['rows']), 'mime' => 'text/csv'];
            }

            return [
                'filename' => $base . '.xlsx',
                'content' => $this->toXlsx('Alerts', $data['headers'], $data['rows']),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        return ['filename' => $base . '.csv', 'content' => $this->toCsv($data['headers'], $data['rows']), 'mime' => 'text/csv'];
    }

    /** @param array<string, mixed> $call */
    public function transcriptText(array $call): string
    {
        $lines = [];
        $lines[] = 'DynaPulse call transcript';
        $lines[] = str_repeat('=', 60);
        $lines[] = 'Domain:    ' . ($call['domain_name'] ?? '');
        $lines[] = 'Caller:    ' . ($call['caller_number'] ?? '');
        $lines[] = 'Called:    ' . ($call['called_number'] ?? '');
        $lines[] = 'Started:   ' . Time::display((string) $call['started_at'], (string) ($call['domain_timezone'] ?? 'UTC'));
        $lines[] = 'Duration:  ' . Time::humanDuration((int) $call['duration_seconds']);
        $lines[] = 'Sentiment: ' . ucfirst((string) $call['sentiment_label']) . sprintf(
            ' (positive %d%%, neutral %d%%, negative %d%%)',
            (int) $call['sentiment_positive'],
            (int) $call['sentiment_neutral'],
            (int) $call['sentiment_negative'],
        );
        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = str_repeat('-', 60);
        $lines[] = (string) $call['transcript_summary'];
        $lines[] = '';
        $lines[] = 'Topics: ' . implode(', ', (array) $call['transcript_topics']);
        $lines[] = '';
        $lines[] = 'Transcript';
        $lines[] = str_repeat('-', 60);

        foreach ((array) $call['transcript_segments'] as $segment) {
            $lines[] = sprintf('[%s] %s: %s', $segment['start'] ?? '', $segment['speaker'] ?? '', $segment['comment'] ?? '');
        }

        if ((array) $call['transcript_segments'] === []) {
            $lines[] = (string) $call['transcript_text'];
        }

        $lines[] = '';
        $lines[] = 'Exported ' . gmdate('Y-m-d H:i:s') . ' UTC by DynaPulse';

        return implode("\r\n", $lines);
    }
}
