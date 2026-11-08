<?php

namespace App\Livewire\Deployments;

use App\Http\Support\Format;
use App\Jobs\CheckUpdates;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\UpdateRun;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\ContainerManager;
use App\Services\Deploy\DeployFormService;
use App\Services\Updates\UpdateService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Deployment detail: polls every 1.5 s while the deployment is active, streams new log
 * lines into the (wire:ignore'd) Alpine console, and offers the container actions.
 */
class Show extends Component
{
    public const ACTION_LABELS = [
        'start' => 'gestartet',
        'shutdown' => 'heruntergefahren',
        'stop' => 'gestoppt',
        'reboot' => 'neu gestartet',
    ];

    public Deployment $deployment;

    #[Locked]
    public int $lastLogId = 0;

    /**
     * Whether the user asked to see the passwords. Only this flag lives in the component
     * state; the secrets themselves are decrypted per render and never enter the snapshot.
     */
    public bool $showSecrets = false;

    /** Only the first render ships the existing log; later renders leave the console alone. */
    protected bool $firstRender = false;

    public function mount(Deployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->lastLogId = (int) $deployment->logs()->max('id');
        $this->firstRender = true;
    }

    /**
     * @return list<array{id: int, level: string, step: ?string, message: string, time: string}>
     */
    public static function logLines(iterable $logs): array
    {
        $out = [];
        foreach ($logs as $l) {
            $out[] = [
                'id' => (int) $l->id,
                'level' => (string) $l->level,
                'step' => $l->step,
                'message' => (string) $l->message,
                'time' => Format::time($l->created_at),
            ];
        }

        return $out;
    }

    /** Called by wire:poll while the deployment is active. */
    public function refreshLive(): void
    {
        $this->deployment->refresh();
        $new = DeploymentLog::query()->where('deployment_id', $this->deployment->id)
            ->where('id', '>', $this->lastLogId)->orderBy('id')->limit(2000)->get();
        if ($new->isNotEmpty()) {
            $this->lastLogId = (int) $new->last()->id;
            $this->dispatch('log-lines', lines: static::logLines($new));
        }
    }

    public function cancel(): void
    {
        $this->deployment->refresh();
        if (! $this->deployment->isActive()) {
            $this->dispatch('notice', kind: 'error', text: 'Das Deployment läuft nicht mehr.');

            return;
        }
        // Still waiting in the queue: cancel it right away (atomically - a worker may claim it
        // at the same moment, then the runner sees cancel_requested instead).
        $flipped = Deployment::query()->whereKey($this->deployment->id)->where('status', 'queued')->update([
            'status' => 'cancelled',
            'cancel_requested' => true,
            'error' => 'Vom Benutzer abgebrochen.',
            'finished_at' => now(),
        ]);
        if ($flipped > 0) {
            $this->deployment->refresh();
            $this->dispatch('notice', kind: 'success', text: 'Deployment abgebrochen.');

            return;
        }
        $this->deployment->update(['cancel_requested' => true]);
        $this->dispatch('notice', kind: 'success', text: 'Abbruch angefordert.');
    }

    public function action(string $action, ContainerManager $manager): void
    {
        $d = $this->deployment->refresh();
        if (! isset(self::ACTION_LABELS[$action]) || ! static::containerActionsAvailable($d)) {
            $this->dispatch('notice', kind: 'error', text: 'Aktion nicht möglich.');

            return;
        }
        try {
            $manager->action($d, $action);
            $this->dispatch('notice', kind: 'success', text: "Container {$d->vmid} ".self::ACTION_LABELS[$action].'.');
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
        $this->refreshLive();
        $this->dispatch('ct-status-refresh');
    }

    public function destroyContainer(ContainerManager $manager): void
    {
        $d = $this->deployment->refresh();
        if (! static::containerActionsAvailable($d)) {
            $this->dispatch('notice', kind: 'error', text: 'Container kann gerade nicht entfernt werden.');

            return;
        }
        try {
            $manager->destroy($d);
            $this->dispatch('notice', kind: 'success', text: "Container {$d->vmid} wurde entfernt.");
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
        $this->refreshLive();
    }

    public function deleteEntry()
    {
        $d = $this->deployment->refresh();
        if ($d->isActive()) {
            $this->dispatch('notice', kind: 'error', text: 'Laufende Deployments können nicht gelöscht werden.');

            return null;
        }
        $hostname = $d->hostname;
        $d->delete();
        session()->flash('success', "Eintrag für {$hostname} gelöscht (der Container selbst bleibt unberührt).");

        return $this->redirectRoute('deployments.index');
    }

    public function toggleReveal(): void
    {
        $this->showSecrets = ! $this->showSecrets;
    }

    /**
     * @return array{root_password: string, password: string}
     */
    protected function revealedSecrets(Deployment $d): array
    {
        $secrets = (array) ($d->secrets ?? []);
        $result = (array) ($d->result ?? []);
        $out = ['root_password' => (string) ($secrets['root_password'] ?? ''), 'password' => ''];
        if ($key = $result['password_param'] ?? null) {
            $out['password'] = (string) ($secrets['params'][$key] ?? '');
        }

        return $out;
    }

    /**
     * Start/stop/remove only make sense while a container exists: after a successful run,
     * or after a failed/cancelled one that got as far as creating it and did not remove it
     * again automatically. Never for "removed" entries or ones without a connection.
     */
    public static function containerActionsAvailable(Deployment $d): bool
    {
        if (! $d->connection_id || $d->isActive()) {
            return false;
        }
        if ($d->status === 'success') {
            return true;
        }
        $result = (array) ($d->result ?? []);

        return in_array($d->status, ['failed', 'cancelled'], true)
            && ! empty($result['container_created'])
            && empty($result['container_removed']);
    }

    /**
     * Step tracker state: done / active / error / pending (port of app.js stepState).
     */
    public function stepState(string $key): string
    {
        $d = $this->deployment;
        $order = array_column(DeployFormService::STEPS, 0);
        $cur = array_search($d->step, $order, true);
        $idx = array_search($key, $order, true);
        if ($d->status === 'success' || $d->step === 'done') {
            return 'done';
        }
        if ($cur === false) {
            return $d->status === 'removed' ? 'done' : 'pending';
        }
        if ($idx < $cur) {
            return 'done';
        }
        if ($idx === $cur) {
            if (in_array($d->status, ['failed', 'cancelled'], true)) {
                return 'error';
            }

            return $d->status === 'running' ? 'active' : 'pending';
        }

        return 'pending';
    }

    // -- central updates ------------------------------------------------------------

    public function checkUpdates(): void
    {
        if (! $this->deployment->isManaged()) {
            return;
        }
        CheckUpdates::dispatch($this->deployment->id);
        $this->dispatch('notice', kind: 'success', text: 'Prüfung gestartet – das Ergebnis erscheint gleich.');
    }

    public function startUpdate(UpdateService $updates): void
    {
        if (! $updates->queue($this->deployment)) {
            $this->dispatch('notice', kind: 'error', text: 'Update nicht möglich – läuft schon eines oder ist der Container nicht verwaltet?');

            return;
        }
        $this->dispatch('notice', kind: 'success', text: 'Update eingeplant.');
    }

    public function rollback(int $runId, UpdateService $updates): void
    {
        $run = UpdateRun::query()->where('deployment_id', $this->deployment->id)->find($runId);
        if (! $run || ! $updates->queueRollback($run)) {
            $this->dispatch('notice', kind: 'error', text: 'Rollback nicht möglich (kein Snapshot vorhanden oder ein Update läuft).');

            return;
        }
        $this->dispatch('notice', kind: 'success', text: "Rollback auf {$run->snapshot} eingeplant.");
    }

    public function render(Catalog $catalog)
    {
        $d = $this->deployment;
        $initial = $this->firstRender
            ? $d->logs()->reorder()->orderByDesc('id')->limit(5000)->get()->reverse()->values()
            : [];

        return view('livewire.deployments.show', [
            'd' => $d,
            'spec' => (array) $d->spec,
            'result' => (array) ($d->result ?? []),
            'app' => $catalog->get($d->app_id),
            'steps' => DeployFormService::STEPS,
            'initialLines' => static::logLines($initial),
            'hasRootPassword' => ! empty(($d->secrets ?? [])['root_password'] ?? null),
            'revealed' => $this->showSecrets ? $this->revealedSecrets($d) : null,
            'canManage' => static::containerActionsAvailable($d),
            'managed' => $d->isManaged(),
            'updateRuns' => $d->updateRuns()->limit(10)->get(),
            'updateActive' => UpdateRun::query()->where('deployment_id', $d->id)->active()->exists(),
        ])->title($d->hostname.' · Deployment #'.$d->id);
    }
}
