<?php

use App\Http\Controllers\Auth\NewUserPasswordController;
use App\Http\Controllers\Condomini\CondominioController;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Condomini\FetchCondominiController;
use App\Http\Controllers\Frontend\WelcomeController;
use App\Http\Controllers\Inviti\InvitoController;
use App\Http\Controllers\Inviti\InvitoRegisteredUserController;
use App\Http\Controllers\Permissions\PermissionController;
use App\Http\Controllers\Permissions\RevokePermissionFromUserController;
use App\Http\Controllers\Roles\RevokePermissionFromRoleController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Segnalazioni\SegnalazioniStatsController;
use App\Http\Controllers\System\SystemUpgradeController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserReinviteController;
use App\Http\Controllers\Users\UserStatusController;
use App\Http\Controllers\Users\UserVerifyController;
use App\Http\Middleware\CheckExternalCron;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Comuni\CercaComuniController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)
    ->name('home');

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/
Route::resource('/utenti', UserController::class)
    ->middleware(['auth', 'verified']);

Route::put('/utenti/{user}/suspend', [UserStatusController::class, 'suspend'])
    ->middleware(['auth', 'verified'])
    ->name('utenti.suspend');

Route::put('/utenti/{user}/unsuspend', [UserStatusController::class, 'unsuspend'])
    ->middleware(['auth', 'verified'])
    ->name('utenti.unsuspend');

Route::post('/utenti/reinvite/{email}', [UserReinviteController::class, 'reinviteUser'])
    ->middleware(['auth', 'verified'])
    ->name('utenti.reinvite');

Route::delete('users/{user}/permissions/{permission}', RevokePermissionFromUserController::class)
    ->middleware(['auth', 'verified'])
    ->name('users.permissions.destroy');

Route::put('/utenti/{user}/toggle-verification', UserVerifyController::class)
    ->middleware(['auth', 'verified'])
    ->name('utenti.toggle-verification');

/*
|--------------------------------------------------------------------------
| Roles Routes
|--------------------------------------------------------------------------
*/
Route::resource('/ruoli', RoleController::class)
    ->middleware(['auth', 'verified']);

Route::delete('roles/{role}/permissions/{permission}', RevokePermissionFromRoleController::class)
    ->middleware(['auth', 'verified'])
    ->name('ruoli.permissions.destroy');

/*
|--------------------------------------------------------------------------
| Permission Routes
|--------------------------------------------------------------------------
*/
Route::get('/permessi', [PermissionController::class, 'index'])
    ->middleware(['auth', 'verified']);

Route::get('/segnalazioni/stats', SegnalazioniStatsController::class)
    ->middleware(['auth', 'verified'])
    ->name('segnalazioni.stats');

/*
|--------------------------------------------------------------------------
| Ricerca dei Comuni italiani — l'aiuto accanto al campo, che resta libero
|--------------------------------------------------------------------------
|
| ⚠️ Sta qui e non sotto `/admin` per una ragione misurata: il pulsante che la chiama vive su
| quattro schermate governate da **due sbarramenti diversi** — le due del condominio chiedono
| «Visualizza condomini» (routes/web.php, poco sotto), le due dell'unità l'accesso al pannello.
| Registrata dentro il gruppo `/admin` con il solo accesso al pannello, il pulsante compariva a chi
| poi riceveva un 403: una funzione visibile e rotta per un ruolo costruito a mano.
|
| L'elenco è dato pubblico ISTAT e non contiene niente dell'installazione: il filtro serve a
| rispettare la convenzione di casa, non a proteggere un segreto.
*/
Route::get('/comuni/cerca', CercaComuniController::class)
    ->middleware([
        'auth',
        'verified',
        'role_or_permission:amministratore|collaboratore|Accesso pannello amministratore|Visualizza condomini',
    ])
    ->name('comuni.cerca');

/*
|--------------------------------------------------------------------------
| Condomini Routes
|--------------------------------------------------------------------------
*/
Route::resource('/condomini', CondominioController::class)
    ->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Visualizza condomini'])
    ->parameters([
        'condomini' => 'condominio',
    ]);

