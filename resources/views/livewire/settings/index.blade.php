<div>
    <x-page-header title="Einstellungen" />

    <div class="m-3 grid grid-cols-1 items-start gap-3 xl:grid-cols-[minmax(0,1fr)_28rem]">
        {{-- general --}}
        <form wire:submit="save" class="min-w-0">
            <x-panel>
                <div class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">Allgemein</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Vorgaben für neue Deployments. Sie lassen sich im Formular je Deployment anpassen.</p>

                <x-form-section title="VMIDs" />
                <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    <x-field label="VMID von" for="s-min" feld="vmid_min" required>
                        <x-input.text id="s-min" feld="vmid_min" type="number" min="100" wire:model="vmid_min" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="VMID bis" for="s-max" feld="vmid_max" required>
                        <x-input.text id="s-max" feld="vmid_max" type="number" wire:model="vmid_max" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="Standard-VMID-Muster" for="s-pattern" feld="vmid_pattern" class="sm:col-span-2">
                        <x-input.text id="s-pattern" feld="vmid_pattern" wire:model="vmid_pattern" class="mt-1 w-full font-mono sm:max-w-xs" placeholder="{o3}{o4:03}" autocomplete="off" />
                    </x-field>
                    <x-input.hilfe class="sm:col-span-2 -mt-1">
                        Leitet die VMID aus der statischen IP ab: <span class="font-mono">{o3}{o4:03}</span> macht aus 10.10.250.55 die 250055 und aus 10.10.240.40 die 240040.
                        Ein Muster im Netzwerkprofil hat Vorrang. Ohne Muster (oder bei DHCP) wird die erste freie VMID im Bereich vorgeschlagen.
                    </x-input.hilfe>
                </div>

                <x-form-section title="Container" />
                <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    <x-field label="Zeitzone" for="s-tz" feld="timezone">
                        <x-input.text id="s-tz" feld="timezone" wire:model="timezone" list="s-tz-list" class="mt-1 w-full" autocomplete="off" />
                        <datalist id="s-tz-list">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}"></option>
                            @endforeach
                        </datalist>
                    </x-field>
                    <x-field label="Standard-Tags" for="s-tags" feld="default_tags" hilfe="Kleinbuchstaben, durch Semikolon getrennt. „proxapps“ wird immer gesetzt.">
                        <x-input.text id="s-tags" feld="default_tags" wire:model="default_tags" class="mt-1 w-full font-mono" />
                    </x-field>
                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <x-input.checkbox label="Pakete beim Einrichten aktualisieren (apt upgrade)" wire:model="apt_upgrade" />
                        <x-input.checkbox label="Container bei Fehlschlag automatisch entfernen" hilfe="Sonst bleibt er zur Fehlersuche bestehen." wire:model="destroy_on_failure" />
                    </div>
                    <x-field label="SSH-Schlüssel für root" for="s-keys" feld="authorized_keys" class="sm:col-span-2"
                        hilfe="Werden in jedem neuen Container hinterlegt. Ein öffentlicher Schlüssel pro Zeile.">
                        <x-input.textarea id="s-keys" feld="authorized_keys" rows="4" wire:model="authorized_keys" class="mt-1" placeholder="ssh-ed25519 AAAA… user@host" />
                    </x-field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-input.button wire:loading.attr="disabled" wire:target="save" label="Speichern" />
                </div>
            </x-panel>
        </form>

        <div class="flex min-w-0 flex-col gap-3">
            {{-- deployer key --}}
            <x-panel>
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">Deployer-Schlüssel</div>
                    <x-input.button type="button" color="gray" size="sm"
                        x-on:click="$store.confirm.ask({ title: 'Neues Schlüsselpaar erzeugen?', text: 'Neue Deployments verwenden dann den neuen Schlüssel. Bereits ausgerollte Container behalten den alten.', button: 'Neu erzeugen', tone: 'blue', ok: () => $wire.regenerateKey() })">
                        <x-icon name="refresh" class="h-4 w-4" />Neu erzeugen
                    </x-input.button>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    ProxApps hinterlegt diesen öffentlichen Schlüssel in jedem neuen Container und richtet die App darüber per SSH ein.
                </p>
                @if ($pubkey)
                    <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-600 dark:bg-gray-900/40">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1 font-mono text-[11px] leading-relaxed text-gray-700 wrap-anywhere dark:text-gray-300">{{ $pubkey }}</div>
                            <x-copy :value="$pubkey" :show="false" />
                        </div>
                    </div>
                @else
                    <x-input.fehler :messages="['Der Schlüssel konnte nicht gelesen werden.']" />
                @endif
            </x-panel>
        </div>
    </div>

    {{-- network profiles --}}
    <x-page-header title="Netzwerkprofile">
        <x-slot:sub>Bridge, VLAN, Netz, Gateway, IP-Pool und VMID-Muster als Vorlage für das Deploy-Formular.</x-slot:sub>
        <x-input.linkbutton :href="route('profiles.create')">
            <x-icon name="plus" class="w-4 h-4" sw="2.2" />Neues Profil
        </x-input.linkbutton>
    </x-page-header>

    @if ($profiles->isEmpty())
        <x-emptystate message="Noch kein Netzwerkprofil angelegt." />
    @else
        <div class="m-3">
            <div class="rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800 overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left text-gray-500 dark:text-gray-400 sm:min-w-0">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                        <tr>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Name</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Netz</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Bridge / VLAN</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Gateway</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">IP-Pool</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">VMID-Muster</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Firewall</th>
                            <th scope="col" class="py-2.5 px-4"><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profiles as $p)
                            <tr wire:key="prof-{{ $p->id }}"
                                class="bg-white border-b border-gray-100 last:border-0 hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:border-gray-700 dark:hover:bg-gray-700/50">
                                <td class="py-2.5 px-4 text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('profiles.edit', $p) }}" class="hover:text-cerulean-600">{{ $p->name }}</a>
                                    @if ($p->is_default)
                                        <span class="ml-1 inline-flex items-center rounded bg-cerulean-100 px-1.5 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">Standard</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $p->cidr }}</td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $p->bridge }}{{ $p->vlan ? ' · '.$p->vlan : '' }}</td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{!! $p->gateway ? e($p->gateway) : '<span class="text-gray-400 dark:text-gray-500">—</span>' !!}</td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">
                                    @if ($p->pool_start || $p->pool_end)
                                        {{ $p->pool_start ?: '…' }} – {{ $p->pool_end ?: '…' }}
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">ganzes Netz</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{!! $p->vmid_pattern ? e($p->vmid_pattern) : '<span class="text-gray-400 dark:text-gray-500">—</span>' !!}</td>
                                <td class="py-2.5 px-4"><x-statusicon :value="(bool) $p->firewall" /></td>
                                <td class="py-2.5 px-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-input.symbolknopf titel="Bearbeiten" :href="route('profiles.edit', $p)">
                                            <x-icon name="pencil" class="h-5 w-5" />
                                        </x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Löschen" ton="rot"
                                            x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Netzwerkprofil „'.$p->name.'“ löschen?') }}, text: 'Bereits ausgerollte Container sind nicht betroffen.', button: 'Löschen', ok: () => $wire.deleteProfile({{ $p->id }}) })">
                                            <x-icon name="trash" class="h-5 w-5" />
                                        </x-input.symbolknopf>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
