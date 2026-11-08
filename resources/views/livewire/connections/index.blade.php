<div>
    <x-page-header title="Proxmox-Verbindungen">
        <x-input.linkbutton :href="route('connections.create')">
            <x-icon name="plus" class="w-4 h-4" sw="2.2" />Neu
        </x-input.linkbutton>
    </x-page-header>

    @if ($connections->isEmpty())
        <x-emptystate message="Noch keine Verbindung angelegt.">
            <x-input.linkbutton :href="route('connections.create')">
                <x-icon name="plus" class="w-4 h-4" sw="2.2" />Verbindung anlegen
            </x-input.linkbutton>
        </x-emptystate>
    @else
        <div class="m-3">
            <div class="rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800 overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left text-gray-500 dark:text-gray-400 sm:min-w-0">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                        <tr>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Name</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Adresse</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Token</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">TLS</th>
                            <th scope="col" class="py-2.5 px-4 font-semibold">Deployments</th>
                            <th scope="col" class="py-2.5 px-4"><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($connections as $c)
                            <tr wire:key="conn-{{ $c->id }}"
                                class="bg-white border-b border-gray-100 last:border-0 hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:border-gray-700 dark:hover:bg-gray-700/50">
                                <td class="py-2.5 px-4 text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('connections.edit', $c) }}" class="hover:text-cerulean-600">{{ $c->name }}</a>
                                </td>
                                <td class="py-2.5 px-4">
                                    <a href="{{ $c->url }}" target="_blank" rel="noopener noreferrer"
                                        class="font-mono text-cerulean-500 hover:text-cerulean-600">{{ $c->url }}</a>
                                </td>
                                <td class="py-2.5 px-4 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $c->token_id }}</td>
                                <td class="py-2.5 px-4">
                                    @if ($c->tls_mode === 'verify')
                                        <span class="inline-flex items-center gap-1 rounded bg-green-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-green-800 dark:bg-green-900 dark:text-green-100">CA-geprüft</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5" title="{{ $c->fingerprint }}">
                                            <span class="inline-flex items-center gap-1 rounded bg-cerulean-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">Fingerabdruck</span>
                                            <span class="font-mono text-xs text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) $c->fingerprint, 11, '…') }}</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4">
                                    <span class="inline-flex h-[18px] min-w-[20px] items-center justify-center rounded bg-cerulean-100 px-1.5 font-mono text-[11px] font-semibold text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">{{ $c->deployments_count }}</span>
                                    @if ($c->active_count)
                                        <span class="ml-1 text-xs text-cerulean-600 dark:text-cerulean-400">{{ $c->active_count }} laufend</span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-input.symbolknopf titel="Verbindung testen" ton="grau"
                                            wire:click="test({{ $c->id }})" wire:loading.attr="disabled" wire:target="test({{ $c->id }})">
                                            <x-icon name="signal" class="h-5 w-5" wire:loading.remove wire:target="test({{ $c->id }})" />
                                            <x-icon name="refresh" class="h-5 w-5 animate-spin" wire:loading wire:target="test({{ $c->id }})" />
                                        </x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Bearbeiten" :href="route('connections.edit', $c)">
                                            <x-icon name="pencil" class="h-5 w-5" />
                                        </x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Löschen" ton="rot"
                                            x-on:click="$store.confirm.ask({
                                                title: {{ \Illuminate\Support\Js::from('Verbindung „'.$c->name.'“ löschen?') }},
                                                text: 'Die Container auf dem Proxmox-Server bleiben unberührt. Bestehende Deployments verlieren nur die Zuordnung.',
                                                button: 'Löschen',
                                                ok: () => $wire.delete({{ $c->id }}),
                                            })">
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
