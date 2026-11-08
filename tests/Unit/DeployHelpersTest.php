<?php

namespace Tests\Unit;

use App\Services\Catalog\Catalog;
use App\Services\Deploy\CreateParams;
use App\Services\Deploy\Templates;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Services\Ssh\SshRunner;
use Mockery;
use Tests\TestCase;

class DeployHelpersTest extends TestCase
{
    private const DEPLOYER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDeployerKeyForTests proxapps-deployer';

    /**
     * @return array<string, mixed>
     */
    private function spec(array $net = [], array $extra = []): array
    {
        return array_merge([
            'app_id' => 'adguard-home', 'node' => 'pve1', 'vmid' => 250053, 'hostname' => 'adguard',
            'cores' => 2, 'memory' => 1024, 'swap' => 512, 'disk' => 8, 'rootfs_storage' => 'local-lvm',
            'template_storage' => 'local', 'template' => '', 'profile_id' => null,
            'net' => array_merge(['mode' => 'static', 'ip' => '192.0.2.53', 'prefix' => 24, 'gateway' => '192.0.2.1',
                'bridge' => 'vmbr0', 'vlan' => 250, 'dns' => '192.0.2.1 192.0.2.2', 'searchdomain' => 'lan.test',
                'firewall' => true], $net),
            'unprivileged' => true, 'nesting' => true, 'onboot' => true,
            'tags' => 'DNS, home;proxapps bad!tag', 'ssh_keys' => "# comment\nssh-ed25519 AAAAUser user@host\n\n",
            'apt_upgrade' => true, 'destroy_on_failure' => false, 'timezone' => 'Europe/Berlin', 'params' => [],
        ], $extra);
    }

    public function test_mask_hides_secrets_with_at_least_four_chars(): void
    {
        $this->assertSame('pw=•••••• short=abc', SshRunner::mask('pw=Geheim123 short=abc', ['Geheim123', 'abc', '']));
        $this->assertSame('nothing', SshRunner::mask('nothing', []));
    }

    public function test_shell_quote(): void
    {
        $this->assertSame('plain-value_1.2', SshRunner::shellQuote('plain-value_1.2'));
        $this->assertSame("''", SshRunner::shellQuote(''));
        $this->assertSame("'it'\"'\"'s \$HOME'", SshRunner::shellQuote("it's \$HOME"));
    }

    public function test_create_params_static_with_vlan_and_firewall(): void
    {
        $app = app(Catalog::class)->get('adguard-home');
        $p = CreateParams::build($this->spec(), ['root_password' => '', 'params' => []],
            'local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', $app, self::DEPLOYER_KEY);

        $this->assertSame('name=eth0,bridge=vmbr0,type=veth,tag=250,ip=192.0.2.53/24,gw=192.0.2.1,firewall=1', $p['net0']);
        $this->assertSame('local-lvm:8', $p['rootfs']);
        $this->assertSame('local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', $p['ostemplate']);
        $this->assertSame(250053, $p['vmid']);
        $this->assertSame('192.0.2.1 192.0.2.2', $p['nameserver']);
        $this->assertSame('lan.test', $p['searchdomain']);
        $this->assertSame('dns;home;proxapps;adblock', $p['tags']);
        $this->assertSame(self::DEPLOYER_KEY."\nssh-ed25519 AAAAUser user@host", $p['ssh-public-keys']);
        $this->assertSame('nesting=1', $p['features']);
        $this->assertTrue($p['unprivileged']);
        $this->assertFalse($p['start']);
        $this->assertArrayNotHasKey('password', $p);
        $this->assertStringContainsString('**AdGuard Home**', $p['description']);
    }

    public function test_create_params_add_deployment_ownership_markers(): void
    {
        $app = app(Catalog::class)->get('adguard-home');
        $p = CreateParams::build($this->spec(), ['root_password' => '', 'params' => []], 'local:vztmpl/x', $app,
            self::DEPLOYER_KEY, 42);

        $this->assertSame('dns;home;proxapps;proxapps-d42;adblock', $p['tags']);
        $this->assertStringContainsString('Ausgerollt mit ProxApps (Deployment #42) am ', $p['description']);
    }

