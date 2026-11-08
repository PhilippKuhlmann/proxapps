<?php

namespace Tests\Unit\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Routes faked Proxmox API calls (host pve.test) to canned responses.
 */
trait FakesProxmox
{
    /**
     * @param  array<string, mixed>  $overrides  "METHOD /path" or "METHOD /path?query" => data|callable(Request, array $query)
     */
    protected function fakeProxmox(array $overrides = []): void
    {
        $routes = array_merge([
            'GET /version' => ['version' => '9.0.3', 'release' => '9.0'],
            'GET /nodes' => [
                ['node' => 'pve2', 'status' => 'offline', 'maxcpu' => 4, 'cpu' => 0, 'maxmem' => 1, 'mem' => 0],
                ['node' => 'pve1', 'status' => 'online', 'maxcpu' => 8, 'cpu' => 0.1, 'maxmem' => 2, 'mem' => 1],
            ],
            'GET /nodes/pve1/storage?content=rootdir' => [
                ['storage' => 'local-lvm', 'type' => 'lvmthin', 'active' => 1, 'avail' => 10, 'total' => 20],
                ['storage' => 'broken', 'type' => 'dir', 'active' => 0],
            ],
            'GET /nodes/pve1/storage?content=vztmpl' => [
                ['storage' => 'local', 'type' => 'dir', 'active' => 1],
            ],
            'GET /nodes/pve1/network' => [
                ['iface' => 'vmbr1', 'comments' => "LAN\n", 'bridge_vlan_aware' => 1],
                ['iface' => 'vmbr0', 'cidr' => '192.0.2.10/24'],
            ],
            'GET /cluster/resources' => [
                ['vmid' => 101, 'node' => 'pve1', 'type' => 'lxc', 'name' => 'dns', 'status' => 'running', 'template' => 0],
                ['vmid' => 102, 'node' => 'pve1', 'type' => 'qemu', 'name' => 'vm', 'status' => 'running'],
                ['vmid' => 900, 'node' => 'pve1', 'type' => 'lxc', 'name' => 'tpl', 'template' => 1],
            ],
            'GET /nodes/pve1/lxc/101/config' => [
                'hostname' => 'dns', 'net0' => 'name=eth0,bridge=vmbr0,ip=192.0.2.20/24,gw=192.0.2.1',
                'net1' => 'name=eth1,bridge=vmbr0,ip=dhcp',
            ],
            'GET /nodes/pve1/qemu/102/config' => ['ipconfig0' => 'ip=192.0.2.21/24,gw=192.0.2.1'],
            'GET /cluster/nextid' => function (Request $r, array $q) {
                $taken = [101, 102, 900];
                if (isset($q['vmid']) && in_array((int) $q['vmid'], $taken, true)) {
                    return Http::response(['data' => null, 'errors' => ['vmid' => "VM {$q['vmid']} already exists"]], 400);
                }

                return Http::response(['data' => (string) ($q['vmid'] ?? 103)]);
            },
        ], $overrides);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($routes) {
            $parts = parse_url($request->url());
            if (($parts['host'] ?? '') !== 'pve.test') {
                return null;
            }
            $path = rawurldecode(substr($parts['path'] ?? '', strlen('/api2/json')));
            parse_str($parts['query'] ?? '', $query);
            $method = $request->method();

            $candidates = [];
            if (isset($query['content'])) {
                $candidates[] = "{$method} {$path}?content={$query['content']}";
            }
            $candidates[] = "{$method} {$path}";
            foreach ($candidates as $key) {
                if (array_key_exists($key, $routes)) {
                    $route = $routes[$key];
                    if ($route instanceof \Closure) {
                        return $route($request, $query);
                    }

                    return Http::response(['data' => $route]);
                }
            }

            return Http::response(['data' => null], 501);
        });
    }
}
