<?php

namespace App\Livewire\Deployments;

use App\Models\Deployment;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Live status of the container on Proxmox (lazy: never blocks the detail page).
 */
#[Lazy]
class ContainerStatus extends Component
{
    public Deployment $deployment;

    /** @var array<string, mixed>|null */
    public ?array $st = null;

    public function mount(Deployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->load();
    }

    #[On('ct-status-refresh')]
    public function load(): void
    {
        $d = $this->deployment->refresh();
        if (in_array($d->status, ['queued', 'running', 'removed'], true) || ! $d->connection) {
            $this->st = null;

            return;
        }
        try {
            $s = $d->connection->client(8)->lxcStatus($d->node, (int) $d->vmid);
            $this->st = [
                'status' => $s['status'] ?? null,
                'uptime' => $s['uptime'] ?? null,
                'mem' => $s['mem'] ?? null,
                'maxmem' => $s['maxmem'] ?? null,
                'cpu' => $s['cpu'] ?? null,
                'cpus' => $s['cpus'] ?? null,
                'name' => $s['name'] ?? null,
            ];
        } catch (Throwable $e) {
            $this->st = ['status' => 'unbekannt', 'error' => $e->getMessage()];
        }
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Live-Status</div>
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <span class="h-2.5 w-2.5 rounded-full bg-gray-300 animate-pulse dark:bg-gray-600"></span>wird abgefragt …
            </div>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.deployments.container-status');
    }
}
