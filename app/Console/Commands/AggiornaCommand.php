<?php

namespace App\Console\Commands;

use App\Services\System\SystemFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Allinea un database già installato al codice in esecuzione: quello che la pagina di
 * aggiornamento fa dopo aver deployato uno zip, per chi il codice lo cambia in un altro modo —
 * un'immagine Docker nuova, un `git pull`. Sono i passi di `SystemFinalizer` che toccano il
 * database: migrazioni, permessi e mappa dei ruoli, elenco dei comuni, classificazione ATECO,
 * versione registrata. Non le cache né il collegamento di storage: chi chiama (l'entrypoint
 * dell'immagine) le fa da sé, nell'ordine giusto per il suo ambiente.
 *
 * Perché non basta `migrate`: permessi, comuni e ATECO vivono nel codice e arrivano a database
 * solo con i loro seeder mirati — e `db:seed` intero non si rilancia mai su un'installazione
 * esistente (farebbe risorgere le categorie cancellate). Un'immagine nuova che aggiunge un
 * permesso, senza questo passo, lo lascerebbe nel codice: è il guasto silenzioso della beta.55,
 * che la revisione della beta.32 ha visto ripresentarsi nel container.
 *
 * Idempotente: su un database già allineato non cambia niente. Su un database vuoto si ferma:
 * quello è lavoro per `kondomanager:installa`.
 */
class AggiornaCommand extends Command
{
    protected $signature = 'kondomanager:aggiorna';

    protected $description = 'Allinea il database al codice in esecuzione (migrazioni, permessi, comuni, ATECO, versione); non fa nulla se è già allineato';

    public function handle(SystemFinalizer $finalizer): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
            $this->components->error('Il database non è installato: prima `php artisan kondomanager:installa`.');

            return self::FAILURE;
        }

        $this->components->task('Migrazioni', fn () => $finalizer->runMigrationsWithRetry());
        $this->components->task('Ruoli e permessi', fn () => $finalizer->sincronizzaRuoliEPermessi());
        $this->components->task('Elenco dei comuni', fn () => $finalizer->caricaElencoComuni());
        $this->components->task('Classificazione ATECO', fn () => $finalizer->caricaClassificazioneAteco());
        $this->components->task('Versione registrata', fn () => $finalizer->alignDatabaseVersion());

        return self::SUCCESS;
    }
}
