<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * beta.49 — Il riparto **stampato** coincide con quello **addebitato**.
 *
 * È la coda ⑩, e il difetto che chiude è quello che si vede in assemblea.
 *
 * ## Perché esistono due algoritmi, e perché è un problema
 *
 * `CalcoloQuoteService` calcola gli addebiti e li scrive in `rate_quote`.
 * `RipartoTabelleService` **ricalcola tutto da capo** per costruire il PDF «Riparto bilancio per
 * tabella × soggetto». Sono due copie dello stesso algoritmo, e la seconda era ferma alla
 * beta.42: aveva ancora la condizione `&& $rip->soggetto !== 'proprietario'` che la beta.43 ha
 * tolto dal motore, e le catene dei ruoli riscritte a mano invece dell'enum.
 *
 * Il commento sopra quel blocco diceva «cascata ruolo (identica a CalcoloQuoteService)».
 * **Era falso da undici giorni, e nessun test se n'era accorto** — perché nessun test confrontava
 * le due metà.
 *
 * ## Il caso, e perché sfuggiva
 *
 * Un'unità con **nuda proprietà e usufrutto** e nessun `proprietario` attivo, con il coefficiente
 * sul `proprietario`: è la configurazione normale di un appartamento con usufrutto, e il
 * `proprietario` sul coefficiente è il default quando non si dichiara altro.
 *
 * Il motore risolve la cascata `proprietario → nuda_proprietario` e **addebita il nudo
 * proprietario**. La stampa non entrava nemmeno nella cascata e lo trattava come quota scoperta:
 * cella a zero.
 *
 * Sfuggiva perché il totale di riga del PDF non viene ricalcolato — è letto da `rate_quote` — e
 * perché esiste un riallineamento (`RipartoTabelleService:243`) che sposta il residuo sulla
 * tabella a peso maggiore. Quel riallineamento però scatta **solo se il soggetto ha un peso**, e
 * qui il peso era zero. Risultato: riga giusta, celle tutte a zero, colonne che non sommano al
 * gran totale. Il documento si contraddiceva da solo.
 *
 * ## Cosa questo file NON copre, ed è molto
 *
 * La mappatura della beta.49 ha trovato **17 divergenze**, non tre. Qui sono chiuse quelle della
 * cascata dei ruoli; restano aperte in particolare l'intera famiglia dello **straordinario** (la
 * stampa ignora `importo_collegato`, non vede le coperture da sopravvenienza, mostra le righe
 * ordinarie della fattura, distribuisce gli addebiti ad personam su tutti) e il **netting del
 * già-versato**, che nella stampa non c'è. Sono in roadmap con la loro decisione: vedi la coda ⑩.
 */
/**
 * L'impalcatura è ricopiata da `RipartoCasiLimiteTest` con nomi propri: in Pest le funzioni
 * dichiarate dentro un file di test non sono visibili agli altri.
 */
function baseCondominioConcordanza(): array
{
    $condominio = \App\Models\Condominio::factory()->create(['nome' => 'CONCORDANZA']);
    $esercizio = \App\Models\Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = \App\Models\Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Gestione Base', 'tipo' => 'ordinaria',
    ]);
    $pianoConto = \App\Models\Gestionale\PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC',
    ]);

    return [$condominio, $esercizio, $gestione, $pianoConto];
}

