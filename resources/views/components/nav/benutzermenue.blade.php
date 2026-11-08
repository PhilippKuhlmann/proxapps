{{-- The user menu top right - button and dropdown (DokuVault markup, Flowbite replaced by Alpine). --}}
@php($nutzer = auth()->user())

<div class="relative" x-data="{ offen: false }" x-on:click.outside="offen = false" x-on:keydown.escape.window="offen = false">
    <button type="button" x-on:click="offen = ! offen" :aria-expanded="offen.toString()" title="{{ $nutzer?->username }}"
        class="flex h-8 w-8 items-center justify-center rounded-full bg-chathams-blue-800 text-gray-200
               transition-colors hover:bg-chathams-blue-900 focus:outline-hidden focus:ring-2
               focus:ring-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600
               dark:focus:ring-gray-600">
        <span class="sr-only">Benutzermenü öffnen</span>
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 0115 0" />
        </svg>
    </button>

    <div x-show="offen" x-cloak
        x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 mt-2 z-50 min-w-56 rounded-lg border border-chathams-blue-200 bg-white text-base
               shadow-xl dark:border-gray-700 dark:bg-gray-800">

        <div class="border-b border-chathams-blue-100 px-4 py-3 dark:border-gray-700">
            <p class="truncate text-sm text-gray-900 dark:text-gray-100">Angemeldet als</p>
            <p class="mt-0.5 truncate font-mono text-xs text-gray-400 dark:text-gray-500">{{ $nutzer?->username }}</p>
        </div>

        <ul class="py-1">
            <li>
                <x-dropdown-link :href="route('profile.edit')">
                    <x-icon name="key" class="h-4 w-4 shrink-0 text-gray-400" sw="1.8" />
                    Passwort ändern
                </x-dropdown-link>
            </li>
            <li>
                <x-dropdown-link :href="route('settings')">
                    <x-icon name="cog" class="h-4 w-4 shrink-0 text-gray-400" sw="1.8" />
                    Einstellungen
                </x-dropdown-link>
            </li>
        </ul>

        <div class="border-t border-chathams-blue-100 py-1 dark:border-gray-700">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="flex w-full items-center gap-2.5 px-4 py-2 text-sm transition-colors text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-300">
                    <x-icon name="logout" class="h-4 w-4 shrink-0" sw="1.8" />
                    Abmelden
                </button>
            </form>
        </div>
    </div>
</div>
