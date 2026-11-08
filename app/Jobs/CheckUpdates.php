<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Checks one container (deploymentId) or all managed containers (null) for updates. */
class CheckUpdates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $deploymentId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->deploymentId ?? 'all');
    }

    public function handle(UpdateChecker $checker, UpdateService $updates): void
    {
        $targets = $this->deploymentId
            ? Deployment::query()->whereKey($this->deploymentId)->get()->filter->isManaged()
            : $updates->managed();
        foreach ($targets as $d) {
            if ($updates->hasActiveRun($d)) {
                continue;
            }
            try {
                $checker->check($d);
            } catch (Throwable $e) {
                $d->forceFill(['update_check_error' => mb_substr($e->getMessage(), 0, 500), 'updates_checked_at' => now()])->save();
            }
        }
    }
}