function collegaContoConcordanza(int $contoId, int $tabellaId, float $coeff, string $soggetto = 'proprietario', float $percent = 100): void
{
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $contoId, 'tabella_id' => $tabellaId,
        'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto,
        'percentuale' => $percent, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Le due invarianti che il documento deve rispettare per non contraddirsi:
 * ogni riga coincide con quanto addebitato, e le celle di quella riga la sommano.
 */
function verificaConcordanza(PianoRate $pianoRate, array $matrice): void
{
    $pianoRate->load('rate.rateQuote');
    $reali = [];
    foreach ($pianoRate->rate as $rata) {
        foreach ($rata->rateQuote as $rq) {
            $chiave = $rq->anagrafica_id . '|' . $rq->immobile_id;
            $reali[$chiave] = ($reali[$chiave] ?? 0) + (int) $rq->importo;
        }
    }

    foreach ($matrice['righe'] as $iid => $riga) {
        foreach ($riga['soggetti'] as $aid => $sogg) {
            expect($sogg['totale'])->toBe($reali["$aid|$iid"] ?? null, "riga $aid|$iid diversa da rate_quote");

            // ⚠️ È **questa** l'asserzione che morde. Il totale di riga viene letto da
            // `rate_quote`, quindi coincide per costruzione; sono le celle a essere ricalcolate,
            // ed è lì che le due metà divergono.
            $sommaCelle = array_sum(array_column($sogg['per_tabella'], 'importo'));
            expect($sommaCelle)->toBe($sogg['totale'], "celle di $aid|$iid non sommano al totale riga");
        }
    }

    expect(array_sum($matrice['tot_per_tabella']))->toBe($matrice['gran_totale']);
    expect(array_sum($reali))->toBe($matrice['gran_totale']);
}

function scenarioNudaProprieta(): array
{
    [$condominio, , $gestione, $pianoConto] = baseCondominioConcordanza();

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'GENERALE', 'quota' => 'millesimi',
    ]);

    // Unità 1 — nuda proprietà + usufrutto, **nessun proprietario**. È il caso della voce ⑩.
    // Unità 2 — proprietario pieno, la controprova: non deve cambiare niente per lei.
    $immobili = [];
    foreach ([1 => 600.0, 2 => 400.0] as $i => $millesimi) {
        $immobili[$i] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $i,
            'nome' => "App $i", 'codice_immobile' => "NP-$i", 'descrizione' => 'Test',
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $immobili[$i]->id,
            'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $nudoProprietario = Anagrafica::factory()->create(['nome' => 'Nudo Proprietario']);
    $usufruttuario    = Anagrafica::factory()->create(['nome' => 'Usufruttuario']);
    $proprietario     = Anagrafica::factory()->create(['nome' => 'Proprietario Pieno']);

    DB::table('anagrafica_immobile')->insert([
        [
            'anagrafica_id' => $nudoProprietario->id, 'immobile_id' => $immobili[1]->id,
            'tipologia' => 'nuda_proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ],
        [
            'anagrafica_id' => $usufruttuario->id, 'immobile_id' => $immobili[1]->id,
            'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ],
        [
            'anagrafica_id' => $proprietario->id, 'immobile_id' => $immobili[2]->id,
            'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
        ],
    ]);

    // Coefficiente sul `proprietario`: il default, e proprio quello che la stampa non risolveva.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'Manutenzione', 'tipo' => 'spesa', 'importo' => 100000,
    ]);
    collegaContoConcordanza($conto->id, $tabella->id, 100, 'proprietario');

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano nuda proprietà', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);
    app(GeneratePianoRateAction::class)->execute($pianoRate);

    return [$pianoRate, $tabella, $nudoProprietario, $proprietario, $immobili];
}

it('il nudo proprietario addebitato dal motore compare anche nella stampa', function () {
    [$pianoRate, $tabella, $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    $cella = $matrice['righe'][$immobili[1]->id]['soggetti'][$nudoProprietario->id]['per_tabella'][$tabella->id]['importo'] ?? null;

    // Prima della beta.49 questa cella era **0**: la stampa non entrava nella cascata e trattava
    // la quota come scoperta, mentre il motore aveva già addebitato € 600,00.
    expect($cella)->toBe(60000);
});

it('nessuna riga della stampa si contraddice: le celle sommano al totale addebitato', function () {
    [$pianoRate] = scenarioNudaProprieta();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    // `verificaInvarianti` esisteva già e sarebbe bastato: asserisce riga = `rate_quote` **e**
    // celle = riga. Nessuno l'aveva però mai puntato su uno scenario con nuda proprietà, ed è
    // per questo che la divergenza è vissuta undici giorni. Il difetto non era il test mancante:
    // era lo scenario mancante.
    verificaConcordanza($pianoRate, $matrice);
});

it('le colonne del documento sommano al gran totale', function () {
    [$pianoRate, $tabella] = scenarioNudaProprieta();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);

    // Con la cella del nudo proprietario a zero, la colonna valeva € 400,00 contro un gran totale
    // di € 1.000,00: chi leggeva il PDF in assemblea vedeva un totale di colonna che non tornava
    // con il totale generale, sullo stesso foglio.
    expect($matrice['tot_per_tabella'][$tabella->id])->toBe(100000)
        ->and($matrice['gran_totale'])->toBe(100000);
});

