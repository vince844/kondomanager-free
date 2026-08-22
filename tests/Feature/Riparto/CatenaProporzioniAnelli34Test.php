<?php

/**
 * # La catena delle proporzioni: gli anelli 3 e 4
 *
 * ## Di cosa fa parte questo file
 *
 * `CalcoloQuoteService::distribuisciSuTabelle()` trasforma una spesa in quote passando per
 * **quattro proporzioni in catena**, e in fondo alla catena c'è
 * `$weights[$key] = $w / $pesoSoggetti`, che **rinormalizza tutto a 1**.
 *
 * ⚠️ **Quella riga è il motivo per cui nessuno di questi difetti è visibile.** Qualunque cosa manchi
 * a monte, il totale del piano coincide sempre col preventivo: la quadratura torna, nessun controllo
 * contabile ha niente da segnalare. Il difetto non si manifesta come «i conti non tornano» ma come
 * **denaro sulla persona sbagliata, con i conti che tornano perfettamente** — ed è per questo che
 * questi difetti emergono uno alla volta, dal forum, perché solo un amministratore vero può
 * accorgersene.
 *
 * | anello | da → a | chiuso in |
 * | :--- | :--- | :--- |
 * | 1 | capitolo → tabella | beta.63 — la parte non dichiarata diventa scoperto |
 * | 2 | tabella → unità | beta.61 e .63 — millesimo vuoto, zero, negativo |
 * | 3 | ripartizione → ruolo | **beta.69, questo file** |
 * | 4 | ruolo → persona | **beta.69, questo file** |
 *
 * ## Cosa hanno detto le sonde, il 22/08/2026
 *
 * Due unità da 500 millesimi, spesa € 1.000,00, tabella al 100%:
 *
 * | scenario | unità 1 | unità 2 | |
 * | :--- | ---: | ---: | :--- |
 * | ripartizioni per ruolo che sommano **60** | € 500,00 | € 500,00 | 🔴 dichiarato 60%, addebitato 100% |
 * | un ruolo con **tutte le quote a zero** | € 666,67 | € 333,33 | 🔴 € 166,67 spostati fra unità |
 * | ruolo **assente** del tutto | € 500,00 | € 500,00 | ✅ la cascata risolve sul proprietario |
 * | comproprietari che sommano 200 | 250 + 250 | € 500,00 | ✅ rinormalizza dentro l'unità |
 *
 * ⚠️ **Il confronto che ha deciso la correzione è fra la seconda riga e la terza.** Sono la stessa
 * situazione sostanziale — nessun inquilino che paghi sull'unità 2 — e davano due esiti diversi,
 * perché una riga di intestatario a quota zero cade **fra le due guardie**: la cascata non scatta
 * (il ruolo *c'è*) e il ramo dello scoperto nemmeno. Il peso evaporava, e la rinormalizzazione
 * finale lo spostava sulle altre unità.
 *
 * Da qui la forma della correzione: **un ruolo le cui quote sono tutte a zero è un ruolo che non
 * paga**, quindi va trattato esattamente come un ruolo assente.
 *
 * ## Cosa questo file NON asserisce
 *
 * Che un unico intestatario con quota 50 debba pagare solo metà. Rinormalizzare fra i pochi
 * intestatari registrati è una **scelta**: la quota dell'unità deve comunque essere pagata da
 * qualcuno, e se ne è registrato uno solo tocca a lui. Il caso è provato qui sotto perché quella
 * scelta resti dichiarata invece di essere indistinguibile da una dimenticanza.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\{Anagrafica, Condominio, Esercizio, Gestione, Immobile, Tabella};
use App\Models\Gestionale\{Conto, PianoConto, PianoRate};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Due unità da 500 millesimi, una spesa da € 1.000,00 su una tabella al 100%, con le ripartizioni
 * per ruolo e gli intestatari che gli si passano. Genera il piano e restituisce il contesto.
 *
 * ⚠️ **Il nome è lungo di proposito:** `scenarioLastrico()` è già preso da
 * `CoefficientiSottoIlCentoTest`, la guardia dell'anello 1. Pest carica tutti i file di test nello
 * stesso spazio dei nomi, e due omonime fanno fallire l'intera suite con un errore fatale invece
 * che con un test rosso — successo nella beta.68.
 */
