<?php

namespace Database\Factories;

use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test connections point at a non-routable *.test host so nothing real is ever contacted.
 *
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'PVE '.fake()->unique()->word(),
            'url' => 'https://pve.test:8006',
            'token_id' => 'proxapps@pve!deploy',
            'token_secret' => '00000000-1111-2222-3333-444444444444',
            'tls_mode' => 'pin',
            'fingerprint' => implode(':', array_fill(0, 32, 'AB')),
            'pin' => 'sha256//'.base64_encode(str_repeat("\x01", 32)),
        ];
    }
}
