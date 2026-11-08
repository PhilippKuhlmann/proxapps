<?php

namespace Tests\Unit;

use App\Jobs\RunUpdate;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Proxmox\ClientFactory;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Ssh\SshRunner;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateRunner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class UpdateRunnerTest extends TestCase
{
    use RefreshDatabase;

    private ProxmoxClient&MockInterface $pve;

    private SshRunner&MockInterface $ssh;

    /** @var list<string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['proxapps.poll_interval' => 0, 'proxapps.retry_interval' => 0]);
        Http::preventStrayRequests();

        $this->pve = Mockery::mock(ProxmoxClient::class);
        $pve = $this->pve;
        $this->app->instance(ClientFactory::class, new class($pve) extends ClientFactory
        {
            public function __construct(private ProxmoxClient $client) {}

            public function make(Connection $connection, int $timeout = 30): ProxmoxClient
            {
                return $this->client;
            }
        });
        $this->ssh = Mockery::mock(SshRunner::class);
        $this->app->instance(SshRunner::class, $this->ssh);
        $checker = Mockery::mock(UpdateChecker::class);
        $checker->shouldReceive('check')->andReturnUsing(function (Deployment $d) {
            $d->forceFill(['app_version' => 'v0.107.81', 'os_updates' => 0])->save();
        });
        $this->app->instance(UpdateChecker::class, $checker);
    }

    private function managed(array $result = []): Deployment
    {
        $d = Deployment::factory()->succeeded()->create(['app_version' => 'v0.107.80', 'latest_version' => 'v0.107.81']);
        $d->forceFill(['result' => array_merge((array) $d->result, ['container_created' => true, 'host_keys' => ['FP1']], $result)])->save();

        return $d;
    }

    private function expectOwnedAndRunning(Deployment $d): void
    {
        $this->pve->shouldReceive('lxcConfig')->with('pve1', $d->vmid)
            ->andReturn(['hostname' => 'adguard', 'tags' => "proxapps;proxapps-d{$d->id};dns"]);
        $this->pve->shouldReceive('lxcStatus')->with('pve1', $d->vmid)->andReturn(['status' => 'running']);
    }

    private function expectSsh(int $appExit = 0): void
    {
        $conn = Mockery::mock(SSH2::class)->shouldIgnoreMissing();
        $this->ssh->shouldReceive('connect')->once()
            ->with('192.0.2.53', 60, Mockery::any(), null, ['FP1'])->andReturn($conn);
        $this->ssh->shouldReceive('runScript')->andReturnUsing(
            function ($c, string $script, array $env, callable $onLine) use ($appExit) {
                $this->scripts[] = $script;
                if (count($this->scripts) === 1) {
                    $onLine('PROXAPPS_OS_COUNT=4');
                    $onLine('Setting up libc6 ...');

                    return 0;
                }
                $this->assertSame('Secret123456', $env['ADMIN_PASS']);
                $this->assertSame('192.0.2.53', $env['PROXAPPS_IP']);

                return $appExit;
            });
    }

    public function test_happy_path_snapshots_updates_verifies_and_prunes(): void
    {
        $d = $this->managed();
        $this->expectOwnedAndRunning($d);
        $this->expectSsh();
        $this->pve->shouldReceive('lxcSnapshot')->once()
            ->withArgs(fn ($node, $vmid, $name) => $node === 'pve1' && str_starts_with($name, UpdateRunner::SNAPSHOT_PREFIX))
            ->andReturn('UPID:pve1:snap');
        $this->pve->shouldReceive('waitTask')->andReturn(['ok' => true, 'exitstatus' => 'OK', 'log' => []]);
        $this->pve->shouldReceive('lxcSnapshots')->andReturn([
            ['name' => 'current'], ['name' => 'manual-backup'],
            ['name' => 'proxapps_upd_20260101_000000'], ['name' => 'proxapps_upd_20260201_000000'],
            ['name' => 'proxapps_upd_20260301_000000'], ['name' => 'proxapps_upd_20260401_000000'],
        ]);
        // keep 3 → only the oldest ProxApps snapshot goes; foreign snapshots are never touched
        $this->pve->shouldReceive('lxcDeleteSnapshot')->once()->with('pve1', $d->vmid, 'proxapps_upd_20260101_000000')
            ->andReturn('UPID:pve1:del');
        Http::fake(['http://192.0.2.53/login.html' => Http::response('ok')]);

        $run = UpdateRun::query()->create(['deployment_id' => $d->id]);
        RunUpdate::dispatchSync($run->id);

        $run->refresh();
        $this->assertSame('success', $run->status, $run->error ?? '');
        $this->assertSame(4, $run->os_packages);
        $this->assertSame('v0.107.80', $run->from_version);
        $this->assertSame('v0.107.81', $run->to_version);
        $this->assertTrue($run->snapshot_exists);
        $this->assertCount(2, $this->scripts);
        $this->assertStringContainsString('dist-upgrade', $this->scripts[0]);
        $this->assertStringContainsString('AdGuardHome', $this->scripts[1]);
        $logs = $d->logs()->where('update_run_id', $run->id)->pluck('message')->all();
        $this->assertContains('4 Debian-Paket(e) aktualisiert.', $logs);
        $this->assertStringNotContainsString('Secret123456', implode("\n", $logs));
    }

    public function test_failed_app_update_rolls_back_to_the_snapshot(): void
    {
        $d = $this->managed();
        $this->expectOwnedAndRunning($d);
        $this->expectSsh(appExit: 1);
        $this->pve->shouldReceive('lxcSnapshot')->once()->andReturn('UPID:pve1:snap');
        $this->pve->shouldReceive('waitTask')->andReturn(['ok' => true, 'exitstatus' => 'OK', 'log' => []]);
        $this->pve->shouldReceive('lxcRollback')->once()
            ->withArgs(fn ($node, $vmid, $name, $start) => str_starts_with($name, UpdateRunner::SNAPSHOT_PREFIX) && $start === true)
            ->andReturn('UPID:pve1:rollback');

        $run = UpdateRun::query()->create(['deployment_id' => $d->id]);
        RunUpdate::dispatchSync($run->id);

        $run->refresh();
        $this->assertSame('rolled_back', $run->status);
        $this->assertStringContainsString('App-Update fehlgeschlagen', (string) $run->error);
    }

    public function test_without_snapshot_a_failure_is_just_failed(): void
    {
        Settings::put('snapshot_before_update', false);
        $d = $this->managed();
        $this->expectOwnedAndRunning($d);
        $this->expectSsh(appExit: 2);
        $this->pve->shouldNotReceive('lxcSnapshot');
        $this->pve->shouldNotReceive('lxcRollback');

        $run = UpdateRun::query()->create(['deployment_id' => $d->id]);
        RunUpdate::dispatchSync($run->id);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertNull($run->snapshot);
    }

    public function test_foreign_container_is_never_touched(): void
    {
        $d = $this->managed();
        $this->pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => 'proxapps;dns']);
        $this->pve->shouldNotReceive('lxcSnapshot');
        $this->ssh->shouldNotReceive('connect');

        $run = UpdateRun::query()->create(['deployment_id' => $d->id]);
        RunUpdate::dispatchSync($run->id);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertStringContainsString('proxapps-d'.$d->id, (string) $run->error);
    }

    public function test_stopped_container_is_not_updated(): void
    {
        $d = $this->managed();
        $this->pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => "proxapps;proxapps-d{$d->id}"]);
        $this->pve->shouldReceive('lxcStatus')->andReturn(['status' => 'stopped']);
        $this->pve->shouldNotReceive('lxcSnapshot');

        $run = UpdateRun::query()->create(['deployment_id' => $d->id]);
        RunUpdate::dispatchSync($run->id);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertStringContainsString('läuft nicht', (string) $run->error);
    }

    public function test_a_run_is_only_claimed_once(): void
    {
        $d = $this->managed();
        $run = UpdateRun::query()->create(['deployment_id' => $d->id, 'status' => 'running']);
        $this->pve->shouldNotReceive('lxcConfig');

        app(UpdateRunner::class)->run($run);

        $this->assertSame('running', $run->refresh()->status);
    }
}
