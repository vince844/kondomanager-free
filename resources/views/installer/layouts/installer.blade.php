<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Kondomanager') }} — {{ __('installer.wizard.title') }}</title>
    @vite(['resources/css/installer.css'])
    @livewireStyles
</head>

<body class="bg-gray-950">
    <div class="min-h-screen flex items-center justify-center px-4 py-6 starting:opacity-0 opacity-100 transition-opacity duration-700">
        <main class="w-full">
            @isset($slot)
                {{ $slot }}
            @endisset
        </main>
    </div>
    @livewireScripts
</body>

</html>
