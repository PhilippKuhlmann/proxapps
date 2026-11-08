<?php

namespace App\Providers;

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
        //
    }
}
