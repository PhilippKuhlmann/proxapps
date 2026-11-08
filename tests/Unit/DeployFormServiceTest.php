<?php

namespace Tests\Unit;

use App\Jobs\RunDeployment;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\DeployFormService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Unit\Concerns\FakesProxmox;

class DeployFormServiceTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    private Connection $conn;

    private AppDefinition $def;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Connection::factory()->create();
        $this->def = app(Catalog::class)->get('adguard-home');
    }

    /**
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'connection_id' => (string) $this->conn->id, 'node' => 'pve1', 'hostname' => 'AdGuard', 'vmid' => '2053',
            'cores' => '1', 'memory' => '512', 'swap' => '512', 'disk' => '4',
            'rootfs_storage' => 'local-lvm', 'template_storage' => 'local', 'template' => '',
            'profile_id' => '', 'net_mode' => 'static', 'ip' => '192.0.2.53', 'prefix' => '24',
            'gateway' => '192.0.2.1', 'bridge' => 'vmbr0', 'vlan' => '', 'dns' => '192.0.2.1, 2001:db8::1',
            'searchdomain' => 'lan.test', 'firewall' => '1', 'unprivileged' => '1', 'nesting' => 'on',
            'onboot' => true, 'tags' => 'proxapps', 'root_password' => '', 'ssh_keys' => '',
            'apt_upgrade' => '1', 'destroy_on_failure' => '',
            'param_ADMIN_USER' => 'admin', 'param_ADMIN_PASS' => '', 'param_WEB_PORT' => '80',
        ], $overrides);
    }

    private function service(): DeployFormService
    {
        return app(DeployFormService::class);
    }

    public function test_happy_path_builds_spec_and_secrets(): void
    {
        $this->fakeProxmox();
        $res = $this->service()->validate($this->def, $this->input());

        $this->assertSame([], $res['errors']);
        $spec = $res['spec'];
        $this->assertSame('adguard', $spec['hostname']);
        $this->assertSame(2053, $spec['vmid']); // default pattern {o3}{o4:03}: 192.0.2.53 → 2053
        $this->assertSame(1, $spec['cores']);
        $this->assertSame([
            'mode' => 'static', 'ip' => '192.0.2.53', 'prefix' => 24, 'gateway' => '192.0.2.1', 'bridge' => 'vmbr0',
            'vlan' => null, 'dns' => '192.0.2.1 2001:db8::1', 'searchdomain' => 'lan.test', 'firewall' => true,
        ], $spec['net']);
        $this->assertTrue($spec['nesting']);
        $this->assertTrue($spec['onboot']);
        $this->assertFalse($spec['destroy_on_failure']);
        $this->assertSame('Europe/Berlin', $spec['timezone']);
        $this->assertNull($spec['profile_id']);
        $this->assertSame(['ADMIN_USER' => 'admin', 'WEB_PORT' => '80'], $spec['params']);

        // generated password: secret, never echoed back
        $pw = $res['secrets']['params']['ADMIN_PASS'];
        $this->assertSame(20, strlen($pw));
        $this->assertMatchesRegularExpression('~^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z0-9]{20}$~', $pw);
        $this->assertSame('', $res['values']['params']['ADMIN_PASS']);
        $this->assertSame('', $res['values']['root_password']);
        $this->assertSame('', $res['secrets']['root_password']);
    }

    public function test_nested_params_and_dhcp(): void
    {
        $this->fakeProxmox();
        $input = $this->input(['net_mode' => 'dhcp', 'ip' => 'garbage', 'prefix' => '', 'gateway' => 'x',
            'root_password' => 'Root12345']);
        unset($input['param_ADMIN_USER'], $input['param_ADMIN_PASS'], $input['param_WEB_PORT']);
        $input['params'] = ['ADMIN_USER' => 'chef', 'ADMIN_PASS' => 'LongEnough1', 'WEB_PORT' => 8080];

        $res = $this->service()->validate($this->def, $input);

        $this->assertSame([], $res['errors']);
        $this->assertNull($res['spec']['net']['ip']);
        $this->assertNull($res['spec']['net']['gateway']);
        $this->assertSame(24, $res['spec']['net']['prefix']);
        $this->assertSame(['ADMIN_USER' => 'chef', 'WEB_PORT' => '8080'], $res['spec']['params']);
        $this->assertSame(['root_password' => 'Root12345', 'params' => ['ADMIN_PASS' => 'LongEnough1']], $res['secrets']);
    }

    public function test_local_field_errors(): void
    {
        $this->fakeProxmox();
        $res = $this->service()->validate($this->def, $this->input([
            'hostname' => '-bad', 'vmid' => '42', 'cores' => '0', 'memory' => 'x', 'disk' => '70000',
            'root_password' => 'abc', 'ssh_keys' => "ssh-ed25519 AAAA ok\nnot a key", 'bridge' => 'vmbr 0',
            'vlan' => '5000', 'gateway' => '198.51.100.1', 'dns' => '192.0.2.1 nope', 'searchdomain' => 'bad_domain',
            'param_ADMIN_USER' => 'bad user', 'param_ADMIN_PASS' => 'short', 'param_WEB_PORT' => '70000',
        ]));
        $e = $res['errors'];

        $this->assertSame('Gültiger Hostname: Buchstaben, Ziffern und Bindestriche.', $e['hostname']);
        $this->assertSame('VMID zwischen 100 und 999999999.', $e['vmid']);
        $this->assertSame('Wert zwischen 1 und 512.', $e['cores']);
        $this->assertSame('Wert zwischen 64 und 4194304.', $e['memory']);
        $this->assertSame('Wert zwischen 1 und 65536.', $e['disk']);
        $this->assertSame('Mindestens 5 Zeichen (oder leer lassen: nur SSH-Schlüssel).', $e['root_password']);
        $this->assertSame('Mindestens eine Zeile ist kein gültiger öffentlicher SSH-Schlüssel.', $e['ssh_keys']);
        $this->assertSame('Bitte eine Bridge wählen.', $e['bridge']);
        $this->assertSame('VLAN-ID 1–4094 oder leer.', $e['vlan']);
        $this->assertSame('Das Gateway liegt nicht im Netz 192.0.2.0/24.', $e['gateway']);
        $this->assertSame('DNS-Server als IP-Adressen, durch Leerzeichen getrennt.', $e['dns']);
        $this->assertSame('Keine gültige Domain.', $e['searchdomain']);
        $this->assertSame('Ungültiges Format.', $e['param_ADMIN_USER']);
        $this->assertSame('Mindestens 8 Zeichen.', $e['param_ADMIN_PASS']);
        $this->assertSame('Zahl zwischen 1 und 65535.', $e['param_WEB_PORT']);
        $this->assertArrayNotHasKey('ip', $e);
        // the entered secret is not echoed back
        $this->assertSame('', $res['values']['params']['ADMIN_PASS']);
    }

    public function test_ip_errors_and_required_params(): void
    {
        $this->fakeProxmox();
        $e = $this->service()->validate($this->def, $this->input(['ip' => '192.0.2.1', 'param_ADMIN_USER' => '']))['errors'];
        $this->assertSame('IP und Gateway sind identisch.', $e['ip']);
        $this->assertSame('Pflichtfeld.', $e['param_ADMIN_USER']);

        $e = $this->service()->validate($this->def, $this->input(['ip' => '192.0.2.300', 'prefix' => '33']))['errors'];
        $this->assertSame('Bitte eine gültige IPv4-Adresse angeben.', $e['ip']);
        $this->assertSame('Präfix 1–32.', $e['prefix']);
        $this->assertArrayNotHasKey('gateway', $e);
    }

    public function test_proxmox_side_checks(): void
    {
        $this->fakeProxmox();
        Deployment::factory()->create(['connection_id' => $this->conn->id, 'vmid' => 250054, 'status' => 'running']);

        $e = $this->service()->validate($this->def, $this->input([
            'rootfs_storage' => 'local', 'template_storage' => 'local-lvm', 'bridge' => 'vmbr9',
            'vmid' => '101', 'ip' => '192.0.2.20',
        ]))['errors'];
        $this->assertSame('Speicher unterstützt keine Container-Disks.', $e['rootfs_storage']);
        $this->assertSame('Speicher unterstützt keine Vorlagen.', $e['template_storage']);
        $this->assertSame('Bridge vmbr9 gibt es auf pve1 nicht.', $e['bridge']);
        $this->assertSame('Die VMID 101 ist bereits vergeben.', $e['vmid']);
        $this->assertSame('Bereits vergeben an 101 dns.', $e['ip']);

        $e = $this->service()->validate($this->def, $this->input(['vmid' => '250054', 'node' => 'pve2']))['errors'];
        $this->assertSame('Die VMID 250054 ist bereits vergeben.', $e['vmid']);
        $this->assertSame('Der Node ist nicht online.', $e['node']);

        $e = $this->service()->validate($this->def, $this->input(['node' => 'nope']))['errors'];
        $this->assertSame('Bitte einen Node wählen.', $e['node']);

        $e = $this->service()->validate($this->def, $this->input(['connection_id' => '999']))['errors'];
        $this->assertSame('Bitte eine Proxmox-Verbindung wählen.', $e['connection_id']);
    }

    public function test_vmid_must_match_the_ip_pattern(): void
    {
        $this->fakeProxmox();

        // global default {o3}{o4:03}
        $e = $this->service()->validate($this->def, $this->input(['vmid' => '250053']))['errors'];
        $this->assertSame(['vmid' => 'Die VMID muss 2053 sein (passend zur IP 192.0.2.53).'], $e);

        // the user's examples
        $this->assertNull(DeployFormService::vmidPatternError(null, '10.10.250.55', 250055, null));
        $this->assertNull(DeployFormService::vmidPatternError(null, '10.10.240.40', 240040, null));
        $this->assertSame('Die VMID muss 240040 sein (passend zur IP 10.10.240.40).',
            DeployFormService::vmidPatternError(null, '10.10.240.40', 250055, null));

        // the profile's own pattern wins; one that yields nothing is an error
        $profile = NetworkProfile::factory()->create(['vmid_pattern' => '{vlan}{o4:03}', 'vlan' => 7]);
        $e = $this->service()->validate($this->def, $this->input(['profile_id' => (string) $profile->id, 'vmid' => '2053']))['errors'];
        $this->assertSame('Die VMID muss 7053 sein (passend zur IP 192.0.2.53).', $e['vmid']);
        $this->assertSame([], $this->service()->validate($this->def,
            $this->input(['profile_id' => (string) $profile->id, 'vmid' => '7053']))['errors']);

        $noVlan = NetworkProfile::factory()->create(['vmid_pattern' => '{vlan}{o4:03}', 'vlan' => null]);
        $e = $this->service()->validate($this->def, $this->input(['profile_id' => (string) $noVlan->id]))['errors'];
        $this->assertSame('Aus der IP 192.0.2.53 ergibt das Muster {vlan}{o4:03} keine gültige VMID.', $e['vmid']);

        $tooSmall = DeployFormService::vmidPatternError(null, '10.10.0.55', 55, null);
        $this->assertSame('Aus der IP 10.10.0.55 ergibt das Muster {o3}{o4:03} keine gültige VMID.', $tooSmall);

        // no pattern at all → any free VMID, and DHCP is never checked
        Settings::put('vmid_pattern', '');
        $this->assertSame([], $this->service()->validate($this->def, $this->input(['vmid' => '250053']))['errors']);
        Settings::put('vmid_pattern', '{o3}{o4:03}');
        $this->assertSame([], $this->service()->validate($this->def,
            $this->input(['vmid' => '250053', 'net_mode' => 'dhcp']))['errors']);
    }

    public function test_ip_is_checked_against_the_selected_profile(): void
    {
        $this->fakeProxmox();
        $profile = NetworkProfile::factory()->create(['cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.254']);
        $check = fn (string $ip, string $vmid) => $this->service()->validate($this->def, $this->input([
            'profile_id' => (string) $profile->id, 'ip' => $ip, 'vmid' => $vmid, 'gateway' => '']))['errors'];

        $this->assertSame(['ip' => 'Netz- und Broadcast-Adresse sind nicht erlaubt.'], $check('192.0.2.255', '2255'));
        $this->assertSame('Netz- und Broadcast-Adresse sind nicht erlaubt.', $check('192.0.2.0', '2000')['ip'] ?? null);
        $this->assertSame(['ip' => 'Das ist die Gateway-Adresse.'], $check('192.0.2.254', '2254'));
        $this->assertSame(['ip' => 'Die Adresse liegt nicht im Netz 192.0.2.0/24.'], $check('198.51.100.5', '100005'));
        $this->assertSame([], $check('192.0.2.53', '2053'));
    }

    public function test_proxmox_errors_become_form_error(): void
    {
        $this->fakeProxmox(['GET /nodes' => fn () => Http::response('', 401)]);

        $e = $this->service()->validate($this->def, $this->input())['errors'];
        $this->assertSame(['_form' => 'Anmeldung fehlgeschlagen: Token-ID oder Secret ist falsch.'], $e);
    }

    public function test_defaults_and_clone(): void
    {
        Settings::put('authorized_keys', 'ssh-ed25519 AAAA me');
        $profile = NetworkProfile::factory()->create(['name' => 'LAN', 'is_default' => true, 'vlan' => 250,
            'cidr' => '192.0.2.0/25', 'dns' => '192.0.2.1']);

        $d = $this->service()->defaults($this->def);
        $this->assertSame($this->conn->id, $d['connection_id']);
        $this->assertSame($profile->id, $d['profile_id']);
        $this->assertSame('static', $d['net_mode']);
        $this->assertSame(25, $d['prefix']);
        $this->assertSame(250, $d['vlan']);
        $this->assertSame('adguard', $d['hostname']);
        $this->assertSame(512, $d['memory']);
        $this->assertSame('proxapps', $d['tags']);
        $this->assertSame('ssh-ed25519 AAAA me', $d['ssh_keys']);
        $this->assertTrue($d['apt_upgrade']);
        $this->assertSame(['ADMIN_USER' => 'admin', 'ADMIN_PASS' => '', 'WEB_PORT' => 80], $d['params']);

        $old = Deployment::factory()->create(['connection_id' => $this->conn->id]);
        $c = $this->service()->defaults($this->def, $old);
        $this->assertSame($old->vmid, $c['vmid']);
        $this->assertSame('local-lvm', $c['rootfs_storage']);
        $this->assertSame('192.0.2.53', $c['ip']);
        $this->assertSame('', $c['vlan']);
        $this->assertSame('', $c['profile_id']);
        $this->assertSame(['ADMIN_USER' => 'admin', 'ADMIN_PASS' => '', 'WEB_PORT' => '80'], $c['params']);
    }

    public function test_defaults_without_profile_use_dhcp(): void
    {
        $d = $this->service()->defaults($this->def);
        $this->assertSame('dhcp', $d['net_mode']);
        $this->assertSame(24, $d['prefix']);
        $this->assertSame('vmbr0', $d['bridge']);
        $this->assertSame('', $d['profile_id']);
    }

    public function test_create_inserts_logs_and_dispatches(): void
    {
        Queue::fake();
        $this->fakeProxmox();
        $res = $this->service()->validate($this->def, $this->input());

        $d = $this->service()->create($this->def, $res['values'], $res['spec'], $res['secrets']);

        $this->assertSame('queued', $d->status);
        $this->assertSame('192.0.2.53', $d->fresh()->ip);
        $this->assertSame($res['secrets'], $d->fresh()->secrets);
        $this->assertSame('Deployment von AdGuard Home als CT 2053 auf pve1 eingeplant.', $d->logs()->first()->message);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deploymentId === $d->id);
    }
}
