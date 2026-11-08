@php
    use App\Http\Support\Format;

    $rec = $app->resources;
    $changed = fn (string $k) => (string) ($v[$k] ?? '') !== (string) ($rec[$k] ?? '');
    $resourcesChanged = collect(\App\Livewire\Apps\Deploy::RESOURCE_KEYS)->contains(fn ($k) => $changed($k));
    $amber = 'border-amber-400! bg-amber-50! dark:border-amber-600! dark:bg-amber-900/20!';
    $static = $v['net_mode'] === 'static';
    $hasErrors = $errors->isNotEmpty();
    $nodeLabel = function (array $n) {
        $mem = ! empty($n['maxmem']) ? ' · RAM '.round(($n['mem'] ?? 0) / $n['maxmem'] * 100).' % belegt' : '';
        $cpu = ! empty($n['maxcpu']) ? ' · '.$n['maxcpu'].' CPU' : '';

        return $n['node'].(($n['status'] ?? '') !== 'online' ? ' (offline)' : '').$cpu.$mem;
    };
    $storageLabel = fn (array $s) => $s['storage'].' ('.($s['type'] ?? '?').')'
        .(! empty($s['avail']) ? ' – '.number_format($s['avail'] / 1024 ** 3, 0, ',', '.').' GB frei' : '');
    $bridgeLabel = fn (array $b) => $b['iface'].(! empty($b['comments']) ? ' – '.$b['comments'] : '').(! empty($b['vlan_aware']) ? ' (VLAN-fähig)' : '');
    $templateLabel = $v['template'] === '' ? 'Automatisch (neueste vorhandene)'
        : (str_starts_with($v['template'], 'download:') ? 'Herunterladen: '.substr($v['template'], 9) : basename(str_replace(':', '/', $v['template'])));
@endphp

