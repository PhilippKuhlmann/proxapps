<?php

namespace App\Http\Support;

/**
 * Small IPv4 / input-format helpers for the UI-side validation (port of the
 * ipaddress / regex checks in the Python reference main.py).
 */
class Net
{
    public const HOSTNAME_RE = '/^(?=.{1,253}$)[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

    public const TOKEN_RE = '/^[^@!\s]+@[A-Za-z0-9._-]+![A-Za-z][A-Za-z0-9._-]*$/';

    public const SSH_KEY_RE = '/^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp\d+|sk-[a-z0-9@.-]+) [A-Za-z0-9+\/=]+( .*)?$/';

    public const BRIDGE_RE = '/^[A-Za-z0-9_.-]{1,32}$/';

    public static function isIpv4(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function isIp(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Parse "a.b.c.d/nn" (host bits allowed, like ipaddress.IPv4Network(strict=False)).
     *
     * @return array{0: string, 1: int}|null [network address, prefix]
     */
    public static function parseCidr(?string $cidr): ?array
    {
        if ($cidr === null || ! preg_match('#^([0-9.]+)(?:/(\d{1,2}))?$#', trim($cidr), $m)) {
            return null;
        }
        if (! self::isIpv4($m[1])) {
            return null;
        }
        $prefix = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 32;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;

        return [long2ip(ip2long($m[1]) & $mask), $prefix];
    }

    public static function inNetwork(string $ip, string $network, int $prefix): bool
    {
        if (! self::isIpv4($ip)) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }

    /** First usable host of a network (used as sample address). */
    public static function firstHost(string $network, int $prefix): string
    {
        $base = ip2long($network);

        return long2ip($prefix >= 31 ? $base : $base + 1);
    }

    /**
     * Split a DNS server list on whitespace / comma / semicolon.
     *
     * @return list<string>|null null when one entry is not an IP address
     */
    public static function dnsList(?string $value): ?array
    {
        $items = array_values(array_filter(preg_split('/[\s,;]+/', (string) $value) ?: [], fn ($d) => $d !== ''));
        foreach ($items as $d) {
            if (! self::isIp($d)) {
                return null;
            }
        }

        return $items;
    }

    /** True when every non-empty, non-comment line is an OpenSSH public key. */
    public static function validSshKeys(?string $text, bool $allowComments = false): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ($allowComments && str_starts_with($line, '#'))) {
                continue;
            }
            if (! preg_match(self::SSH_KEY_RE, $line)) {
                return false;
            }
        }

        return true;
    }
}
