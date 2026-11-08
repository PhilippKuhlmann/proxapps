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
                'success' => SendCoreEvent::send('deployment.succeeded', 'info', "{$d->app_name} auf {$where} ausgerollt", $d->ip ? "IP {$d->ip}" : null, ['deploymentId' => $d->id]),
                'failed' => SendCoreEvent::send('deployment.failed', 'critical', "Ausrollen von {$d->app_name} auf {$where} fehlgeschlagen", $d->error, ['deploymentId' => $d->id]),
                'cancelled' => SendCoreEvent::send('deployment.cancelled', 'warning', "Ausrollen von {$d->app_name} auf {$where} abgebrochen", null, ['deploymentId' => $d->id]),
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
