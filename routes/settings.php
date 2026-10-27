<?php

use App\Enums\Permission;
use App\Http\Controllers\Impostazioni\BackupSettingsController;
use App\Http\Controllers\Impostazioni\CronSettingsController;
use App\Http\Controllers\Impostazioni\ImpostazioniController;
use App\Http\Controllers\Impostazioni\ImpostazioniGeneraliController;
use App\Http\Controllers\Impostazioni\ImpostazioniStampeController;
use App\Http\Controllers\Impostazioni\InstallazioneController;
use App\Http\Controllers\Impostazioni\LogsController;
use App\Http\Controllers\Impostazioni\MailSettingsController;
use App\Http\Controllers\Impostazioni\RestoreController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthController;
use App\Http\Middleware\CheckForPendingUpdates;
use App\Http\Middleware\CheckSuspendedUser;
use App\Http\Middleware\EnsureRestoreToken;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {

    Route::get('impostazioni', ImpostazioniController::class)
        ->name('impostazioni');

    Route::get('impostazioni/generali', [ImpostazioniGeneraliController::class, '__invoke'])
        ->name('impostazioni.generali');

    Route::post('impostazioni/generali', [ImpostazioniGeneraliController::class, 'store'])
        ->name('impostazioni.generali.store');

    Route::get('impostazioni/stampe', [ImpostazioniStampeController::class, 'index'])
        ->name('impostazioni.stampe');

    // «Questa installazione»: limiti, spazio, funzioni accese (1.11.0-beta.33).
    Route::get('impostazioni/installazione', InstallazioneController::class)
        ->name('impostazioni.installazione');

    Route::post('impostazioni/stampe', [ImpostazioniStampeController::class, 'store'])
        ->name('impostazioni.stampe.store');

    // Cron, posta e registro delle email: fino alla 1.11.0-beta.38 avevano solo `auth`, e i tre
    // controller nessun controllo. Qualunque utente con il login (condòmino, fornitore,
    // collaboratore) leggeva destinatari e oggetti di tutte le email e il token del cron, e con
    // `mail/test` si faceva consegnare la password SMTP salvata su un server suo; con `mail` deviava
    // tutta la posta, reset password dell'amministratore compreso. Giro di sicurezza della
    // 1.11.0-beta.39. Il permesso è lo stesso che apre l'hub delle impostazioni.
    Route::middleware('permission:'.Permission::MANAGE_GENERAL_SETTINGS->value)->group(function () {

        Route::get('impostazioni/cron', [CronSettingsController::class, 'edit'])
            ->name('impostazioni.cron');

        Route::post('impostazioni/cron', [CronSettingsController::class, 'update'])
            ->name('impostazioni.cron.update');

        Route::post('impostazioni/cron/regenerate', [CronSettingsController::class, 'regenerateToken'])
            ->name('impostazioni.cron.regenerate');

        // MAIL SETTINGS
        Route::get('impostazioni/mail', [MailSettingsController::class, 'edit'])
            ->name('impostazioni.mail');

        // La posta in uscita porta anche i link di «password dimenticata»: salvarla e provarla chiede
        // la password ridigitata da poco (Coda 261, decisione 127.7).
        Route::post('impostazioni/mail', [MailSettingsController::class, 'update'])
            ->middleware('password.recente:impostazioni.mail')
            ->name('admin.settings.mail.update');

        // Ogni prova apre una connessione verso il server e la porta scritti nel modulo e aspetta la
        // risposta: al massimo sei al minuto (Coda 311). Il limite conta dopo la conferma della
        // password, quindi solo le prove vere.
        Route::post('impostazioni/mail/test', [MailSettingsController::class, 'testConnection'])
            ->middleware(['password.recente:impostazioni.mail', 'throttle:modulo'])
            ->name('admin.settings.mail.test');

        // LOGS & AUDIT
        Route::get('logs', [LogsController::class, 'index'])
            ->name('logs.index');
    });

    // BACKUPS
    Route::get('impostazioni/backups', [BackupSettingsController::class, 'index'])
        ->name('impostazioni.backups');

    Route::post('impostazioni/backups', [BackupSettingsController::class, 'store'])
        ->name('impostazioni.backups.store');

    Route::post('impostazioni/backups/settings', [BackupSettingsController::class, 'updateSettings'])
        ->name('impostazioni.backups.settings');

    // Togliere o impostare la password dei backup, e scaricarne uno, chiedono la password
    // dell'account ridigitata da poco (Coda 261): il controllo sta nei gestori, dopo quello sui
    // backup spenti (404) e sul permesso (403), che restano le prime risposte.
    Route::post('impostazioni/backups/password', [BackupSettingsController::class, 'removePassword'])
        ->name('impostazioni.backups.password');

    Route::post('impostazioni/backups/{backup:uuid}/step', [BackupSettingsController::class, 'step'])
        ->name('impostazioni.backups.step');

    Route::get('impostazioni/backups/{backup:uuid}/download', [BackupSettingsController::class, 'download'])
        ->name('impostazioni.backups.download');

    Route::delete('impostazioni/backups/{backup:uuid}', [BackupSettingsController::class, 'destroy'])
        ->name('impostazioni.backups.destroy');

    // RIPRISTINO — avvio (protetto da sessione + permesso + sudo). Gli step
    // successivi NON usano la sessione (vedi gruppo session-less più sotto).
    // L'ispezione apre l'archivio con la password mandata: sei prove al minuto, come gli altri moduli che
    // provano una password (`modulo`, con la rotta nella chiave: non consuma le prove della password
    // dell'account, che hanno il loro conto).
    Route::post('impostazioni/backups/{backup:uuid}/ripristina/ispeziona', [RestoreController::class, 'inspect'])
        ->middleware('throttle:modulo')
        ->name('impostazioni.backups.restore.inspect');

    // L'avvio chiede la password dell'account: conta fra le prove della password attuale
    // (`password-attuale`, un conto per utente su tutte le rotte che la verificano).
    Route::post('impostazioni/backups/{backup:uuid}/ripristina', [RestoreController::class, 'start'])
        ->middleware('throttle:password-attuale')
        ->name('impostazioni.backups.restore.start');

    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    // Il cambio dell'email chiede la «password attuale» nel modulo (Coda 261), l'eliminazione
    // dell'account la password: è la stessa barriera della conferma della password, e conta con lei
    // fra le prove della password attuale (`password-attuale`: un conto per utente, sei al minuto e
    // trenta all'ora, su tutte le rotte che la verificano). Conta anche i salvataggi senza password:
    // quei numeri non li raggiunge nessuno che stia scrivendo.
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:password-attuale')
        ->name('profile.update');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware('throttle:password-attuale')
        ->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])
        ->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-attuale')
        ->name('password.update');

    // La 2FA chiede la password ridigitata da poco, anche la pagina, che mostra i codici di recupero
    // (Coda 261). Le quattro chiamate della pagina, scaduta la conferma, ricevono un 423 e dopo la
    // conferma si torna alla pagina.
    Route::get('settings/two-factor', [TwoFactorAuthController::class, 'show'])
        ->middleware('password.recente')
        ->name('two-factor.show');

    Route::post('settings/two-factor', [TwoFactorAuthController::class, 'enable'])
        ->middleware('password.recente:two-factor.show')
        ->name('two-factor.enable');

    Route::post('settings/two-factor/confirm', [TwoFactorAuthController::class, 'confirm'])
        ->middleware('password.recente:two-factor.show')
        ->name('two-factor.confirm');

    Route::post('settings/two-factor/recovery-codes', [TwoFactorAuthController::class, 'regenerateRecoveryCodes'])
        ->middleware('password.recente:two-factor.show')
        ->name('two-factor.regenerate-recovery-codes');

    Route::delete('settings/two-factor', [TwoFactorAuthController::class, 'disable'])
        ->middleware('password.recente:two-factor.show')
        ->name('two-factor.disable');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});

