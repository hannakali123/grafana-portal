<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel Grafana') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased">
<!-- GANZE Seite als Flex‐Spalte ► Header oben, Main füllt Rest -->
<div class="flex flex-col min-h-screen bg-gray-100">

    {{-- Navigation --}}
    @include('layouts.navigation')

    {{-- optionale Seiten-Überschrift --}}
    @isset($header)
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                {{ $header }}
            </div>
        </header>
    @endisset

    {{-- Hauptbereich ------------------------------------------------------- --}}
    @php($isPortal = request()->routeIs('portal'))
    <main @class([
        'flex flex-col flex-1 min-h-0 relative',  {{--   ← relative   --}}
        $isPortal ? 'overflow-hidden' : 'py-12',
    ])>

        @if ($isPortal)
            {{-- Portal-Seite bekommt direkt den Slot (iframe) ohne Wrapper --}}
            {{ $slot }}
        @else
            {{-- alle anderen Seiten behalten den zentrierten Container --}}
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        @endif
    </main>

</div>
</body>
</html>
