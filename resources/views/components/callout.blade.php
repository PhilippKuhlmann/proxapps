@props(['title', 'ton' => 'blau'])

{{-- Info card (cerulean) or warning card (amber) with optional buttons on the right. --}}
@php
    $toene = [
        'blau' => ['rounded-xl border border-cerulean-200 bg-cerulean-50 hover:border-cerulean-400 dark:bg-cerulean-900/10 dark:border-cerulean-800 dark:hover:border-cerulean-600',
            'text-cerulean-900 dark:text-cerulean-200', 'text-cerulean-700 dark:text-cerulean-400'],
        'gelb' => ['rounded-xl border border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-900/20',
            'text-amber-900 dark:text-amber-200', 'text-amber-800 dark:text-amber-300'],
    ];
    [$rahmen, $kopf, $text] = $toene[$ton] ?? $toene['blau'];
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-3 p-4 shadow-xs transition '.$rahmen]) }}>
    <div class="min-w-0 flex-1">
        <div class="font-DINPro-bold {{ $kopf }}">{{ $title }}</div>
        <div class="text-sm {{ $text }}">{{ $slot }}</div>
    </div>
    @isset($aktionen)
        <div class="flex shrink-0 items-center gap-2">{{ $aktionen }}</div>
    @endisset
</div>
