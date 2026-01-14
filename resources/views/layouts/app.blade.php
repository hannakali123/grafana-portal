<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel Grafana') }}</title>


    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />


    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased">

<div class="flex flex-col min-h-screen bg-gray-100">


    @include('layouts.navigation')


    @isset($header)
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                {{ $header }}
            </div>
        </header>
    @endisset


    @php($isPortal = request()->routeIs('portal'))
    <main @class([
        'flex flex-col flex-1 min-h-0 relative',  {{--   ← relative   --}}
        $isPortal ? 'overflow-hidden' : 'py-12',
    ])>

        @if ($isPortal)

            {{ $slot }}
        @else

            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        @endif
    </main>

</div>
</body>
</html>
