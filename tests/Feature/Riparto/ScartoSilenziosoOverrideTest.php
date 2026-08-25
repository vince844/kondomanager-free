<?php

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
 * I tre scarti silenziosi del motore di riparto.
 *
 * Un piano rate **forza sempre** l'importo dei suoi capitoli (`piano_rate_capitoli.importo`),
 * quindi `processaConti` entra nel ramo `$hasOverride`. Da lì si arriva a tre punti in cui
 * l'importo può sparire senza che nessuno se ne accorga: nessuna eccezione, nessuno scoperto,
 * solo una riga di `Log::warning` che l'amministratore non vede mai.
 *
 * Il sintomo è quello segnalato da Vincenzo il 06/08/2026: il cruscotto dice «Disallineato:
 * ricalcola!», si clicca «Ricalcola», la finestra risponde «Operazione Completata» e non cambia
 * niente. Per sempre — nessun numero di clic può risolverlo.
 *
 * ## L'asserzione non è «esce un errore»
 *
 * È che **la somma delle quote generate uguaglia l'importo pianificato dei capitoli**. È la
 * proprietà che oggi si rompe in silenzio, ed è la stessa che il motore già calcola per conto
 * suo in `calcolaPerGestione` (`delta_non_ripartito`, :141-155) limitandosi a scriverla nel log.
 *
 * Un test che verificasse solo il lancio dell'eccezione passerebbe anche con una correzione che
 * blocca e continua a perdere i soldi.
 *
 * ## Perché ogni scenario ha DUE capitoli, e uno è sano
 *
 * `GeneratePianoRateAction:249` blocca già oggi quando le quote calcolate sono **zero**, con un
 * messaggio che nomina tutte e tre le cause. Quel caso è coperto e non va rifatto.
 *
 * Il difetto vive nella perdita **parziale**, che è esattamente il caso segnalato: piano 207 di
 * Demo KM, cinque capitoli per € 35.661,60, di cui uno solo con una tabella collegata. Vengono
 * generati € 2.400,00, la guardia dello zero non scatta perché qualcosa è stato generato, e
 * € 33.261,60 spariscono senza che niente lo dica.
 *
 * Un capitolo solo, rotto, avrebbe dato un test verde contro una guardia che esisteva già — la
 * trappola della beta.40: *«il test che sarebbe servito somigliava molto a uno che c'era già»*.
 *
 * ## Cosa questi test NON coprono
 *
 * - Il caso `$coeff <= 0` sulla pivot `conto_tabella_millesimale` (`:653`), che è il quarto ramo
 *   della stessa famiglia: stessa forma, nessun caso reale osservato, non riprodotto qui.
 * - I piani **straordinari** su fatture, che passano da `righe_fattura` e non dalla pivot dei
 *   capitoli: il ramo con override è lo stesso, il modo di popolarlo no.
 * - La **stampa** del riparto (`RipartoTabelleService`), che ha gli stessi `continue` su tabella
 *   vuota e somma zero. È la coda ⑩ in roadmap e resta scoperta per scelta.
 * - La perdita totale (tutti i capitoli rotti): la copre `GeneratePianoRateAction:249`, ed è il
 *   motivo per cui questi scenari tengono sempre un capitolo sano.
 */

/**
 * Scenario: due unità con proprietario attivo, e un piano rate con **due** capitoli forzati via
 * pivot — «Spese generali» da € 2.400,00, sano e con la sua tabella compilata, e «Manutenzione
 * idraulica» da € 1.000,00, che ogni test rompe a modo suo.
 *
 * Le anagrafiche ci sono apposta: così nessuno degli scoperti storici (unità senza soggetto,
 * v1.9.1) può scattare, e se l'importo sparisce è per il motivo che il test sta cercando.
 *
 * Il capitolo sano serve a tenere spenta la guardia dello zero — vedi il commento in testa.
 */
function scenarioCapitoloForzatoScartoSilenzioso(): array
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'nome' => 'Gestione Ordinaria',
        'tipo' => 'ordinaria',
        'descrizione' => 'Gestione Ordinaria',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'PC 2026',
    ]);

    // Il capitolo che ogni test rompe a modo suo.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => 'Manutenzione idraulica',
        'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria',
        'importo' => 100000,
    ]);

    // Il capitolo sano, che tiene spenta la guardia dello zero di :249.
    $contoSano = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome' => 'Spese generali',
        'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria',
        'importo' => 240000,
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome' => 'Piano 2026',
        'stato' => 'bozza',
        'tipo' => 'ordinario',
        'numero_rate' => 1,
    ]);

    // L'importo forzato dal piano: è questo che accende il ramo $hasOverride.
    $pianoRate->capitoli()->attach($conto->id, ['importo' => 100000]);
    $pianoRate->capitoli()->attach($contoSano->id, ['importo' => 240000]);

    $immobili = collect(['1', '2'])->map(function (string $interno) use ($condominio) {
        $immobile = Immobile::create([
            'condominio_id' => $condominio->id,
            'tipo' => 'appartamento',
            'codice_immobile' => "C1-00{$interno}",
            'nome' => "Int {$interno}",
            'descrizione' => "Unità di prova {$interno}",
            'interno' => $interno,
            'scala' => 'A',
        ]);

        $anagrafica = Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario',
            'quota' => 100,
            'attivo' => true,
            'data_inizio' => now(),
        ]);

        return $immobile;
    });

    // La tabella del capitolo sano, compilata: 500 + 500.
    $tabellaSana = collegaTabellaScartoSilenzioso($contoSano, $condominio, 'Millesimi generali');
    foreach ($immobili as $immobile) {
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabellaSana->id,
            'immobile_id' => $immobile->id,
            'valore' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [
        'condominio' => $condominio,
        'gestione' => $gestione,
        'conto' => $conto,
        'contoSano' => $contoSano,
        'pianoRate' => $pianoRate,
        'immobili' => $immobili,
    ];
}

