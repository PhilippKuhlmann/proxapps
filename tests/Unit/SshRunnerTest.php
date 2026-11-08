<?php

namespace Tests\Unit;

use App\Services\Deploy\DeploymentCancelled;
use App\Services\Ssh\DeployerKey;
use App\Services\Ssh\HostKeyMismatch;
use App\Services\Ssh\SshException;
use App\Services\Ssh\SshRunner;
use Illuminate\Support\Facades\File;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Tests\TestCase;

/**
 * In-memory stand-in for a phpseclib connection: records commands, replays script output.
 */
class FakeSsh extends SSH2
{
    /** @var list<string> */
    public array $execs = [];

    /** @var list<string|true|\Closure> read() results while the script runs; exhausted = channel closed */
    public array $chunks = [];

    public int $scriptExit = 0;

    public bool $open = false;

    public bool $wasReset = false;

    public ?string $hostKey = null;

    public int $logins = 0;

    public ?string $failOn = null;

    private int|false $exit = false;

    public function __construct() {}

    public function __destruct() {}

    public function setTimeout($timeout)
    {
        //
    }

    public function setKeepAlive($interval)
    {
        //
    }

    public function exec($command, $callback = null)
    {
        $this->execs[] = $command;
        if ($this->failOn !== null && str_starts_with($command, $this->failOn)) {
            throw new RuntimeException('Please close the channel (1) before trying to open it again');
        }
        if ($callback === false) {
            $this->open = true;
            $this->exit = $this->scriptExit;

            return true;
        }
        $this->exit = 0;

        return '';
    }

    public function isInteractiveChannelOpen($channel)
    {
        return $this->open;
    }

    public function read($expect = '', $mode = self::READ_SIMPLE, $channel = null)
    {
        if (! $this->chunks) {
            $this->open = false;

            return true;
        }
        $next = array_shift($this->chunks);

        return $next instanceof \Closure ? $next() : $next;
    }

    public function reset($channel = null)
    {
        $this->wasReset = true;
        $this->open = false;
    }

    public function getExitStatus()
    {
        return $this->exit;
    }

    public function getServerPublicHostKey()
    {
        return $this->hostKey ?? false;
    }

    public function login($username, ...$args)
    {
        $this->logins++;

        return true;
    }

    public function disconnect()
    {
        //
    }

    /**
     * Content of a file uploaded via the plain-SSH base64 fallback.
     */
    public function uploaded(string $suffix): ?string
    {
        foreach ($this->execs as $cmd) {
            if (preg_match("~^umask 077 && printf %s '([A-Za-z0-9+/=]+)' \\| base64 -d > '([^']+)'$~", $cmd, $m)
                && str_ends_with($m[2], $suffix)) {
                return base64_decode($m[1]);
            }
        }

        return null;
    }
}

class SshRunnerTest extends TestCase
{
    /** ssh-keygen -lf of this key prints SHA256:BrMg81bfXDQJQQxvaHjreqgE7p/rpYvNX/ejEBYAmUo */
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJf+xC7PUe626tob3HTjpxyUTFnMHwGpXfha5X6N18gE';

