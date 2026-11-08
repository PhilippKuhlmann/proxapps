@props(['message' => 'Noch keine Einträge vorhanden.'])

<div {{ $attributes->merge(['class' => 'm-3 flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-white/50 px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-800/40']) }}>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.3" stroke="currentColor" class="h-10 w-10 text-gray-300 dark:text-gray-600" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
    </svg>
    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $message }}</div>
    @if (trim((string) $slot) !== '')
        <div class="mt-2">{{ $slot }}</div>
    @endif
</div>
