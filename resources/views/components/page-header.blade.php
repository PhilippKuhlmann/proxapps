@props(['title', 'back' => null, 'backLabel' => 'Zurück'])

{{-- List page header: title (optionally with a back link) left, actions right. --}}
<div class="flex flex-wrap w-full items-center justify-between gap-3 px-3 pt-4 pb-1">
    <div class="flex min-w-0 flex-col">
        @if ($back)
            <a href="{{ $back }}"
                class="mb-1 inline-flex w-fit items-center gap-1 text-sm text-gray-500 hover:text-cerulean-600 dark:text-gray-400 dark:hover:text-cerulean-400">
                <x-icon name="back" class="h-4 w-4" sw="2" />{{ $backLabel }}
            </a>
        @endif
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-CoconPro text-gray-900 dark:text-gray-100">
            {{ $title }}
            @isset($badge)
                {{ $badge }}
            @endisset
        </h1>
        @isset($sub)
            <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $sub }}</div>
        @endisset
    </div>
    @if (trim((string) $slot) !== '')
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">{{ $slot }}</div>
    @endif
</div>
