<?php

namespace App\Services\Deploy;

use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;

/**
 * Container templates for a package: already on a storage and downloadable via aplinfo.
 */
class Templates
{
    /**
     * @return array{local: list<array{volid: string, name: string, size: mixed}>, downloadable: list<array{name: string, version: mixed}>}
     */
    public static function list(ProxmoxClient $c, string $node, string $storage, string $package): array
    {
        $local = [];
        try {
            foreach ($c->templatesOnStorage($node, $storage) as $item) {
                $parts = explode('/', (string) $item['volid'], 2);
                $fname = end($parts);
                if (str_starts_with($fname, $package.'_') && str_contains($fname, '_amd64')) {
                    $local[] = ['volid' => (string) $item['volid'], 'name' => $fname, 'size' => $item['size'] ?? null];
                }
            }
        } catch (ProxmoxException) {
            // storage not readable: no local templates
        }
        static::sortNewestFirst($local);

        $available = [];
        try {
            foreach ($c->aplinfo($node) as $t) {
                if (($t['package'] ?? null) === $package && ($t['section'] ?? null) === 'system'
                    && ($t['architecture'] ?? 'amd64') === 'amd64') {
                    $available[] = ['name' => (string) $t['template'], 'version' => $t['version'] ?? null];
                }
            }
        } catch (ProxmoxException) {
            // appliance index unavailable
        }
        static::sortNewestFirst($available);

        $localNames = array_flip(array_column($local, 'name'));
        $downloadable = array_values(array_filter($available, fn ($t) => ! isset($localNames[$t['name']])));

        return ['local' => $local, 'downloadable' => $downloadable];
    }

    /**
     * Version tuple from names like "debian-13-standard_13.1-2_amd64.tar.zst" => [13, 1, 2].
     *
     * @return list<int>
     */
    public static function versionKey(string $name): array
    {
        if (! preg_match('~_(\d[^_]*)_~', $name, $m)) {
            return [];
        }
        preg_match_all('~\d+~', $m[1], $nums);

        return array_map('intval', $nums[0]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function sortNewestFirst(array &$items): void
    {
        usort($items, fn ($a, $b) => static::compareVersions(static::versionKey($b['name']), static::versionKey($a['name'])));
    }

    /**
     * Lexicographic tuple comparison (like Python tuples).
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function compareVersions(array $a, array $b): int
    {
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return count($a) <=> count($b);
    }
}
