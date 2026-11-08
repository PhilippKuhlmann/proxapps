<?php

namespace Database\Factories;

use App\Models\NetworkProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Uses the TEST-NET-1 range (192.0.2.0/24) so tests never touch a real network.
 *
 * @extends Factory<NetworkProfile>
 */
class NetworkProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Netz '.fake()->unique()->word(),
            'bridge' => 'vmbr0',
            'vlan' => null,
            'cidr' => '192.0.2.0/24',
            'gateway' => '192.0.2.1',
            'dns' => '192.0.2.1',
            'searchdomain' => null,
            'pool_start' => '192.0.2.50',
            'pool_end' => '192.0.2.99',
            'vmid_pattern' => null,
            'firewall' => false,
            'is_default' => false,
        ];
    }
}