function scenarioCatenaProporzioni(array $ripartizioni, array $intestatari, bool $accettaScoperti = true): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'descrizione' => 'catena',
    ]);
    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC',
    ]);
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'Spesa', 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => 100000,
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà',
        'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($ripartizioni as $soggetto => $perc) {
        DB::table('conto_tabella_ripartizioni')->insert([
            'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => $soggetto,
            'percentuale' => $perc, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $unita = [];
    foreach ([1, 2] as $n) {
        $unita[$n] = Immobile::create([
            'condominio_id' => $condominio->id, 'tipo' => 'appartamento',
            'codice_immobile' => "U{$n}", 'nome' => "Unità {$n}", 'interno' => (string) $n,
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $unita[$n]->id,
            'valore' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ($intestatari as $i) {
        $a = Anagrafica::factory()->create(['nome' => $i['nome']]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $a->id, 'immobile_id' => $unita[$i['unita']]->id,
            'tipologia' => $i['ruolo'], 'quota' => $i['quota'], 'attivo' => true,
            'data_inizio' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario',
    ]);

    app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: $accettaScoperti, notaScoperti: 'catena');

    return compact('piano', 'unita');
}

/** Quanto è stato addebitato a un'unità, in centesimi. */
function addebitatoA(array $s, int $n): int
{
    return (int) DB::table('rate_quote')->where('immobile_id', $s['unita'][$n]->id)->sum('importo');
}

/** Quanto è stato addebitato in tutto, scoperti compresi. */
function addebitatoInTutto(): int
{
    return (int) DB::table('rate_quote')->sum('importo');
}

it('⚠️ anello 3: ripartizioni che dichiarano il 60% non addebitano il 100%', function () {
    // Il difetto. `ContoController@update` è l'unica delle quattro porte che scrive le ripartizioni
    // senza controllare che sommino a 100 — ma la correzione sta **anche** nel motore, perché una
    // porta nuova domani rifarebbe lo stesso buco, e perché i dati sporchi esistono già.
    $s = scenarioCatenaProporzioni(['proprietario' => 60], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'A1'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'A2'],
    ]);

    expect(addebitatoA($s, 1))->toBe(30000,
        "Le ripartizioni per ruolo dichiarano il 60% della spesa, e l'unità 1 è stata addebitata di\n".
        '€ '.number_format(addebitatoA($s, 1) / 100, 2, ',', '.').". Doveva essere € 300,00: metà del 60%.\n\n".
        "Se legge € 500,00, il 40% non dichiarato non è diventato scoperto — è stato **assorbito**\n".
        "dalla rinormalizzazione finale e spalmato su chi c'era. È l'anello 3 della catena, e ha la\n".
        "stessa forma dell'anello 1 chiuso nella beta.63."
    );
    expect(addebitatoA($s, 2))->toBe(30000);
});

it('e il 40% non dichiarato ferma la generazione, invece di sparire', function () {
    // ⚠️ **Senza questo, la guardia sopra si soddisfa anche generando un piano che copre solo il
    // 60% del preventivo senza dirlo a nessuno.** È lo stesso meccanismo dell'anello 1: la parte
    // che nessuno copre non viene addebitata a caso — la generazione si **ferma**, e l'ultima
    // parola resta a chi firma il piano.
    expect(fn () => scenarioCatenaProporzioni(['proprietario' => 60], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'S1'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'S2'],
    ], accettaScoperti: false))->toThrow(\App\Exceptions\Gestionale\ScopertiNonAccettatiException::class);
});

it("accettando lo scoperto il piano copre il dichiarato, e non un centesimo di più", function () {
    // La controprova: l'amministratore può procedere motivando, e allora il piano copre € 600,00 su
    // € 1.000,00 — la decurtazione è **dichiarata**, non nascosta.
    $s = scenarioCatenaProporzioni(['proprietario' => 60], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'T1'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'T2'],
    ]);

    expect(addebitatoInTutto())->toBe(60000,
        'Il piano copre € '.number_format(addebitatoInTutto() / 100, 2, ',', '.').
        " invece dei € 600,00 che le ripartizioni dichiarano."
    );
});

