@php($i = $info)
<div @class([
    'rounded-xl border bg-white px-4 py-3 shadow-xs dark:bg-gray-800',
    'border-gray-200 dark:border-gray-700' => $i['ok'],
    'border-red-300 dark:border-red-700' => ! $i['ok'],
])>
    <div class="flex min-h-8 items-center gap-3">
        <span @class(['h-3 w-3 shrink-0 rounded-full', 'bg-emerald-500' => $i['ok'], 'bg-red-500' => ! $i['ok']])
            title="{{ $i['ok'] ? 'erreichbar' : 'nicht erreichbar' }}"></span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                <a href="{{ route('connections.edit', $connection) }}"
                    class="shrink-0 font-semibold text-gray-900 hover:text-cerulean-600 dark:text-gray-100 dark:hover:text-cerulean-400">{{ $connection->name }}</a>
                <span class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ $connection->url }}</span>
            </div>
            @if ($i['ok'])
                <div class="truncate text-xs text-gray-600 dark:text-gray-300">
                    Proxmox VE {{ $i['version'] ?? '?' }} · {{ count($i['nodes']) }} Node(s) · {{ $i['guests'] }} Gäste
                </div>
            @else
                <div class="text-xs text-red-700 wrap-anywhere dark:text-red-300">{{ $i['error'] }}</div>
            @endif
        </div>
        <button type="button" wire:click="refresh" title="Neu abfragen" aria-label="Neu abfragen"
            class="shrink-0 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-cerulean-600 dark:hover:bg-gray-700">
            <x-icon name="refresh" class="h-4 w-4" wire:loading.class="animate-spin" />
        </button>
    </div>

    @if ($i['ok'] && $i['nodes'])
        <div class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 border-t border-gray-100 pt-3 sm:grid-cols-2 xl:grid-cols-3 dark:border-gray-700">
            @foreach ($i['nodes'] as $n)
                <div class="min-w-0">
                    <div class="mb-1 flex items-center gap-2 text-sm">
                        <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => ($n['status'] ?? '') === 'online', 'bg-red-500' => ($n['status'] ?? '') !== 'online'])></span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $n['node'] }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            {{ ($n['status'] ?? '') === 'online' ? ($n['maxcpu'] ? $n['maxcpu'].' CPU · '.\App\Http\Support\Format::bytes($n['maxmem']) : 'online') : 'offline' }}
                        </span>
                    </div>
                    @if (($n['status'] ?? '') === 'online')
                        <x-usage-bar label="CPU" :percent="is_numeric($n['cpu'] ?? null) ? (int) round($n['cpu'] * 100) : null" />
                        <x-usage-bar label="RAM" :percent="\App\Http\Support\Format::percent($n['mem'] ?? null, $n['maxmem'] ?? null)" />
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
