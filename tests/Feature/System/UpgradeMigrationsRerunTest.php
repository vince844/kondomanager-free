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
    // Aggiunta nella beta.47: le tre tabelle dell'importatore. Sono `CREATE TABLE` pure,
    // senza un solo `ALTER` su tabelle popolate — la categoria a rischio più basso — ma
    // valgono anche per loro le regole del salto, e una migrazione fuori da questo dataset
    // smette di essere presidiata (lezione della beta.31).
    '2026_08_06_090000_create_import_tables',
    // Aggiunta nella beta.53: riclassifica la tipologia «Ufficio» da `unita_abitativa` a
    // `unita_non_abitativa`. È un `UPDATE` su dati esistenti — non uno schema change — e la sua
    // idempotenza sta tutta nella `WHERE` sulla categoria vecchia: al secondo giro non trova
    // righe. Proprio perché tocca **dati** e non struttura vale la pena presidiarla: uno schema
    // change fallisce rumorosamente, un update rieseguito male no.
    '2026_08_15_090000_correggi_categoria_ufficio',
    // Aggiunta nella beta.53: le due colonne di «Pertinenza di» su `immobili`, più il drop della
    // pivot `immobile_pertinenza`. È la categoria a rischio più alto del dataset — un `ALTER` con
    // chiave esterna su una tabella popolata, più un `DROP TABLE` — e su MySQL colonna e vincolo
    // sono due statement distinti, quindi un'interruzione a metà lascia esattamente lo stato che
    // `cleanupPartialMigration()` deve saper riconoscere.
    '2026_08_15_100000_add_pertinenza_di_to_immobili',
    // Aggiunta nella beta.54: la tabella delle righe-per-pagina scelte da ciascuno. È una
    // `CREATE TABLE` pura — la categoria a rischio più basso — ma vale la regola del salto: una
    // migrazione fuori da questo dataset smette di essere presidiata (lezione della beta.31).
    '2026_08_16_100000_create_preferenze_tabelle_utente',
    '2026_08_16_120000_add_last_login_at_to_users_table',

    // Aggiunta nella beta.58: allenta il vincolo NOT NULL su tre colonne esistenti. È un `MODIFY`
    // su tabelle vive e non un `CREATE`, quindi sta nella categoria a rischio più alto insieme a
    // `add_pertinenza_di_to_immobili` — ed è idempotente per costruzione, perché ripetere un
    // `change()` che rende nullable una colonna già nullable la riscrive identica.
    '2026_08_18_100000_rendi_facoltativi_descrizione_e_interno',

    // Aggiunta nella beta.59: crea la tabella dei Comuni italiani. È un `CREATE` puro con guardia
    // su `hasTable`, quindi la categoria meno rischiosa — ma sta qui lo stesso, perché una
    // migrazione fuori da questo dataset smette di essere presidiata.
    '2026_08_19_100000_create_comuni_table',

    // Aggiunta nella 1.11.0-beta.8: la classificazione ATECO. Stessa forma della tabella dei Comuni
    // — guardia sulla sola esistenza, nessuna foreign key da aggiungere separatamente — e per la
    // stessa ragione sta qui: una migrazione fuori da questo dataset smette di essere presidiata.
    '2026_08_30_100000_create_codici_ateco_table',

    // Aggiunta nella 1.11.0-beta.9: le categorie di fornitore iniziali, spostate dal seeder a una
    // migrazione perché l'amministratore ora può cancellarle. Scrive **dati**, non schema, quindi la
    // rieseguibilità qui vale doppio: senza la guardia per nome un secondo passaggio creerebbe nove
    // doppioni.
    '2026_08_31_090000_seed_categorie_fornitore',

    // Aggiunta nella beta.10, gemella di quella qui sopra: le cinque categorie dei documenti
    // passano dal seeder a una migrazione, **per lo stesso motivo e con più urgenza** — la voce
    // «Elimina» sulle categorie dell'archivio esiste da prima, quindi la risurrezione da `db:seed`
    // poteva già succedere. Anche questa scrive **dati**: senza la guardia per nome un secondo
    // passaggio creerebbe cinque doppioni.
    '2026_08_31_120000_seed_categorie_documento',

    // Aggiunta nella beta.10: la colonna `slug` sulle categorie dei documenti, con il backfill
    // della sola riga «Fatture». Ha **due** guardie separate — una sulla colonna e una sul dato —
    // perché su MySQL il DDL non è transazionale e un'esecuzione morta a metà deve poter fare al
    // secondo tentativo solo la metà che manca.
    '2026_08_31_130000_add_slug_to_categorie_documento',

    // Aggiunta nella beta.62: `immobili.numero_vani` da `integer` a `decimal(5,2)`, per le visure
    // catastali che riportano mezzi vani. È un `MODIFY` su tabella viva, quindi la stessa
    // categoria a rischio di `add_pertinenza_di_to_immobili` e
    // `rendi_facoltativi_descrizione_e_interno`. Idempotente per costruzione: ripetere un
    // `change()` verso `decimal(5,2)` su una colonna che già lo è la riscrive identica. Il
    // passaggio è un **allargamento**, quindi nessun valore esistente può essere troncato.
    '2026_08_20_120000_vani_decimali_su_immobili',

    // Aggiunte nella beta.64. La prima è la categoria a rischio più alto del dataset — tre `ALTER`
    // con chiave esterna su tabelle popolate — e su MySQL colonna e vincolo sono due statement
    // distinti: un'interruzione a metà lascia la colonna senza la chiave, ed è lo stato che le due
    // guardie separate devono saper riconoscere.
    '2026_08_22_090000_add_updated_by_to_comunicazioni_segnalazioni_documenti',

    // Colonna semplice con guardia: marca i condomini costruiti dal programma a scopo
    // dimostrativo, che devono restare eliminabili anche con movimenti contabili.
    '2026_08_23_090000_add_is_demo_to_condomini_table',

    // Colonna con foreign key autoreferenziata (self-join su budget_movements): due guardie
    // distinte, colonna e vincolo, come richiede la regola del progetto su MySQL.
    '2026_08_23_153000_add_reverses_movement_id_to_budget_movements_table',
    // La seconda tocca **dati** e non struttura — inserisce le righe delle tre preferenze nuove
    // ereditando lo stato della sorella «nuova» — e proprio per questo vale la pena presidiarla:
    // uno schema change rieseguito male fallisce rumorosamente, un inserimento no. La sua
    // idempotenza sta nell'esclusione degli utenti che la riga ce l'hanno già: al secondo giro non
    // trova nessuno, e soprattutto non riaccende quello che qualcuno avesse spento nel frattempo.
    '2026_08_22_090100_seed_preferenze_notifica_di_modifica',
]);
