{{--
  Pagina 503 mostrata durante un ripristino a chiunque NON stia guidando il
  ripristino. DEVE essere autosufficiente: durante l'import il database
  (sessioni, cache, impostazioni) è a metà sovrascrittura, quindi niente
  auth(), niente query, niente Inertia. Solo HTML + JS vanilla.

  Due volti, decisi da $stuck (calcolato dal middleware leggendo lo stato su
  file): ripristino in corso (spinner + meta-refresh) oppure ripristino
  fallito/in stallo (pagina di recupero con log copiabile e pulsanti).

  Il log della pagina non ha la versione né il messaggio d'errore: la pagina è
  per chiunque. Arrivano con la risposta di «Riprendi» (password giusta) e degli
  step (token), e la pagina li scrive nel log se il ripristino si ferma di nuovo
  (Coda 343).

  Fermo nel backup di sicurezza il ripristino non ha toccato il database: la
  frase sotto il titolo e la nota sotto «Sblocca» lo dicono, al posto di «il
  database potrebbe essere incompleto». Se dopo «Riprendi» il ripristino si
  ferma di nuovo, lo script le riscrive con la fase nuova; se la risposta di
  «Riprendi» non arriva, o è un errore del server, tornano quelle generiche.
--}}
@php
    $locale = $locale ?? 'it';
    // La durata del token della finestra dei backup (`backup.stale_after_hours`): la nomina la frase della password
    // che non si può verificare, come la risposta del recupero.
    $ore = (int) ($oreDelToken ?? 2);
    // Fermo (o in stallo) nel backup di sicurezza: il database non è stato toccato.
    $fermoPrimaDelDatabase = ($failedPhase ?? null) === 'safety_backup';

    $T = [
        'it' => [
            'running_title' => 'Ripristino in corso',
            'running_body' => "È in corso il ripristino di un backup. L'applicazione è temporaneamente non disponibile e tornerà accessibile automaticamente al termine.",
            'running_hint' => 'Questa pagina si aggiorna da sola ogni 30 secondi. Non chiudere né ricaricare manualmente il ripristino.',
            'failed_title' => 'Ripristino non riuscito',
            'failed_body' => "Il ripristino del backup si è interrotto prima di completarsi. Il database potrebbe trovarsi in uno stato incompleto: puoi provare a completarlo oppure sbloccare l'applicazione.",
            'failed_body_untouched' => "Il ripristino del backup si è fermato durante il backup di sicurezza, prima di toccare il database: puoi provare a riprenderlo oppure sbloccare l'applicazione.",
            'details' => 'Dettagli tecnici',
            'copy' => 'Copia log',
            'copied' => 'Copiato negli appunti',
            'log_intro' => "Se il problema si ripete, copia questo log e inviacelo per l'assistenza.",
            'password_label' => 'Password del tuo account',
            'password_ph' => 'Conferma con la tua password',
            'resume' => 'Riprendi il ripristino',
            'abort' => "Sblocca l'applicazione",
            'abort_note' => 'Il database potrebbe restare incompleto. Sblocca solo se sai come sistemarlo (es. un nuovo ripristino).',
            'abort_note_untouched' => "Il ripristino non ha toccato il database: sbloccando, il gestionale riparte con i dati che aveva.",
            'working' => 'Attendere…',
            'need_password' => 'Inserisci la password del tuo account.',
            'auth_failed' => 'Password non corretta.',
            'auth_unavailable' => "Per questo ripristino la password non si può verificare: copia il log e inviacelo per l'assistenza. Se la finestra dei backup da cui è partito è ancora aperta, e dall'avvio ".($ore === 1 ? "non è passata più di un'ora" : "non sono passate più di {$ore} ore").", puoi riprenderlo o sbloccare l'applicazione da lì.",
            'generic_error' => 'Operazione non riuscita. Riprova.',
            'too_many' => 'Troppi tentativi: attendi un minuto e riprova.',
            'resume_progress' => 'Ripristino in corso…',
            'failed_again' => "Il ripristino si è fermato di nuovo. Nei dettagli tecnici ora trovi anche la versione e il messaggio d'errore.",
        ],
        'en' => [
            'running_title' => 'Restore in progress',
            'running_body' => 'A backup is being restored. The application is temporarily unavailable and will come back automatically when the restore completes.',
            'running_hint' => 'This page refreshes every 30 seconds. Do not close or reload the restore manually.',
            'failed_title' => 'Restore failed',
            'failed_body' => 'The backup restore stopped before completing. The database may be in an incomplete state: you can try to finish it or unlock the application.',
            'failed_body_untouched' => 'The backup restore stopped during the safety backup, before touching the database: you can try to resume it or unlock the application.',
            'details' => 'Technical details',
            'copy' => 'Copy log',
            'copied' => 'Copied to clipboard',
            'log_intro' => 'If the problem persists, copy this log and send it to us for support.',
            'password_label' => 'Your account password',
            'password_ph' => 'Confirm with your password',
            'resume' => 'Resume the restore',
            'abort' => 'Unlock the application',
            'abort_note' => 'The database may stay incomplete. Only unlock if you know how to fix it (e.g. a new restore).',
            'abort_note_untouched' => 'The restore did not touch the database: unlocking brings the application back with the data it had.',
            'working' => 'Please wait…',
            'need_password' => 'Enter your account password.',
            'auth_failed' => 'Wrong password.',
            'auth_unavailable' => 'The password cannot be checked for this restore: copy the log and send it to us for support. If the backups window it was started from is still open, and no more than '.($ore === 1 ? 'an hour has' : "{$ore} hours have").' passed since it started, you can resume it or unlock the application from there.',
            'generic_error' => 'The operation failed. Please try again.',
            'too_many' => 'Too many attempts: wait a minute and try again.',
            'resume_progress' => 'Restore in progress…',
            'failed_again' => 'The restore stopped again. The technical details now also show the version and the error message.',
        ],
        'es' => [
            'running_title' => 'Restauración en curso',
            'running_body' => 'Se está restaurando una copia de seguridad. La aplicación no está disponible temporalmente y volverá automáticamente cuando termine la restauración.',
            'running_hint' => 'Esta página se actualiza sola cada 30 segundos. No cierres ni recargues la restauración manualmente.',
            'failed_title' => 'La restauración ha fallado',
            'failed_body' => 'La restauración de la copia se detuvo antes de completarse. La base de datos podría quedar incompleta: puedes intentar terminarla o desbloquear la aplicación.',
            'failed_body_untouched' => 'La restauración de la copia se detuvo durante la copia de seguridad previa, antes de modificar la base de datos: puedes intentar reanudarla o desbloquear la aplicación.',
            'details' => 'Detalles técnicos',
            'copy' => 'Copiar registro',
            'copied' => 'Copiado al portapapeles',
            'log_intro' => 'Si el problema continúa, copia este registro y envíanoslo para soporte.',
            'password_label' => 'Contraseña de tu cuenta',
            'password_ph' => 'Confirma con tu contraseña',
            'resume' => 'Reanudar la restauración',
            'abort' => 'Desbloquear la aplicación',
            'abort_note' => 'La base de datos podría quedar incompleta. Desbloquea solo si sabes cómo arreglarlo (p. ej. una nueva restauración).',
            'abort_note_untouched' => 'La restauración no modificó la base de datos: al desbloquear, la aplicación vuelve con los datos que tenía.',
            'working' => 'Espera…',
            'need_password' => 'Introduce la contraseña de tu cuenta.',
            'auth_failed' => 'Contraseña incorrecta.',
            'auth_unavailable' => 'No se puede verificar la contraseña para esta restauración: copia el registro y envíanoslo para soporte. Si la ventana de copias de seguridad donde se inició sigue abierta, y no '.($ore === 1 ? 'ha pasado más de una hora' : "han pasado más de {$ore} horas").' desde el inicio, puedes reanudarla o desbloquear la aplicación desde allí.',
            'generic_error' => 'La operación ha fallado. Inténtalo de nuevo.',
            'too_many' => 'Demasiados intentos: espera un minuto y vuelve a intentarlo.',
            'resume_progress' => 'Restauración en curso…',
            'failed_again' => 'La restauración se ha detenido de nuevo. Los detalles técnicos ahora también muestran la versión y el mensaje de error.',
        ],
        'pt' => [
            'running_title' => 'Restauro em curso',
            'running_body' => 'Está a ser restaurado um backup. A aplicação está temporariamente indisponível e voltará automaticamente quando o restauro terminar.',
            'running_hint' => 'Esta página atualiza-se sozinha a cada 30 segundos. Não feche nem recarregue o restauro manualmente.',
            'failed_title' => 'O restauro falhou',
            'failed_body' => 'O restauro do backup parou antes de concluir. A base de dados pode ficar incompleta: pode tentar terminá-lo ou desbloquear a aplicação.',
            'failed_body_untouched' => 'O restauro do backup parou durante a cópia de segurança prévia, antes de alterar a base de dados: pode tentar retomá-lo ou desbloquear a aplicação.',
            'details' => 'Detalhes técnicos',
            'copy' => 'Copiar registo',
            'copied' => 'Copiado para a área de transferência',
            'log_intro' => 'Se o problema persistir, copie este registo e envie-nos para suporte.',
            'password_label' => 'Palavra-passe da sua conta',
            'password_ph' => 'Confirme com a sua palavra-passe',
            'resume' => 'Retomar o restauro',
            'abort' => 'Desbloquear a aplicação',
            'abort_note' => 'A base de dados pode ficar incompleta. Desbloqueie apenas se souber como resolver (ex. um novo restauro).',
            'abort_note_untouched' => 'O restauro não alterou a base de dados: ao desbloquear, a aplicação volta com os dados que tinha.',
            'working' => 'Aguarde…',
            'need_password' => 'Introduza a palavra-passe da sua conta.',
            'auth_failed' => 'Palavra-passe incorreta.',
            'auth_unavailable' => 'Não é possível verificar a palavra-passe para este restauro: copie o registo e envie-nos para suporte. Se a janela das cópias de segurança onde foi iniciado ainda estiver aberta, e não '.($ore === 1 ? 'tiver passado mais de uma hora' : "tiverem passado mais de {$ore} horas").' desde o início, pode retomá-lo ou desbloquear a aplicação a partir daí.',
            'generic_error' => 'A operação falhou. Tente novamente.',
            'too_many' => 'Demasiadas tentativas: aguarde um minuto e tente novamente.',
            'resume_progress' => 'Restauro em curso…',
            'failed_again' => 'O restauro parou novamente. Os detalhes técnicos mostram agora também a versão e a mensagem de erro.',
        ],
    ];

    $t = $T[$locale] ?? $T['it'];

    $failedAtStr = ! empty($failedAt) ? date('Y-m-d H:i:s', (int) $failedAt) : '—';
    $log = "KondoManager restore log\n"
        ."uuid: ".($restoreUuid ?? '—')."\n"
        ."failed_phase: ".($failedPhase ?? '—')."\n"
        ."failed_at: ".$failedAtStr;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @unless($stuck)
  <meta http-equiv="refresh" content="30">
  @endunless
  <title>{{ $stuck ? $t['failed_title'] : $t['running_title'] }} — KondoManager</title>
  <style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      background: #030712; color: #374151; padding: 1rem;
    }
    .card {
      background: #fff; width: 100%; max-width: 480px; padding: 2.5rem; border-radius: 16px;
      box-shadow: 0 20px 25px -5px rgba(0,0,0,.3); border: 1px solid #e5e7eb; text-align: center;
    }
    .logo {
      width: 3rem; height: 3rem; margin: 0 auto 1.25rem; border-radius: 12px; background: #030712;
      color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem;
    }
    h1 { font-size: 1.35rem; font-weight: 700; color: #030712; margin: 0 0 .5rem; }
    h1.failed { color: #b91c1c; }
    p { color: #6b7280; font-size: .95rem; line-height: 1.6; margin: .25rem 0; }
    .spinner {
      width: 2rem; height: 2rem; margin: 1.5rem auto .5rem; border: 3px solid #e5e7eb;
      border-top-color: #030712; border-radius: 50%; animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .icon-fail {
      width: 3rem; height: 3rem; margin: .25rem auto 1rem; border-radius: 50%;
      background: #fee2e2; color: #b91c1c; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
    }
    .hint { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f3f4f6; font-size: .82rem; color: #9ca3af; }
    details { margin-top: 1.25rem; text-align: left; }
    summary { cursor: pointer; font-size: .82rem; color: #6b7280; user-select: none; }
    .logbox {
      margin-top: .6rem; position: relative; background: #f9fafb; border: 1px solid #e5e7eb;
      border-radius: 10px; padding: .75rem .85rem;
    }
    .logbox pre {
      margin: 0; white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: .72rem; color: #374151; line-height: 1.5; max-height: 9rem; overflow: auto;
    }
    .log-intro { font-size: .78rem; color: #9ca3af; margin: .35rem 0 0; text-align: left; }
    .copy-btn {
      margin-top: .55rem; font-size: .75rem; font-weight: 600; color: #374151; background: #fff;
      border: 1px solid #d1d5db; border-radius: 8px; padding: .35rem .7rem; cursor: pointer;
    }
    .copy-btn:hover { background: #f3f4f6; }
    .field { margin-top: 1.35rem; text-align: left; }
    .field label { display: block; font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
    .field input {
      width: 100%; padding: .6rem .7rem; border: 1px solid #d1d5db; border-radius: 9px; font-size: .9rem;
    }
    .field input:focus { outline: 2px solid #6366f1; outline-offset: 0; border-color: transparent; }
    .actions { margin-top: 1rem; display: flex; flex-direction: column; gap: .6rem; }
    .btn { border: 0; border-radius: 10px; padding: .7rem 1rem; font-size: .9rem; font-weight: 600; cursor: pointer; }
    .btn:disabled { opacity: .55; cursor: default; }
    .btn-primary { background: #0f172a; color: #fff; }
    .btn-primary:hover:not(:disabled) { background: #1e293b; }
    .btn-ghost { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
    .btn-ghost:hover:not(:disabled) { background: #fef2f2; }
    .abort-note { font-size: .72rem; color: #9ca3af; margin: .1rem 0 0; text-align: left; }
    .msg { margin-top: .8rem; font-size: .82rem; min-height: 1rem; }
    .msg.err { color: #b91c1c; }
    .msg.ok { color: #047857; }
  </style>
</head>
<body>
  <div class="card">
    @if($stuck)
      <div class="icon-fail" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="8" x2="12" y2="13"/><circle cx="12" cy="17" r="0.6" fill="currentColor" stroke="none"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
      </div>
      <h1 class="failed">{{ $t['failed_title'] }}</h1>
      <p id="failedBody">{{ $fermoPrimaDelDatabase ? $t['failed_body_untouched'] : $t['failed_body'] }}</p>

      <details>
        <summary>{{ $t['details'] }}</summary>
        <div class="logbox">
          <pre id="restoreLog">{{ $log }}</pre>
        </div>
        <p class="log-intro">{{ $t['log_intro'] }}</p>
        <button type="button" class="copy-btn" id="copyBtn">{{ $t['copy'] }}</button>
      </details>

      <div class="field">
        <label for="accpwd">{{ $t['password_label'] }}</label>
        <input type="password" id="accpwd" autocomplete="current-password" placeholder="{{ $t['password_ph'] }}">
      </div>

      <div class="actions">
        <button type="button" class="btn btn-primary" id="resumeBtn">{{ $t['resume'] }}</button>
        <button type="button" class="btn btn-ghost" id="abortBtn">{{ $t['abort'] }}</button>
        <p class="abort-note" id="abortNote">{{ $fermoPrimaDelDatabase ? $t['abort_note_untouched'] : $t['abort_note'] }}</p>
      </div>

      <div class="msg" id="msg" role="status"></div>
    @else
      <div class="spinner"></div>
      <h1>{{ $t['running_title'] }}</h1>
      <p>{{ $t['running_body'] }}</p>
      <p class="hint">{{ $t['running_hint'] }}</p>
    @endif
  </div>

  @if($stuck)
  <script>
    (function () {
      var T = @json($t);
      var URL_RESUME = @json(url('ripristino/riprendi'));
      var URL_ABORT  = @json(url('ripristino/annulla'));
      var URL_STEP   = @json(url('ripristino/step'));
      var URL_RESULT = @json(url('ripristino/esito'));

      var pwd = document.getElementById('accpwd');
      var msg = document.getElementById('msg');
      var resumeBtn = document.getElementById('resumeBtn');
      var abortBtn = document.getElementById('abortBtn');
      var copyBtn = document.getElementById('copyBtn');
      var logEl = document.getElementById('restoreLog');
      var bodyEl = document.getElementById('failedBody');
      var noteEl = document.getElementById('abortNote');
      var token = null;

      function setMsg(text, kind) { msg.textContent = text || ''; msg.className = 'msg' + (kind ? ' ' + kind : ''); }
      function busy(on) { resumeBtn.disabled = on; abortBtn.disabled = on; }

      // Il log completo, dalla risposta di chi ha il token o la password: versione ed errore compresi.
      function scriviLog(r) {
        if (!r || !logEl) return;
        var at = r.failed_at ? new Date(r.failed_at * 1000).toISOString().replace('T', ' ').slice(0, 19) : '—';
        logEl.textContent = 'KondoManager restore log\n'
          + 'uuid: ' + (r.uuid || '—') + '\n'
          + 'version: ' + (r.app_version || '—') + '\n'
          + 'failed_phase: ' + (r.failed_phase || r.phase || '—') + '\n'
          + 'failed_at: ' + at + '\n'
          + 'error: ' + (r.error || '—');
        var dettagli = logEl.closest('details');
        if (dettagli) dettagli.open = true;
      }

      // La frase sotto il titolo e la nota sotto «Sblocca» seguono lo stato che il server ha appena dato: «il database
      // non è stato toccato» solo per un ripristino fermo nel backup di sicurezza; in corso, o fermo più avanti, le
      // frasi generiche.
      function scriviTesti(r) {
        var fermoPrima = !!r && r.phase === 'failed' && r.failed_phase === 'safety_backup';
        bodyEl.textContent = fermoPrima ? T.failed_body_untouched : T.failed_body;
        noteEl.textContent = fermoPrima ? T.abort_note_untouched : T.abort_note;
      }

      copyBtn && copyBtn.addEventListener('click', function () {
        var txt = document.getElementById('restoreLog').textContent;
        navigator.clipboard.writeText(txt).then(function () {
          copyBtn.textContent = T.copied;
          setTimeout(function () { copyBtn.textContent = T.copy; }, 2000);
        }).catch(function () {});
      });

      function post(url, body, headers) {
        return fetch(url, {
          method: 'POST',
          headers: Object.assign({ 'Accept': 'application/json', 'Content-Type': 'application/json' }, headers || {}),
          body: JSON.stringify(body || {})
        });
      }

      // Guida gli step successivi col token restituito da "riprendi", finché
      // il ripristino completa o fallisce di nuovo.
      function drive() {
        post(URL_STEP, {}, { 'X-Restore-Token': token }).then(function (r) {
          return r.ok ? r.json() : Promise.reject(r);
        }).then(function (data) {
          var phase = data && data.restore && data.restore.phase;
          if (phase === 'completed') { window.location.assign(URL_RESULT); return; }
          // Fermo di nuovo: la pagina resta, con il log completo, invece di ricaricarsi senza.
          if (phase === 'failed') { scriviLog(data.restore); scriviTesti(data.restore); busy(false); setMsg(T.failed_again, 'err'); return; }
          if (!phase) { window.location.reload(); return; }
          setMsg(T.resume_progress, 'ok');
          setTimeout(drive, 1200);
        }).catch(function () { window.location.reload(); });
      }

      resumeBtn.addEventListener('click', function () {
        if (!pwd.value) { setMsg(T.need_password, 'err'); pwd.focus(); return; }
        busy(true); setMsg(T.working, null);
        post(URL_RESUME, { account_password: pwd.value }).then(function (r) {
          if (r.status === 429) { busy(false); setMsg(T.too_many, 'err'); return null; }
          // 409: per questo ripristino la password non si può verificare (resta la finestra dei backup, con il token).
          if (r.status === 409) { busy(false); setMsg(T.auth_unavailable, 'err'); return null; }
          if (r.status === 422 || r.status === 403) { busy(false); setMsg(T.auth_failed, 'err'); return null; }
          // Una sola richiesta di «Riprendi» rifà il backup di sicurezza e può arrivare a togliere il database: con un
          // errore del server, o senza una risposta leggibile (sotto), la pagina non sa fin dove è arrivata, e torna alle
          // frasi generiche. Un 4xx viene prima di ogni azione: le frasi restano.
          if (!r.ok) { busy(false); if (r.status >= 500) scriviTesti(null); setMsg(T.generic_error, 'err'); return null; }
          return r.json();
        }).then(function (data) {
          if (!data) return;
          token = data.token;
          scriviTesti(data.restore);
          setMsg(T.resume_progress, 'ok');
          drive();
        }).catch(function () { busy(false); scriviTesti(null); setMsg(T.generic_error, 'err'); });
      });

      abortBtn.addEventListener('click', function () {
        if (!pwd.value) { setMsg(T.need_password, 'err'); pwd.focus(); return; }
        busy(true); setMsg(T.working, null);
        post(URL_ABORT, { account_password: pwd.value }).then(function (r) {
          if (r.status === 429) { busy(false); setMsg(T.too_many, 'err'); return; }
          if (r.status === 409) { busy(false); setMsg(T.auth_unavailable, 'err'); return; }
          if (r.status === 422 || r.status === 403) { busy(false); setMsg(T.auth_failed, 'err'); return; }
          if (!r.ok) { busy(false); setMsg(T.generic_error, 'err'); return; }
          window.location.assign('/');
        }).catch(function () { busy(false); setMsg(T.generic_error, 'err'); });
      });
    })();
  </script>
  @endif
</body>
</html>
