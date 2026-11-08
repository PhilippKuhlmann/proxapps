@props(['href', 'icon', 'value', 'label', 'ton' => 'blau'])

@php
    $toene = [
        'blau' => 'bg-cerulean-50 text-cerulean-600 group-hover:bg-cerulean-100 dark:bg-gray-700 dark:text-cerulean-400',
        'gruen' => 'bg-green-50 text-green-600 group-hover:bg-green-100 dark:bg-gray-700 dark:text-green-400',
        'rot' => 'bg-rose-50 text-rose-600 group-hover:bg-rose-100 dark:bg-gray-700 dark:text-rose-400',
    ];
@endphp

<a href="{{ $href }}"
    class="group flex items-center gap-2 px-2.5 py-2 bg-white rounded-lg border border-gray-200 shadow-xs transition hover:border-cerulean-300 hover:shadow-md dark:bg-gray-800 dark:border-gray-700 dark:hover:border-cerulean-500">
    <span class="flex items-center justify-center w-7 h-7 rounded-md transition-colors shrink-0 {{ $toene[$ton] ?? $toene['blau'] }}">
        <x-icon :name="$icon" class="w-4 h-4" sw="1.8" />
    </span>
    <span class="flex min-w-0 flex-col">
        <span class="text-base font-bold leading-none text-chathams-blue-800 dark:text-gray-100">{{ $value }}</span>
        <span class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $label }}</span>
    </span>
</a>
