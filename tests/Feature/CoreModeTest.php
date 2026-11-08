<?php

namespace Tests\Feature;

use App\Jobs\SendCoreEvent;
use App\Models\Connection;
use App\Models\Deployment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class CoreModeTest extends FeatureTestCase
{
    private const TOKEN = 'core-token-0123456789-abcdefghijklmnopqrstuvwxyz';

    private function coreMode(): void
    {
        config(['proxapps.core' => ['enabled' => true, 'url' => 'http://core.test', 'token' => self::TOKEN]]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function bridge(string $component, array $body)
    {
        return $this->withToken(self::TOKEN)->postJson("/api/bridge/{$component}", $body);
    }

    public function test_core_routes_do_not_exist_without_core_mode(): void
    {
        $this->getJson('/core/health')->assertNotFound();
        $this->withToken(self::TOKEN)->getJson('/core/manifest')->assertNotFound();
        $this->get('/setup')->assertOk();
    }

    public function test_core_mode_switches_off_the_web_interface_and_needs_the_token(): void
    {
        $this->coreMode();

        $this->get('/')->assertNotFound()->assertSee('über PK95 Core verwaltet');
        $this->get('/login')->assertNotFound();
        $this->getJson('/core/health')->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/core/manifest')->assertUnauthorized();
        $this->withToken('falsch-falsch-falsch-falsch-falsch-falsch')->getJson('/core/manifest')->assertUnauthorized();
        $this->withToken(self::TOKEN)->getJson('/core/manifest')->assertOk()
            ->assertJson(['id' => 'proxapps', 'protocol' => 1, 'version' => '1.0.0'])
            ->assertJsonPath('menu.2.key', 'deployments');
        $this->withToken(self::TOKEN)->postJson('/core/backup/prepare')->assertNoContent();
        $this->withoutToken()->postJson('/api/bridge/connections', ['mount' => []])->assertUnauthorized();
    }

    public function test_bridge_renders_a_page_with_its_data(): void
    {
        $this->coreMode();
        Connection::factory()->create(['name' => 'Homelab', 'url' => 'https://pve.test:8006']);

        $this->bridge('connections', ['mount' => []])->assertOk()
            ->assertJsonPath('view.connections.0.name', 'Homelab')
            ->assertJsonMissingPath('view.connections.0.token_secret');

        $apps = $this->bridge('apps', ['mount' => []])->assertOk()->json('view.apps');
        $this->assertContains('AdGuard Home', array_column($apps, 'name'));
        $this->bridge('nope', ['mount' => []])->assertNotFound();
    }

    public function test_bridge_runs_the_deploy_form_like_livewire(): void
    {
        $this->coreMode();
        $conn = Connection::factory()->create(['name' => 'Homelab']);

        $mounted = $this->bridge('deploy', ['mount' => ['app' => 'adguard-home']])->assertOk();
        $state = $mounted->json('state');
        $this->assertSame('adguard-home', $state['appId']);
        $this->assertSame('AdGuard Home', $mounted->json('view.app.name'));

        // choosing the connection loads nodes, storages and a VMID suggestion
        $state['v']['connection_id'] = (string) $conn->id;
        $changed = $this->bridge('deploy', ['state' => $state, 'updated' => 'v.connection_id'])->assertOk();
        $state = $changed->json('state');
        $this->assertSame('pve1', $state['v']['node']);
        $this->assertNotEmpty($state['nodes']);
        $this->assertNotSame('', $state['v']['vmid']);
        $this->assertIsArray($changed->json('view.templateChoices'));

        // a method call with validation errors comes back as field errors
        $state['v']['hostname'] = 'kein gültiger name!';
        $submitted = $this->bridge('deploy', ['state' => $state, 'call' => ['method' => 'submit']])->assertOk();
        $this->assertArrayHasKey('hostname', $submitted->json('errors'));
        $this->assertNull($submitted->json('redirect'));

        $this->bridge('deploy', ['state' => $state, 'call' => ['method' => 'render']])->assertStatus(400);
    }

    public function test_bridge_passes_models_and_their_data(): void
    {
        $this->coreMode();
        $d = Deployment::factory()->succeeded()->create(['hostname' => 'adguard-07']);

        $page = $this->bridge('deployment', ['mount' => ['deployment' => $d->id]])->assertOk();
        $this->assertSame(['__model' => $d->id], $page->json('state.deployment'));
        $this->assertSame('adguard-07', $page->json('view.deployment.hostname'));
        $this->assertIsBool($page->json('view.deployment.is_active'));
        $this->assertSame('done', $page->json('view.stepStates.validate'));

        $toggled = $this->bridge('deployment', ['state' => $page->json('state'), 'call' => ['method' => 'toggleReveal']])->assertOk();
        $this->assertTrue($toggled->json('state.showSecrets'));
    }

    public function test_finished_deployments_are_reported_to_core(): void
    {
        $this->coreMode();
        Queue::fake();
        $d = Deployment::factory()->create(['status' => 'running', 'app_name' => 'AdGuard Home', 'hostname' => 'dns', 'vmid' => 250053]);

        $d->forceFill(['status' => 'failed', 'error' => 'SSH nicht erreichbar'])->save();

        Queue::assertPushed(SendCoreEvent::class, fn (SendCoreEvent $job) => $job->event['type'] === 'deployment.failed'
            && $job->event['severity'] === 'critical'
            && $job->event['title'] === 'Ausrollen von AdGuard Home auf dns (CT 250053) fehlgeschlagen'
            && $job->event['body'] === 'SSH nicht erreichbar');
    }

    public function test_event_job_posts_with_the_token_and_retries_on_server_errors(): void
    {
        $this->coreMode();
        Http::fake(['core.test/*' => Http::sequence()->push('', 503)->push(['id' => 1], 202)]);
        $job = new SendCoreEvent(['type' => 'deployment.succeeded', 'severity' => 'info', 'title' => 'x', 'body' => null, 'data' => [], 'occurred_at' => now()->toIso8601String()]);

        try {
            $job->handle();
            $this->fail('expected a retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }
        $job->handle();

        Http::assertSent(fn ($r) => $r->url() === 'http://core.test/api/v1/events' && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }
}
