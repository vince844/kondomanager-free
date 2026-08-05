<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Le migrazioni del salto 1.9.1 → 1.10 devono poter essere RIESEGUITE.
 *
 * Motivo: MySQL non ha DDL transazionale, e su hosting condiviso le migrazioni
 * possono superare il tempo massimo di esecuzione imposto dal web server, che
 * PHP non può alzare. Quando succede, le modifiche già applicate restano nel
 * database ma la migrazione NON viene registrata in `migrations`: al tentativo
 * successivo riparte da capo.
 *
 * Se non è rieseguibile, quel secondo tentativo fallisce con un errore secco
 * ("Table already exists", "Duplicate column name", "Duplicate key name"),
 * `SystemFinalizer::runMigrationsWithRetry()` riprova tre volte e si arrende,
 * e l'installazione resta con il database a metà — senza backup automatico,
 * che dalla 1.10 non è disponibile aggiornando da una 1.9.x.
 *
 * La pagina di conferma DICHIARA all'amministratore che l'aggiornamento
 * riprende da dove si era interrotto. Questo test è ciò che rende vera quella
 * frase.
 *
 * Regressione beta.31: tre migrazioni su dodici — le tre di
 * `contributi_versati`, le ultime aggiunte — non avevano alcuna guardia.
 */
it('resta rieseguibile dopo un\'interruzione a metà', function (string $file) {
    $path = database_path("migrations/{$file}.php");

    expect(file_exists($path))->toBeTrue("Migrazione non trovata: {$file}");

    $migration = require $path;

    $tabellePrima = collect(Schema::getTableListing())->sort()->values()->all();

    // RefreshDatabase ha già eseguito l'intera catena: questa seconda chiamata
    // riproduce esattamente la ripresa dopo un'interruzione che ha applicato le
    // modifiche senza registrare la migrazione. Se solleva, il test fallisce qui.
    $migration->up();

    // Una riesecuzione deve essere un no-op strutturale: nessuna tabella persa,
    // nessuna tabella comparsa.
    expect(collect(Schema::getTableListing())->sort()->values()->all())
        ->toEqual($tabellePrima);
})->with([
    // Aggiunta nella beta.43: era l'unica migrazione del percorso 1.9.1 → 1.10 rimasta fuori
    // dal dataset. È un `MODIFY` su un ENUM, quindi intrinsecamente rieseguibile — passa senza
    // toccare niente. Il punto non è che potesse rompersi: è che una migrazione non presidiata
    // qui dentro smette di essere controllata, e la beta.31 ha già pagato quella distrazione
    // con tre migrazioni senza guardia.
    '2026_02_20_093745_update_metodo_distribuzione_in_piani_rate_table',
    '2026_07_10_000010_create_backups_table',
    '2026_07_14_000000_add_type_and_encrypted_to_backups_table',
    '2026_07_21_000000_add_conferma_to_fattura_coperture_table',
    '2026_07_22_090000_add_regime_fiscale_to_fornitori_table',
    '2026_07_22_090100_add_base_ritenuta_to_righe_fattura_table',
    '2026_07_22_140000_add_is_capitolo_to_conti_table',
    '2026_07_24_090000_make_gestione_id_nullable_on_scritture_contabili',
    '2026_07_24_090100_backfill_apertura_saldi_casse',
    '2026_07_24_120000_create_contributi_versati_table',
    '2026_07_25_090000_add_unique_constraint_to_contributi_versati',
    '2026_07_25_100000_add_richiede_gia_versato_to_conti_table',
    '2026_07_25_150000_add_liquidita_stato_to_contributi_versati',
    '2026_07_31_120000_add_piano_rate_id_to_saldi_table',
    '2026_08_03_090000_create_deleghe_f24_tables',
    '2026_08_04_100000_add_data_prima_scadenza_to_piani_rate',
    '2026_08_05_090000_convert_tipologia_anagrafica_immobile_to_varchar',
]);
