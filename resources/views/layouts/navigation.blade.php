<nav class="fixed top-0 z-50 w-full bg-white border-b border-gray-200 dark:bg-gray-900 dark:border-gray-700">
    <div class="px-3 py-3 lg:px-5 lg:pl-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center justify-start">
                {{-- mobile: opens the drawer --}}
                <button type="button" x-on:click="mobilUmschalten()" aria-controls="logo-sidebar"
                    :aria-expanded="mobilOffen.toString()"
                    class="inline-flex items-center p-2 text-sm text-gray-500 rounded-lg lg:hidden hover:bg-gray-100 focus:outline-hidden focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600">
                    <span class="sr-only">Seitenleiste öffnen</span>
                    <svg class="w-6 h-6" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path clip-rule="evenodd" fill-rule="evenodd"
                            d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z">
                        </path>
                    </svg>
                </button>
                {{-- desktop only: collapses the sidebar --}}
                <button type="button" x-on:click="menuUmschalten()"
                    :aria-expanded="(! menuEin).toString()"
                    :title="menuEin ? 'Menü ausklappen' : 'Menü einklappen'"
                    class="hidden lg:inline-flex items-center p-2 text-sm text-gray-500 rounded-lg hover:bg-gray-100 focus:outline-hidden focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600">
                    <span class="sr-only">Menü ein- oder ausklappen</span>
                    <svg class="w-6 h-6" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path clip-rule="evenodd" fill-rule="evenodd"
                            d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z">
                        </path>
                    </svg>
                </button>
                <a href="{{ route('dashboard') }}" class="flex ml-2 md:mr-24">
                    <x-brand />
                </a>
            </div>

            <div class="hidden md:flex gap-1 dark:text-gray-100">
                <x-nav.link :url="route('dashboard')" name="Dashboard"><x-icon name="home" class="h-6 w-6" /></x-nav.link>
                <x-nav.link :url="route('apps.index')" name="App-Katalog"><x-icon name="apps" class="h-6 w-6" /></x-nav.link>
                <x-nav.link :url="route('deployments.index')" name="Deployments"><x-icon name="rocket" class="h-6 w-6" /></x-nav.link>
                <x-nav.link :url="route('updates.index')" name="Updates"><x-icon name="refresh" class="h-6 w-6" /></x-nav.link>
                <x-nav.link :url="route('connections.index')" name="Proxmox-Verbindungen"><x-icon name="server" class="h-6 w-6" /></x-nav.link>
            </div>

            <div class="flex items-center">
                <div class="flex items-center ml-3">
                    <div class="flex items-center gap-1 mr-3">
                        <x-theme-toggle class="dark:text-gray-400 hover:bg-cerulean-500 dark:hover:bg-gray-700" />
                    </div>
                    <x-nav.benutzermenue />
                </div>
            </div>
        </div>
    </div>
</nav>
