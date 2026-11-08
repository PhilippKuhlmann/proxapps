<?php

namespace App\Services\Deploy;

use App\Jobs\RunDeployment;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Catalog\AppDefinition;
use App\Services\Ipam;
use App\Services\Proxmox\ProxmoxException;
use App\Support\Core;
use App\Support\Settings;

/**
 * Defaults, validation and creation for the deploy form (shared by the Livewire component).
 */
class DeployFormService
{
    public const STEPS = [
        ['validate', 'Prüfungen'],
        ['template', 'Debian-Vorlage'],
        ['create', 'Container anlegen'],
        ['start', 'Container starten'],
        ['network', 'Netzwerk & SSH'],
        ['base', 'Grundsystem'],
        ['install', 'App installieren'],
        ['verify', 'Funktionsprüfung'],
    ];

    public const HOSTNAME_RE = '~^(?=.{1,253}$)[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$~D';

    public const SSH_KEY_RE = '~^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp\d+|sk-[a-z0-9@.-]+) [A-Za-z0-9+/=]+( .*)?$~D';

    public const RESOURCE_LIMITS = [
        'cores' => [1, 512],
        'memory' => [64, 4_194_304],
        'swap' => [0, 4_194_304],
        'disk' => [1, 65_536],
    ];

    public function __construct(private Ipam $ipam) {}

    /**
     * Initial form values (like pyref _default_deploy_values), optionally cloned from an earlier deployment.
     *
     * @return array<string, mixed>
     */
    public function defaults(AppDefinition $app, ?Deployment $cloneFrom = null): array
    {
        $settings = Settings::all();
        $prof = NetworkProfile::ordered()->first();
        $conn = Connection::query()->orderBy('name')->first(['id']);

        $params = [];
        foreach ($app->parameters as $p) {
            $params[$p['key']] = $p['type'] === 'password' ? '' : ($p['default'] ?? '');
        }

        $values = [
            'connection_id' => $conn ? $conn->id : '',
            'node' => '',
            'hostname' => $app->hostname,
            'vmid' => '',
            'cores' => $app->resources['cores'],
            'memory' => $app->resources['memory'],
            'swap' => $app->resources['swap'],
            'disk' => $app->resources['disk'],
            'rootfs_storage' => '',
            'template_storage' => '',
            'template' => '',
            'profile_id' => $prof ? $prof->id : '',
            'net_mode' => $prof ? 'static' : 'dhcp',
            'ip' => '',
            'prefix' => $prof ? $prof->prefix() : 24,
            'gateway' => $prof?->gateway ?: '',
            'bridge' => $prof?->bridge ?: 'vmbr0',
            'vlan' => $prof?->vlan ?: '',
            'dns' => $prof?->dns ?: '',
            'searchdomain' => $prof?->searchdomain ?: '',
            'firewall' => (bool) $prof?->firewall,
            'unprivileged' => (bool) ($app->lxc['unprivileged'] ?? true),
            'nesting' => (bool) ($app->lxc['nesting'] ?? true),
            'onboot' => (bool) ($app->lxc['onboot'] ?? true),
            'tags' => (string) ($settings['default_tags'] ?? ''),
            'root_password' => '',
            'ssh_keys' => (string) ($settings['authorized_keys'] ?? ''),
            'apt_upgrade' => (bool) ($settings['apt_upgrade'] ?? true),
            'destroy_on_failure' => (bool) ($settings['destroy_on_failure'] ?? false),
            'params' => $params,
            // PK95 Core links the app to the proxy: subdomain suggestion from the catalog.
            'subdomain' => $app->proxy ? (string) ($app->proxy['subdomain'] ?? $app->hostname) : '',
            // Off: PK95 Core creates no proxy host (only the internal monitor).
            'proxy' => (bool) $app->proxy,
        ];

        if ($cloneFrom && $cloneFrom->app_id === $app->id) {
            $spec = (array) $cloneFrom->spec;
            $net = (array) ($spec['net'] ?? []);
            foreach (['node', 'hostname', 'vmid', 'cores', 'memory', 'swap', 'disk', 'rootfs_storage',
                'template_storage', 'template', 'unprivileged', 'nesting', 'onboot', 'tags', 'ssh_keys',
                'apt_upgrade', 'destroy_on_failure'] as $k) {
                if (array_key_exists($k, $spec)) {
                    $values[$k] = $spec[$k];
                }
            }
            $values = array_merge($values, [
                'connection_id' => $cloneFrom->connection_id ?? '',
                'net_mode' => $net['mode'] ?? 'static',
                'ip' => $net['ip'] ?? '',
                'prefix' => $net['prefix'] ?? 24,
                'gateway' => $net['gateway'] ?? '',
                'bridge' => $net['bridge'] ?? 'vmbr0',
                'vlan' => $net['vlan'] ?? '',
                'dns' => $net['dns'] ?? '',
                'searchdomain' => $net['searchdomain'] ?? '',
                'firewall' => (bool) ($net['firewall'] ?? false),
                'profile_id' => $spec['profile_id'] ?? '',
            ]);
            foreach ((array) ($spec['params'] ?? []) as $k => $v) {
                $values['params'][$k] = $v;
            }
            if (array_key_exists('subdomain', $spec)) {
                $values['subdomain'] = (string) $spec['subdomain'];
            }
            if (array_key_exists('proxy', $spec)) {
                $values['proxy'] = (bool) $spec['proxy'];
            }
        }

        return $values;
    }

