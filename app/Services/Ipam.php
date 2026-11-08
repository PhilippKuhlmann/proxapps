<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Support\Settings;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * IP address management helpers: VMID patterns, collision checks, suggestions.
 */
class Ipam
{
    private const PATTERN_RE = '~\{(vlan|o1|o2|o3|o4)(?::0?(\d{1,2})d?)?\}~';

    /**
     * Turn a pattern like "{vlan}{o4:03}" into a VMID for the given IP.
     */
    public static function renderVmidPattern(string $pattern, ?string $ip, ?int $vlan): ?int
    {
        if ($pattern === '') {
            return null;
        }
        $octets = null;
        if ($ip !== null && $ip !== '') {
            if (! static::isIpv4($ip)) {
                return null;
            }
            $octets = array_map('intval', explode('.', $ip));
        }

        $failed = false;
        $text = preg_replace_callback(self::PATTERN_RE, function (array $m) use ($octets, $vlan, &$failed) {
            $name = $m[1];
            $width = $m[2] ?? '';
            if ($name === 'vlan') {
                if ($vlan === null) {
                    $failed = true;

                    return '';
                }
                $val = $vlan;
            } else {
                if ($octets === null) {
                    $failed = true;

                    return '';
                }
                $val = $octets[(int) $name[1] - 1];
            }

            return $width !== '' ? str_pad((string) $val, (int) $width, '0', STR_PAD_LEFT) : (string) $val;
        }, $pattern);

        if ($failed || $text === null || ! ctype_digit($text)) {
            return null;
        }
        $vmid = (int) $text;

        return $vmid >= 100 && $vmid <= 999_999_999 && strlen(ltrim($text, '0')) <= 9 ? $vmid : null;
    }

    public static function stripPrefix(string $ip): string
    {
        return trim(explode('/', $ip)[0]);
    }

    public static function isIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function cacheKey(int $connectionId): string
    {
        return "proxapps.used_ips.{$connectionId}";
    }

