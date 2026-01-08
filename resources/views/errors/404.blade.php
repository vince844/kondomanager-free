<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ __('errors.404_title') }}</title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50">

@php
  $user = auth()->user();
@endphp

<div class="flex min-h-screen flex-col items-center px-4 py-12 sm:px-6 lg:px-8">
  <div class="mx-auto max-w-md text-center mt-20">
    <!-- Icona Km -->
    <div class="mx-auto mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-black text-white shadow-lg">
      <span class="text-lg font-bold">Km</span>
    </div>

    <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl text-foreground">
      {{ __('errors.404_heading') }}
    </h1>

    <p class="mt-4 text-muted-foreground">
      {{ __('errors.404_message') }}
    </p>

    <div class="mt-6">
      @if ($user)
        <a href="{{ $user->hasPermissionTo('Accesso pannello amministratore')
            ? route('admin.dashboard')
            : route('user.dashboard') }}"
           class="inline-flex items-center rounded-md bg-black px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2">
          {{ __('errors.back_to_dashboard') }}
        </a>
      @else
        <a href="{{ route('login') }}"
           class="inline-flex items-center rounded-md bg-black px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2">
          {{ __('errors.back_to_login') }}
        </a>
      @endif
    </div>

  </div>
</div>

</body>
</html>