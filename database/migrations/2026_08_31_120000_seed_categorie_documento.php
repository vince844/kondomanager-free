<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le categorie dei documenti iniziali, spostate dal seeder a qui.
 *
 * ## Perché una migrazione e non un seeder
 *
 * ⚠️ **Qui il difetto non è teorico come lo era per i fornitori: è vivo da sempre.** Le categorie
 * dei documenti **si possono già cancellare** — `admin.categorie.destroy` esiste, e la voce sta nel
 * menù di ogni riga dell'elenco categorie. Finché le righe iniziali le scriveva
 * `CategoriaDocumentoSeeder` con `firstOrCreate`, **ogni esecuzione di `db:seed` faceva risorgere
 * ciò che l'amministratore aveva cancellato di proposito**, senza dirlo.
 *
 * È la **Coda 103**, aperta aprendo la 1.11.0-beta.9 e chiusa per i fornitori in quella stessa beta:
 * delle quattro tabelle master questa era la più urgente, perché è l'unica in cui la cancellazione
 * era già raggiungibile dall'interfaccia. Restano `CategoriaEventoSeeder` — che è **peggio**, usa
 * `updateOrCreate` e quindi riscrive anche le descrizioni che l'amministratore ha cambiato — e
 * `TipologieImmobiliSeeder`.
 *
 * **Una migrazione ha per costruzione la semantica «una volta sola»**: Laravel la registra in
 * `migrations` e non la riesegue mai più. Nessuna colonna `di_sistema`, nessuna lapide, nessuna
 * logica da mantenere; e il giorno che qualcuno aggiungesse `db:seed` al percorso di aggiornamento,
 * qui non c'è più niente che possa risorgere, perché **il seeder non esiste più**.
 *
 * ## ⚠️ «Fatture» non è un'etichetta come le altre, ed è la ragione della Coda 106
 *
 * `FatturaPassivaService` la cerca **per nome** in due punti — `CategoriaDocumento::where('name',
 * 'Fatture')->first()` — e quando non la trova scrive `category_id` a **null**: da quel momento ogni
 * allegato di fattura entra in archivio senza categoria, in silenzio.
 *
 * La cancellazione è protetta **per caso** (`destroy()` rifiuta se la categoria ha documenti, e
 * «Fatture» in uso ne ha); la **rinomina non è protetta affatto**, ed è l'azione più probabile.
 * Questa migrazione garantisce che la categoria **esista** su ogni installazione, che è metà del
 * problema; l'altra metà — il codice che cerca per nome — è la Coda 106 e si scioglie a parte.
 *
 * ## Idempotenza
 *
 * Guardia sulla tabella, e poi **una guardia per nome**: le installazioni esistenti hanno già queste
 * righe dal seeder e non devono ricevere doppioni. Va nel dataset di
 * `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 *
 * ⚠️ **Un'installazione che ne ha già cancellata una NON se la vede tornare**, ed è il punto: la
 * guardia per nome non la reintroduce, perché la migrazione gira una volta sola e su quelle
 * installazioni è già passata. *(Al contrario del seeder, che la rimetteva a ogni `db:seed`.)*
 */
return new class extends Migration
{
    /** Le cinque categorie iniziali, con le descrizioni che stanno già nei database esistenti. */
    private const CATEGORIE = [
        ['name' => 'Bilanci',   'description' => 'Documenti relativi ai bilanci economici'],
        ['name' => 'Verbali',   'description' => 'Verbali delle assemblee e riunioni'],
        ['name' => 'Avvisi',    'description' => 'Comunicazioni e avvisi generali'],
        ['name' => 'Contratti', 'description' => 'Contratti stipulati con fornitori o terzi'],

        // ⚠️ Questa la usa il codice, non solo l'amministratore: vedi il blocco qui sopra e la
        // Coda 106. Su un database esistente ha un id alto (31 su quello di sviluppo) perché è
        // stata aggiunta dopo le altre quattro, con il ciclo passivo.
        ['name' => 'Fatture',   'description' => 'Fatture passive e note di credito dei fornitori'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('categorie_documento')) {
            return;
        }

        $adesso = now();

        foreach (self::CATEGORIE as $categoria) {
            // Una guardia per nome, non un `insert` in blocco: chi ha già queste righe dal seeder
            // non deve ricevere doppioni.
            if (DB::table('categorie_documento')->where('name', $categoria['name'])->exists()) {
                continue;
            }

            DB::table('categorie_documento')->insert([
                'name'        => $categoria['name'],
                'description' => $categoria['description'],
                'created_at'  => $adesso,
                'updated_at'  => $adesso,
            ]);
        }
    }

    /**
     * ⚠️ **Il `down()` non cancella niente, ed è deliberato.**
     *
     * Dopo questa migrazione le categorie sono dati dell'amministratore, non nostri: ne avrà
     * rinominate, cancellate e aggiunte. Cancellare «le cinque che abbiamo messo» significherebbe
     * portare via anche quelle che ha rinominato, e lasciare senza categoria i documenti che le
     * usano. Un `down()` che distrugge dati dell'utente è peggio di un `down()` che non fa niente.
     */
    public function down(): void
    {
        // Volutamente vuoto: vedi sopra.
    }
};
