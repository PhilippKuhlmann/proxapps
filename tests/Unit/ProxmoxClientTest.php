<?php

namespace Tests\Unit;

use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Services\Proxmox\Tls;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Concerns\FakesProxmox;

class ProxmoxClientTest extends TestCase
{
    use FakesProxmox;

    private function client(): ProxmoxClient
    {
        return new ProxmoxClient('pve.test', 'proxapps@pve!deploy', 'secret-uuid', 'pin',
            'sha256//'.base64_encode(str_repeat("\x02", 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['proxapps.poll_interval' => 0]);
    }

    public function test_parse_kv(): void
    {
        $this->assertSame(
            ['name' => 'eth0', 'bridge' => 'vmbr0', 'ip' => '192.0.2.5/24', 'gw' => '192.0.2.1'],
            ProxmoxClient::parseKv('name=eth0, bridge=vmbr0,ip=192.0.2.5/24,gw=192.0.2.1,garbage'));
        $this->assertSame([], ProxmoxClient::parseKv(''));
        $this->assertSame(['a' => 'b=c'], ProxmoxClient::parseKv('a=b=c'));
    }

    public function test_tls_normalize_url(): void
    {
        $this->assertSame('https://192.0.2.10:8006', Tls::normalizeUrl(' 192.0.2.10 '));
        $this->assertSame('https://pve.example.test:443', Tls::normalizeUrl('https://PVE.example.test:443/'));
        $this->assertSame('https://pve.test:8006', Tls::normalizeUrl('http://pve.test'));
        $this->assertSame('https://[::1]:8006', Tls::normalizeUrl('[::1]'));

        $this->expectException(ProxmoxException::class);
        $this->expectExceptionMessage('Ungültige Adresse.');
        Tls::normalizeUrl('');
    }

    public function test_tls_fingerprint_helpers(): void
    {
        $this->assertSame('AABBCC01', Tls::normalizeFingerprint('aa:bb cc-01 zz'));
        $this->assertSame('AA:BB:CC:01', Tls::formatFingerprint('aabbcc01'));
        $this->assertSame('', Tls::formatFingerprint(''));
    }

    public function test_certificate_info_computes_fingerprint_and_spki_pin(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $make = function () use ($key) {
            $csr = openssl_csr_new(['commonName' => 'pve.test'], $key);

            return openssl_csr_sign($csr, null, $key, 1, [], random_int(1, PHP_INT_MAX));
        };
        $a = Tls::certificateInfo($make());
        $b = Tls::certificateInfo($make());

        $this->assertMatchesRegularExpression('~^([0-9A-F]{2}:){31}[0-9A-F]{2}$~', $a['fingerprint']);
        $this->assertMatchesRegularExpression('~^sha256//[A-Za-z0-9+/]{43}=$~', $a['pin']);
        // same key => same pin, different certificate => different fingerprint
        $this->assertSame($a['pin'], $b['pin']);
        $this->assertNotSame($a['fingerprint'], $b['fingerprint']);

        $spki = Tls::pemToDer(openssl_pkey_get_details($key)['key']);
        $this->assertSame('sha256//'.base64_encode(hash('sha256', $spki, true)), $a['pin']);
    }

    public function test_pin_is_enforced_before_the_request_is_sent(): void
    {
        $opts = $this->client()->pinOptions();

        $this->assertTrue($opts[CURLOPT_CERTINFO]);
        $this->assertIsCallable($opts[CURLOPT_PREREQFUNCTION]);
        $this->assertArrayNotHasKey(CURLOPT_PINNEDPUBLICKEY, $opts); // rejected by Guzzle 8's allow-list
    }

    public function test_pin_mode_requires_a_valid_pin(): void
    {
        $this->expectException(ProxmoxException::class);
        $this->expectExceptionMessage('Kein gültiger TLS-Fingerabdruck hinterlegt.');
        new ProxmoxClient('pve.test', 'id', 'secret', 'pin', 'AA:BB');
    }

    public function test_unwraps_data_and_sends_token(): void
    {
        $this->fakeProxmox();
        $c = $this->client();

        $this->assertSame(['version' => '9.0.3', 'release' => '9.0'], $c->version());
        $this->assertSame(['pve1', 'pve2'], array_column($c->nodes(), 'node'));
        $this->assertSame(['local-lvm'], array_column($c->storages('pve1', 'rootdir'), 'storage'));
        $bridges = $c->bridges('pve1');
        $this->assertSame(['vmbr0', 'vmbr1'], array_column($bridges, 'iface'));
        $this->assertTrue($bridges[1]['vlan_aware']);
        $this->assertSame('LAN', $bridges[1]['comments']);
        $guests = $c->guests();
        $this->assertSame(101, $guests[0]['vmid']);
        $this->assertTrue($guests[2]['template']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'PVEAPIToken=proxapps@pve!deploy=secret-uuid')
            && str_starts_with($r->url(), 'https://pve.test:8006/api2/json/version'));
    }

    public function test_401_has_a_clear_message(): void
    {
        $this->fakeProxmox(['GET /version' => fn () => Http::response('', 401)]);

        try {
            $this->client()->version();
            $this->fail('expected exception');
        } catch (ProxmoxException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('Anmeldung fehlgeschlagen: Token-ID oder Secret ist falsch.', $e->getMessage());
        }
    }

    public function test_error_message_contains_parameter_errors(): void
    {
        $this->fakeProxmox(['POST /nodes/pve1/lxc' => fn () => Http::response(
            ['data' => null, 'errors' => ['hostname' => "invalid format\n"]], 400)]);

        try {
            $this->client()->createLxc('pve1', ['hostname' => 'x_y', 'nameserver' => null, 'start' => false]);
            $this->fail('expected exception');
        } catch (ProxmoxException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame(['hostname' => "invalid format\n"], $e->errors);
            $this->assertSame('Proxmox-API POST /nodes/pve1/lxc → 400 Bad Request: hostname: invalid format', $e->getMessage());
        }
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->body() === 'hostname=x_y&start=0');
    }

