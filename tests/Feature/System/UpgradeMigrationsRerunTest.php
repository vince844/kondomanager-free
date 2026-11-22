<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    // Le migrazioni delle impostazioni stanno in `database/settings/`, e nel dataset si scrivono con la cartella davanti.
    $path = str_starts_with($file, 'settings/') ? database_path("{$file}.php") : database_path("migrations/{$file}.php");

    expect(file_exists($path))->toBeTrue("Migrazione non trovata: {$file}");

    $migration = require $path;

    $tabellePrima = collect(Schema::getTableListing())->sort()->values()->all();
    // Anche gli indici (S8-28): la migrazione di luglio su `contributi_versati`, rieseguita dopo la beta.31 che
    // ha sostituito il suo vincolo con quello per persona, non trovava più il proprio indice e rifaceva il dedup
    // per unità — cancellando righe legittime — e ricreava il vincolo vecchio. Le tabelle non lo dicevano.
    $indiciPrima = collect($tabellePrima)->mapWithKeys(fn ($t) => [$t => collect(Schema::getIndexes($t))->pluck('name')->sort()->values()->all()])->all();
    // E le chiavi esterne (rilievo T16 della Fase 1-bis della 1.11.0-beta.43): su sqlite una seconda chiave uguale non cambia né
    // tabelle né indici, e su MySQL la stessa riesecuzione fallirebbe. Si confrontano colonne, tabella, colonne di arrivo e regola.
    $chiavi = fn () => collect($tabellePrima)->mapWithKeys(fn ($t) => [$t => collect(Schema::getForeignKeys($t))
        ->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . '(' . implode(',', $k['foreign_columns']) . ') ' . strtolower((string) ($k['on_delete'] ?? '')))
        ->sort()->values()->all()])->all();
    $chiaviPrima = $chiavi();

    // RefreshDatabase ha già eseguito l'intera catena: questa seconda chiamata
    // riproduce esattamente la ripresa dopo un'interruzione che ha applicato le
    // modifiche senza registrare la migrazione. Se solleva, il test fallisce qui.
    $migration->up();

    // Una riesecuzione deve essere un no-op strutturale: nessuna tabella persa,
    // nessuna tabella comparsa, nessun indice diverso.
    expect(collect(Schema::getTableListing())->sort()->values()->all())
        ->toEqual($tabellePrima)
        ->and(collect($tabellePrima)->mapWithKeys(fn ($t) => [$t => collect(Schema::getIndexes($t))->pluck('name')->sort()->values()->all()])->all())
        ->toEqual($indiciPrima)
        ->and($chiavi())->toEqual($chiaviPrima);
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

    // Aggiunta nella beta.13: allarga con `condominio_id` l'indice unico di `fatture_passive`,
    // che bloccava la stessa terna fra condomìni diversi. È la categoria a rischio più alto del
    // dataset — due DDL su una tabella popolata, e in mezzo ai due l'indice unico **non esiste**
    // — e ha una particolarità che nessun'altra qui dentro ha: l'indice vecchio regge la chiave
    // esterna su `fornitore_id`, quindi l'ordine dei due statement non è libero. Si crea prima il
    // nuovo (con `fornitore_id` in testa, così subentra) e solo dopo si toglie il vecchio;
    // l'ordine opposto dà errore 1553, incontrato scrivendola. Le due guardie leggono **le
    // colonne** degli indici, non la loro esistenza, e reggono anche lo stato «tutti e due
    // presenti» che un'interruzione fra i due statement lascia — provato a mano ricreandolo.
    '2026_09_02_090000_scope_unique_fattura_al_condominio',
    // Aggiunta nella beta.16 (Coda 116): una colonna sola su `fornitori`, per distinguere «no»
    // da «non gliel'ha mai chiesto nessuno». Rischio basso — un `ADD COLUMN` nullable su una
    // tabella popolata — ma porta un **backfill**, ed è quello a doverla far entrare qui: la
    // guardia toglie e rifà la colonna, quindi una riesecuzione ricalcola anche il backfill.
    // È idempotente perché il criterio non dipende dallo stato precedente della colonna, ma
    // solo da `soggetto_ritenuta` / `tipo_ritenuta` / `regime_forfetario`, che il backfill non
    // tocca: rieseguirla dà lo stesso insieme di fornitori marcati come già decisi.
    '2026_09_04_090000_add_ritenuta_decisa_il_to_fornitori_table',
    // Aggiunte nella beta.29 (il dettaglio del riparto persistito): una tabella nuova, `create`
    // dentro `hasTable` con le FK nella stessa `create`; e il drop di due colonne di `rate_quote`
    // mai scritte né lette, con le guardie separate per FK (MySQL, `information_schema`) e per
    // colonna, e il `dropForeign` nello stesso blueprint su SQLite — il modello è
    // `add_pertinenza_di_to_immobili`. La seconda è quella che qui conta: un drop rieseguito
    // senza guardia dà errore 1091 sullo stato parziale che un timeout lascia.
    '2026_09_14_200000_create_righe_riparto_table',
    '2026_09_14_200100_drop_riga_fattura_id_and_voce_id_from_rate_quote_table',
    // Aggiunte nella 1.11.0-beta.31 (B2, il tempo entra nel calcolo): sei migrazioni più un drop nel
    // blocco S2 del piano esecutivo, un'ottava in S5 (le colonne del passaggio su `subentri`, due FK) e una nona
    // dalla verifica di S5 (`piani_rate.esercizio_id`, con travaso). Due `create` dentro `hasTable` (`competenze_capitolo`, `subentri`);
    // colonne con `hasColumn` per colonna (fatture_passive, righe_riparto); la FK `saldi.subentro_id` con
    // le due guardie separate (colonna, poi `information_schema` su MySQL e `PRAGMA foreign_key_list` su
    // SQLite) — la categoria a rischio più alto del dataset, come `add_pertinenza_di`; l'indice non unico
    // con `Schema::hasIndex`; il drop di `tipologie_spese` con `hasColumn` **e** un conteggio che si ferma se
    // la colonna ha valori. Il travaso da `dati_extra->competenza` scrive solo dove le colonne nuove sono
    // nulle: rieseguito non travasa due volte (invariante 23 del progetto sul subentro).
    '2026_09_18_200000_add_competenza_to_fatture_passive_table',
    '2026_09_18_200100_create_competenze_capitolo_table',
    '2026_09_18_200200_create_subentri_table',
    '2026_09_18_200300_add_subentro_id_to_saldi_table',
    '2026_09_18_200400_add_index_periodi_to_anagrafica_immobile_table',
    '2026_09_18_200500_add_competenza_to_righe_riparto_table',
    '2026_09_18_200600_drop_tipologie_spese_from_anagrafica_immobile_table',
    '2026_09_19_180000_add_dettagli_passaggio_to_subentri_table',
    // La nona (verifica S5, R8): colonna + FK + travaso solo sui nulli dove la gestione ha un esercizio solo.
    '2026_09_20_090000_add_esercizio_id_to_piani_rate_table',
    // La decima (S6): unique nuovo su contributi_versati con guardie `getIndexes`, due colonne su subentri.
    '2026_09_20_100000_s6_contributi_per_persona_e_annullamento_conguaglio',
    '2026_09_20_180000_add_titolarita_tratto_to_righe_riparto_table',
    // Coda 165 (beta.36): colonna più chiave esterna della tabella verso se stessa — due statement su MySQL.
    '2026_09_27_090000_add_fattura_rettificata_id_to_fatture_passive_table',
    // Beta.37: quattro colonne su subentri (annullamento e registro del passaggio) e la chiave esterna verso users.
    '2026_09_28_100000_add_annullamento_to_subentri_table',
    // Beta.43 (decisione 55): la riga di titolarità da cui viene ogni riga di riparto — colonna più chiave esterna, due
    // statement su MySQL. Nessun travaso: i piani di prima restano senza legame, e il conguaglio lo deduce al volo.
    '2026_10_04_120000_add_anagrafica_immobile_id_to_righe_riparto_table',
    // La frase del promemoria del credito: riscrive **dati** (`eventi.description`) e nessuna struttura, quindi la riesecuzione è un
    // no-op strutturale. La sua idempotenza sta nella `WHERE` sulla frase vecchia: al secondo giro non trova righe.
    '2026_10_17_100000_riscrivi_descrizioni_promemoria_credito',
    // Beta.53 (Coda 244): il passaggio a cui l'amministratore lega una riga del Wallet — colonna più chiave esterna verso `subentri`, due
    // statement su MySQL. Nessun travaso: le righe di prima restano senza legame.
    '2026_10_18_100000_add_passaggio_id_to_saldi_table',
    // Beta.59 (Coda 414): una colonna JSON nullable su `piani_rate`, senza chiave esterna e senza travaso — la guardia è una, sulla colonna.
    '2026_10_30_100000_add_quote_non_assegnate_to_piani_rate_table',
    // Beta.10, in dataset dalla beta.60 (Coda 436): la tabella dei legami documento-categoria, il travaso e poi la chiave esterna e la colonna `documenti.category_id`
    // tolte con due guardie separate (su MySQL sono due statement). Lo stato a metà, con il suo travaso, lo prova il test sotto.
    '2026_08_31_140000_documenti_in_piu_categorie',
    // 1.12.0-beta.1 (Coda 438): l'esercizio del già versato — colonna più chiave esterna verso `esercizi`, due statement su MySQL. Nessun travaso: le righe
    // già salvate restano vuote (valgono per tutti i piani della voce, decisione 187). Lo stato a metà lo prova il test sotto.
    '2026_11_04_100000_add_esercizio_id_to_contributi_versati_table',
    // 1.12.0-beta.2 (decisione 200): il registro dei documenti mandati a una persona — la tabella, due indici e quattro chiavi esterne, sette statement
    // su MySQL (la `create table` porta solo le colonne), ognuno con la sua guardia. Gli stati a metà li provano i due test sotto.
    '2026_11_05_100000_create_registro_invii_table',
    // 1.12.0-beta.3 (decisioni 217 e 218): l'imputazione dell'incasso, la sua nota e la natura dello storno — tre colonne su `scritture_contabili`,
    // tre statement su MySQL, una guardia per colonna. Nessun travaso: gli incassi e gli storni di prima restano senza. Lo stato a metà lo prova il test sotto.
    '2026_11_06_100000_add_imputazione_e_natura_storno_to_scritture_contabili_table',
    // 1.12.0-beta.4: l'origine del pregresso dichiarata sul saldo — quattro colonne e due chiavi esterne su `saldi`, sei statement su MySQL, una
    // guardia per ognuno. Nessun travaso: i saldi già scritti restano «origine non dichiarata». Lo stato a metà lo prova il test sotto.
    '2026_11_07_100000_add_origine_dichiarata_to_saldi_table',
    // 1.12.0-beta.4: la dispensa dei sei mesi — tre tabelle, due indici unici e sette chiavi esterne, ognuno con la sua guardia. Gli stati a metà
    // li prova il test sotto.
    '2026_11_07_100100_create_dispense_sei_mesi_tables',
    // 1.12.0-beta.4: l'accordo di rientro come registro — due tabelle, due indici e sette chiavi esterne, ognuno con la sua guardia. Lo stato a
    // metà lo prova il test sotto.
    '2026_11_07_140000_create_accordi_rientro_tables',
    // 1.12.0-beta.4: i modelli delle lettere — una tabella, un indice e tre chiavi esterne, cinque statement su MySQL, ognuno con la sua guardia.
    // Lo stato a metà lo prova il test sotto.
    '2026_11_07_160000_create_modelli_testo_table',
    // 1.12.0-beta.6: le carte dello studio — una tabella, le colonne una per una e un indice, ognuno con la sua guardia, e la «Carta dello studio»
    // scritta solo se la tabella è vuota. Lo stato a metà lo prova il test sotto.
    '2026_11_08_210000_create_carte_stampa_table',
    // 1.12.0-beta.8 (Coda 460, decisioni 238 e 241): gli allegati di segnalazioni e comunicazioni — due colonne per tabella su `segnalazioni` e
    // `comunicazioni`, una guardia per colonna; due colonne e una chiave esterna su `documenti`, tre statement con tre guardie; le quattro preferenze
    // di notifica nuove, scritte solo per chi non le ha già. Gli stati a metà li prova `tests/Feature/Allegati/AllegatiMigrazioniTest.php`.
    '2026_11_08_200000_add_allegati_to_segnalazioni_e_comunicazioni',
    '2026_11_08_200100_add_moderazione_to_documenti',
    '2026_11_08_200300_seed_preferenze_notifica_allegati',
    // L'autore di un documento che si elimina lascia vuoto: la chiave in cascata tolta, la colonna vuotabile, la chiave nuova, tre statement su MySQL
    // con tre guardie (l'ordine non è libero: una chiave «lascia vuoto» su una colonna `NOT NULL` è rifiutata). Gli stati a metà li prova il test sotto.
    '2026_11_14_100000_documenti_created_by_null_on_delete',
    // La bozza della nota sintetica, una per esercizio: la tabella, un indice unico e tre chiavi esterne, ognuno con la sua guardia.
    '2026_11_14_100100_create_note_sintetiche_table',
    // Le versioni del rendiconto: la tabella, un indice unico e cinque chiavi esterne, ognuno con la sua guardia. Le due chiavi verso l'esercizio e il
    // condominio non cancellano in cascata. Gli stati a metà li prova il test sotto.
    '2026_11_14_100200_create_rendiconto_versioni_table',
    // La natura del piano rate («addebiti» per il piano degli addebiti a singole persone): una colonna vuotabile, una guardia. La prova su MySQL è in
    // `tests/Unit/Gestionale/NaturaPianiRateMigrazioneMysqlTest.php`.
    '2026_11_15_100000_add_natura_to_piani_rate_table',
    // Le regole del recupero dei crediti di ogni condominio, con la storia: la tabella, un indice unico e due chiavi esterne, ognuno con la sua
    // guardia. Lo stato a metà lo prova il test sotto; su MySQL `tests/Unit/Gestionale/RegoleRecuperoMigrazioneMysqlTest.php`.
    '2026_11_15_110000_create_regole_recupero_table',
    // Il calendario dell'accordo di rientro e la regola di decadenza: la tabella delle rate, il suo indice unico, la sua chiave esterna e quattro
    // colonne sull'accordo, ognuno con la sua guardia. Lo stato a metà lo prova il test sotto; su MySQL
    // `tests/Unit/Gestionale/CalendarioAccordoMigrazioneMysqlTest.php`.
    '2026_11_15_120000_add_calendario_e_decadenza_to_accordi_rientro',
    // Le risposte dell'amministratore ai promemoria (i sei mesi per agire, i 180 giorni del rendiconto, i compiti dell'agenda) e la scelta della dispensa sul
    // sollecito in tesoreria: la tabella, l'indice unico, due indici, sette chiavi esterne e la colonna della dispensa, ognuno con la sua guardia. Lo stato a metà
    // lo prova il test sotto.
    '2026_11_15_140000_create_risposte_promemoria_table',
    // Le impostazioni del condominio (una riga per condominio, le colonne con il loro tipo): la tabella, l'indice unico e le due chiavi esterne, ognuno con la
    // sua guardia. Lo stato a metà lo prova il test sotto.
    '2026_11_15_200000_create_impostazioni_condominio_table',
    // La correzione una volta sola delle rate segnalate rimaste «in verifica» con il compito chiuso: solo dati, nessuna struttura; rieseguita non trova
    // niente. Il suo effetto lo prova `tests/Feature/Gestionale/BonificoSegnalatoChiusoTest.php`.
    '2026_11_15_210000_sblocca_le_rate_segnalate_con_il_compito_chiuso',
    // I compiti dell'Inbox delle segnalazioni già aperte o in lavorazione: solo dati, nessuna struttura; rieseguita non fa doppioni. Il suo effetto lo prova
    // `tests/Feature/Dashboard/ActionInboxSegnalazioniTest.php`.
    '2026_11_15_230000_crea_i_compiti_delle_segnalazioni_aperte',
    // 1.12.0-beta.11 (Coda 559, i commenti alle comunicazioni): la colonna «commenti_moderati» su `comunicazioni` e quella su `segnalazioni`, una guardia per
    // colonna; la colonna «discussione_user_id» su `commenti` e la sua chiave esterna, due statement con due guardie; i commenti spenti sulle comunicazioni
    // che ci sono già (solo dati, rieseguita non trova niente da cambiare); le preferenze di notifica nuove, scritte solo per chi non le ha già. Gli stati a
    // metà li prova `tests/Feature/CommentiComunicazioni/CommentiMigrazioniTest.php`, che prova anche le tre migrazioni delle impostazioni.
    '2026_11_10_100000_add_commenti_moderati_to_comunicazioni',
    '2026_11_10_100100_add_discussione_user_id_to_commenti',
    '2026_11_10_100200_spegni_commenti_comunicazioni_esistenti',
    '2026_11_10_100400_seed_preferenze_notifica_commenti_comunicazioni',
    '2026_11_11_110000_add_commenti_moderati_to_segnalazioni',
    '2026_11_11_130000_seed_preferenze_notifica_commento_modificato',
    // 1.12.0-beta.11 (decisione 450): sull'accordo di rientro la scelta degli interessi sulla parte della rata fuori dall'accordo, una colonna con la sua
    // guardia; gli accordi che ci sono prendono il di serie dal valore della colonna.
    '2026_11_17_100000_add_interessi_parte_fuori_to_accordi_rientro',
    // 1.12.0-beta.11 (decisioni 445 e 449): i compiti «da moderare» dell'Action inbox — chiude i compiti generici di prima e scrive quello unico di ogni elemento
    // con qualcosa in attesa: solo dati, nessuna struttura; rieseguita non cambia niente. Il suo effetto lo prova `tests/Feature/Dashboard/ModerazioneMigrazioneTest.php`.
    '2026_11_17_120000_crea_i_compiti_da_moderare',
    // 1.12.0-beta.11 (decisioni 450 e 454): sull'accordo di rientro il registro delle scelte cambiate dopo la registrazione e la chiusura per la successione,
    // due colonne con due guardie; poi, solo dati, le successioni con l'arretrato agli eredi registrate prima (i promemoria degli accessori passati agli eredi
    // seguono le quote, l'accordo del defunto prende il di serie): rieseguita non cambia niente. Il suo effetto lo prova
    // `tests/Feature/Gestionale/SuccessioniRegistratePrimaMigrazioneTest.php`.
    '2026_11_17_140000_add_registro_e_successione_to_accordi_rientro',
    '2026_11_17_150000_riallinea_le_successioni_registrate_prima',
    // 1.12.0-beta.12 (decisione 483, Coda 663): sul passaggio la scelta della successione per gli interessi del defunto dopo il decesso, una colonna con la
    // sua guardia; poi, solo dati, le successioni già registrate prendono il di serie della loro strada dell'arretrato (solo i passaggi senza la scelta):
    // rieseguita non cambia niente. Il suo effetto lo prova `tests/Feature/Gestionale/InteressiDelDefuntoDopoIlDecessoTest.php`.
    '2026_11_18_170000_add_interessi_del_defunto_to_subentri',
    '2026_11_18_170100_riallinea_gli_interessi_delle_successioni_registrate',
    // 1.12.0-beta.12 (decisione 483 (3)): le preferenze di notifica nuove «Commento eliminato» (segnalazioni e comunicazioni) nascono come «Commento nascosto»,
    // solo dati, scritte solo per chi non le ha già: rieseguita non cambia niente. Il suo effetto lo prova `tests/Feature/Commenti/AvvisoCommentoEliminatoTest.php`.
    '2026_11_18_180000_seed_preferenze_notifica_commento_eliminato',
    // 1.12.0-beta.12 (decisioni 489, 493 e 494, gli interessi sul debito ereditato): le otto scelte degli eredi su `impostazioni_condominio` e la scelta per
    // una sola successione `subentri.interessi_eredi`, una guardia per colonna. Il loro effetto lo prova `tests/Feature/Gestionale/InteressiSulDebitoEreditatoTest.php`.
    '2026_11_18_200000_add_interessi_degli_eredi',
    // 1.12.0-beta.12 (Coda 687, decisione 507, la fattura ricorda la sua gestione): la colonna `gestione_id` su `fatture_passive` e la sua chiave esterna, due
    // statement con due guardie; poi, solo dati, la colonna riempita dalla scrittura di competenza (o dalle voci) solo dove è vuota: rieseguita non trova
    // niente. Il loro effetto, e gli stati a metà, li prova `tests/Feature/Gestionale/FatturaRicordaLaGestioneTest.php`.
    '2026_11_18_210000_add_gestione_id_to_fatture_passive',
    '2026_11_18_210100_riempi_gestione_delle_fatture_passive',
    // 1.12.0-beta.12 (Coda 589, decisioni 290–292, la pubblicazione programmata delle comunicazioni): le tre colonne `programmata_per`, `programmata_da` e
    // `pubblicata_il` su `comunicazioni`, una guardia per colonna; poi, solo dati, `pubblicata_il` riempita con `created_at` per le comunicazioni già
    // pubblicate e approvate e ancora vuote (rieseguita non trova niente); poi la preferenza «Comunicazione programmata pubblicata», scritta solo per chi
    // non l'ha già, con lo stato di «Comunicazione approvata». Il loro effetto lo prova `tests/Feature/ComunicazioniProgrammate/ProgrammateDatabaseTest.php`.
    '2026_11_12_100000_add_pubblicazione_programmata_to_comunicazioni',
    '2026_11_12_100100_riempi_pubblicata_il_delle_comunicazioni',
    '2026_11_12_100200_seed_preferenza_notifica_comunicazione_programmata',
    // 1.12.0-beta.12 (Coda 591, decisioni 294, 295, 301–305, l'assegnazione delle segnalazioni): `assigned_to` svuotato la prima volta (solo finché manca
    // `assegnata_il`: rieseguita non tocca le assegnazioni nuove) e le due colonne `assegnata_da` e `assegnata_il` su `segnalazioni`, una guardia per colonna;
    // poi la preferenza «Segnalazione assegnata», scritta solo per chi non l'ha già, con lo stato di «Nuova segnalazione». Il loro effetto lo prova
    // `tests/Feature/AssegnazioneSegnalazioni/AssegnazioneDatabaseTest.php`.
    '2026_11_12_110000_add_assegnazione_to_segnalazioni',
    '2026_11_12_110200_seed_preferenza_notifica_segnalazione_assegnata',
    // La riga «passo_proposto» delle impostazioni generali si toglie (la scelta è del condominio): `deleteIfExists`, rieseguita non trova niente. Il suo
    // effetto lo prova `tests/Feature/Impostazioni/ImpostazioniPassoPropostoTest.php`.
    'settings/2026_11_15_200100_remove_passo_proposto_from_general_settings',
    // 1.12.0-beta.12 (Coda 591): i due interruttori dell'assegnazione nelle impostazioni generali, spenti di serie, ognuno scritto solo se non c'è;
    // rieseguita non riscrive una scelta già fatta. Il loro effetto lo prova `tests/Feature/AssegnazioneSegnalazioni/AssegnazioneDatabaseTest.php`.
    'settings/2026_11_12_110100_add_assegnazione_segnalazioni_to_general_settings',
    // 1.12.0-beta.12 (decisione 490): «Kondomanager» di serie diventa «KondoManager» nelle impostazioni, solo dove vale esattamente quello; rieseguita non cambia
    // niente. Il suo effetto lo prova `tests/Feature/Mail/NomeNelleEmailTest.php`.
    'settings/2026_11_18_190000_scrivi_kondomanager_con_la_emme_maiuscola',
    // 1.12.0-beta.13 (Coda 679, registro delle modifiche): le quattro tabelle del registro (nessuna chiave esterna), ognuna, ogni indice e la riga della
    // catena con la sua guardia; rieseguita non tocca la catena già avanzata. Il loro effetto lo provano i test di `tests/Feature/RegistroModifiche/`.
    '2026_11_19_100000_create_registro_modifiche_tables',
    // 1.12.0-beta.13 (Coda 679, §7): `eventi.created_by` facoltativa, per i promemoria del programma; cambia la colonna solo se è ancora obbligatoria.
    // Il suo effetto lo prova `tests/Feature/Gestionale/SyncF24WithPagamentoTest.php`.
    '2026_11_19_100100_rendi_facoltativo_l_autore_degli_eventi',
    // 1.12.0-beta.13 (Coda 679, §9, le versioni di prima dei documenti): la tabella `documenti_versioni`, l'indice e le due chiavi esterne, ognuno con la
    // sua guardia. Il suo effetto lo prova `tests/Feature/RegistroModifiche/VersioniDeiDocumentiTest.php`.
    '2026_11_19_110000_create_documenti_versioni_table',
    // 1.12.0-beta.13 (Coda 679, §11, decisione 518 (1)): la durata delle righe del registro, di serie 10 anni, scritta solo se non c'è; rieseguita non
    // riscrive una scelta già fatta. Il suo effetto lo prova `tests/Feature/RegistroModifiche/ConservazioneESospensioneTest.php`.
    'settings/2026_11_19_120000_create_registro_modifiche_settings',
    // 1.12.0-beta.13 (Coda 679, l'assemblea, la cerniera e la convocazione): §1.3 del progetto della tappa 2. Le tabelle dell'assemblea, della delibera e dei
    // convocati hanno anche un `it()` che le interrompe dopo le tabelle (in fondo al file); il loro uso lo provano i test di `tests/Feature/Assemblee/Motore/`.
    '2026_11_19_130000_create_assemblee_tables',
    '2026_11_19_130100_create_delibere_tables',
    '2026_11_19_130200_create_assemblee_memo_table',
    '2026_11_19_130300_add_tabella_proprieta_to_condomini_palazzine_scale',
    '2026_11_19_130400_add_mezzo_convocazione_to_anagrafiche',
    '2026_11_19_130500_add_esito_to_registro_invii',
    '2026_11_19_130600_create_assemblee_convocati_table',
    '2026_11_19_130700_create_preventivo_versioni_table',
    'settings/2026_11_19_130800_create_assemblee_settings',
    // 1.12.0-beta.13 (decisione 572, le caselle di posta dello studio): la casella PEC nel suo gruppo, ogni proprietà scritta solo se manca; l'ultima
    // prova e il giorno dell'impostazione della posta, vuoti se mancano. Rieseguite non toccano una casella già impostata. Il loro effetto lo prova
    // `tests/Feature/Impostazioni/CaselleDelloStudioTest.php`.
    'settings/2026_11_19_140000_create_pec_settings',
    'settings/2026_11_19_140100_add_prova_to_mail_settings',
    // 1.12.0-beta.13 (Coda 679, la sala): le colonne della seduta (apertura, esito, presidente, fotografia), le sei tabelle della sala, la scelta delle teste
    // del condominio, le due scelte dello studio. Le tabelle e le colonne della seduta hanno anche un `it()` che le interrompe (in fondo al file); il loro
    // uso lo provano i test di `tests/Feature/Assemblee/Sala/`.
    '2026_11_20_100000_add_sala_to_assemblee_sedute',
    '2026_11_20_100100_create_assemblee_sala_tables',
    '2026_11_20_100200_add_teste_della_sala_to_impostazioni_condominio',
    'settings/2026_11_20_100300_add_sala_to_assemblee_settings',
    // 1.12.0-beta.13 (Coda 679, la sala): le note del presidente su un voto, che vanno nel verbale. Ha anche un `it()` che la interrompe (in fondo al
    // file); il suo uso lo prova `tests/Feature/Assemblee/Sala/NoteDelPresidenteTest.php`.
    '2026_11_20_100400_add_note_to_assemblee_votazioni',
    // 1.12.0-beta.13 (Coda 679, la sala): quando il foglio della sala è stato stampato l'ultima volta, per «ristampa il foglio» quando una delega cambia
    // dopo. Una colonna, una guardia; il suo uso lo prova `tests/Feature/Assemblee/Sala/RegoleDellaSalaTest.php`.
    '2026_11_20_100500_add_foglio_stampato_il_to_assemblee',
    // 1.12.0-beta.13 (Coda 679, i passi): quando i documenti sono stati guardati, con «Avanti: Convocazione». Una colonna, una guardia; il suo uso lo
    // prova `tests/Feature/Assemblee/Pagine/PassiDellAssembleaTest.php`.
    '2026_11_20_100600_add_documenti_visti_il_to_assemblee',
    // 1.12.0-beta.13 (Coda 679, il verbale): il registro dei verbali di ogni perimetro con il punto di partenza, il verbale, il suo testo per parte e le sue
    // versioni, il registro di nomina e revoca; e il giorno in cui l'annotazione di una seduta deserta è incollata nel libro di carta. Hanno anche un `it()`
    // che le interrompe (in fondo al file); il loro uso lo provano i test di `tests/Feature/Assemblee/Verbale/`.
    '2026_11_20_110000_create_assemblee_verbali_tables',
    '2026_11_20_110100_add_nel_libro_to_assemblee_sedute',
    // 1.12.0-beta.13 (Coda 679, il seguito delle delibere): le cose da fare dopo una delibera (la tabella, gli indici e le chiavi esterne, ognuno con la sua
    // guardia) e sulla delibera la conferma del riepilogo e i suoi dati (una guardia per colonna e una per la chiave). Hanno anche un `it()` che le
    // interrompe (in fondo al file); il loro uso lo provano i test di `tests/Feature/Assemblee/Seguito/`.
    '2026_11_21_100000_create_delibere_cose_da_fare_table',
    '2026_11_21_100100_add_seguito_to_delibere',
    // 1.12.0-beta.13 (Coda 679, la convocazione): «Promemoria per email delle convocazioni da spedire», acceso di serie; rieseguita non riaccende un
    // interruttore spento. Il suo effetto lo prova `tests/Feature/Impostazioni/PromemoriaDelleConvocazioniTest.php`.
    'settings/2026_11_21_090000_add_promemoria_convocazioni_to_mail_settings',
    // 1.12.0-beta.13 (Coda 688, i lavori urgenti sulla scheda delle fatture): il giorno dei lavori nel piano rate, una data che può essere vuota. Una
    // colonna, una guardia; il suo uso lo prova `tests/Feature/Gestionale/UrgenzaSullaSchedaDelleFattureTest.php`.
    '2026_11_21_130000_add_giorno_dei_lavori_to_piani_rate_table',
    // 1.12.0-beta.13 (decisione 647, il mezzo nella riga della convocazione): la tabella dei mezzi scelti per assemblea, con l'indice unico e le tre chiavi; ha
    // anche un `it()` che la interrompe dopo la tabella (in fondo al file); il suo uso lo prova `tests/Feature/Assemblee/Convocazione/MezzoNellaRigaTest.php`.
    '2026_11_21_140000_create_assemblee_mezzi_scelti_table',
    // 1.12.0-beta.13 (decisione 664 (6), rilievo C53, i contitolari presenti tutti e due): chi entra con chi rappresenta la testa, presente senza voto. Una
    // colonna, una guardia; il suo uso lo prova `tests/Feature/Assemblee/Sala/SalaApertaTest.php`.
    '2026_11_21_150000_add_insieme_to_assemblee_presenze',
]);

