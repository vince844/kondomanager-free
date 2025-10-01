<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    {{-- <meta name="csrf-token" content="{{ csrf_token() }}"> --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'KondoManager') }}</title>

    {{-- <link rel="stylesheet" href="{{ asset('vendor/installer/css/installer.css') }}"> --}}
    @vite(['resources/css/installer.css'])
    {{-- @livewireStyles --}}
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center starting:opacity-0 opacity-100 transition-opacity duration-700">
        <main class="w-full max-w-screen-lg min-w-5xl mx-auto">
            <h1 class="text-gray-400 m-2">KondoManager Installer Wizard</h1>
            @isset($slot)
                {{ $slot }}
            @endisset
        </main>
    </div>
    {{-- @livewireScripts --}}
</body>

</html>