function collegaTabellaScartoSilenzioso(Conto $conto, Condominio $condominio, string $nome): Tabella
{
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => $nome,
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'attiva' => true,
    ]);

    DB::table('conto_tabella_millesimale')->insert([
        'conto_id' => $conto->id,
        'tabella_id' => $tabella->id,
        'coefficiente' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tabella;
}

/** La somma di tutte le quote generate, in centesimi. */
function totaleQuoteGenerateScartoSilenzioso(PianoRate $pianoRate): int
{
    return (int) DB::table('rate_quote')
        ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
        ->where('rate.piano_rate_id', $pianoRate->id)
        ->sum('rate_quote.importo');
}

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1 — coda ⑨: capitolo forzato senza tabelle e senza sottoconti
// ─────────────────────────────────────────────────────────────────────────────

test('capitolo forzato senza tabelle: la generazione si ferma invece di scartare in silenzio', function () {
    $s = scenarioCapitoloForzatoScartoSilenzioso();
    // Nessuna tabella collegata, nessun sottoconto: è il caso di CalcoloQuoteService:569.

    $action = app(GeneratePianoRateAction::class);

    expect(fn () => $action->execute($s['pianoRate'], accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});

test('capitolo forzato senza tabelle: forzando, i mille euro restano contati come scoperto', function () {
    $s = scenarioCapitoloForzatoScartoSilenzioso();
    $action = app(GeneratePianoRateAction::class);

    $action->execute($s['pianoRate'], accettaScoperti: true, notaScoperti: 'Collego la tabella domani');

    $s['pianoRate']->refresh();

    // Forzando si procede — ma la motivazione resta scritta, ed è la prova che il sistema
    // ha *saputo* di quei € 1.000,00 invece di perderli senza accorgersene.
    expect($s['pianoRate']->nota_scoperti)->toBe('Collego la tabella domani');

    // E si distribuisce solo il capitolo sano: € 2.400,00, non € 3.400,00.
    // È corretto — quei mille euro non sono assegnabili a nessuno — ma ora è *dichiarato*.
    expect(totaleQuoteGenerateScartoSilenzioso($s['pianoRate']))->toBe(240000);
});

// ─────────────────────────────────────────────────────────────────────────────
// Caso 2 — §7.2: la tabella c'è, ma non ha immobili assegnati
// ─────────────────────────────────────────────────────────────────────────────

test('tabella collegata ma senza immobili: la generazione si ferma invece di scartare in silenzio', function () {
    $s = scenarioCapitoloForzatoScartoSilenzioso();
    collegaTabellaScartoSilenzioso($s['conto'], $s['condominio'], 'Millesimi generali');
    // La tabella esiste ed è collegata al capitolo, ma `quote_tabella` è vuota.
    // È il caso che Vincenzo ha descritto: CalcoloQuoteService:661 → :765, return nudo.

    $action = app(GeneratePianoRateAction::class);

    expect(fn () => $action->execute($s['pianoRate'], accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Caso 3 — §7.2: la tabella ha gli immobili, ma tutti i valori sono a zero
// ─────────────────────────────────────────────────────────────────────────────

test('tabella con valori tutti a zero: la generazione si ferma invece di scartare in silenzio', function () {
    $s = scenarioCapitoloForzatoScartoSilenzioso();
    $tabella = collegaTabellaScartoSilenzioso($s['conto'], $s['condominio'], 'Millesimi generali');

    foreach ($s['immobili'] as $immobile) {
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $action = app(GeneratePianoRateAction::class);

    expect(fn () => $action->execute($s['pianoRate'], accettaScoperti: false))
        ->toThrow(ScopertiNonAccettatiException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// La controprova: il caso sano non deve cambiare comportamento
// ─────────────────────────────────────────────────────────────────────────────

test('con entrambe le tabelle compilate i capitoli si distribuiscono per intero e non si blocca niente', function () {
    $s = scenarioCapitoloForzatoScartoSilenzioso();
    $tabella = collegaTabellaScartoSilenzioso($s['conto'], $s['condominio'], 'Millesimi idraulico');

    foreach ($s['immobili'] as $immobile) {
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $action = app(GeneratePianoRateAction::class);
    $action->execute($s['pianoRate'], accettaScoperti: false);

    // € 3.400,00 pianificati, € 3.400,00 addebitati. È la proprietà che i tre casi
    // qui sopra rompono in silenzio, ed è il vero oggetto di questo file.
    expect(totaleQuoteGenerateScartoSilenzioso($s['pianoRate']))->toBe(340000);
});
