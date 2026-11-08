<?php

namespace App\Livewire\Apps;

use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\DeployFormService;
use App\Services\Deploy\Templates;
use App\Services\Ipam;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Ssh\DeployerKey;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The deploy form (Livewire port of the Python deploy page + app.js deployForm):
 * connection → nodes → storages/bridges/templates, network profile apply, IP/VMID
 * suggestions, live collision checks, recommended-resource hints, advanced options.
 */
class Deploy extends Component
{
    public const RESOURCE_KEYS = ['cores', 'memory', 'swap', 'disk'];

    /** Fields living in the collapsed "Erweitert" section. */
    public const ADVANCED_KEYS = ['unprivileged', 'nesting', 'onboot', 'tags', 'root_password', 'ssh_keys',
        'apt_upgrade', 'destroy_on_failure'];

    #[Locked]
    public string $appId = '';

    /** @var array<string, mixed> form values, same keys as DeployFormService::defaults() */
    public array $v = [];

    /** @var list<array<string, mixed>> */
    public array $nodes = [];

    /** @var array{rootdir: array, vztmpl: array, bridges: array, templates: array} */
    public array $options = ['rootdir' => [], 'vztmpl' => [], 'bridges' => [], 'templates' => []];

    public ?string $apiError = null;

    /** @var array{vmid: ?array{ok: bool, msg: string}, ip: ?array{ok: bool, msg: string}} */
    public array $checks = ['vmid' => null, 'ip' => null];

    public bool $showAdvanced = false;

    #[Locked]
    public ?int $clonedFrom = null;

    public function mount(string $app, DeployFormService $form): void
    {
        $def = app(Catalog::class)->get($app);
        abort_if(! $def, 404, 'App nicht gefunden');
        $this->appId = $def->id;

        $clone = null;
        $from = request()->query('from');
        if (is_numeric($from)) {
            $clone = Deployment::query()->where('app_id', $def->id)->find((int) $from);
            $this->clonedFrom = $clone?->id;
        }

        $v = $form->defaults($def, $clone);
        foreach (['connection_id', 'profile_id', 'vmid', 'vlan', 'prefix'] as $k) {
            $v[$k] = $v[$k] === null ? '' : (string) $v[$k];
        }
        foreach (['ip', 'gateway', 'dns', 'searchdomain', 'node', 'rootfs_storage', 'template_storage', 'template'] as $k) {
            $v[$k] = (string) ($v[$k] ?? '');
        }
        $this->v = $v;

        if ($this->v['connection_id'] !== '') {
            $this->loadNodes(true);
        }
    }

    // -- helpers -------------------------------------------------------------------

    protected function definition(): AppDefinition
    {
        $def = app(Catalog::class)->get($this->appId);
        abort_if(! $def, 404, 'App nicht gefunden');

        return $def;
    }

    protected function connection(): ?Connection
    {
        $id = $this->v['connection_id'] ?? '';

        return is_numeric($id) ? Connection::query()->find((int) $id) : null;
    }

    protected function client(int $timeout = 15): ?ProxmoxClient
    {
        return $this->connection()?->client($timeout);
    }

    protected function profile(): ?NetworkProfile
    {
        $id = $this->v['profile_id'] ?? '';

        return is_numeric($id) ? NetworkProfile::query()->find((int) $id) : null;
    }

    /** The profile only counts for static addressing. */
    protected function staticProfile(): ?NetworkProfile
    {
        return ($this->v['net_mode'] ?? '') === 'static' ? $this->profile() : null;
    }

    protected function ipam(): Ipam
    {
        return app(Ipam::class);
    }

    protected function validIp(): ?string
    {
        $ip = trim((string) ($this->v['ip'] ?? ''));

        return Ipam::isIpv4($ip) ? $ip : null;
    }

    /** @return array{local: array, downloadable: array} */
    public function templateChoices(): array
    {
        return $this->options['templates'][$this->v['template_storage'] ?? ''] ?? ['local' => [], 'downloadable' => []];
    }

