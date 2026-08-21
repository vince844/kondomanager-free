<?php

/**
 * Lo zero scritto apposta arriva anche sulla carta, non solo nella pagina di modifica.
 *
 * ## Da dove nasce
 *
 * Segnalazione dal forum, agosto 2026. Un amministratore chiede di poter scrivere **zero** come
 * millesimo di un'unità in una tabella, citando la separazione dei beni dell'art. 1123 c.3 c.c.:
 * *«ritengo che vada data la possibilità di gestire questo caso»*.
 *
 * La beta.61 gliel'ha data in **ingresso**: lo zero si salva, resta distinto dalla casella vuota e
 * il motore lo tratta come «non partecipa» — escluso dal riparto **e** dal divisore.
 *
 * Ma il senso di scrivere zero invece di togliere la riga è **documentale**: mettere agli atti che
 * quell'unità è stata considerata e non partecipa, così in assemblea la tabella si legge e regge a
 * un'eventuale impugnazione. E in uscita quel senso si perdeva: `RipartoTabelleService` saltava la
 * quota con la stessa condizione del motore (`$valore <= 0.0`) **prima** di registrarla, quindi nel
 * PDF del riparto la cella usciva con un trattino — indistinguibile da un'unità che nella tabella
 * non c'è proprio.
 *
 * Lo zero valeva insomma solo per chi apriva la schermata di modifica. Sul documento che va in
 * assemblea, le due forme che il programma dichiara diverse tornavano identiche.
 *
 * ## Cosa cambia
 *
 * La quota si registra **prima** della guardia: il valore zero entra nella matrice della stampa e
 * la cella scrive `0,00`. L'aritmetica non si tocca — nessun peso, nessun euro, il divisore resta
 * quello di prima — cambia solo che il documento dice quello che l'amministratore ha scritto.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'unità che nel piano **non ha nessun addebito**: quella sparisce dal documento per una
 * ragione diversa (`RipartoTabelleService:229`, `importoTotale === 0`), e rimetterla vorrebbe dire
 * righe di soli zeri su ogni riparto. È una scelta di presentazione a sé, annotata in roadmap.
 *
 * Non copre la resa grafica della cella — il colore e il fondo si guardano a video.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Services\RipartoCapitoliService;
use App\Services\RipartoTabelleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Una tabella «scale» parziale: due unità partecipano, il negozio è dentro con **zero**.
 *
 * È la forma che la segnalazione chiedeva: il negozio non è stato dimenticato, è stato considerato
 * e messo a zero.
 */
