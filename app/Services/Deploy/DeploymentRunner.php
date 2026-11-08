<?php

namespace App\Services\Deploy;

use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Ipam;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Services\Ssh\DeployerKey;
use App\Services\Ssh\SshException;
use App\Services\Ssh\SshRunner;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Deployment engine: turns a deployment spec into a running, provisioned LXC container.
 */
class DeploymentRunner
{
    private Deployment $d;

    private string $step = 'validate';

    private float $lastCancelCheck = 0.0;

    /** Absolute end (microtime) of the whole run; every step limit is clamped to it. */
    private float $deadline = 0.0;

    /** Whether the ownership tags were set on the created container. */
    private bool $tagged = false;

    /** @var list<string> SHA256 host key fingerprints from the create task log */
    private array $hostKeys = [];

    /** Seconds kept free before the job timeout for error handling and clean-up. */
    public const CLEANUP_RESERVE = 300;

    public function __construct(
        private Catalog $catalog,
        private Ipam $ipam,
        private SshRunner $ssh,
        private DeployerKey $key,
    ) {}

    public static function stepLabel(string $step): string
    {
        foreach (DeployFormService::STEPS as [$key, $label]) {
            if ($key === $step) {
                return $label;
            }
        }

        return $step;
    }

    /**
     * Seconds the whole run may take: the job timeout minus a reserve for clean-up
     * (optionally lower via config "proxapps.deploy_budget").
     */
    public static function budget(): int
    {
        $max = RunDeployment::TIMEOUT - self::CLEANUP_RESERVE;
        $configured = config('proxapps.deploy_budget');

        return $configured !== null ? max(1, min((int) $configured, $max)) : $max;
    }

