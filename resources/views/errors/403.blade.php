<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>403 - Accesso negato</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-background">

  <div class="flex min-h-[100dvh] flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-md text-center">
      <div class="mx-auto h-12 w-12 text-primary"></div>
      <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl text-foreground">403 - Accesso negato</h1>
      <p class="mt-4 text-muted-foreground">
        Oops,  @isset($exception) {{ $exception->getMessage() }} @endisset
      </p>

      <div class="mt-6">
        <a href="{{ route(auth()->user()->hasRole('amministratore') ? 'admin.dashboard' : 'user.dashboard') }}"
          class="inline-flex items-center rounded-md bg-black px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2">
         Torna alla Dashboard
       </a>
      </div>
    </div>
  </div>

</body>
</html>
