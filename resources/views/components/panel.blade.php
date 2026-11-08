{{--
    The surface a section stands on: white ground, fine border, barely a shadow.
      keins   carries a table (cells bring their own padding)
      eng     a bar above a list
      normal  the regular case
      weit    an empty page that says so
--}}
@props(['polster' => 'normal'])

@php
    $polsterungen = [
        'keins' => '',
        'eng' => 'p-4',
        'normal' => 'p-5',
        'weit' => 'p-10',
    ];
@endphp

<div {{ $attributes->merge(['class' => trim(
    'rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800 '
    .($polsterungen[$polster] ?? $polsterungen['normal'])
)]) }}>
    {{ $slot }}
</div>
