<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PK95 Core mode (app protocol v1): with PK95_CORE_MODE=1 PK95 Core runs this instance.
 * Core reaches the API with its service token; the own web interface is switched off.
 */
class Core
{
    public static function enabled(): bool
    {
        return (bool) config('proxapps.core.enabled');
    }

    public static function url(): string
    {
        return rtrim((string) config('proxapps.core.url'), '/');
    }

    public static function token(): string
    {
        return (string) config('proxapps.core.token');
    }

    /**
     * Settings Core shares with its apps (GET {core}/api/v1/context), cached for a minute.
     * Empty when Core is not reachable: then nothing is linked automatically.
     *
     * @return array{base_domain?: string, proxy?: bool, uptime?: bool}
     */
    public static function context(): array
    {
        if (! self::enabled() || self::url() === '') {
            return [];
        }

        return Cache::remember('core.context', 60, function () {
            try {
                $response = Http::withToken(self::token())->acceptJson()->timeout(5)->get(self::url().'/api/v1/context');

                return $response->successful() ? (array) $response->json() : [];
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Full domain for a deployment: "cloud" → cloud.<base domain>; a name with a dot stays as it is.
     */
    public static function domainFor(string $subdomain, string $baseDomain): string
    {
        $subdomain = strtolower(trim($subdomain, " .\t\n"));
        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return $subdomain;
        }

        return $baseDomain === '' ? '' : $subdomain.'.'.strtolower(trim($baseDomain, ' .'));
    }

    /** "Authorization: Bearer <token>" compared in constant time. */
    public static function authorized(Request $request): bool
    {
        $given = (string) $request->bearerToken();

        return self::enabled() && strlen(self::token()) >= 32 && $given !== '' && hash_equals(self::token(), $given);
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'id' => 'proxapps',
            'name' => 'ProxApps',
            'version' => (string) config('proxapps.version'),
            'protocol' => 1,
            'icon' => 'server',
            'menu' => [
                ['key' => 'dashboard', 'label' => 'Start', 'icon' => 'home'],
                ['key' => 'apps', 'label' => 'App-Katalog', 'icon' => 'apps'],
                ['key' => 'deployments', 'label' => 'Deployments', 'icon' => 'rocket'],
                ['key' => 'updates', 'label' => 'Updates', 'icon' => 'refresh'],
                ['key' => 'connections', 'label' => 'Proxmox-Verbindungen', 'icon' => 'server'],
                ['key' => 'settings', 'label' => 'Einstellungen', 'icon' => 'cog'],
            ],
            'capabilities' => ['events', 'backup'],
            'events' => ['deployment.succeeded', 'deployment.failed', 'deployment.cancelled', 'deployment.removed', 'update.succeeded', 'update.failed'],
        ];
    }
}
