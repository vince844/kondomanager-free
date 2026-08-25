<?php

/**
 * Un capitolo le cui tabelle non arrivano al 100% non addebita il 100% a chi c'è.
 *
 * ## Il difetto
 *
 * Una voce di spesa si collega alle tabelle millesimali con un **coefficiente percentuale**: è la
 * forma con cui il gestionale rappresenta le ripartizioni a quote fisse fra platee diverse, e la
 * materia condominiale ne è piena — l'art. 1126 c.c. (un terzo a chi ha l'uso esclusivo del
 * lastrico, due terzi ai condòmini serviti), l'art. 1124 (metà valore, metà altezza), l'art. 1125.
 *
 * `AssociaTabellaController` blocca la somma dei coefficienti **sopra** il 100. Sotto, niente. E
 * `CalcoloQuoteService` rinormalizza i pesi a 1 (`$w / $pesoSoggetti`), quindi qualunque cosa i
 * coefficienti sommino, **il 100% della spesa viene distribuito lo stesso** — sulle sole unità
 * delle tabelle collegate.
 *
 * ⚠️ **È una guardia scritta in un verso solo**, la famiglia che questo progetto ha già pagato
 * nella beta.41 e nella beta.45: *cosa succede se lo scrivo quando non dovrei* era stato chiesto,
 * *cosa succede se non lo scrivo quando dovrei* no.
 *
 * ## Lo scenario, che non è di laboratorio
 *
 * Rifacimento del lastrico solare, € 9.000. L'amministratore crea la tabella «Lastrico uso
 * esclusivo» con dentro il solo titolare, la collega al capitolo con coefficiente **33,33** — è
 * la frazione che l'art. 1126 assegna a chi ha l'uso — e si ripromette di aggiungere subito dopo
 * la tabella dei condòmini serviti al 66,67.
 *
 * Se genera il piano **prima** di aggiungerla, il titolare del lastrico riceve **€ 9.000 invece di
 * € 3.000**: paga tre volte quello che la legge gli assegna, e nessun controllo dice niente perché
 * il totale del piano quadra col preventivo.
 *
 * ## La correzione
 *
 * La parte non dichiarata dai coefficienti diventa uno **scoperto**, cioè passa dal canale che il
 * progetto ha già: l'importo distribuito si decurta, la generazione si ferma dicendo quale capitolo
 * e quanto manca, e l'amministratore può procedere lo stesso scrivendo il perché. Non è un
 * meccanismo nuovo — è quello del millesimo non compilato della beta.61, applicato a un'altra
 * causa. L'ultima parola resta di chi firma il piano.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la somma **sopra** il 100, che era già bloccata al momento dell'associazione. Non copre
 * il warning nel modulo della voce di spesa — quello avvisa *mentre si configura*, sta in
 * `docs/roadmap.md` sotto Iniziativa A ed è collocato in v1.11: è l'altra metà, e le due non si
 * sostituiscono. Non copre la qualificazione giuridica di quale sia la platea giusta: quella è di
 * chi delibera, non del programma.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Il capitolo del lastrico: € 9.000, una sola tabella collegata con il coefficiente indicato.
 *
 * Prende l'**elenco** dei coefficienti: una tabella collegata per ciascuno, tutte con la stessa
 * unità dentro. `[33.33]` è lo scenario del difetto; `[100]` è la controprova; `[33.33, 33.33,
 * 33.33]` è il caso in cui la somma fa 99,99 per la sola precisione della colonna.
 */
