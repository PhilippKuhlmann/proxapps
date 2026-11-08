<?php

namespace App\Livewire;

use App\Models\Connection;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Throwable;

/**
 * Status line of one Proxmox connection on the dashboard. Lazy, so a slow or unreachable
 * server never blocks the page; queried with a short timeout.
 */
#[Lazy]
class ConnectionStatus extends Component
{
    public Connection $connection;

    /** @var array{ok: bool, error: ?string, version: ?string, nodes: array, guests: int} */
    public array $info = [];

    public function mount(Connection $connection): void
    {
        $this->connection = $connection;
        $this->info = static::overview($connection);
    }

    public function refresh(): void
    {
        $this->info = static::overview($this->connection);
    }

    /**
     * @return array{ok: bool, error: ?string, version: ?string, nodes: array, guests: int}
     */
    public static function overview(Connection $connection, int $timeout = 8): array
    {
        $out = ['ok' => false, 'error' => null, 'version' => null, 'nodes' => [], 'guests' => 0];
        try {
            $client = $connection->client($timeout);
            $version = $client->version();
            $nodes = $client->nodes();
            $guests = $client->guests();
            $out['ok'] = true;
            $out['version'] = $version['version'] ?? null;
            $out['nodes'] = $nodes;
            $out['guests'] = count(array_filter($guests, fn ($g) => empty($g['template'])));
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex min-h-14 items-center gap-3 rounded-xl border bg-white px-4 py-3 shadow-xs dark:bg-gray-800 border-gray-200 dark:border-gray-700">
            <span class="h-3 w-3 shrink-0 rounded-full bg-gray-300 animate-pulse dark:bg-gray-600"></span>
            <div class="min-w-0 flex-1 text-sm text-gray-500 dark:text-gray-400">Verbindung wird abgefragt …</div>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.connection-status');
    }
}