it('la nuda proprietà ha una sigla che la legenda conosce, e sta accanto al proprietario', function () {
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate);
    $soggetti = $matrice['righe'][$immobili[1]->id]['soggetti'];

    // La sigla cadeva sul ripiego `strtoupper(substr($ruolo, 0, 1))` e usciva «N», che nella
    // legenda del documento non esiste. Ed è ordinata accanto al proprietario, non in fondo dopo
    // l'inquilino: è lo stesso soggetto economico con l'usufrutto staccato.
    expect($soggetti[$nudoProprietario->id]['ruolo'])->toBe('NP')
        ->and(array_key_first($soggetti))->toBe($nudoProprietario->id);
});

it('il ripiego non butta via il terminale quando il ruolo richiesto non è in catalogo', function () {
    // La trappola era in entrambe le copie: `array_slice(catenaRiparto($x), 1)`. Per un soggetto
    // fuori catalogo la catena è il `default` — `[proprietario, nuda_proprietario]` — che **non**
    // comincia con il ruolo richiesto, quindi tagliare la testa toglieva `proprietario`, cioè il
    // terminale legale che il commento dell'enum dichiara di garantire contro i dati sporchi.
    $ripiego = \App\Enums\RuoloAnagraficaImmobile::catenaRipiego('condomino');

    expect(array_map(fn ($r) => $r->value, $ripiego))
        ->toBe(['proprietario', 'nuda_proprietario']);

    // Sui ruoli veri il taglio resta: il ruolo richiesto è già stato cercato e non trovato.
    expect(array_map(fn ($r) => $r->value, \App\Enums\RuoloAnagraficaImmobile::catenaRipiego('proprietario')))
        ->toBe(['nuda_proprietario']);
});

