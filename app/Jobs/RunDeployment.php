<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Services\Deploy\DeploymentLogger;
use App\Services\Deploy\DeploymentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

class RunDeployment implements ShouldQueue
{
    use Queueable;

    /**
     * Job timeout in seconds. Keep DB_QUEUE_RETRY_AFTER (Dockerfile) > worker --timeout
     * (docker/supervisord.conf) > this value. The runner stops itself 5 minutes earlier.
     */
    public const TIMEOUT = 7200;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $deploymentId) {}

    public function handle(DeploymentRunner $runner): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if ($deployment) {
            $runner->run($deployment);
        }
    }

    /**
     * The worker gave up on the job.
     *
     * - TimeoutExceeded (a subclass of MaxAttemptsExceeded): the run was killed → failed.
     * - MaxAttemptsExceeded: the job was reserved again after retry_after. That happens when the
     *   worker died (crash, OOM, restart) – or, in theory, while the first run is still going.
     *   Only mark the deployment failed when nothing happened on it for longer than the job
     *   timeout, so the original run is certainly dead.
     * - anything else: failed.
     */
    public function failed(?Throwable $e): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if (! $deployment || ! $deployment->isActive()) {
            return;
        }
        if ($e instanceof MaxAttemptsExceededException && ! $e instanceof TimeoutExceededException
            && ! $this->stale($deployment)) {
            return;
        }
        $message = $e instanceof TimeoutExceededException
            ? 'Abgebrochen: Das Zeitlimit des Hintergrundprozesses ('.intdiv($this->timeout, 60).' min) wurde überschritten.'
            : 'Abgebrochen: Der Hintergrundprozess wurde beendet ('.class_basename($e ?? 'Fehler').').';
        $deployment->forceFill(['status' => 'failed', 'error' => $message, 'finished_at' => now()])->save();
        DeploymentLogger::log($deployment, $message, 'error', $deployment->step);
    }

    private function stale(Deployment $deployment): bool
    {
        $limit = now()->subSeconds($this->timeout);
        $lastLog = DeploymentLog::query()->where('deployment_id', $deployment->id)->max('created_at');
        $last = max(array_filter([$deployment->updated_at?->getTimestamp(),
            $lastLog !== null ? strtotime((string) $lastLog) : null]) ?: [0]);

        return $last < $limit->getTimestamp();
    }
}
