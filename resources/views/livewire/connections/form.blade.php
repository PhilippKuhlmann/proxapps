<div class="md:flex xs:flex-col mx-auto px-3 max-w-3xl">
    <form wire:submit="save" x-on:keydown.meta.enter.prevent="$el.requestSubmit()" x-on:keydown.ctrl.enter.prevent="$el.requestSubmit()"
        class="p-5 sm:p-6 rounded-xl border border-gray-200 bg-white shadow-xs dark:bg-gray-800 dark:border-gray-700 w-full my-3">
        <div class="flex flex-col text-cerulean-950 dark:text-cerulean-500 w-full">
            <a href="{{ route('connections.index') }}"
                class="mb-2 inline-flex w-fit items-center gap-1 text-sm text-gray-500 hover:text-cerulean-600 dark:text-gray-400 dark:hover:text-cerulean-400">
                <x-icon name="back" class="h-4 w-4" sw="2" />Zurück
            </a>
            <div class="text-2xl font-CoconPro">{{ $connectionId ? 'Verbindung bearbeiten' : 'Neue Proxmox-Verbindung' }}</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                ProxApps spricht die Proxmox-API mit einem API-Token an. Ein einzelner Node oder ein Cluster-Mitglied genügt.
            </p>

            <x-input.fehler feld="_form" art="banner" class="mt-4" />

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                <div class="flex flex-col">
                    <x-input.label for="f-name" value="Name" required />
                    <x-input.text id="f-name" feld="name" wire:model="name" class="mt-1 w-full" placeholder="z. B. Homelab-Cluster" autofocus />
                    <x-input.fehler feld="name" />
                </div>
                <div class="flex flex-col">
                    <x-input.label for="f-url" value="Adresse" required />
                    <x-input.text id="f-url" feld="url" wire:model.blur="url" class="mt-1 w-full font-mono text-sm" placeholder="https://pve.example.lan:8006" />
                    <x-input.hilfe>Port 8006 wird ergänzt, wenn er fehlt.</x-input.hilfe>
                    <x-input.fehler feld="url" />
                </div>
                <div class="flex flex-col">
                    <x-input.label for="f-token" value="Token-ID" required />
                    <x-input.text id="f-token" feld="token_id" wire:model="token_id" class="mt-1 w-full font-mono text-sm" placeholder="proxapps@pve!deploy" autocomplete="off" />
                    <x-input.fehler feld="token_id" />
                </div>
                <div class="flex flex-col">
                    <x-input.label for="f-secret" value="Token-Secret" :required="! $hasSecret" />
                    <x-input.text id="f-secret" feld="token_secret" wire:model="token_secret" type="password" class="mt-1 w-full font-mono text-sm"
                        autocomplete="new-password" :placeholder="$hasSecret ? 'leer lassen = unverändert' : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'" />
                    @if ($hasSecret)
                        <x-input.hilfe>Gespeichert. Nur ausfüllen, um es zu ersetzen.</x-input.hilfe>
                    @endif
                    <x-input.fehler feld="token_secret" />
                </div>
                <div class="flex flex-col sm:col-span-2">
                    <x-input.label for="f-pool" value="Ressourcenpool (optional)" />
                    <x-input.text id="f-pool" feld="pool" wire:model="pool" class="mt-1 w-full font-mono text-sm sm:max-w-xs" placeholder="proxapps" autocomplete="off" />
                    <x-input.hilfe>Neue Container landen in diesem Pool. Dann braucht der Token Schreibrechte nur auf <span class="font-mono">/pool/&lt;name&gt;</span> – bestehende Gäste bleiben unantastbar.</x-input.hilfe>
                    <x-input.fehler feld="pool" />
                </div>
            </div>

            <x-form-section title="TLS-Zertifikat" />
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach (['pin' => ['Fingerabdruck festpinnen', 'Empfohlen für das selbstsignierte Proxmox-Zertifikat: nur genau dieses Zertifikat wird akzeptiert.'],
                           'verify' => ['CA-Prüfung', 'Für Zertifikate einer vertrauenswürdigen CA (z. B. Let’s Encrypt).']] as $mode => [$label, $text])
                    <button type="button" wire:click="$set('tls_mode', '{{ $mode }}')" role="radio" aria-checked="{{ $tls_mode === $mode ? 'true' : 'false' }}"
                        @class([
                            'rounded-lg border p-3 text-left transition-colors',
                            'border-cerulean-500 bg-white ring-1 ring-cerulean-500 dark:bg-gray-900' => $tls_mode === $mode,
                            'border-gray-200 bg-white/60 hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900/40' => $tls_mode !== $mode,
                        ])>
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $text }}</div>
                    </button>
                @endforeach
            </div>

            @if ($tls_mode === 'pin')
                <div class="mt-4 flex flex-col">
                    <x-input.label value="SHA-256-Fingerabdruck" required />
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        @if ($fingerprint)
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-green-50 px-3 py-2 font-mono text-xs text-green-900 wrap-anywhere dark:border-green-800 dark:bg-green-900/25 dark:text-green-200">
                                <x-icon name="shield" class="h-4 w-4 shrink-0" />{{ $fingerprint }}
                            </span>
                        @else
                            <span class="text-sm text-gray-500 dark:text-gray-400">Noch kein Fingerabdruck bestätigt.</span>
                        @endif
                        <x-input.button type="button" color="gray" size="sm" wire:click="fetchFingerprint" wire:loading.attr="disabled" wire:target="fetchFingerprint">
                            <x-icon name="refresh" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="fetchFingerprint" />
                            Fingerabdruck abrufen
                        </x-input.button>
                    </div>

                    @if ($fetchError)
                        <x-input.fehler :messages="[$fetchError]" />
                    @endif

                    @if ($fetched)
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200">
                            <div class="font-medium">Der Server zeigt dieses Zertifikat:</div>
                            <div class="mt-1 font-mono text-xs wrap-anywhere">{{ $fetched }}</div>
                            <div class="mt-2 text-xs">
                                Vergleiche den Wert mit der Proxmox-Oberfläche (<span class="font-medium">Node → System → Zertifikate → pve-ssl.pem → Fingerabdruck</span>) und übernimm ihn nur, wenn er übereinstimmt.
                            </div>
                            <div class="mt-3 flex gap-2">
                                <x-input.button type="button" size="sm" wire:click="accept">
                                    <x-icon name="check" class="h-4 w-4" sw="2.2" />Übernehmen
                                </x-input.button>
                                <x-input.button type="button" size="sm" color="gray" wire:click="$set('fetched', '')" label="Verwerfen" />
                            </div>
                        </div>
                    @endif
                    <x-input.fehler feld="fingerprint" />
                </div>
            @endif

            <details class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-600 dark:bg-gray-900/40">
                <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Wie lege ich das API-Token an?</summary>
                <div class="mt-3 space-y-2 text-gray-600 dark:text-gray-300">
                    <p>Auf einem Proxmox-Node als root ausführen. Das Secret wird nur einmal angezeigt.</p>
                    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-3 font-mono text-xs leading-relaxed text-gray-200">pveum role add ProxApps --privs "VM.Allocate,VM.Audit,VM.Config.CPU,VM.Config.Memory,VM.Config.Disk,VM.Config.Network,VM.Config.Options,VM.PowerMgmt,VM.Snapshot,VM.Snapshot.Rollback,Datastore.AllocateSpace,Datastore.AllocateTemplate,Datastore.Audit,SDN.Use,SDN.Audit,Sys.Audit,Pool.Audit"
