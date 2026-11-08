@props(['value' => null, 'required' => false])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-900 dark:text-gray-100']) }}>
    {{ $value ?? $slot }}@if ($required)<span class="ml-0.5 text-red-500" aria-hidden="true">*</span>@endif
</label>
