<?php

namespace Tests\Unit;

use App\Jobs\RemoveContainer;
use App\Jobs\RunDeployment;
use App\Jobs\SendCoreEvent;
use App\Models\Connection;
use App\Models\Deployment;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\ContainerManager;
use App\Services\Deploy\DeployException;
use App\Services\Deploy\DeploymentLogger;
use App\Services\Deploy\DeploymentRunner;
use App\Services\Proxmox\ClientFactory;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Services\Ssh\DeployerKey;
use App\Services\Ssh\SshRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class DeploymentRunnerTest extends TestCase
{
    use RefreshDatabase;

    private ProxmoxClient&MockInterface $pve;

    private SshRunner&MockInterface $ssh;

    private string $keyDir;

    /** @var list<array{script: string, env: array<string, string>, timeout: int}> */
    private array $scripts = [];

    /** @var list<array{upid: string, timeout: int, cancel: bool}> */
    private array $waits = [];

    private ?\Closure $onCreate = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyDir = sys_get_temp_dir().'/proxapps-runner-'.bin2hex(random_bytes(4));
        config([
            'proxapps.ssh_key_path' => $this->keyDir.'/id_ed25519',
            'proxapps.poll_interval' => 0,
            'proxapps.retry_interval' => 0,
        ]);
        Http::preventStrayRequests();
        Process::fake(['*' => Process::result(exitCode: 1)]); // nothing answers ping

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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->keyDir);
        parent::tearDown();
    }

    /** Host key lines as Proxmox prints them in the create task log. */
    private const FP_ED25519 = 'Xv8b3Qb6f0FQx2bqvHkqzLwE4m3cBf5iUZ1k8gYpNnA';

    private const FP_RSA = 'k2l9P0aQ1sR2tU3vW4xY5zA6bC7dE8fG9hI0jK1lM2n';

    /** @var list<string> */
    private array $createLog = [
        "Creating SSH host key 'ssh_host_ed25519_key' - this may take some time ...",
        'done: SHA256:'.self::FP_ED25519.' root@adguard',
        "Creating SSH host key 'ssh_host_rsa_key' - this may take some time ...",
        'done: SHA256:'.self::FP_RSA.'= root@adguard',
    ];

    /**
     * VMID 2053 matches the default pattern {o3}{o4:03} for the factory IP 192.0.2.53.
     */
    private function deployment(array $spec = [], array $attrs = []): Deployment
    {
        $d = Deployment::factory()->create(array_merge(['vmid' => 2053], $attrs));
        $d->spec = array_replace_recursive($d->spec, ['vmid' => $d->vmid], $spec);
        $d->save();

        return $d;
    }

    /**
     * @return array<string, string>
     */
    private function ownedConfig(Deployment $d, array $overrides = []): array
    {
        return array_merge(['hostname' => 'adguard', 'tags' => "adblock;dns;proxapps;proxapps-d{$d->id}"], $overrides);
    }

    private function expectProxmoxUntilStart(Deployment $d, bool $startOk = true, bool $tagging = true): void
    {
        $vmid = $d->vmid;
        $this->pve->shouldReceive('vmidFree')->with($vmid)->andReturnTrue();
        $this->pve->shouldReceive('guests')->andReturn([]);
        $this->pve->shouldReceive('templatesOnStorage')->with('pve1', 'local')->andReturn([
            ['volid' => 'local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', 'size' => 1],
        ]);
        $this->pve->shouldReceive('aplinfo')->andReturn([]);
        $this->pve->shouldReceive('createLxc')->once()->withArgs(function (string $node, array $p) use ($vmid, $d) {
            return $node === 'pve1' && $p['vmid'] === $vmid
                && $p['ostemplate'] === 'local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst'
                && ! isset($p['tags'])   // set after creation (pool-scoped tokens)
                && str_contains($p['description'], "Deployment #{$d->id}")
                && str_starts_with($p['ssh-public-keys'], 'ssh-ed25519 ')
                && ! isset($p['password']);
        })->andReturn('UPID:pve1:create');
        if ($tagging) {
            $this->pve->shouldReceive('lxcSetConfig')->withArgs(
                fn (string $node, int $id, array $p) => $node === 'pve1' && $id === $vmid
                    && in_array('proxapps', explode(';', $p['tags']), true)
                    && in_array("proxapps-d{$d->id}", explode(';', $p['tags']), true)
            );
        }
        $this->pve->shouldReceive('lxcAction')->with('pve1', $vmid, 'start')->andReturn('UPID:pve1:start');
        $this->pve->shouldReceive('waitTask')->andReturnUsing(function (string $upid, int $timeout, ?callable $onLog = null, ?callable $cancel = null) use ($startOk) {
            $this->waits[] = ['upid' => $upid, 'timeout' => $timeout, 'cancel' => $cancel !== null];
            if ($upid === 'UPID:pve1:create' && $onLog) {
                foreach ($this->createLog as $line) {
                    $onLog($line);
                }
                if ($this->onCreate) {
                    ($this->onCreate)();
                }
            }
            $onLog && $onLog("task output of {$upid}");
            if ($upid === 'UPID:pve1:destroyfail') {
                return ['ok' => false, 'exitstatus' => "can't lock file", 'log' => []];
            }
            if ($upid === 'UPID:pve1:start' && ! $startOk) {
                return ['ok' => false, 'exitstatus' => 'startup for container failed', 'log' => []];
            }

            return ['ok' => true, 'exitstatus' => 'OK', 'log' => []];
        });
    }

    private function expectSsh(int $installExit = 0, ?array $hostKeys = null): void
    {
        $conn = Mockery::mock(SSH2::class)->shouldIgnoreMissing();
        $this->ssh->shouldReceive('connect')->once()
            ->with('192.0.2.53', 240, Mockery::any(), Mockery::any(), $hostKeys ?? [self::FP_ED25519, self::FP_RSA])
            ->andReturn($conn);
        $this->ssh->shouldReceive('runScript')->twice()->andReturnUsing(
            function ($c, string $script, array $env, callable $onLine, array $secrets, int $timeout, ?callable $tick, ?callable $warn) use ($installExit) {
                $this->assertNotNull($tick);
                $this->assertNotNull($warn);
                $this->scripts[] = ['script' => $script, 'env' => $env, 'timeout' => $timeout];
                $onLine(SshRunner::mask('using password Secret123456', $secrets));

                return count($this->scripts) === 2 ? $installExit : 0;
            });
    }

    /**
     * @return list<string>
     */
    private function messages(Deployment $d): array
    {
        return $d->logs()->pluck('message')->all();
    }

    public function test_happy_path(): void
    {
        $d = $this->deployment();
        $this->expectProxmoxUntilStart($d);
        $this->expectSsh();
        Http::fake(['http://192.0.2.53/login.html' => Http::response('ok', 200)]);

        RunDeployment::dispatchSync($d->id);

        $d->refresh();
        $this->assertSame('success', $d->status, implode("\n", $this->messages($d)));
        $this->assertSame('done', $d->step);
        $this->assertNotNull($d->finished_at);
        $this->assertSame([
            'container_created' => true,
            'url' => 'http://192.0.2.53:80/', 'user' => 'admin', 'password_param' => 'ADMIN_PASS',
            'notes' => 'Trage 192.0.2.53 als DNS-Server in deinem Router bzw. DHCP-Server ein, damit alle'
                ."\nGeräte über AdGuard Home auflösen. Updates spielt ProxApps zentral ein (Seite „Updates“).",
            'proxy_url' => 'http://192.0.2.53:80',
            'host_keys' => [self::FP_ED25519, self::FP_RSA],
        ], $d->result);

        $messages = $this->messages($d);
        foreach (['── Prüfungen', "VMID {$d->vmid} ist frei.", 'IP 192.0.2.53 ist frei.', '── Debian-Vorlage',
            'Verwende vorhandene Vorlage local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', '── Container anlegen',
            'Container läuft.', '── Netzwerk & SSH', 'Verbinde per SSH mit root@192.0.2.53 …', 'SSH-Verbindung steht.',
            '── Grundsystem', '── App installieren', '── Funktionsprüfung', 'Prüfe http://192.0.2.53:80/login.html …',
            'Antwort HTTP 200 – Dienst erreichbar.', '✔ AdGuard Home ist bereit: http://192.0.2.53:80/',
            'using password ••••••', 'Tags gesetzt: proxapps, proxapps-d'.$d->id.', dns, adblock',
            'Erwartete SSH-Hostschlüssel: SHA256:'.self::FP_ED25519.', SHA256:'.self::FP_RSA] as $expected) {
            $this->assertContains($expected, $messages);
        }
        $this->assertStringNotContainsString('Secret123456', implode("\n", $messages));
        $this->assertSame(8, $d->logs()->where('level', 'step')->count());

        [$base, $install] = $this->scripts;
        $this->assertSame(['PROXAPPS_UPGRADE' => '1', 'PROXAPPS_TZ' => 'Europe/Berlin'], $base['env']);
        $this->assertStringContainsString('apt', $base['script']);
        $this->assertSame([
            'PROXAPPS_IP' => '192.0.2.53', 'PROXAPPS_HOSTNAME' => 'adguard', 'PROXAPPS_VMID' => (string) $d->vmid,
            'ADMIN_USER' => 'admin', 'WEB_PORT' => '80', 'ADMIN_PASS' => 'Secret123456',
        ], $install['env']);
        $this->assertStringContainsString('AdGuard', $install['script']);
        $this->assertSame([1800, 3600], [$base['timeout'], $install['timeout']]);

        // the create wait must not be interrupted by a cancel (it would orphan an untagged CT)
        $create = collect($this->waits)->firstWhere('upid', 'UPID:pve1:create');
        $this->assertFalse($create['cancel']);
        $this->assertSame(900, $create['timeout']);
    }

    public function test_missing_host_keys_are_logged_as_warning(): void
    {
        $this->createLog = [];
        $d = $this->deployment();
        $this->expectProxmoxUntilStart($d);
        $this->expectSsh(hostKeys: []);
        Http::fake(['http://192.0.2.53/login.html' => Http::response('ok', 200)]);

        app(DeploymentRunner::class)->run($d);

        $this->assertSame('success', $d->fresh()->status);
        $this->assertContains('Warnung: Im Protokoll des Anlegens stehen keine SSH-Hostschlüssel – der Hostschlüssel '
            .'des Containers kann nicht geprüft werden.', $this->messages($d));
    }

    public function test_step_limits_are_clamped_to_the_overall_deadline(): void
    {
        config(['proxapps.deploy_budget' => 100]);
        $d = $this->deployment();
        $this->expectProxmoxUntilStart($d);
        $conn = Mockery::mock(SSH2::class)->shouldIgnoreMissing();
        $this->ssh->shouldReceive('connect')->once()
            ->withArgs(fn (string $ip, int $timeout) => $timeout <= 100)->andReturn($conn);
        $this->ssh->shouldReceive('runScript')->twice()->andReturnUsing(function ($c, $s, $e, $l, $sec, int $timeout) {
            $this->scripts[] = ['script' => $s, 'env' => $e, 'timeout' => $timeout];

            return 0;
        });
        Http::fake(['http://192.0.2.53/login.html' => Http::response('ok', 200)]);

        app(DeploymentRunner::class)->run($d);

        $this->assertSame('success', $d->fresh()->status);
        foreach ($this->scripts as $script) {
            $this->assertLessThanOrEqual(100, $script['timeout']);
        }
        foreach ($this->waits as $wait) {
            $this->assertLessThanOrEqual(100, $wait['timeout']);
        }
        $this->assertSame(7200 - 300, (function () {
            config(['proxapps.deploy_budget' => null]);

            return DeploymentRunner::budget();
        })());
        config(['proxapps.deploy_budget' => 999999]);
        $this->assertSame(6900, DeploymentRunner::budget());
    }

    public function test_exhausted_deadline_fails_the_deployment(): void
    {
        config(['proxapps.deploy_budget' => 1]);
        $d = $this->deployment();
        $this->pve->shouldReceive('vmidFree')->andReturnUsing(function () {
            usleep(1_100_000);

            return true;
        });
        $this->pve->shouldReceive('guests')->andReturn([]);
        $this->pve->shouldReceive('templatesOnStorage')->andReturn([
            ['volid' => 'local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst', 'size' => 1],
        ]);
        $this->pve->shouldReceive('aplinfo')->andReturn([]);
        $this->pve->shouldNotReceive('createLxc');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('Zeitlimit für das gesamte Ausrollen (0 min) überschritten.', $d->error);
    }

    public function test_vmid_not_matching_the_ip_is_rejected_by_the_runner(): void
    {
        $d = $this->deployment([], ['vmid' => 250053]);
        $this->pve->shouldReceive('vmidFree')->andReturnTrue();
        $this->pve->shouldNotReceive('createLxc');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('validate', $d->step);
        $this->assertSame('Die VMID muss 2053 sein (passend zur IP 192.0.2.53).', $d->error);
    }

    public function test_cancel_during_create_waits_for_the_task_and_tags_the_container(): void
    {
        $d = $this->deployment();
        $this->onCreate = fn () => Deployment::query()->whereKey($d->id)->update(['cancel_requested' => true]);
        $this->expectProxmoxUntilStart($d);
        $this->pve->shouldNotReceive('lxcAction');
        $this->pve->shouldNotReceive('lxcDestroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('cancelled', $d->status);
        $this->assertSame(['container_created' => true], $d->result);
        $messages = $this->messages($d);
        $this->assertContains('Tags gesetzt: proxapps, proxapps-d'.$d->id.', dns, adblock', $messages);
        $this->assertContains("Container {$d->vmid} bleibt zur Fehlersuche bestehen.", $messages);
    }

    public function test_failed_tagging_is_retried_before_destroying(): void
    {
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, tagging: false);
        $this->pve->shouldReceive('lxcSetConfig')->twice()->andReturnUsing(function () {
            static $calls = 0;
            if (++$calls === 1) {
                throw new ProxmoxException('Proxmox-API PUT /nodes/pve1/lxc/2053/config → 500 CT is locked (create)', 500);
            }
        });
        $this->pve->shouldReceive('lxcConfig')->with('pve1', $d->vmid)->andReturn($this->ownedConfig($d));
        $this->pve->shouldReceive('lxcDestroy')->once()->andReturn('UPID:pve1:destroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('create', $d->step);
        $this->assertSame(['container_created' => true, 'container_removed' => true], $d->result);
        $messages = $this->messages($d);
        $this->assertContains('Tags nachträglich gesetzt: proxapps, proxapps-d'.$d->id.', dns, adblock', $messages);
        $this->assertContains('Container entfernt.', $messages);
    }

    public function test_untaggable_container_is_reported_clearly(): void
    {
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, tagging: false);
        $this->pve->shouldReceive('lxcSetConfig')->twice()->andThrow(new ProxmoxException('403 Permission check failed', 403));
        $this->pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => '']);
        $this->pve->shouldNotReceive('lxcDestroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame(['container_created' => true], $d->result);
        $warning = collect($this->messages($d))->first(fn ($m) => str_starts_with($m, "Achtung: CT {$d->vmid} konnte nicht"));
        $this->assertNotNull($warning);
        $this->assertStringContainsString('von Hand entfernen', $warning);
        $this->assertContains("Container {$d->vmid} wird nicht entfernt: er stammt nicht erkennbar von diesem Deployment.",
            $this->messages($d));
    }

    public function test_failure_keeps_container_by_default(): void
    {
        $d = $this->deployment();
        $this->expectProxmoxUntilStart($d, startOk: false);
        $this->pve->shouldNotReceive('lxcDestroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('start', $d->step);
        $this->assertSame('Start fehlgeschlagen: startup for container failed', $d->error);
        $messages = $this->messages($d);
        $this->assertContains('✖ Start fehlgeschlagen: startup for container failed', $messages);
        $this->assertContains("Container {$d->vmid} bleibt zur Fehlersuche bestehen.", $messages);
    }

    public function test_failed_install_masks_secrets_in_error(): void
    {
        $d = $this->deployment();
        $this->expectProxmoxUntilStart($d);
        $this->expectSsh(installExit: 3);

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('install', $d->step);
        $this->assertSame('Installation von AdGuard Home fehlgeschlagen (Exit-Code 3).', $d->error);
    }

    public function test_destroy_on_failure_refuses_foreign_container(): void
    {
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, startOk: false);
        $this->pve->shouldReceive('lxcConfig')->with('pve1', $d->vmid)
            ->andReturn(['hostname' => 'adguard', 'tags' => 'dns;production']);
        $this->pve->shouldNotReceive('lxcDestroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertContains("Container {$d->vmid} wird nicht entfernt: er stammt nicht erkennbar von diesem Deployment.",
            $this->messages($d));
    }

    public function test_destroy_on_failure_removes_own_container(): void
    {
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, startOk: false);
        $this->pve->shouldReceive('lxcConfig')->with('pve1', $d->vmid)->andReturn($this->ownedConfig($d));
        $this->pve->shouldReceive('lxcDestroy')->once()->with('pve1', $d->vmid)->andReturn('UPID:pve1:destroy');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('start', $d->step); // the failed step is kept
        $this->assertSame(['container_created' => true, 'container_removed' => true], $d->result);
        $this->assertContains('Container entfernt.', $this->messages($d));
    }

    public function test_destroy_on_failure_checks_the_destroy_task(): void
    {
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, startOk: false);
        $this->pve->shouldReceive('lxcConfig')->andReturn($this->ownedConfig($d));
        $this->pve->shouldReceive('lxcDestroy')->once()->andReturn('UPID:pve1:destroyfail');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('start', $d->step);
        $this->assertSame(['container_created' => true], $d->result);
        $messages = $this->messages($d);
        $this->assertContains("Container {$d->vmid} konnte nicht entfernt werden: can't lock file", $messages);
        $this->assertNotContains('Container entfernt.', $messages);
    }

    public function test_old_container_with_same_vmid_is_not_destroyed(): void
    {
        // An earlier deployment's CT (other id tag) still sits on the VMID derived from the IP.
        $d = $this->deployment(['destroy_on_failure' => true]);
        $this->expectProxmoxUntilStart($d, startOk: false);
        $this->pve->shouldReceive('lxcConfig')->andReturn(
            ['hostname' => 'adguard', 'tags' => 'proxapps;proxapps-d999', 'description' => 'Deployment #999']);
        $this->pve->shouldNotReceive('lxcDestroy');

        app(DeploymentRunner::class)->run($d);

        $this->assertContains("Container {$d->vmid} wird nicht entfernt: er stammt nicht erkennbar von diesem Deployment.",
            $this->messages($d));
    }

    public function test_used_ip_aborts_before_creating(): void
    {
        $d = $this->deployment();
        $this->pve->shouldReceive('vmidFree')->andReturnTrue();
        $this->pve->shouldReceive('guests')->andReturn([['vmid' => 77, 'node' => 'pve1', 'type' => 'lxc', 'name' => 'old',
            'status' => 'running', 'template' => false]]);
        $this->pve->shouldReceive('lxcConfig')->with('pve1', 77)->andReturn(['net0' => 'name=eth0,ip=192.0.2.53/24']);
        $this->pve->shouldNotReceive('createLxc');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame('validate', $d->step);
        $this->assertSame('Die IP 192.0.2.53 ist bereits vergeben (77 old).', $d->error);
    }

    public function test_cancel_requested_before_start(): void
    {
        $d = $this->deployment([], ['cancel_requested' => true]);
        $this->pve->shouldNotReceive('createLxc');

        app(DeploymentRunner::class)->run($d);

        $d->refresh();
        $this->assertSame('cancelled', $d->status);
        $this->assertSame('Vom Benutzer abgebrochen.', $d->error);
        $this->assertContains('Ausrollen wurde abgebrochen.', $this->messages($d));
    }

    public function test_runner_does_not_run_a_deployment_twice(): void
    {
        $d = $this->deployment([], ['status' => 'failed']);
        $this->pve->shouldNotReceive('vmidFree');

        app(DeploymentRunner::class)->run($d);

        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(0, $d->logs()->count());
    }

    public function test_container_manager_guards_ownership(): void
    {
        $d = $this->deployment([], ['status' => 'success']);
        $this->pve->shouldReceive('lxcConfig')->with('pve1', $d->vmid)->andReturn(['hostname' => 'other', 'tags' => 'proxapps']);
        $this->pve->shouldNotReceive('lxcDestroy');
        $this->pve->shouldNotReceive('lxcAction');
        $manager = app(ContainerManager::class);

        try {
            $manager->destroy($d);
            $this->fail('expected exception');
        } catch (DeployException $e) {
            $this->assertStringContainsString('aus Sicherheitsgründen nicht entfernt', $e->getMessage());
        }
        $this->expectException(DeployException::class);
        $manager->action($d, 'stop');
    }

    public function test_container_manager_destroys_owned_container(): void
    {
        $d = $this->deployment([], ['status' => 'success', 'result' => ['container_created' => true]]);
        $this->pve->shouldReceive('lxcConfig')->andReturn($this->ownedConfig($d));
        $this->pve->shouldReceive('lxcStatus')->andReturn(['status' => 'running']);
        $this->pve->shouldReceive('lxcAction')->once()->with('pve1', $d->vmid, 'stop')->andReturn('UPID:pve1:stop');
        $this->pve->shouldReceive('lxcDestroy')->once()->andReturn('UPID:pve1:destroy');
        $this->pve->shouldReceive('waitTask')->andReturn(['ok' => true, 'exitstatus' => 'OK', 'log' => []]);

        $steps = [];
        app(ContainerManager::class)->destroy($d, function (string $step) use (&$steps) {
            $steps[] = $step;
        });

        $d->refresh();
        $this->assertSame(['r_check', 'r_stop', 'r_destroy', 'r_cleanup'], $steps);
        $this->assertSame('removed', $d->status);
        $this->assertSame(['container_created' => true, 'container_removed' => true], $d->result);
        $this->assertContains("Container {$d->vmid} wurde entfernt.", $this->messages($d));
    }

    public function test_remove_job_runs_the_steps_and_restores_the_status_on_failure(): void
    {
        Queue::fake();
        $d = $this->deployment([], ['status' => 'success', 'step' => 'done', 'result' => ['container_created' => true]]);
        RemoveContainer::start($d);
        $d->refresh();
        $this->assertSame(['removing', 'r_check'], [$d->status, $d->step]);
        $this->assertTrue($d->isActive(), 'blocks VMID, IP and actions');
        Queue::assertPushed(RemoveContainer::class, fn ($job) => $job->previousStatus === 'success');

        // the stop fails: back to "success", the step that failed is remembered
        $this->pve->shouldReceive('lxcConfig')->andReturn($this->ownedConfig($d));
        $this->pve->shouldReceive('lxcStatus')->andReturn(['status' => 'running']);
        $this->pve->shouldReceive('lxcAction')->andReturn('UPID:pve1:stop');
        $this->pve->shouldReceive('waitTask')->andReturn(['ok' => false, 'exitstatus' => 'timeout', 'log' => []]);
        $this->pve->shouldNotReceive('lxcDestroy');
        (new RemoveContainer($d->id, 'success'))->handle(app(ContainerManager::class));

        $d->refresh();
        $this->assertSame(['success', 'done'], [$d->status, $d->step]);
        $this->assertSame('r_stop', $d->result['removal_step']);
        $this->assertStringContainsString('Stoppen fehlgeschlagen', $d->result['removal_error']);
        // the restored status is no new deployment result: no event to Core
        Queue::assertNotPushed(SendCoreEvent::class);
    }

    public function test_container_manager_refuses_container_of_an_older_deployment(): void
    {
        // Same VMID and hostname, but tagged by another deployment → must not be touched.
        $d = $this->deployment([], ['status' => 'success']);
        $this->pve->shouldReceive('lxcConfig')->andReturn(['hostname' => 'adguard', 'tags' => 'proxapps;proxapps-d'.($d->id + 1)]);
        $this->pve->shouldNotReceive('lxcDestroy');
        $this->pve->shouldNotReceive('lxcAction');

        try {
            app(ContainerManager::class)->action($d, 'stop');
            $this->fail('expected exception');
        } catch (DeployException $e) {
            $this->assertStringContainsString("Tag 'proxapps-d{$d->id}'", $e->getMessage());
        }
    }

    public function test_ownership_rule(): void
    {
        $d = $this->deployment();
        $id = $d->id;
        $owned = fn (array $cfg) => ContainerManager::ownedConfig($cfg, $d);

        $this->assertTrue($owned(['hostname' => 'adguard', 'tags' => "dns;proxapps;proxapps-d{$id}"]));
        $this->assertTrue($owned(['hostname' => 'adguard', 'tags' => "PROXAPPS,proxapps-d{$id}"]));
        // fallback: marker in the description (tags could not be completed)
        $this->assertTrue($owned(['hostname' => 'adguard', 'tags' => 'proxapps',
            'description' => "**AdGuard Home**\n\nAusgerollt mit ProxApps (Deployment #{$id}) am 08.11.2026"]));
        $this->assertFalse($owned(['hostname' => 'adguard', 'tags' => 'proxapps',
            'description' => "Ausgerollt mit ProxApps (Deployment #{$id}0)"]));
        $this->assertFalse($owned(['hostname' => 'adguard', 'tags' => 'proxapps']));
        $this->assertFalse($owned(['hostname' => 'adguard', 'tags' => "proxapps-d{$id}"]));
        $this->assertFalse($owned(['hostname' => 'adguard', 'tags' => "proxapps;proxapps-d{$id}0"]));
        $this->assertFalse($owned(['hostname' => 'other', 'tags' => "proxapps;proxapps-d{$id}"]));
        $this->assertFalse($owned([]));

        $this->pve->shouldReceive('lxcConfig')->andThrow(new ProxmoxException('does not exist', 500));
        $this->assertFalse(ContainerManager::owned($this->pve, $d));
    }

    public function test_recover_command_marks_only_running_deployments_failed(): void
    {
        $running = $this->deployment([], ['status' => 'running']);
        $queued = $this->deployment([], ['status' => 'queued']);
        $done = $this->deployment([], ['status' => 'success']);

        Artisan::call('proxapps:recover');

        $this->assertSame('failed', $running->fresh()->status);
        $this->assertSame('Abgebrochen: ProxApps wurde während des Ausrollens neu gestartet.', $running->fresh()->error);
        $this->assertSame('queued', $queued->fresh()->status); // its job is still queued
        $this->assertSame('success', $done->fresh()->status);
    }

    public function test_job_failed_handles_timeouts_and_stale_reservations(): void
    {
        $job = fn (Deployment $d) => new RunDeployment($d->id);

        // the worker killed the run (timeout) → failed
        $timedOut = $this->deployment([], ['status' => 'running']);
        $job($timedOut)->failed(new TimeoutExceededException('timed out'));
        $this->assertSame('failed', $timedOut->fresh()->status);
        $this->assertSame('Abgebrochen: Das Zeitlimit des Hintergrundprozesses (120 min) wurde überschritten.',
            $timedOut->fresh()->error);

        // re-reserved while the first run may still be alive → untouched
        $busy = $this->deployment([], ['status' => 'running']);
        $job($busy)->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('running', $busy->fresh()->status);

        // re-reserved and nothing happened for longer than the job timeout → the worker died
        $dead = $this->deployment([], ['status' => 'running']);
        Deployment::query()->whereKey($dead->id)->update(['updated_at' => now()->subSeconds(RunDeployment::TIMEOUT + 60)]);
        $job($dead)->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('failed', $dead->fresh()->status);

        // a recent log line counts as activity
        $logged = $this->deployment([], ['status' => 'running']);
        Deployment::query()->whereKey($logged->id)->update(['updated_at' => now()->subSeconds(RunDeployment::TIMEOUT + 60)]);
        DeploymentLogger::log($logged, 'still working', 'out', 'install');
        $job($logged)->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('running', $logged->fresh()->status);

        $this->assertSame(7200, (new RunDeployment(1))->timeout);
    }

    public function test_catalog_and_deployer_key_are_fresh_per_job(): void
    {
        $catalog = app(Catalog::class);
        $key = app(DeployerKey::class);
        $this->assertSame($catalog, app(Catalog::class));

        $this->app->forgetScopedInstances(); // what the queue worker does between jobs

        $this->assertNotSame($catalog, app(Catalog::class));
        $this->assertNotSame($key, app(DeployerKey::class));
    }
}
