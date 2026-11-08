<div>
    <x-page-header title="App-Katalog">
        <label class="relative">
            <span class="sr-only">Katalog durchsuchen</span>
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Suche"
                class="rounded-sm border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-300 focus:border-cerulean-500 dark:focus:border-cerulean-500 focus:ring-cerulean-500 dark:focus:ring-cerulean-600 w-56 pl-9 pr-3 py-2 text-sm">
        </label>
    </x-page-header>

    @foreach ($errors_ as $file => $message)
        <div class="m-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">
            <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" />
            <span><span class="font-mono">{{ is_string($file) ? $file : '' }}</span> {{ is_array($message) ? implode(' ', $message) : $message }}</span>
        </div>
    @endforeach

    @if (! $apps)
        @if ($search !== '')
            <div class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Keine App passt zu „{{ $search }}“.</div>
        @else
            <x-emptystate message="Der Katalog ist leer. Apps liegen als catalog/<id>/app.yaml + install.sh vor." />
        @endif
    @else
        <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($apps as $app)
                <x-panel class="flex flex-col" wire:key="app-{{ $app->id }}">
                    <div class="flex items-start gap-4">
                        <x-app-icon :app="$app" class="h-14 w-14 p-1.5" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('apps.deploy', $app->id) }}"
                                    class="text-xl font-CoconPro text-gray-900 hover:text-cerulean-600 dark:text-gray-100 dark:hover:text-cerulean-400">{{ $app->name }}</a>
                                <span class="inline-flex items-center rounded bg-cerulean-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">{{ $app->category }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $app->summary }}</p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            <x-icon name="cpu" class="h-3.5 w-3.5 text-gray-400" />{{ $app->resources['cores'] ?? '?' }} Kern{{ ($app->resources['cores'] ?? 1) == 1 ? '' : 'e' }}
                        </span>
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $app->resources['memory'] ?? '?' }} MB RAM</span>
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $app->resources['disk'] ?? '?' }} GB Disk</span>
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $app->template }}</span>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 pt-5">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            @if ($n = $counts[$app->id] ?? 0)
                                <a href="{{ route('deployments.index') }}" class="hover:text-cerulean-600">{{ $n }}× ausgerollt</a>
                            @else
                                noch nicht ausgerollt
                            @endif
                            @if ($app->website)
                                · <a href="{{ $app->website }}" target="_blank" rel="noopener noreferrer" class="hover:text-cerulean-600">Website</a>
                            @endif
                        </span>
                        <x-input.linkbutton :href="route('apps.deploy', $app->id)">
                            <x-icon name="rocket" class="h-4 w-4" sw="2" />Ausrollen
                        </x-input.linkbutton>
                    </div>
                </x-panel>
            @endforeach
        </div>
    @endif
</div>