    /**
     * Validate the submitted form (port of pyref _validate_deploy).
     *
     * App parameters are read from "param_<KEY>" or, if absent, from the nested "params" array.
     *
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, errors: array<string, string>, spec: array<string, mixed>, secrets: array{root_password: string, params: array<string, string>}}
     */
    public function validate(AppDefinition $app, array $input): array
    {
        $errors = [];
        $str = fn (string $k, string $default = '') => array_key_exists($k, $input) && $input[$k] !== null
            ? (is_bool($input[$k]) ? ($input[$k] ? '1' : '') : (string) $input[$k]) : $default;

        $v = [
            'connection_id' => static::toInt($input['connection_id'] ?? null),
            'node' => $str('node'),
            'hostname' => strtolower($str('hostname')),
            'vmid' => $str('vmid'),
            'cores' => $str('cores'),
            'memory' => $str('memory'),
            'swap' => $str('swap'),
            'disk' => $str('disk'),
            'rootfs_storage' => $str('rootfs_storage'),
            'template_storage' => $str('template_storage'),
            'template' => $str('template'),
            'profile_id' => static::toInt($input['profile_id'] ?? null) ?? '',
            'net_mode' => $str('net_mode', 'static'),
            'ip' => $str('ip'),
            'prefix' => $str('prefix'),
            'gateway' => $str('gateway'),
            'bridge' => $str('bridge'),
            'vlan' => $str('vlan'),
            'dns' => $str('dns'),
            'searchdomain' => $str('searchdomain'),
            'firewall' => static::checkbox($input['firewall'] ?? null),
            'unprivileged' => static::checkbox($input['unprivileged'] ?? null),
            'nesting' => static::checkbox($input['nesting'] ?? null),
            'onboot' => static::checkbox($input['onboot'] ?? null),
            'tags' => $str('tags'),
            'root_password' => '',
            'ssh_keys' => $str('ssh_keys'),
            'apt_upgrade' => static::checkbox($input['apt_upgrade'] ?? null),
            'destroy_on_failure' => static::checkbox($input['destroy_on_failure'] ?? null),
            'params' => [],
        ];
        $rootPassword = $str('root_password');

        // resources
        foreach (self::RESOURCE_LIMITS as $key => [$lo, $hi]) {
            $num = static::toInt($v[$key]);
            if ($num === null || $num < $lo || $num > $hi) {
                $errors[$key] = "Wert zwischen {$lo} und {$hi}.";
            } else {
                $v[$key] = $num;
            }
        }
        if (! preg_match(self::HOSTNAME_RE, $v['hostname'])) {
            $errors['hostname'] = 'Gültiger Hostname: Buchstaben, Ziffern und Bindestriche.';
        }
        $vmid = static::toInt($v['vmid']);
        if ($vmid === null || $vmid < 100 || $vmid > 999_999_999) {
            $errors['vmid'] = 'VMID zwischen 100 und 999999999.';
        } else {
            $v['vmid'] = $vmid;
        }
        if ($rootPassword !== '' && mb_strlen($rootPassword) < 5) {
            $errors['root_password'] = 'Mindestens 5 Zeichen (oder leer lassen: nur SSH-Schlüssel).';
        }
        foreach (preg_split('~\R~', $v['ssh_keys']) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#') && ! preg_match(self::SSH_KEY_RE, $line)) {
                $errors['ssh_keys'] = 'Mindestens eine Zeile ist kein gültiger öffentlicher SSH-Schlüssel.';
                break;
            }
        }

