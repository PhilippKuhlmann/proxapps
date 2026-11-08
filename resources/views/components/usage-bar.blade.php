@props(['percent', 'label'])

{{-- Small usage bar (node CPU / RAM): green → amber → red. --}}
@php
    $p = $percent;
    $farbe = $p === null ? 'bg-gray-300 dark:bg-gray-600' : ($p >= 90 ? 'bg-red-500' : ($p >= 70 ? 'bg-amber-500' : 'bg-emerald-500'));
@endphp

<div class="flex min-w-0 items-center gap-2 text-xs">
    <span class="w-8 shrink-0 text-[10.5px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $label }}</span>
    <span class="relative h-1.5 w-full min-w-16 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
        <span class="absolute inset-y-0 left-0 rounded-full {{ $farbe }}" style="width: {{ (int) ($p ?? 0) }}%"></span>
    </span>
    <span class="w-9 shrink-0 text-right font-mono text-gray-600 dark:text-gray-300">{{ $p === null ? '—' : $p.' %' }}</span>
</div>
