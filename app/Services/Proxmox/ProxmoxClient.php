<?php

namespace App\Services\Proxmox;

use App\Services\Deploy\DeploymentCancelled;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Client for the Proxmox VE REST API (API token auth).
 *
 * TLS mode "pin": no CA verification, but curl compares the server's public key with the
 * stored pin during the handshake (CURLOPT_PINNEDPUBLICKEY), before the token is sent.
 * TLS mode "verify": regular CA + hostname verification.
 */
class ProxmoxClient
{
    public readonly string $base;

    public readonly string $api;

    private string $authorization;

    /** Pin presented by the server when the last pinned handshake was rejected ('' if unknown). */
    private ?string $pinMismatch = null;

    public function __construct(
        string $url,
        string $tokenId,
        string $tokenSecret,
        public readonly string $tlsMode = 'pin',
        private ?string $pin = null,
        private int $timeout = 30,
    ) {
        $this->base = Tls::normalizeUrl($url);
        $this->api = $this->base.'/api2/json';
        $this->authorization = 'PVEAPIToken='.trim($tokenId).'='.trim($tokenSecret);
        if ($tlsMode !== 'verify') {
            $this->pin = trim((string) $pin);
            if (! preg_match('~^sha256//[A-Za-z0-9+/]{43}=$~', $this->pin)) {
                throw new ProxmoxException('Kein gültiger TLS-Fingerabdruck hinterlegt.');
            }
        }
    }

    // -- low level -------------------------------------------------------------

