<?php

namespace App\Services\Catalog;

/**
 * One app of the catalog (catalog/<id>/app.yaml + install script).
 */
final class AppDefinition
{
    /**
     * @param  array{cores: int, memory: int, swap: int, disk: int}  $resources
     * @param  array{unprivileged: bool, nesting: bool, onboot: bool}  $lxc
     * @param  list<string>  $tags
     * @param  list<array{key: string, label: string, type: string, default: mixed, required: bool, help: string, generate: bool, min_length: ?int, pattern: ?string, min: ?int, max: ?int, options: list<array<string, mixed>>}>  $parameters
     * @param  list<array<string, mixed>>  $ports
     * @param  array<string, mixed>  $healthcheck
     * @param  array<string, mixed>  $access
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $summary,
        public readonly string $description,
        public readonly string $category,
        public readonly string $version,
        public readonly string $website,
        public readonly string $source,
        public readonly string $hostname,
        public readonly string $template,
        public readonly array $resources,
        public readonly array $lxc,
        public readonly array $tags,
        public readonly array $parameters,
        public readonly array $ports,
        public readonly string $installScript,
        public readonly array $healthcheck,
        public readonly array $access,
        public readonly string $notes,
        public readonly ?string $iconPath,
        public readonly string $path = '',
        /** @var array{script: ?string, installed_version: string, latest_version: array{url: string, json: string}} */
        public readonly array $updates = ['script' => null, 'installed_version' => '', 'latest_version' => ['url' => '', 'json' => '']],
    ) {}

    public function hasAppUpdate(): bool
    {
        return ! empty($this->updates['script']);
    }

    /**
     * @return array{key: string, label: string, type: string, default: mixed, required: bool, help: string, generate: bool, min_length: ?int, pattern: ?string, min: ?int, max: ?int, options: list<array<string, mixed>>}|null
     */
    public function param(string $key): ?array
    {
        foreach ($this->parameters as $p) {
            if ($p['key'] === $key) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Keys of parameters whose values are secrets (type "password").
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return array_values(array_map(fn ($p) => $p['key'],
            array_filter($this->parameters, fn ($p) => $p['type'] === 'password')));
    }
}
