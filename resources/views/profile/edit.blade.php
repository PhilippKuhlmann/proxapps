<x-layouts::app title="Passwort ändern">
    <div class="md:flex xs:flex-col mx-auto px-3 max-w-3xl">
        <form method="POST" action="{{ route('profile.update') }}"
            class="p-5 sm:p-6 rounded-xl border border-gray-200 bg-white shadow-xs dark:bg-gray-800 dark:border-gray-700 w-full my-3">
            @csrf
            <div class="flex flex-col text-cerulean-950 dark:text-cerulean-500 w-full">
                <a href="{{ route('dashboard') }}"
                    class="mb-2 inline-flex w-fit items-center gap-1 text-sm text-gray-500 hover:text-cerulean-600 dark:text-gray-400 dark:hover:text-cerulean-400">
                    <x-icon name="back" class="h-4 w-4" sw="2" />Zurück
                </a>
                <div class="text-2xl font-CoconPro">Passwort ändern</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Angemeldet als <span class="font-mono text-gray-700 dark:text-gray-300">{{ auth()->user()->username }}</span>
                </p>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                    <div class="flex flex-col sm:col-span-2">
                        <x-input.label for="current" value="Aktuelles Passwort" />
                        <x-input.text id="current" name="current" feld="current" type="password" class="mt-1 w-full"
                            autocomplete="current-password" required autofocus />
                        <x-input.fehler feld="current" />
                    </div>
                    <div class="flex flex-col">
                        <x-input.label for="password" value="Neues Passwort" />
                        <x-input.text id="password" name="password" feld="password" type="password" class="mt-1 w-full"
                            autocomplete="new-password" required />
                        <x-input.hilfe>Mindestens 10 Zeichen.</x-input.hilfe>
                        <x-input.fehler feld="password" />
                    </div>
                    <div class="flex flex-col">
                        <x-input.label for="password2" value="Neues Passwort wiederholen" />
                        <x-input.text id="password2" name="password2" feld="password2" type="password" class="mt-1 w-full"
                            autocomplete="new-password" required />
                        <x-input.fehler feld="password2" />
                    </div>
                </div>
            </div>
            <div class="flex flex-row justify-end gap-3 mt-6">
                <x-input.linkbutton :href="route('dashboard')" color="gray">Abbrechen</x-input.linkbutton>
                <x-input.button label="Passwort ändern" />
            </div>
        </form>
    </div>
</x-layouts::app>
