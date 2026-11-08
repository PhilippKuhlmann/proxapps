@props(['title', 'first' => false])

{{-- Section divider inside a form; an optional slot sits on the right of the title. --}}
<div {{ $attributes->class([
    'flex flex-wrap items-center justify-between gap-2',
    'border-t border-gray-100 pt-4 mt-6 dark:border-gray-700' => ! $first,
    'mt-4' => $first,
]) }}>
    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $title }}</span>
    {{ $slot }}
</div>
