<x-layouts::guest title="Anmeldung">
    <x-anmeldeblatt kopf="Anmeldung">

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <x-input.fehler feld="username" art="banner" />

            <div>
                <x-input.feldname for="username" value="Benutzername" />
                <x-input.text id="username" feld="username" class="mt-1.5 block w-full" type="text"
                    name="username" :value="old('username')" required autofocus autocomplete="username" />
            </div>

            <div class="mt-5">
                <x-input.feldname for="password" value="Passwort" />
                <x-password-field id="password" name="password" feld="password" required />
            </div>

            <label for="remember_me" class="mt-5 inline-flex w-fit cursor-pointer items-center">
                <input id="remember_me" type="checkbox" name="remember"
                    class="rounded border-gray-300 text-cerulean-600 shadow-xs focus:ring-cerulean-500">
                <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Angemeldet bleiben</span>
            </label>

            <x-input.button class="mt-7" size="blatt" label="Anmelden" />
        </form>

        <x-slot:fuss>Apps als Debian-LXC auf Proxmox VE ausrollen.</x-slot:fuss>
    </x-anmeldeblatt>
</x-layouts::guest>
