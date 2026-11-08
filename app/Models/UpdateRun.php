<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One update of a deployed container (Debian packages + app), optionally guarded by a
 * Proxmox snapshot that can be rolled back.
 *
 * @property int $id
 * @property int $deployment_id
 * @property string $status
 * @property string $trigger
 * @property string|null $step
 * @property string|null $snapshot
 * @property bool $snapshot_exists
 * @property string|null $from_version
 * @property string|null $to_version
 * @property int|null $os_packages
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['deployment_id', 'status', 'trigger', 'step', 'snapshot', 'snapshot_exists', 'from_version',
    'to_version', 'os_packages', 'error', 'started_at', 'finished_at'])]
class UpdateRun extends Model
{
    public const ACTIVE = ['queued', 'running'];

    public const STEPS = [
        ['u_check', 'Prüfen'],
        ['u_snapshot', 'Snapshot'],
        ['u_os', 'Debian-Pakete'],
        ['u_app', 'App-Update'],
        ['u_verify', 'Funktionsprüfung'],
        ['u_cleanup', 'Aufräumen'],
    ];

    public const STATUS_LABELS = [
        'queued' => 'Eingeplant',
        'running' => 'Läuft',
        'success' => 'Erfolgreich',
        'failed' => 'Fehlgeschlagen',
        'rolled_back' => 'Zurückgerollt',
        'cancelled' => 'Abgebrochen',
    ];

    protected $attributes = [
        'status' => 'queued',
        'trigger' => 'manual',
        'snapshot_exists' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_exists' => 'boolean',
            'os_packages' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @param  Builder<UpdateRun>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public static function stepLabel(?string $step): string
    {
        foreach (self::STEPS as [$key, $label]) {
            if ($key === $step) {
                return $label;
            }
        }

        return $step === 'u_rollback' ? 'Rollback' : (string) $step;
    }
}
