<x-layouts::guest title="Ersteinrichtung">
    <x-anmeldeblatt kopf="Ersteinrichtung">

        <form method="POST" action="{{ route('setup.store') }}">
            @csrf

            <p class="mb-6 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                Willkommen bei ProxApps. Lege hier dein Konto an – danach verbindest du deinen Proxmox-Server.
            </p>

            @if ($needsCode ?? false)
                <div class="mb-5">
                    <x-input.feldname for="code" value="Einrichtungscode" />
                    <x-input.text id="code" feld="code" name="code" type="text" required autofocus
                        autocomplete="off" spellcheck="false" class="mt-1.5 block w-full font-mono uppercase" />
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Steht im Container-Log: docker logs proxapps</p>
                    <x-input.fehler feld="code" class="mt-2" />
                </div>
            @endif

            <div>
                <x-input.feldname for="username" value="Benutzername" />
                <x-input.text id="username" feld="username" name="username" type="text" required :autofocus="! ($needsCode ?? false)"
                    autocomplete="username" :value="old('username')" class="mt-1.5 block w-full" />
                <x-input.fehler feld="username" class="mt-2" />
            </div>

            <div class="mt-5">
                <x-input.feldname for="password" value="Passwort" />
                <x-password-field id="password" name="password" feld="password" autocomplete="new-password" required />
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Mindestens 10 Zeichen.</p>
                <x-input.fehler feld="password" class="mt-2" />
            </div>

            <div class="mt-5">
                <x-input.feldname for="password2" value="Passwort wiederholen" />
                <x-input.text id="password2" feld="password2" name="password2" type="password" required
                    autocomplete="new-password" class="mt-1.5 block w-full" />
                <x-input.fehler feld="password2" class="mt-2" />
            </div>

            <x-input.button class="mt-7" size="blatt" label="Konto anlegen" />
        </form>
    </x-anmeldeblatt>
</x-layouts::guest>