function scenarioTabellaConZero(): array
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id'  => $esercizio->id,
        'nome'          => 'Ordinaria 2026',
        'tipo'          => 'ordinaria',
        'descrizione'   => 'Manutenzione scale',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'PC 2026',
    ]);

    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Manutenzione scale',
        'tipo'           => 'spesa',
        'natura_spesa'   => 'ordinaria',
        'importo'        => 300000,   // € 3.000,00
    ]);

    /*
     * ⚠️ **Serve un secondo capitolo, e non è zavorra dello scenario: è il caso vero.**
     *
     * Un negozio escluso dalle scale partecipa comunque alle spese generali — è per questo che
     * compare sul riparto e che la colonna «SCALE» accanto al suo nome deve dire qualcosa. Se
     * costruissi un piano con il solo capitolo scale, il negozio non avrebbe **nessun** addebito e
     * la sua riga sparirebbe dal documento per una ragione diversa (`RipartoTabelleService:229`,
     * `importoTotale === 0`), che è una scelta di presentazione a sé e resta in roadmap.
     */
    $contoGenerali = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Spese generali',
        'tipo'           => 'spesa',
        'natura_spesa'   => 'ordinaria',
        'importo'        => 300000,   // € 3.000,00
    ]);

    $tabellaGenerale = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'GENERALE',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    $pivotGen = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $contoGenerali->id,
        'tabella_id'   => $tabellaGenerale->id,
        'coefficiente' => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotGen,
        'soggetto'                     => 'proprietario',
        'percentuale'                  => 100,
        'created_at'                   => now(),
        'updated_at'                   => now(),
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'SCALE',
        'tipo'          => 'scale',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $conto->id,
        'tabella_id'   => $tabella->id,
        'coefficiente' => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto'                     => 'proprietario',
        'percentuale'                  => 100,
        'created_at'                   => now(),
        'updated_at'                   => now(),
    ]);

    $unita = [];

    // Due appartamenti che pagano, e il negozio con ingresso su strada messo a zero.
    // La quarta unità ha il millesimo **non compilato** (`null`), che è il terzo stato della
    // beta.61 e non va confuso con lo zero: serve a provare che sulla carta restano distinti.
    foreach ([['App 1', 500.0], ['App 2', 500.0], ['Negozio', 0.0], ['Da compilare', null]] as $n => [$nome, $valore]) {
        $immobile = Immobile::create([
            'condominio_id'   => $condominio->id,
            'tipo'            => 'appartamento',
            'codice_immobile' => 'C1-00'.($n + 1),
            'nome'            => $nome,
            'descrizione'     => $nome,
            'interno'         => (string) ($n + 1),
        ]);

        DB::table('quote_tabella')->insert([
            'tabella_id'  => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore'      => $valore,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Nella tabella generale ci sono tutti e tre, negozio compreso: è la ragione per cui il
        // negozio compare sul riparto anche se dalle scale è escluso.
        DB::table('quote_tabella')->insert([
            'tabella_id'  => $tabellaGenerale->id,
            'immobile_id' => $immobile->id,
            'valore'      => 333.33,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $anagrafica = Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafica->id,
            'immobile_id'   => $immobile->id,
            'tipologia'     => 'proprietario',
            'quota'         => 100,
            'attivo'        => true,
            'data_inizio'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $unita[$nome] = ['immobile' => $immobile, 'anagrafica' => $anagrafica];
    }

    $pianoRate = PianoRate::create([
        'gestione_id'   => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano scale',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
    ]);

    app(GeneratePianoRateAction::class)->execute($pianoRate, accettaScoperti: true, notaScoperti: 'verifica');

    return ['piano' => $pianoRate, 'tabella' => $tabella, 'unita' => $unita];
}

test('il negozio a zero compare nella matrice della stampa, con quota 0', function () {
    // ⚠️ È il difetto: prima della correzione la cella non esisteva affatto nella matrice, quindi
    // il PDF scriveva un trattino — la stessa cosa che scrive per un'unità che nella tabella non
    // c'è. Le due forme che il programma dichiara diverse tornavano identiche sulla carta.
    $s = scenarioTabellaConZero();

    $matrice = (new RipartoTabelleService())->buildMatrice($s['piano']);

    $negozio = $s['unita']['Negozio'];
    $cella = $matrice['righe'][$negozio['immobile']->id]['soggetti'][$negozio['anagrafica']->id]['per_tabella'][$s['tabella']->id] ?? null;

    // ⚠️ **`toBeNull` sulla quota, non un cast a float.** La cella esiste **sempre** — è costruita
    // per ogni tabella con `$quoteMill[$tabId][$immobileId] ?? null` — quindi asserire che la cella
    // c'è non prova niente. E `(float) null` è `0.0`, cioè la prima stesura di questo test passava
    // **senza** la correzione, dicendo il contrario di quello che il suo nome prometteva. È il
    // segnale d'allarme che questo progetto si è già scritto tre volte, e mi ci sono infilato.
    //
    // Quello che conta è la distinzione fra `null` («questa unità nella tabella non c'è», che il
    // PDF scrive «—») e `0.0` («c'è, con zero», che il PDF scrive «0,00»).
    expect($cella['quota'])->not->toBeNull('Il negozio a zero non è registrato: il PDF scriverebbe «—», indistinguibile da un\'unità assente dalla tabella.')
        ->and((float) $cella['quota'])->toBe(0.0);
});

test('e non paga niente: lo zero resta «non partecipa», non diventa un addebito', function () {
    // La controprova che conta. Far comparire lo zero sul documento non deve trasformarlo in una
    // quota: è **presentazione**, non aritmetica. Se questo test cadesse, la correzione avrebbe
    // fatto pagare qualcosa a chi la legge esclude.
    $s = scenarioTabellaConZero();

    $matrice = (new RipartoTabelleService())->buildMatrice($s['piano']);

    $negozio = $s['unita']['Negozio'];
    $cella = $matrice['righe'][$negozio['immobile']->id]['soggetti'][$negozio['anagrafica']->id]['per_tabella'][$s['tabella']->id];

    expect((int) $cella['importo'])->toBe(0);
});

test('i due che partecipano si dividono tutta la spesa, come prima', function () {
    // Il divisore resta la somma effettiva — 1000, non 1000 più lo zero — quindi € 1.500,00 a testa.
    // È la garanzia che la correzione non ha toccato il motore.
    $s = scenarioTabellaConZero();

    $matrice = (new RipartoTabelleService())->buildMatrice($s['piano']);

    $importi = [];
    foreach (['App 1', 'App 2'] as $nome) {
        $u = $s['unita'][$nome];
        $importi[] = (int) $matrice['righe'][$u['immobile']->id]['soggetti'][$u['anagrafica']->id]['per_tabella'][$s['tabella']->id]['importo'];
    }

    expect($importi)->toBe([150000, 150000]);
});

test("il millesimo non ancora compilato resta un trattino, non diventa uno zero", function () {
    // ⚠️ **È il difetto che la revisione avversariale ha trovato nella prima stesura di questa
    // correzione.** Registrando la quota prima della guardia si registrava anche il `null`, perché
    // `(float) null` è `0.0`: sulla carta un millesimo **non ancora compilato** usciva «0,00»,
    // cioè affermava «considerata ed esclusa» dove il dato dice «manca il numero».
    //
    // Sono i tre stati che la beta.61 ha separato con fatica, e la correzione li rimetteva insieme
    // proprio sul documento che va in assemblea. Qui si prova che restano distinti: `null` → «—»,
    // `0.0` → «0,00».
    $s = scenarioTabellaConZero();

    $matrice = (new RipartoTabelleService())->buildMatrice($s['piano']);

    $daCompilare = $s['unita']['Da compilare'];
    $cella = $matrice['righe'][$daCompilare['immobile']->id]['soggetti'][$daCompilare['anagrafica']->id]['per_tabella'][$s['tabella']->id] ?? null;

    expect($cella)->not->toBeNull()
        ->and($cella['quota'])->toBeNull('Il millesimo non compilato è stato registrato come zero: sulla carta dice «esclusa» invece di «manca il numero».');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * La gemella: la stampa «per capitoli»
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ⚠️ **Il riparto si stampa in due modi e i due servizi sono due copie della stessa logica.**
 * `RipartoCapitoliService` aveva la riga identica — registrazione del millesimo *dopo* il
 * `continue` sul peso — e quindi lo stesso difetto: lo zero documentato non arrivava al PDF.
 * È stato corretto insieme al primo, ma correggere non basta: senza un test la copia ricade da
 * sola alla prima modifica che tocca solo l'altra. È la lezione che questo progetto ha già pagato
 * nella beta.49, quando la seconda copia calcolava per conto suo.
 */
test('anche la stampa per capitoli mostra lo zero documentato', function () {
    $s = scenarioTabellaConZero();

    $matrice = (new RipartoCapitoliService())->buildMatrice($s['piano']);

    $negozio = $s['unita']['Negozio'];
    $riga = $matrice['righe'][$negozio['immobile']->id]['soggetti'][$negozio['anagrafica']->id] ?? null;

    expect($riga)->not->toBeNull('Il negozio deve comparire anche sul riparto per capitoli');

    // Si cerca la colonna del capitolo «Manutenzione scale»: è quella in cui il negozio è a zero.
    $capitoloScale = collect($matrice['capitoli'] ?? [])
        ->search(fn ($info) => ($info['nome'] ?? null) === 'Manutenzione scale');

    expect($capitoloScale)->not->toBeFalse('Il capitolo delle scale deve essere una colonna della stampa');

    $cella = $riga['per_capitolo'][$capitoloScale] ?? null;

    // Stessa avvertenza dell'altro file: la cella esiste sempre con `quota => null`, e
    // `(float) null` vale `0.0`. Ciò che si asserisce è che la quota **non è null** — cioè che il
    // millesimo scritto a zero è arrivato fin qui — e che vale davvero zero.
    expect($cella)->not->toBeNull()
        ->and($cella['quota'])->not->toBeNull('Lo zero documentato non è arrivato alla stampa per capitoli')
        ->and((float) $cella['quota'])->toBe(0.0)
        ->and($cella['importo'])->toBe(0);
});
