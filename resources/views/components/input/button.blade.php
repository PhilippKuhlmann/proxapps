@props([
    'label' => null,
    'type' => 'submit',
    'color' => 'blue',
    'size' => 'md',
])

@php
    $base = 'items-center justify-center gap-1.5 rounded-lg font-DINPro-bold shadow-xs transition-colors focus:outline-hidden focus:ring-2 focus:ring-offset-2 disabled:opacity-50 dark:focus:ring-offset-gray-800';

    // "feld" matches the 42px height of x-input.text / select; "blatt" is the full-width
    // button below a guest form.
    $sizes = [
        'md' => 'inline-flex px-4 py-2 text-sm',
        'sm' => 'inline-flex px-3 py-1.5 text-xs',
        'feld' => 'inline-flex shrink-0 whitespace-nowrap px-3 py-2 text-sm leading-6 border border-transparent',
        'blatt' => 'flex w-full px-4 py-2.5',
    ];

    $variants = [
        'blue' => 'bg-cerulean-600 text-white hover:bg-cerulean-700 focus:ring-cerulean-500',
        'red' => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
        'gray' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-cerulean-500 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600',
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$color] ?? $variants['blue']);
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $label ?? $slot }}
</button>
