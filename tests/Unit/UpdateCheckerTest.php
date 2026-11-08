<?php

namespace Tests\Unit;

use App\Models\Connection;
use App\Models\Deployment;
use App\Services\Proxmox\ClientFactory;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Ssh\SshRunner;
use App\Services\Updates\UpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class UpdateCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_stores_versions_and_package_count(): void
    {
        $d = Deployment::factory()->succeeded()->create();
        $d->forceFill(['result' => ['container_created' => true, 'host_keys' => ['FP1']]])->save();

        $pve = Mockery::mock(ProxmoxClient::class);
        $pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => "proxapps;proxapps-d{$d->id}"]);
        $pve->shouldReceive('lxcStatus')->andReturn(['status' => 'running']);
        $this->app->instance(ClientFactory::class, new class($pve) extends ClientFactory
        {
            public function __construct(private ProxmoxClient $client) {}

            public function make(Connection $connection, int $timeout = 30): ProxmoxClient
            {
                return $this->client;
            }
        });

        $ssh = Mockery::mock(SshRunner::class);
        $ssh->shouldReceive('connect')->once()->with('192.0.2.53', 30, null, null, ['FP1'])
            ->andReturn(Mockery::mock(SSH2::class)->shouldIgnoreMissing());
        $ssh->shouldReceive('runScript')->once()->andReturnUsing(function ($c, string $script, array $env, callable $onLine) {
            $this->assertStringContainsString('AdGuardHome --version', $script);
            $onLine('PROXAPPS_OS_UPDATES=7');
            $onLine('PROXAPPS_APP_VERSION=v0.107.79');

            return 0;
        });
        $this->app->instance(SshRunner::class, $ssh);
        Http::fake(['static.adtidy.org/*' => Http::response(['version' => 'v0.107.80'])]);

        app(UpdateChecker::class)->check($d);

        $d->refresh();
        $this->assertSame('v0.107.79', $d->app_version);
        $this->assertSame('v0.107.80', $d->latest_version);
        $this->assertSame(7, $d->os_updates);
        $this->assertNull($d->update_check_error);
        $this->assertNotNull($d->updates_checked_at);
        $this->assertTrue($d->appUpdateAvailable());
        $this->assertTrue($d->hasUpdates());
    }

    public function test_errors_are_recorded_instead_of_thrown(): void
    {
        $d = Deployment::factory()->succeeded()->create();
        $d->forceFill(['result' => ['container_created' => true]])->save();
        $pve = Mockery::mock(ProxmoxClient::class);
        $pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => "proxapps;proxapps-d{$d->id}"]);
        $pve->shouldReceive('lxcStatus')->andReturn(['status' => 'stopped']);
        $this->app->instance(ClientFactory::class, new class($pve) extends ClientFactory
        {
            public function __construct(private ProxmoxClient $client) {}

            public function make(Connection $connection, int $timeout = 30): ProxmoxClient
            {
                return $this->client;
            }
        });
        Http::fake(['*' => Http::response('down', 503)]);

        app(UpdateChecker::class)->check($d);

        $error = (string) $d->refresh()->update_check_error;
        $this->assertStringContainsString('Neueste Version nicht ermittelbar', $error);
        $this->assertStringContainsString('läuft nicht', $error);
    }
}
