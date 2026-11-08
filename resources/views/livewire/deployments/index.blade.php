<div @if ($hasActive) wire:poll.5s @endif>
    <x-page-header title="Deployments">
        <x-input.select wire:model.live="status" class="w-48 py-2! text-sm!" aria-label="Status filtern">
            <option value="">Alle Status</option>
            <option value="active">Laufend</option>
            <option value="success">Erfolgreich</option>
            <option value="failed">Fehlgeschlagen</option>
            <option value="cancelled">Abgebrochen</option>
            <option value="removed">Entfernt</option>
        </x-input.select>
        <x-input.linkbutton :href="route('apps.index')">
            <x-icon name="rocket" class="w-4 h-4" sw="2" />App ausrollen
        </x-input.linkbutton>
    </x-page-header>

    @if ($deployments->isEmpty())
        <x-emptystate :message="$status === '' ? 'Noch nichts ausgerollt.' : 'Keine Deployments mit diesem Status.'">
            @if ($status === '')
                <x-input.linkbutton :href="route('apps.index')">Zum App-Katalog</x-input.linkbutton>
            @endif
        </x-emptystate>
    @else
        <div class="m-3">
            <div class="rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800 overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left text-gray-500 dark:text-gray-400 sm:min-w-0">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                        <tr>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Status</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">App</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Hostname</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">VMID</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">IP</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Node</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Verbindung</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Erstellt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deployments as $d)
                            <tr wire:key="dep-{{ $d->id }}" onclick="if (!event.target.closest('a')) window.location='{{ route('deployments.show', $d) }}'"
                                @class([
                                    'cursor-pointer bg-white border-b border-gray-100 last:border-0 hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:border-gray-700 dark:hover:bg-gray-700/50',
                                    'opacity-60' => $d->status === 'removed',
                                ])>
                                <td class="py-2.5 px-4"><x-status-pill :status="$d->status" /></td>
                                <td class="py-2.5 px-4 text-gray-900 dark:text-gray-100">{{ $d->app_name }}</td>
                                <td class="py-2.5 px-4">
                                    <a href="{{ route('deployments.show', $d) }}" class="text-cerulean-500 hover:text-cerulean-600">{{ $d->hostname }}</a>
                                </td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $d->vmid }}</td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">
                                    @if ($d->ip)
                                        {{ $d->ip }}
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">{{ ($d->spec['net']['mode'] ?? '') === 'dhcp' ? 'DHCP' : '—' }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 text-gray-900 dark:text-gray-100">{{ $d->node }}</td>
                                <td class="py-2.5 px-4">
                                    @if ($d->connection)
                                        {{ $d->connection->name }}
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{{ \App\Http\Support\Format::dt($d->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @if ($deployments->hasPages())
            <div class="px-3 pb-3">{{ $deployments->links() }}</div>
        @endif
    @endif
</div>
