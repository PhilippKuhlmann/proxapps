<?php

namespace App\Support;

/**
 * TCP reachability check for apps without an HTTP interface (catalog healthcheck.tcp).
 */
class PortProbe
{
    /**
     * @return array{0: string, 1: int}|null host and port from "host:port" ("[v6]:port" allowed)
     */
    public static function parse(string $target): ?array
    {
        if (! preg_match('~^\[?([A-Za-z0-9.:-]+?)\]?:(\d{1,5})$~D', trim($target), $m)) {
            return null;
        }
        $port = (int) $m[2];

        return $port >= 1 && $port <= 65535 ? [$m[1], $port] : null;
    }

    public static function open(string $host, int $port, float $timeout = 3.0): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen(str_contains($host, ':') ? "[{$host}]" : $host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /** Waits until the port accepts connections; returns false when $timeout seconds passed. */
    public static function wait(string $host, int $port, int $timeout, float $interval = 3.0): bool
    {
        $deadline = microtime(true) + max(1, $timeout);
        do {
            if (static::open($host, $port)) {
                return true;
            }
            if ($interval > 0) {
                usleep((int) ($interval * 1_000_000));
            }
        } while (microtime(true) < $deadline);

        return false;
    }

    /** "version/2026.8.5", "v17.0.2", "Forgejo 17.0.2" → the part starting at the first digit. */
    public static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $v = trim((string) preg_replace('~^[^0-9]*~', '', $version));

        return $v !== '' ? $v : trim($version);
    }
}
