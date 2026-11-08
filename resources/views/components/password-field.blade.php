@props(['id', 'name', 'feld' => null, 'autocomplete' => 'current-password', 'autofocus' => false])

{{-- Password input with a show/hide eye (DokuVault login markup). --}}
<div class="relative mt-1.5" x-data="{ show: false }">
    <x-input.text :id="$id" :name="$name" :feld="$feld ?? $name" class="block w-full pr-10" type="password"
        :autocomplete="$autocomplete" x-bind:type="show ? 'text' : 'password'" :autofocus="$autofocus" {{ $attributes }} />
    <button type="button" x-on:click="show = !show" tabindex="-1"
        x-bind:aria-label="show ? 'Passwort verbergen' : 'Passwort anzeigen'"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-cerulean-600
               focus:outline-hidden dark:text-gray-500 dark:hover:text-gray-300">
        <x-icon name="eye" class="h-5 w-5" sw="1.7" x-show="!show" />
        <x-icon name="eye-off" class="h-5 w-5" sw="1.7" x-show="show" x-cloak />
    </button>
</div>
