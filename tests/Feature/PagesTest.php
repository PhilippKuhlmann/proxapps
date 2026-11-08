<?php

namespace Tests\Feature;

use App\Livewire\ConnectionStatus;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Deploy\DeploymentLogger;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class PagesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_dashboard_without_connection_shows_onboarding(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Noch keine Proxmox-Verbindung')
            ->assertSee('Apps im Katalog')
            ->assertSee('AdGuard Home');
    }

    public function test_dashboard_with_connection_and_deployments(): void
    {
        $conn = Connection::factory()->create(['name' => 'Homelab']);
        Deployment::factory()->succeeded()->create(['connection_id' => $conn->id, 'hostname' => 'adguard-01']);

        $this->get('/')->assertOk()
            ->assertSee('Proxmox-Verbindungen')
            ->assertSee('Tipp: Netzwerkprofil anlegen')
            ->assertSee('adguard-01')
            ->assertSee('Erfolgreich');
    }

    public function test_connection_status_line_shows_version_and_nodes(): void
    {
        $conn = Connection::factory()->create(['name' => 'Homelab']);

        Livewire::withoutLazyLoading()->test(ConnectionStatus::class, ['connection' => $conn])
            ->assertSee('Proxmox VE 9.0.3')
            ->assertSee('pve1')
            ->assertSee('2 Gäste');
    }

    public function test_connection_status_line_shows_errors(): void
    {
        $this->refakeProxmox(['GET /version' => fn () => Http::response(['data' => null], 401)]);
        $conn = Connection::factory()->create();

        Livewire::withoutLazyLoading()->test(ConnectionStatus::class, ['connection' => $conn])
            ->assertSee('Anmeldung fehlgeschlagen');
    }

    public function test_catalog_lists_apps(): void
    {
        $this->get('/apps')->assertOk()
            ->assertSee('App-Katalog')
            ->assertSee('AdGuard Home')
            ->assertSee('512 MB RAM')
            ->assertSee(route('apps.icon', 'adguard-home'), false)
            ->assertSee('Ausrollen');
    }

    public function test_app_icon_is_served(): void
    {
        $this->get('/apps/adguard-home/icon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/apps/nope/icon')->assertNotFound();
    }

    public function test_deploy_page_renders_with_proxmox_options(): void
    {
        Connection::factory()->create(['name' => 'Homelab']);
        NetworkProfile::factory()->create(['name' => 'LAN', 'cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.1',
            'pool_start' => '192.0.2.50', 'pool_end' => '192.0.2.60', 'is_default' => true]);

        $this->get('/apps/adguard-home')->assertOk()
            ->assertSee('AdGuard Home ausrollen')
            ->assertSee('Jetzt ausrollen')
            ->assertSee('Homelab')
            ->assertSee('pve1')
            ->assertSee('local-lvm (lvmthin)')
            ->assertSee('Empfohlen: 512 MB')
            ->assertSee('Admin-Passwort')
            ->assertSee('leer = generieren')
            ->assertSee('Nächste freie IP');
    }

    public function test_unknown_app_is_404(): void
    {
        $this->get('/apps/does-not-exist')->assertNotFound();
    }

    public function test_connections_pages(): void
    {
        Connection::factory()->create(['name' => 'Homelab', 'url' => 'https://pve.test:8006']);

        $this->get('/connections')->assertOk()->assertSee('Homelab')->assertSee('https://pve.test:8006');
        $this->get('/connections/new')->assertOk()
            ->assertSee('Neue Proxmox-Verbindung')
            ->assertSee('Fingerabdruck abrufen')
            ->assertSee('pveum role add ProxApps', false);
    }

    /**
     * Blade directives are not compiled inside component tag attributes (<x-… x-on:click="@js(…)">);
     * they would reach the browser verbatim and break the Alpine expression (confirm dialogs).
     */
    public function test_no_uncompiled_blade_directives_reach_the_browser(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        Connection::factory()->create(['name' => 'Homelab']);
        NetworkProfile::factory()->create(['name' => 'Server-VLAN']);
        $d = Deployment::factory()->create(['status' => 'success', 'result' => ['container_created' => true]]);

        foreach (['/connections', '/settings', "/deployments/{$d->id}"] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('@js(', $html, "raw @js() on {$url}");
        }
    }

    public function test_settings_page(): void
    {
        NetworkProfile::factory()->create(['name' => 'Server-VLAN', 'vmid_pattern' => '{vlan}{o4:03}']);

        $this->get('/settings')->assertOk()
            ->assertSee('Allgemein')
            ->assertSee('Deployer-Schlüssel')
            ->assertSee('ssh-ed25519')
            ->assertSee('Server-VLAN')
            ->assertSee('{vlan}{o4:03}');
        $this->get('/settings/profiles/new')->assertOk()->assertSee('Neues Netzwerkprofil');
    }

    public function test_deployments_index_empty_and_filled(): void
    {
        $this->get('/deployments')->assertOk()->assertSee('Noch nichts ausgerollt.');

        Deployment::factory()->create(['hostname' => 'adguard-07', 'status' => 'failed', 'vmid' => 9123]);
        $this->get('/deployments')->assertOk()->assertSee('adguard-07')->assertSee('9123')->assertSee('Fehlgeschlagen');
    }

    public function test_deployment_detail_shows_steps_log_and_result(): void
    {
        $d = Deployment::factory()->succeeded()->create(['hostname' => 'adguard-07']);
        DeploymentLogger::log($d, 'Hallo aus dem Protokoll', 'info', 'validate');

        $this->get(route('deployments.show', $d))->assertOk()
            ->assertSee('adguard-07')
            ->assertSee('Debian-Vorlage')
            ->assertSee('Funktionsprüfung')
            ->assertSee('Hallo aus dem Protokoll')
            ->assertSee('ist bereit')
            ->assertSee('http://192.0.2.53:80/')
            ->assertSee('Erneut ausrollen')
            ->assertDontSee('Secret123456');
    }
}
