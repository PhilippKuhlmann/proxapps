@props(['url', 'name', 'target' => '_self'])

<a href="{{ $url }}" target="{{ $target }}" title="{{ $name }}" aria-label="{{ $name }}"
    class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-cerulean-600 dark:text-gray-400 dark:hover:bg-gray-700 transition-colors">
    {{ $slot }}
</a>
