{{-- Field label on the guest pages: capitals on monospace, like the port lists elsewhere. --}}
@props(['value' => null])

<label {{ $attributes->merge(['class' => 'block font-mono text-[11px] uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400']) }}>
    {{ $value ?? $slot }}
</label>
