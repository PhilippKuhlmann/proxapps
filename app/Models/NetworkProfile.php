<?php

namespace App\Models;

use Database\Factories\NetworkProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable network settings (bridge, VLAN, subnet, IP pool, VMID pattern).
 *
 * @property int $id
 * @property string $name
 * @property string $bridge
 * @property int|null $vlan
 * @property string $cidr
 * @property string|null $gateway
 * @property string|null $dns
 * @property string|null $searchdomain
 * @property string|null $pool_start
 * @property string|null $pool_end
 * @property string|null $vmid_pattern
 * @property bool $firewall
 * @property bool $is_default
 */
#[Fillable(['name', 'bridge', 'vlan', 'cidr', 'gateway', 'dns', 'searchdomain', 'pool_start', 'pool_end',
    'vmid_pattern', 'firewall', 'is_default'])]
class NetworkProfile extends Model
{
    /** @use HasFactory<NetworkProfileFactory> */
    use HasFactory;

    protected $attributes = [
        'bridge' => 'vmbr0',
        'firewall' => false,
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vlan' => 'integer',
            'firewall' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Profiles in display order: default first, then by name.
     *
     * @return Collection<int, NetworkProfile>
     */
    public static function ordered()
    {
        return static::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function prefix(): int
    {
        $parts = explode('/', (string) $this->cidr, 2);

        return isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : 32;
    }
}
