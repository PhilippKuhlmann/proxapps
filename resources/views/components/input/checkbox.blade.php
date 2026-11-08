@props(['label', 'hilfe' => null])

<label class="mt-1 inline-flex items-start gap-2">
    <input type="checkbox" {{ $attributes->merge(['class' => 'mt-0.5 rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-700']) }}>
    <span class="text-sm text-gray-700 dark:text-gray-300">
        {{ $label }}
        @if ($hilfe)
            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hilfe }}</span>
        @endif
    </span>
</label>