it('un titolare staccato dopo la generazione non rompe la quadratura del documento', function () {
    // ⚠️ **Il caso non è di laboratorio: è il rimedio che gli amministratori usano oggi per il
    // subentro**, perché il motore non legge le date di competenza — genero le rate, stacco il
    // vecchio proprietario dalla pivot, ristampo.
    //
    // Le sue quote restano in `rate_quote`, ma nella pivot non c'è più: tutte le sue celle
    // valgono zero mentre il totale di riga resta quello addebitato. Il riallineamento di
    // sicurezza non poteva soccorrere, perché è guardato da `$peseTot > 0.0` e per un soggetto
    // sparito i pesi sono tutti zero: non esiste una «tabella a peso maggiore» su cui appoggiare
    // il residuo.
    //
    // Risultato prima della beta.50: la riga non sommava alle sue celle e le colonne non
    // sommavano al gran totale — cioè cadeva l'invariante che `RipartoTabelleService` dichiara
    // nella propria intestazione come **garanzia legale**, sul foglio che va in assemblea.
    [$pianoRate, $tabella, $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    // Il distacco: la riga della pivot sparisce, le rate no.
    DB::table('anagrafica_immobile')
        ->where('anagrafica_id', $nudoProprietario->id)
        ->where('immobile_id', $immobili[1]->id)
        ->delete();

    $matrice = (new RipartoTabelleService())->buildMatrice($pianoRate->fresh());

    // La garanzia legale regge: riga = addebitato, celle = riga, colonne = gran totale.
    verificaConcordanza($pianoRate, $matrice);

    // E il residuo è finito dove ha un significato — la colonna che non appartiene a nessuna
    // tabella millesimale — non spalmato su una tabella a caso.
    $sogg = $matrice['righe'][$immobili[1]->id]['soggetti'][$nudoProprietario->id];
    expect($sogg['per_tabella'][RipartoTabelleService::COLONNA_DIRETTO]['importo'] ?? null)->toBe(60000)
        ->and($sogg['per_tabella'][$tabella->id]['importo'] ?? 0)->toBe(0);
});

/**
 * beta.51 — La stessa prova sull'ALTRA stampa.
 *
 * La beta.49 ha corretto la cascata dei ruoli in `RipartoTabelleService` e ha lasciato indietro
 * il gemello `RipartoCapitoliService`: delle due stampe che finiscono in assemblea ne è stata
 * sistemata una sola, e questo file presidiava solo quella. È il difetto che la revisione della
 * beta.51 ha trovato guardando la concordanza fra i motori invece che il diff.
 *
 * In `RipartoCapitoliService` la condizione era `if ($anagrafiche->isEmpty() && $rip->soggetto
 * !== 'proprietario')`: la cascata veniva saltata **proprio per il proprietario**, cioè
 * esattamente il caso della nuda proprietà. Con lo scenario qui sopra — coefficiente sul
 * proprietario, unità senza proprietario attivo — il motore addebitava € 600,00 al nudo
 * proprietario e questa stampa lo faceva sparire dal documento.
 *
 * Cosa questi due test NON coprono: `RipartoCapitoliService` non ha alcuna nozione di peso
 * scoperto (in tutto il file non compare il termine), quindi quando la cascata si esaurisce
 * davvero l'importo viene ancora scartato in silenzio con un `continue`, senza il riallineamento
 * che l'altra stampa possiede. È una differenza residua fra i due documenti, non toccata qui.
 */
it('anche la stampa per capitolo mostra il nudo proprietario addebitato dal motore', function () {
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    $matrice = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate);

    $totaleSoggetto = $matrice['righe'][$immobili[1]->id]['soggetti'][$nudoProprietario->id]['totale'] ?? null;

    // Prima della beta.51 il soggetto non compariva affatto in questa matrice: la cascata era
    // esclusa per il `proprietario` e il `continue` lo saltava del tutto.
    expect($totaleSoggetto)->toBe(60000);
});

it('la stampa per capitolo quadra con quella per tabella, sullo stesso piano', function () {
    [$pianoRate] = scenarioNudaProprieta();

    $perCapitolo = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate);
    $perTabella  = (new RipartoTabelleService())->buildMatrice($pianoRate);

    // Due documenti che raccontano lo stesso riparto in due modi: se i gran totali divergono,
    // uno dei due è sbagliato e l'amministratore non ha modo di sapere quale.
    expect($perCapitolo['gran_totale'])->toBe($perTabella['gran_totale'])
        ->and($perCapitolo['gran_totale'])->toBe(100000);
});

