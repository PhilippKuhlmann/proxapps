<?php

namespace App\Services\Deploy;

use App\Models\Deployment;
use App\Services\Ipam;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;

/**
 * User-triggered actions on deployed containers. Every action first checks that the
 * container still belongs to the deployment (see owned()).
 */
class ContainerManager
{
    public const ACTIONS = ['start', 'shutdown', 'stop', 'reboot'];

    public const TAG = 'proxapps';

    public function __construct(private Ipam $ipam) {}

    /**
     * The deployment-unique tag every container gets right after creation.
     */
    public static function deploymentTag(Deployment|int $d): string
    {
        return self::TAG.'-d'.($d instanceof Deployment ? $d->id : $d);
    }

    /**
     * The marker written into the container description at creation.
     */
    public static function descriptionMarker(Deployment|int $d): string
    {
        return 'Deployment #'.($d instanceof Deployment ? $d->id : $d);
    }

    /**
     * Ownership rule – a container belongs to deployment #N only when ALL of these hold:
     *  - its hostname equals the deployment's hostname,
     *  - it carries the tag "proxapps",
     *  - it carries the deployment-unique tag "proxapps-dN", or (fallback for a container whose
     *    tags could not be completed) its description contains "Deployment #N".
     *
     * VMIDs are derived from the IP and therefore get reused: hostname + "proxapps" alone would
     * let an old entry stop or delete a newer container that took over the same VMID.
     * Containers created before this rule (no "proxapps-dN" tag) are no longer managed; adding
     * the tag in Proxmox re-enables it.
     */
    public static function owned(ProxmoxClient $client, Deployment $d): bool
    {
        try {
            $cfg = $client->lxcConfig($d->node, (int) $d->vmid);
        } catch (ProxmoxException) {
            return false;
        }

        return static::ownedConfig($cfg, $d);
    }

    /**
     * @param  array<string, mixed>  $cfg  container config as returned by the API
     */
    public static function ownedConfig(array $cfg, Deployment $d): bool
    {
        $tags = array_map('strtolower', preg_split('~[;,\s]+~', (string) ($cfg['tags'] ?? '')) ?: []);
        if (($cfg['hostname'] ?? null) !== $d->hostname || ! in_array(self::TAG, $tags, true)) {
            return false;
        }
        if (in_array(static::deploymentTag($d), $tags, true)) {
            return true;
        }

        return (bool) preg_match('~'.preg_quote(static::descriptionMarker($d), '~').'(?!\d)~',
            rawurldecode((string) ($cfg['description'] ?? '')));
    }

    public static function notOwnedMessage(Deployment $d): string
    {
        return "CT {$d->vmid} auf {$d->node} gehört nicht (mehr) erkennbar zu diesem Deployment: Hostname "
            ."'{$d->hostname}', Tag '".self::TAG."' und Tag '".static::deploymentTag($d)."' (oder '"
            .static::descriptionMarker($d)."' in der Beschreibung) müssen passen. Bei älteren Containern "
            ."den Tag '".static::deploymentTag($d)."' in Proxmox ergänzen.";
    }

    private function client(Deployment $d, int $timeout = 30): ProxmoxClient
    {
        $connection = $d->connection;
        if (! $connection) {
            throw new ProxmoxException('Verbindung nicht gefunden.');
        }

        return $connection->client($timeout);
    }

    public function action(Deployment $d, string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new DeployException('Aktion nicht möglich.');
        }
        $client = $this->client($d);
        if (! static::owned($client, $d)) {
            throw new DeployException(static::notOwnedMessage($d));
        }
        $upid = $client->lxcAction($d->node, (int) $d->vmid, $action);
        $res = $client->waitTask($upid, 180);
        if (! $res['ok']) {
            throw new DeployException("Aktion '{$action}' fehlgeschlagen: {$res['exitstatus']}");
        }
        DeploymentLogger::log($d, "Container-Aktion '{$action}' ausgeführt.", 'info', 'manage');
    }

    public function destroy(Deployment $d): void
    {
        $client = $this->client($d);
        if (! static::owned($client, $d)) {
            throw new DeployException(rtrim(static::notOwnedMessage($d), '.').' – aus Sicherheitsgründen nicht entfernt.');
        }
        DeploymentLogger::log($d, "Entferne Container {$d->vmid} auf {$d->node} …", 'step', 'destroy');
        $upid = $client->lxcDestroy($d->node, (int) $d->vmid);
        $res = $client->waitTask($upid, 300, function (string $line) use ($d) {
            DeploymentLogger::log($d, $line, 'task', 'destroy');
        });
        if (! $res['ok']) {
            throw new DeployException("Entfernen fehlgeschlagen: {$res['exitstatus']}");
        }
        if ($d->connection_id) {
            $this->ipam->invalidate((int) $d->connection_id);
        }
        $result = array_merge((array) ($d->result ?? []), ['container_removed' => true]);
        $d->forceFill(['status' => 'removed', 'step' => 'removed', 'result' => $result])->save();
        DeploymentLogger::log($d, "Container {$d->vmid} wurde entfernt.", 'success', 'destroy');
    }
}
