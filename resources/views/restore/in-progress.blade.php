{{--
  Pagina 503 mostrata durante un ripristino in corso a chiunque NON stia
  guidando il ripristino. DEVE essere autosufficiente: durante l'import il
  database (sessioni, cache, impostazioni) è a metà sovrascrittura, quindi
  niente auth(), niente query, niente Inertia. Solo HTML + un meta-refresh
  che ricarica finché il ripristino non finisce e l'app torna raggiungibile.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="30">
  <title>Ripristino in corso — KondoManager</title>
  <style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      background: #030712; color: #374151; padding: 1rem;
    }
    .card {
      background: #fff; width: 100%; max-width: 460px; padding: 2.5rem; border-radius: 16px;
      box-shadow: 0 20px 25px -5px rgba(0,0,0,.3); border: 1px solid #e5e7eb; text-align: center;
    }
    .logo {
      width: 3rem; height: 3rem; margin: 0 auto 1.25rem; border-radius: 12px; background: #030712;
      color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem;
    }
    h1 { font-size: 1.35rem; font-weight: 700; color: #030712; margin: 0 0 .5rem; }
    p { color: #6b7280; font-size: .95rem; line-height: 1.6; margin: .25rem 0; }
    .spinner {
      width: 2rem; height: 2rem; margin: 1.5rem auto .5rem; border: 3px solid #e5e7eb;
      border-top-color: #030712; border-radius: 50%; animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hint { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f3f4f6; font-size: .82rem; color: #9ca3af; }
    .en { color: #9ca3af; font-size: .85rem; margin-top: .75rem; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">Km</div>
    <div class="spinner"></div>
    <h1>Ripristino in corso</h1>
    <p>È in corso il ripristino di un backup. L'applicazione è temporaneamente
       non disponibile e tornerà accessibile automaticamente al termine.</p>
    <p class="en">A backup is being restored. The application is temporarily
       unavailable and will come back automatically when the restore completes.</p>
    <p class="hint">Questa pagina si aggiorna da sola ogni 30 secondi. Non chiudere né ricaricare manualmente il ripristino.</p>
  </div>
</body>
</html>