/* ─────────────────────────────────────────────────────────────────────────────
 * A6 — l'incrocio che non era di nessuno dei due test qui sopra.
 *
 * I due casi esistevano già separati e passavano entrambi: quello a `:241` dissocia ma verifica
 * **solo** la stampa per tabelle; quello qui sopra confronta le due stampe ma **non** dissocia.
 * Il difetto vive esattamente nella combinazione, ed è per questo che è sopravvissuto alla beta.50
 * — che ha chiuso lo stesso scenario (A3) su una stampa sola — e alla beta.51.
 *
 * La causa è strutturale, non un caso limite. `RipartoTabelleService` costruisce le righe iterando
 * `$totaliReali`, cioè le quote **realmente emesse** in `rate_quote`; `RipartoCapitoliService` le
 * costruisce da `$importiAssegnati`, cioè dai pesi **ricalcolati dal vivo sulla pivot**, e usa
 * `rate_quote` solo per riallineare righe che esistono già. Un soggetto dissociato dopo la
 * generazione ha le sue quote in `rate_quote` e non ha più pesi: **compare nella prima e sparisce
 * dalla seconda.** Non a zero — proprio assente, quindi le colonne quadrano fra loro e nessuna
 * invariante interna della stampa se ne accorge.
 *
 * ⚠️ **Non è un caso di laboratorio, ed è lo stesso di `:241`:** è il rimedio che gli
 * amministratori usano oggi per il subentro, perché il motore non legge le date di competenza —
 * genero le rate, stacco il vecchio proprietario, ristampo.
 * ───────────────────────────────────────────────────────────────────────────── */
it('un titolare staccato dopo la generazione non fa sparire il suo importo dalla stampa per capitoli', function () {
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    // Il distacco: la riga della pivot sparisce, le rate no. Identico a quello del test a `:241`.
    DB::table('anagrafica_immobile')
        ->where('anagrafica_id', $nudoProprietario->id)
        ->where('immobile_id', $immobili[1]->id)
        ->delete();

    $perCapitolo = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate->fresh());
    $perTabella  = (new RipartoTabelleService())->buildMatrice($pianoRate->fresh());

    // Il soggetto staccato vale 60000 su 100000 — lo fissa il test a `:241`. Se la stampa per
    // capitoli lo perde, il gran totale scende a 40000: **sei euro su dieci fuori dal foglio che
    // va in assemblea**, su un documento che nessuna invariante interna segnala come incompleto.
    expect($perCapitolo['gran_totale'])->toBe($perTabella['gran_totale'])
        ->and($perCapitolo['gran_totale'])->toBe(100000);

    // E non basta che il totale torni: il soggetto deve avere una riga propria, altrimenti il
    // documento quadra facendo pagare a qualcun altro.
    $riga = $perCapitolo['righe'][$immobili[1]->id]['soggetti'][$nudoProprietario->id] ?? null;

    expect($riga)->not->toBeNull()
        ->and($riga['totale'])->toBe(60000);
});

it('l\'importo del titolare staccato finisce nella colonna «Fuori riparto», non su un capitolo a caso', function () {
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    DB::table('anagrafica_immobile')
        ->where('anagrafica_id', $nudoProprietario->id)
        ->where('immobile_id', $immobili[1]->id)
        ->delete();

    $matrice = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate->fresh());
    $colonna = \App\Services\RipartoCapitoliService::COLONNA_FUORI_RIPARTO;

    // ⚠️ **Perché non su un capitolo vero.** Il dettaglio per capitolo di un soggetto dissociato
    // non è ricostruibile: `rate_quote.regole_calcolo` conserva `audit`, `importi`, `origine`,
    // `parametri` e `dettagli_saldo`, e in `importi` solo `saldo_usato`, `totale_calcolato` e
    // `quota_pura_gestione`. Attribuire l'importo a un capitolo sarebbe inventare un dato su un
    // documento che va in assemblea.
    expect($matrice['capitoli'])->toHaveKey($colonna)
        ->and($matrice['capitoli'][$colonna]['nome'])->toBe('Fuori riparto')
        // Nessuna dimensione di riparto: la sotto-colonna delle quote resta vuota invece di
        // mostrare un totale «0», che sarebbe un numero finto.
        ->and($matrice['capitoli'][$colonna]['senza_quote'])->toBeTrue()
        ->and($matrice['tot_per_capitolo'][$colonna])->toBe(60000);

    $perCapitolo = $matrice['righe'][$immobili[1]->id]['soggetti'][$nudoProprietario->id]['per_capitolo'];

    expect($perCapitolo[$colonna]['importo'])->toBe(60000)
        // La quota resta nulla: il template stampa «—» invece di un millesimo che non esiste.
        ->and($perCapitolo[$colonna]['quota'])->toBeNull();
});

