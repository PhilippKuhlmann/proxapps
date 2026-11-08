{{--
    The square button that only carries an icon: pencil, trash, play …
    titel is used as title AND aria-label - the icon alone has no name for a screen reader.
    href turns it into an <a>.
--}}
@props([
    'titel',
    'ton' => 'blau',
    'href' => null,
])

@php
    $sockel = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 '
        .'bg-white shadow-xs transition-colors focus:outline-hidden focus:ring-2 focus:ring-offset-2 disabled:opacity-50 '
        .'dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700 dark:focus:ring-offset-gray-800';

    $toene = [
        'blau' => 'text-cerulean-600 hover:border-cerulean-300 hover:bg-cerulean-50 focus:ring-cerulean-500 dark:text-cerulean-400',
        'rot' => 'text-red-600 hover:border-red-300 hover:bg-red-50 focus:ring-red-500 dark:text-red-400',
        'grau' => 'text-gray-600 hover:border-gray-300 hover:bg-gray-50 focus:ring-cerulean-500 dark:text-gray-300',
    ];

    $klassen = $sockel.' '.($toene[$ton] ?? $toene['blau']);
@endphp

@if ($href)
    <a href="{{ $href }}" title="{{ $titel }}" aria-label="{{ $titel }}" {{ $attributes->merge(['class' => $klassen]) }}>
        {{ $slot }}
    </a>
@else
    <button type="button" title="{{ $titel }}" aria-label="{{ $titel }}" {{ $attributes->merge(['class' => $klassen]) }}>
        {{ $slot }}
    </button>
@endif