Route::get('/condomini/options', [CondominioController::class, 'options'])
    ->name('condomini.options');

/*
 * Il condominio dimostrativo.
 *
 * ⚠️ **Sotto lo stesso sbarramento della creazione**: chi non può creare un condominio non può
 * nemmeno crearne uno di prova. Sono due rotte e non una perché la seconda deve poter esistere da
 * sola — un amministratore che ha finito di guardare la demo la toglie senza passare dalla pagina
 * di creazione.
 */
Route::post('/condomini/dimostrativo', [CondominioController::class, 'creaDimostrativo'])
    ->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Visualizza condomini'])
    ->name('condomini.dimostrativo.crea');

Route::delete('/condomini/{condominio}/dimostrativo', [CondominioController::class, 'eliminaDimostrativo'])
    ->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Visualizza condomini'])
    ->name('condomini.dimostrativo.elimina');

Route::get('/fetch-condomini', FetchCondominiController::class)
    ->middleware(['auth', 'verified']);

/*
|--------------------------------------------------------------------------
| Importazione dati da altri gestionali
|--------------------------------------------------------------------------
|
| Fuori dal gruppo `/gestionale/{condominio}` **per necessità**, non per gusto:
| l'importazione precede il condominio — il primo file che si carica è spesso
| quello che lo crea — mentre quel gruppo ha in testa un condominio esistente e
| due middleware che pretendono esercizio e piano dei conti.
|
| Il permesso è quello della creazione condomìni: chi può importare sta creando
| condomìni, anagrafiche e unità in blocco.
*/
Route::middleware(['auth', 'verified', 'role_or_permission:amministratore|Crea condomini'])
    ->prefix('/importa-dati')
    ->name('import.')
    ->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/', [ImportController::class, 'store'])->name('store');

        Route::get('/{uuid}/riconoscimento', [ImportController::class, 'riconoscimento'])
            ->name('riconoscimento');

        Route::get('/{uuid}/verifica', [ImportController::class, 'verificaFile'])
            ->name('verifica');

        Route::get('/{uuid}/anteprima', [ImportController::class, 'anteprima'])
            ->name('anteprima');

        Route::put('/{uuid}/decisione', [ImportController::class, 'decidi'])
            ->name('decisione');

        Route::post('/{uuid}/conferma', [ImportController::class, 'conferma'])
            ->name('conferma');

        Route::get('/{uuid}/esito', [ImportController::class, 'esito'])
            ->name('esito');

        Route::get('/{uuid}/rapporto.pdf', [ImportController::class, 'rapportoPdf'])
            ->name('rapporto');

        // Chiude una sessione lasciata a metà. Non annulla ciò che è già entrato in archivio:
        // quello ha una condizione da valutare, e arriva con la 1.10.1.
        Route::delete('/{uuid}', [ImportController::class, 'scarta'])->name('scarta');

        Route::put('/{uuid}/file/{file}/tipo', [ImportController::class, 'forzaTipo'])
            ->name('file.tipo');

        Route::delete('/{uuid}/file/{file}', [ImportController::class, 'escludiFile'])
            ->name('file.escludi');
    });

/*
|--------------------------------------------------------------------------
| Passwords Routes
|--------------------------------------------------------------------------
*/
Route::get('/password/new', [NewUserPasswordController::class, 'showResetForm'])
    ->name('password.new')
    ->middleware('signed');

Route::post('/password/new', [NewUserPasswordController::class, 'reset'])
    ->name('password.create');

/*
|--------------------------------------------------------------------------
| Inviti Routes
|--------------------------------------------------------------------------
*/
// `only()` e non un `resource` intero: `InvitoController` implementa **solo** queste quattro
// azioni. Le altre tre che `Route::resource` generava — `show`, `edit`, `update` — puntavano a
// metodi inesistenti e rispondevano **500** a chiunque ci arrivasse per URL. Un invito non si
// modifica: si revoca (`destroy`) e se ne manda un altro. Rimosse nella beta.62, insieme alle
// altre quattordici trovate dalla scansione di `RotteSenzaMetodoTest`.
Route::resource('/inviti', InvitoController::class)
    ->only(['index', 'create', 'store', 'destroy'])
    ->middleware(['auth', 'verified']);

