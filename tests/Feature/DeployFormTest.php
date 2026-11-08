<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Apps\Deploy;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class DeployFormTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_submit_without_connection_shows_field_errors(): void
    {
        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.hostname', 'not_valid!')
            ->set('v.cores', '0')
            ->call('submit')
            ->assertHasErrors(['connection_id', 'hostname', 'cores', 'vmid'])
            ->assertSee('Bitte die rot markierten Felder prüfen.')
            ->assertSee('Bitte eine Proxmox-Verbindung wählen.')
            ->assertSee('Gültiger Hostname: Buchstaben, Ziffern und Bindestriche.')
            ->assertSee('Wert zwischen 1 und 512.');

        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_mount_loads_nodes_options_and_suggestions(): void
    {
        Connection::factory()->create();
        NetworkProfile::factory()->create(['cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.1', 'bridge' => 'vmbr0',
            'pool_start' => '192.0.2.20', 'pool_end' => '192.0.2.30', 'is_default' => true]);

        $c = Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->assertSet('v.node', 'pve1')              // first online node
            ->assertSet('v.rootfs_storage', 'local-lvm')
            ->assertSet('v.template_storage', 'local')
            ->assertSet('v.bridge', 'vmbr0')
            ->assertSet('apiError', null);

        // .20 and .21 are used by guests in the fake cluster → next free is .22
        $c->assertSet('v.ip', '192.0.2.22')
            ->assertSet('checks.ip.ok', true)
            ->assertSet('checks.vmid.ok', true);
    }

    public function test_live_checks_flag_used_vmid_and_ip(): void
    {
        Connection::factory()->create();

        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.net_mode', 'static')
            ->set('v.vmid', '101')
            ->assertSet('checks.vmid.ok', false)
            ->assertSet('checks.vmid.msg', 'bereits vergeben')
            ->set('v.ip', '192.0.2.20')
            ->assertSet('checks.ip.ok', false)
            ->assertSee('vergeben an 101 dns')
            ->set('v.ip', 'kaputt')
            ->assertSet('checks.ip.msg', 'keine gültige IPv4-Adresse');
    }

    public function test_changed_resources_are_highlighted_and_resettable(): void
    {
        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->assertDontSee('Auf Empfehlung zurücksetzen')
            ->set('v.memory', '1024')
            ->assertSee('Auf Empfehlung zurücksetzen')
            ->call('resetResources')
            ->assertSet('v.memory', 512)
            ->assertDontSee('Auf Empfehlung zurücksetzen');
    }

    public function test_server_side_proxmox_errors_are_shown_per_field(): void
    {
        Connection::factory()->create();

        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.net_mode', 'static')
            ->set('v.ip', '192.0.2.20')
            ->set('v.vmid', '101')
            ->set('v.prefix', '24')
            ->call('submit')
            ->assertHasErrors(['vmid', 'ip'])
            ->assertSee('Die VMID 101 ist bereits vergeben.')
            ->assertSee('Bereits vergeben an 101 dns.');
    }

    public function test_valid_submit_creates_deployment_and_redirects(): void
    {
        Queue::fake();
        Connection::factory()->create();

        $c = Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.net_mode', 'static')
            ->set('v.ip', '192.0.2.40')
            ->set('v.prefix', '24')
            ->set('v.gateway', '192.0.2.1')
            ->assertSet('v.vmid', '2040')   // default pattern {o3}{o4:03}
            ->call('submit')
            ->assertHasNoErrors();

        $d = Deployment::query()->firstOrFail();
        $c->assertRedirect(route('deployments.show', $d));
        $this->assertSame(2040, $d->vmid);
        $this->assertSame('192.0.2.40', $d->ip);
        $this->assertNotEmpty($d->secrets['params']['ADMIN_PASS']); // generated
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_vmid_follows_the_ip_without_proxmox(): void
    {
        // No connection at all: the VMID is still derived locally from the pattern.
        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.net_mode', 'static')
            ->set('v.ip', '10.10.250.55')
            ->assertSet('v.vmid', '250055')
            ->assertSee('Abgeleitet aus der IP')
            ->assertSee('{o3}{o4:03}')
            ->set('v.ip', '10.10.240.40')
            ->assertSet('v.vmid', '240040');
    }

    public function test_vmid_follows_the_ip_when_proxmox_is_unreachable(): void
    {
        Connection::factory()->create();
        $c = Livewire::test(Deploy::class, ['app' => 'adguard-home'])->set('v.net_mode', 'static');

        // From now on every API call fails.
        Http::swap(new HttpFactory(app('events')));
        Http::fake(fn () => throw new ConnectionException('unreachable'));

        $c->set('v.ip', '10.10.250.55')
            ->assertSet('v.vmid', '250055')
            ->set('v.ip', '10.10.240.40')
            ->assertSet('v.vmid', '240040');
    }

    public function test_no_pattern_hint_for_dhcp(): void
    {
        Livewire::test(Deploy::class, ['app' => 'adguard-home'])
            ->set('v.net_mode', 'dhcp')
            ->assertDontSee('Abgeleitet aus der IP');
    }

    public function test_clone_from_previous_deployment(): void
    {
        $old = Deployment::factory()->succeeded()->create(['hostname' => 'adguard-alt']);
        $old->update(['spec' => array_merge($old->spec, ['hostname' => 'adguard-alt', 'memory' => 1024])]);

        $this->get(route('apps.deploy', 'adguard-home').'?from='.$old->id)->assertOk()
            ->assertSee('Werte übernommen aus')
            ->assertSee('adguard-alt');
    }
}
