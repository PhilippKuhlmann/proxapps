<?php

namespace App\Providers;

use App\Jobs\SendCoreEvent;
use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Catalog\Catalog;
use App\Services\Ipam;
use App\Services\Proxmox\ClientFactory;
use App\Services\Ssh\DeployerKey;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: queue workers run for hours and reset scoped instances between
        // jobs, so a changed catalog or a regenerated deployer key is picked up by the next job.
        $this->app->scoped(Catalog::class, fn () => new Catalog((string) config('proxapps.catalog_dir')));
        $this->app->scoped(DeployerKey::class, fn () => new DeployerKey);
        $this->app->singleton(Ipam::class);
        $this->app->singleton(ClientFactory::class);
    }

    /**
     * Event data PK95 Core needs to link a deployment with the proxy and monitoring.
     *
     * @return array<string, mixed>
     */
    public static function linkData(Deployment $d): array
    {
        $spec = (array) $d->spec;
        $result = (array) $d->result;

        return [
            'deploymentId' => $d->id,
            'appId' => $d->app_id,
            'appName' => $d->app_name,
            'hostname' => $d->hostname,
            'vmid' => $d->vmid,
            'ip' => $d->ip,
            'domain' => (string) ($spec['domain'] ?? ''),
            // false: the proxy entry was switched off in the deploy form.
            'proxy' => (bool) ($spec['proxy'] ?? true),
            'proxyUrl' => (string) ($result['proxy_url'] ?? ''),
        ];
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // PK95 Core mode: finished deployments and updates become events in Core.
        Deployment::updated(function (Deployment $d) {
            if (! $d->wasChanged('status')) {
                return;
            }
            $where = $d->hostname.($d->vmid ? " (CT {$d->vmid})" : '');
            match ($d->status) {
                'success' => SendCoreEvent::send('deployment.succeeded', 'info', "{$d->app_name} auf {$where} ausgerollt", $d->ip ? "IP {$d->ip}" : null, self::linkData($d)),
                'failed' => SendCoreEvent::send('deployment.failed', 'critical', "Ausrollen von {$d->app_name} auf {$where} fehlgeschlagen", $d->error, ['deploymentId' => $d->id]),
                'cancelled' => SendCoreEvent::send('deployment.cancelled', 'warning', "Ausrollen von {$d->app_name} auf {$where} abgebrochen", null, ['deploymentId' => $d->id]),
                // Container removed: PK95 Core deletes the proxy host and monitors it created.
                'removed' => SendCoreEvent::send('deployment.removed', 'info', "{$d->app_name} auf {$where} entfernt", null, self::linkData($d)),
                default => null,
            };
        });
        UpdateRun::updated(function (UpdateRun $run) {
            if (! $run->wasChanged('status')) {
                return;
            }
            $d = $run->deployment;
            $name = $d ? "{$d->app_name} auf {$d->hostname}" : "Update {$run->id}";
            match ($run->status) {
                'success' => SendCoreEvent::send('update.succeeded', 'info', "{$name} aktualisiert", $run->to_version ? "Version {$run->to_version}" : null, ['updateRunId' => $run->id]),
                'failed' => SendCoreEvent::send('update.failed', 'critical', "Update von {$name} fehlgeschlagen", $run->error, ['updateRunId' => $run->id]),
                default => null,
            };
        });
    }
}