Route::get('/invito/register', [InvitoRegisteredUserController::class, 'show'])
    ->name('invito.register')
    ->middleware('signed', 'throttle:6,1');

/*
|--------------------------------------------------------------------------
| System Upgrade Routes
|--------------------------------------------------------------------------
*/

// GRUPPO 1: Rotte ibride (Manuale + Automatico)
// Queste rotte NON devono avere il middleware 'auto.update' perché servono
// anche a chi aggiorna caricando i file via FTP per lanciare le migrazioni DB.
Route::middleware(['auth', 'verified', 'role:amministratore'])
    ->prefix('system/upgrade')
    ->name('system.upgrade.')
    ->group(function () {
        // La Dashboard: Il controller gestisce internamente la vista "Disabled"
        Route::get('/', [SystemUpgradeController::class, 'index'])->name('index');

        // Pagina di conferma database (Accessibile dopo upload manuale o auto)
        Route::get('/finalize', [SystemUpgradeController::class, 'confirm'])->name('confirm');

        // Backup di sicurezza (solo database) PRIMA delle migrazioni, guidato
        // a step dal frontend come il backup normale.
        Route::post('/backup', [SystemUpgradeController::class, 'backupStart'])->name('backup');
        Route::post('/backup/{backup:uuid}/step', [SystemUpgradeController::class, 'backupStep'])->name('backup.step');

        // Esecuzione migrazioni (Deve funzionare anche in manuale!)
        Route::post('/run', [SystemUpgradeController::class, 'run'])->name('run');

        // Changelog
        Route::get('/whats-new', [SystemUpgradeController::class, 'showChangelog'])->name('changelog');
    });

// GRUPPO 2: Rotte ESCLUSIVE per Auto-Update
// Queste rotte creano il bridge e scaricano file. Devono essere bloccate se config=false.
Route::middleware(['auth', 'verified', 'auto.update', 'role:amministratore'])
    ->prefix('system/upgrade')
    ->name('system.upgrade.')
    ->group(function () {
        Route::post('/launch', [SystemUpgradeController::class, 'launch'])->name('launch');
    });

/*

|--------------------------------------------------------------------------
| Rotta per Cron Job Esterno
|--------------------------------------------------------------------------
| Questa rotta è dedicata esclusivamente all'esecuzione dello scheduler tramite un cron job esterno (es. cron-job.org).
| Il middleware CheckExternalCron gestisce la sicurezza, autorizzando solo le richieste con token valido e provenienti dagli IP di cron-job.org.
| La rotta è protetta da un token di sicurezza configurabile e da un controllo IP per garantire che solo cron-job.org possa accedervi, prevenendo abusi e accesssi non autorizzati.
*/

Route::get('/system/run-scheduler', function (Request $request) {

    // 1. ATOMIC LOCK (Protezione Anti-Sovrapposizione)
    // Se lo scheduler è lento e dura più di 1 minuto, impediamo che
    // ne parta un secondo in parallelo che impallerebbe la CPU/RAM.
    // Il lock scade automaticamente dopo 50 secondi.
    $lock = Cache::lock('scheduler_running', 50);

    if (! $lock->get()) {
        // Se non riusciamo a prendere il lock, significa che sta già girando.
        return response()->json([
            'status' => 'skipped',
            'message' => 'Scheduler già in esecuzione (Overlap Protection).',
        ], 429);
    }

    try {
        // Eseguiamo lo scheduler
        Artisan::call('schedule:run');

        return response()->json([
            'status' => 'success',
            'message' => 'Scheduler eseguito (WEB).',
            'timestamp' => now()->toDateTimeString(),
        ]);

    } finally {
        // Rilasciamo il blocco immediatamente dopo aver finito
        $lock->release();
    }

})->middleware([
    CheckExternalCron::class,
    'throttle:3,1',
]);

/*
|--------------------------------------------------------------------------
| Settings nd Auth Routes
|--------------------------------------------------------------------------
*/
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/user.php';
