<?php

namespace Tests\Unit;

use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Ipam;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Concerns\FakesProxmox;

class IpamTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    /**
     * @return array<string, array{string, ?string, ?int, ?int}>
     */
    public static function patterns(): array
    {
        return [
            'vlan + padded octet' => ['{vlan}{o4:03}', '10.10.250.89', 250, 250089],
            'third + padded fourth octet' => ['{o3}{o4:03}', '10.10.250.89', 250, 250089],
            'python style width' => ['{o3}{o4:3d}', '10.10.250.5', null, 250005],
            'literal digits' => ['1{o4:02}', '192.0.2.7', null, 107],
            'too small' => ['{o4}', '192.0.2.7', null, null],
            'invalid text' => ['abc', '10.10.250.89', 250, null],
            'unknown placeholder' => ['{o5}{o4}', '10.10.250.89', 250, null],
            'vlan missing' => ['{vlan}{o4:03}', '10.10.250.89', null, null],
            'ip missing' => ['{o3}{o4:03}', null, 250, null],
            'invalid ip' => ['{o3}{o4:03}', '10.10.250', 250, null],
            'empty' => ['', '10.10.250.89', 250, null],
            'vlan only' => ['{vlan}00', null, 30, 3000],
        ];
    }

    #[DataProvider('patterns')]
    public function test_render_vmid_pattern(string $pattern, ?string $ip, ?int $vlan, ?int $expected): void
    {
        $this->assertSame($expected, Ipam::renderVmidPattern($pattern, $ip, $vlan));
    }

    public function test_default_pattern_maps_ip_to_vmid_without_profile(): void
    {
        // The user's convention: third octet + last octet (3 digits).
        $this->assertSame('{o3}{o4:03}', Ipam::effectivePattern(null));
        $this->assertSame(250055, Ipam::renderVmidPattern(Ipam::effectivePattern(null), '10.10.250.55', null));
        $this->assertSame(240040, Ipam::renderVmidPattern(Ipam::effectivePattern(null), '10.10.240.40', null));

        $profile = NetworkProfile::factory()->make(['vmid_pattern' => '9{o4:03}']);
        $this->assertSame('9{o4:03}', Ipam::effectivePattern($profile), 'profile pattern wins');

        Settings::put('vmid_pattern', '');
        $this->assertNull(Ipam::effectivePattern(null), 'empty setting disables the default');
    }

    public function test_validate_ip_in_profile(): void
    {
        $p = new NetworkProfile(['cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.1']);

        $this->assertNull(Ipam::validateIpInProfile('192.0.2.50', $p));
        $this->assertSame('Keine gültige IPv4-Adresse.', Ipam::validateIpInProfile('192.0.2', $p));
        $this->assertSame('Die Adresse liegt nicht im Netz 192.0.2.0/24.', Ipam::validateIpInProfile('198.51.100.1', $p));
        $this->assertSame('Netz- und Broadcast-Adresse sind nicht erlaubt.', Ipam::validateIpInProfile('192.0.2.255', $p));
        $this->assertSame('Netz- und Broadcast-Adresse sind nicht erlaubt.', Ipam::validateIpInProfile('192.0.2.0', $p));
        $this->assertSame('Das ist die Gateway-Adresse.', Ipam::validateIpInProfile('192.0.2.1', $p));
    }

    public function test_pool_range_respects_pool_bounds(): void
    {
        $p = new NetworkProfile(['cidr' => '192.0.2.77/24', 'pool_start' => '192.0.2.250', 'pool_end' => '192.0.2.255']);
        $this->assertSame(['192.0.2.250', '192.0.2.251', '192.0.2.252', '192.0.2.253', '192.0.2.254'],
            iterator_to_array(Ipam::poolRange($p), false));
        $this->assertSame('192.0.2.0/24', Ipam::networkString('192.0.2.77/24'));
    }

    public function test_used_ips_scans_configs_and_active_deployments(): void
    {
        $this->fakeProxmox();
        $conn = Connection::factory()->create();
        Deployment::factory()->create(['connection_id' => $conn->id, 'vmid' => 555, 'hostname' => 'neu',
            'ip' => '192.0.2.30', 'status' => 'running']);
        Deployment::factory()->create(['connection_id' => $conn->id, 'ip' => '192.0.2.31', 'status' => 'failed']);

        $used = app(Ipam::class)->usedIps($conn->client(), $conn->id, true);

        $this->assertSame([
            '192.0.2.20' => '101 dns',
            '192.0.2.21' => '102 vm',
            '192.0.2.30' => '555 neu (in Arbeit)',
        ], $used);
    }

    public function test_suggest_ip_skips_used_gateway_and_pattern_collisions(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->fakeProxmox();
        $conn = Connection::factory()->create();
        $profile = NetworkProfile::factory()->create([
            'cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.19', 'pool_start' => '192.0.2.19',
            'pool_end' => '192.0.2.40', 'vmid_pattern' => '1{o4}',
        ]);
        // .19 gateway, .20 used by CT 101, .21 used by VM 102 -> .22 (VMID 122 is free)
        $this->assertSame('192.0.2.22', app(Ipam::class)->suggestIp($conn->client(), $conn->id, $profile));
    }

    public function test_suggest_vmid_from_pattern_or_range(): void
    {
        $this->fakeProxmox();
        $conn = Connection::factory()->create();
        $ipam = app(Ipam::class);
        $profile = NetworkProfile::factory()->create(['vlan' => 250, 'vmid_pattern' => '{vlan}{o4:03}']);

        $this->assertSame(250089, $ipam->suggestVmid($conn->client(), $profile, '10.10.250.89'));

        Settings::put('vmid_min', 101);
        Deployment::factory()->create(['connection_id' => $conn->id, 'vmid' => 103, 'status' => 'queued']);
        // 101 and 102 exist in Proxmox, 103 is being deployed
        $this->assertSame(104, $ipam->suggestVmid($conn->client(), null, null));
    }
}