it('il subentro fatto per intero non gonfia il documento', function () {
    // ⚠️ **Reperto della revisione avversariale della beta.52, e i tre test qui sopra non lo
    // prendevano:** staccano il vecchio titolare e si fermano lì. Nessuno **riattacca** un
    // subentrante, che è il passo successivo e obbligato del rimedio che gli amministratori usano
    // — stacco chi è uscito, attacco chi è entrato.
    //
    // Il subentrante ha pesi vivi sulla pivot e **zero quote in `rate_quote`**, perché il piano
    // era già stato generato. Il riallineamento lo saltava e gli lasciava il lordo ricalcolato dal
    // vivo: € 600,00 che nessuno deve. Misurato prima della correzione: gran totale 160000 contro
    // 100000 di rate emesse.
    //
    // ⚠️ Il difetto **preesisteva alla beta.52** ed era mascherato: il lordo fantasma era
    // compensato dall'assenza della riga dell'orfano, e i due errori si annullavano. Aggiungendo
    // la riga dell'orfano si sono sommati.
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    DB::table('anagrafica_immobile')->where('immobile_id', $immobili[1]->id)->delete();

    $subentrante = Anagrafica::factory()->create(['nome' => 'Subentrante']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $subentrante->id, 'immobile_id' => $immobili[1]->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    $perCapitolo = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate->fresh());
    $perTabella  = (new RipartoTabelleService())->buildMatrice($pianoRate->fresh());

    expect($perCapitolo['gran_totale'])->toBe($perTabella['gran_totale'])
        ->and($perCapitolo['gran_totale'])->toBe(100000);

    // L'importo dell'uscente resta visibile dove ha un significato, e il subentrante non porta
    // niente: non gli è stato addebitato niente.
    $colonna = \App\Services\RipartoCapitoliService::COLONNA_FUORI_RIPARTO;
    expect($perCapitolo['tot_per_capitolo'][$colonna])->toBe(60000);
});

it('IL PRESIDIO CHE MANCAVA: la matrice per capitoli quadra con rate_quote, non solo con la gemella', function () {
    // ⚠️ I test A6 confrontavano le due stampe fra loro — `perCapitolo === perTabella` — che regge
    // finché sbagliano **insieme**. `verificaConcordanza()` è l'unica funzione che asserisce
    // «gran totale = somma di `rate_quote`», e nessuno la puntava su questa matrice.
    //
    // È il difetto di metodo che la revisione ha trovato: un test il cui nome promette più di
    // quanto il corpo verifichi.
    [$pianoRate, , $nudoProprietario, , $immobili] = scenarioNudaProprieta();

    DB::table('anagrafica_immobile')->where('immobile_id', $immobili[1]->id)->delete();
    $subentrante = Anagrafica::factory()->create(['nome' => 'Subentrante']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $subentrante->id, 'immobile_id' => $immobili[1]->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now(),
    ]);

    $matrice = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate->fresh());

    $reali = 0;
    foreach ($pianoRate->fresh()->rate as $rata) {
        foreach ($rata->rateQuote as $rq) {
            $reali += (int) round($rq->importo);
        }
    }

    expect($matrice['gran_totale'])->toBe($reali)
        ->and(array_sum($matrice['tot_per_capitolo']))->toBe($matrice['gran_totale']);
});

it('la colonna «Fuori riparto» non compare quando non serve', function () {
    // La controprova, che vale quanto il caso positivo: una pseudo-colonna che comparisse sempre
    // aggiungerebbe una colonna vuota a ogni riparto stampato dal gestionale.
    [$pianoRate] = scenarioNudaProprieta();

    $matrice = (new \App\Services\RipartoCapitoliService())->buildMatrice($pianoRate);

    expect($matrice['capitoli'])->not->toHaveKey(\App\Services\RipartoCapitoliService::COLONNA_FUORI_RIPARTO);
});
