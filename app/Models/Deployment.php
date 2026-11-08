<?php

namespace App\Models;

use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rollout of a catalog app as an LXC container.
 *
 * @property int $id
 * @property string $app_id
 * @property string $app_name
 * @property int|null $connection_id
 * @property string $node
 * @property int $vmid
 * @property string $hostname
 * @property string|null $ip
 * @property string $status
 * @property string|null $step
 * @property array $spec
 * @property array|null $secrets
 * @property array|null $result
 * @property string|null $error
 * @property bool $cancel_requested
 * @property Carbon|null $finished_at
 */
#[Fillable(['app_id', 'app_name', 'connection_id', 'node', 'vmid', 'hostname', 'ip', 'status', 'step', 'spec',
    'secrets', 'result', 'error', 'cancel_requested', 'finished_at',
    'app_version', 'latest_version', 'os_updates', 'updates_checked_at', 'update_check_error'])]
#[Hidden(['secrets'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    public const ACTIVE = ['queued', 'running'];

    public const FINAL = ['success', 'failed', 'cancelled', 'removed'];

    protected $attributes = [
        'status' => 'queued',
        'cancel_requested' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vmid' => 'integer',
            'spec' => 'array',
            'secrets' => 'encrypted:array',
            'result' => 'array',
            'cancel_requested' => 'boolean',
            'finished_at' => 'datetime',
            'os_updates' => 'integer',
            'updates_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<UpdateRun, $this>
     */
    public function updateRuns(): HasMany
    {
        return $this->hasMany(UpdateRun::class)->orderByDesc('id');
    }

    /**
     * Deployments whose container exists and is managed by ProxApps (target of updates).
     *
     * @param  Builder<Deployment>  $query
     */
    public function scopeManaged(Builder $query): void
    {
        $query->where('status', 'success')->whereNotNull('connection_id');
    }

    public function isManaged(): bool
    {
        return $this->status === 'success' && $this->connection_id !== null
            && empty(((array) ($this->result ?? []))['container_removed']);
    }

    /** True when the app or Debian packages have pending updates (as of the last check). */
    public function hasUpdates(): bool
    {
        return ($this->os_updates ?? 0) > 0 || $this->appUpdateAvailable();
    }

    public function appUpdateAvailable(): bool
    {
        return $this->latest_version !== null && $this->app_version !== null
            && ltrim($this->latest_version, 'vV') !== ltrim($this->app_version, 'vV');
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return HasMany<DeploymentLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class)->orderBy('id');
    }

    /**
     * @param  Builder<Deployment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
