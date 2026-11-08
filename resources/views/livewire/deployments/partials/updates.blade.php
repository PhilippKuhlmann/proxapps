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
<x-panel>
    <div class="flex items-center justify-between gap-2">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Updates</div>
        <div class="flex items-center gap-2">
            <x-input.symbolknopf titel="Auf Updates prüfen" wire:click="checkUpdates">
                <x-icon name="refresh" class="h-5 w-5" />
            </x-input.symbolknopf>
            @unless ($updateActive)
                <x-input.button type="button" size="sm" :color="$d->hasUpdates() ? 'blue' : 'gray'"
                    x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($d->hostname.' aktualisieren?') }}, text: 'Vorher wird ein Snapshot angelegt (falls aktiviert). Debian-Pakete und App werden aktualisiert und danach geprüft.', button: 'Aktualisieren', tone: 'blue', ok: () => $wire.startUpdate() })">
                    Aktualisieren
                </x-input.button>
            @endunless
        </div>
    </div>

    <table class="mt-2 w-full text-sm">
        <x-kv label="App-Version" :value="$d->app_version ?? '—'" mono />
        <x-kv label="Neueste Version" mono>
            @if ($d->latest_version)
                {{ $d->latest_version }}
                @if ($d->appUpdateAvailable())
                    <span class="ml-1 rounded bg-cerulean-100 px-1.5 py-0.5 text-[11px] font-semibold text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">Update</span>
                @endif
            @else
                <span class="text-gray-400 dark:text-gray-500">—</span>
            @endif
        </x-kv>
        <x-kv label="Debian-Pakete" :value="$d->os_updates === null ? '—' : ($d->os_updates > 0 ? $d->os_updates.' ausstehend' : 'aktuell')" />
        <x-kv label="Geprüft" :value="$d->updates_checked_at ? Format::dt($d->updates_checked_at) : 'nie'" mono />
    </table>
    @if ($d->update_check_error)
        <div class="mt-2 text-xs text-red-600 wrap-anywhere dark:text-red-400">{{ $d->update_check_error }}</div>
    @endif

    @if ($updateRuns->isNotEmpty())
        <div class="mt-4 mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Verlauf</div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($updateRuns as $run)
                <li wire:key="run-{{ $run->id }}" class="py-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $runPill[$run->status] ?? '' }}">
                            @if ($run->isActive())<span class="h-1.5 w-1.5 rounded-full bg-cerulean-500 animate-pulse"></span>@endif
                            {{ UpdateRun::STATUS_LABELS[$run->status] ?? $run->status }}
                        </span>
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ Format::dt($run->finished_at ?? $run->created_at) }}</span>
                    </div>
                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                        @if ($run->isActive())
                            {{ UpdateRun::stepLabel($run->step) }} …
                        @else
                            @if ($run->from_version || $run->to_version)
                                <span class="font-mono">{{ $run->from_version ?? '?' }} → {{ $run->to_version ?? '?' }}</span> ·
                            @endif
                            {{ $run->os_packages !== null ? $run->os_packages.' Paket(e)' : '' }}
                            {{ $run->trigger === 'schedule' ? '· automatisch' : '' }}
                        @endif
                    </div>
                    @if ($run->error)
                        <div class="mt-1 text-xs text-red-600 wrap-anywhere dark:text-red-400">{{ $run->error }}</div>
                    @endif
                    @if ($run->snapshot && $run->snapshot_exists && ! $run->isActive() && ! $updateActive)
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <span class="font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $run->snapshot }}</span>
                            <button type="button" class="text-xs text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400"
                                x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Auf Stand vor Update #'.$run->id.' zurückrollen?') }}, text: {{ \Illuminate\Support\Js::from('Der Container wird auf den Snapshot '.$run->snapshot.' zurückgesetzt und neu gestartet. Alle Änderungen seitdem gehen verloren.') }}, button: 'Zurückrollen', ok: () => $wire.rollback({{ $run->id }}) })">
                                Zurückrollen
                            </button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-panel>
