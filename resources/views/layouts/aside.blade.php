{{-- Mobile drawer backdrop (below lg) --}}
<div x-show="mobilOffen" x-cloak x-on:click="mobilUmschalten(false)" x-transition.opacity
    class="bg-gray-900/50 dark:bg-gray-900/80 fixed inset-0 z-30 lg:hidden" aria-hidden="true"></div>

<aside id="logo-sidebar"
    class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full text-gray-900 bg-white border-r border-gray-200 lg:translate-x-0 dark:bg-gray-900 dark:border-gray-700"
    x-bind:class="{ 'lg:-translate-x-full!': menuEin, 'translate-x-0!': mobilOffen }" aria-label="Seitenleiste">
    <div class="flex justify-between flex-col h-full px-3 pb-4 overflow-y-auto bg-white dark:bg-gray-900">
        <ul class="space-y-1">
            <x-aside.toplink label="Dashboard" icon="home" :href="route('dashboard')" :aktiv="request()->routeIs('dashboard')" />
            <x-aside.toplink label="App-Katalog" icon="apps" :href="route('apps.index')" :aktiv="request()->routeIs('apps.*')" />
            <x-aside.toplink label="Deployments" icon="rocket" :href="route('deployments.index')" :aktiv="request()->routeIs('deployments.*')" />
            <x-aside.toplink label="Updates" icon="refresh" :href="route('updates.index')" :aktiv="request()->routeIs('updates.*')" />

            <li class="pt-3 mt-2 border-t border-gray-200 dark:border-gray-700">
                <span class="px-2 text-[10.5px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Verwaltung</span>
            </li>
            <x-aside.toplink label="Proxmox-Verbindungen" icon="server" :href="route('connections.index')" :aktiv="request()->routeIs('connections.*')" />
            <x-aside.toplink label="Einstellungen" icon="cog" :href="route('settings')" :aktiv="request()->routeIs('settings', 'profiles.*')" />
        </ul>

        <a href="{{ route('apps.index') }}"
            class="mt-10 pt-3 text-center text-xs text-gray-400 border-t border-gray-200 hover:text-cerulean-600 dark:border-gray-700 dark:text-gray-500">
            ProxApps v{{ config('proxapps.version', '1.0.0') }}
        </a>
    </div>
</aside>
