<?php

namespace App\Jobs;

use App\Models\UpdateRun;
use App\Services\Deploy\DeploymentLogger;
use App\Services\Updates\UpdateRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RollbackUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $updateRunId) {}

    public function handle(UpdateRunner $runner): void
    {
        $run = UpdateRun::query()->find($this->updateRunId);
        if (! $run || ! $run->deployment) {
            return;
        }
        try {
            $runner->rollback($run);
        } catch (Throwable $e) {
            DeploymentLogger::log($run->deployment, '✖ Rollback fehlgeschlagen: '.$e->getMessage(), 'error', 'u_rollback', $run->id);
        }
    }
}
