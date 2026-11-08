@props(['href', 'label', 'icon', 'aktiv' => false])

{{-- A single top-level sidebar entry (DokuVault x-aside.toplink). --}}
<li>
    <a href="{{ $href }}" @if ($aktiv) aria-current="page" @endif
        @class([
            'group flex items-center w-full p-2 rounded-lg text-sm font-medium transition-colors',
            'bg-cerulean-50 text-cerulean-700 dark:bg-gray-800 dark:text-cerulean-400' => $aktiv,
            'text-gray-700 hover:bg-gray-100 hover:text-cerulean-700 dark:text-gray-200 dark:hover:bg-gray-800' => ! $aktiv,
        ])>
        <x-icon :name="$icon"
            @class([
                'w-5 h-5 transition-colors',
                'text-cerulean-600 dark:text-cerulean-400' => $aktiv,
                'text-gray-400 group-hover:text-cerulean-600 dark:text-gray-500' => ! $aktiv,
            ]) />
        <span class="flex-1 ml-3 text-left whitespace-nowrap">{{ $label }}</span>
    </a>
</li>
