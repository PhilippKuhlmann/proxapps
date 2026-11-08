<?php

namespace App\Livewire\Connections;

use App\Livewire\ConnectionStatus;
use App\Models\Connection;
use App\Models\Deployment;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Proxmox-Verbindungen')]
class Index extends Component
{
    public function test(int $id): void
    {
        $connection = Connection::query()->findOrFail($id);
        $info = ConnectionStatus::overview($connection, 10);

        if ($info['ok']) {
            $this->dispatch('notice', kind: 'success', text: "Verbindung OK – Proxmox VE {$info['version']}, "
                .count($info['nodes']).' Node(s), '.$info['guests'].' Gäste.');
        } else {
            $this->dispatch('notice', kind: 'error', text: (string) $info['error']);
        }
    }

    public function delete(int $id): void
    {
        $connection = Connection::query()->findOrFail($id);

        if (Deployment::query()->active()->where('connection_id', $connection->id)->exists()) {
            $this->dispatch('notice', kind: 'error', text: 'Die Verbindung wird gerade von einem laufenden Deployment genutzt.');

            return;
        }

        $connection->delete();
        $this->dispatch('notice', kind: 'success', text: "Verbindung „{$connection->name}“ gelöscht.");
    }

    public function render()
    {
        return view('livewire.connections.index', [
            'connections' => Connection::query()->withCount([
                'deployments',
                'deployments as active_count' => fn ($q) => $q->whereIn('status', Deployment::ACTIVE),
            ])->orderBy('name')->get(),
        ]);
    }
}