    public function test_create_params_sets_pool_only_when_configured(): void
    {
        $app = app(Catalog::class)->get('adguard-home');
        $sec = ['root_password' => '', 'params' => []];

        $without = CreateParams::build($this->spec(), $sec, 'local:vztmpl/x', $app, self::DEPLOYER_KEY);
        $with = CreateParams::build($this->spec([], ['pool' => 'proxapps']), $sec, 'local:vztmpl/x', $app, self::DEPLOYER_KEY);

        $this->assertArrayNotHasKey('pool', $without);
        $this->assertSame('proxapps', $with['pool']);
    }

    public function test_create_params_dhcp_password_and_no_nesting(): void
    {
        $app = app(Catalog::class)->get('adguard-home');
        $spec = $this->spec(['mode' => 'dhcp', 'ip' => null, 'gateway' => null, 'vlan' => null, 'firewall' => false,
            'dns' => '', 'searchdomain' => ''], ['nesting' => false, 'tags' => '', 'ssh_keys' => '']);
        $p = CreateParams::build($spec, ['root_password' => 'Root1234', 'params' => []], 'local:vztmpl/x', $app, self::DEPLOYER_KEY);

        $this->assertSame('name=eth0,bridge=vmbr0,type=veth,ip=dhcp', $p['net0']);
        $this->assertNull($p['nameserver']);
        $this->assertNull($p['searchdomain']);
        $this->assertSame('proxapps;dns;adblock', $p['tags']);
        $this->assertSame(self::DEPLOYER_KEY, $p['ssh-public-keys']);
        $this->assertArrayNotHasKey('features', $p);
        $this->assertSame('Root1234', $p['password']);
        // empty values are dropped from the form body
        $this->assertArrayNotHasKey('nameserver', ProxmoxClient::cleanForm($p));
    }

    public function test_version_key_and_sorting(): void
    {
        $this->assertSame([13, 1, 2], Templates::versionKey('debian-13-standard_13.1-2_amd64.tar.zst'));
        $this->assertSame([], Templates::versionKey('weird.tar.zst'));
    }

    public function test_templates_list_newest_first_and_filters(): void
    {
        $c = Mockery::mock(ProxmoxClient::class);
        $c->shouldReceive('templatesOnStorage')->with('pve1', 'local')->andReturn([
            ['volid' => 'local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', 'size' => 1],
            ['volid' => 'local:vztmpl/debian-13-standard_13.10-1_amd64.tar.zst', 'size' => 2],
            ['volid' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst', 'size' => 3],
            ['volid' => 'local:vztmpl/debian-13-standard_13.2-1_arm64.tar.zst', 'size' => 4],
        ]);
        $c->shouldReceive('aplinfo')->with('pve1')->andReturn([
            ['package' => 'debian-13-standard', 'section' => 'system', 'template' => 'debian-13-standard_13.1-2_amd64.tar.zst', 'version' => '13.1-2'],
            ['package' => 'debian-13-standard', 'section' => 'system', 'template' => 'debian-13-standard_13.11-1_amd64.tar.zst', 'version' => '13.11-1'],
            ['package' => 'debian-13-standard', 'section' => 'system', 'template' => 'debian-13-standard_13.3-1_amd64.tar.zst', 'version' => '13.3-1'],
            ['package' => 'debian-13-standard', 'section' => 'turnkeylinux', 'template' => 'debian-13-standard_99_amd64.tar.zst'],
            ['package' => 'debian-13-standard', 'section' => 'system', 'architecture' => 'arm64', 'template' => 'debian-13-standard_13.12-1_arm64.tar.zst'],
        ]);

        $list = Templates::list($c, 'pve1', 'local', 'debian-13-standard');

        $this->assertSame([
            'debian-13-standard_13.10-1_amd64.tar.zst',
            'debian-13-standard_13.1-2_amd64.tar.zst',
        ], array_column($list['local'], 'name'));
        $this->assertSame('local:vztmpl/debian-13-standard_13.10-1_amd64.tar.zst', $list['local'][0]['volid']);
        $this->assertSame([
            'debian-13-standard_13.11-1_amd64.tar.zst',
            'debian-13-standard_13.3-1_amd64.tar.zst',
        ], array_column($list['downloadable'], 'name'));
    }

    public function test_templates_list_tolerates_api_errors(): void
    {
        $c = Mockery::mock(ProxmoxClient::class);
        $c->shouldReceive('templatesOnStorage')->andThrow(new ProxmoxException('nope', 403));
        $c->shouldReceive('aplinfo')->andThrow(new ProxmoxException('nope', 403));

        $this->assertSame(['local' => [], 'downloadable' => []], Templates::list($c, 'pve1', 'local', 'debian-13-standard'));
    }
}
