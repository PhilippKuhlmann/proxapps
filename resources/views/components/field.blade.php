@props(['label', 'for' => null, 'feld' => null, 'hilfe' => null, 'required' => false])

{{-- Label (+ optional badge on the right) / input slot / help / error. --}}
<div {{ $attributes->merge(['class' => 'flex min-w-0 flex-col']) }}>
    <div class="flex min-h-5 items-center justify-between gap-2">
        <x-input.label :for="$for" :value="$label" :required="$required" />
        @isset($badge)
            <span class="flex shrink-0 items-center gap-1.5">{{ $badge }}</span>
        @endisset
    </div>
    {{ $slot }}
    @if ($hilfe)
        <x-input.hilfe>{{ $hilfe }}</x-input.hilfe>
    @endif
    @isset($unten)
        {{ $unten }}
    @endisset
    @if ($feld)
        <x-input.fehler :feld="$feld" />
    @endif
</div>
