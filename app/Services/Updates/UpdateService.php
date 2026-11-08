<?php

namespace App\Services\Updates;

use App\Jobs\RollbackUpdate;
use App\Jobs\RunUpdate;
use App\Models\Deployment;
use App\Models\UpdateRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entry points for the UI and the scheduler: which containers are managed, queue an
 * update (never two at once for the same container) or a rollback.
 */
class UpdateService
{
    /**
     * @return Collection<int, Deployment>
     */
    public function managed(): Collection
    {
        return Deployment::query()->managed()->with('connection')->orderBy('hostname')->get()
            ->filter(fn (Deployment $d) => $d->isManaged())->values();
    }

    public function hasActiveRun(Deployment $d): bool
    {
        return UpdateRun::query()->where('deployment_id', $d->id)->active()->exists();
    }

    /** Queue an update; returns null when the container is not manageable or one is running. */
    public function queue(Deployment $d, string $trigger = 'manual'): ?UpdateRun
    {
        if (! $d->isManaged()) {
            return null;
        }

        $run = DB::transaction(function () use ($d, $trigger) {
            if ($this->hasActiveRun($d)) {
                return null;
            }

            return UpdateRun::query()->create(['deployment_id' => $d->id, 'trigger' => $trigger]);
        });
        if ($run) {
            RunUpdate::dispatch($run->id);
        }

        return $run;
    }

    /** Queue a rollback to the snapshot taken before $run. */
    public function queueRollback(UpdateRun $run): bool
    {
        if (! $run->snapshot || ! $run->snapshot_exists || $run->isActive()
            || ! $run->deployment?->isManaged() || $this->hasActiveRun($run->deployment)) {
            return false;
        }
        RollbackUpdate::dispatch($run->id);

        return true;
    }
}