    private const HOST_FP = 'BrMg81bfXDQJQQxvaHjreqgE7p/rpYvNX/ejEBYAmUo';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/proxapps-ssh-'.bin2hex(random_bytes(4));
        config(['proxapps.retry_interval' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function runner(?FakeSsh $client = null): SshRunner
    {
        $key = new DeployerKey($this->dir.'/id_ed25519');

        return new class($key, $client) extends SshRunner
        {
            public int $clients = 0;

            public function __construct(DeployerKey $key, private ?FakeSsh $client)
            {
                parent::__construct($key);
            }

            protected function newClient(string $host): SSH2
            {
                $this->clients++;

                return $this->client ?? throw new RuntimeException('no client');
            }
        };
    }

    public function test_fingerprint_matches_openssh(): void
    {
        $this->assertSame(self::HOST_FP, SshRunner::fingerprint(self::HOST_KEY));
        $this->assertSame(self::HOST_FP, SshRunner::fingerprint(explode(' ', self::HOST_KEY)[1]));
        $this->assertNull(SshRunner::fingerprint('ssh-ed25519 !!!'));
        $this->assertSame(self::HOST_FP, SshRunner::normalizeFingerprint('SHA256:'.self::HOST_FP.'='));
    }

    public function test_connect_accepts_the_expected_host_key(): void
    {
        $ssh = new FakeSsh;
        $ssh->hostKey = self::HOST_KEY;

        $conn = $this->runner($ssh)->connect('192.0.2.53', 5, null, null, ['other', 'SHA256:'.self::HOST_FP]);

        $this->assertSame($ssh, $conn);
        $this->assertSame(1, $ssh->logins);
    }

    public function test_connect_aborts_on_a_foreign_host_key_before_login(): void
    {
        $ssh = new FakeSsh;
        $ssh->hostKey = self::HOST_KEY;
        $runner = $this->runner($ssh);

        try {
            $runner->connect('192.0.2.53', 30, null, null, ['Xv8b3Qb6f0FQx2bqvHkqzLwE4m3cBf5iUZ1k8gYpNnA']);
            $this->fail('expected HostKeyMismatch');
        } catch (HostKeyMismatch $e) {
            $this->assertStringContainsString('SSH-Hostschlüssel von 192.0.2.53 (SHA256:'.self::HOST_FP.')', $e->getMessage());
            $this->assertStringContainsString('aus Sicherheitsgründen abgebrochen', $e->getMessage());
        }
        $this->assertSame(0, $ssh->logins);   // no secret or key ever reaches the foreign host
        $this->assertSame(1, $runner->clients); // no retry
    }

    public function test_connect_without_expected_keys_skips_the_check(): void
    {
        $ssh = new FakeSsh; // no host key available at all
        $this->assertSame($ssh, $this->runner($ssh)->connect('192.0.2.53', 5));
    }

    public function test_run_script_streams_output_and_cleans_up(): void
    {
        $ssh = new FakeSsh;
        $ssh->chunks = ["hello\nwor", true, "ld with Geheim123\n", 'tail'];
        $lines = [];
        $ticks = 0;

        $code = $this->runner()->runScript($ssh, 'echo hi', ['A' => 'x y', 'bad-key' => 'no'],
            function (string $l) use (&$lines) {
                $lines[] = $l;
            }, ['Geheim123'], 1800, function () use (&$ticks) {
                $ticks++;
            });

        $this->assertSame(0, $code);
        $this->assertSame(['hello', 'world with ••••••', 'tail'], $lines);
        $this->assertGreaterThanOrEqual(4, $ticks); // also on reads without output
        $dir = null;
        foreach ($ssh->execs as $cmd) {
            if (preg_match("~^setsid -w timeout --kill-after=15 1800 bash '(/root/\\.proxapps-[0-9a-f]{16})'/run\\.sh 2>&1$~", $cmd, $m)) {
                $dir = $m[1];
            }
        }
        $this->assertNotNull($dir, implode("\n", $ssh->execs));
        $this->assertSame("rm -rf '{$dir}'", end($ssh->execs));
        $this->assertEmpty(array_filter($ssh->execs, fn ($c) => str_starts_with($c, 'pkill')));
        $this->assertFalse($ssh->wasReset);

        $this->assertSame("A='x y'\n", $ssh->uploaded('/env'));
        $run = (string) $ssh->uploaded('/run.sh');
        $this->assertStringStartsWith("set -a\n. '{$dir}'/env\nset +a\nrm -f '{$dir}'/env\nset -euo pipefail\n", $run);
        $this->assertStringEndsWith("echo hi\n", $run);
    }

    public function test_run_script_returns_the_exit_code(): void
    {
        $ssh = new FakeSsh;
        $ssh->scriptExit = 3;

        $this->assertSame(3, $this->runner()->runScript($ssh, 'false', [], fn () => null, [], 600));
    }

    public function test_cancel_without_output_stops_the_script_and_cleans_up(): void
    {
        $ssh = new FakeSsh;
        $ssh->chunks = [true, true, true]; // the script prints nothing
        $calls = 0;

        try {
            $this->runner()->runScript($ssh, 'sleep 600', [], fn () => null, [], 1800, function () use (&$calls) {
                if (++$calls === 2) {
                    throw new DeploymentCancelled;
                }
            });
            $this->fail('expected DeploymentCancelled');
        } catch (DeploymentCancelled) {
            // expected
        }

        $this->assertTrue($ssh->wasReset);
        $n = count($ssh->execs);
        $this->assertMatchesRegularExpression("~^pkill -f '\\[/\\]root/\\.proxapps-[0-9a-f]{16}/run\\.sh'; true$~", $ssh->execs[$n - 2]);
        $this->assertStringStartsWith('rm -rf ', $ssh->execs[$n - 1]);
    }

    public function test_local_deadline_aborts_a_hanging_script(): void
    {
        $ssh = new FakeSsh;
        $ssh->chunks = array_fill(0, 50, function () {
            usleep(300_000);

            return true;
        });
        $runner = new class(new DeployerKey($this->dir.'/id_ed25519')) extends SshRunner
        {
            public const LOCAL_GRACE = 0;
        };

        try {
            $runner->runScript($ssh, 'sleep 600', [], fn () => null, [], 1);
            $this->fail('expected SshException');
        } catch (SshException $e) {
            $this->assertSame('Zeitüberschreitung beim Ausführen des Skripts (nach 1 s)', $e->getMessage());
        }
        $this->assertTrue($ssh->wasReset);
        $this->assertNotEmpty(array_filter($ssh->execs, fn ($c) => str_starts_with($c, 'pkill -f ')));
        $this->assertStringStartsWith('rm -rf ', end($ssh->execs));
    }

    public function test_remote_timeout_exit_code_is_reported_as_timeout(): void
    {
        $ssh = new FakeSsh;
        $ssh->scriptExit = 124;

        $this->expectException(SshException::class);
        $this->expectExceptionMessage('Zeitüberschreitung beim Ausführen des Skripts (nach 1 s)');
        $this->runner()->runScript($ssh, 'sleep 600', [], fn () => null, [], 1);
    }

    public function test_failed_cleanup_is_reported(): void
    {
        $ssh = new FakeSsh;
        $ssh->failOn = 'rm -rf';
        $warnings = [];

        $code = $this->runner()->runScript($ssh, 'true', [], fn () => null, [], 600, null,
            function (string $m) use (&$warnings) {
                $warnings[] = $m;
            });

        $this->assertSame(0, $code);
        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Aufräumen im Container fehlgeschlagen (/root/.proxapps-', $warnings[0]);
        $this->assertStringContainsString('Please close the channel', $warnings[0]);
    }
}
