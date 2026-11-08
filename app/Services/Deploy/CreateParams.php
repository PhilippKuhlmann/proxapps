<?php

namespace App\Services\Deploy;

use App\Services\Catalog\AppDefinition;

/**
 * Builds the POST /nodes/{node}/lxc parameters from a deployment spec.
 */
class CreateParams
{
    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $secrets
     * @param  int|null  $deploymentId  adds the ownership markers (tag "proxapps-d{id}", "Deployment #{id}")
     * @return array<string, mixed>
     */
    public static function build(array $spec, array $secrets, string $ostemplate, AppDefinition $app,
        string $deployerPublicKey, ?int $deploymentId = null): array
    {
        $net = $spec['net'];
        $net0 = ['name=eth0', 'bridge='.$net['bridge'], 'type=veth'];
        if (! empty($net['vlan'])) {
            $net0[] = 'tag='.(int) $net['vlan'];
        }
        if ($net['mode'] === 'dhcp') {
            $net0[] = 'ip=dhcp';
        } else {
            $net0[] = 'ip='.$net['ip'].'/'.(int) $net['prefix'];
            if (! empty($net['gateway'])) {
                $net0[] = 'gw='.$net['gateway'];
            }
        }
        if (! empty($net['firewall'])) {
            $net0[] = 'firewall=1';
        }

        $keys = [trim($deployerPublicKey)];
        foreach (preg_split('~\R~', (string) ($spec['ssh_keys'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#') && ! in_array($line, $keys, true)) {
                $keys[] = $line;
            }
        }

        $tags = [];
        $own = $deploymentId !== null ? [ContainerManager::TAG, ContainerManager::deploymentTag($deploymentId)] : [ContainerManager::TAG];
        $candidates = array_merge(preg_split('~[;,\s]+~', (string) ($spec['tags'] ?? '')), $own, $app->tags);
        foreach ($candidates as $t) {
            $t = strtolower(trim((string) $t));
            if ($t !== '' && preg_match('~^[a-z0-9_][a-z0-9_\-+.]*$~D', $t) && ! in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }

        $params = [
            'ostemplate' => $ostemplate,
            'vmid' => (int) $spec['vmid'],
            'hostname' => $spec['hostname'],
            'cores' => (int) $spec['cores'],
            'memory' => (int) $spec['memory'],
            'swap' => (int) $spec['swap'],
            'rootfs' => $spec['rootfs_storage'].':'.(int) $spec['disk'],
            'net0' => implode(',', $net0),
            'nameserver' => ($net['dns'] ?? '') ?: null,
            'searchdomain' => ($net['searchdomain'] ?? '') ?: null,
            'unprivileged' => (bool) ($spec['unprivileged'] ?? true),
            'onboot' => (bool) ($spec['onboot'] ?? true),
            'tags' => implode(';', $tags),
            'ssh-public-keys' => implode("\n", $keys),
            'description' => "**{$app->name}**\n\nAusgerollt mit ProxApps"
                .($deploymentId !== null ? ' ('.ContainerManager::descriptionMarker($deploymentId).')' : '')
                .' am '.gmdate('d.m.Y H:i')." UTC.\n",
            'start' => false,
        ];
        if ($spec['nesting'] ?? true) {
            $params['features'] = 'nesting=1';
        }
        if (! empty($secrets['root_password'])) {
            $params['password'] = $secrets['root_password'];
        }
        if (! empty($spec['pool'])) {
            $params['pool'] = $spec['pool'];
        }

        return $params;
    }
}