    // -- reactions to field changes ---------------------------------------------------

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'v.')) {
            return;
        }
        $key = substr($property, 2);
        $this->resetErrorBag(str_starts_with($key, 'params.') ? 'param_'.substr($key, 7) : $key);

        match ($key) {
            'connection_id' => $this->loadNodes(false),
            'node' => $this->loadOptions(false),
            'template_storage' => $this->validateTemplate(),
            'profile_id' => $this->applyProfile(),
            'net_mode' => $this->onNetMode(),
            'ip' => $this->onIpChanged(),
            'vmid' => $this->check(),
            default => null,
        };
    }

    public function loadNodes(bool $keep = false): void
    {
        $this->apiError = null;
        $this->nodes = [];
        $this->options = ['rootdir' => [], 'vztmpl' => [], 'bridges' => [], 'templates' => []];
        $this->checks = ['vmid' => null, 'ip' => null];
        try {
            $client = $this->client(10);
            if (! $client) {
                return;
            }
            $this->nodes = $client->nodes();
        } catch (Throwable $e) {
            $this->apiError = $e->getMessage();

            return;
        }

        $names = array_column($this->nodes, 'node');
        if (! $keep || ! in_array($this->v['node'], $names, true)) {
            $online = array_values(array_filter($this->nodes, fn ($n) => ($n['status'] ?? null) === 'online'));
            $this->v['node'] = $online[0]['node'] ?? ($names[0] ?? '');
        }
        if ($this->v['node'] !== '') {
            $this->loadOptions($keep);
        }
    }

    public function loadOptions(bool $keep = false): void
    {
        $this->apiError = null;
        $node = (string) $this->v['node'];
        try {
            $client = $this->client(15);
            if (! $client || $node === '') {
                return;
            }
            $rootdir = $client->storages($node, 'rootdir');
            $vztmpl = $client->storages($node, 'vztmpl');
            $bridges = $client->bridges($node);
            $templates = [];
            $package = $this->definition()->template;
            foreach ($vztmpl as $s) {
                $templates[$s['storage']] = Templates::list($client, $node, $s['storage'], $package);
            }
        } catch (Throwable $e) {
            $this->apiError = $e->getMessage();

            return;
        }
        $this->options = compact('rootdir', 'vztmpl', 'bridges', 'templates');

        if (! in_array($this->v['rootfs_storage'], array_column($rootdir, 'storage'), true)) {
            $pref = collect($rootdir)->first(fn ($s) => in_array($s['type'], ['zfspool', 'lvmthin'], true)) ?? ($rootdir[0] ?? null);
            $this->v['rootfs_storage'] = $pref['storage'] ?? '';
        }
        if (! in_array($this->v['template_storage'], array_column($vztmpl, 'storage'), true)) {
            $pref = collect($vztmpl)->first(fn ($s) => $s['storage'] === 'local') ?? ($vztmpl[0] ?? null);
            $this->v['template_storage'] = $pref['storage'] ?? '';
        }
        if ($bridges && ! in_array($this->v['bridge'], array_column($bridges, 'iface'), true)) {
            $this->v['bridge'] = $bridges[0]['iface'];
        }
        $this->validateTemplate();

        if (! $keep || ($this->v['vmid'] === '' && $this->v['ip'] === '')) {
            $this->suggest();
        } else {
            $this->check();
        }
    }

    public function validateTemplate(): void
    {
        $t = $this->templateChoices();
        $cur = (string) $this->v['template'];
        $valid = $cur === ''
            || in_array($cur, array_column($t['local'], 'volid'), true)
            || in_array($cur, array_map(fn ($x) => 'download:'.$x['name'], $t['downloadable']), true);
        if (! $valid) {
            $this->v['template'] = '';
        }
    }

    public function applyProfile(): void
    {
        $p = $this->profile();
        if (! $p) {
            return; // "Manuell": keep the current values
        }
        $this->v['net_mode'] = 'static';
        $this->v['bridge'] = $p->bridge ?: $this->v['bridge'];
        $this->v['vlan'] = $p->vlan ? (string) $p->vlan : '';
        $this->v['prefix'] = (string) $p->prefix();
        $this->v['gateway'] = (string) $p->gateway;
        $this->v['dns'] = (string) $p->dns;
        $this->v['searchdomain'] = (string) $p->searchdomain;
        $this->v['firewall'] = (bool) $p->firewall;
        $this->v['ip'] = '';
        $this->resetErrorBag(['ip', 'prefix', 'gateway', 'bridge', 'vlan', 'dns', 'searchdomain']);
        $this->suggest();
    }

    protected function onNetMode(): void
    {
        if ($this->v['net_mode'] === 'static' && $this->v['ip'] === '' && $this->profile()) {
            $this->suggestIp();
        } else {
            $this->check();
        }
    }

    /** Initial suggestion: IP from the profile pool (static), VMID from the pattern or the range. */
    public function suggest(): void
    {
        try {
            $client = $this->client(20);
            $conn = $this->connection();
            if (! $client || ! $conn) {
                return;
            }
            $p = $this->staticProfile();
            $ip = null;
            if ($p) {
                $ip = $this->ipam()->suggestIp($client, $conn->id, $p);
                $this->v['ip'] = (string) $ip;
            }
            $this->v['vmid'] = (string) $this->ipam()->suggestVmid($client, $p, $ip ?: $this->staticIp(), $this->vlanValue());
            $this->resetErrorBag(['vmid', 'ip']);
        } catch (Throwable $e) {
            $this->apiError = $e->getMessage();
        }
        $this->check();
    }

    /** "Nächste freie IP". */
    public function suggestIp(): void
    {
        $p = $this->staticProfile();
        if (! $p) {
            $this->addError('ip', 'Für einen IP-Vorschlag bitte ein Netzwerkprofil wählen.');

            return;
        }
        try {
            $client = $this->client(20);
            $conn = $this->connection();
            if (! $client || ! $conn) {
                return;
            }
            $ip = $this->ipam()->suggestIp($client, $conn->id, $p);
            if (! $ip) {
                $this->addError('ip', 'Im Pool des Profils ist keine Adresse mehr frei.');

                return;
            }
            $this->v['ip'] = $ip;
            $this->resetErrorBag('ip');
            if (Ipam::effectivePattern($p)) {
                $this->v['vmid'] = (string) $this->ipam()->suggestVmid($client, $p, $ip, $this->vlanValue());
                $this->resetErrorBag('vmid');
            }
        } catch (Throwable $e) {
            $this->apiError = $e->getMessage();
        }
        $this->check();
    }

    /** "Vorschlag" next to the VMID. */
    public function suggestVmid(): void
    {
        try {
            $client = $this->client(20);
            if (! $client) {
                return;
            }
            $this->v['vmid'] = (string) $this->ipam()->suggestVmid($client, $this->staticProfile(), $this->staticIp(), $this->vlanValue());
            $this->resetErrorBag('vmid');
        } catch (Throwable $e) {
            $this->apiError = $e->getMessage();
        }
        $this->check();
    }

    /** The entered IP, only when the container gets a static address. */
    protected function staticIp(): ?string
    {
        return ($this->v['net_mode'] ?? 'static') === 'static' ? $this->validIp() : null;
    }

    protected function vlanValue(): ?int
    {
        $vlan = $this->v['vlan'] ?? null;

        return is_numeric($vlan) ? (int) $vlan : null;
    }

    protected function onIpChanged(): void
    {
        $p = $this->staticProfile();
        $ip = $this->staticIp();
        $pattern = Ipam::effectivePattern($p);
        if ($ip && $pattern) {
            // The VMID follows from the IP (e.g. 10.10.250.55 -> 250055) - computed locally,
            // so it stays in sync even when Proxmox cannot be reached right now.
            $vmid = Ipam::renderVmidPattern($pattern, $ip, $p?->vlan ?? $this->vlanValue());
            if ($vmid !== null) {
                $this->v['vmid'] = (string) $vmid;
                $this->resetErrorBag('vmid');
            } else {
                try {
                    if ($client = $this->client(20)) {
                        $this->v['vmid'] = (string) $this->ipam()->suggestVmid($client, $p, $ip, $this->vlanValue());
                        $this->resetErrorBag('vmid');
                    }
                } catch (Throwable) {
                    // keep the manual value
                }
            }
        }
        $this->check();
    }

    /** Live badges next to VMID and IP. */
    public function check(): void
    {
        $this->checks = ['vmid' => null, 'ip' => null];
        $conn = $this->connection();
        if (! $conn) {
            return;
        }
        try {
            $client = $conn->client(20);

            $vmid = trim((string) $this->v['vmid']);
            if ($vmid !== '') {
                $num = DeployFormService::toInt($vmid);
                if ($num === null || $num < 100 || $num > 999_999_999) {
                    $this->checks['vmid'] = ['ok' => false, 'msg' => 'VMID zwischen 100 und 999999999.'];
                } else {
                    $busy = Deployment::query()->active()->where('vmid', $num)->exists();
                    $free = ! $busy && $client->vmidFree($num);
                    $this->checks['vmid'] = ['ok' => $free, 'msg' => $free ? 'frei' : 'bereits vergeben'];
                }
            }

            $ip = trim((string) $this->v['ip']);
            if ($this->v['net_mode'] === 'static' && $ip !== '') {
                if (! Ipam::isIpv4($ip)) {
                    $this->checks['ip'] = ['ok' => false, 'msg' => 'keine gültige IPv4-Adresse'];
                } elseif ($owner = ($this->ipam()->usedIps($client, $conn->id)[$ip] ?? null)) {
                    $this->checks['ip'] = ['ok' => false, 'msg' => "vergeben an {$owner}"];
                } elseif (Ipam::ping($ip)) {
                    $this->checks['ip'] = ['ok' => false, 'msg' => 'antwortet auf Ping – vermutlich belegt'];
                } else {
                    $this->checks['ip'] = ['ok' => true, 'msg' => 'frei'];
                }
            }
        } catch (Throwable) {
            // transient errors: no badge
        }
    }

    public function resetResources(): void
    {
        $r = $this->definition()->resources;
        foreach (self::RESOURCE_KEYS as $k) {
            $this->v[$k] = $r[$k] ?? $this->v[$k];
            $this->resetErrorBag($k);
        }
    }

    public function setNetMode(string $mode): void
    {
        $this->v['net_mode'] = $mode === 'dhcp' ? 'dhcp' : 'static';
        $this->updated('v.net_mode');
    }

    // -- submit -----------------------------------------------------------------------

    public function submit(DeployFormService $form)
    {
        $this->resetErrorBag();
        $def = $this->definition();

        try {
            $result = $form->validate($def, $this->v);
        } catch (Throwable $e) {
            $this->addError('_form', $e->getMessage());

            return null;
        }

        if ($result['errors']) {
            foreach ($result['errors'] as $key => $message) {
                $this->addError($key, $message);
            }
            if (array_intersect(array_keys($result['errors']), self::ADVANCED_KEYS)) {
                $this->showAdvanced = true;
            }

            return null;
        }

        $deployment = $form->create($def, $result['values'], $result['spec'], $result['secrets']);

        return $this->redirectRoute('deployments.show', $deployment);
    }

    public function render()
    {
        $def = $this->definition();
        $profiles = NetworkProfile::ordered();
        $connections = Connection::query()->orderBy('name')->get(['id', 'name', 'url']);

        $ctx = array_merge($this->v['params'] ?? [], [
            'ip' => $this->v['ip'] ?: '<IP>',
            'hostname' => $this->v['hostname'],
            'vmid' => $this->v['vmid'],
        ]);
        $ports = array_map(fn ($p) => [
            'port' => Catalog::render((string) ($p['port'] ?? ''), $ctx),
            'proto' => $p['proto'] ?? 'tcp',
            'label' => $p['label'] ?? '',
        ], $def->ports);

        try {
            $pubkey = app(DeployerKey::class)->publicKey();
        } catch (Throwable) {
            $pubkey = '';
        }

        return view('livewire.apps.deploy', [
            'app' => $def,
            'profiles' => $profiles,
            'connections' => $connections,
            'currentConnection' => $connections->firstWhere('id', (int) $this->v['connection_id']),
            'currentProfile' => $profiles->firstWhere('id', (int) $this->v['profile_id']),
            'vmidPattern' => ($this->v['net_mode'] ?? '') === 'static' ? Ipam::effectivePattern($this->staticProfile()) : null,
            'templateChoices' => $this->templateChoices(),
            'ports' => $ports,
            'pubkey' => $pubkey,
        ])->title($def->name.' ausrollen');
    }
}