        // network
        if (! in_array($v['net_mode'], ['static', 'dhcp'], true)) {
            $v['net_mode'] = 'static';
        }
        if (! preg_match('~^[A-Za-z0-9_.-]{1,32}$~D', $v['bridge'])) {
            $errors['bridge'] = 'Bitte eine Bridge wählen.';
        }
        if ($v['vlan'] !== '') {
            $vlan = static::toInt($v['vlan']);
            if ($vlan === null || $vlan < 1 || $vlan > 4094) {
                $errors['vlan'] = 'VLAN-ID 1–4094 oder leer.';
            } else {
                $v['vlan'] = $vlan;
            }
        } else {
            $v['vlan'] = null;
        }
        if ($v['net_mode'] === 'static') {
            if (! Ipam::isIpv4($v['ip'])) {
                $errors['ip'] = 'Bitte eine gültige IPv4-Adresse angeben.';
            }
            $prefix = static::toInt($v['prefix']);
            if ($prefix === null || $prefix < 1 || $prefix > 32) {
                $errors['prefix'] = 'Präfix 1–32.';
            } else {
                $v['prefix'] = $prefix;
            }
            if ($v['gateway'] !== '') {
                if (! Ipam::isIpv4($v['gateway'])) {
                    $errors['gateway'] = 'Keine gültige IPv4-Adresse.';
                } elseif (! isset($errors['ip']) && ! isset($errors['prefix'])) {
                    $cidr = "{$v['ip']}/{$v['prefix']}";
                    if (! Ipam::inNetwork($v['gateway'], $cidr)) {
                        $errors['gateway'] = 'Das Gateway liegt nicht im Netz '.Ipam::networkString($cidr).'.';
                    } elseif ($v['gateway'] === $v['ip']) {
                        $errors['ip'] = 'IP und Gateway sind identisch.';
                    }
                }
            }
        } else {
            $v['prefix'] = static::toInt($v['prefix']) ?? 24;
        }
        $profile = $v['profile_id'] !== '' ? NetworkProfile::query()->find($v['profile_id']) : null;
        if ($v['net_mode'] === 'static' && $profile && ! isset($errors['ip'])
            && ($ipError = Ipam::validateIpInProfile($v['ip'], $profile)) !== null) {
            $errors['ip'] = $ipError;
        }
        $dns = array_values(array_filter(preg_split('~[\s,;]+~', $v['dns']), fn ($d) => $d !== ''));
        $dnsOk = true;
        foreach ($dns as $d) {
            if (filter_var($d, FILTER_VALIDATE_IP) === false) {
                $dnsOk = false;
                break;
            }
        }
        if ($dnsOk) {
            $v['dns'] = implode(' ', $dns);
        } else {
            $errors['dns'] = 'DNS-Server als IP-Adressen, durch Leerzeichen getrennt.';
        }
        if ($v['searchdomain'] !== '' && ! preg_match(self::HOSTNAME_RE, $v['searchdomain'])) {
            $errors['searchdomain'] = 'Keine gültige Domain.';
        }

        // app parameters
        $params = [];
        $secretParams = [];
        $nested = is_array($input['params'] ?? null) ? $input['params'] : [];
        foreach ($app->parameters as $p) {
            $key = $p['key'];
            $field = "param_{$key}";
            $raw = array_key_exists($field, $input) ? $input[$field] : ($nested[$key] ?? null);
            if ($p['type'] === 'boolean') {
                $val = static::checkbox($raw) ? '1' : '0';
            } else {
                $val = $raw === null ? '' : (is_bool($raw) ? ($raw ? '1' : '') : (string) $raw);
            }
            if ($p['type'] === 'password' && $val === '' && $p['generate']) {
                $val = static::generatePassword();
            }
            if ($p['required'] && $val === '') {
                $errors[$field] = 'Pflichtfeld.';
            } elseif ($val !== '') {
                if ($p['type'] === 'number') {
                    $num = static::toInt($val);
                    if ($num === null || ($p['min'] !== null && $num < $p['min']) || ($p['max'] !== null && $num > $p['max'])) {
                        $errors[$field] = 'Zahl zwischen '.static::pyStr($p['min']).' und '.static::pyStr($p['max']).'.';
                    } else {
                        $val = (string) $num;
                    }
                }
                if ($p['min_length'] && mb_strlen($val) < $p['min_length']) {
                    $errors[$field] = "Mindestens {$p['min_length']} Zeichen.";
                }
                if ($p['pattern'] !== null && $p['pattern'] !== '' && ! static::fullMatch($p['pattern'], $val)) {
                    $errors[$field] = 'Ungültiges Format.';
                }
                if ($p['type'] === 'select' && $p['options']
                    && ! in_array($val, array_map(fn ($o) => (string) ($o['value'] ?? ''), $p['options']), true)) {
                    $errors[$field] = 'Ungültige Auswahl.';
                }
            }
            if ($p['type'] === 'password') {
                $secretParams[$key] = $val;
                $v['params'][$key] = '';
            } else {
                $params[$key] = $val;
                $v['params'][$key] = $val;
            }
        }

