{{--
    The error message for a field - one place instead of four spellings.
      feld="name"                 - from the default error bag
      :messages="[...]"           - explicit messages
    art="banner" is the variant above a form instead of below a field.
--}}
@props(['feld' => null, 'messages' => null, 'art' => 'feld'])

@php
    $meldungen = $messages !== null
        ? array_values(array_filter((array) $messages))
        : ($feld ? $errors->get($feld) : []);

    $eigeneAbstaende = (bool) preg_match('/(^|\s)m[tby]?-/', $attributes->get('class') ?? '');
    $banner = $art === 'banner';
@endphp

@if ($meldungen)
    <p role="{{ $banner ? 'alert' : 'status' }}" aria-live="polite"
        {{ $attributes->merge(['class' => trim(collect([
            'flex items-start gap-1.5',
            $banner
                ? 'gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                : 'text-xs text-red-600 dark:text-red-400',
            $eigeneAbstaende ? '' : ($banner ? 'mb-4' : 'mt-1.5'),
        ])->filter()->implode(' '))]) }}>
        <svg class="{{ $banner ? 'mt-0.5 h-4 w-4' : 'mt-0.5 h-3.5 w-3.5' }} shrink-0"
            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-9-4a1 1 0 012 0v5a1 1 0 01-2 0V6zm1 9a1.25 1.25 0 110-2.5 1.25 1.25 0 010 2.5z" clip-rule="evenodd" />
        </svg>
        <span class="flex flex-col gap-0.5">
            @foreach ($meldungen as $meldung)
                <span>{{ $meldung }}</span>
            @endforeach
        </span>
    </p>
@endif