it('la migrazione dei modelli delle lettere interrotta dopo la tabella e prima dell\'indice e delle chiavi si riprende e li mette, uno per nome', function () {
    $path = database_path('migrations/2026_11_07_160000_create_modelli_testo_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_160000_create_modelli_testo_table');
    $migration = require $path;
    $stato = fn () => [
        'colonne' => collect(Schema::getColumnListing('modelli_testo'))->sort()->values()->all(),
        'indici' => collect(Schema::getIndexes('modelli_testo'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'modelli_testo'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('modelli_testo'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $atteso = [
        'colonne' => ['ambito', 'condominio_id', 'created_at', 'created_by', 'id', 'nome', 'testo', 'tipo', 'updated_at', 'updated_by'],
        'indici' => ['modelli_testo_ambito_tipo_condominio_index'],
        'chiavi' => ['condominio_id>condomini cascade', 'created_by>users set null', 'updated_by>users set null'],
    ];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella: l'indice e due chiavi mancano.
    Schema::table('modelli_testo', function ($table) {
        $table->dropForeign(['condominio_id']);
        $table->dropForeign(['updated_by']);
    });
    Schema::table('modelli_testo', fn ($table) => $table->dropIndex('modelli_testo_ambito_tipo_condominio_index'));
    expect($stato()['indici'])->toBe([])->and($stato()['chiavi'])->toBe(['created_by>users set null']);

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('modelli_testo'))->toBeFalse();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione dell\'accordo di rientro interrotta dopo le tabelle e prima degli indici e delle chiavi si riprende e li mette, uno per nome', function () {
    $path = database_path('migrations/2026_11_07_140000_create_accordi_rientro_tables.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_140000_create_accordi_rientro_tables');
    $migration = require $path;
    $tabelle = ['accordi_rientro', 'accordo_rientro_rate'];
    $stato = fn () => collect($tabelle)->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'accord'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $atteso = [
        'accordi_rientro' => ['indici' => ['accordi_rientro_persona_index'], 'chiavi' => ['anagrafica_id>anagrafiche cascade', 'chiuso_da>users set null',
            'condominio_id>condomini cascade', 'registrato_da>users set null']],
        'accordo_rientro_rate' => ['indici' => ['accordo_rientro_rata_unica'], 'chiavi' => ['accordo_rientro_id>accordi_rientro cascade', 'immobile_id>immobili set null',
            'rata_id>rate cascade']],
    ];
    expect($stato())->toBe($atteso);

    // Interrotta dopo le due tabelle: un indice e tre chiavi mancano.
    Schema::table('accordo_rientro_rate', function ($table) {
        $table->dropForeign(['accordo_rientro_id']);
        $table->dropForeign(['rata_id']);
    });
    Schema::table('accordo_rientro_rate', fn ($table) => $table->dropUnique('accordo_rientro_rata_unica'));
    Schema::table('accordi_rientro', fn ($table) => $table->dropForeign(['anagrafica_id']));
    expect($stato()['accordo_rientro_rate'])->toBe(['indici' => [], 'chiavi' => ['immobile_id>immobili set null']]);
    // E la tabella delle rate creata senza la colonna del residuo del giorno dell'accordo.
    Schema::table('accordo_rientro_rate', fn ($table) => $table->dropColumn('residuo_iniziale'));
    expect(Schema::hasColumn('accordo_rientro_rate', 'residuo_iniziale'))->toBeFalse();

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso)
        ->and(Schema::hasColumn('accordo_rientro_rate', 'residuo_iniziale'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(collect($tabelle)->filter(fn ($t) => Schema::hasTable($t))->all())->toBe([]);
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione dell\'origine dei saldi interrotta dopo la prima colonna si riprende e mette le altre e una chiave sola per colonna; rifatta dopo il ritorno indietro le rimette', function () {
    $path = database_path('migrations/2026_11_07_100000_add_origine_dichiarata_to_saldi_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_100000_add_origine_dichiarata_to_saldi_table');
    $migration = require $path;
    $tutte = ['esercizio_origine_id', 'origine_dichiarata', 'origine_dichiarata_da', 'origine_dichiarata_il'];
    $colonne = fn () => collect(Schema::getColumnListing('saldi'))->filter(fn ($c) => in_array($c, $tutte, true))->sort()->values()->all();
    $chiavi = fn () => collect(Schema::getForeignKeys('saldi'))->filter(fn ($k) => array_intersect($k['columns'], $tutte) !== [])
        ->mapWithKeys(fn ($k) => [implode(',', $k['columns']) => $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete'])])->sortKeys()->all();
    $attese = ['esercizio_origine_id' => 'esercizi set null', 'origine_dichiarata_da' => 'users set null'];
    expect($colonne())->toBe($tutte)->and($chiavi())->toBe($attese);

    // Interrotta dopo la prima colonna: le chiavi e le altre tre mancano.
    Schema::table('saldi', function ($table) {
        $table->dropForeign(['esercizio_origine_id']);
        $table->dropForeign(['origine_dichiarata_da']);
    });
    Schema::table('saldi', fn ($table) => $table->dropColumn(['origine_dichiarata', 'origine_dichiarata_da', 'origine_dichiarata_il']));
    expect($colonne())->toBe(['esercizio_origine_id'])->and($chiavi())->toBe([]);

    $migration->up();
    $migration->up();
    expect($colonne())->toBe($tutte)->and($chiavi())->toBe($attese);

    $migration->down();
    $migration->down();
    expect($colonne())->toBe([]);

    $migration->up();
    expect($colonne())->toBe($tutte)->and($chiavi())->toBe($attese);
});

it('la migrazione della dispensa dei sei mesi interrotta dopo le tabelle e prima degli indici e delle chiavi si riprende e li mette, uno per nome', function () {
    $path = database_path('migrations/2026_11_07_100100_create_dispense_sei_mesi_tables.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_100100_create_dispense_sei_mesi_tables');
    $migration = require $path;
    $tabelle = ['dispense_sei_mesi', 'dispensa_sei_mesi_esercizio', 'dispensa_sei_mesi_anagrafica'];
    $stato = fn () => collect($tabelle)->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dispensa_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $atteso = [
        'dispense_sei_mesi' => ['indici' => [], 'chiavi' => ['condominio_id>condomini cascade', 'registrata_da>users set null', 'revocata_da>users set null']],
        'dispensa_sei_mesi_esercizio' => ['indici' => ['dispensa_esercizio_unica'], 'chiavi' => ['dispensa_id>dispense_sei_mesi cascade', 'esercizio_id>esercizi cascade']],
        'dispensa_sei_mesi_anagrafica' => ['indici' => ['dispensa_anagrafica_unica'], 'chiavi' => ['anagrafica_id>anagrafiche cascade', 'dispensa_id>dispense_sei_mesi cascade']],
    ];
    expect($stato())->toBe($atteso);

    Schema::table('dispensa_sei_mesi_esercizio', function ($table) {
        $table->dropForeign(['dispensa_id']);
        $table->dropForeign(['esercizio_id']);
    });
    Schema::table('dispensa_sei_mesi_esercizio', fn ($table) => $table->dropUnique('dispensa_esercizio_unica'));
    Schema::table('dispense_sei_mesi', fn ($table) => $table->dropForeign(['condominio_id']));
    expect($stato()['dispensa_sei_mesi_esercizio'])->toBe(['indici' => [], 'chiavi' => []]);

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(collect($tabelle)->filter(fn ($t) => Schema::hasTable($t))->all())->toBe([]);
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione dell\'imputazione e della natura dello storno interrotta dopo la prima colonna si riprende e mette le altre, una per nome; rifatta dopo il ritorno indietro le rimette', function () {
    $path = database_path('migrations/2026_11_06_100000_add_imputazione_e_natura_storno_to_scritture_contabili_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_06_100000_add_imputazione_e_natura_storno_to_scritture_contabili_table');
    $migration = require $path;
    $colonne = fn () => collect(Schema::getColumnListing('scritture_contabili'))->filter(fn ($c) => in_array($c, ['imputazione', 'imputazione_nota', 'natura_storno'], true))->sort()->values()->all();
    $tutte = ['imputazione', 'imputazione_nota', 'natura_storno'];
    expect($colonne())->toBe($tutte);

    // Interrotta dopo la prima: le altre due mancano.
    Schema::table('scritture_contabili', fn ($table) => $table->dropColumn(['imputazione_nota', 'natura_storno']));
    $migration->up();
    $migration->up();
    expect($colonne())->toBe($tutte);

    $migration->down();
    $migration->down();
    expect($colonne())->toBe([]);

    $migration->up();
    expect($colonne())->toBe($tutte);
});

it('la migrazione del registro degli invii interrotta dopo la tabella e prima degli indici si riprende e li mette, uno per nome', function () {
    $migration = require database_path('migrations/2026_11_05_100000_create_registro_invii_table.php');
    $indici = fn () => collect(Schema::getIndexes('registro_invii'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'registro_invii_'))->sort()->values()->all();
    $attesi = ['registro_invii_condominio_tipo_index', 'registro_invii_documento_index'];
    expect($indici())->toBe($attesi);

    Schema::table('registro_invii', function ($table) {
        $table->dropIndex('registro_invii_documento_index');
        $table->dropIndex('registro_invii_condominio_tipo_index');
    });
    $migration->up();
    $migration->up();

    expect($indici())->toBe($attesi);
});

it('la migrazione del registro degli invii interrotta dopo la tabella e prima delle chiavi esterne si riprende e mette una chiave sola per colonna', function () {
    $path = database_path('migrations/2026_11_05_100000_create_registro_invii_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_05_100000_create_registro_invii_table');
    $migration = require $path;
    $chiavi = fn () => collect(Schema::getForeignKeys('registro_invii'))
        ->mapWithKeys(fn ($k) => [implode(',', $k['columns']) => $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete'])])
        ->sortKeys()->all();
    $attese = [
        'anagrafica_id' => 'anagrafiche set null',
        'condominio_id' => 'condomini cascade',
        'emesso_da'     => 'users set null',
        'mail_log_id'   => 'mail_logs set null',
    ];
    expect($chiavi())->toBe($attese);

    Schema::table('registro_invii', function ($table) {
        foreach (['condominio_id', 'anagrafica_id', 'mail_log_id', 'emesso_da'] as $colonna) {
            $table->dropForeign([$colonna]);
        }
    });
    expect($chiavi())->toBe([]);

    $migration->up();
    $migration->up();
    expect($chiavi())->toBe($attese)
        ->and(collect(Schema::getForeignKeys('registro_invii')))->toHaveCount(4);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('registro_invii'))->toBeFalse();
    $migration->up();
    expect($chiavi())->toBe($attese);
});

it('Coda 438 — la migrazione dell\'esercizio del già versato interrotta fra i due statement (colonna sì, chiave esterna no) si riprende e mette una chiave sola, che azzera l\'esercizio', function () {
    $path = database_path('migrations/2026_11_04_100000_add_esercizio_id_to_contributi_versati_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_04_100000_add_esercizio_id_to_contributi_versati_table');
    $migration = require $path;
    $legame = fn () => collect(Schema::getForeignKeys('contributi_versati'))->filter(fn ($k) => $k['columns'] === ['esercizio_id'])->values();
    Schema::table('contributi_versati', fn ($table) => $table->dropForeign(['esercizio_id']));
    expect($legame())->toHaveCount(0)
        ->and(Schema::hasColumn('contributi_versati', 'esercizio_id'))->toBeTrue();

    $migration->up();
    $migration->up();

    expect($legame())->toHaveCount(1)
        ->and($legame()->first()['foreign_table'])->toBe('esercizi')
        ->and($legame()->first()['foreign_columns'])->toBe(['id'])
        ->and(strtolower((string) $legame()->first()['on_delete']))->toBe('set null');

    // E il ritorno indietro, due volte, toglie colonna e chiave; rifatta, le rimette.
    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('contributi_versati', 'esercizio_id'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('contributi_versati', 'esercizio_id'))->toBeTrue()
        ->and($legame())->toHaveCount(1);
});

it('decisione 55 — la migrazione del legame interrotta fra i due statement (colonna sì, chiave esterna no) si riprende e mette una chiave sola, che azzera il legame', function () {
    $migration = require database_path('migrations/2026_10_04_120000_add_anagrafica_immobile_id_to_righe_riparto_table.php');
    Schema::table('righe_riparto', fn ($table) => $table->dropForeign(['anagrafica_immobile_id']));
    $legame = fn () => collect(Schema::getForeignKeys('righe_riparto'))->filter(fn ($k) => $k['columns'] === ['anagrafica_immobile_id'])->values();
    expect($legame())->toHaveCount(0)
        ->and(Schema::hasColumn('righe_riparto', 'anagrafica_immobile_id'))->toBeTrue();

    $migration->up();
    $migration->up();

    expect($legame())->toHaveCount(1)
        ->and($legame()->first()['foreign_table'])->toBe('anagrafica_immobile')
        ->and(strtolower((string) $legame()->first()['on_delete']))->toBe('set null');
});

it('la migrazione del passaggio delle righe del Wallet interrotta fra i due statement (colonna sì, chiave esterna no) si riprende e mette una chiave sola, restrict', function () {
    $path = database_path('migrations/2026_10_18_100000_add_passaggio_id_to_saldi_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_10_18_100000_add_passaggio_id_to_saldi_table');
    $migration = require $path;
    $legame = fn () => collect(Schema::getForeignKeys('saldi'))->filter(fn ($k) => $k['columns'] === ['passaggio_id'])->values();
    Schema::table('saldi', fn ($table) => $table->dropForeign(['passaggio_id']));
    expect($legame())->toHaveCount(0)
        ->and(Schema::hasColumn('saldi', 'passaggio_id'))->toBeTrue();

    $migration->up();
    $migration->up();

    expect($legame())->toHaveCount(1)
        ->and($legame()->first()['foreign_table'])->toBe('subentri')
        ->and($legame()->first()['foreign_columns'])->toBe(['id'])
        ->and(strtolower((string) $legame()->first()['on_delete']))->toBe('restrict');
});

it('Coda 414 — la migrazione delle quote non assegnate rieseguita dopo un\'interruzione lascia una colonna sola, e rifatta dopo il ritorno indietro la rimette', function () {
    $path = database_path('migrations/2026_10_30_100000_add_quote_non_assegnate_to_piani_rate_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_10_30_100000_add_quote_non_assegnate_to_piani_rate_table');
    $migration = require $path;
    $colonne = fn () => collect(Schema::getColumnListing('piani_rate'))->filter(fn ($c) => $c === 'quote_non_assegnate')->count();
    expect($colonne())->toBe(1);

    $migration->up();
    expect($colonne())->toBe(1);

    $migration->down();
    $migration->down();
    expect($colonne())->toBe(0);

    $migration->up();
    $migration->up();
    expect($colonne())->toBe(1);
});

it('Coda 436 — la migrazione dei documenti in più categorie ripresa dopo l\'interruzione fra la chiave esterna e la colonna (chiave tolta, colonna ancora lì) travasa la categoria nei legami e toglie la colonna, e rieseguita non cambia niente', function () {
    $path = database_path('migrations/2026_08_31_140000_documenti_in_piu_categorie.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_08_31_140000_documenti_in_piu_categorie');
    $migration = require $path;

    // Lo stato di prima della migrazione: il ritorno indietro rimette la colonna con la sua chiave e toglie la tabella dei legami.
    $migration->down();
    $chiave = fn () => collect(Schema::getForeignKeys('documenti'))->filter(fn ($k) => $k['columns'] === ['category_id'])->count();
    expect(Schema::hasColumn('documenti', 'category_id'))->toBeTrue()
        ->and($chiave())->toBe(1)
        ->and(Schema::hasTable('documento_categoria'))->toBeFalse();

    $categoria = DB::table('categorie_documento')->orderBy('id')->value('id');
    $documento = DB::table('documenti')->insertGetId([
        'name' => 'Verbale', 'description' => '', 'created_by' => User::factory()->create()->id, 'path' => 'documenti/verbale.pdf',
        'category_id' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // L'interruzione fra i due statement di MySQL: la chiave è già tolta, la colonna c'è ancora.
    Schema::table('documenti', fn ($table) => $table->dropForeign(['category_id']));
    expect($chiave())->toBe(0)
        ->and(Schema::hasColumn('documenti', 'category_id'))->toBeTrue();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('documenti', 'category_id'))->toBeFalse()
        ->and($chiave())->toBe(0)
        ->and(DB::table('documento_categoria')->where('documento_id', $documento)->pluck('categoria_documento_id')->all())->toBe([$categoria]);
});

it('Coda 436 — l\'interruzione dopo il travaso, con la chiave già tolta e la colonna ancora lì, si riprende senza duplicare i legami già scritti', function () {
    $path = database_path('migrations/2026_08_31_140000_documenti_in_piu_categorie.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_08_31_140000_documenti_in_piu_categorie');
    $migration = require $path;

    // Il ritorno indietro toglie la tabella dei legami e rimette la colonna con la sua chiave.
    $migration->down();
    $categorie = DB::table('categorie_documento')->orderBy('id')->limit(2)->pluck('id')->all();
    $autore = User::factory()->create()->id;
    $documento = fn (string $nome, ?int $categoria) => DB::table('documenti')->insertGetId([
        'name' => $nome, 'description' => '', 'created_by' => $autore, 'path' => "documenti/{$nome}.pdf",
        'category_id' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Due documenti nella stessa categoria, uno in un'altra, uno senza: lo stato che il travaso legge.
    $a = $documento('A', $categorie[0]);
    $b = $documento('B', $categorie[0]);
    $c = $documento('C', $categorie[1]);
    $documento('D', null);

    // L'interruzione fra i due statement di MySQL: la chiave è già tolta, la colonna c'è ancora.
    Schema::table('documenti', fn ($table) => $table->dropForeign(['category_id']));

    // E il travaso è già avvenuto, a metà: la tabella dei legami c'è e ha già la riga del primo documento.
    Schema::create('documento_categoria', function ($tabella) {
        $tabella->id();
        $tabella->foreignId('documento_id')->constrained('documenti')->cascadeOnDelete();
        $tabella->foreignId('categoria_documento_id')->constrained('categorie_documento')->cascadeOnDelete();
        $tabella->unique(['documento_id', 'categoria_documento_id'], 'documento_categoria_unico');
        $tabella->timestamps();
    });
    DB::table('documento_categoria')->insert(['documento_id' => $a, 'categoria_documento_id' => $categorie[0], 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('documenti', 'category_id'))->toBeFalse()
        ->and(DB::table('documento_categoria')->orderBy('documento_id')->get()->map(fn ($r) => [$r->documento_id, $r->categoria_documento_id])->all())
        ->toBe([[$a, $categorie[0]], [$b, $categorie[0]], [$c, $categorie[1]]]);
});

it('la migrazione delle impostazioni dello studio si riprende dopo un\'interruzione a metà, una proprietà alla volta; i testi delle lettere non sono impostazioni', function () {
    $path = database_path('settings/2026_11_07_120000_add_studio_to_print_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_120000_add_studio_to_print_settings');
    // Il nome di prima, che portava anche i testi delle lettere, non c'è più: quella proprietà non è mai uscita.
    expect(file_exists(database_path('settings/2026_11_07_120000_add_studio_e_testi_lettere_to_print_settings.php')))->toBeFalse();
    $migration = require $path;
    $proprieta = ['studio_nome', 'studio_indirizzo', 'studio_email', 'studio_pec', 'studio_telefono'];
    $presenti = fn () => DB::table('settings')->where('group', 'print')->whereIn('name', $proprieta)->orderBy('name')->pluck('name')->all();
    $tutte = collect($proprieta)->sort()->values()->all();
    expect($presenti())->toBe($tutte)
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'testi_lettere')->exists())->toBeFalse();

    // Interrotta dopo le prime due: le altre mancano. La ripresa le mette, una volta sola; il valore già scritto non si tocca.
    DB::table('settings')->where('group', 'print')->where('name', 'studio_nome')->update(['payload' => json_encode('Studio Bianchi')]);
    DB::table('settings')->where('group', 'print')->whereIn('name', ['studio_email', 'studio_pec', 'studio_telefono'])->delete();
    $migration->up();
    $migration->up();
    expect($presenti())->toBe($tutte)
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'studio_nome')->value('payload'))->toBe(json_encode('Studio Bianchi'))
        ->and(DB::table('settings')->where('group', 'print')->whereIn('name', $proprieta)->count())->toBe(5)
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'testi_lettere')->exists())->toBeFalse();

    $migration->down();
    $migration->down();
    expect($presenti())->toBe([]);
    $migration->up();
    expect($presenti())->toBe($tutte);
});

it('un database dove la proprietà dei testi delle lettere fosse rimasta (una copia di prova): le impostazioni di stampa si leggono e si salvano lo stesso', function () {
    // La migrazione di prima la scriveva; su una copia dove era passata la riga resta. La classe delle impostazioni non la chiede più.
    DB::table('settings')->insert(['group' => 'print', 'name' => 'testi_lettere', 'locked' => false, 'payload' => json_encode(['sollecito' => 'Vecchio.']),
        'created_at' => now(), 'updated_at' => now()]);
    $settings = app(\App\Settings\PrintSettings::class);
    $settings->studio_nome = 'Studio Bianchi';
    $settings->save();

    expect(app(\App\Settings\PrintSettings::class)->refresh()->studio_nome)->toBe('Studio Bianchi')
        ->and(property_exists(\App\Settings\PrintSettings::class, 'testi_lettere'))->toBeFalse()
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'testi_lettere')->value('payload'))->toBe(json_encode(['sollecito' => 'Vecchio.']));
});

it('la migrazione del comune dello studio si riprende e non tocca un valore già scritto', function () {
    $path = database_path('settings/2026_11_07_150000_add_studio_comune_to_print_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_07_150000_add_studio_comune_to_print_settings');
    $migration = require $path;
    $presente = fn () => DB::table('settings')->where('group', 'print')->where('name', 'studio_comune')->count();
    expect($presente())->toBe(1);
    DB::table('settings')->where('group', 'print')->where('name', 'studio_comune')->update(['payload' => json_encode('Milano')]);
    $migration->up();
    expect($presente())->toBe(1)->and(DB::table('settings')->where('group', 'print')->where('name', 'studio_comune')->value('payload'))->toBe(json_encode('Milano'));
    $migration->down();
    $migration->down();
    expect($presente())->toBe(0);
    $migration->up();
    $migration->up();
    expect($presente())->toBe(1);
});

it('la migrazione della partita IVA, del codice fiscale, del sito e del logo dello studio si riprende dopo un\'interruzione a metà e non tocca un valore già scritto', function () {
    $path = database_path('settings/2026_11_08_200000_add_carta_dello_studio_to_print_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_08_200000_add_carta_dello_studio_to_print_settings');
    $migration = require $path;
    $proprieta = ['logo_stampe_path', 'studio_codice_fiscale', 'studio_partita_iva', 'studio_sito'];
    $presenti = fn () => DB::table('settings')->where('group', 'print')->whereIn('name', $proprieta)->orderBy('name')->pluck('name')->all();
    expect($presenti())->toBe($proprieta);

    // Interrotta dopo la prima: le altre mancano. La ripresa le mette, una volta sola; il valore già scritto non si tocca.
    DB::table('settings')->where('group', 'print')->where('name', 'studio_partita_iva')->update(['payload' => json_encode('01234567890')]);
    DB::table('settings')->where('group', 'print')->whereIn('name', ['logo_stampe_path', 'studio_codice_fiscale', 'studio_sito'])->delete();
    $migration->up();
    $migration->up();
    expect($presenti())->toBe($proprieta)
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'studio_partita_iva')->value('payload'))->toBe(json_encode('01234567890'))
        ->and(DB::table('settings')->where('group', 'print')->whereIn('name', $proprieta)->count())->toBe(4);

    $migration->down();
    $migration->down();
    expect($presenti())->toBe([]);
    $migration->up();
    expect($presenti())->toBe($proprieta)
        ->and(app(\App\Settings\PrintSettings::class)->refresh()->logo_stampe_path)->toBeNull();
});

it('la migrazione delle impostazioni di stampa del 14/06/2026 si riesegue: è una classe senza nome, e non tocca la nota legale né la firma già scritte', function () {
    $path = database_path('settings/2026_06_14_223000_create_print_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_06_14_223000_create_print_settings');
    // Già eseguita dalla catena del test: una classe con nome, caricata di nuovo, non si potrebbe dichiarare due volte.
    $migration = require $path;
    expect($migration)->toBeInstanceOf(\Spatie\LaravelSettings\Migrations\SettingsMigration::class);
    DB::table('settings')->where('group', 'print')->where('name', 'nota_legale_stampe')->update(['payload' => json_encode('La nota dello studio')]);
    DB::table('settings')->where('group', 'print')->where('name', 'firma_stampe_path')->delete();

    $migration->up();
    $migration->up();
    expect(DB::table('settings')->where('group', 'print')->where('name', 'nota_legale_stampe')->value('payload'))->toBe(json_encode('La nota dello studio'))
        ->and(DB::table('settings')->where('group', 'print')->whereIn('name', ['nota_legale_stampe', 'firma_stampe_path'])->count())->toBe(2);

    $migration->down();
    expect(DB::table('settings')->where('group', 'print')->whereIn('name', ['nota_legale_stampe', 'firma_stampe_path'])->count())->toBe(0);
    $migration->up();
    expect(DB::table('settings')->where('group', 'print')->where('name', 'nota_legale_stampe')->value('payload'))
        ->toBe(json_encode('Professione esercitata ai sensi della legge 14 gennaio 2013, n.4'));
});

it('la migrazione delle carte dello studio interrotta dopo la tabella, senza una colonna e senza l\'indice, si riprende e li mette; la «Carta dello studio» una volta sola', function () {
    $path = database_path('migrations/2026_11_08_210000_create_carte_stampa_table.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_08_210000_create_carte_stampa_table');
    $migration = require $path;
    $stato = fn () => [
        'colonne' => collect(Schema::getColumnListing('carte_stampa'))->sort()->values()->all(),
        'indici' => collect(Schema::getIndexes('carte_stampa'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'carte_stampa'))->sort()->values()->all(),
        'carte' => DB::table('carte_stampa')->orderBy('id')->get(['nome', 'aspetto', 'mostra_logo', 'colore', 'predefinita'])
            ->map(fn ($c) => [$c->nome, $c->aspetto, (bool) $c->mostra_logo, $c->colore, (bool) $c->predefinita])->all(),
    ];
    $atteso = [
        'colonne' => ['aspetto', 'carta_pagine', 'carta_path', 'carta_tipo', 'colore', 'created_at', 'id', 'margine_alto', 'margine_basso', 'margine_destro',
            'margine_sinistro', 'mostra_logo', 'nome', 'predefinita', 'updated_at'],
        'indici' => ['carte_stampa_predefinita_index'],
        'carte' => [['Carta dello studio', 'campi', true, null, true]],
    ];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella: una colonna e l'indice mancano.
    Schema::table('carte_stampa', fn ($table) => $table->dropIndex('carte_stampa_predefinita_index'));
    Schema::table('carte_stampa', fn ($table) => $table->dropColumn('margine_destro'));
    expect($stato()['indici'])->toBe([])->and($stato()['colonne'])->not->toContain('margine_destro');

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('carte_stampa'))->toBeFalse();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione della carta per gruppo si riprende e non tocca una scelta già scritta', function () {
    $path = database_path('settings/2026_11_08_210100_add_carta_per_gruppo_to_print_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_08_210100_add_carta_per_gruppo_to_print_settings');
    $migration = require $path;
    $valore = fn () => DB::table('settings')->where('group', 'print')->where('name', 'carta_per_gruppo')->value('payload');
    $vuoto = ['lettere' => null, 'ricevute_e_avvisi' => null, 'rendiconto_e_assemblea' => null, 'per_la_persona' => null, 'registri_e_elenchi' => null];
    expect(json_decode((string) $valore(), true))->toBe($vuoto);

    DB::table('settings')->where('group', 'print')->where('name', 'carta_per_gruppo')->update(['payload' => json_encode(['lettere' => 7] + $vuoto)]);
    $migration->up();
    $migration->up();
    expect(json_decode((string) $valore(), true))->toBe(['lettere' => 7] + $vuoto)
        ->and(DB::table('settings')->where('group', 'print')->where('name', 'carta_per_gruppo')->count())->toBe(1);

    $migration->down();
    $migration->down();
    expect($valore())->toBeNull();
    $migration->up();
    expect(json_decode((string) $valore(), true))->toBe($vuoto);
});

it('la migrazione dell\'autore dei documenti si riprende dopo ognuno dei suoi tre passi, e una riga senza autore resta', function () {
    $path = database_path('migrations/2026_11_14_100000_documenti_created_by_null_on_delete.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_14_100000_documenti_created_by_null_on_delete');
    $migration = require $path;
    $stato = fn () => [
        'vuotabile' => (bool) collect(Schema::getColumns('documenti'))->firstWhere('name', 'created_by')['nullable'],
        'chiavi' => collect(Schema::getForeignKeys('documenti'))->filter(fn ($k) => $k['columns'] === ['created_by'])
            ->map(fn ($k) => $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->values()->all(),
    ];
    $atteso = ['vuotabile' => true, 'chiavi' => ['users set null']];
    expect($stato())->toBe($atteso);

    // Com'era prima: la chiave in cascata, la colonna obbligatoria.
    $migration->down();
    expect($stato())->toBe(['vuotabile' => false, 'chiavi' => ['users cascade']]);

    // Interrotta dopo il primo passo: la chiave in cascata tolta, la colonna ancora obbligatoria.
    Schema::table('documenti', fn ($table) => $table->dropForeign(['created_by']));
    expect($stato())->toBe(['vuotabile' => false, 'chiavi' => []]);
    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    // Interrotta dopo il secondo passo: la colonna vuotabile, nessuna chiave.
    Schema::table('documenti', fn ($table) => $table->dropForeign(['created_by']));
    expect($stato())->toBe(['vuotabile' => true, 'chiavi' => []]);
    $migration->up();
    expect($stato())->toBe($atteso);

    // Un documento che ha perso l'autore: il ritorno indietro rimette la cascata e lascia la colonna vuotabile, senza toccare la riga.
    $id = DB::table('documenti')->insertGetId(['name' => 'Verbale', 'description' => 'Verbale', 'path' => 'documenti/v.pdf', 'created_by' => null,
        'created_at' => now(), 'updated_at' => now()]);
    $migration->down();
    expect($stato())->toBe(['vuotabile' => true, 'chiavi' => ['users cascade']])
        ->and(DB::table('documenti')->where('id', $id)->exists())->toBeTrue();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione delle versioni del rendiconto e quella della nota interrotte dopo la tabella si riprendono e mettono l\'indice e le chiavi, una per nome', function () {
    $stato = fn (string $t, string $prefisso) => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, $prefisso))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $versioni = require database_path('migrations/2026_11_14_100200_create_rendiconto_versioni_table.php');
    $nota = require database_path('migrations/2026_11_14_100100_create_note_sintetiche_table.php');
    $attesoVersioni = ['indici' => ['rendiconto_versioni_numero_unico'], 'chiavi' => ['approvata_da>users set null', 'condominio_id>condomini restrict',
        'creata_da>users set null', 'esercizio_id>esercizi restrict', 'verbale_documento_id>documenti set null']];
    $attesoNota = ['indici' => ['note_sintetiche_esercizio_unica'], 'chiavi' => ['aggiornata_da>users set null', 'condominio_id>condomini cascade',
        'esercizio_id>esercizi cascade']];
    expect($stato('rendiconto_versioni', 'rendiconto_versioni'))->toBe($attesoVersioni)
        ->and($stato('note_sintetiche', 'note_sintetiche'))->toBe($attesoNota);

    // Interrotte dopo la tabella: l'indice e tre chiavi della versione, l'indice e una chiave della nota mancano.
    Schema::table('rendiconto_versioni', function ($table) {
        $table->dropForeign(['esercizio_id']);
        $table->dropForeign(['condominio_id']);
        $table->dropForeign(['verbale_documento_id']);
    });
    Schema::table('rendiconto_versioni', fn ($table) => $table->dropUnique('rendiconto_versioni_numero_unico'));
    Schema::table('note_sintetiche', fn ($table) => $table->dropForeign(['esercizio_id']));
    Schema::table('note_sintetiche', fn ($table) => $table->dropUnique('note_sintetiche_esercizio_unica'));
    expect($stato('rendiconto_versioni', 'rendiconto_versioni'))->toBe(['indici' => [], 'chiavi' => ['approvata_da>users set null', 'creata_da>users set null']])
        ->and($stato('note_sintetiche', 'note_sintetiche'))->toBe(['indici' => [], 'chiavi' => ['aggiornata_da>users set null', 'condominio_id>condomini cascade']]);

    $versioni->up();
    $versioni->up();
    $nota->up();
    $nota->up();
    expect($stato('rendiconto_versioni', 'rendiconto_versioni'))->toBe($attesoVersioni)
        ->and($stato('note_sintetiche', 'note_sintetiche'))->toBe($attesoNota);

    $versioni->down();
    $versioni->down();
    $nota->down();
    expect(Schema::hasTable('rendiconto_versioni'))->toBeFalse()->and(Schema::hasTable('note_sintetiche'))->toBeFalse();
    $nota->up();
    $versioni->up();
    expect($stato('rendiconto_versioni', 'rendiconto_versioni'))->toBe($attesoVersioni)
        ->and($stato('note_sintetiche', 'note_sintetiche'))->toBe($attesoNota);
});

it('la migrazione delle regole del recupero interrotta dopo la tabella si riprende e mette l\'indice e le chiavi, uno per nome', function () {
    $migration = require database_path('migrations/2026_11_15_110000_create_regole_recupero_table.php');
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('regole_recupero'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'regole_recupero'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('regole_recupero'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $atteso = ['indici' => ['regole_recupero_dal_unico'], 'chiavi' => ['condominio_id>condomini cascade', 'creata_da>users set null']];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella: l'indice unico e la chiave del condominio mancano.
    Schema::table('regole_recupero', fn ($table) => $table->dropForeign(['condominio_id']));
    Schema::table('regole_recupero', fn ($table) => $table->dropUnique('regole_recupero_dal_unico'));
    expect($stato())->toBe(['indici' => [], 'chiavi' => ['creata_da>users set null']]);

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('regole_recupero'))->toBeFalse();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione del calendario dell\'accordo interrotta dopo la tabella e una colonna si riprende e mette l\'indice, la chiave e le colonne, uno per nome', function () {
    $migration = require database_path('migrations/2026_11_15_120000_add_calendario_e_decadenza_to_accordi_rientro.php');
    $colonne = ['decadenza_giorni', 'decadenza_rate', 'decadenza_registrata_il', 'segnali'];
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('accordo_rientro_scadenze'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'accordo_rientro_scadenze'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('accordo_rientro_scadenze'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
        'colonne' => collect(Schema::getColumnListing('accordi_rientro'))->intersect($colonne)->sort()->values()->all(),
    ];
    $atteso = ['indici' => ['accordo_rientro_scadenze_numero_unico'], 'chiavi' => ['accordo_rientro_id>accordi_rientro cascade'], 'colonne' => $colonne];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella e la prima colonna: l'indice, la chiave e tre colonne mancano.
    Schema::table('accordo_rientro_scadenze', fn ($table) => $table->dropForeign(['accordo_rientro_id']));
    Schema::table('accordo_rientro_scadenze', fn ($table) => $table->dropUnique('accordo_rientro_scadenze_numero_unico'));
    foreach (['decadenza_giorni', 'segnali', 'decadenza_registrata_il'] as $colonna) {
        Schema::table('accordi_rientro', fn ($table) => $table->dropColumn($colonna));
    }
    expect($stato())->toBe(['indici' => [], 'chiavi' => [], 'colonne' => ['decadenza_rate']]);

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('accordo_rientro_scadenze'))->toBeFalse()
        ->and(collect(Schema::getColumnListing('accordi_rientro'))->intersect($colonne)->values()->all())->toBe([]);
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione del tasso legale scritto a mano si riprende e non tocca un tasso già scritto; tornata indietro si rimette vuota', function () {
    $path = database_path('settings/2026_11_15_130000_create_tasso_legale_settings.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_15_130000_create_tasso_legale_settings');
    $migration = require $path;
    $riga = fn () => DB::table('settings')->where('group', 'tasso_legale')->where('name', 'scritti_a_mano');
    expect($riga()->count())->toBe(1)->and($riga()->value('payload'))->toBe(json_encode([]));

    // Un tasso già scritto: la ripresa non lo tocca, e la riga resta una.
    $scritto = [['anno' => 2027, 'tasso_e4' => 22500, 'fonte' => 'D.M. 10/12/2026', 'scritto_da' => null, 'scritto_da_nome' => 'Studio', 'scritto_il' => '2026-12-16']];
    $riga()->update(['payload' => json_encode($scritto)]);
    $migration->up();
    $migration->up();
    expect($riga()->count())->toBe(1)->and(json_decode((string) $riga()->value('payload'), true))->toBe($scritto);

    $migration->down();
    $migration->down();
    expect($riga()->count())->toBe(0);
    $migration->up();
    expect($riga()->count())->toBe(1)->and(app(\App\Settings\TassoLegaleSettings::class)->refresh()->scritti_a_mano)->toBe([]);
});

it('la migrazione delle risposte ai promemoria interrotta dopo la tabella si riprende e mette gli indici, le chiavi e la colonna della dispensa, uno per nome', function () {
    $migration = require database_path('migrations/2026_11_15_140000_create_risposte_promemoria_table.php');
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('risposte_promemoria'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'risposte_promemoria'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('risposte_promemoria'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
        'colonna' => Schema::hasColumn('dispense_sei_mesi', 'togli_sollecito_tesoreria'),
    ];
    $atteso = [
        'indici' => ['risposte_promemoria_argomento_stato_index', 'risposte_promemoria_chiave_unica', 'risposte_promemoria_condominio_argomento_index'],
        'chiavi' => ['agenda_evento_id>eventi set null', 'aggiornata_da>users set null', 'anagrafica_id>anagrafiche cascade', 'condominio_id>condomini cascade',
            'esercizio_id>esercizi cascade', 'evento_id>eventi set null', 'promemoria_evento_id>eventi set null'],
        'colonna' => true,
    ];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella: l'indice unico, una chiave e la colonna della dispensa mancano.
    Schema::table('risposte_promemoria', fn ($table) => $table->dropForeign(['esercizio_id']));
    Schema::table('risposte_promemoria', fn ($table) => $table->dropUnique('risposte_promemoria_chiave_unica'));
    Schema::table('dispense_sei_mesi', fn ($table) => $table->dropColumn('togli_sollecito_tesoreria'));
    expect($stato()['indici'])->not->toContain('risposte_promemoria_chiave_unica')
        ->and($stato()['chiavi'])->not->toContain('esercizio_id>esercizi cascade')
        ->and($stato()['colonna'])->toBeFalse();

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('risposte_promemoria'))->toBeFalse()->and(Schema::hasColumn('dispense_sei_mesi', 'togli_sollecito_tesoreria'))->toBeFalse();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione delle impostazioni del condominio interrotta dopo la tabella si riprende e mette l\'indice unico e le chiavi, uno per nome', function () {
    $migration = require database_path('migrations/2026_11_15_200000_create_impostazioni_condominio_table.php');
    $stato = fn () => [
        'colonne' => collect(['condominio_id', 'recupero_salita', 'recupero_suggerisce_azione', 'aggiornate_da'])->filter(fn ($c) => Schema::hasColumn('impostazioni_condominio', $c))->values()->all(),
        'indici' => collect(Schema::getIndexes('impostazioni_condominio'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'impostazioni_condominio'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('impostazioni_condominio'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $atteso = [
        'colonne' => ['condominio_id', 'recupero_salita', 'recupero_suggerisce_azione', 'aggiornate_da'],
        'indici' => ['impostazioni_condominio_condominio_unico'],
        'chiavi' => ['aggiornate_da>users set null', 'condominio_id>condomini cascade'],
    ];
    expect($stato())->toBe($atteso);

    // Interrotta dopo la tabella: l'indice unico e una chiave mancano.
    Schema::table('impostazioni_condominio', fn ($table) => $table->dropForeign(['condominio_id']));
    Schema::table('impostazioni_condominio', fn ($table) => $table->dropUnique('impostazioni_condominio_condominio_unico'));
    expect($stato()['indici'])->toBe([])->and($stato()['chiavi'])->toBe(['aggiornate_da>users set null']);

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);

    $migration->down();
    $migration->down();
    expect(Schema::hasTable('impostazioni_condominio'))->toBeFalse();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione del registro delle modifiche su tabelle già create senza la firma e senza l\'indice dell\'azione li aggiunge, uno per guardia', function () {
    $path = database_path('migrations/2026_11_19_100000_create_registro_modifiche_tables.php');
    expect(file_exists($path))->toBeTrue('Migrazione non trovata: 2026_11_19_100000_create_registro_modifiche_tables');
    $migration = require $path;
    $stato = fn () => [
        'firma' => Schema::hasColumn('registro_modifiche', 'firma'),
        'indici' => collect(Schema::getIndexes('registro_modifiche'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'registro_modifiche_'))->sort()->values()->all(),
    ];
    $atteso = ['firma' => true, 'indici' => ['registro_modifiche_area_index', 'registro_modifiche_autore_index', 'registro_modifiche_avvenuta_index',
        'registro_modifiche_azione_index', 'registro_modifiche_oggetto_index', 'registro_modifiche_scheda_index', 'registro_modifiche_sequenza_unique']];
    expect($stato())->toBe($atteso);

    // Come nel database dove le tabelle sono nate prima della firma e dell'indice: le due cose mancano.
    Schema::table('registro_modifiche', fn ($table) => $table->dropIndex('registro_modifiche_azione_index'));
    Schema::table('registro_modifiche', fn ($table) => $table->dropColumn('firma'));
    expect($stato()['firma'])->toBeFalse();

    $migration->up();
    $migration->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione dell\'assemblea interrotta dopo le quattro tabelle si riprende e mette gli indici e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite; la guardia delle chiavi legge `information_schema` lì, `PRAGMA foreign_key_list` qui).
    $stato = fn () => collect(['assemblee', 'assemblee_sedute', 'assemblee_punti', 'assemblee_punti_oggetti'])->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, $t . '_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $migrazione = require database_path('migrations/2026_11_19_130000_create_assemblee_tables.php');
    $delibere = require database_path('migrations/2026_11_19_130100_create_delibere_tables.php');
    $atteso = $stato();
    expect($atteso['assemblee']['indici'])->toBe(['assemblee_condominio_data_index', 'assemblee_condominio_stato_index'])
        ->and($atteso['assemblee']['chiavi'])->toContain('condominio_id>condomini restrict', 'riconvocata_da_id>assemblee set null', 'tabella_id>tabelle restrict')
        ->and($atteso['assemblee_sedute']['indici'])->toBe(['assemblee_sedute_unica'])
        ->and($atteso['assemblee_punti']['indici'])->toBe(['assemblee_punti_chiave_fonte_index', 'assemblee_punti_ordine_index'])
        ->and($atteso['assemblee_punti_oggetti']['chiavi'])->toBe(['punto_id>assemblee_punti cascade']);

    // Interrotta dopo le tabelle: gli indici e le chiavi mancano.
    Schema::table('assemblee', function ($t) {
        $t->dropForeign(['condominio_id']);
        $t->dropForeign(['tabella_id']);
    });
    Schema::table('assemblee', fn ($t) => $t->dropIndex('assemblee_condominio_data_index'));
    Schema::table('assemblee_sedute', fn ($t) => $t->dropUnique('assemblee_sedute_unica'));
    Schema::table('assemblee_punti', fn ($t) => $t->dropForeign(['assemblea_id']));
    Schema::table('assemblee_punti_oggetti', fn ($t) => $t->dropUnique('assemblee_punti_oggetti_unico'));
    expect($stato())->not->toBe($atteso);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $delibere->down();
    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('assemblee'))->toBeFalse()->and(Schema::hasTable('assemblee_punti_oggetti'))->toBeFalse();
    $migrazione->up();
    $delibere->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione della delibera e dei collegamenti interrotta dopo le tabelle si riprende e mette gli indici e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite).
    // La chiave di chi conferma il seguito la mette la sua migrazione (2026_11_21_100100, con il suo `it()`): qui si guardano quelle di questa.
    $stato = fn () => collect(['delibere', 'delibere_collegamenti'])->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, $t . '_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->reject(fn ($k) => $k['columns'] === ['seguito_confermato_da'])
            ->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $migrazione = require database_path('migrations/2026_11_19_130100_create_delibere_tables.php');
    $atteso = [
        'delibere' => ['indici' => ['delibere_assemblea_index', 'delibere_condominio_data_index', 'delibere_punto_index'],
            'chiavi' => ['assemblea_id>assemblee restrict', 'condominio_id>condomini restrict', 'created_by>users set null', 'punto_id>assemblee_punti restrict',
                'revocata_da_delibera_id>delibere set null']],
        'delibere_collegamenti' => ['indici' => ['delibere_collegamenti_condominio_index', 'delibere_collegamenti_delibera_index', 'delibere_collegamenti_unico'],
            'chiavi' => ['collegata_da>users set null', 'delibera_id>delibere restrict']],
    ];
    expect($stato())->toBe($atteso);

    Schema::table('delibere', function ($t) {
        $t->dropForeign(['punto_id']);
        $t->dropForeign(['assemblea_id']);
    });
    Schema::table('delibere', fn ($t) => $t->dropIndex('delibere_punto_index'));
    Schema::table('delibere_collegamenti', fn ($t) => $t->dropForeign(['delibera_id']));
    Schema::table('delibere_collegamenti', fn ($t) => $t->dropUnique('delibere_collegamenti_unico'));
    expect($stato())->not->toBe($atteso);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('delibere'))->toBeFalse()->and(Schema::hasTable('delibere_collegamenti'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione dei convocati interrotta dopo la tabella si riprende e mette l\'indice unico e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite).
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('assemblee_convocati'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'assemblee_convocati_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('assemblee_convocati'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $migrazione = require database_path('migrations/2026_11_19_130600_create_assemblee_convocati_table.php');
    $atteso = ['indici' => ['assemblee_convocati_unico'], 'chiavi' => ['anagrafica_id>anagrafiche set null', 'assemblea_id>assemblee restrict',
        'created_by>users set null', 'invio_id>registro_invii set null']];
    expect($stato())->toBe($atteso);

    Schema::table('assemblee_convocati', function ($t) {
        $t->dropForeign(['invio_id']);
        $t->dropForeign(['assemblea_id']);
    });
    Schema::table('assemblee_convocati', fn ($t) => $t->dropUnique('assemblee_convocati_unico'));
    expect($stato())->toBe(['indici' => [], 'chiavi' => ['anagrafica_id>anagrafiche set null', 'created_by>users set null']]);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('assemblee_convocati'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione delle tabelle della sala interrotta dopo le sei tabelle si riprende e mette gli indici e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite; la guardia delle chiavi legge `information_schema` lì, `PRAGMA foreign_key_list` qui).
    $tabelle = ['assemblee_deleghe', 'assemblee_presenze', 'assemblee_votazioni', 'assemblee_voti', 'assemblee_dichiarazioni', 'assemblee_gesti'];
    $stato = fn () => collect($tabelle)->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, $t . '_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $percorso = database_path('migrations/2026_11_20_100100_create_assemblee_sala_tables.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_20_100100_create_assemblee_sala_tables');
    $migrazione = require $percorso;
    $atteso = $stato();
    expect($atteso['assemblee_deleghe']['chiavi'])->toContain('assemblea_id>assemblee restrict', 'delegato_anagrafica_id>anagrafiche set null',
        'documento_id>documenti set null')
        ->and($atteso['assemblee_presenze']['chiavi'])->toContain('seduta_id>assemblee_sedute restrict')
        ->and($atteso['assemblee_votazioni']['indici'])->toBe(['assemblee_votazioni_unica'])
        ->and($atteso['assemblee_votazioni']['chiavi'])->toContain('delibera_id>delibere set null', 'punto_id>assemblee_punti restrict')
        ->and($atteso['assemblee_voti']['indici'])->toBe(['assemblee_voti_unico'])
        ->and($atteso['assemblee_voti']['chiavi'])->toContain('votazione_id>assemblee_votazioni cascade')
        ->and($atteso['assemblee_gesti']['indici'])->toBe(['assemblee_gesti_assemblea_arrivo_index', 'assemblee_gesti_gesto_unico']);

    // Interrotta dopo le tabelle: gli indici e le chiavi mancano.
    Schema::table('assemblee_deleghe', fn ($t) => $t->dropForeign(['assemblea_id']));
    Schema::table('assemblee_deleghe', fn ($t) => $t->dropIndex('assemblee_deleghe_assemblea_stato_index'));
    Schema::table('assemblee_voti', fn ($t) => $t->dropForeign(['votazione_id']));
    Schema::table('assemblee_voti', fn ($t) => $t->dropUnique('assemblee_voti_unico'));
    Schema::table('assemblee_gesti', fn ($t) => $t->dropUnique('assemblee_gesti_gesto_unico'));
    Schema::table('assemblee_presenze', fn ($t) => $t->dropForeign(['seduta_id']));
    expect($stato())->not->toBe($atteso);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('assemblee_deleghe'))->toBeFalse()->and(Schema::hasTable('assemblee_gesti'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione delle colonne della seduta interrotta dopo la prima colonna si riprende e mette le altre, una per nome', function () {
    $percorso = database_path('migrations/2026_11_20_100000_add_sala_to_assemblee_sedute.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_20_100000_add_sala_to_assemblee_sedute');
    $migrazione = require $percorso;
    $colonne = ['aperta_il', 'chiusa_il', 'esito', 'presidente', 'segretario', 'apertura', 'fotografia', 'impronta_fotografia', 'dal_foglio'];
    $ci = fn () => array_values(array_filter($colonne, fn ($c) => Schema::hasColumn('assemblee_sedute', $c)));
    expect($ci())->toBe($colonne);

    // Interrotta dopo la prima colonna: le altre otto mancano.
    $migrazione->down();
    Schema::table('assemblee_sedute', fn ($t) => $t->dateTime('aperta_il')->nullable());
    expect($ci())->toBe(['aperta_il']);

    $migrazione->up();
    $migrazione->up();
    expect($ci())->toBe($colonne);
});

it('la migrazione delle note del presidente interrotta dopo la prima colonna si riprende e mette l\'altra', function () {
    $percorso = database_path('migrations/2026_11_20_100400_add_note_to_assemblee_votazioni.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_20_100400_add_note_to_assemblee_votazioni');
    $migrazione = require $percorso;
    $colonne = ['nota', 'nota_non_votato'];
    $ci = fn () => array_values(array_filter($colonne, fn ($c) => Schema::hasColumn('assemblee_votazioni', $c)));
    expect($ci())->toBe($colonne);

    // Interrotta dopo la prima colonna: l'altra manca.
    $migrazione->down();
    Schema::table('assemblee_votazioni', fn ($t) => $t->text('nota')->nullable());
    expect($ci())->toBe(['nota']);

    $migrazione->up();
    $migrazione->up();
    expect($ci())->toBe($colonne);
});

it('la migrazione delle tabelle del verbale interrotta dopo le cinque tabelle si riprende e mette gli indici e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite; la guardia delle chiavi legge `information_schema` lì, `PRAGMA foreign_key_list` qui).
    $tabelle = ['assemblee_registri_verbali', 'assemblee_verbali', 'assemblee_verbali_parti', 'assemblee_verbali_versioni', 'registro_nomine'];
    $stato = fn () => collect($tabelle)->mapWithKeys(fn ($t) => [$t => [
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->filter(fn ($n) => str_starts_with($n, $t . '_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ]])->all();
    $percorso = database_path('migrations/2026_11_20_110000_create_assemblee_verbali_tables.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_20_110000_create_assemblee_verbali_tables');
    $migrazione = require $percorso;
    $atteso = $stato();
    expect($atteso['assemblee_registri_verbali']['indici'])->toBe(['assemblee_registri_verbali_unico'])
        ->and($atteso['assemblee_verbali']['indici'])->toContain('assemblee_verbali_numero_unico', 'assemblee_verbali_assemblea_index')
        ->and($atteso['assemblee_verbali']['chiavi'])->toContain('assemblea_id>assemblee restrict', 'condominio_id>condomini restrict',
            'seduta_id>assemblee_sedute restrict', 'documento_id>documenti restrict', 'modello_id>modelli_testo set null', 'sostituisce_id>assemblee_verbali set null')
        ->and($atteso['assemblee_verbali_parti']['indici'])->toBe(['assemblee_verbali_parti_unica'])
        ->and($atteso['assemblee_verbali_parti']['chiavi'])->toContain('verbale_id>assemblee_verbali restrict', 'punto_id>assemblee_punti restrict')
        ->and($atteso['assemblee_verbali_versioni']['indici'])->toBe(['assemblee_verbali_versioni_parte_index'])
        ->and($atteso['registro_nomine']['indici'])->toBe(['registro_nomine_condominio_dal_index'])
        ->and($atteso['registro_nomine']['chiavi'])->toContain('condominio_id>condomini restrict', 'delibera_id>delibere restrict', 'documento_id>documenti set null');

    // Interrotta dopo le tabelle: gli indici e le chiavi mancano.
    Schema::table('assemblee_verbali_versioni', fn ($t) => $t->dropForeign(['verbale_id']));
    Schema::table('assemblee_verbali_versioni', fn ($t) => $t->dropIndex('assemblee_verbali_versioni_parte_index'));
    Schema::table('assemblee_verbali_parti', fn ($t) => $t->dropForeign(['verbale_id']));
    Schema::table('assemblee_verbali_parti', fn ($t) => $t->dropUnique('assemblee_verbali_parti_unica'));
    Schema::table('assemblee_verbali', fn ($t) => $t->dropForeign(['sostituisce_id']));
    Schema::table('assemblee_verbali', fn ($t) => $t->dropUnique('assemblee_verbali_numero_unico'));
    Schema::table('registro_nomine', fn ($t) => $t->dropForeign(['delibera_id']));
    Schema::table('assemblee_registri_verbali', fn ($t) => $t->dropUnique('assemblee_registri_verbali_unico'));
    expect($stato())->not->toBe($atteso);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('assemblee_verbali'))->toBeFalse()->and(Schema::hasTable('registro_nomine'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione del giorno incollato nel libro di carta si riesegue e mette la colonna una volta sola', function () {
    $percorso = database_path('migrations/2026_11_20_110100_add_nel_libro_to_assemblee_sedute.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_20_110100_add_nel_libro_to_assemblee_sedute');
    $migrazione = require $percorso;
    expect(Schema::hasColumn('assemblee_sedute', 'nel_libro_il'))->toBeTrue();

    $migrazione->down();
    expect(Schema::hasColumn('assemblee_sedute', 'nel_libro_il'))->toBeFalse();
    $migrazione->up();
    $migrazione->up();
    expect(Schema::hasColumn('assemblee_sedute', 'nel_libro_il'))->toBeTrue();
});

it('la migrazione delle cose da fare delle delibere interrotta dopo la tabella si riprende e mette gli indici e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite; la guardia delle chiavi legge `information_schema` lì, `PRAGMA foreign_key_list` qui).
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('delibere_cose_da_fare'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'delibere_cose_da_fare_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('delibere_cose_da_fare'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' '
            . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $percorso = database_path('migrations/2026_11_21_100000_create_delibere_cose_da_fare_table.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_21_100000_create_delibere_cose_da_fare_table');
    $migrazione = require $percorso;
    $atteso = $stato();
    expect($atteso['indici'])->toBe(['delibere_cose_da_fare_condominio_stato_index', 'delibere_cose_da_fare_delibera_index', 'delibere_cose_da_fare_oggetto_index'])
        ->and($atteso['chiavi'])->toContain('delibera_id>delibere restrict', 'condominio_id>condomini restrict', 'evento_id>eventi set null',
            'documento_id>documenti set null', 'assegnato_a>users set null');

    // Interrotta dopo la tabella: gli indici e le chiavi mancano.
    Schema::table('delibere_cose_da_fare', fn ($t) => $t->dropForeign(['delibera_id']));
    Schema::table('delibere_cose_da_fare', fn ($t) => $t->dropForeign(['evento_id']));
    Schema::table('delibere_cose_da_fare', fn ($t) => $t->dropIndex('delibere_cose_da_fare_oggetto_index'));
    expect($stato())->not->toBe($atteso);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('delibere_cose_da_fare'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});

it('la migrazione del seguito sulla delibera interrotta dopo la prima colonna si riprende e mette le altre e la chiave, una per nome', function () {
    $percorso = database_path('migrations/2026_11_21_100100_add_seguito_to_delibere.php');
    expect(file_exists($percorso))->toBeTrue('Migrazione non trovata: 2026_11_21_100100_add_seguito_to_delibere');
    $migrazione = require $percorso;
    $chiavi = fn () => collect(Schema::getForeignKeys('delibere'))->filter(fn ($k) => $k['columns'] === ['seguito_confermato_da'])->count();
    expect(Schema::hasColumns('delibere', ['seguito_confermato_il', 'seguito_confermato_da', 'dati']))->toBeTrue()->and($chiavi())->toBe(1);

    // Interrotta dopo la prima colonna: le altre e la chiave mancano.
    $migrazione->down();
    Schema::table('delibere', fn ($t) => $t->dateTime('seguito_confermato_il')->nullable());
    expect(Schema::hasColumn('delibere', 'dati'))->toBeFalse();

    $migrazione->up();
    $migrazione->up();
    expect(Schema::hasColumns('delibere', ['seguito_confermato_il', 'seguito_confermato_da', 'dati']))->toBeTrue()->and($chiavi())->toBe(1);
});

it('la migrazione dei mezzi scelti per assemblea interrotta dopo la tabella si riprende e mette l\'indice unico e le chiavi, uno per nome', function () {
    // Che cosa non copre: MySQL (la suite gira su SQLite).
    $stato = fn () => [
        'indici' => collect(Schema::getIndexes('assemblee_mezzi_scelti'))->pluck('name')->filter(fn ($n) => str_starts_with($n, 'assemblee_mezzi_scelti_'))->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys('assemblee_mezzi_scelti'))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'] . ' ' . strtolower((string) $k['on_delete']))->sort()->values()->all(),
    ];
    $migrazione = require database_path('migrations/2026_11_21_140000_create_assemblee_mezzi_scelti_table.php');
    $atteso = ['indici' => ['assemblee_mezzi_scelti_unico'], 'chiavi' => ['anagrafica_id>anagrafiche cascade', 'assemblea_id>assemblee cascade', 'created_by>users set null']];
    expect($stato())->toBe($atteso);

    Schema::table('assemblee_mezzi_scelti', function ($t) {
        $t->dropForeign(['assemblea_id']);
        $t->dropForeign(['created_by']);
    });
    Schema::table('assemblee_mezzi_scelti', fn ($t) => $t->dropUnique('assemblee_mezzi_scelti_unico'));
    expect($stato())->toBe(['indici' => [], 'chiavi' => ['anagrafica_id>anagrafiche cascade']]);

    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('assemblee_mezzi_scelti'))->toBeFalse();
    $migrazione->up();
    expect($stato())->toBe($atteso);
});
