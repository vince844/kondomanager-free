<?php

use App\Models\CategoriaDocumento;
use App\Services\System\SystemFinalizer;

/**
 * Le cinque categorie dei documenti arrivano da una migrazione, e nessun seeder le rimette.
 *
 * ## ⚠️ Perché, e perché qui il difetto non era teorico
 *
 * Le categorie dei documenti **si cancellano dall'interfaccia da prima** di questa beta:
 * `admin.categorie.destroy` esiste e la voce sta nel menù di ogni riga. Finché le righe iniziali le
 * scriveva `CategoriaDocumentoSeeder` con `firstOrCreate`, **ogni `db:seed` faceva risorgere ciò che
 * l'amministratore aveva cancellato di proposito**, senza dirlo.
 *
 * È la **Coda 103**. Delle quattro tabelle master era la più urgente proprio per questo: per i
 * fornitori (chiusa nella beta.9) la cancellazione non esisteva ancora, quindi il difetto era
 * dormiente; qui era sveglio.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'idempotenza della migrazione, che sta in
 * `tests/Feature/System/UpgradeMigrationsRerunTest.php` col resto. Non copre il difetto della
 * categoria «Fatture» cercata per nome da `FatturaPassivaService`: è la **Coda 106**, ed è un
 * problema diverso — questa migrazione garantisce che la categoria *esista*, non che il nome resti.
 */
it('⚠️ un\'installazione si ritrova le cinque categorie, non una tabella vuota', function () {
    // ⚠️ È la lezione della beta.8 e della beta.59: una tabella che nasce vuota non dà errori, non
    // lascia log, e si nota solo aprendo la tendina. Qui la consegna la fa una migrazione, quindi
    // basta che le migrazioni siano girate — cioè quello che `RefreshDatabase` fa.
    expect(CategoriaDocumento::count())->toBe(5)
        ->and(CategoriaDocumento::where('name', 'Bilanci')->exists())->toBeTrue()
        ->and(CategoriaDocumento::where('name', 'Fatture')->exists())->toBeTrue();
});

it('⚠️ una categoria cancellata NON torna, nemmeno rilanciando la finalizzazione', function () {
    // Il gesto vero: l'amministratore elimina «Avvisi» perché non la usa, e poi arriva un
    // aggiornamento. Con il seeder tornava, e nessuno collegava la ricomparsa a quel comando.
    CategoriaDocumento::where('name', 'Avvisi')->delete();

    app(SystemFinalizer::class)->finalize();

    expect(CategoriaDocumento::where('name', 'Avvisi')->exists())->toBeFalse()
        ->and(CategoriaDocumento::count())->toBe(4);
});

it('⚠️ nessun seeder può far risorgere una categoria di documento', function () {
    // ⚠️ Non `class_exists()`: la classmap di composer può contenere ancora la voce di una classe
    // cancellata, e il tentativo di aprirne il file farebbe fallire il test con un errore di
    // inclusione invece che con la misura. Il file o c'è o non c'è.
    expect(file_exists(database_path('seeders/CategoriaDocumentoSeeder.php')))->toBeFalse();

    // E la guardia guarda **due** forme, non una: il modello e la tabella. Un seeder che scrivesse
    // con `DB::table('categorie_documento')` aggirerebbe un controllo che cerca solo il modello, e
    // la protezione si svuoterebbe senza che nessuno se ne accorga.
    $colpevoli = collect(glob(database_path('seeders/*.php')))
        ->filter(function (string $file) {
            $testo = file_get_contents($file);

            return str_contains($testo, 'CategoriaDocumento::')
                || str_contains($testo, "'categorie_documento'");
        })
        ->map(fn (string $file) => basename($file))
        ->values()
        ->all();

    expect($colpevoli)->toBe([]);
});