    public function run(Deployment $d): void
    {
        // Claim the deployment atomically so it never runs twice.
        $claimed = Deployment::query()->whereKey($d->id)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $d->refresh();
        $this->d = $d;
        $this->step = 'validate';
        $this->lastCancelCheck = 0.0;
        $this->deadline = microtime(true) + static::budget();
        $this->tagged = false;
        $this->hostKeys = [];

        $spec = (array) $d->spec;
        $secrets = (array) ($d->secrets ?? []);
        $secretValues = array_values(array_filter(
            array_merge([$secrets['root_password'] ?? null], array_values((array) ($secrets['params'] ?? []))),
            fn ($v) => $v !== null && $v !== ''));
        $secretValues = array_map('strval', $secretValues);
        $created = false;
        $tags = '';
        $client = null;

        try {
            // Queue workers live for hours: always use the current catalog (new apps, fixed scripts).
            $this->catalog->reload();
            $app = $this->catalog->get($d->app_id);
            if ($d->cancel_requested) {
                throw new DeploymentCancelled;
            }
            if ($app === null) {
                throw new DeployException("App '{$d->app_id}' ist nicht (mehr) im Katalog.");
            }
            $connection = $d->connection;
            if (! $connection) {
                throw new ProxmoxException('Verbindung nicht gefunden.');
            }
            $client = $connection->client(60);
            $node = (string) $spec['node'];
            $vmid = (int) $spec['vmid'];
            $net = (array) $spec['net'];
            $shouldCancel = fn () => $this->cancelRequested();

            // 1. validate ---------------------------------------------------------
            $this->begin('validate');
            if (! $client->vmidFree($vmid)) {
                throw new DeployException("Die VMID {$vmid} ist bereits vergeben.");
            }
            $this->log("VMID {$vmid} ist frei.");
            if ($net['mode'] === 'static') {
                $ip = (string) $net['ip'];
                $profile = ! empty($spec['profile_id']) ? NetworkProfile::query()->find($spec['profile_id']) : null;
                $vlan = isset($net['vlan']) && $net['vlan'] !== '' ? (int) $net['vlan'] : null;
                if ($error = DeployFormService::vmidPatternError($profile, $ip, $vmid, $vlan)) {
                    throw new DeployException($error);
                }
                $used = $this->ipam->usedIps($client, (int) $d->connection_id, true);
                $owner = $used[$ip] ?? null;
                if ($owner && ! str_starts_with($owner, "{$vmid} ")) {
                    throw new DeployException("Die IP {$ip} ist bereits vergeben ({$owner}).");
                }
                if (Ipam::ping($ip)) {
                    throw new DeployException("Die IP {$ip} antwortet bereits auf Ping – sie ist vermutlich belegt.");
                }
                $this->log("IP {$ip} ist frei.");
            }

            // 2. template ---------------------------------------------------------
            $this->begin('template');
            $ostemplate = $this->resolveTemplate($client, $spec, $app);

            // 3. create -----------------------------------------------------------
            $this->begin('create');
            $params = CreateParams::build($spec, $secrets, $ostemplate, $app, $this->key->publicKey(), $d->id);
            $this->log("Lege CT {$vmid} '{$spec['hostname']}' auf {$node} an "
                ."({$spec['cores']} vCPU, {$spec['memory']} MB RAM, {$spec['disk']} GB auf "
                ."{$spec['rootfs_storage']}) …");
            // Proxmox checks tag permissions on /vms/<vmid> only, never on the pool. A token
            // whose write rights are limited to a pool therefore cannot set tags during
            // creation – set them right afterwards, when the container is a pool member.
            $tags = $params['tags'] ?? '';
            unset($params['tags']);
            $createLimit = $this->limit(900);
            $upid = $client->createLxc($node, $params);
            $created = true;
            // No cancel callback here: leaving the wait early would leave a half-created container
            // without tags behind. A cancel is honoured right after the task has finished.
            $res = $client->waitTask($upid, $createLimit, $this->taskLogger('create'));
            if (! $res['ok']) {
                throw new DeployException("Anlegen fehlgeschlagen: {$res['exitstatus']}");
            }
            $this->mergeResult(['container_created' => true]);
            $client->lxcSetConfig($node, $vmid, ['tags' => $tags]);
            $this->tagged = true;
            $this->log('Tags gesetzt: '.str_replace(';', ', ', $tags));
            $this->ipam->invalidate((int) $d->connection_id);
            $this->checkCancel(true);

            // 4. start ------------------------------------------------------------
            $this->begin('start');
            $upid = $client->lxcAction($node, $vmid, 'start');
            $res = $client->waitTask($upid, $this->limit(300), $this->taskLogger('start'), $shouldCancel);
            if (! $res['ok']) {
                throw new DeployException("Start fehlgeschlagen: {$res['exitstatus']}");
            }
            $this->log('Container läuft.');

            // 5. network + ssh ----------------------------------------------------
            $this->begin('network');
            $ip = $net['mode'] === 'static' ? ($net['ip'] ?? null) : null;
            if (! $ip) {
                $ip = $this->waitForDhcp($client, $node, $vmid, $this->limit(120));
            }
            $this->setState(['ip' => $ip]);
            if ($this->hostKeys) {
                $this->log('Erwartete SSH-Hostschlüssel: SHA256:'.implode(', SHA256:', $this->hostKeys));
            } else {
                $warning = 'Warnung: Im Protokoll des Anlegens stehen keine SSH-Hostschlüssel – '
                    .'der Hostschlüssel des Containers kann nicht geprüft werden.';
                $this->log($warning);
                Log::warning("Deployment {$d->id}: {$warning}");
            }
            $this->log("Verbinde per SSH mit root@{$ip} …");
            $conn = $this->ssh->connect($ip, $this->limit(240),
                fn (string $m) => $this->log($m, 'info', 'network'), $shouldCancel, $this->hostKeys);
            $this->log('SSH-Verbindung steht.');

            try {
                // 6. base ---------------------------------------------------------
                $this->begin('base');
                $baseScript = $this->baseScript();
                $env = [
                    'PROXAPPS_UPGRADE' => ($spec['apt_upgrade'] ?? true) ? '1' : '0',
                    'PROXAPPS_TZ' => (string) ($spec['timezone'] ?? ''),
                ];
                $code = $this->ssh->runScript($conn, $baseScript, $env, $this->outputLogger('base'),
                    $secretValues, $this->limit(1800), fn () => $this->checkCancel(), $this->warnLogger('base'));
                if ($code !== 0) {
                    throw new DeployException("Grundinstallation fehlgeschlagen (Exit-Code {$code}).");
                }

                // 7. install ------------------------------------------------------
                $this->begin('install');
                $env = [
                    'PROXAPPS_IP' => $ip,
                    'PROXAPPS_HOSTNAME' => (string) $spec['hostname'],
                    'PROXAPPS_VMID' => (string) $vmid,
                ];
                foreach ((array) ($spec['params'] ?? []) as $k => $v) {
                    $env[$k] = (string) $v;
                }
                foreach ((array) ($secrets['params'] ?? []) as $k => $v) {
                    $env[$k] = (string) $v;
                }
                $code = $this->ssh->runScript($conn, $app->installScript, $env, $this->installOutputLogger(),
                    $secretValues, $this->limit(3600), fn () => $this->checkCancel(), $this->warnLogger('install'));
                if ($code !== 0) {
                    throw new DeployException("Installation von {$app->name} fehlgeschlagen (Exit-Code {$code}).");
                }
            } finally {
                $this->disconnect($conn);
            }

            // 8. verify -----------------------------------------------------------
            $this->begin('verify');
            // Re-read the spec: the install script may have added values (PROXAPPS_VAR lines).
            $context = ['ip' => $ip, 'hostname' => $spec['hostname'], 'vmid' => $vmid]
                + (array) (((array) $this->d->spec)['params'] ?? []);
            $hc = $app->healthcheck;
            if (! empty($hc['url'])) {
                $url = Catalog::render((string) $hc['url'], $context);
                $this->healthCheck($url, $this->limit((int) ($hc['timeout'] ?? 120)));
            } else {
                $this->log('Keine Funktionsprüfung definiert.');
            }

            $access = $app->access;
            $result = [
                'url' => ! empty($access['url']) ? Catalog::render((string) $access['url'], $context) : '',
                'user' => ! empty($access['user']) ? Catalog::render((string) $access['user'], $context) : '',
                'password_param' => $access['password_param'] ?? null,
                'notes' => Catalog::render($app->notes, $context),
                // PK95 Core puts this behind the reverse proxy (deployment.succeeded).
                'proxy_url' => ! empty($app->proxy['url']) ? Catalog::render((string) $app->proxy['url'], $context) : '',
                // Remembered for later SSH sessions (central updates verify the same host key).
                'host_keys' => $this->hostKeys,
            ];
            $this->setState(['status' => 'success', 'step' => 'done', 'finished_at' => now(),
                'result' => array_merge((array) ($this->d->result ?? []), $result)]);
            $this->log("✔ {$app->name} ist bereit: ".($result['url'] ?: $ip), 'success', 'done');
        } catch (DeploymentCancelled) {
            $this->log('Ausrollen wurde abgebrochen.', 'error', $this->step);
            $this->setState(['status' => 'cancelled', 'error' => 'Vom Benutzer abgebrochen.', 'finished_at' => now()]);
            $this->maybeDestroy($spec, $client, $created, $tags);
        } catch (Throwable $e) {
            $message = SshRunner::mask(trim($e->getMessage()) !== '' ? $e->getMessage() : class_basename($e), $secretValues);
            if (! $e instanceof DeployException && ! $e instanceof ProxmoxException && ! $e instanceof SshException) {
                Log::error("Deployment {$d->id} crashed: ".SshRunner::mask((string) $e, $secretValues));
            }
            $this->log("✖ {$message}", 'error', $this->step);
            $this->setState(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $this->maybeDestroy($spec, $client, $created, $tags);
        } finally {
            if ($d->connection_id) {
                $this->ipam->invalidate((int) $d->connection_id);
            }
        }
    }

    // -- steps --------------------------------------------------------------------

    private function begin(string $name): void
    {
        $this->step = $name;
        $this->checkCancel(true);
        $this->setState(['status' => 'running', 'step' => $name]);
        $this->log('── '.static::stepLabel($name), 'step', $name);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolveTemplate(ProxmoxClient $client, array $spec, AppDefinition $app): string
    {
        $node = (string) $spec['node'];
        $storage = (string) $spec['template_storage'];
        $choice = (string) ($spec['template'] ?? '');
        $found = Templates::list($client, $node, $storage, $app->template);

        if (str_starts_with($choice, 'download:')) {
            $name = substr($choice, strlen('download:'));
        } elseif ($choice !== '') {
            foreach ($found['local'] as $t) {
                if ($t['volid'] === $choice) {
                    $this->log("Verwende vorhandene Vorlage {$choice}", 'info', 'template');

                    return $choice;
                }
            }
            throw new DeployException("Die Vorlage {$choice} ist auf {$storage} nicht vorhanden.");
        } else {
            if ($found['local']) {
                $volid = $found['local'][0]['volid'];
                $this->log("Verwende vorhandene Vorlage {$volid}", 'info', 'template');

                return $volid;
            }
            if (! $found['downloadable']) {
                throw new DeployException("Keine Vorlage '{$app->template}' gefunden (pveam update auf dem Node ausführen?).");
            }
            $name = $found['downloadable'][0]['name'];
        }

        foreach ($found['local'] as $t) {
            if ($t['name'] === $name) {
                $this->log("Verwende vorhandene Vorlage {$t['volid']}", 'info', 'template');

                return $t['volid'];
            }
        }
        if (! in_array($name, array_column($found['downloadable'], 'name'), true)) {
            throw new DeployException("Die Vorlage {$name} ist im Proxmox-Katalog nicht verfügbar.");
        }
        $this->log("Lade Vorlage {$name} auf {$storage} herunter …", 'info', 'template');
        $upid = $client->downloadTemplate($node, $storage, $name);
        $res = $client->waitTask($upid, $this->limit(1800), $this->taskLogger('template', true), fn () => $this->cancelRequested());
        if (! $res['ok']) {
            throw new DeployException("Download der Vorlage fehlgeschlagen: {$res['exitstatus']}");
        }

        return "{$storage}:vztmpl/{$name}";
    }

    private function waitForDhcp(ProxmoxClient $client, string $node, int $vmid, int $timeout = 120): string
    {
        $this->log('Warte auf IP-Adresse per DHCP …', 'info', 'network');
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $this->checkCancel(true);
            try {
                foreach ($client->lxcInterfaces($node, $vmid) as $iface) {
                    if (($iface['name'] ?? null) === 'lo') {
                        continue;
                    }
                    $inet = $iface['inet'] ?? null;
                    if ($inet) {
                        $ip = explode('/', (string) $inet)[0];
                        $this->log("DHCP-Adresse erhalten: {$ip}", 'info', 'network');

                        return $ip;
                    }
                }
            } catch (ProxmoxException) {
                // container not ready yet
            }
            $this->pause();
        }
        throw new DeployException('Der Container hat per DHCP keine IP-Adresse bekommen.');
    }

    private function healthCheck(string $url, int $timeout): void
    {
        $this->log("Prüfe {$url} …", 'info', 'verify');
        $deadline = microtime(true) + $timeout;
        $last = '';
        do {
            $this->checkCancel(true);
            try {
                $resp = Http::withoutVerifying()->withoutRedirecting()->timeout(5)->connectTimeout(5)->get($url);
                if ($resp->status() < 500) {
                    $this->log("Antwort HTTP {$resp->status()} – Dienst erreichbar.", 'info', 'verify');

                    return;
                }
                $last = "HTTP {$resp->status()}";
            } catch (ConnectionException $e) {
                $last = class_basename($e);
            }
            $this->pause();
        } while (microtime(true) < $deadline);
        throw new DeployException("Dienst unter {$url} nicht erreichbar ({$last}).");
    }

    /**
     * After a failure: make sure a created container carries the ownership tags (so it can be
     * managed later) and remove it when "destroy_on_failure" is set and it is provably ours.
     *
     * @param  array<string, mixed>  $spec
     */
    private function maybeDestroy(array $spec, ?ProxmoxClient $client, bool $created, string $tags): void
    {
        if (! $created || $client === null) {
            return;
        }
        $node = (string) $spec['node'];
        $vmid = (int) $spec['vmid'];
        if (! $this->tagged && $tags !== '') {
            try {
                $client->lxcSetConfig($node, $vmid, ['tags' => $tags]);
                $this->tagged = true;
                $this->log('Tags nachträglich gesetzt: '.str_replace(';', ', ', $tags));
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'does not exist')) {
                    $this->log("Container {$vmid} wurde nicht angelegt – nichts zu bereinigen.", 'info');

                    return;
                }
                $warning = "Achtung: CT {$vmid} konnte nicht mit den Tags '".str_replace(';', ', ', $tags)
                    ."' versehen werden ({$e->getMessage()}). ProxApps erkennt ihn so nicht als eigenen Container "
                    .'und kann ihn weder verwalten noch entfernen – bitte in Proxmox prüfen und ggf. von Hand entfernen.';
                $this->log($warning, 'error');
                Log::warning("Deployment {$this->d->id}: {$warning}");
            }
        }
        if (empty($spec['destroy_on_failure'])) {
            $this->log("Container {$vmid} bleibt zur Fehlersuche bestehen.", 'info');

            return;
        }
        try {
            if (! ContainerManager::owned($client, $this->d)) {
                $this->log("Container {$vmid} wird nicht entfernt: er stammt nicht "
                    .'erkennbar von diesem Deployment.', 'info');

                return;
            }
            $this->log("Entferne Container {$vmid} …", 'info');
            $upid = $client->lxcDestroy($node, $vmid);
            $res = $client->waitTask($upid, 240);
            if (! $res['ok']) {
                $this->log("Container {$vmid} konnte nicht entfernt werden: {$res['exitstatus']}", 'error');

                return;
            }
            $this->log('Container entfernt.', 'info');
            // The step stays at the step that failed; the removal is recorded in the result.
            $this->mergeResult(['container_removed' => true]);
        } catch (Throwable $e) {
            $this->log("Container konnte nicht entfernt werden: {$e->getMessage()}", 'error');
        }
    }

    // -- helpers --------------------------------------------------------------------

    private function baseScript(): string
    {
        $path = (string) config('proxapps.base_script', resource_path('provisioning/base.sh'));
        if (! is_file($path)) {
            throw new DeployException("Grundskript {$path} fehlt.");
        }

        return (string) file_get_contents($path);
    }

    /**
     * Logs a Proxmox task line. For the create task it also collects the SSH host key
     * fingerprints Proxmox prints ("done: SHA256:<fp> root@<host>").
     */
    private function taskLogger(string $step, bool $onlyProgress = false): Closure
    {
        $last = -10.0;

        return function (string $line) use (&$last, $step, $onlyProgress) {
            if ($step === 'create' && preg_match('~done:\s*SHA256:([A-Za-z0-9+/]{16,}={0,2})~', $line, $m)) {
                $fp = rtrim($m[1], '=');
                if (! in_array($fp, $this->hostKeys, true)) {
                    $this->hostKeys[] = $fp;
                }
            }
            if ($onlyProgress && preg_match('~(\d{1,3}(?:\.\d+)?)%~', $line, $m)) {
                $pct = (float) $m[1];
                if ($pct - $last < 10 && $pct < 100) {
                    return;
                }
                $last = $pct;
            }
            $this->log($line, 'task', $step);
        };
    }

    private function outputLogger(string $step): Closure
    {
        return function (string $line) use ($step) {
            $this->log($line, 'out', $step);
            $this->checkCancel();
        };
    }

    /**
     * Like outputLogger('install'), but an install script can hand values it generated
     * (e.g. an admin passphrase) back to ProxApps with a line "PROXAPPS_SECRET:KEY=value".
     * Such lines are never logged; the value is stored encrypted with the deployment's
     * secret parameters, so access.password_param can point to it.
     */
    private function installOutputLogger(): Closure
    {
        return function (string $line) {
            if (preg_match('~^PROXAPPS_SECRET:([A-Z][A-Z0-9_]{0,40})=(.+)$~D', $line, $m)) {
                $secrets = (array) ($this->d->secrets ?? []);
                $secrets['params'] = array_merge((array) ($secrets['params'] ?? []), [$m[1] => trim($m[2])]);
                $this->d->secrets = $secrets;
                $this->d->save();
                $this->log("Geheimer Wert {$m[1]} übernommen (verschlüsselt gespeichert).", 'info', 'install');
                $this->checkCancel();

                return;
            }
            // Plain values (e.g. the access URL, which depends on the chosen TLS mode) can be
            // used as {KEY} in access.url, healthcheck.url and notes.
            if (preg_match('~^PROXAPPS_VAR:([A-Z][A-Z0-9_]{0,40})=(.*)$~D', $line, $m)) {
                $spec = (array) $this->d->spec;
                $spec['params'] = array_merge((array) ($spec['params'] ?? []), [$m[1] => mb_substr(trim($m[2]), 0, 500)]);
                $this->d->spec = $spec;
                $this->d->save();
                $this->log("{$m[1]} = ".trim($m[2]), 'info', 'install');
                $this->checkCancel();

                return;
            }
            $this->log($line, 'out', 'install');
            $this->checkCancel();
        };
    }

    private function warnLogger(string $step): Closure
    {
        return fn (string $message) => $this->log($message, 'error', $step);
    }

    /**
     * Clamp a step's time limit to what is left of the overall deadline.
     */
    private function limit(int $seconds): int
    {
        $left = (int) floor($this->deadline - microtime(true));
        if ($left <= 0) {
            throw new DeployException('Zeitlimit für das gesamte Ausrollen ('.intdiv(static::budget(), 60)
                .' min) überschritten.');
        }

        return max(1, min($seconds, $left));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function mergeResult(array $fields): void
    {
        $this->setState(['result' => array_merge((array) ($this->d->result ?? []), $fields)]);
    }

    private function cancelRequested(): bool
    {
        return (bool) Deployment::query()->whereKey($this->d->id)->value('cancel_requested');
    }

    /**
     * Throw when the user asked to cancel. Without $force the DB is asked at most every 2 seconds.
     */
    private function checkCancel(bool $force = false): void
    {
        $now = microtime(true);
        if (! $force && $now - $this->lastCancelCheck < 2) {
            return;
        }
        $this->lastCancelCheck = $now;
        if ($this->cancelRequested()) {
            throw new DeploymentCancelled;
        }
    }

    private function pause(): void
    {
        $interval = (float) config('proxapps.retry_interval', 3);
        if ($interval > 0) {
            usleep((int) ($interval * 1_000_000));
        }
    }

    private function disconnect(mixed $conn): void
    {
        try {
            if ($conn instanceof SSH2) {
                $conn->disconnect();
            }
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function setState(array $fields): void
    {
        $this->d->forceFill($fields)->save();
    }

    private function log(string $message, string $level = 'info', ?string $step = null): void
    {
        DeploymentLogger::log($this->d, $message, $level, $step ?? $this->step);
    }
}