    protected function http(): PendingRequest
    {
        $request = Http::withHeaders(['Authorization' => $this->authorization])
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout));

        if ($this->tlsMode === 'verify') {
            return $request;
        }

        return $request->withOptions([
            'verify' => false,
            'curl' => $this->pinOptions(),
        ]);
    }

    /**
     * curl options enforcing the public key pin.
     *
     * Guzzle 8 refuses CURLOPT_PINNEDPUBLICKEY (outside its curl allow-list), so the pin is
     * checked in CURLOPT_PREREQFUNCTION instead: curl calls it after the TLS handshake and
     * before the request (and the API token) is sent; aborting there sends nothing.
     *
     * @return array<int, mixed>
     */
    public function pinOptions(): array
    {
        $expected = (string) $this->pin;
        $this->pinMismatch = null;

        return [
            CURLOPT_CERTINFO => true,
            CURLOPT_PREREQFUNCTION => function ($ch) use ($expected) {
                $actual = static::peerPin($ch);
                if ($actual !== null && hash_equals($expected, $actual)) {
                    return CURL_PREREQFUNC_OK;
                }
                $this->pinMismatch = $actual ?? '';

                return CURL_PREREQFUNC_ABORT;
            },
        ];
    }

    /**
     * SPKI pin of the leaf certificate presented on the curl handle (null if unavailable).
     */
    public static function peerPin(\CurlHandle $ch): ?string
    {
        $chain = curl_getinfo($ch, CURLINFO_CERTINFO);
        $pem = is_array($chain) ? ($chain[0]['Cert'] ?? null) : null;
        if (! is_string($pem) || $pem === '') {
            return null;
        }
        try {
            return Tls::certificateInfo($pem)['pin'];
        } catch (ProxmoxException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $data
     */
    public function request(string $method, string $path, array $query = [], ?array $data = null): mixed
    {
        $url = $this->api.$path;
        $query = array_map(fn ($v) => static::formValue($v), array_filter($query, fn ($v) => $v !== null));

        try {
            $response = match ($method) {
                'GET' => $this->http()->get($url, $query),
                'POST' => $this->http()->asForm()->post($url, static::cleanForm($data ?? [])),
                'PUT' => $this->http()->asForm()->put($url, static::cleanForm($data ?? [])),
                'DELETE' => $this->http()->delete($query ? $url.'?'.http_build_query($query) : $url),
                default => throw new InvalidArgumentException($method),
            };
        } catch (ConnectionException $e) {
            throw $this->connectionError($e);
        }

        return $this->unwrap($method, $path, $response);
    }

    private function connectionError(ConnectionException $e): ProxmoxException
    {
        $msg = $e->getMessage();
        if ($this->pinMismatch !== null || str_contains($msg, 'cURL error 90') || stripos($msg, 'pinned public key') !== false) {
            return new ProxmoxException(
                'Der TLS-Fingerabdruck des Servers stimmt nicht mit dem gespeicherten überein.', null, [], $e);
        }
        if (str_contains($msg, 'cURL error 28') || stripos($msg, 'timed out') !== false) {
            return new ProxmoxException("Zeitüberschreitung bei {$this->base}", null, [], $e);
        }

        return new ProxmoxException("Verbindung zu {$this->base} fehlgeschlagen: {$msg}", null, [], $e);
    }

    private function unwrap(string $method, string $path, Response $response): mixed
    {
        $status = $response->status();
        if ($status === 401) {
            throw new ProxmoxException('Anmeldung fehlgeschlagen: Token-ID oder Secret ist falsch.', 401);
        }
        $payload = $response->json();
        if ($status >= 400) {
            $errors = is_array($payload) && is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
            $msg = $response->reason() ?: 'Fehler';
            if ($errors) {
                $msg .= ': '.implode('; ', array_map(
                    fn ($k, $v) => $k.': '.trim(is_scalar($v) ? (string) $v : json_encode($v)),
                    array_keys($errors), $errors));
            } elseif (is_array($payload) && ! empty($payload['message'])) {
                $msg .= ': '.trim((string) $payload['message']);
            }
            throw new ProxmoxException("Proxmox-API {$method} {$path} → {$status} {$msg}", $status, $errors);
        }

        return is_array($payload) && array_key_exists('data', $payload) ? $payload['data'] : $payload;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): mixed
    {
        return $this->request('POST', $path, [], $data);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function delete(string $path, array $query = []): mixed
    {
        return $this->request('DELETE', $path, $query);
    }

    // -- high level --------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return (array) $this->get('/version');
    }

    /**
     * @return list<array{node: string, status: ?string, maxcpu: mixed, cpu: mixed, maxmem: mixed, mem: mixed}>
     */
    public function nodes(): array
    {
        $nodes = array_map(fn (array $n) => [
            'node' => (string) $n['node'],
            'status' => $n['status'] ?? null,
            'maxcpu' => $n['maxcpu'] ?? null,
            'cpu' => $n['cpu'] ?? null,
            'maxmem' => $n['maxmem'] ?? null,
            'mem' => $n['mem'] ?? null,
        ], (array) $this->get('/nodes'));
        usort($nodes, fn ($a, $b) => strcmp($a['node'], $b['node']));

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clusterStatus(): array
    {
        return (array) $this->get('/cluster/status');
    }

    /**
     * @return list<array{vmid: int, node: string, type: string, name: string, status: ?string, template: bool}>
     */
    public function guests(): array
    {
        return array_values(array_map(fn (array $g) => [
            'vmid' => (int) $g['vmid'],
            'node' => (string) ($g['node'] ?? ''),
            'type' => (string) ($g['type'] ?? ''),
            'name' => (string) ($g['name'] ?? ''),
            'status' => $g['status'] ?? null,
            'template' => (bool) ($g['template'] ?? false),
        ], (array) $this->get('/cluster/resources', ['type' => 'vm'])));
    }

    public function vmidFree(int $vmid): bool
    {
        try {
            $this->get('/cluster/nextid', ['vmid' => $vmid]);

            return true;
        } catch (ProxmoxException $e) {
            if ($e->status === 400) {
                return false;
            }
            throw $e;
        }
    }

    public function nextVmid(): int
    {
        return (int) $this->get('/cluster/nextid');
    }

    /**
     * @return list<array{storage: string, type: ?string, avail: mixed, total: mixed}>
     */
    public function storages(string $node, string $content): array
    {
        $items = (array) $this->get('/nodes/'.static::q($node).'/storage', ['content' => $content, 'enabled' => 1]);
        $items = array_filter($items, fn (array $s) => (bool) ($s['active'] ?? 1));
        $out = array_map(fn (array $s) => [
            'storage' => (string) $s['storage'],
            'type' => $s['type'] ?? null,
            'avail' => $s['avail'] ?? null,
            'total' => $s['total'] ?? null,
        ], array_values($items));
        usort($out, fn ($a, $b) => strcmp($a['storage'], $b['storage']));

        return $out;
    }

    /**
     * @return list<array{iface: string, comments: string, cidr: ?string, vlan_aware: bool}>
     */
    public function bridges(string $node): array
    {
        $out = array_map(fn (array $b) => [
            'iface' => (string) $b['iface'],
            'comments' => trim((string) ($b['comments'] ?? '')),
            'cidr' => $b['cidr'] ?? null,
            'vlan_aware' => (bool) ($b['bridge_vlan_aware'] ?? false),
        ], (array) $this->get('/nodes/'.static::q($node).'/network', ['type' => 'any_bridge']));
        usort($out, fn ($a, $b) => strcmp($a['iface'], $b['iface']));

        return $out;
    }

    /**
     * @return list<array{volid: string, size: mixed}>
     */
    public function templatesOnStorage(string $node, string $storage): array
    {
        return array_values(array_map(fn (array $t) => [
            'volid' => (string) $t['volid'],
            'size' => $t['size'] ?? null,
        ], (array) $this->get('/nodes/'.static::q($node).'/storage/'.static::q($storage).'/content',
            ['content' => 'vztmpl'])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function aplinfo(string $node): array
    {
        return array_values((array) $this->get('/nodes/'.static::q($node).'/aplinfo'));
    }

    public function downloadTemplate(string $node, string $storage, string $template): string
    {
        return (string) $this->post('/nodes/'.static::q($node).'/aplinfo',
            ['storage' => $storage, 'template' => $template]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function createLxc(string $node, array $params): string
    {
        return (string) $this->post('/nodes/'.static::q($node).'/lxc', $params);
    }

    public function lxcSnapshot(string $node, int $vmid, string $name, string $description = ''): string
    {
        return (string) $this->post('/nodes/'.static::q($node)."/lxc/{$vmid}/snapshot",
            ['snapname' => $name, 'description' => $description]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lxcSnapshots(string $node, int $vmid): array
    {
        return array_values((array) $this->get('/nodes/'.static::q($node)."/lxc/{$vmid}/snapshot"));
    }

    public function lxcRollback(string $node, int $vmid, string $name, bool $start = true): string
    {
        return (string) $this->post('/nodes/'.static::q($node)."/lxc/{$vmid}/snapshot/".static::q($name).'/rollback',
            ['start' => $start]);
    }

    public function lxcDeleteSnapshot(string $node, int $vmid, string $name): string
    {
        return (string) $this->delete('/nodes/'.static::q($node)."/lxc/{$vmid}/snapshot/".static::q($name));
    }

    /**
     * Update container options (PUT /config).
     *
     * @param  array<string, mixed>  $params
     */
    public function lxcSetConfig(string $node, int $vmid, array $params): void
    {
        $this->request('PUT', '/nodes/'.static::q($node)."/lxc/{$vmid}/config", [], $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function lxcConfig(string $node, int $vmid): array
    {
        return (array) $this->get('/nodes/'.static::q($node)."/lxc/{$vmid}/config");
    }

    /**
     * @return array<string, mixed>
     */
    public function qemuConfig(string $node, int $vmid): array
    {
        return (array) $this->get('/nodes/'.static::q($node)."/qemu/{$vmid}/config");
    }

    /**
     * @return array<string, mixed>
     */
    public function lxcStatus(string $node, int $vmid): array
    {
        return (array) $this->get('/nodes/'.static::q($node)."/lxc/{$vmid}/status/current");
    }

    /**
     * @return list<array{name: ?string, inet: ?string}>
     */
    public function lxcInterfaces(string $node, int $vmid): array
    {
        return array_values(array_map(fn (array $i) => [
            'name' => $i['name'] ?? null,
            'inet' => $i['inet'] ?? null,
        ], (array) $this->get('/nodes/'.static::q($node)."/lxc/{$vmid}/interfaces")));
    }

    public function lxcAction(string $node, int $vmid, string $action): string
    {
        if (! in_array($action, ['start', 'stop', 'shutdown', 'reboot'], true)) {
            throw new InvalidArgumentException("Unbekannte Aktion '{$action}'.");
        }

        return (string) $this->post('/nodes/'.static::q($node)."/lxc/{$vmid}/status/{$action}");
    }

    public function lxcDestroy(string $node, int $vmid): string
    {
        return (string) $this->delete('/nodes/'.static::q($node)."/lxc/{$vmid}",
            ['purge' => 1, 'destroy-unreferenced-disks' => 1, 'force' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    public function taskStatus(string $node, string $upid): array
    {
        return (array) $this->get('/nodes/'.static::q($node).'/tasks/'.static::q($upid).'/status');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function taskLog(string $node, string $upid, int $start = 0, int $limit = 500): array
    {
        return array_values((array) $this->get('/nodes/'.static::q($node).'/tasks/'.static::q($upid).'/log',
            ['start' => $start, 'limit' => $limit]));
    }

    /**
     * Poll a task until it stopped, forwarding new log lines.
     *
     * @return array{ok: bool, exitstatus: string, log: list<string>}
     */
    public function waitTask(string $upid, int $timeout = 900, ?callable $onLog = null, ?callable $shouldCancel = null): array
    {
        $node = explode(':', $upid)[1] ?? '';
        $started = microtime(true);
        $seen = 0;
        $lines = [];
        $interval = (float) config('proxapps.poll_interval', 1.5);

        while (true) {
            $status = $this->taskStatus($node, $upid);
            try {
                $entries = $this->taskLog($node, $upid, $seen);
            } catch (ProxmoxException) {
                $entries = [];
            }
            foreach ($entries as $entry) {
                $n = (int) ($entry['n'] ?? 0);
                if ($n <= $seen) {
                    continue;
                }
                $seen = $n;
                $text = (string) ($entry['t'] ?? '');
                if ($text === '' || $text === 'no content') {
                    continue;
                }
                $lines[] = $text;
                if ($onLog) {
                    $onLog($text);
                }
            }
            if (($status['status'] ?? null) === 'stopped') {
                $exit = (string) ($status['exitstatus'] ?? '');

                return ['ok' => $exit === 'OK' || str_starts_with($exit, 'WARNINGS'), 'exitstatus' => $exit, 'log' => $lines];
            }
            if (microtime(true) - $started > $timeout) {
                return ['ok' => false, 'exitstatus' => 'Zeitüberschreitung', 'log' => $lines];
            }
            if ($shouldCancel && $shouldCancel()) {
                throw new DeploymentCancelled;
            }
            if ($interval > 0) {
                usleep((int) ($interval * 1_000_000));
            }
        }
    }

    // -- helpers -------------------------------------------------------------------

    public static function q(string|int $value): string
    {
        return rawurlencode((string) $value);
    }

    public static function formValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function cleanForm(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[$k] = static::formValue($v);
        }

        return $out;
    }

    /**
     * Parse Proxmox property strings like "name=eth0,bridge=vmbr0,ip=…".
     *
     * @return array<string, string>
     */
    public static function parseKv(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $out[trim($k)] = trim($v);
            }
        }

        return $out;
    }
}
