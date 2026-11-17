<?php

declare(strict_types=1);

namespace DynaPulse\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything is stored in UTC as "Y-m-d H:i:s" strings (SQLite friendly) and
 * rendered in the domain's timezone at the edges.
 */
final class Time
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return gmdate(self::STORAGE_FORMAT);
    }

    public static function nowImmutable(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Accepts NetSapiens datetimes ("2024-10-01T22:48:21+00:00",
     * "2024-10-01 22:48:21", "2024-10-01T22:48:21Z[US/Pacific]") and normalises
     * them to UTC storage format. Returns null when unparseable.
     */
    public static function parse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === 'null' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        // NetSapiens sometimes appends the timezone in brackets.
        $zone = null;
        if (preg_match('/\[([A-Za-z_\/]+)\]$/', $value, $m)) {
            $zone = $m[1];
            $value = trim(substr($value, 0, -strlen($m[0])));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            if ($zone !== null && in_array($zone, timezone_identifiers_list(), true)) {
                $dt = $dt->setTimezone(new DateTimeZone($zone));
            }

            return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * NetSapiens transcription lookups need the YYYYMM of the call in the
     * server's local time; we use the domain timezone for that.
     */
    public static function yearMonth(?string $utc, string $timezone = 'UTC'): string
    {
        $dt = self::toLocal($utc, $timezone);

        return $dt->format('Ym');
    }

    public static function toLocal(?string $utc, string $timezone = 'UTC'): DateTimeImmutable
    {
        $zone = self::zone($timezone);
        $dt = $utc === null || $utc === ''
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : (DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, $utc, new DateTimeZone('UTC')) ?: new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return $dt->setTimezone($zone);
    }

    public static function display(?string $utc, string $timezone = 'UTC', string $format = 'M j, Y g:i A'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        return self::toLocal($utc, $timezone)->format($format);
    }

    public static function iso(?string $utc, string $timezone = 'UTC'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        return self::toLocal($utc, $timezone)->format('c');
    }

    public static function zone(string $timezone): DateTimeZone
    {
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        return new DateTimeZone($timezone);
    }

    public static function addMinutes(string $utc, int $minutes): string
    {
        $dt = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, $utc, new DateTimeZone('UTC'))
            ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $dt->modify(sprintf('%+d minutes', $minutes))->format(self::STORAGE_FORMAT);
    }

    public static function addSeconds(string $utc, int $seconds): string
    {
        $dt = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, $utc, new DateTimeZone('UTC'))
            ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $dt->modify(sprintf('%+d seconds', $seconds))->format(self::STORAGE_FORMAT);
    }

    public static function daysAgo(int $days): string
    {
        return gmdate(self::STORAGE_FORMAT, time() - ($days * 86400));
    }

    /** Start of day in the given timezone, expressed in UTC storage format. */
    public static function startOfDay(string $date, string $timezone): string
    {
        $zone = self::zone($timezone);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', $zone);

        return ($dt ?: new DateTimeImmutable('now', $zone))->setTimezone(new DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
    }

    /** End of day (inclusive) in the given timezone, expressed in UTC. */
    public static function endOfDay(string $date, string $timezone): string
    {
        $zone = self::zone($timezone);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59', $zone);

        return ($dt ?: new DateTimeImmutable('now', $zone))->setTimezone(new DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
    }

    public static function humanDuration(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        if ($minutes >= 60) {
            return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
        }

        return sprintf('%d:%02d', $minutes, $rest);
    }

    public static function relative(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'never';
        }

        $then = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, $utc, new DateTimeZone('UTC'));
        if ($then === false) {
            return 'never';
        }

        $diff = time() - $then->getTimestamp();
        $future = $diff < 0;
        $diff = abs($diff);

        $text = match (true) {
            $diff < 60 => 'seconds',
            $diff < 3600 => (int) round($diff / 60) . ' min',
            $diff < 86400 => (int) round($diff / 3600) . ' hr',
            $diff < 2592000 => (int) round($diff / 86400) . ' d',
            default => 'a long time',
        };

        return $future ? 'in ' . $text : $text . ' ago';
    }
}
