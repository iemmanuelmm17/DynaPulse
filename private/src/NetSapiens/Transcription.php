<?php

declare(strict_types=1);

namespace DynaPulse\NetSapiens;

use DynaPulse\Support\Str;

/**
 * Value object for GET /domains/{domain}/transcriptions (NetSapiens Call
 * Intelligence: transcript segments, summary, topics and sentiment).
 */
final class Transcription
{
    /**
     * @param list<array{speaker:string, comment:string, sentiment:string, start:string, end:string}> $segments
     * @param list<string> $topics
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $topics,
        public readonly array $segments,
        public readonly string $text,
        public readonly int $positive,
        public readonly int $neutral,
        public readonly int $negative,
        public readonly string $label,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromApi(array $data): self
    {
        $segments = [];
        $lines = [];

        foreach ((array) ($data['call-intelligence-segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $speaker = self::speaker((string) ($segment['speaker'] ?? ''));
            $comment = self::cleanText((string) ($segment['comment'] ?? ''));
            $sentiment = ucfirst(strtolower((string) ($segment['sentiment'] ?? 'Neutral')));

            if ($comment === '') {
                continue;
            }

            $segments[] = [
                'speaker' => $speaker,
                'comment' => $comment,
                'sentiment' => $sentiment,
                'start' => (string) ($segment['start-time'] ?? ''),
                'end' => (string) ($segment['end-time'] ?? ''),
            ];

            $lines[] = $speaker . ': ' . $comment;
        }

        $topics = [];
        foreach ((array) ($data['call-intelligence-topics-top'] ?? []) as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $topics[] = $topic;
            }
        }

        $positive = (int) round((float) ($data['call-intelligence-percent-positive'] ?? 0));
        $neutral = (int) round((float) ($data['call-intelligence-percent-neutral'] ?? 0));
        $negative = (int) round((float) ($data['call-intelligence-percent-negative'] ?? 0));

        $summary = self::cleanText((string) ($data['call-intelligence-summary'] ?? ''));

        if ($summary === '' && $lines !== []) {
            $summary = Str::limit(implode(' ', array_slice($lines, 0, 4)), 240);
        }

        return new self(
            jobId: (string) ($data['call-intelligence-job-id'] ?? ''),
            status: strtolower((string) ($data['call-intelligence-processing-status'] ?? '')),
            summary: $summary,
            topics: array_values(array_unique($topics)),
            segments: $segments,
            text: implode("\n", $lines),
            positive: max(0, min(100, $positive)),
            neutral: max(0, min(100, $neutral)),
            negative: max(0, min(100, $negative)),
            label: self::sentimentLabel($positive, $neutral, $negative),
            createdAt: (string) ($data['created-datetime'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toColumns(): array
    {
        return [
            'transcript_status' => 'finished',
            'has_transcript' => 1,
            'transcript_summary' => $this->summary,
            'transcript_topics' => json_encode($this->topics, JSON_UNESCAPED_UNICODE) ?: '[]',
            'transcript_text' => $this->text,
            'transcript_segments' => json_encode($this->segments, JSON_UNESCAPED_UNICODE) ?: '[]',
            'sentiment_label' => $this->label,
            'sentiment_positive' => $this->positive,
            'sentiment_neutral' => $this->neutral,
            'sentiment_negative' => $this->negative,
        ];
    }

    public function transcriptForExport(): string
    {
        $lines = [];

        foreach ($this->segments as $segment) {
            $lines[] = sprintf('[%s] %s: %s', $segment['start'], $segment['speaker'], $segment['comment']);
        }

        return implode("\n", $lines);
    }

    /**
     * Deliberately conservative: "negative" needs a meaningful share of the
     * call, otherwise a single complaint would flag every conversation.
     */
    public static function sentimentLabel(int $positive, int $neutral, int $negative): string
    {
        if ($positive === 0 && $neutral === 0 && $negative === 0) {
            return '';
        }

        if ($negative >= 40 || ($negative >= 25 && $negative > $positive)) {
            return 'negative';
        }

        if ($positive > 0 && $negative > 0 && $positive >= 20 && $negative >= 20) {
            return 'mixed';
        }

        if ($positive >= 60) {
            return 'positive';
        }

        return 'neutral';
    }

    private static function speaker(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'Unknown';
        }

        $value = Str::phoneFromUri($raw);

        if ($value !== '' && preg_match('/^\+?[\d][\d\s\-().]*$/', $value)) {
            return Str::prettyPhone($value);
        }

        return $value !== '' ? $value : 'Unknown';
    }

    private static function cleanText(string $value): string
    {
        $value = str_replace(["\\'", '\\"'], ["'", '"'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
