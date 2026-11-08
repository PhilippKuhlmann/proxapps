@props(['rows' => 3, 'feld' => null])

{{-- Multi-line, monospace: a wrong character in an SSH key is otherwise easy to miss. --}}
@php($fehler = $feld && $errors->has($feld))

<textarea rows="{{ $rows }}" spellcheck="false" @if ($fehler) aria-invalid="true" @endif
    {{ $attributes->merge(['class' => 'w-full min-w-0 resize-y font-mono text-xs leading-relaxed rounded-lg shadow-xs dark:bg-gray-700 dark:text-gray-100 '.($fehler
        ? 'border-red-500 dark:border-red-500 focus:border-red-500 focus:ring-red-500'
        : 'border-gray-300 dark:border-gray-700 focus:border-cerulean-500 focus:ring-cerulean-500')]) }}>{{ $slot }}</textarea>