        // Proxmox-side checks
        $connection = $v['connection_id'] !== null ? Connection::query()->find($v['connection_id']) : null;
        if (! $connection) {
            $errors['connection_id'] = 'Bitte eine Proxmox-Verbindung wählen.';
        } else {
            try {
                $client = $connection->client(15);
                $nodes = [];
                foreach ($client->nodes() as $n) {
                    $nodes[$n['node']] = $n;
                }
                if (! isset($nodes[$v['node']])) {
                    $errors['node'] = 'Bitte einen Node wählen.';
                } elseif (($nodes[$v['node']]['status'] ?? null) !== 'online') {
                    $errors['node'] = 'Der Node ist nicht online.';
                } else {
                    $node = $v['node'];
                    $rootdir = array_column($client->storages($node, 'rootdir'), 'storage');
                    $vztmpl = array_column($client->storages($node, 'vztmpl'), 'storage');
                    $bridges = array_column($client->bridges($node), 'iface');
                    if (! in_array($v['rootfs_storage'], $rootdir, true)) {
                        $errors['rootfs_storage'] = 'Speicher unterstützt keine Container-Disks.';
                    }
                    if (! in_array($v['template_storage'], $vztmpl, true)) {
                        $errors['template_storage'] = 'Speicher unterstützt keine Vorlagen.';
                    }
                    if (! isset($errors['bridge']) && ! in_array($v['bridge'], $bridges, true)) {
                        $errors['bridge'] = "Bridge {$v['bridge']} gibt es auf {$node} nicht.";
                    }
                }
                if (! isset($errors['vmid'])) {
                    $busy = Deployment::query()->active()->where('vmid', $v['vmid'])->exists();
                    if ($busy || ! $client->vmidFree($v['vmid'])) {
                        $errors['vmid'] = "Die VMID {$v['vmid']} ist bereits vergeben.";
                    }
                }
                if ($v['net_mode'] === 'static' && ! isset($errors['ip'])) {
                    $owner = $this->ipam->usedIps($client, $connection->id, true)[$v['ip']] ?? null;
                    if ($owner) {
                        $errors['ip'] = "Bereits vergeben an {$owner}.";
                    }
                }
            } catch (ProxmoxException $e) {
                $errors['_form'] = $e->getMessage();
            }
        }

        // The VMID follows from the IP whenever a pattern applies (10.10.250.55 → 250055).
        if ($v['net_mode'] === 'static' && ! isset($errors['ip']) && ! isset($errors['vmid']) && ! isset($errors['vlan'])) {
            $vmidError = static::vmidPatternError($profile, $v['ip'], (int) $v['vmid'], is_int($v['vlan']) ? $v['vlan'] : null);
            if ($vmidError !== null) {
                $errors['vmid'] = $vmidError;
            }
        }

