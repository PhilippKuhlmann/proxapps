<div class="md:flex xs:flex-col mx-auto px-3 max-w-3xl">
    <form wire:submit="save" x-on:keydown.meta.enter.prevent="$el.requestSubmit()" x-on:keydown.ctrl.enter.prevent="$el.requestSubmit()"
        class="p-5 sm:p-6 rounded-xl border border-gray-200 bg-white shadow-xs dark:bg-gray-800 dark:border-gray-700 w-full my-3">
        <div class="flex flex-col text-cerulean-950 dark:text-cerulean-500 w-full">
            <a href="{{ route('settings') }}"
                class="mb-2 inline-flex w-fit items-center gap-1 text-sm text-gray-500 hover:text-cerulean-600 dark:text-gray-400 dark:hover:text-cerulean-400">
                <x-icon name="back" class="h-4 w-4" sw="2" />Zurück
            </a>
            <div class="text-2xl font-CoconPro">{{ $profileId ? 'Netzwerkprofil bearbeiten' : 'Neues Netzwerkprofil' }}</div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                <x-field label="Name" for="p-name" feld="name" required>
                    <x-input.text id="p-name" feld="name" wire:model="name" class="mt-1 w-full" placeholder="z. B. Server-VLAN" autofocus />
                </x-field>
                <div class="flex flex-col justify-end pb-1">
                    <x-input.checkbox label="Standardprofil" hilfe="Wird im Deploy-Formular vorausgewählt." wire:model="is_default" />
                </div>
            </div>

            <x-form-section title="Netz" />
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                <x-field label="Netz (CIDR)" for="p-cidr" feld="cidr" required>
                    <x-input.text id="p-cidr" feld="cidr" wire:model.live.debounce.400ms="cidr" class="mt-1 w-full font-mono" placeholder="10.10.250.0/24" />
                </x-field>
                <x-field label="Gateway" for="p-gw" feld="gateway">
                    <x-input.text id="p-gw" feld="gateway" wire:model="gateway" class="mt-1 w-full font-mono" placeholder="10.10.250.1" />
                </x-field>
                <x-field label="Bridge" for="p-bridge" feld="bridge" required>
                    <x-input.text id="p-bridge" feld="bridge" wire:model="bridge" class="mt-1 w-full font-mono" placeholder="vmbr0" />
                </x-field>
                <x-field label="VLAN" for="p-vlan" feld="vlan" hilfe="Leer = ohne Tag.">
                    <x-input.text id="p-vlan" feld="vlan" type="number" min="1" max="4094" wire:model.live.debounce.400ms="vlan" class="mt-1 w-full font-mono" />
                </x-field>
                <x-field label="DNS-Server" for="p-dns" feld="dns" hilfe="Durch Leerzeichen getrennt.">
                    <x-input.text id="p-dns" feld="dns" wire:model="dns" class="mt-1 w-full font-mono" placeholder="10.10.250.1 1.1.1.1" />
                </x-field>
                <x-field label="Suchdomain" for="p-search" feld="searchdomain">
                    <x-input.text id="p-search" feld="searchdomain" wire:model="searchdomain" class="mt-1 w-full font-mono" placeholder="home.lan" />
                </x-field>
                <div class="sm:col-span-2">
                    <x-input.checkbox label="Proxmox-Firewall an der Netzwerkkarte aktivieren" wire:model="firewall" />
                </div>
            </div>

            <x-form-section title="Vorschläge" />
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                <x-field label="IP-Pool von" for="p-ps" feld="pool_start">
                    <x-input.text id="p-ps" feld="pool_start" wire:model.live.debounce.400ms="pool_start" class="mt-1 w-full font-mono" placeholder="10.10.250.50" />
                </x-field>
                <x-field label="IP-Pool bis" for="p-pe" feld="pool_end">
                    <x-input.text id="p-pe" feld="pool_end" wire:model="pool_end" class="mt-1 w-full font-mono" placeholder="10.10.250.99" />
                </x-field>
                <x-input.hilfe class="sm:col-span-2 -mt-1">
                    Aus diesem Bereich schlägt ProxApps die nächste freie Adresse vor (belegt sind alle Adressen aus Gast-Konfigurationen des Clusters).
                    Leer = ganzes Netz ohne Gateway.
                </x-input.hilfe>
                <x-field label="VMID-Muster" for="p-pattern" feld="vmid_pattern" class="sm:col-span-2"
                    hilfe="{vlan}{o4:03} → 10.10.250.89 in VLAN 250 = 250089. Platzhalter: {vlan}, {o1}…{o4}, {o4:03} (mit führenden Nullen), Ziffern.">
                    <x-input.text id="p-pattern" feld="vmid_pattern" wire:model.live.debounce.300ms="vmid_pattern" class="mt-1 w-full font-mono" placeholder="{vlan}{o4:03}" />
                    <x-slot:unten>
                        @if ($preview)
                            <span class="mt-1 inline-flex w-fit items-center gap-1.5 rounded bg-cerulean-100 px-2 py-0.5 font-mono text-[11px] font-semibold text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">
                                Beispiel: {{ $preview }}
                            </span>
                        @endif
                    </x-slot:unten>
                </x-field>
            </div>
        </div>

        <div class="flex flex-row justify-end gap-3 mt-6">
            <x-input.linkbutton :href="route('settings')" color="gray">Abbrechen</x-input.linkbutton>
            <x-input.button wire:loading.attr="disabled" wire:target="save" :label="$profileId ? 'Speichern' : 'Anlegen'" />
        </div>
    </form>
</div>
