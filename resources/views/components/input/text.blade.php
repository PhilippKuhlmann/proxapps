{{--
    feld: name of the field in validation. With an error for it the field gets a red
    border and announces itself as invalid to screen readers.
--}}
@props(['disabled' => false, 'feld' => null])

@php($fehler = $feld && $errors->has($feld))

<input {{ $disabled ? 'disabled' : '' }}
    @if ($fehler) aria-invalid="true" @endif
    {{ $attributes->merge(['class' => 'rounded-lg shadow-xs dark:bg-gray-700 dark:text-gray-100 disabled:opacity-60 '.($fehler
        ? 'border-red-500 dark:border-red-500 focus:border-red-500 focus:ring-red-500'
        : 'border-gray-300 dark:border-gray-700 focus:border-cerulean-500 focus:ring-cerulean-500')]) }}>
