{{--
    Native select with the DokuVault field look (the pixel-close native variant of
    x-input.select; Livewire binds directly to it).
--}}
@props(['feld' => null])

@php($fehler = $feld && $errors->has($feld))

<select @if ($fehler) aria-invalid="true" @endif
    {{ $attributes->merge(['class' => 'w-full rounded-lg border bg-white px-3 py-2 text-base shadow-xs dark:bg-gray-700 text-gray-900 dark:text-gray-100 disabled:opacity-60 '.($fehler
        ? 'border-red-500 dark:border-red-500 focus:border-red-500 focus:ring-red-500'
        : 'border-gray-300 dark:border-gray-700 focus:border-cerulean-500 dark:focus:border-cerulean-500 focus:ring-cerulean-500')]) }}>
    {{ $slot }}
</select>
