@props([
    'href',
    'color' => 'blue',
    'size' => 'md',
])

{{-- A link that looks like x-input.button (same classes). --}}
@php
    $base = 'items-center justify-center gap-1.5 rounded-lg font-DINPro-bold shadow-xs transition-colors focus:outline-hidden focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800';
    $sizes = [
        'md' => 'inline-flex px-4 py-2 text-sm',
        'sm' => 'inline-flex px-3 py-1.5 text-xs',
    ];
    $variants = [
        'blue' => 'bg-cerulean-600 text-white hover:bg-cerulean-700 focus:ring-cerulean-500',
        'red' => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
        'gray' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-cerulean-500 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600',
        'tint' => 'border border-cerulean-300 bg-white text-cerulean-700 shadow-none hover:bg-cerulean-100 focus:ring-cerulean-500 dark:bg-transparent dark:border-cerulean-700 dark:text-cerulean-300 dark:hover:bg-cerulean-900/30',
    ];
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$color] ?? $variants['blue'])]) }}>
    {{ $slot }}
</a>
