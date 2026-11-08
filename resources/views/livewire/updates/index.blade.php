@php
    use App\Http\Support\Format;
    use App\Models\UpdateRun;
    $runPill = [
        'queued' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        'running' => 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200',
        'success' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
        'failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100',
        'rolled_back' => 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
        'cancelled' => 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    ];
@endphp
<div @if ($poll) wire:poll.3s @endif>
    <x-page-header title="Updates">
        <x-input.button type="button" color="gray" wire:click="checkAll" wire:loading.attr="disabled" wire:target="checkAll">
            <x-icon name="refresh" class="h-4 w-4" />Jetzt prüfen
        </x-input.button>
        @if ($pending > 0)
            <x-input.button type="button"
                x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($pending.' Container aktualisieren?') }}, text: 'Für jeden Container wird vorher ein Snapshot angelegt (falls aktiviert). Debian-Pakete und App werden aktualisiert, die Funktion wird danach geprüft.', button: 'Alle aktualisieren', tone: 'blue', ok: () => $wire.updateAll() })">
                <x-icon name="sparkles" class="h-4 w-4" />Alle aktualisieren ({{ $pending }})
            </x-input.button>
        @endif
    </x-page-header>

    <div class="grid grid-cols-1 gap-3 p-3 xl:grid-cols-[1fr_22rem]">
        <div class="min-w-0">
            @if ($deployments->isEmpty())
                <x-emptystate message="Noch keine verwalteten Container. Rolle zuerst eine App aus." class="m-0!">
                    <x-input.linkbutton :href="route('apps.index')">Zum App-Katalog</x-input.linkbutton>
                </x-emptystate>
            @else
                <div class="rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800 overflow-x-auto">
                    <table class="w-full min-w-max text-sm text-left text-gray-500 dark:text-gray-400 sm:min-w-0">
                        <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                            <tr>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Container</th>
                                <th scope="col" class="py-2.5 px-4 font-semibold">App-Version</th>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Debian</th>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Letztes Update</th>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Geprüft</th>
                                <th scope="col" class="py-2.5 px-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deployments as $d)
                                @php $run = $lastRuns[$d->id] ?? null; $busy = $run && $run->isActive(); @endphp
                                <tr wire:key="upd-{{ $d->id }}" class="bg-white border-b border-gray-100 last:border-0 dark:bg-gray-800 dark:border-gray-700 align-top">
                                    <td class="py-2.5 px-4">
                                        <a href="{{ route('deployments.show', $d) }}" class="font-medium text-cerulean-500 hover:text-cerulean-600">{{ $d->hostname }}</a>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $d->app_name }} · CT <span class="font-mono">{{ $d->vmid }}</span> · <span class="font-mono">{{ $d->ip }}</span></div>
                                    </td>
                                    <td class="py-2.5 px-4">
                                        <span class="font-mono text-gray-900 dark:text-gray-100">{{ $d->app_version ?? '—' }}</span>
                                        @if ($d->appUpdateAvailable())
                                            <div class="mt-1 inline-flex items-center gap-1 rounded bg-cerulean-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">
                                                Neu <span class="font-mono font-normal normal-case tracking-normal">{{ $d->latest_version }}</span>
                                            </div>
                                        @elseif ($d->latest_version && $d->app_version)
                                            <div class="mt-1 text-xs text-green-700 dark:text-green-400">aktuell</div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-4">
                                        @if ($d->os_updates === null)
                                            <span class="text-gray-400 dark:text-gray-500">—</span>
                                        @elseif ($d->os_updates > 0)
                                            <span class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900 dark:bg-amber-900 dark:text-amber-100">{{ $d->os_updates }} Paket(e)</span>
                                        @else
                                            <span class="text-xs text-green-700 dark:text-green-400">aktuell</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-4">
                                        @if ($run)
                                            <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $runPill[$run->status] ?? '' }}">
                                                @if ($busy)<span class="h-1.5 w-1.5 rounded-full bg-cerulean-500 animate-pulse"></span>@endif
                                                {{ UpdateRun::STATUS_LABELS[$run->status] ?? $run->status }}
                                            </span>
                                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                @if ($busy)
                                                    {{ UpdateRun::stepLabel($run->step) }}
                                                @else
                                                    {{ Format::dt($run->finished_at ?? $run->created_at) }}{{ $run->trigger === 'schedule' ? ' · automatisch' : '' }}
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-4 text-xs">
                                        <span class="font-mono text-gray-900 dark:text-gray-100">{{ $d->updates_checked_at ? Format::dt($d->updates_checked_at) : 'nie' }}</span>
                                        @if ($d->update_check_error)
                                            <div class="mt-0.5 max-w-xs text-red-600 wrap-anywhere dark:text-red-400" title="{{ $d->update_check_error }}">{{ \Illuminate\Support\Str::limit($d->update_check_error, 90) }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-4">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-input.symbolknopf titel="Jetzt prüfen" wire:click="checkOne({{ $d->id }})">
                                                <x-icon name="refresh" class="h-5 w-5" />
                                            </x-input.symbolknopf>
                                            @if (! $busy)
                                                <x-input.button type="button" size="sm" :color="$d->hasUpdates() ? 'blue' : 'gray'"
                                                    x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($d->hostname.' aktualisieren?') }}, text: 'Vorher wird ein Snapshot angelegt (falls aktiviert). Debian-Pakete und App werden aktualisiert und danach geprüft.', button: 'Aktualisieren', tone: 'blue', ok: () => $wire.update({{ $d->id }}) })">
                                                    Aktualisieren
                                                </x-input.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 px-1 text-xs text-gray-500 dark:text-gray-400">
                    ProxApps prüft alle 6 Stunden automatisch auf neue Versionen. Details und Protokoll jedes Updates stehen beim jeweiligen Deployment.
                </p>
            @endif
        </div>

        <form wire:submit="saveSettings">
            <x-panel>
                <div class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">Automatische Updates</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Im Update-Fenster prüft ProxApps alle Container und aktualisiert die mit ausstehenden Updates.
                </p>
                <div class="mt-4 flex flex-col gap-3">
                    <x-input.checkbox label="Update-Fenster aktiv" wire:model="auto_update_enabled" />
                    <x-field label="Uhrzeit ({{ $tz }})" for="u-time" feld="auto_update_time">
                        <x-input.text id="u-time" feld="auto_update_time" type="time" wire:model="auto_update_time" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-form-section title="Sicherheit" />
                    <x-input.checkbox label="Vor jedem Update einen Snapshot anlegen" wire:model="snapshot_before_update"
                        hilfe="Proxmox-Snapshot des Containers – Rollback mit einem Klick." />
                    <x-input.checkbox label="Bei Fehlschlag automatisch zurückrollen" wire:model="auto_rollback"
                        hilfe="Schlägt ein Schritt oder die Funktionsprüfung fehl, wird der Snapshot wiederhergestellt." />
                    <x-field label="Snapshots behalten (pro Container)" for="u-keep" feld="keep_snapshots" hilfe="Ältere ProxApps-Snapshots werden nach erfolgreichen Updates gelöscht.">
                        <x-input.text id="u-keep" feld="keep_snapshots" type="number" min="1" max="20" wire:model="keep_snapshots" class="mt-1 w-full font-mono" />
                    </x-field>
                </div>
                <div class="mt-5 flex justify-end">
                    <x-input.button wire:loading.attr="disabled" wire:target="saveSettings" label="Speichern" />
                </div>
            </x-panel>
        </form>
    </div>
</div>