pveum user add proxapps@pve --comment "ProxApps deployer"
pveum user token add proxapps@pve deploy --privsep 1 --comment "ProxApps"
# mit privsep gilt: Rechte = Benutzer-ACL ∩ Token-ACL, deshalb beide setzen
pveum acl modify / --roles ProxApps --users proxapps@pve
pveum acl modify / --roles ProxApps --tokens 'proxapps@pve!deploy'</pre>
                    <p class="text-xs">
                        Enger geht es mit einzelnen Pfaden statt <span class="font-mono">/</span>: <span class="font-mono">/vms</span>,
                        <span class="font-mono">/storage/&lt;speicher&gt;</span>, <span class="font-mono">/sdn/zones/localnetwork</span>, <span class="font-mono">/nodes</span>.
                        <span class="font-mono">Sys.Modify</span> wird bewusst nicht vergeben.
                    </p>
                </div>
            </details>
        </div>

        <div class="flex flex-row flex-wrap items-center justify-end gap-3 mt-6">
            <span wire:loading wire:target="save" class="text-xs text-cerulean-600 dark:text-cerulean-400">Verbindung wird geprüft …</span>
            <x-input.linkbutton :href="route('connections.index')" color="gray">Abbrechen</x-input.linkbutton>
            <x-input.button wire:loading.attr="disabled" wire:target="save" :label="$connectionId ? 'Prüfen & speichern' : 'Prüfen & anlegen'" />
        </div>
    </form>
</div>
