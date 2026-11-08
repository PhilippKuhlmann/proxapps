<?php

namespace App\Services\Deploy;

use App\Models\Deployment;
use App\Models\DeploymentLog;

class DeploymentLogger
{
    public const LEVELS = ['info', 'step', 'task', 'out', 'error', 'success'];

    public static function log(Deployment $d, string $message, string $level = 'info', ?string $step = null,
        ?int $updateRunId = null): void
    {
        DeploymentLog::query()->create([
            'deployment_id' => $d->id,
            'update_run_id' => $updateRunId,
            'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
            'step' => $step,
            'message' => mb_substr(mb_scrub($message, 'UTF-8'), 0, 4000),
        ]);
    }
}
