@php
    use App\Http\Support\Format;

    $active = $d->isActive();
    $net = (array) ($spec['net'] ?? []);
@endphp

<div>
    @if ($active || $updateActive)
        <div wire:poll.1500ms="refreshLive"></div>
    @endif

    <x-page-header :title="$d->hostname" :back="route('deployments.index')" back-label="Deployments">
        <x-slot:badge>
            <x-status-pill :status="$d->status" />
        </x-slot:badge>
        <x-slot:sub>
            {{ $d->app_name }} · <span class="font-mono">CT {{ $d->vmid }}</span> auf {{ $d->node }}
            @if ($d->connection) · {{ $d->connection->name }} @endif
            · gestartet {{ Format::dt($d->created_at) }}
            @if ($d->finished_at) · beendet {{ Format::dt($d->finished_at) }} @endif
        </x-slot:sub>

        @if ($active)
            @if ($d->cancel_requested)
                <span class="text-sm text-amber-700 dark:text-amber-400">Abbruch angefordert …</span>
            @else
                <x-input.button type="button" color="gray"
                    x-on:click="$store.confirm.ask({ title: 'Ausrollen abbrechen?', text: 'Der laufende Schritt wird beendet. Ein bereits angelegter Container bleibt bestehen, außer „bei Fehlschlag entfernen“ ist aktiv.', button: 'Abbrechen', ok: () => $wire.cancel() })">
                    <x-icon name="x" class="h-4 w-4" sw="2" />Abbrechen
                </x-input.button>
            @endif
        @else
            @if ($canManage)
                <div class="flex items-center gap-1.5">
                    <x-input.symbolknopf titel="Starten" wire:click="action('start')" wire:loading.attr="disabled" wire:target="action">
                        <x-icon name="play" class="h-5 w-5" />
                    </x-input.symbolknopf>
                    <x-input.symbolknopf titel="Herunterfahren" wire:click="action('shutdown')" wire:loading.attr="disabled" wire:target="action">
                        <x-icon name="power" class="h-5 w-5" />
                    </x-input.symbolknopf>
                    <x-input.symbolknopf titel="Neustarten" wire:click="action('reboot')" wire:loading.attr="disabled" wire:target="action">
                        <x-icon name="refresh" class="h-5 w-5" />
                    </x-input.symbolknopf>
                    <x-input.symbolknopf titel="Stoppen (hart)" ton="rot" wire:loading.attr="disabled" wire:target="action"
                        x-on:click="$store.confirm.ask({ title: 'Container hart stoppen?', text: 'Entspricht dem Ziehen des Netzsteckers. Lieber „Herunterfahren“, wenn möglich.', button: 'Stoppen', ok: () => $wire.action('stop') })">
                        <x-icon name="stop" class="h-5 w-5" />
                    </x-input.symbolknopf>
                </div>
                <span wire:loading wire:target="action,destroyContainer" class="text-xs text-cerulean-600 dark:text-cerulean-400">wird ausgeführt …</span>
            @endif
            @if ($app)
                <x-input.linkbutton :href="route('apps.deploy', $d->app_id).'?from='.$d->id" color="gray">
                    <x-icon name="refresh" class="h-4 w-4" />Erneut ausrollen
                </x-input.linkbutton>
            @endif
            @if ($canManage)
                <x-input.button type="button" color="red"
                    x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Container '.$d->vmid.' ('.$d->hostname.') entfernen?') }}, text: 'Der Container wird auf Proxmox gestoppt und samt Disks unwiderruflich gelöscht. ProxApps prüft vorher Tag und Hostname.', button: 'Container entfernen', ok: () => $wire.destroyContainer() })">
                    <x-icon name="trash" class="h-4 w-4" />Container entfernen
                </x-input.button>
            @endif
            <x-input.symbolknopf titel="Eintrag löschen" ton="rot"
                x-on:click="$store.confirm.ask({ title: 'Eintrag löschen?', text: 'Nur der Eintrag und das Protokoll in ProxApps werden gelöscht – der Container selbst bleibt unberührt.', button: 'Eintrag löschen', ok: () => $wire.deleteEntry() })">
                <x-icon name="x" class="h-5 w-5" />
            </x-input.symbolknopf>
        @endif
    </x-page-header>

    {{-- step tracker --}}
    <ol class="m-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8" aria-label="Fortschritt">
        @foreach ($steps as $i => [$key, $label])
            @php($state = $this->stepState($key))
            <li @class([
                'flex items-center gap-2 rounded-lg border px-2.5 py-2 text-sm shadow-xs',
                'border-gray-200 bg-white text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200' => $state === 'done',
                'border-cerulean-400 bg-cerulean-50 text-cerulean-800 ring-1 ring-cerulean-400 dark:border-cerulean-600 dark:bg-cerulean-900/20 dark:text-cerulean-200' => $state === 'active',
                'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-900/20 dark:text-rose-200' => $state === 'error',
                'border-gray-200 bg-white/60 text-gray-400 dark:border-gray-700 dark:bg-gray-800/40 dark:text-gray-500' => $state === 'pending',
            ]) @if ($state === 'active') aria-current="step" @endif>
                <span @class([
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                    'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' => $state === 'done',
                    'bg-cerulean-600 text-white' => $state === 'active',
                    'bg-rose-600 text-white' => $state === 'error',
                    'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500' => $state === 'pending',
                ])>
                    @if ($state === 'done')
                        <x-icon name="check" class="h-3.5 w-3.5" sw="2.6" />
                    @elseif ($state === 'active')
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    @elseif ($state === 'error')
                        <x-icon name="x" class="h-3.5 w-3.5" sw="2.6" />
                    @else
                        {{ $i + 1 }}
                    @endif
                </span>
                <span class="min-w-0 truncate">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if (in_array($d->status, ['failed', 'cancelled'], true) && $d->error)
        <div class="m-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-900/20" role="alert">
            <div class="text-sm font-medium text-red-800 dark:text-red-300">{{ $d->status === 'cancelled' ? 'Ausrollen abgebrochen' : 'Ausrollen fehlgeschlagen' }}</div>
            <div class="mt-1 text-sm text-red-700/90 wrap-anywhere dark:text-red-400/90">{{ $d->error }}</div>
            @if (! empty($result['container_removed']))
                <div class="mt-2 text-sm text-red-700/90 dark:text-red-400/90">Container wurde automatisch entfernt.</div>
            @endif
        </div>
    @elseif (! empty($result['container_removed']))
        <div class="m-3 text-sm text-gray-600 dark:text-gray-400">Container wurde auf Proxmox entfernt.</div>
    @endif

    <div class="m-3 grid grid-cols-1 items-start gap-3 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="flex min-w-0 flex-col gap-3">
            {{-- success result --}}
            @if ($d->status === 'success')
                <x-panel class="border-green-300! dark:border-green-800!">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300">
                            <x-icon name="check" class="h-5 w-5" sw="2.4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $d->app_name }} ist bereit</div>
                            @if (! empty($result['url']))
                                <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer"
                                    class="mt-1 inline-flex items-center gap-1.5 font-mono text-sm text-cerulean-600 hover:text-cerulean-700 hover:underline dark:text-cerulean-400">
                                    {{ $result['url'] }}<x-icon name="external" class="h-4 w-4" />
                                </a>
                            @endif
                        </div>
                    </div>

                    <table class="mt-4 w-full text-sm">
                        @if (! empty($result['url']))
                            <x-kv label="Adresse"><x-copy :value="$result['url']" class="font-mono text-[13px]" /></x-kv>
                        @endif
                        @if (! empty($result['user']))
                            <x-kv label="Benutzer"><x-copy :value="$result['user']" class="font-mono text-[13px]" /></x-kv>
                        @endif
                        @if (! empty($result['password_param']))
                            <x-kv label="Passwort">
                                <span class="inline-flex flex-wrap items-center gap-2">
                                    @if ($revealed !== null)
                                        <x-copy :value="$revealed['password'] ?: '—'" class="font-mono text-[13px]" />
                                    @else
                                        <span class="font-mono tracking-widest text-gray-500">••••••••••</span>
                                    @endif
                                    <button type="button" wire:click="toggleReveal" class="inline-flex items-center gap-1 text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">
                                        <x-icon :name="$revealed !== null ? 'eye-off' : 'eye'" class="h-4 w-4" />{{ $revealed !== null ? 'Verbergen' : 'Anzeigen' }}
                                    </button>
                                </span>
                            </x-kv>
                        @endif
                        @if ($hasRootPassword)
                            <x-kv label="Root-Passwort">
                                <span class="inline-flex flex-wrap items-center gap-2">
                                    @if ($revealed !== null)
                                        <x-copy :value="$revealed['root_password'] ?: '—'" class="font-mono text-[13px]" />
                                    @else
                                        <span class="font-mono tracking-widest text-gray-500">••••••••••</span>
                                        <button type="button" wire:click="toggleReveal" class="inline-flex items-center gap-1 text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">
                                            <x-icon name="eye" class="h-4 w-4" />Anzeigen
                                        </button>
                                    @endif
                                </span>
                            </x-kv>
                        @endif
                        <x-kv label="SSH" mono>
                            <x-copy :value="'ssh root@'.($d->ip ?: $d->hostname)" />
                        </x-kv>
                    </table>

                    @if (! empty($result['notes']))
                        <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm leading-relaxed whitespace-pre-line text-gray-700 dark:border-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ trim($result['notes']) }}</div>
                    @endif
                </x-panel>
            @endif

            {{-- log console --}}
            <div wire:ignore x-data="deploymentLog(@js($initialLines))" x-on:log-lines.window="append($event.detail.lines)"
                class="overflow-hidden rounded-xl border border-gray-800 shadow-xs">
                <div class="flex items-center justify-between gap-3 bg-gray-900 px-4 py-2 text-xs text-gray-400">
                    <span class="inline-flex items-center gap-2 font-semibold uppercase tracking-wide">
                        <x-icon name="terminal" class="h-4 w-4" />Protokoll
                    </span>
                    <button type="button" x-show="!autoscroll" x-cloak x-on:click="autoscroll = true; scrollDown()"
                        class="rounded px-2 py-0.5 text-cerulean-300 hover:bg-gray-800">Automatisch scrollen ↓</button>
                </div>
                <div x-ref="console" x-on:scroll.passive="onScroll()"
                    class="log-console bg-gray-950 font-mono text-xs max-h-[32rem] min-h-48 overflow-auto px-4 py-3 leading-relaxed">
                    <template x-for="l in lines" :key="l.id">
                        <div class="flex gap-3" :class="lineClass(l)">
                            <span class="shrink-0 select-none text-gray-600" x-text="l.time"></span>
                            <span class="min-w-0 whitespace-pre-wrap wrap-anywhere" x-text="l.message"></span>
                        </div>
                    </template>
                    <div x-show="!lines.length" class="text-gray-500">Noch keine Ausgabe.</div>
                </div>
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-3">
            @if ($canManage)
                <livewire:deployments.container-status :deployment="$d" :key="'ct-'.$d->id.'-'.$d->status" />
            @endif

            @if ($managed)
                @include('livewire.deployments.partials.updates')
            @endif

            <x-panel>
                <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Container</div>
                <table class="w-full text-sm">
                    <x-kv label="Node" :value="$d->node" />
                    <x-kv label="VMID" :value="$d->vmid" mono />
                    <x-kv label="Hostname" :value="$d->hostname" mono />
                    <x-kv label="IP" mono>
                        @if ($d->ip)
                            <x-copy :value="$d->ip" />{{ ($net['mode'] ?? '') === 'static' && isset($net['prefix']) ? '/'.$net['prefix'] : '' }}
                        @elseif (($net['mode'] ?? '') === 'dhcp')
                            DHCP
                        @endif
                    </x-kv>
                    <x-kv label="Ressourcen" :value="($spec['cores'] ?? '?').' Kern(e) · '.($spec['memory'] ?? '?').' MB RAM · '.($spec['swap'] ?? '?').' MB Swap · '.($spec['disk'] ?? '?').' GB'" />
                    <x-kv label="Speicher" :value="$spec['rootfs_storage'] ?? null" mono />
                    <x-kv label="Netzwerk">
                        {{ $net['bridge'] ?? '?' }}{{ ! empty($net['vlan']) ? ' · VLAN '.$net['vlan'] : '' }}{{ ! empty($net['gateway']) ? ' · GW '.$net['gateway'] : '' }}{{ ! empty($net['firewall']) ? ' · Firewall' : '' }}
                        @if (! empty($net['dns']) || ! empty($net['searchdomain']))
                            <span class="block text-xs text-gray-500 dark:text-gray-400">DNS {{ $net['dns'] ?: '–' }}{{ ! empty($net['searchdomain']) ? ' · '.$net['searchdomain'] : '' }}</span>
                        @endif
                    </x-kv>
                    <x-kv label="Vorlage" mono :value="($spec['template'] ?? '') === '' ? 'automatisch' : $spec['template']" />
                    <x-kv label="Optionen">
                        {{ collect([
                            ! empty($spec['unprivileged']) ? 'unprivilegiert' : 'privilegiert',
                            ! empty($spec['nesting']) ? 'Nesting' : null,
                            ! empty($spec['onboot']) ? 'Autostart' : null,
                            ! empty($spec['apt_upgrade']) ? 'apt upgrade' : null,
                            ! empty($spec['destroy_on_failure']) ? 'bei Fehler entfernen' : null,
                        ])->filter()->implode(' · ') }}
                    </x-kv>
                    <x-kv label="Tags" :value="$spec['tags'] ?? null" mono />
                </table>
            </x-panel>

            @if (! empty($spec['params']))
                <x-panel>
                    <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">App-Einstellungen</div>
                    <table class="w-full text-sm">
                        @foreach ($spec['params'] as $key => $value)
                            <x-kv :label="$app?->param($key)['label'] ?? $key" :value="(string) $value" mono />
                        @endforeach
                    </table>
                </x-panel>
            @endif
        </div>
    </div>
</div>
