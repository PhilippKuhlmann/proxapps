<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · ProxApps</title>
    <link rel="icon" href="{{ asset('img/logo.svg') }}" type="image/svg+xml">
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>

<body class="antialiased font-DINPro bg-gray-100 dark:bg-gray-900">
    <div class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-md rounded-lg border border-chathams-blue-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between gap-4 border-b border-chathams-blue-100 px-6 py-3 font-mono text-[11px] uppercase tracking-[0.15em] text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <span>Fehler</span>
                <span>@yield('code')</span>
            </div>
            <div class="px-6 py-8">
                <div class="mb-6 flex items-center gap-3">
                    <img src="{{ asset('img/logo.svg') }}" alt="" width="40" height="40" class="h-10 w-10" />
                    <span class="font-CoconPro text-lg uppercase tracking-[0.13em] text-chathams-blue-800 dark:text-gray-100">ProxApps</span>
                </div>
                <div class="text-2xl font-CoconPro text-gray-900 dark:text-gray-100">@yield('title')</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{-- Only messages of deliberate aborts (4xx); server errors could leak internals. --}}
                    @php($code = isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500)
                    {{ $code < 500 && $exception->getMessage() && ! str_starts_with($exception->getMessage(), 'No query results') ? $exception->getMessage() : '' }}
                    @hasSection('message')
                        <span class="block text-gray-500 dark:text-gray-400">@yield('message')</span>
                    @endif
                </p>
                <a href="{{ url('/') }}"
                    class="mt-6 inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-DINPro-bold shadow-xs transition-colors bg-cerulean-600 text-white hover:bg-cerulean-700 focus:outline-hidden focus:ring-2 focus:ring-cerulean-500 focus:ring-offset-2">
                    Zum Dashboard
                </a>
            </div>
        </div>
    </div>
</body>

</html>
