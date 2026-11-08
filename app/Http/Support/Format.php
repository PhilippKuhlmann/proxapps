<?php

namespace App\Http\Support;

use App\Support\Settings;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Display helpers shared by the Blade views (dates in the configured timezone, sizes).
 */
class Format
{
    private static ?string $tz = null;

    public static function timezone(): string
    {
        if (self::$tz !== null) {
            return self::$tz;
        }
        try {
            $tz = (string) (Settings::get('timezone') ?: 'Europe/Berlin');
            new DateTimeZone($tz);
        } catch (Throwable) {
            $tz = 'UTC';
        }

        return self::$tz = $tz;
    }

    /** Forget the cached timezone (after the settings were saved). */
    public static function flush(): void
    {
        self::$tz = null;
    }

    public static function dt(CarbonInterface|string|null $value, bool $seconds = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $date = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);
        } catch (Throwable) {
            return (string) $value;
        }

        return $date->setTimezone(self::timezone())->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i');
    }

    public static function time(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $date = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);
        } catch (Throwable) {
            return (string) $value;
        }

        return $date->setTimezone(self::timezone())->format('H:i:s');
    }

    public static function bytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $n = (float) $value;
        foreach (['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as $unit) {
            if ($n < 1024 || $unit === 'PB') {
                return in_array($unit, ['B', 'KB'], true)
                    ? number_format($n, 0, ',', '.').' '.$unit
                    : number_format($n, 1, ',', '.').' '.$unit;
            }
            $n /= 1024;
        }

        return '—';
    }

    /** Percentage 0–100 of $part in $total, or null when unknown. */
    public static function percent(mixed $part, mixed $total): ?int
    {
        if (! is_numeric($part) || ! is_numeric($total) || (float) $total <= 0) {
            return null;
        }

        return (int) max(0, min(100, round((float) $part / (float) $total * 100)));
    }

    public static function uptime(mixed $seconds): string
    {
        if (! is_numeric($seconds)) {
            return '—';
        }
        $s = (int) $seconds;
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);

        return $d > 0 ? "{$d} T {$h} Std" : ($h > 0 ? "{$h} Std {$m} Min" : "{$m} Min");
    }
}
