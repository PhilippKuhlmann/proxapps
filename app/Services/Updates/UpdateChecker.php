<?php

namespace App\Services\Updates;

use App\Models\Deployment;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\ContainerManager;
use App\Services\Ssh\SshRunner;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Finds out what is pending for a managed container: installed app version (inside the
 * container), newest app version (from the catalog's version URL) and the number of
 * upgradable Debian packages. Results are stored on the deployment.
 */
class UpdateChecker
{
    public function __construct(private Catalog $catalog, private SshRunner $ssh) {}

    public function check(Deployment $d): void
    {
        $this->catalog->reload();
        $app = $this->catalog->get($d->app_id);
        $errors = [];
        $data = ['updates_checked_at' => now()];

        if ($app) {
            try {
                $latest = $this->latestVersion($app);
                if ($latest !== null) {
                    $data['latest_version'] = $latest;
                }
            } catch (Throwable $e) {
                $errors[] = 'Neueste Version nicht ermittelbar: '.$this->short($e);
            }
        } else {
            $errors[] = "App '{$d->app_id}' ist nicht (mehr) im Katalog.";
        }

        try {
            $state = $this->inspect($d, $app);
            $data['os_updates'] = $state['os_updates'];
            if ($state['app_version'] !== null) {
                $data['app_version'] = $state['app_version'];
            }
            if ($state['apt_error']) {
                $errors[] = 'apt-get update meldete Fehler – die Paketanzahl kann veraltet sein.';
            }
        } catch (Throwable $e) {
            $errors[] = $this->short($e);
        }

        $data['update_check_error'] = $errors ? implode(' ', $errors) : null;
        $d->forceFill($data)->save();
    }

    /** Newest version published for the app (e.g. "v0.107.80"), or null when not configured. */
    public function latestVersion(AppDefinition $app): ?string
    {
        $src = $app->updates['latest_version'] ?? [];
        if (empty($src['url'])) {
            return null;
        }
        $json = Http::timeout(10)->connectTimeout(5)->acceptJson()->get($src['url'])->throw()->json();
        $value = $src['json'] !== '' ? data_get($json, $src['json']) : $json;
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? mb_substr($value, 0, 64) : null;
    }

    /**
     * SSH into the container and read the installed version + number of upgradable packages.
     *
     * @return array{app_version: ?string, os_updates: int, apt_error: bool}
     */
    public function inspect(Deployment $d, ?AppDefinition $app): array
    {
        $client = $d->connection?->client(15);
        if (! $client) {
            throw new UpdateException('Die Proxmox-Verbindung dieses Deployments gibt es nicht mehr.');
        }
        if (! ContainerManager::owned($client, $d)) {
            throw new UpdateException(ContainerManager::notOwnedMessage($d));
        }
        $status = (array) $client->lxcStatus($d->node, (int) $d->vmid);
        if (($status['status'] ?? '') !== 'running') {
            throw new UpdateException("Container {$d->vmid} läuft nicht – Prüfung übersprungen.");
        }
        if (! $d->ip) {
            throw new UpdateException('Für diesen Container ist keine IP-Adresse bekannt.');
        }

        $versionCmd = $app?->updates['installed_version'] ?? '';
        $script = "if ! apt-get update -qq >/dev/null 2>&1; then echo PROXAPPS_APT_ERROR=1; fi\n"
            ."echo \"PROXAPPS_OS_UPDATES=\$(apt-get -s -o Debug::NoLocking=1 dist-upgrade 2>/dev/null | grep -c '^Inst ' || true)\"\n";
        if ($versionCmd !== '') {
            $script .= "v=\"\$( ( {$versionCmd} ) 2>/dev/null | head -n1 || true)\"\necho \"PROXAPPS_APP_VERSION=\$v\"\n";
        }

        $lines = [];
        $ssh = $this->ssh->connect($d->ip, 30, null, null, (array) (((array) $d->result)['host_keys'] ?? []));
        try {
            $code = $this->ssh->runScript($ssh, $script, [], function (string $line) use (&$lines) {
                $lines[] = $line;
            }, [], 300);
        } finally {
            $this->disconnect($ssh);
        }
        if ($code !== 0) {
            throw new UpdateException("Prüfskript im Container endete mit Exit-Code {$code}.");
        }

        $out = ['app_version' => null, 'os_updates' => 0, 'apt_error' => false];
        foreach ($lines as $line) {
            if (preg_match('~^PROXAPPS_OS_UPDATES=(\d+)~', $line, $m)) {
                $out['os_updates'] = (int) $m[1];
            } elseif (preg_match('~^PROXAPPS_APP_VERSION=(.*)$~', $line, $m) && trim($m[1]) !== '') {
                $out['app_version'] = mb_substr(trim($m[1]), 0, 64);
            } elseif (str_starts_with($line, 'PROXAPPS_APT_ERROR=')) {
                $out['apt_error'] = true;
            }
        }

        return $out;
    }

    private function short(Throwable $e): string
    {
        $msg = trim($e->getMessage());

        return $msg !== '' ? mb_substr($msg, 0, 300) : class_basename($e);
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
