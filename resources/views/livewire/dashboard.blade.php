@php($active = ($counts['queued'] ?? 0) + ($counts['running'] ?? 0))
<div class="p-3 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="text-3xl font-CoconPro text-gray-900 dark:text-gray-100">Dashboard</div>
        <x-input.linkbutton :href="route('apps.index')">
            <x-icon name="rocket" class="w-4 h-4" sw="2" />App ausrollen
        </x-input.linkbutton>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 mb-6">
        <x-stat-tile :href="route('connections.index')" icon="server" :value="$connections->count()" label="Verbindungen" />
        <x-stat-tile :href="route('apps.index')" icon="apps" :value="count($apps)" label="Apps im Katalog" />
        <x-stat-tile :href="route('deployments.index')" icon="check" ton="gruen" :value="$counts['success'] ?? 0" label="Erfolgreich ausgerollt" />
        <x-stat-tile :href="route('deployments.index')" icon="clock" :value="$active" label="Laufend" />
        <x-stat-tile :href="route('deployments.index')" icon="warn" ton="rot" :value="$counts['failed'] ?? 0" label="Fehlgeschlagen" />
    </div>

    {{-- onboarding --}}
    @if ($connections->isEmpty())
        <x-callout title="Noch keine Proxmox-Verbindung" class="mb-5">
            Verbinde zuerst deinen Proxmox-Server oder -Cluster über ein API-Token. Danach kannst du Apps mit wenigen Klicks ausrollen.
            <x-slot:aktionen>
                <x-input.linkbutton :href="route('connections.create')">
                    <x-icon name="plus" class="w-4 h-4" sw="2.2" />Verbindung anlegen
                </x-input.linkbutton>
            </x-slot:aktionen>
        </x-callout>
    @elseif ($profileCount === 0)
        <x-callout title="Tipp: Netzwerkprofil anlegen" class="mb-5">
            Mit einem Netzwerkprofil (Bridge, VLAN, Netz, Gateway, IP-Pool, VMID-Muster) schlägt ProxApps freie IPs und passende VMIDs automatisch vor.
            <x-slot:aktionen>
                <x-input.linkbutton :href="route('profiles.create')" color="tint">Profil anlegen</x-input.linkbutton>
            </x-slot:aktionen>
        </x-callout>
    @endif

    @if ($connections->isNotEmpty())
        <div class="mb-6">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Proxmox-Verbindungen</div>
            <div class="flex flex-col gap-2">
                @foreach ($connections as $connection)
                    <livewire:connection-status :connection="$connection" :key="'conn-'.$connection->id" />
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <x-dashboard-tile title="Letzte Deployments">
            <x-slot:aktion>
                <a href="{{ route('deployments.index') }}" class="text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">Alle anzeigen</a>
            </x-slot:aktion>
            @if ($recent->isEmpty())
                <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Noch nichts ausgerollt.</div>
            @else
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($recent as $d)
                        <a href="{{ route('deployments.show', $d) }}"
                            class="flex items-center justify-between gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <span class="min-w-0">
                                <span class="block truncate text-gray-800 dark:text-gray-100">{{ $d->hostname }} <span class="text-gray-400 dark:text-gray-500">· {{ $d->app_name }}</span></span>
                                <span class="block truncate text-xs text-gray-400 dark:text-gray-500">
                                    <span class="font-mono">CT {{ $d->vmid }}</span>{{ $d->ip ? ' · '.$d->ip : '' }} · {{ $d->node }} · {{ \App\Http\Support\Format::dt($d->created_at) }}
                                </span>
                            </span>
                            <x-status-pill :status="$d->status" class="shrink-0" />
                        </a>
                    @endforeach
                </div>
            @endif
        </x-dashboard-tile>

        <x-dashboard-tile title="App-Katalog">
            <x-slot:aktion>
                <a href="{{ route('apps.index') }}" class="text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">Katalog öffnen</a>
            </x-slot:aktion>
            @if (! $apps)
                <div class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Der Katalog ist leer.</div>
            @else
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($apps as $app)
                        <a href="{{ route('apps.deploy', $app->id) }}"
                            class="flex items-center gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <x-app-icon :app="$app" class="h-9 w-9" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-gray-800 dark:text-gray-100">{{ $app->name }}</span>
                                <span class="block truncate text-xs text-gray-400 dark:text-gray-500">{{ $app->summary }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-medium text-cerulean-600 dark:text-cerulean-400">Ausrollen</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-dashboard-tile>
    </div>
</div>