        // Domain for PK95 Core (reverse proxy + monitoring); only apps with a proxy target.
        $v['subdomain'] = $app->proxy ? strtolower(trim($str('subdomain'))) : '';
        $v['proxy'] = $app->proxy && static::checkbox($input['proxy'] ?? null);
        $domain = '';
        if ($v['proxy'] && $v['subdomain'] !== '') {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $v['subdomain'])) {
                $errors['subdomain'] = 'Nur Kleinbuchstaben, Ziffern, Punkte und Bindestriche.';
            } else {
                $domain = Core::domainFor($v['subdomain'], (string) (Core::context()['base_domain'] ?? ''));
                $param = (string) ($app->proxy['domain_param'] ?? '');
                if ($domain !== '' && $param !== '' && array_key_exists($param, $params) && trim((string) $params[$param]) === '') {
                    $params[$param] = 'https://'.$domain;
                }
            }
        }

        $static = $v['net_mode'] === 'static';
        $spec = [
            'app_id' => $app->id,
            'node' => $v['node'],
            'vmid' => $v['vmid'],
            'hostname' => $v['hostname'],
            'cores' => $v['cores'],
            'memory' => $v['memory'],
            'swap' => $v['swap'],
            'disk' => $v['disk'],
            'rootfs_storage' => $v['rootfs_storage'],
            'template_storage' => $v['template_storage'],
            'template' => $v['template'],
            'profile_id' => $v['profile_id'] ?: null,
            'net' => [
                'mode' => $v['net_mode'],
                'ip' => $static ? $v['ip'] : null,
                'prefix' => $v['prefix'],
                'gateway' => $static ? $v['gateway'] : null,
                'bridge' => $v['bridge'],
                'vlan' => $v['vlan'],
                'dns' => $v['dns'],
                'searchdomain' => $v['searchdomain'],
                'firewall' => $v['firewall'],
            ],
            'unprivileged' => $v['unprivileged'],
            'nesting' => $v['nesting'],
            'onboot' => $v['onboot'],
            'tags' => $v['tags'],
            'ssh_keys' => $v['ssh_keys'],
            'apt_upgrade' => $v['apt_upgrade'],
            'destroy_on_failure' => $v['destroy_on_failure'],
            'timezone' => Settings::get('timezone'),
            'pool' => $connection?->pool ?: null,
            'params' => $params,
            'subdomain' => $v['subdomain'],
            'proxy' => $v['proxy'],
            'domain' => $domain,
        ];
        $secrets = ['root_password' => $rootPassword, 'params' => $secretParams];

        return ['values' => $v, 'errors' => $errors, 'spec' => $spec, 'secrets' => $secrets];
    }

    /**
     * Insert the deployment, write the first log line and queue the runner.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $secrets
     */
    public function create(AppDefinition $app, array $values, array $spec, array $secrets): Deployment
    {
        $d = Deployment::query()->create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'connection_id' => $values['connection_id'],
            'node' => $spec['node'],
            'vmid' => $spec['vmid'],
            'hostname' => $spec['hostname'],
            'ip' => $spec['net']['ip'] ?? null,
            'status' => 'queued',
            'step' => 'validate',
            'spec' => $spec,
            'secrets' => $secrets,
        ]);
        DeploymentLogger::log($d,
            "Deployment von {$app->name} als CT {$spec['vmid']} auf {$spec['node']} eingeplant.", 'info', 'validate');
        RunDeployment::dispatch($d->id);

        return $d;
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * With a static IP and an applicable VMID pattern (profile pattern or global default) the
     * VMID must be the one the pattern yields for the IP. Null = fine (or no pattern applies).
     */
    public static function vmidPatternError(?NetworkProfile $profile, string $ip, int $vmid, ?int $vlan): ?string
    {
        $pattern = Ipam::effectivePattern($profile);
        if ($pattern === null) {
            return null;
        }
        $expected = Ipam::renderVmidPattern($pattern, $ip, $profile?->vlan ?? $vlan);
        if ($expected === null) {
            return "Aus der IP {$ip} ergibt das Muster {$pattern} keine gültige VMID.";
        }

        return $expected === $vmid ? null : "Die VMID muss {$expected} sein (passend zur IP {$ip}).";
    }

    public static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (! preg_match('~^[+-]?\d{1,18}$~D', $s)) {
            return null;
        }

        return (int) $s;
    }

    public static function checkbox(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(is_string($value) ? $value : '', ['1', 'on', 'true', 'yes'], true);
    }

    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        while (true) {
            $pw = '';
            for ($i = 0; $i < $length; $i++) {
                $pw .= $alphabet[random_int(0, $max)];
            }
            if (preg_match('~[a-z]~', $pw) && preg_match('~[A-Z]~', $pw) && preg_match('~\d~', $pw)) {
                return $pw;
            }
        }
    }

    /**
     * Python-style re.fullmatch for catalog-supplied patterns.
     */
    public static function fullMatch(string $pattern, string $value): bool
    {
        $regex = '~^(?:'.str_replace('~', '\~', $pattern).')$~Du';

        return @preg_match($regex, $value) === 1;
    }

    private static function pyStr(?int $v): string
    {
        return $v === null ? 'None' : (string) $v;
    }
}
