{{--
    Troppi tentativi (429): la risposta dei limiti di rotta, per le prove della password attuale, i moduli per
    ospiti e il login. Chi la riceve legge che cosa è successo e quanto aspettare, nella lingua
    dell'installazione: il tempo è quello del limite che si è fermato (`Retry-After`, in secondi).
    Le chiamate JSON non arrivano qui: ricevono il JSON del framework, e la pagina che le fa scrive il suo
    messaggio.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ __('errors.429_title') }}</title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50">

    @php
      $user = auth()->user();
      $secondi = isset($exception) && method_exists($exception, 'getHeaders')
          ? (int) ($exception->getHeaders()['Retry-After'] ?? 0)
          : 0;
      $minuti = $secondi > 0 ? (int) ceil($secondi / 60) : 0;
    @endphp

  <div class="flex min-h-screen flex-col items-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-md text-center mt-20">
       <!-- Icona Km -->
      <div class="mx-auto mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-black text-white shadow-lg">
        <span class="text-lg font-bold">Km</span>
      </div>

      <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl text-foreground">
        {{ __('errors.429_heading') }}
      </h1>

      <p class="mt-4 text-muted-foreground">
        {{ __('errors.429_message') }}
        @if ($minuti > 0)
          {{ trans_choice('errors.429_retry_minutes', $minuti) }}
        @else
          {{ __('errors.429_retry_later') }}
        @endif
      </p>

      <div class="mt-6">
        {{-- `checkPermissionTo` e non `hasPermissionTo`: una pagina d'errore non deve fallire a sua volta. --}}
        @if ($user)
          <a href="{{ $user->checkPermissionTo(\App\Enums\Permission::ACCESS_ADMIN_PANEL->value)
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