<div>
    <x-page-header :title="$app->name.' ausrollen'" :back="route('apps.index')" back-label="App-Katalog">
        <x-slot:badge>
            <span class="inline-flex items-center rounded bg-cerulean-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">{{ $app->category }}</span>
        </x-slot:badge>
        @if ($clonedFrom)
            <x-slot:sub>Werte übernommen aus <a href="{{ route('deployments.show', $clonedFrom) }}" class="text-cerulean-600 hover:underline">Deployment #{{ $clonedFrom }}</a></x-slot:sub>
        @endif
    </x-page-header>

    @if ($connections->isEmpty())
        <x-callout title="Noch keine Proxmox-Verbindung" class="m-3">
            Bevor du {{ $app->name }} ausrollen kannst, lege eine Verbindung zu deinem Proxmox-Server an.
            <x-slot:aktionen>
                <x-input.linkbutton :href="route('connections.create')"><x-icon name="plus" class="w-4 h-4" sw="2.2" />Verbindung anlegen</x-input.linkbutton>
            </x-slot:aktionen>
        </x-callout>
    @endif

    <form wire:submit="submit" x-on:keydown.meta.enter.prevent="$el.requestSubmit()" x-on:keydown.ctrl.enter.prevent="$el.requestSubmit()"
        class="m-3 grid grid-cols-1 items-start gap-3 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_24rem]">

        <x-panel class="min-w-0 p-5 sm:p-6">
            @if ($hasErrors)
                <x-input.fehler art="banner" :messages="array_filter([$errors->first('_form'), 'Bitte die rot markierten Felder prüfen.'])" />
            @endif

            {{-- ============ Ziel ============ --}}
            <x-form-section title="Ziel" first />
            @if ($apiError)
                <div class="mt-3 flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200" role="alert">
                    <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span class="min-w-0 flex-1 wrap-anywhere">Proxmox-Abfrage fehlgeschlagen: {{ $apiError }}</span>
                    <button type="button" wire:click="loadNodes(true)" class="shrink-0 text-sm font-medium text-amber-900 underline dark:text-amber-200">Erneut versuchen</button>
                </div>
            @endif
            <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                <x-field label="Proxmox-Verbindung" for="f-conn" feld="connection_id" required>
                    <x-input.select id="f-conn" feld="connection_id" wire:model.live="v.connection_id" class="mt-1">
                        <option value="">– bitte wählen –</option>
                        @foreach ($connections as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </x-input.select>
                </x-field>

                <x-field label="Node" for="f-node" feld="node" required>
                    <x-slot:badge>
                        <span wire:loading wire:target="v.connection_id,loadNodes" class="text-xs text-cerulean-600 dark:text-cerulean-400">lädt …</span>
                    </x-slot:badge>
                    <x-input.select id="f-node" feld="node" wire:model.live="v.node" class="mt-1" :disabled="! $nodes">
                        @if (! $nodes)
                            <option value="">{{ $v['connection_id'] ? 'keine Nodes geladen' : 'zuerst Verbindung wählen' }}</option>
                        @endif
                        @foreach ($nodes as $n)
                            <option value="{{ $n['node'] }}">{{ $nodeLabel($n) }}</option>
                        @endforeach
                    </x-input.select>
                </x-field>

                <x-field label="Container-Speicher" for="f-rootfs" feld="rootfs_storage" required>
                    <x-slot:badge>
                        <span wire:loading wire:target="v.node,loadOptions" class="text-xs text-cerulean-600 dark:text-cerulean-400">lädt …</span>
                    </x-slot:badge>
                    <x-input.select id="f-rootfs" feld="rootfs_storage" wire:model.live="v.rootfs_storage" class="mt-1" :disabled="! $options['rootdir']">
                        @if (! $options['rootdir'])
                            <option value="">–</option>
                        @endif
                        @foreach ($options['rootdir'] as $s)
                            <option value="{{ $s['storage'] }}">{{ $storageLabel($s) }}</option>
                        @endforeach
                    </x-input.select>
                </x-field>

                <x-field label="Vorlagen-Speicher" for="f-tstore" feld="template_storage" required>
                    <x-input.select id="f-tstore" feld="template_storage" wire:model.live="v.template_storage" class="mt-1" :disabled="! $options['vztmpl']">
                        @if (! $options['vztmpl'])
                            <option value="">–</option>
                        @endif
                        @foreach ($options['vztmpl'] as $s)
                            <option value="{{ $s['storage'] }}">{{ $s['storage'] }} ({{ $s['type'] ?? '?' }})</option>
                        @endforeach
                    </x-input.select>
                </x-field>

                <x-field label="Debian-Vorlage" for="f-tpl" feld="template" class="sm:col-span-2"
                    :hilfe="'Paket '.$app->template.'. Fehlt die Vorlage, lädt ProxApps sie vor dem Anlegen herunter.'">
                    <x-input.select id="f-tpl" feld="template" wire:model.live="v.template" class="mt-1">
                        <option value="">Automatisch (neueste vorhandene)</option>
                        @if ($templateChoices['local'])
                            <optgroup label="Auf {{ $v['template_storage'] }} vorhanden">
                                @foreach ($templateChoices['local'] as $t)
                                    <option value="{{ $t['volid'] }}">{{ $t['name'] }}{{ is_numeric($t['size'] ?? null) ? ' ('.Format::bytes($t['size']).')' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if ($templateChoices['downloadable'])
                            <optgroup label="Von Proxmox herunterladen">
                                @foreach ($templateChoices['downloadable'] as $t)
                                    <option value="download:{{ $t['name'] }}">Herunterladen: {{ $t['name'] }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </x-input.select>
                </x-field>
            </div>

            {{-- ============ Identität ============ --}}
            <x-form-section title="Identität" />
            <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                <x-field label="Hostname" for="f-host" feld="hostname" required hilfe="Buchstaben, Ziffern und Bindestriche.">
                    <x-input.text id="f-host" feld="hostname" wire:model.live.debounce.400ms="v.hostname" class="mt-1 w-full font-mono" autocomplete="off" spellcheck="false" />
                </x-field>

                <x-field label="VMID" for="f-vmid" feld="vmid" required>
                    <x-slot:badge>
                        <x-check-badge :check="$checks['vmid']" target="v.vmid,check,suggestVmid,suggest,suggestIp" />
                    </x-slot:badge>
                    <div class="mt-1 flex gap-2">
                        <x-input.text id="f-vmid" feld="vmid" wire:model.live.debounce.500ms="v.vmid" inputmode="numeric" class="w-full min-w-0 font-mono" autocomplete="off" />
                        <x-input.button type="button" color="gray" size="feld" wire:click="suggestVmid" wire:loading.attr="disabled" wire:target="suggestVmid" :disabled="! $v['connection_id']"
                            title="Freie VMID vorschlagen (aus dem VMID-Muster des Profils oder dem Bereich in den Einstellungen)">
                            <x-icon name="sparkles" class="h-4 w-4" />Vorschlag
                        </x-input.button>
                    </div>
                    @if ($vmidPattern)
                        <x-slot:unten>
                            <x-input.hilfe>Abgeleitet aus der IP (<span class="font-mono">{{ $vmidPattern }}</span>)</x-input.hilfe>
                        </x-slot:unten>
                    @endif
                </x-field>
            </div>

            {{-- ============ Ressourcen ============ --}}
            <x-form-section title="Ressourcen">
                @if ($resourcesChanged)
                    <button type="button" wire:click="resetResources"
                        class="inline-flex items-center gap-1 text-sm normal-case text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">
                        <x-icon name="refresh" class="h-4 w-4" />Auf Empfehlung zurücksetzen
                    </button>
                @endif
            </x-form-section>
            <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 lg:grid-cols-4">
                @foreach (['cores' => ['CPU-Kerne', '', 1], 'memory' => ['Arbeitsspeicher', 'MB', 64], 'swap' => ['Swap', 'MB', 0], 'disk' => ['Disk', 'GB', 1]] as $k => [$label, $unit, $min])
                    <x-field :label="$label" :for="'f-'.$k" :feld="$k" required>
                        <div class="relative mt-1">
                            <x-input.text :id="'f-'.$k" :feld="$k" type="number" :min="$min" wire:model.live.debounce.300ms="v.{{ $k }}"
                                @class(['w-full font-mono', 'pr-11' => $unit, $amber => $changed($k) && ! $errors->has($k)]) />
                            @if ($unit)
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-gray-400">{{ $unit }}</span>
                            @endif
                        </div>
                        <x-slot:unten>
                            <span @class(['mt-1 text-xs', 'text-amber-700 dark:text-amber-400' => $changed($k), 'text-gray-500 dark:text-gray-400' => ! $changed($k)])>
                                Empfohlen: {{ $rec[$k] ?? '?' }}{{ $unit ? ' '.$unit : '' }}
                            </span>
                        </x-slot:unten>
                    </x-field>
                @endforeach
            </div>

            {{-- ============ Netzwerk ============ --}}
            <x-form-section title="Netzwerk" />
            <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                <x-field label="Netzwerkprofil" for="f-profile" class="sm:col-span-2">
                    <x-input.select id="f-profile" wire:model.live="v.profile_id" class="mt-1">
                        @foreach ($profiles as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} – {{ $p->cidr }}{{ $p->vlan ? ' · VLAN '.$p->vlan : '' }}{{ $p->is_default ? ' (Standard)' : '' }}</option>
                        @endforeach
                        <option value="">Manuell</option>
                    </x-input.select>
                    <x-slot:unten>
                        @if ($currentProfile)
                            <x-input.hilfe>
                                Bridge {{ $currentProfile->bridge }}
                                @if ($currentProfile->pool_start && $currentProfile->pool_end) · IP-Pool {{ $currentProfile->pool_start }} – {{ $currentProfile->pool_end }} @endif
                                @if ($currentProfile->vmid_pattern) · VMID-Muster <span class="font-mono">{{ $currentProfile->vmid_pattern }}</span> @endif
                            </x-input.hilfe>
                        @elseif ($profiles->isEmpty())
                            <x-input.hilfe>Noch kein Profil – <a href="{{ route('profiles.create') }}" class="text-cerulean-600 hover:underline">jetzt anlegen</a>, dann werden IPs und VMIDs vorgeschlagen.</x-input.hilfe>
                        @endif
                    </x-slot:unten>
                </x-field>

                <div class="grid grid-cols-2 gap-3 sm:col-span-2" role="radiogroup" aria-label="Adressierung">
                    @foreach (['static' => ['Statische IP', 'Feste Adresse – empfohlen für Dienste wie DNS.'], 'dhcp' => ['DHCP', 'Adresse vom DHCP-Server; ProxApps wartet auf die Zuweisung.']] as $mode => [$label, $text])
                        <button type="button" wire:click="setNetMode('{{ $mode }}')" role="radio" aria-checked="{{ $v['net_mode'] === $mode ? 'true' : 'false' }}"
                            @class([
                                'rounded-lg border p-3 text-left transition-colors',
                                'border-cerulean-500 bg-white ring-1 ring-cerulean-500 dark:bg-gray-900' => $v['net_mode'] === $mode,
                                'border-gray-200 bg-white/60 hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900/40' => $v['net_mode'] !== $mode,
                            ])>
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $text }}</div>
                        </button>
                    @endforeach
                </div>

                @if ($static)
                    <x-field label="IP-Adresse" for="f-ip" feld="ip" required class="sm:col-span-2">
                        <x-slot:badge>
                            <x-check-badge :check="$checks['ip']" target="v.ip,check,suggestIp,suggest" />
                        </x-slot:badge>
                        <div class="mt-1 flex gap-2">
                            <x-input.text id="f-ip" feld="ip" wire:model.live.debounce.500ms="v.ip" class="w-full min-w-0 font-mono" placeholder="10.10.250.53" autocomplete="off" />
                            <x-input.button type="button" color="gray" size="feld" wire:click="suggestIp" wire:loading.attr="disabled" wire:target="suggestIp" :disabled="! $currentProfile"
                                title="{{ $currentProfile ? 'Nächste freie Adresse aus dem Pool des Profils' : 'Nur mit Netzwerkprofil' }}">
                                <x-icon name="sparkles" class="h-4 w-4" />Nächste freie IP
                            </x-input.button>
                        </div>
                    </x-field>
                    <x-field label="Präfix" for="f-prefix" feld="prefix" required>
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center font-mono text-gray-400">/</span>
                            <x-input.text id="f-prefix" feld="prefix" type="number" min="1" max="32" wire:model="v.prefix" class="w-full pl-6 font-mono" />
                        </div>
                    </x-field>
                    <x-field label="Gateway" for="f-gw" feld="gateway">
                        <x-input.text id="f-gw" feld="gateway" wire:model.live.debounce.400ms="v.gateway" class="mt-1 w-full font-mono" placeholder="10.10.250.1" />
                    </x-field>
                @endif

                <x-field label="Bridge" for="f-bridge" feld="bridge" required>
                    @if ($options['bridges'])
                        <x-input.select id="f-bridge" feld="bridge" wire:model.live="v.bridge" class="mt-1">
                            @if (! in_array($v['bridge'], array_column($options['bridges'], 'iface'), true))
                                <option value="{{ $v['bridge'] }}">{{ $v['bridge'] }} (nicht auf dem Node)</option>
                            @endif
                            @foreach ($options['bridges'] as $b)
                                <option value="{{ $b['iface'] }}">{{ $bridgeLabel($b) }}</option>
                            @endforeach
                        </x-input.select>
                    @else
                        <x-input.text id="f-bridge" feld="bridge" wire:model="v.bridge" class="mt-1 w-full font-mono" placeholder="vmbr0" />
                    @endif
                </x-field>
                <x-field label="VLAN" for="f-vlan" feld="vlan" hilfe="Leer = ohne Tag.">
                    <x-input.text id="f-vlan" feld="vlan" type="number" min="1" max="4094" wire:model.live.debounce.400ms="v.vlan" class="mt-1 w-full font-mono" placeholder="–" />
                </x-field>
                <x-field label="DNS-Server" for="f-dns" feld="dns" hilfe="Durch Leerzeichen getrennt. Leer = vom Proxmox-Host.">
                    <x-input.text id="f-dns" feld="dns" wire:model="v.dns" class="mt-1 w-full font-mono" placeholder="10.10.250.1 1.1.1.1" />
                </x-field>
                <x-field label="Suchdomain" for="f-search" feld="searchdomain">
                    <x-input.text id="f-search" feld="searchdomain" wire:model="v.searchdomain" class="mt-1 w-full font-mono" placeholder="home.lan" />
                </x-field>
                <div class="sm:col-span-2">
                    <x-input.checkbox label="Proxmox-Firewall für die Netzwerkkarte aktivieren" wire:model="v.firewall" />
                </div>
            </div>

            {{-- ============ App-Einstellungen ============ --}}
            @if ($app->parameters)
                <x-form-section title="App-Einstellungen" />
                <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    @foreach ($app->parameters as $p)
                        @php($fk = 'param_'.$p['key'])
                        @if ($p['type'] === 'boolean')
                            <div class="flex flex-col sm:col-span-2">
                                <x-input.checkbox :label="$p['label']" :hilfe="$p['help'] ?: null" wire:model="v.params.{{ $p['key'] }}" />
                                <x-input.fehler :feld="$fk" />
                            </div>
                        @else
                            <x-field :label="$p['label']" :for="'f-'.$fk" :feld="$fk" :required="$p['required'] && ! ($p['type'] === 'password' && $p['generate'])" :hilfe="$p['help'] ?: null">
                                @if ($p['type'] === 'select')
                                    <x-input.select :id="'f-'.$fk" :feld="$fk" wire:model="v.params.{{ $p['key'] }}" class="mt-1">
                                        @unless ($p['required'])
                                            <option value="">–</option>
                                        @endunless
                                        @foreach ($p['options'] as $o)
                                            <option value="{{ $o['value'] ?? '' }}">{{ $o['label'] ?? ($o['value'] ?? '') }}</option>
                                        @endforeach
                                    </x-input.select>
                                @elseif ($p['type'] === 'password')
                                    <div class="relative mt-1" x-data="{ show: false }">
                                        <x-input.text :id="'f-'.$fk" :feld="$fk" type="password" x-bind:type="show ? 'text' : 'password'"
                                            wire:model="v.params.{{ $p['key'] }}" class="w-full pr-10 font-mono" autocomplete="new-password"
                                            :placeholder="$p['generate'] ? 'leer = generieren' : ''" />
                                        <button type="button" x-on:click="show = !show" tabindex="-1" x-bind:aria-label="show ? 'Passwort verbergen' : 'Passwort anzeigen'"
                                            class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-cerulean-600 dark:text-gray-500 dark:hover:text-gray-300">
                                            <x-icon name="eye" class="h-5 w-5" x-show="!show" />
                                            <x-icon name="eye-off" class="h-5 w-5" x-show="show" x-cloak />
                                        </button>
                                    </div>
                                @else
                                    <x-input.text :id="'f-'.$fk" :feld="$fk" :type="$p['type'] === 'number' ? 'number' : 'text'"
                                        :min="$p['min'] ?? null" :max="$p['max'] ?? null"
                                        wire:model="v.params.{{ $p['key'] }}" class="mt-1 w-full" autocomplete="off" />
                                @endif
                            </x-field>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- ============ Erweitert ============ --}}
            <div x-data="{ open: $wire.entangle('showAdvanced') }" class="mt-6 border-t border-gray-100 pt-4 dark:border-gray-700">
                <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()"
                    class="group flex w-full items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-cerulean-600 dark:text-gray-500">
                    <span>Erweitert</span>
                    <x-icon name="chevron-down" class="h-4 w-4 transition-transform" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-cloak x-transition.opacity class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <x-input.checkbox label="Unprivilegierter Container" hilfe="Empfohlen. Root im Container ist auf dem Host kein Root." wire:model="v.unprivileged" />
                        <x-input.checkbox label="Nesting erlauben" hilfe="Nötig für systemd-Dienste in neueren Debian-Versionen." wire:model="v.nesting" />
                        <x-input.checkbox label="Beim Booten des Hosts starten" wire:model="v.onboot" />
                        <x-input.checkbox label="Pakete beim Einrichten aktualisieren (apt upgrade)" wire:model="v.apt_upgrade" />
                        <x-input.checkbox label="Container bei Fehlschlag automatisch entfernen" hilfe="Sonst bleibt er zur Fehlersuche bestehen." wire:model="v.destroy_on_failure" />
                    </div>
                    <x-field label="Tags" for="f-tags" feld="tags" hilfe="Durch Semikolon getrennt. „proxapps“ wird immer gesetzt.">
                        <x-input.text id="f-tags" feld="tags" wire:model="v.tags" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="Root-Passwort" for="f-rootpw" feld="root_password" hilfe="Leer lassen: Anmeldung nur per SSH-Schlüssel.">
                        <x-input.text id="f-rootpw" feld="root_password" type="password" wire:model="v.root_password" class="mt-1 w-full" autocomplete="new-password" />
                    </x-field>
                    <x-field label="Zusätzliche SSH-Schlüssel" for="f-keys" feld="ssh_keys" class="sm:col-span-2" hilfe="Ein öffentlicher Schlüssel pro Zeile (für root).">
                        <x-input.textarea id="f-keys" feld="ssh_keys" rows="3" wire:model="v.ssh_keys" class="mt-1" placeholder="ssh-ed25519 AAAA… user@host" />
                    </x-field>
                    @if ($pubkey)
                        <div class="sm:col-span-2 rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-600 dark:bg-gray-900/40">
                            <div class="mb-1 flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>Deployer-Schlüssel (wird immer hinterlegt, damit ProxApps einrichten kann)</span>
                                <x-copy :value="$pubkey" :show="false" />
                            </div>
                            <div class="font-mono text-[11px] leading-relaxed text-gray-700 wrap-anywhere dark:text-gray-300">{{ $pubkey }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-6 flex flex-row flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-700">
                <span wire:loading wire:target="submit" class="text-xs text-cerulean-600 dark:text-cerulean-400">Prüfe Eingaben bei Proxmox …</span>
                <x-input.linkbutton :href="route('apps.index')" color="gray">Abbrechen</x-input.linkbutton>
                <x-input.button wire:loading.attr="disabled" wire:target="submit" :disabled="$connections->isEmpty()">
                    <x-icon name="rocket" class="h-4 w-4" sw="2" />Jetzt ausrollen
                </x-input.button>
            </div>
        </x-panel>

        {{-- ============ right column ============ --}}
        <aside class="flex min-w-0 flex-col gap-3 lg:sticky lg:top-20">
            <x-panel>
                <div class="flex items-start gap-3">
                    <x-app-icon :app="$app" class="h-12 w-12 p-1.5" />
                    <div class="min-w-0">
                        <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $app->name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Version {{ $app->version }} · {{ $app->template }}</div>
                    </div>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ trim($app->description ?: $app->summary) }}</p>

                @if ($ports)
                    <div class="mt-4 mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ports</div>
                    <table class="w-full text-sm">
                        @foreach ($ports as $port)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700/50">
                                <td class="py-1 pr-4 font-mono text-gray-900 dark:text-gray-100">{{ $port['port'] }}<span class="text-gray-400">/{{ $port['proto'] }}</span></td>
                                <td class="py-1 text-gray-500 dark:text-gray-400">{{ $port['label'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($app->website || $app->source)
                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        @if ($app->website)
                            <a href="{{ $app->website }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-cerulean-600 hover:text-cerulean-700 hover:underline dark:text-cerulean-400">
                                <x-icon name="globe" class="h-4 w-4" />Website
                            </a>
                        @endif
                        @if ($app->source)
                            <a href="{{ $app->source }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-cerulean-600 hover:text-cerulean-700 hover:underline dark:text-cerulean-400">
                                <x-icon name="external" class="h-4 w-4" />Quellcode
                            </a>
                        @endif
                    </div>
                @endif
            </x-panel>

            <x-panel>
                <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Zusammenfassung</div>
                <table class="w-full text-sm">
                    <x-kv label="Ziel" :value="$currentConnection ? $currentConnection->name.($v['node'] ? ' / '.$v['node'] : '') : null" />
                    <x-kv label="Container" mono>
                        @if ($v['vmid'] !== '' || $v['hostname'] !== '')
                            CT {{ $v['vmid'] ?: '?' }} · {{ $v['hostname'] ?: '?' }}
                        @endif
                    </x-kv>
                    <x-kv label="Netzwerk" mono>
                        @if ($static)
                            {{ $v['ip'] ?: '?' }}/{{ $v['prefix'] }}
                        @else
                            DHCP
                        @endif
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $v['bridge'] }}{{ $v['vlan'] !== '' ? ' · VLAN '.$v['vlan'] : '' }}{{ $static && $v['gateway'] ? ' · GW '.$v['gateway'] : '' }}</span>
                    </x-kv>
                    <x-kv label="Ressourcen">
                        {{ $v['cores'] }} Kern{{ (string) $v['cores'] === '1' ? '' : 'e' }} · {{ $v['memory'] }} MB · {{ $v['disk'] }} GB
                        @if ($resourcesChanged)
                            <span class="ml-1 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-900 dark:text-amber-100">angepasst</span>
                        @endif
                    </x-kv>
                    <x-kv label="Speicher" :value="$v['rootfs_storage'] ?: null" mono />
                    <x-kv label="Vorlage" :value="$templateLabel" />
                </table>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    ProxApps legt einen neuen Container mit dem Tag <span class="font-mono">proxapps</span> an und verändert keine bestehenden Gäste.
                </p>
            </x-panel>
        </aside>
    </form>
</div>
