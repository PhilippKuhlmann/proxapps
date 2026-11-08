<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $deployment_id
 * @property string $level
 * @property string|null $step
 * @property string $message
 * @property Carbon|null $created_at
 */
#[Fillable(['deployment_id', 'update_run_id', 'level', 'step', 'message'])]
class DeploymentLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
