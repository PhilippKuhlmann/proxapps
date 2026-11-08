<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vmid = fake()->unique()->numberBetween(9000, 9999);

        return [
            'app_id' => 'adguard-home',
            'app_name' => 'AdGuard Home',
            'connection_id' => Connection::factory(),
            'node' => 'pve1',
            'vmid' => $vmid,
            'hostname' => 'adguard',
            'ip' => '192.0.2.53',
            'status' => 'queued',
            'step' => 'validate',
            'spec' => [
                'app_id' => 'adguard-home',
                'node' => 'pve1',
                'vmid' => $vmid,
                'hostname' => 'adguard',
                'cores' => 1,
                'memory' => 512,
                'swap' => 512,
                'disk' => 4,
                'rootfs_storage' => 'local-lvm',
                'template_storage' => 'local',
                'template' => '',
                'profile_id' => null,
                'net' => [
                    'mode' => 'static', 'ip' => '192.0.2.53', 'prefix' => 24, 'gateway' => '192.0.2.1',
                    'bridge' => 'vmbr0', 'vlan' => null, 'dns' => '192.0.2.1', 'searchdomain' => '',
                    'firewall' => false,
                ],
                'unprivileged' => true,
                'nesting' => true,
                'onboot' => true,
                'tags' => 'proxapps',
                'ssh_keys' => '',
                'apt_upgrade' => true,
                'destroy_on_failure' => false,
                'timezone' => 'Europe/Berlin',
                'params' => ['ADMIN_USER' => 'admin', 'WEB_PORT' => '80'],
            ],
            'secrets' => ['root_password' => '', 'params' => ['ADMIN_PASS' => 'Secret123456']],
            'result' => null,
            'error' => null,
            'cancel_requested' => false,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => 'success',
            'step' => 'done',
            'finished_at' => now(),
            'result' => ['url' => 'http://192.0.2.53:80/', 'user' => 'admin', 'password_param' => 'ADMIN_PASS',
                'notes' => ''],
        ]);
    }
}
