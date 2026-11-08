<?php

namespace App\Jobs;

use App\Models\UpdateRun;
use App\Services\Deploy\DeploymentLogger;
use App\Services\Updates\UpdateRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunUpdate implements ShouldQueue
{
    use Queueable;

    /** Below the worker --timeout (7300) and DB_QUEUE_RETRY_AFTER (7600). */
    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $updateRunId) {}

    public function handle(UpdateRunner $runner): void
    {
        $run = UpdateRun::query()->find($this->updateRunId);
        if ($run) {
            $runner->run($run);
        }
    }

    /** Worker timeout or crash: never leave the run "running" forever. */
    public function failed(?Throwable $e): void
    {
        $run = UpdateRun::query()->find($this->updateRunId);
        if ($run && $run->isActive()) {
            $msg = 'Update abgebrochen: '.($e ? class_basename($e) : 'unbekannter Fehler').'.';
            $run->forceFill(['status' => 'failed', 'error' => $msg, 'finished_at' => now()])->save();
            if ($run->deployment) {
                DeploymentLogger::log($run->deployment, $msg, 'error', $run->step, $run->id);
            }
        }
    }
}
