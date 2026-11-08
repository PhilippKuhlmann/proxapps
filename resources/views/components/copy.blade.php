@props(['value', 'show' => true])

{{-- A value with a small copy button next to it. show=false renders only the button. --}}
@php($val = trim((string) $value))

@if ($val === '')
    <span class="text-gray-400 dark:text-gray-500">—</span>
@else
    <span x-data="{ copied: false }" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 min-w-0']) }}>
        @if ($show)
            <span class="min-w-0 wrap-anywhere">{{ $value }}</span>
        @endif
        <button type="button" title="Kopieren" aria-label="Kopieren"
            x-on:click="$kopieren(@js($val))"
            class="shrink-0 text-gray-400 hover:text-cerulean-600 dark:text-gray-500 dark:hover:text-gray-300 focus:outline-hidden">
            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
            </svg>
            <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-4 h-4 text-green-600 dark:text-green-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </button>
    </span>
@endif