it('⚠️ anello 4: un ruolo con tutte le quote a zero non sposta denaro su un\'altra unità', function () {
    // Il caso peggiore, e quello che esiste già sui dati veri (misurato il 22/08/2026: una coppia
    // unità-ruolo con somma quote zero). Una riga di intestatario a quota 0 cadeva fra le due
    // guardie: la cascata non scattava perché il ruolo c'è, e lo scoperto nemmeno.
    $s = scenarioCatenaProporzioni(['proprietario' => 50, 'inquilino' => 50], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'C1p'],
        ['unita' => 1, 'ruolo' => 'inquilino',    'quota' => 100, 'nome' => 'C1i'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'C2p'],
        ['unita' => 2, 'ruolo' => 'inquilino',    'quota' => 0,   'nome' => 'C2i'],
    ]);

    expect(addebitatoA($s, 1))->toBe(50000,
        "L'unità 1 è stata addebitata di € ".number_format(addebitatoA($s, 1) / 100, 2, ',', '.').
        " invece di € 500,00.\n\n".
        "Sull'unità **2** c'è un inquilino con quota zero, cioè uno che non paga. Il suo peso non\n".
        "deve finire sull'unità 1: deve comportarsi come un inquilino assente, e in quel caso la\n".
        "cascata lo risolve sul proprietario della sua stessa unità.\n\n".
        "€ 666,67 su questa riga significa che € 166,67 si sono spostati da un'unità all'altra, con\n".
        'il totale del piano perfettamente uguale al preventivo.'
    );
    expect(addebitatoA($s, 2))->toBe(50000);
});

it('e si comporta esattamente come se quel ruolo non ci fosse', function () {
    // ⚠️ **È il confronto che ha deciso la forma della correzione.** Le due situazioni sono la
    // stessa cosa — nessun inquilino che paghi sull'unità 2 — e devono dare lo stesso risultato.
    $conQuotaZero = scenarioCatenaProporzioni(['proprietario' => 50, 'inquilino' => 50], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'Z1p'],
        ['unita' => 1, 'ruolo' => 'inquilino',    'quota' => 100, 'nome' => 'Z1i'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'Z2p'],
        ['unita' => 2, 'ruolo' => 'inquilino',    'quota' => 0,   'nome' => 'Z2i'],
    ]);
    $primo = [addebitatoA($conQuotaZero, 1), addebitatoA($conQuotaZero, 2)];

    DB::table('rate_quote')->delete();

    $senzaInquilino = scenarioCatenaProporzioni(['proprietario' => 50, 'inquilino' => 50], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'W1p'],
        ['unita' => 1, 'ruolo' => 'inquilino',    'quota' => 100, 'nome' => 'W1i'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'W2p'],
    ]);
    $secondo = [addebitatoA($senzaInquilino, 1), addebitatoA($senzaInquilino, 2)];

    expect($primo)->toBe($secondo,
        "Un inquilino a quota zero e un inquilino assente danno risultati diversi.\n".
        'Sono la stessa situazione: nessuno che paghi quella metà su quell\'unità.'
    );
});

/*
|--------------------------------------------------------------------------
| I casi sani, che la correzione non deve spostare di un centesimo
|--------------------------------------------------------------------------
*/

it('un ruolo del tutto assente resta risolto dalla cascata', function () {
    $s = scenarioCatenaProporzioni(['proprietario' => 50, 'inquilino' => 50], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'E1p'],
        ['unita' => 1, 'ruolo' => 'inquilino',    'quota' => 100, 'nome' => 'E1i'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'E2p'],
    ]);

    expect(addebitatoA($s, 2))->toBe(50000,
        'Senza inquilino la quota deve restare sul proprietario della stessa unità: è la cascata di '.
        'risoluzione del ruolo, ed è una scelta di progetto documentata.'
    );
});

it('i comproprietari si rinormalizzano dentro la loro unità', function () {
    $s = scenarioCatenaProporzioni(['proprietario' => 100], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'D1a'],
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'D1b'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'D2'],
    ]);

    expect(addebitatoA($s, 1))->toBe(50000, 'Due comproprietari devono pagare insieme la quota della loro unità.');
    expect(addebitatoA($s, 2))->toBe(50000);
});

it('un unico intestatario con quota 50 paga comunque tutta la quota dell\'unità', function () {
    // ⚠️ **Questa è una scelta dichiarata, non un difetto.** La quota dell'unità deve essere pagata
    // da qualcuno: se è registrato un solo intestatario, tocca a lui, anche se la sua quota dice 50.
    // Sta qui perché la scelta resti visibile invece di essere indistinguibile da una dimenticanza —
    // e perché se un giorno si decidesse il contrario, questo test lo dica invece di rompersi.
    $s = scenarioCatenaProporzioni(['proprietario' => 100], [
        ['unita' => 1, 'ruolo' => 'proprietario', 'quota' => 50,  'nome' => 'B1'],
        ['unita' => 2, 'ruolo' => 'proprietario', 'quota' => 100, 'nome' => 'B2'],
    ]);

    expect(addebitatoA($s, 1))->toBe(50000);
    expect(addebitatoA($s, 2))->toBe(50000);
});
