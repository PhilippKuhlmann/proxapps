{{--
    The shell of every page seen without signing in (login, first run): network plan
    background, card, drawing-style title block, wordmark.
    $kopf names the sheet in the title block; the version stands on the right.
--}}
@props(['kopf'])

<div class="relative grid min-h-screen items-center gap-10 overflow-hidden px-5 py-16 sm:px-10
            lg:grid-cols-[26rem_1fr] lg:px-12 xl:gap-12 xl:px-16
            bg-linear-to-b from-chathams-blue-50 to-chathams-blue-100
            dark:from-gray-900 dark:to-cerulean-950">

    {{-- graph paper, very subdued --}}
    <div class="netzplan-raster pointer-events-none absolute inset-0 opacity-60
                text-chathams-blue-100 dark:text-cerulean-950"></div>

    @include('auth._netzplan', ['hintergrund' => true])

    <div class="relative z-10 mx-auto w-full max-w-md rounded-lg border border-chathams-blue-200 bg-white shadow-xl
                lg:mx-0 dark:border-gray-700 dark:bg-gray-800">

        <div class="flex items-center justify-between gap-4 border-b border-chathams-blue-100 px-6 py-3
                    font-mono text-[11px] uppercase tracking-[0.15em] text-gray-500
                    dark:border-gray-700 dark:text-gray-400">
            <span>{{ $kopf }}</span>
            <span class="normal-case">v{{ config('proxapps.version', '1.0.0') }}</span>
        </div>

        <div class="px-6 py-8">
            <h1 class="mb-8 flex items-center gap-3">
                <img src="{{ asset('img/logo.svg') }}" alt="" width="44" height="44" class="h-11 w-11 shrink-0" />
                <span class="font-CoconPro text-lg uppercase tracking-[0.13em] text-chathams-blue-800 sm:text-xl dark:text-gray-100">
                    ProxApps
                </span>
            </h1>

            @if (session('status'))
                <div class="mb-6 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800
                            dark:border-green-800 dark:bg-green-900/25 dark:text-green-300" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </div>

        @isset($fuss)
            <div class="border-t border-chathams-blue-100 px-6 py-4 text-sm leading-relaxed text-gray-500
                        dark:border-gray-700 dark:text-gray-400">
                {{ $fuss }}
            </div>
        @endisset
    </div>

    @include('auth._netzplan')
</div>
