{{-- One dashboard tile: heading on top, scrolling content below, fixed height. --}}
@props(['title'])

<x-panel {{ $attributes->merge(['class' => 'flex h-96 flex-col']) }}>
    <div class="mb-4 flex shrink-0 items-center justify-between gap-3">
        <div class="text-2xl font-CoconPro text-gray-900 dark:text-gray-100">{{ $title }}</div>
        @isset($aktion)
            <div class="shrink-0">{{ $aktion }}</div>
        @endisset
    </div>
    <div class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2">
        {{ $slot }}
    </div>
</x-panel>
