<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\Deploy\ContainerManager;
use App\Services\Deploy\DeploymentLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Removes a deployed container in the background so the page can show the steps
 * (Deployment::REMOVE_STEPS). On failure the deployment returns to its previous status.
 */
class RemoveContainer implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $deploymentId, public string $previousStatus) {}

    /**
     * Mark the deployment as "removing" and queue the job. Quietly: status changes of a
     * removal must not look like a new deployment result to the observer (events to Core).
     */
    public static function start(Deployment $d): void
    {
        $previous = $d->status;
        $result = array_merge((array) ($d->result ?? []), ['removal_error' => null]);
        $d->forceFill(['status' => 'removing', 'step' => 'r_check', 'result' => $result])->saveQuietly();
        DeploymentLogger::log($d, "Entfernen von Container {$d->vmid} eingeplant.", 'info', 'r_check');
        self::dispatch($d->id, $previous);
    }

    public function handle(ContainerManager $manager): void
    {
        $d = Deployment::query()->find($this->deploymentId);
        if (! $d || $d->status !== 'removing') {
            return;
        }
        try {
            $manager->destroy($d, fn (string $step) => $d->forceFill(['step' => $step])->saveQuietly());
        } catch (Throwable $e) {
            $this->fail($d, $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $d = Deployment::query()->find($this->deploymentId);
        if ($d && $d->status === 'removing') {
            $this->fail($d, $e?->getMessage() ?: 'Abgebrochen.');
        }
    }

    private function fail(Deployment $d, string $message): void
    {
        $result = array_merge((array) ($d->result ?? []), ['removal_error' => $message, 'removal_step' => $d->step]);
        $d->forceFill(['status' => $this->previousStatus, 'step' => $this->previousStatus === 'success' ? 'done' : $d->step, 'result' => $result])->saveQuietly();
        DeploymentLogger::log($d, 'Entfernen fehlgeschlagen: '.$message, 'error', $result['removal_step']);
    }
}