function scenarioLastrico(array $coefficienti): array
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
        'descrizione'   => 'Gestione con il capitolo del lastrico',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'PC 2026',
    ]);

    // € 9.000,00 in centesimi interi, come vuole la convenzione del progetto.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Rifacimento lastrico solare',
        'tipo'           => 'spesa',
        'natura_spesa'   => 'ordinaria',
        'importo'        => 900000,
    ]);

    // Il titolare dell'uso esclusivo: l'unica unità dentro queste tabelle.
    $immobile = Immobile::create([
        'condominio_id'   => $condominio->id,
        'tipo'            => 'appartamento',
        'codice_immobile' => 'C1-ATT',
        'nome'            => 'Attico',
        'descrizione'     => 'Con lastrico a uso esclusivo',
        'interno'         => '10',
    ]);

    foreach ($coefficienti as $n => $coefficiente) {
        $tabella = Tabella::create([
            'condominio_id' => $condominio->id,
            'nome'          => 'Lastrico — platea '.($n + 1),
            'tipo'          => 'lastrico',
            'quota'         => 'millesimi',
            'attiva'        => true,
        ]);

        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id'     => $conto->id,
            'tabella_id'   => $tabella->id,
            'coefficiente' => $coefficiente,
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

        DB::table('quote_tabella')->insert([
            'tabella_id'  => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore'      => 1000.0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

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

    $pianoRate = PianoRate::create([
        'gestione_id'   => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano lastrico',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
    ]);

    return ['piano' => $pianoRate, 'immobile' => $immobile, 'conto' => $conto];
}

/** Quanto è stato addebitato in tutto all'unità, in centesimi. */
function addebitatoAllUnita(int $immobileId): int
{
    return (int) DB::table('rate_quote')->where('immobile_id', $immobileId)->sum('importo');
}

test('con una sola tabella al 33,33% si addebita un terzo, non tutto', function () {
    // ⚠️ È il difetto: prima della correzione qui passavano **900000** centesimi, cioè € 9.000
    // interi sul titolare del lastrico invece dei € 3.000 che l'art. 1126 gli assegna.
    $s = scenarioLastrico([33.33]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: true, notaScoperti: 'Verifica');

    // 900000 × 0,3333 = 299970 centesimi, cioè € 2.999,70. Non è € 3.000,00 tondi perché il
    // coefficiente è `decimal(5,2)` e «un terzo» non ci sta: lo scarto è dichiarato, non nascosto.
    expect(addebitatoAllUnita($s['immobile']->id))->toBe(299970);
});

test('la parte non dichiarata dai coefficienti risulta scoperta, e ferma la generazione', function () {
    // Senza questo, la decurtazione sarebbe silenziosa: il piano coprirebbe un terzo della spesa
    // e nessuno se ne accorgerebbe. Lo scoperto è ciò che rende la decurtazione **visibile**.
    $s = scenarioLastrico([33.33]);

    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});

test("l'amministratore può procedere lo stesso, motivando", function () {
    // L'ultima parola resta di chi firma il piano: è il principio che la roadmap dichiara e che
    // vale in tutti gli altri punti del prodotto in cui compare uno scoperto.
    $s = scenarioLastrico([33.33]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: true, notaScoperti: 'Manca la tabella dei serviti');

    expect($s['piano']->fresh()->nota_scoperti)->toBe('Manca la tabella dei serviti');
});

test('con il 100% dichiarato non cambia niente: nessuno scoperto e la spesa si copre tutta', function () {
    // ⚠️ **La controprova, e non è un di più.** Una correzione che decurta deve lasciare
    // esattamente com'erano i capitoli configurati bene — che sono tutti quelli oggi a database
    // (misurato il 21/08/2026: 18 su 18 sommano 100). Senza questo test, una soglia sbagliata
    // decurterebbe anche loro e il difetto sarebbe peggiore di quello che cura.
    $s = scenarioLastrico([100]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: false);

    expect(addebitatoAllUnita($s['immobile']->id))->toBe(900000);
});

test('tre tabelle a un terzo non fanno scattare niente: 99,99 è rumore della colonna', function () {
    // ⚠️ **La soglia esiste per questo caso, ed è la ragione per cui non è zero.** Il coefficiente
    // è `decimal(5,2)`: tre platee in parti uguali sommano **99,99**, non 100, e non c'è modo di
    // scriverlo meglio. Una guardia severa segnalerebbe ogni ripartizione in terzi — cioè
    // griderebbe al lupo, che è la lezione della beta.60: *una guardia che grida troppo si spegne*.
    //
    // La tolleranza è **0,05 punti**, scelta sulla precisione della colonna e non a occhio: con
    // due decimali, N platee in parti uguali perdono al massimo N × 0,005 punti, quindi 0,05
    // copre fino a dieci tabelle sullo stesso capitolo. Sopra quella soglia non è arrotondamento:
    // è una tabella che manca.
    // 33,33 × 3 = 99,99: sotto la tolleranza, quindi nessuno scoperto e la spesa si copre tutta.
    $s = scenarioLastrico([33.33, 33.33, 33.33]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: false);

    expect(addebitatoAllUnita($s['immobile']->id))->toBe(900000);
});

test('a esattamente 99,95 la soglia non scatta: è il limite che dichiara accettabile', function () {
    // ⚠️ **Il caso limite, e prima decideva il rumore in virgola mobile.** La costante dichiara
    // che 0,05 punti mancanti sono tolleranza; con il confronto in frazione, `1.0 - 0.9995` vale
    // `0.0005000000000000004` e la superava — segnalando proprio il caso che voleva ammettere.
    // Il confronto è ora in punti percentuali arrotondati a due decimali, che è l'unità in cui la
    // colonna è scritta.
    $s = scenarioLastrico([99.95]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: false);

    expect(addebitatoAllUnita($s['immobile']->id))->toBe(900000);
});

test('a 99,94 la soglia scatta: un punto sotto il limite non è più arrotondamento', function () {
    // La controprova del test qui sopra. Senza, una soglia messa per sbaglio a «maggiore o uguale»
    // — o alzata di un decimale — passerebbe inosservata e la guardia smetterebbe di guardare.
    $s = scenarioLastrico([99.94]);

    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});