/*
| I middleware del gruppo `web` che usano la sessione o il suo utente, tolti dalle quattro rotte del
| ripristino autenticate dal token (step, stato, riprendi, annulla). Nel database importato l'utente
| della sessione può avere un'altra password (`auth.session` risponderebbe 401), essere sospeso
| (`CheckSuspendedUser`: un rimando al login) o essere un amministratore con il database di una
| versione precedente (`CheckForPendingUpdates`: un rimando alla pagina dell'aggiornamento); e a metà
| dell'import la tabella degli utenti può non esserci ancora: ogni lettura dell'utente, anche quella dei
| dati condivisi delle pagine (`HandleInertiaRequests`), finiva in un errore 500. Il ripristino si
| fermava a metà senza dire niente. Le quattro rotte rispondono in JSON e non leggono l'utente.
|
| Fuori anche la sessione stessa. Con le sessioni nel database (`SESSION_DRIVER=database`: l'immagine
| Docker, le installazioni nate fino alla v1.6.0, un `.env` senza la riga) `StartSession` legge e scrive
| la tabella `sessions`, che l'import toglie e ricrea: il passo in cui la tabella manca rispondeva 500
| dopo aver lavorato, e da lì ogni richiesta del token, «Riprendi» e «Annulla» compresi; e una sessione
| riscritta durante l'import poteva scontrarsi con la stessa riga del backup. Con lei se ne vanno i due
| middleware del gruppo che la usano: gli errori per le viste e la protezione dalle richieste
| contraffatte, che qui non protegge niente (step, riprendi e annulla ne sono già esclusi in
| `bootstrap/app.php`, stato è una lettura): il token viaggia in un'intestazione, che una pagina di un
| altro sito non può mandare. Queste rotte non emettono più il cookie della sessione né `XSRF-TOKEN`.
*/
$senzaUtenteDellaSessione = [
    'auth.session',
    CheckSuspendedUser::class,
    CheckForPendingUpdates::class,
    HandleInertiaRequests::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

/*
|--------------------------------------------------------------------------
| Ripristino — rotte SENZA sessione
|--------------------------------------------------------------------------
| Durante l'import il database viene sovrascritto e la tabella sessions con
| esso: gli step e lo stato NON possono usare l'auth di sessione. Sono
| autenticati dal token monouso (EnsureRestoreToken) e devono restare
| raggiungibili anche in modalità ripristino (il prefisso "ripristino" è
| nell'allowlist di CheckRestoreMode). Vedi docs/ripristino_backup_design.md.
|
| Fuori da `auth.session` (Coda 261), come «riprendi» e «annulla» più sotto:
| il gruppo `web` chiude la sessione di un utente la cui password non è più
| quella con cui la sessione è nata, e dopo l'import del database l'utente della
| sessione del browser può avere un'altra password, o nessuna. Queste rotte non
| leggono la sessione: risponderebbero 401 a un ripristino che sta andando bene.
| Per la stessa ragione fuori dalla sessione e dagli altri middleware del gruppo
| che la usano (`$senzaUtenteDellaSessione`, qui sopra).
*/
Route::middleware(EnsureRestoreToken::class)->withoutMiddleware($senzaUtenteDellaSessione)->group(function () {

    Route::post('ripristino/step', [RestoreController::class, 'step'])
        ->name('ripristino.step');

    Route::get('ripristino/stato', [RestoreController::class, 'status'])
        ->name('ripristino.status');
});

// Pagina di esito: la modalità ripristino è già spenta se completato, ma le
// sessioni sono state azzerate — niente auth, legge lo stato su file.
Route::get('ripristino/esito', [RestoreController::class, 'result'])
    ->name('ripristino.result');

// Recupero da un ripristino fallito/in stallo (pulsanti della pagina 503 o
// dell'overlay admin). NON sotto EnsureRestoreToken: chi arriva dalla 503 non
// ha il token — l'autorizzazione (token OPPURE password account) è nel
// controller. CSRF-except in bootstrap/app.php (nessuna sessione qui).
// Il limite di tentativi sulla password sta nel controller (authorizeRecovery), non qui: un
// throttle di rotta conterebbe anche le richieste col token, e cinque password sbagliate di chiunque
// dallo stesso indirizzo bloccherebbero il recupero all'amministratore.
Route::post('ripristino/riprendi', [RestoreController::class, 'resume'])
    ->withoutMiddleware($senzaUtenteDellaSessione)
    ->name('ripristino.resume');
Route::post('ripristino/annulla', [RestoreController::class, 'abort'])
    ->withoutMiddleware($senzaUtenteDellaSessione)
    ->name('ripristino.abort');
