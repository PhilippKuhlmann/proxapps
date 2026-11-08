<?php

namespace App\Services\Updates;

use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\ContainerManager;
use App\Services\Deploy\DeploymentLogger;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Ssh\SshRunner;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Updates one managed container: Proxmox snapshot → Debian packages → app update script →
 * health check → snapshot clean-up. On failure it rolls back to the snapshot (when enabled).
 */
class UpdateRunner
{
    /** Prefix of the snapshots ProxApps creates; only those are ever deleted. */
    public const SNAPSHOT_PREFIX = 'proxapps_upd_';

    private UpdateRun $run;

    private Deployment $d;

    private string $step = 'u_check';

    public function __construct(private Catalog $catalog, private SshRunner $ssh, private UpdateChecker $checker) {}

    public function run(UpdateRun $run): void
    {
        $claimed = UpdateRun::query()->whereKey($run->id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $this->run = $run->refresh();
        $this->d = $run->deployment;
        $this->step = 'u_check';
        $d = $this->d;

        $secrets = (array) ($d->secrets ?? []);
        $secretValues = array_values(array_map('strval', array_filter(
            array_merge([$secrets['root_password'] ?? null], array_values((array) ($secrets['params'] ?? []))),
            fn ($v) => $v !== null && $v !== '')));
        $client = null;

        try {
            $this->catalog->reload();
            $app = $this->catalog->get($d->app_id);

            // 1. check ----------------------------------------------------------------
            $this->begin('u_check');
            if (! $d->isManaged()) {
                throw new UpdateException('Dieses Deployment hat keinen verwalteten Container (mehr).');
            }
            $client = $d->connection?->client(60);
            if (! $client) {
                throw new UpdateException('Die Proxmox-Verbindung dieses Deployments gibt es nicht mehr.');
            }
            if (! ContainerManager::owned($client, $d)) {
                throw new UpdateException(ContainerManager::notOwnedMessage($d));
            }
            $status = (array) $client->lxcStatus($d->node, (int) $d->vmid);
            if (($status['status'] ?? '') !== 'running') {
                throw new UpdateException("Container {$d->vmid} läuft nicht – bitte zuerst starten.");
            }
            if (! $d->ip) {
                throw new UpdateException('Für diesen Container ist keine IP-Adresse bekannt.');
            }
            $this->run->forceFill(['from_version' => $d->app_version])->save();
            $this->log("CT {$d->vmid} ({$d->hostname}) läuft und gehört zu diesem Deployment.");

            // 2. snapshot -------------------------------------------------------------
            if (Settings::get('snapshot_before_update')) {
                $this->begin('u_snapshot');
                $name = self::SNAPSHOT_PREFIX
                    .now()->setTimezone((string) (Settings::get('timezone') ?: 'Europe/Berlin'))->format('Ymd_His');
                $this->log("Lege Snapshot {$name} an …");
                $upid = $client->lxcSnapshot($d->node, (int) $d->vmid, $name,
                    "ProxApps: vor Update #{$this->run->id} ({$d->app_name})");
                $res = $client->waitTask($upid, 600, fn (string $l) => $this->log($l, 'task'));
                if (! $res['ok']) {
                    throw new UpdateException("Snapshot fehlgeschlagen: {$res['exitstatus']}");
                }
                $this->run->forceFill(['snapshot' => $name, 'snapshot_exists' => true])->save();
                $this->log('Snapshot angelegt.');
            } else {
                $this->log('Snapshot vor Updates ist in den Einstellungen abgeschaltet.');
            }

            // 3+4. packages + app ------------------------------------------------------
            $ssh = $this->ssh->connect($d->ip, 60, fn (string $m) => $this->log($m), null,
                (array) (((array) $d->result)['host_keys'] ?? []));
            try {
                $this->begin('u_os');
                $count = null;
                $code = $this->ssh->runScript($ssh, self::osScript(), [], function (string $line) use (&$count) {
                    if (preg_match('~^PROXAPPS_OS_COUNT=(\d+)~', $line, $m)) {
                        $count = (int) $m[1];

                        return;
                    }
                    $this->log($line, 'out');
                }, $secretValues, 1800, null, fn (string $w) => $this->log($w, 'error'));
                if ($code !== 0) {
                    throw new UpdateException("Aktualisieren der Debian-Pakete fehlgeschlagen (Exit-Code {$code}).");
                }
                $this->run->forceFill(['os_packages' => $count])->save();
                $this->log($count ? "{$count} Debian-Paket(e) aktualisiert." : 'Debian-Pakete waren bereits aktuell.');

                $this->begin('u_app');
                if ($app && $app->hasAppUpdate()) {
                    $env = [
                        'PROXAPPS_IP' => (string) $d->ip,
                        'PROXAPPS_HOSTNAME' => (string) $d->hostname,
                        'PROXAPPS_VMID' => (string) $d->vmid,
                    ];
                    foreach ((array) (((array) $d->spec)['params'] ?? []) as $k => $v) {
                        $env[$k] = (string) $v;
                    }
                    foreach ((array) ($secrets['params'] ?? []) as $k => $v) {
                        $env[$k] = (string) $v;
                    }
                    $code = $this->ssh->runScript($ssh, (string) $app->updates['script'], $env,
                        fn (string $l) => $this->log($l, 'out'), $secretValues, 1800, null,
                        fn (string $w) => $this->log($w, 'error'));
                    if ($code !== 0) {
                        throw new UpdateException("App-Update fehlgeschlagen (Exit-Code {$code}).");
                    }
                } else {
                    $this->log('Für diese App gibt es kein App-Update-Skript – nur die Debian-Pakete wurden aktualisiert.');
                }
            } finally {
                $this->disconnect($ssh);
            }

            // 5. verify ----------------------------------------------------------------
            $this->begin('u_verify');
            $url = $app ? (string) ($app->healthcheck['url'] ?? '') : '';
            if ($url !== '') {
                $context = ['ip' => $d->ip, 'hostname' => $d->hostname, 'vmid' => $d->vmid]
                    + (array) (((array) $d->spec)['params'] ?? []);
                $this->healthCheck(Catalog::render($url, $context), (int) ($app->healthcheck['timeout'] ?? 120));
            } else {
                $this->log('Keine Funktionsprüfung definiert.');
            }

            // 6. clean-up --------------------------------------------------------------
            $this->begin('u_cleanup');
            $this->pruneSnapshots($client);
            try {
                $this->checker->check($d->refresh());
            } catch (Throwable $e) {
                $this->log('Versionsprüfung nach dem Update fehlgeschlagen: '.$e->getMessage(), 'error');
            }
            $d->refresh();
            $this->run->forceFill(['status' => 'success', 'step' => 'done', 'to_version' => $d->app_version,
                'finished_at' => now()])->save();
            $this->log('✔ Update abgeschlossen'.($d->app_version ? " – {$d->app_name} {$d->app_version}" : '').'.', 'success', 'done');
        } catch (Throwable $e) {
            $message = SshRunner::mask(trim($e->getMessage()) !== '' ? $e->getMessage() : class_basename($e), $secretValues);
            if (! $e instanceof UpdateException) {
                Log::warning("Update run {$this->run->id} failed: ".SshRunner::mask((string) $e, $secretValues));
            }
            $this->log("✖ {$message}", 'error');
            $this->fail($client, $message);
        }
    }

    /** After a failure: roll back to the pre-update snapshot when that is enabled. */
    private function fail(?ProxmoxClient $client, string $message): void
    {
        $this->run->refresh();
        if ($client && $this->run->snapshot && $this->run->snapshot_exists && Settings::get('auto_rollback')) {
            try {
                $this->rollbackTo($client, $this->run, 'u_rollback');
                $this->run->forceFill(['status' => 'rolled_back', 'error' => $message, 'finished_at' => now()])->save();
                $this->log("Auf den Stand vor dem Update zurückgerollt ({$this->run->snapshot}).", 'success', 'u_rollback');

                return;
            } catch (Throwable $e) {
                $message .= ' Rollback fehlgeschlagen: '.$e->getMessage();
                $this->log('✖ Rollback fehlgeschlagen: '.$e->getMessage(), 'error', 'u_rollback');
            }
        }
        $this->run->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 2000), 'finished_at' => now()])->save();
    }

    /** User-triggered rollback of a finished update run. */
    public function rollback(UpdateRun $run): void
    {
        $this->run = $run;
        $this->d = $run->deployment;
        $this->step = 'u_rollback';
        $client = $this->d->connection?->client(60);
        if (! $client) {
            throw new UpdateException('Die Proxmox-Verbindung dieses Deployments gibt es nicht mehr.');
        }
        if (! ContainerManager::owned($client, $this->d)) {
            throw new UpdateException(ContainerManager::notOwnedMessage($this->d));
        }
        $this->rollbackTo($client, $run, 'u_rollback');
        $run->forceFill(['status' => 'rolled_back'])->save();
        $this->log("Manuell auf den Stand vor Update #{$run->id} zurückgerollt ({$run->snapshot}).", 'success');
        try {
            $this->checker->check($this->d->refresh());
        } catch (Throwable) {
            // shown on the next check
        }
    }

    private function rollbackTo(ProxmoxClient $client, UpdateRun $run, string $step): void
    {
        $this->step = $step;
        $this->log("── Rollback auf {$run->snapshot}", 'step', $step);
        $upid = $client->lxcRollback($this->d->node, (int) $this->d->vmid, (string) $run->snapshot, true);
        $res = $client->waitTask($upid, 600, fn (string $l) => $this->log($l, 'task', $step));
        if (! $res['ok']) {
            throw new UpdateException("Rollback fehlgeschlagen: {$res['exitstatus']}");
        }
    }

    /** Keep the newest N ProxApps snapshots of this container, delete older ones. */
    private function pruneSnapshots(ProxmoxClient $client): void
    {
        $keep = max(1, (int) (Settings::get('keep_snapshots') ?: 3));
        $names = [];
        foreach ($client->lxcSnapshots($this->d->node, (int) $this->d->vmid) as $s) {
            $name = (string) ($s['name'] ?? '');
            if (str_starts_with($name, self::SNAPSHOT_PREFIX)) {
                $names[] = $name;
            }
        }
        rsort($names); // names embed the timestamp → newest first
        $obsolete = array_slice($names, $keep);
        foreach ($obsolete as $name) {
            $this->log("Lösche alten Snapshot {$name} …");
            $res = $client->waitTask($client->lxcDeleteSnapshot($this->d->node, (int) $this->d->vmid, $name), 600);
            if ($res['ok']) {
                UpdateRun::query()->where('deployment_id', $this->d->id)->where('snapshot', $name)
                    ->update(['snapshot_exists' => false]);
            } else {
                $this->log("Snapshot {$name} konnte nicht gelöscht werden: {$res['exitstatus']}", 'error');
            }
        }
        $this->log(count($names) - count($obsolete).' ProxApps-Snapshot(s) behalten (maximal '.$keep.').');
    }

    public static function osScript(): string
    {
        return <<<'BASH'
echo "==> Paketquellen aktualisieren"
apt-get update -q
echo "PROXAPPS_OS_COUNT=$(apt-get -s -o Debug::NoLocking=1 dist-upgrade 2>/dev/null | grep -c '^Inst ' || true)"
echo "==> Pakete aktualisieren"
apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold dist-upgrade
apt-get -y -q autoremove
apt-get clean
if [ -f /var/run/reboot-required ]; then echo "==> Hinweis: Das System empfiehlt einen Neustart."; fi
BASH;
    }

    private function healthCheck(string $url, int $timeout): void
    {
        $this->log("Prüfe {$url} …");
        $deadline = microtime(true) + max(5, $timeout);
        $last = '';
        do {
            try {
                $resp = Http::withoutVerifying()->withoutRedirecting()->timeout(5)->connectTimeout(5)->get($url);
                if ($resp->status() < 500) {
                    $this->log("Antwort HTTP {$resp->status()} – Dienst erreichbar.");

                    return;
                }
                $last = "HTTP {$resp->status()}";
            } catch (ConnectionException $e) {
                $last = class_basename($e);
            }
            usleep((int) ((float) config('proxapps.retry_interval', 3) * 1_000_000));
        } while (microtime(true) < $deadline);
        throw new UpdateException("Dienst unter {$url} nach dem Update nicht erreichbar ({$last}).");
    }

    private function begin(string $step): void
    {
        $this->step = $step;
        $this->run->forceFill(['step' => $step])->save();
        $this->log('── '.UpdateRun::stepLabel($step), 'step');
    }

    private function log(string $message, string $level = 'info', ?string $step = null): void
    {
        DeploymentLogger::log($this->d, $message, $level, $step ?? $this->step, $this->run->id);
    }

    private function disconnect(mixed $ssh): void
    {
        try {
            if ($ssh instanceof SSH2) {
                $ssh->disconnect();
            }
        } catch (Throwable) {
            // ignore
        }
    }
}