    public function test_vmid_free_maps_400_to_false(): void
    {
        $this->fakeProxmox();
        $c = $this->client();

        $this->assertFalse($c->vmidFree(101));
        $this->assertTrue($c->vmidFree(250089));
        $this->assertSame(103, $c->nextVmid());
    }

    public function test_destroy_sends_purge_flags(): void
    {
        $this->fakeProxmox(['DELETE /nodes/pve1/lxc/150' => 'UPID:pve1:destroy']);

        $this->assertSame('UPID:pve1:destroy', $this->client()->lxcDestroy('pve1', 150));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), 'purge=1') && str_contains($r->url(), 'destroy-unreferenced-disks=1')
            && str_contains($r->url(), 'force=1'));
    }

    public function test_wait_task_pages_through_log_and_reports_ok(): void
    {
        $upid = 'UPID:pve1:0001:0002:0003:vzcreate:150:root@pam!deploy:';
        $statusCalls = 0;
        $logStarts = [];
        $this->fakeProxmox([
            'GET /nodes/pve1/tasks/'.$upid.'/status' => function () use (&$statusCalls) {
                $statusCalls++;

                return Http::response(['data' => $statusCalls < 2
                    ? ['status' => 'running']
                    : ['status' => 'stopped', 'exitstatus' => 'OK']]);
            },
            'GET /nodes/pve1/tasks/'.$upid.'/log' => function (Request $r, array $q) use (&$logStarts) {
                $logStarts[] = (int) $q['start'];

                return Http::response(['data' => (int) $q['start'] === 0
                    ? [['n' => 1, 't' => 'extracting archive'], ['n' => 2, 't' => 'no content']]
                    : [['n' => 2, 't' => 'no content'], ['n' => 3, 't' => 'TASK OK']]]);
            },
        ]);

        $seen = [];
        $res = $this->client()->waitTask($upid, 60, function (string $line) use (&$seen) {
            $seen[] = $line;
        });

        $this->assertTrue($res['ok']);
        $this->assertSame('OK', $res['exitstatus']);
        $this->assertSame(['extracting archive', 'TASK OK'], $res['log']);
        $this->assertSame($res['log'], $seen);
        $this->assertSame([0, 2], $logStarts);
    }

    public function test_wait_task_failure_and_warnings(): void
    {
        $status = ['status' => 'stopped', 'exitstatus' => 'command failed: exit code 1'];
        $this->fakeProxmox([
            'GET /nodes/pve1/tasks/UPID:pve1:x/status' => function () use (&$status) {
                return Http::response(['data' => $status]);
            },
            'GET /nodes/pve1/tasks/UPID:pve1:x/log' => fn () => Http::response('', 500),
        ]);

        $res = $this->client()->waitTask('UPID:pve1:x');
        $this->assertFalse($res['ok']);
        $this->assertSame('command failed: exit code 1', $res['exitstatus']);

        $status = ['status' => 'stopped', 'exitstatus' => 'WARNINGS: 2'];
        $this->assertTrue($this->client()->waitTask('UPID:pve1:x')['ok']);
    }
}
