<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Deploy\DeploymentLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deployments that were running when the process died can never finish. Run on container start.
 * Queued deployments are left alone: their job is still in the jobs table and runs after the restart.
 */
#[Signature('proxapps:recover')]
#[Description('Markiert unterbrochene Deployments (running) als fehlgeschlagen.')]
class RecoverDeployments extends Command
{
    public const MESSAGE = 'Abgebrochen: ProxApps wurde während des Ausrollens neu gestartet.';

    public function handle(): int
    {
        $count = 0;
        foreach (Deployment::query()->where('status', 'running')->get() as $d) {
            $d->forceFill(['status' => 'failed', 'error' => self::MESSAGE, 'finished_at' => now()])->save();
            DeploymentLogger::log($d, self::MESSAGE, 'error');
            $count++;
        }
        $this->info("{$count} unterbrochene(s) Deployment(s) als fehlgeschlagen markiert.");

        $updates = 0;
        foreach (UpdateRun::query()->where('status', 'running')->with('deployment')->get() as $run) {
            $msg = 'Abgebrochen: ProxApps wurde während des Updates neu gestartet.'
                .($run->snapshot && $run->snapshot_exists ? " Snapshot {$run->snapshot} steht für einen Rollback bereit." : '');
            $run->forceFill(['status' => 'failed', 'error' => $msg, 'finished_at' => now()])->save();
            if ($run->deployment) {
                DeploymentLogger::log($run->deployment, $msg, 'error', $run->step, $run->id);
            }
            $updates++;
        }
        $this->info("{$updates} unterbrochene(s) Update(s) als fehlgeschlagen markiert.");

        return self::SUCCESS;
    }
}
