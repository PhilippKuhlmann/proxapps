<?php

namespace App\Livewire\Updates;

use App\Jobs\CheckUpdates;
use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Central update overview: every managed container with installed/newest app version and
 * pending Debian packages; update one or all; settings for the nightly update window.
 */
#[Title('Updates')]
class Index extends Component
{
    public bool $auto_update_enabled = false;

    public string $auto_update_time = '03:00';

    public bool $snapshot_before_update = true;

    public bool $auto_rollback = true;

    public string $keep_snapshots = '3';

    /** Poll a while after "Jetzt prüfen" so the results show up without reloading. */
    public int $pollUntil = 0;

    public function mount(): void
    {
        $s = Settings::all();
        $this->auto_update_enabled = (bool) ($s['auto_update_enabled'] ?? false);
        $this->auto_update_time = (string) ($s['auto_update_time'] ?? '03:00');
        $this->snapshot_before_update = (bool) ($s['snapshot_before_update'] ?? true);
        $this->auto_rollback = (bool) ($s['auto_rollback'] ?? true);
        $this->keep_snapshots = (string) ($s['keep_snapshots'] ?? 3);
    }

    public function checkAll(): void
    {
        CheckUpdates::dispatch();
        $this->pollUntil = time() + 180;
        $this->dispatch('notice', kind: 'success', text: 'Prüfung gestartet – die Ergebnisse erscheinen gleich.');
    }

    public function checkOne(int $id): void
    {
        CheckUpdates::dispatch($id);
        $this->pollUntil = time() + 120;
    }

    public function update(int $id, UpdateService $updates): void
    {
        $d = Deployment::query()->find($id);
        if (! $d || ! $updates->queue($d)) {
            $this->dispatch('notice', kind: 'error', text: 'Update nicht möglich – läuft schon eines oder ist der Container nicht verwaltet?');

            return;
        }
        $this->dispatch('notice', kind: 'success', text: "Update für {$d->hostname} eingeplant.");
    }

    public function updateAll(UpdateService $updates): void
    {
        $count = 0;
        foreach ($updates->managed() as $d) {
            if ($d->hasUpdates() && $updates->queue($d)) {
                $count++;
            }
        }
        $this->dispatch('notice', kind: $count ? 'success' : 'error',
            text: $count ? "{$count} Update(s) eingeplant." : 'Keine Container mit ausstehenden Updates gefunden – bitte zuerst prüfen.');
    }

    public function saveSettings(): void
    {
        $this->resetErrorBag();
        if (! preg_match('~^([01]\d|2[0-3]):[0-5]\d$~D', $this->auto_update_time)) {
            $this->addError('auto_update_time', 'Uhrzeit im Format HH:MM, z. B. 03:00.');
        }
        $keep = filter_var($this->keep_snapshots, FILTER_VALIDATE_INT);
        if ($keep === false || $keep < 1 || $keep > 20) {
            $this->addError('keep_snapshots', 'Zwischen 1 und 20.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        Settings::put('auto_update_enabled', $this->auto_update_enabled);
        Settings::put('auto_update_time', $this->auto_update_time);
        Settings::put('snapshot_before_update', $this->snapshot_before_update);
        Settings::put('auto_rollback', $this->auto_rollback);
        Settings::put('keep_snapshots', $keep);
        $this->dispatch('notice', kind: 'success', text: 'Update-Einstellungen gespeichert.');
    }

    public function render(UpdateService $updates)
    {
        $deployments = $updates->managed();
        $ids = $deployments->pluck('id');
        $lastRuns = UpdateRun::query()->whereIn('deployment_id', $ids)
            ->whereIn('id', UpdateRun::query()->selectRaw('max(id)')->whereIn('deployment_id', $ids)->groupBy('deployment_id'))
            ->get()->keyBy('deployment_id');
        $active = UpdateRun::query()->active()->exists();

        return view('livewire.updates.index', [
            'deployments' => $deployments,
            'lastRuns' => $lastRuns,
            'poll' => $active || $this->pollUntil > time(),
            'pending' => $deployments->filter->hasUpdates()->count(),
            'tz' => (string) (Settings::get('timezone') ?: 'Europe/Berlin'),
        ]);
    }
}
