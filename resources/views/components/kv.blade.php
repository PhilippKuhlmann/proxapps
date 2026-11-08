@props(['label', 'value' => null, 'mono' => false])

{{-- A row of a mini table (detail card). The slot wins over value. --}}
<tr class="border-b border-gray-100 last:border-0 dark:border-gray-700/50">
    <td class="py-1.5 pr-6 align-top text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $label }}</td>
    <td @class(['py-1.5 wrap-anywhere text-gray-900 dark:text-gray-100', 'font-mono text-[13px]' => $mono])>
        @if (trim((string) $slot) !== '')
            {{ $slot }}
        @elseif ($value === null || $value === '')
            <span class="text-gray-400 dark:text-gray-500">—</span>
        @else
            {{ $value }}
        @endif
    </td>
</tr>
