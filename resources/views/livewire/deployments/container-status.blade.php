@php
    use App\Http\Support\Format;

    $status = $st['status'] ?? null;
    $label = ['running' => 'läuft', 'stopped' => 'gestoppt'][$status] ?? ($status ?: '—');
    $dot = match ($status) {
        'running' => 'bg-emerald-500',
        'stopped' => 'bg-gray-400',
        default => 'bg-amber-500',
    };
@endphp
<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Live-Status</span>
        <button type="button" wire:click="load" title="Aktualisieren" aria-label="Aktualisieren"
            class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-cerulean-600 dark:hover:bg-gray-700">
            <x-icon name="refresh" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="load" />
        </button>
    </div>
    @if ($st === null)
        <div class="text-sm text-gray-500 dark:text-gray-400">—</div>
    @else
        <div class="flex items-center gap-2 text-sm">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dot }}"></span>
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $label }}</span>
            @if ($status === 'running' && isset($st['uptime']))
                <span class="text-xs text-gray-500 dark:text-gray-400">seit {{ Format::uptime($st['uptime']) }}</span>
            @endif
        </div>
        @if (! empty($st['error']))
            <div class="mt-1 text-xs text-amber-700 wrap-anywhere dark:text-amber-400">{{ $st['error'] }}</div>
        @elseif ($status === 'running')
            <div class="mt-3 flex flex-col gap-1.5">
                <x-usage-bar label="CPU" :percent="is_numeric($st['cpu'] ?? null) ? (int) round($st['cpu'] * 100) : null" />
                <x-usage-bar label="RAM" :percent="Format::percent($st['mem'] ?? null, $st['maxmem'] ?? null)" />
            </div>
            <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                {{ Format::bytes($st['mem'] ?? null) }} von {{ Format::bytes($st['maxmem'] ?? null) }}{{ isset($st['cpus']) ? ' · '.$st['cpus'].' vCPU' : '' }}
            </div>
        @endif
    @endif
</div>