    /**
     * Map of IPv4 => "VMID name" for every guest config in the cluster plus active deployments.
     *
     * @return array<string, string>
     */
    public function usedIps(ProxmoxClient $c, int $connectionId, bool $force = false): array
    {
        if (! $force) {
            $cached = Cache::get(static::cacheKey($connectionId));
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = [];
        foreach ($c->guests() as $g) {
            if (! empty($g['template'])) {
                continue;
            }
            try {
                if ($g['type'] === 'lxc') {
                    $cfg = $c->lxcConfig($g['node'], (int) $g['vmid']);
                    $re = '~^net\d+$~';
                } else {
                    $cfg = $c->qemuConfig($g['node'], (int) $g['vmid']);
                    $re = '~^ipconfig\d+$~';
                }
            } catch (ProxmoxException) {
                continue;
            }
            foreach ($cfg as $key => $value) {
                if (! preg_match($re, (string) $key)) {
                    continue;
                }
                $ip = ProxmoxClient::parseKv((string) $value)['ip'] ?? '';
                if ($ip !== '' && ! in_array($ip, ['dhcp', 'manual', 'auto'], true)) {
                    $result[static::stripPrefix($ip)] = trim($g['vmid'].' '.($g['name'] ?? ''));
                }
            }
        }

        // Deployments that are still being created are not in Proxmox yet.
        $active = Deployment::query()->active()->where('connection_id', $connectionId)->whereNotNull('ip')
            ->get(['vmid', 'hostname', 'ip']);
        foreach ($active as $d) {
            $result[static::stripPrefix($d->ip)] ??= "{$d->vmid} {$d->hostname} (in Arbeit)";
        }

        Cache::put(static::cacheKey($connectionId), $result, (int) config('proxapps.used_ips_cache_seconds', 20));

        return $result;
    }

    public function invalidate(int $connectionId): void
    {
        Cache::forget(static::cacheKey($connectionId));
    }

    /**
     * True if the address answers an ICMP echo. False if ping is unavailable.
     */
    public static function ping(string $ip): bool
    {
        if (! static::isIpv4($ip)) {
            return false;
        }
        $binary = (new ExecutableFinder)->find('ping');
        if ($binary === null) {
            return false;
        }
        try {
            return Process::timeout(3)->run([$binary, '-c', '1', '-W', '1', $ip])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Usable host addresses of the profile's network, limited to the pool.
     *
     * @return Generator<int, string>
     */
    public static function poolRange(NetworkProfile $p): Generator
    {
        $net = static::network($p->cidr);
        if ($net === null) {
            return;
        }
        [$network, $prefix, $broadcast] = $net;
        if ($prefix >= 31) {
            $first = $network;
            $last = $broadcast;
        } else {
            $first = $network + 1;
            $last = $broadcast - 1;
        }
        if ($p->pool_start && static::isIpv4($p->pool_start)) {
            $first = max($first, ip2long($p->pool_start));
        }
        if ($p->pool_end && static::isIpv4($p->pool_end)) {
            $last = min($last, ip2long($p->pool_end));
        }
        for ($i = $first; $i <= $last; $i++) {
            yield long2ip($i);
        }
    }

    /**
     * Parse "a.b.c.d/nn" (host bits allowed) into [network, prefix, broadcast] as integers.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function network(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr), 2);
        if (! static::isIpv4($parts[0])) {
            return null;
        }
        $prefix = 32;
        if (isset($parts[1])) {
            if (! ctype_digit($parts[1]) || (int) $parts[1] > 32) {
                return null;
            }
            $prefix = (int) $parts[1];
        }
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = ip2long($parts[0]) & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        return [$network, $prefix, $broadcast];
    }

    public static function networkString(string $cidr): string
    {
        $net = static::network($cidr);

        return $net ? long2ip($net[0]).'/'.$net[1] : $cidr;
    }

    public static function inNetwork(string $ip, string $cidr): bool
    {
        $net = static::network($cidr);
        if (! $net || ! static::isIpv4($ip)) {
            return false;
        }
        $long = ip2long($ip);

        return $long >= $net[0] && $long <= $net[2];
    }

    /**
     * @param  array<int, string>  $exclude
     */
    public function suggestIp(ProxmoxClient $c, int $connectionId, NetworkProfile $p, array $exclude = []): ?string
    {
        $used = $this->usedIps($c, $connectionId);
        $blocked = array_fill_keys(array_merge(array_keys($used), $exclude), true);
        if ($p->gateway) {
            $blocked[$p->gateway] = true;
        }
        $takenVmids = array_fill_keys(array_map(fn ($g) => (int) $g['vmid'], $c->guests()), true);
        $pings = 0;
        foreach (static::poolRange($p) as $ip) {
            if (isset($blocked[$ip])) {
                continue;
            }
            $vmid = static::renderVmidPattern((string) static::effectivePattern($p), $ip, $p->vlan);
            if ($vmid !== null && isset($takenVmids[$vmid])) {
                continue;
            }
            if ($pings >= 16) {
                return $ip;
            }
            $pings++;
            if (static::ping($ip)) {
                continue;
            }

            return $ip;
        }

        return null;
    }

    /**
     * The VMID pattern that applies: the profile's own pattern, otherwise the global
     * default from the settings (e.g. "{o3}{o4:03}": 10.10.250.55 → 250055). Null = none.
     */
    public static function effectivePattern(?NetworkProfile $p): ?string
    {
        $pattern = trim((string) ($p?->vmid_pattern ?? ''));
        if ($pattern === '') {
            $pattern = trim((string) Settings::get('vmid_pattern'));
        }

        return $pattern !== '' ? $pattern : null;
    }

    public function suggestVmid(ProxmoxClient $c, ?NetworkProfile $p, ?string $ip, ?int $vlan = null): int
    {
        $pattern = static::effectivePattern($p);
        if ($pattern && $ip) {
            $vmid = static::renderVmidPattern($pattern, $ip, $p?->vlan ?? $vlan);
            if ($vmid !== null) {
                return $vmid;
            }
        }
        $lo = (int) (Settings::get('vmid_min') ?: 100);
        $hi = (int) (Settings::get('vmid_max') ?: 999999);
        $taken = array_fill_keys(array_map(fn ($g) => (int) $g['vmid'], $c->guests()), true);
        foreach (Deployment::query()->active()->pluck('vmid') as $vmid) {
            $taken[(int) $vmid] = true;
        }
        for ($vmid = max($lo, 100); $vmid <= $hi; $vmid++) {
            if (! isset($taken[$vmid])) {
                return $vmid;
            }
        }

        return $c->nextVmid();
    }

    public static function validateIpInProfile(string $ip, NetworkProfile $p): ?string
    {
        if (! static::isIpv4($ip)) {
            return 'Keine gültige IPv4-Adresse.';
        }
        $net = static::network($p->cidr);
        if (! $net) {
            return 'Das Netz des Profils ist ungültig.';
        }
        $netStr = long2ip($net[0]).'/'.$net[1];
        if (! static::inNetwork($ip, $p->cidr)) {
            return "Die Adresse liegt nicht im Netz {$netStr}.";
        }
        $long = ip2long($ip);
        if ($long === $net[0] || $long === $net[2]) {
            return 'Netz- und Broadcast-Adresse sind nicht erlaubt.';
        }
        if ($p->gateway && $ip === $p->gateway) {
            return 'Das ist die Gateway-Adresse.';
        }

        return null;
    }
}
