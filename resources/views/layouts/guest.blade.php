@props([
    'title' => '',
])
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ProxApps' : 'ProxApps' }}</title>
    <link rel="icon" href="{{ asset('img/logo.svg') }}" type="image/svg+xml">

    <script>
        // Set before rendering to avoid FOUC
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="antialiased font-DINPro text-gray-900 dark:text-gray-100">

    {{-- Without a navigation bar there would be no way into dark mode here. --}}
    <div class="relative">
        <div class="absolute top-3 right-3 z-20 flex items-center gap-2">
            <x-theme-toggle class="text-gray-500 dark:text-gray-400 hover:bg-white/70 dark:hover:bg-gray-800" />
        </div>

        {{ $slot }}
    </div>

    @livewireScripts
</body>

</html>
