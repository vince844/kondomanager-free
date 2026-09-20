<?php

namespace App\Console\Commands;

use App\Services\Installer\ChiudiInstallazione;
use App\Services\Installer\CreaAmministratore;
use App\Services\Installer\PreparaDatabase;
use App\Services\Installer\ScriviImpostazioni;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * L'installazione senza wizard: quello che il wizard chiede a una persona, qui arriva
 * dall'ambiente (o dalle opzioni). Serve a chi avvia KondoManager in un container — dove il
 * codice è in sola lettura e il `.env` non esiste — e a chi vuole un'installazione ripetibile.
 * Usa gli stessi servizi del wizard (App\Services\Installer): una logica sola.
 *
 * Idempotente per costruzione: su un'installazione già fatta non fa nulla e non fallisce, così
 * l'entrypoint di un container può lanciarlo a ogni avvio. E prima di scrivere qualunque cosa
 * verifica di avere tutto: su un database vuoto senza le credenziali dell'amministratore si ferma
 * con un errore chiaro, senza creare nemmeno una tabella.
 *
 * Ordine dei passi, ed è un ordine che conta: migrazioni → ruoli → amministratore → seeder. Il
 * DatabaseSeeder, quando il wizard è spento, crea un amministratore di comodo con password nota
 * se non ne trova uno: creare il nostro PRIMA è ciò che lo tiene fuori.
 */
class InstallaCommand extends Command
{
    protected $signature = 'kondomanager:installa
        {--admin-nome= : nome dell\'amministratore (default INSTALL_ADMIN_NAME)}
        {--admin-email= : email dell\'amministratore (default INSTALL_ADMIN_EMAIL)}
        {--admin-password= : password iniziale, almeno 6 caratteri (default INSTALL_ADMIN_PASSWORD)}
        {--nome= : nome dell\'applicazione (default APP_NAME)}
        {--lingua= : lingua dell\'interfaccia, fra quelle disponibili (default APP_LOCALE)}
        {--attendi=120 : quanti secondi aspettare che il database risponda}';

    protected $aliases = ['km:install'];

    protected $description = 'Installa KondoManager senza il wizard, leggendo le impostazioni dall\'ambiente; non fa nulla se è già installato';

    public function handle(
        PreparaDatabase $prepara,
        CreaAmministratore $creaAmministratore,
        ScriviImpostazioni $scriviImpostazioni,
        ChiudiInstallazione $chiudi,
    ): int {
        if (! $this->attendiDatabase((int) $this->option('attendi'))) {
            $this->components->error('Il database non risponde: nessuna scrittura.');

            return self::FAILURE;
        }

        // Il lock vale solo con un database che c'è: un volume conservato con un MySQL ricreato
        // vuoto ha il lock e niente altro, e va installato, non dichiarato pronto.
        if ($chiudi->installata()) {
            if (Schema::hasTable('migrations')) {
                $this->components->info('KondoManager è già installato (file di lock presente): nessuna modifica.');

                return self::SUCCESS;
            }

            $this->components->warn('File di lock presente ma database vuoto: si installa.');
        }

        if ($creaAmministratore->esisteUnAmministratore()) {
            // Installazione fatta da un'altra strada (wizard senza lock, sorgente): si chiude solo.
            $chiudi->esegui();
            $this->components->info('Installazione già presente (esiste un amministratore): scritto il file di lock, nessun\'altra modifica.');

            return self::SUCCESS;
        }

        // `?:` e non il default di env(): una variabile presente ma vuota vale come assente.
        $adminNome = (string) ($this->option('admin-nome') ?: env('INSTALL_ADMIN_NAME') ?: 'Amministratore');
        $adminEmail = (string) ($this->option('admin-email') ?: env('INSTALL_ADMIN_EMAIL', ''));
        $adminPassword = (string) ($this->option('admin-password') ?: env('INSTALL_ADMIN_PASSWORD', ''));
        $nome = (string) ($this->option('nome') ?: config('app.name', ''));
        $lingua = (string) ($this->option('lingua') ?: config('app.locale', ''));

        // Le stesse regole del wizard, ma PRIMA di toccare il database: un errore qui non lascia
        // tabelle a metà. La password non compare mai nell'output.
        try {
            Validator::make(
                ['name' => trim($adminNome), 'email' => trim($adminEmail), 'password' => $adminPassword],
                ['name' => 'required|string|min:2', 'email' => 'required|email', 'password' => 'required|min:6'],
                [],
                ['name' => 'INSTALL_ADMIN_NAME', 'email' => 'INSTALL_ADMIN_EMAIL', 'password' => 'INSTALL_ADMIN_PASSWORD'],
            )->validate();
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $messaggio) {
                $this->components->error($messaggio);
            }
            $this->components->error('Mancano i dati dell\'amministratore: nessuna scrittura.');

            if ($this->laravel->configurationIsCached()) {
                $this->components->warn('La configurazione è in cache e il file .env non viene letto: `php artisan config:clear`, oppure passa i dati come opzioni.');
            }

            return self::INVALID;
        }

        $this->components->task('Migrazioni', fn () => $prepara->migra(fresh: false));
        $this->components->task('Cache dei permessi', fn () => $prepara->pulisciCache());

        // Da qui alle impostazioni è tutto DML, e va in una transazione sola: un errore a metà
        // (un container fermato dall'orchestratore durante il seed, una connessione caduta)
        // non deve lasciare un amministratore senza dati iniziali — che al secondo avvio
        // passerebbe per un'installazione fatta. Con la transazione, il secondo avvio trova lo
        // schema e nient'altro, e rifà tutto.
        try {
            DB::transaction(function () use ($prepara, $creaAmministratore, $scriviImpostazioni, $adminNome, $adminEmail, $adminPassword, $nome, $lingua) {
                $this->components->task('Ruoli e permessi', fn () => $prepara->seedRuoli());
                $this->components->task("Amministratore {$adminEmail}", fn () => $creaAmministratore->esegui($adminNome, $adminEmail, $adminPassword));
                $this->components->task('Dati iniziali', fn () => $prepara->seed());
                $this->components->task('Impostazioni', fn () => $scriviImpostazioni->esegui($nome ?: null, $lingua ?: null));
            });
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $messaggio) {
                $this->components->error($messaggio);
            }

            return self::INVALID;
        } catch (\Throwable $e) {
            // Solo il messaggio: la traccia dell'eccezione originale porta gli argomenti dei
            // frame, e uno di quelli è la password dell'amministratore.
            throw new \RuntimeException('Installazione interrotta, nessun dato scritto: '.$e->getMessage());
        }

        $this->components->task('Collegamento storage', fn () => $prepara->collegaStorage());
        $this->components->task('Chiusura', fn () => $chiudi->esegui());

        $this->components->info("KondoManager installato. Accedi con {$adminEmail}.");

        return self::SUCCESS;
    }

    protected function attendiDatabase(int $secondi): bool
    {
        $scadenza = time() + max(0, $secondi);

        do {
            try {
                DB::connection()->getPdo();

                return true;
            } catch (\Throwable) {
                if (time() >= $scadenza) {
                    return false;
                }
                sleep(2);
            }
        } while (true);
    }
}
