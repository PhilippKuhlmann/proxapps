<?php

namespace App\Services\Catalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * App catalog: every app lives in <catalog_dir>/<id>/ with an app.yaml and an install script.
 */
class Catalog
{
    private const ID_RE = '~^[a-z0-9][a-z0-9-]{1,40}$~D';

    private const KEY_RE = '~^[A-Z][A-Z0-9_]{0,40}$~D';

    public const PARAM_TYPES = ['text', 'password', 'number', 'boolean', 'select'];

    /** @var array<string, AppDefinition>|null */
    private ?array $apps = null;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(private ?string $dir = null) {}

    public function dir(): string
    {
        return $this->dir ?? (string) config('proxapps.catalog_dir', base_path('catalog'));
    }

    /**
     * @return list<AppDefinition>
     */
    public function all(): array
    {
        $apps = array_values($this->load());
        usort($apps, fn (AppDefinition $a, AppDefinition $b) => [$a->category, $a->name] <=> [$b->category, $b->name]);

        return $apps;
    }

    public function get(string $id): ?AppDefinition
    {
        return $this->load()[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    public function reload(): void
    {
        $this->apps = null;
        $this->load();
    }

    /**
     * Replace {name} placeholders; unknown names stay untouched.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function render(string $tpl, array $ctx): string
    {
        return (string) preg_replace_callback('~\{([A-Za-z_][A-Za-z0-9_]*)\}~', function (array $m) use ($ctx) {
            if (! array_key_exists($m[1], $ctx)) {
                return $m[0];
            }
            $v = $ctx[$m[1]];

            return is_bool($v) ? ($v ? 'True' : 'False') : (string) $v;
        }, $tpl);
    }

    /**
     * @return array<string, AppDefinition>
     */
    private function load(): array
    {
        if ($this->apps !== null) {
            return $this->apps;
        }
        $apps = [];
        $errors = [];
        $base = $this->dir();
        if (is_dir($base)) {
            $entries = scandir($base) ?: [];
            sort($entries);
            foreach ($entries as $entry) {
                $path = $base.DIRECTORY_SEPARATOR.$entry;
                if ($entry[0] === '.' || ! is_file($path.'/app.yaml')) {
                    continue;
                }
                try {
                    $app = $this->loadOne($path);
                    $apps[$app->id] = $app;
                } catch (CatalogException|ParseException|\TypeError|\ValueError $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        $this->errors = $errors;

        return $this->apps = $apps;
    }

    private function loadOne(string $path): AppDefinition
    {
        $raw = Yaml::parseFile($path.'/app.yaml');
        if (! is_array($raw)) {
            $raw = [];
        }
        $id = (string) ($raw['id'] ?? basename($path));
        if (! preg_match(self::ID_RE, $id)) {
            throw new CatalogException("{$path}: ungültige id '{$id}'");
        }
        $install = (string) ($raw['install'] ?? 'install.sh');
        $installPath = $path.'/'.$install;
        if (str_contains($install, '..') || ! is_file($installPath)) {
            throw new CatalogException("{$path}: Installationsskript '{$install}' fehlt");
        }

        // Optional central updates: update script, command printing the installed version,
        // and where ProxApps looks up the newest version (JSON URL + key).
        $upd = (array) ($raw['updates'] ?? []);
        $updateScript = null;
        if (! empty($upd['script'])) {
            $script = (string) $upd['script'];
            if (str_contains($script, '..') || ! is_file($path.'/'.$script)) {
                throw new CatalogException("{$path}: Update-Skript '{$script}' fehlt");
            }
            $updateScript = (string) file_get_contents($path.'/'.$script);
        }
        $latest = (array) ($upd['latest_version'] ?? []);
        $updates = [
            'script' => $updateScript,
            'installed_version' => trim((string) ($upd['installed_version'] ?? '')),
            'latest_version' => [
                'url' => (string) ($latest['url'] ?? ''),
                'json' => (string) ($latest['json'] ?? ''),
            ],
        ];

        $params = [];
        foreach ((array) ($raw['parameters'] ?? []) as $p) {
            if (! is_array($p) || ! isset($p['key'], $p['label'])) {
                throw new CatalogException("{$path}: Parameter ohne 'key' oder 'label'");
            }
            $param = [
                'key' => (string) $p['key'],
                'label' => (string) $p['label'],
                'type' => (string) ($p['type'] ?? 'text'),
                'default' => $p['default'] ?? null,
                'required' => (bool) ($p['required'] ?? false),
                'help' => (string) ($p['help'] ?? ''),
                'generate' => (bool) ($p['generate'] ?? false),
                'min_length' => isset($p['min_length']) ? (int) $p['min_length'] : null,
                'pattern' => isset($p['pattern']) ? (string) $p['pattern'] : null,
                'min' => isset($p['min']) ? (int) $p['min'] : null,
                'max' => isset($p['max']) ? (int) $p['max'] : null,
                'options' => array_values(array_map(
                    fn ($o) => is_array($o) ? $o : ['value' => $o, 'label' => (string) $o],
                    (array) ($p['options'] ?? []))),
            ];
            if (! preg_match(self::KEY_RE, $param['key'])) {
                throw new CatalogException("{$path}: ungültiger Parameter-Schlüssel '{$param['key']}'");
            }
            if (! in_array($param['type'], self::PARAM_TYPES, true)) {
                throw new CatalogException("{$path}: unbekannter Parametertyp '{$param['type']}'");
            }
            $params[] = $param;
        }

        $res = (array) ($raw['resources'] ?? []);
        $lxc = array_merge(['unprivileged' => true, 'nesting' => true, 'onboot' => true], (array) ($raw['lxc'] ?? []));
        $icon = null;
        foreach (['icon.svg', 'icon.png'] as $name) {
            if (is_file($path.'/'.$name)) {
                $icon = $path.'/'.$name;
                break;
            }
        }

        return new AppDefinition(
            id: $id,
            name: (string) ($raw['name'] ?? $id),
            summary: (string) ($raw['summary'] ?? ''),
            description: trim((string) ($raw['description'] ?? '')),
            category: (string) ($raw['category'] ?? 'Sonstiges'),
            version: (string) ($raw['version'] ?? 'latest'),
            website: (string) ($raw['website'] ?? ''),
            source: (string) ($raw['source'] ?? ''),
            hostname: (string) ($raw['hostname'] ?? $id),
            template: (string) (((array) ($raw['os'] ?? []))['template'] ?? 'debian-13-standard'),
            resources: [
                'cores' => (int) ($res['cores'] ?? 1),
                'memory' => (int) ($res['memory'] ?? 512),
                'swap' => (int) ($res['swap'] ?? 512),
                'disk' => (int) ($res['disk'] ?? 4),
            ],
            lxc: [
                'unprivileged' => (bool) $lxc['unprivileged'],
                'nesting' => (bool) $lxc['nesting'],
                'onboot' => (bool) $lxc['onboot'],
            ],
            tags: array_values(array_map(fn ($t) => strtolower((string) $t), (array) ($raw['tags'] ?? []))),
            parameters: $params,
            ports: array_values((array) ($raw['ports'] ?? [])),
            installScript: (string) file_get_contents($installPath),
            healthcheck: (array) ($raw['healthcheck'] ?? []),
            access: (array) ($raw['access'] ?? []),
            notes: trim((string) ($raw['notes'] ?? '')),
            iconPath: $icon,
            path: $path,
            updates: $updates,
        );
    }
}
