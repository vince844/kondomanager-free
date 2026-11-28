<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il «già versato» nei piani rate: la guardia che impedisce di toglierlo due volte, dalla stampa dell'anteprima alla generazione (Code 483, 486, 487,
 * 489 della 1.12.0-beta.14).
 *
 * - **483**: l'anteprima di stampa del riparto di un piano non generato saltava la guardia e mostrava un già versato tolto due volte. Ora il motore
 *   gira con la guardia senza fermarsi, e se la generazione si fermerebbe le due stampe dicono «Anteprima: questo piano non si genera così» con il
 *   messaggio della guardia, senza la tabella.
 * - **486**: la guardia contava solo le righe con un importo degli altri piani, e non guardava le righe senza importo di questo.
 * - **487**: la guardia confrontava l'id della voce: un capitolo in un piano e una sua sottovoce nell'altro non si vedevano.
 * - **489** (decisione 685 (3)): la riga delle voci vuota non impegnava le voci che il piano chiedeva. L'ultima voce di un piano non si elimina dal piano dei
 *   conti; un piano senza voci scelte e senza voci libere non si crea; il piano con la riga vuota, **al ricalcolo** (la rotta «Ricalcola»), si scrive
 *   le voci che chiede, con gli stessi importi; e finché ce n'è uno così nella gestione, in qualunque esercizio (in un altro, se non è fermo), un piano nuovo
 *   senza voci scelte si ferma alla creazione, e dice di ricalcolarlo o, dove il ricalcolo si rifiuta, di eliminarlo e crearlo di nuovo. Finché quel piano
 *   si ricalcola si ferma anche il piano nuovo con le voci scelte (decisione 685 (3), «qualsiasi piano nuovo»). Le altre generazioni non toccano la riga.
 *   Lo straordinario («Spese fuori preventivo» anche sulla gestione ordinaria) e il piano degli addebiti non prendono voci: in un altro esercizio non
 *   fanno del piano senza voci un piano da rifare (rilievo 1 del secondo scettico, in fondo).
 *
 * Il criterio, una funzione sola del motore: quanto chiede un piano su una voce (`CalcoloQuoteService::quantoChiedeSullaVoce`), con la discesa del motore
 * nei capitoli (la stessa divisione fra le sottovoci, con i figli nati fino alla creazione di quel piano). Gli altri piani con la riga vuota contano, in
 * qualunque esercizio, con le voci che il motore gli farà prendere (rilievo 1 dello scettico della tappa 1); nello stesso esercizio quelle di questo piano
 * sono già prese, anche quando è il suo ricalcolo a scriverle (così il ricalcolo di un piano della 1.10 non si ferma per un altro come lui).
 *
 * Le cifre si rifanno dalla regola accanto a ogni prova: Ugo e Bice a 500 millesimi, ogni voce divisa a metà, a Ugo tolto il già versato che vale per
 * l'esercizio del piano (decisione 183.1), una volta sola fra tutti i piani che lo condividono (decisione 187).
 *
 * Cosa resta scoperto: il già versato di persona e di unità sulla stessa voce (Coda 160); lo straordinario (la guardia non gira sulle fatture); le
 * Code 461, 488, 490 e 491, nella 1.12.0-beta.15.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\DettaglioRiparto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/Beta1_112GiaVersatoTest.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Aprile 2027, come le prove della Coda 438: il 2027 c'è già, e il piano del 2026 si crea o si ricalcola adesso.
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $this->user = be52Utente();
    b52tSqliteJson();
});

/** Il già versato di Ugo su una voce qualunque, dalla pagina del già versato: `$valePer` è l'anno, o `tutti`. */
function b14gGiaVersato($test, array $s, Conto $voce, string $valePer, int $cents = 30000)
{
    return $test->actingAs($test->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s['c'], $voce]), [
        'natura' => 'fondo_vincolato',
        'vale_per' => $valePer === 'tutti' ? 'tutti' : $s['es'][$valePer]->id,
        'righe' => [
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => $cents],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => $s['persone']['bice']->id, 'gia_versato' => 0],
        ],
        'descrizione' => 'Accantonamento di prima',
        'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'cassa_id' => null,
    ]);
}

/** Un capitolo senza tabelle con le sue sottovoci sulla tabella «Proprietà», dal modale della nuova voce: `nome => importo in euro`. */
function b14gCapitolo($test, array &$s, string $nome, array $figli): Conto
{
    $voce = fn (array $over) => $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $over))->assertSessionHasNoErrors();

    $voce(['nome' => $nome, 'importo' => '0,00', 'isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null,
        'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $capitolo = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
    foreach ($figli as $figlio => $euro) {
        $voce(['nome' => $figlio, 'importo' => $euro, 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
        $s[$figlio] = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $figlio)->firstOrFail();
    }

    return $capitolo;
}

/** Un piano creato dalla pagina dell'anno **senza generarlo**; `$capitoli` come `g112Crea` (`null`: nessuna voce scelta). */
function b14gCreaSenzaGenerare($test, array $s, string $anno, string $nome, ?array $capitoli)
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 0,
    ] + ($capitoli !== null ? ['capitoli_config' => collect($capitoli)->map(fn ($euro, $voce) => ['id' => $s[$voce]->id, 'importo' => $euro])->values()->all()] : []));
}

/** Le righe delle voci di un piano: `conto_id => importo` (null senza importo), in ordine di voce. */
function b14gRiga(PianoRate $piano): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('conto_id')->pluck('importo', 'conto_id')
        ->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

/** Il testo della stampa del riparto, resa dalla sua rotta (il PDF vero è binario): spazi normalizzati, senza gli stili, con le virgolette vere; e la matrice. */
function b14gStampa($test, array $s, string $anno, PianoRate $piano, string $vista = 'tabelle'): array
{
    $dati = null;
    View::composer("pdf.gestionale.riparto_{$vista}", function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $test->actingAs($test->user)->get(route("admin.gestionale.esercizi.piani-rate.print-riparto-{$vista}", [$s['c'], $s['es'][$anno], $piano]))->assertOk();
    $html = preg_replace('#<style.*?</style>#si', '', view("pdf.gestionale.riparto_{$vista}", $dati)->render());

    return [preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $dati['matrice']];
}

/** La parte di Ugo nelle righe del dettaglio: il già versato tolto (righe `netting`, negative) e il totale. */
function b14gUgoNelDettaglio(array $s, array $righe): array
{
    $mie = array_filter($righe, fn (array $r) => (int) ($r['anagrafica_id'] ?? 0) === (int) $s['persone']['ugo']->id);

    return [
        'netting' => (int) array_sum(array_map(fn (array $r) => (int) $r['importo'], array_filter($mie, fn (array $r) => $r['tipo'] === 'netting'))),
        'totale' => (int) array_sum(array_map(fn (array $r) => (int) $r['importo'], $mie)),
    ];
}

const B14G_TUTTI_Q27 = 'Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano «Preventivo 2026» dell\'esercizio «Esercizio 2026», attivo sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 2.400,00) supera il suo budget (€ 1.200,00), e il già versato verrebbe tolto in tutti e due. Se il già versato è di un esercizio solo, indicalo nella pagina «Già versato» della voce: varrà solo nei piani di quell\'esercizio.';

// --- 483: l'anteprima di stampa passa dalla guardia, senza fermarsi ------------------------------------------------------------------

it('Coda 483, PC-1 — l\'anteprima di stampa del piano del 2027 che la generazione rifiuta dice che non si genera così, con il messaggio della guardia, senza la tabella', function (string $vista) {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    // Dalla regola: il P26 chiede € 1.200,00 + € 200,00 a metà, € 700,00 a testa; a Ugo si tolgono i € 300,00: Ugo € 400,00, Bice € 700,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
    // Il Q27 con le due voci, creato dalla pagina del 2027 senza generarlo. Dalla regola (183.1 e 187) non si genera: € 1.200,00 + € 1.200,00 = € 2.400,00
    // sulla voce da € 1.200,00, e i € 300,00 di Ugo sono già tolti per intero dal P26.
    expect(g112Errore(b14gCreaSenzaGenerare($this, $s, '2027', 'Q27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $q27 = g112Piano($s, 'Q27');
    expect(g112Errore(g112Ricalcola($this, $s, $q27, '2027')))->toBe('Errore durante il ricalcolo: '.B14G_TUTTI_Q27)
        ->and($q27->rate()->count())->toBe(0);

    [$testo, $matrice] = b14gStampa($this, $s, '2027', $q27, $vista);

    // Oggi l'anteprima toglieva a Ugo i € 300,00 un'altra volta e lo mostrava a € 400,00 in un piano che non si genera.
    expect($matrice['fonte']['non_si_genera'] ?? null)->toBe(B14G_TUTTI_Q27)
        ->and($matrice['fonte']['tipo'])->toBe(DettaglioRiparto::ANTEPRIMA)
        ->and($matrice['righe'])->toBe([])
        ->and($testo)->toContain('Anteprima: questo piano non si genera così')
        ->and($testo)->toContain(B14G_TUTTI_Q27)
        ->and($testo)->not->toContain('€ 400,00')
        ->and($testo)->not->toContain('Nessun dato disponibile');
})->with(['per tabella' => 'tabelle', 'per capitolo' => 'capitoli']);

it('Coda 483, B-C2 — il piano del 2027 nato senza voci prima della beta, stampato prima di generarlo: l\'anteprima dice che non si genera così', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    // Il P27 nasce dalla pagina del 2027 senza voci scelte, poi la riga si svuota: com'era il piano creato prima della 1.12.0-beta.1.
    expect(g112Errore(b14gCreaSenzaGenerare($this, $s, '2027', 'P27', null)))->toBeNull();
    $p27 = g112Piano($s, 'P27');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->delete();

    [$testo, $matrice] = b14gStampa($this, $s, '2027', $p27);

    // Il P27 con la riga vuota prende le due voci (nessun piano del 2027 le ha): € 1.200,00 della voce più € 1.200,00 del P26 superano la voce, e il già
    // versato vale per tutti gli esercizi. Oggi l'anteprima toglieva a Ugo € 300,00 (Ugo € 400,00).
    expect($matrice['fonte']['non_si_genera'] ?? null)->toBe(B14G_TUTTI_Q27)
        ->and($testo)->toContain('Anteprima: questo piano non si genera così')
        ->and($matrice['righe'])->toBe([]);
});

it('Coda 483 — gli altri lettori del dettaglio (il conguaglio, il prospetto, il deliberato) leggono l\'anteprima com\'era: il motore in sola lettura, senza la guardia', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull()
        ->and(g112Errore(b14gCreaSenzaGenerare($this, $s, '2027', 'Q27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();

    $dettaglio = DettaglioRiparto::perPiano(g112Piano($s, 'Q27'));

    // Dalla regola del motore in sola lettura: Ugo € 600,00 + € 100,00 − € 300,00 = € 400,00. Il dettaglio non dice «non si genera»: non è una stampa.
    expect(b14gUgoNelDettaglio($s, $dettaglio['righe']))->toBe(['netting' => -30000, 'totale' => 40000])
        ->and($dettaglio['fonte']['non_si_genera'] ?? null)->toBeNull();
});

it('controprova — l\'anteprima di un piano che si genera resta la tabella di sempre, con il già versato tolto una volta', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(b14gCreaSenzaGenerare($this, $s, '2026', 'Preventivo 2026', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();

    [$testo, $matrice] = b14gStampa($this, $s, '2026', g112Piano($s, 'Preventivo 2026'));

    // Dalla regola: Ugo € 600,00 + € 100,00 − € 300,00 = € 400,00, Bice € 700,00, in tutto € 1.100,00.
    expect($matrice['fonte']['non_si_genera'] ?? null)->toBeNull()
        ->and($matrice['gran_totale'])->toBe(110000)
        ->and($testo)->not->toContain('non si genera così')
        ->and($testo)->toContain('Già versato');
});

// --- 486: le righe senza importo contano con l'importo della voce -------------------------------------------------------------------

it('Coda 486, SLA-5 — il P26 con «Spese generali» senza importo, scritto dalla rotta, ferma l\'integrativo del 2027 senza voci scelte', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    // Il P26 con la voce senza importo: il motore la chiede con l'importo della voce. Dalla regola Ugo € 600,00 − € 300,00 + € 100,00 = € 400,00, Bice € 700,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026', ['spesa' => '', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = g112Piano($s, 'Preventivo 2026');
    expect(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => 20000])
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
    // Il P27 con le sole Pulizie: € 100,00 a testa.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Pulizie 2027', ['pulizie' => '200,00'])))->toBeNull();

    // L'integrativo del 2027 senza voci scelte prende «Spese generali», libera nel 2027, per € 1.200,00. Con il P26 la voce è chiesta due volte (€ 2.400,00 su
    // € 1.200,00) e il già versato vale per tutti gli esercizi: si ferma. Oggi chiedeva Ugo € 300,00 (€ 600,00 − € 300,00) e Bice € 600,00.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Integrativo 2027', null)))->toBe(B14G_TUTTI_Q27)
        ->and(g112Piano($s, 'Integrativo 2027'))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 486 — la riga di questo piano senza importo è guardata anche lei: l\'integrativo del 2026 con «Spese generali» senza importo si ferma', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    // Dalla regola: € 1.200,00 del P26 più € 1.200,00 della voce chiesta senza importo = € 2.400,00 sulla voce da € 1.200,00, tutti e due nel 2026. Oggi la
    // riga senza importo non passava dalla guardia: l'integrativo toglieva a Ugo i € 300,00 un'altra volta (Ugo € 300,00, Bice € 600,00).
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', ['spesa' => ''])))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato, ma la somma degli importi richiesti sulla voce dai piani rate attivi che hanno quel già versato (€ 2.400,00, con il piano «Preventivo 2026») supera il suo budget (€ 1.200,00): il già versato verrebbe tolto più volte e parte della spesa non verrebbe mai richiesta ai condòmini. Aggiorna prima l\'importo della voce al fabbisogno reale, oppure chiedi la voce con un importo più basso, o toglila dalla pagina di un piano che ha anche altre voci.')
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull();
});

// --- 487: il capitolo in un piano e la sua sottovoce nell'altro ----------------------------------------------------------------------

/** Il capitolo «Manutenzione» (Ascensore € 600,00, Caldaia € 400,00) e i € 300,00 di Ugo sull'Ascensore per tutti gli esercizi. */
function b14gManutenzione($test): array
{
    $s = g112Scenario($test, 'importati', ['2026', '2027']);
    $s['manutenzione'] = b14gCapitolo($test, $s, 'Manutenzione', ['Ascensore' => '600,00', 'Caldaia' => '400,00']);
    $s['ascensore'] = $s['Ascensore'];
    b14gGiaVersato($test, $s, $s['ascensore'], 'tutti')->assertSessionHasNoErrors();

    return $s;
}

const B14G_TUTTI_ASCENSORE = 'Impossibile generare: la voce di spesa "Ascensore" ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano %s, attivo sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 1.200,00) supera il suo budget (€ 600,00), e il già versato verrebbe tolto in tutti e due. Se il già versato è di un esercizio solo, indicalo nella pagina «Già versato» della voce: varrà solo nei piani di quell\'esercizio.';

it('Coda 487, P-2 — prima il capitolo nel P26, poi la sola Ascensore nel M27: il M27 si ferma, e il messaggio dice il capitolo', function () {
    $s = b14gManutenzione($this);

    // Il P26 chiede il capitolo, € 1.000,00: il motore lo divide sulle sottovoci, Ascensore € 600,00 e Caldaia € 400,00. Dalla regola Ugo € 300,00 − € 300,00
    // + € 200,00 = € 200,00, Bice € 300,00 + € 200,00 = € 500,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026', ['manutenzione' => '1.000,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 20000, 'bice' => 50000]);

    // Il M27 con la sola Ascensore, € 600,00: con i € 600,00 che il P26 le passa dal capitolo fa € 1.200,00 sulla voce da € 600,00. Oggi lo generava con Ugo
    // € 0,00 e Bice € 300,00: i € 300,00 di Ugo tolti due volte.
    expect(g112Errore(g112Crea($this, $s, '2027', 'M27', ['ascensore' => '600,00'])))
        ->toBe(sprintf(B14G_TUTTI_ASCENSORE, '«Preventivo 2026» dell\'esercizio «Esercizio 2026»').' La voce è chiesta con il capitolo «Manutenzione» dal piano «Preventivo 2026».')
        ->and(g112Piano($s, 'M27'))->toBeNull();
});

it('Coda 487, P-2 — prima la sola Ascensore nel M27, poi il capitolo nel P26: il P26 si ferma, e il messaggio dice il capitolo', function () {
    $s = b14gManutenzione($this);

    // Il M27 da solo: Ascensore € 600,00 a metà, a Ugo tolti i € 300,00: Ugo € 0,00, Bice € 300,00.
    expect(g112Errore(g112Crea($this, $s, '2027', 'M27', ['ascensore' => '600,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'M27'), $s))->toBe(['ugo' => 0, 'bice' => 30000]);

    // Il P26 con il capitolo passa € 600,00 all'Ascensore: € 1.200,00 sulla voce da € 600,00. Oggi lo generava con Ugo € 200,00 e Bice € 500,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026', ['manutenzione' => '1.000,00'])))
        ->toBe(sprintf(B14G_TUTTI_ASCENSORE, '«M27» dell\'esercizio «Esercizio 2027»').' La voce è chiesta con il capitolo «Manutenzione» da questo piano.')
        ->and(g112Piano($s, 'Preventivo 2026'))->toBeNull();
});

it('controprova — acconto con il capitolo e saldo con la sottovoce, che insieme fanno la voce: si generano tutti e due, ognuno con metà del già versato', function () {
    $s = b14gManutenzione($this);

    // L'acconto chiede il capitolo per € 500,00: Ascensore € 300,00 (500 × 600/1000), Caldaia € 200,00. Il saldo chiede l'Ascensore per € 300,00. Sulla voce
    // € 300,00 + € 300,00 = € 600,00, la voce: si generano. Ognuno chiede metà della voce e toglie metà del già versato (€ 150,00): acconto Ugo € 150,00 −
    // € 150,00 + € 100,00 = € 100,00, Bice € 150,00 + € 100,00 = € 250,00; saldo Ugo € 150,00 − € 150,00 = € 0,00, Bice € 150,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Acconto 2026', ['manutenzione' => '500,00'])))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Saldo 2026', ['ascensore' => '300,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Acconto 2026'), $s))->toBe(['ugo' => 10000, 'bice' => 25000])
        ->and(g112Chiesto(g112Piano($s, 'Saldo 2026'), $s))->toBe(['ugo' => 0, 'bice' => 15000]);
});

// --- 489: la riga delle voci vuota ---------------------------------------------------------------------------------------------------

/** La gestione del 2026 sola (S7): «Spese generali» € 1.200,00, «Pulizie» € 200,00, «Varie» € 300,00; il Q26 su «Spese generali», il P26 su «Varie». */
function b14gS7($test): array
{
    $s = g112ScenarioUnEsercizio($test);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');
    $s['varie'] = be52Voce($test, $s, $s['pc'], 'Varie', 'spesa', '300,00');
    // Dalla regola: Q26 € 600,00 a testa, P26 € 150,00 a testa.
    expect(g112Errore(g112Crea($test, $s, '2026', 'Q26', ['spesa' => '1.200,00'])))->toBeNull()
        ->and(g112Errore(g112Crea($test, $s, '2026', 'P26', ['varie' => '300,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Q26'), $s))->toBe(['ugo' => 60000, 'bice' => 60000])
        ->and(g112Chiesto(g112Piano($s, 'P26'), $s))->toBe(['ugo' => 15000, 'bice' => 15000]);

    return $s;
}

it('Coda 489, porta 1 — «Varie», l\'ultima voce del P26 in bozza, non si elimina dal piano dei conti: la frase del cestino', function () {
    $s = b14gS7($this);
    $p26 = g112Piano($s, 'P26');

    $r = $this->actingAs($this->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $s['varie']]));

    // Oggi la voce spariva e la chiave esterna toglieva la riga: il P26 restava senza voci e al ricalcolo prendeva le Pulizie senza impegnarle.
    expect(g112Errore($r))->toBe('Eliminazione inibita: «Varie» è l\'ultima voce del piano rate «P26». Un piano senza voci, al ricalcolo, prende tutte le voci della '
            .'gestione che nessun altro piano del suo esercizio ha già. Se deve chiedere un\'altra voce, aggiungila prima con «Sincronizza», se è libera, e poi '
            .'togli questa dal piano ed eliminala; se il piano non serve più, eliminalo dall\'elenco dei piani rate.')
        ->and(Conto::find($s['varie']->id))->not->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['varie']->id => 30000]);
});

it('controprova — una voce che non è l\'ultima del suo piano si elimina dal piano dei conti come prima', function () {
    $s = b14gS7($this);
    // Le «Varie» sono già chieste per intero dal P26 e non si chiedono di nuovo: il R26 prende le Pulizie e una voce nuova, «Extra», che nessun altro piano chiede.
    $s['extra'] = be52Voce($this, $s, $s['pc'], 'Extra', 'spesa', '100,00');
    expect(g112Errore(b14gCreaSenzaGenerare($this, $s, '2026', 'R26', ['pulizie' => '200,00', 'extra' => '100,00'])))->toBeNull();
    // Togliere le Pulizie dal piano dei conti lascia al R26 una voce, l'«Extra».
    $r = $this->actingAs($this->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $s['pc'], $s['pulizie']]));

    expect(g112Errore($r))->toBeNull()
        ->and(Conto::find($s['pulizie']->id))->toBeNull()
        ->and(b14gRiga(g112Piano($s, 'R26')))->toBe([$s['extra']->id => 10000]);
});

it('Coda 489, porta 2 — un piano senza voci scelte quando tutte le voci sono già nei piani del suo esercizio non si crea, nemmeno senza generarlo', function () {
    $s = b14gS7($this);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Pul26', ['pulizie' => '200,00'])))->toBeNull();

    // Oggi nasceva con la riga vuota, e al primo ricalcolo dopo una voce nuova la prendeva senza impegnarla.
    expect(g112Errore(b14gCreaSenzaGenerare($this, $s, '2026', 'Integrativo 2026', null)))
        ->toBe('Impossibile creare il piano rate: tutte le voci di questa gestione sono già nei piani «Q26», «P26» e «Pul26», attivi nello stesso esercizio, e un piano '
            .'creato senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta nessuna voce da chiedere.')
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull();
});

/** Il P26 com'era nelle installazioni: «Varie» eliminata dal piano dei conti con una versione di prima, la riga delle voci vuota. */
function b14gP26SenzaVoci($test, array $s): PianoRate
{
    $p26 = g112Piano($s, 'P26');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $p26->id)->delete();
    Conto::whereKey($s['varie']->id)->delete();

    return $p26;
}

it('Coda 489 — il ricalcolo del P26 con la riga vuota gli scrive le voci che chiede, senza importo, con le stesse cifre; un integrativo dopo non le chiede di nuovo', function () {
    $s = b14gS7($this);
    $p26 = b14gP26SenzaVoci($this, $s);

    // «Ricalcola»: la riga vuota prende le voci che nessun piano del 2026 ha già, le Pulizie (€ 200,00): € 100,00 a testa, come prima.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 10000, 'bice' => 10000])
        ->and(b14gRiga($p26))->toBe([$s['pulizie']->id => null]);

    // Un integrativo senza voci scelte: non resta nessuna voce libera. Oggi prendeva di nuovo le Pulizie, € 100,00 a testa: € 400,00 per una voce da € 200,00.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nei piani «Q26» e «P26», attivi nello stesso esercizio, e un piano creato '
            .'senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta nessuna voce da chiedere.')
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull();
});

/** Decisione 685 (3): il rifiuto di qualsiasi piano nuovo finché il P26 senza voci si ricalcola ancora. */
const B14G_RICALCOLA_P26 = 'Impossibile creare un piano nuovo finché il piano «P26» non ha voci associate: ricalcolalo prima (il ricalcolo gli scrive le voci che '
    .'chiede, con gli stessi importi; se il pregresso di un\'unità va a un\'altra persona, l\'esito del ricalcolo lo dice), poi crea questo piano.';

it('Coda 489 — finché il P26 ha la riga vuota e si ricalcola, un piano nuovo, senza voci scelte o con, si ferma e dice di ricalcolarlo prima (decisione 685 (3)); dopo il ricalcolo si crea', function () {
    $s = b14gS7($this);
    $p26 = b14gP26SenzaVoci($this, $s);

    // L'integrativo senza voci prendeva le Pulizie, che il P26 chiede a ogni ricalcolo: € 100,00 a testa due volte. Lo «Spese 2026» con le voci scelte si
    // creava fino al giro di correzioni della tappa 1.
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Spese 2026', ['spesa' => '300,00'])))->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Spese 2026'))->toBeNull();

    // Ricalcolato il P26 (le Pulizie nella riga, € 100,00 a testa), un piano nuovo si crea: «Servizi» € 300,00, nata dopo e libera, a metà, € 150,00 a testa. Le
    // «Spese generali» no: il Q26 le chiede già per intero, e non si chiedono di nuovo.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 10000, 'bice' => 10000]);
    $s['servizi'] = be52Voce($this, $s, $s['pc'], 'Servizi', 'spesa', '300,00');
    expect(g112Errore(g112Crea($this, $s, '2026', 'Servizi 2026', ['servizi' => '300,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Servizi 2026'), $s))->toBe(['ugo' => 15000, 'bice' => 15000]);
});

it('Coda 489 — la catena: il P26 con la riga vuota ricalcolato due volte e poi seguito da un integrativo con una voce nuova; le cifre del P26 restano quelle del motore di prima', function () {
    $s = b14gS7($this);
    $p26 = b14gP26SenzaVoci($this, $s);

    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull();
    // Le stesse cifre, ora con la riga scritta: il ricalcolo dopo non cambia niente. La pagina del piano legge la riga senza importo con l'importo della
    // voce: l'atteso è € 200,00, come le rate, e il piano non è «Disallineato».
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 10000, 'bice' => 10000])
        ->and(b14gRiga($p26))->toBe([$s['pulizie']->id => null])
        ->and(app(\App\Services\PianoRateQuoteService::class)->totaleAttesoCents($p26->fresh()))->toBe(20000)
        ->and(app(\App\Services\PianoRateQuoteService::class)->eDisallineato($p26->fresh()))->toBeFalse();

    // Una voce nuova dopo il ricalcolo («Giardino» € 400,00) è libera: un integrativo senza voci scelte la prende, € 200,00 a testa, e il P26 non la tocca.
    $s['giardino'] = be52Voce($this, $s, $s['pc'], 'Giardino', 'spesa', '400,00');
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Integrativo 2026'), $s))->toBe(['ugo' => 20000, 'bice' => 20000])
        ->and(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 10000, 'bice' => 10000]);
});

/** Un piano come lo creava e lo generava la 1.10 senza voci scelte: la riga delle voci vuota, generato con le voci che il motore prende senza righe. */
function b14gPianoDella110($test, array $s, string $nome): PianoRate
{
    $piano = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => $nome, 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Piano di prova', esercizio: $s['e']);

    return $piano->fresh();
}

it('Coda 489 — un piano della 1.10 senza voci: la generazione com\'era lascia la riga vuota, il ricalcolo dalla rotta gli scrive le voci con le stesse cifre', function () {
    $s = g112ScenarioUnEsercizio($this);
    $s['pulizie'] = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');
    expect(g112Errore(g112Crea($this, $s, '2026', 'Q26', ['spesa' => '1.200,00'])))->toBeNull();

    // Senza righe il piano prende le voci che nessun piano del 2026 ha: le Pulizie, € 200,00 a metà, € 100,00 a testa. La generazione che non è un ricalcolo
    // (la creazione della 1.10, gli aiuti che la rifanno) lascia la riga com'è.
    $v26 = b14gPianoDella110($this, $s, 'V26');
    expect(g112Chiesto($v26, $s))->toBe(['ugo' => 10000, 'bice' => 10000])
        ->and(b14gRiga($v26))->toBe([]);

    // «Ricalcola» dalla pagina del piano: la riga si scrive con le Pulizie, senza importo, e le cifre restano € 100,00 a testa.
    expect(g112Errore(g112Ricalcola($this, $s, $v26, '2026')))->toBeNull()
        ->and(b14gRiga($v26))->toBe([$s['pulizie']->id => null])
        ->and(g112Chiesto($v26, $s))->toBe(['ugo' => 10000, 'bice' => 10000]);
});

it('Coda 489 — due piani della 1.10 senza voci nello stesso esercizio, con il già versato: la creazione li nomina tutti e due, il ricalcolo del primo non si ferma per il secondo, quello del secondo dice che le voci sono già nel primo', function () {
    $s = g112ScenarioUnEsercizio($this);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();

    // Com'erano: tutti e due prendono «Spese generali», € 1.200,00 a metà, e a Ugo tolgono € 300,00: Ugo € 300,00 e Bice € 600,00 in ognuno (€ 2.400,00 per una
    // voce da € 1.200,00, il difetto della 489).
    $p = b14gPianoDella110($this, $s, 'P26');
    $q = b14gPianoDella110($this, $s, 'Q26');
    expect(g112Chiesto($p, $s))->toBe(['ugo' => 30000, 'bice' => 60000])
        ->and(g112Chiesto($q, $s))->toBe(['ugo' => 30000, 'bice' => 60000]);

    // Un piano nuovo si ferma e dice di ricalcolarli (decisione 685 (3)).
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))
        ->toBe('Impossibile creare un piano nuovo finché i piani «P26» e «Q26» non hanno voci associate: ricalcolali prima (il ricalcolo scrive a ognuno '
            .'le voci che chiede, con gli stessi importi; se il pregresso di un\'unità va a un\'altra persona, l\'esito del ricalcolo lo dice), poi crea questo piano.')
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull();

    // Il ricalcolo del P26 è la strada che la frase indica: il Q26, ancora senza righe, non lo ferma. La riga si scrive, le cifre restano.
    expect(g112Errore(g112Ricalcola($this, $s, $p, '2026')))->toBeNull()
        ->and(b14gRiga($p))->toBe([$s['spesa']->id => null])
        ->and(g112Chiesto($p, $s))->toBe(['ugo' => 30000, 'bice' => 60000]);

    // Il ricalcolo del Q26 ora vede la voce nel P26: non resta niente da chiedere, e il rifiuto lo dice; il Q26 resta com'era, da eliminare.
    expect(g112Errore(g112Ricalcola($this, $s, $q, '2026')))
        ->toBe('Errore durante il ricalcolo: Impossibile generare il piano rate: tutte le voci di questa gestione sono già nel piano «P26», attivo nello stesso '
            .'esercizio, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta '
            .'nessuna voce da chiedere.')
        ->and(b14gRiga($q))->toBe([])
        ->and(g112Chiesto($q, $s))->toBe(['ugo' => 30000, 'bice' => 60000]);

    // Il Q26 non ha più voci da prendere, e il ricalcolo non gli scriverebbe la riga: non ferma un piano con le voci scelte, che arriva alla guardia del già
    // versato (€ 1.200,00 del P26 + € 300,00 = € 1.500,00 sulla voce da € 1.200,00).
    expect(g112Errore(g112Crea($this, $s, '2026', 'Spese 2026', ['spesa' => '300,00'])))
        ->toStartWith('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato, ma la somma degli importi richiesti sulla voce dai '
            .'piani rate attivi che hanno quel già versato (€ 1.500,00, con il piano «P26»)');
});

// --- Il giro di correzioni dopo lo scettico: il piano senza voci in un altro esercizio (rilievi 1 e 10) -------------------------------

/** Il messaggio «per tutti gli esercizi» con il piano dato del 2026, che non ha voci associate. */
function b14gTuttiSenzaVoci(string $piano): string
{
    return 'Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano «'.$piano.'» '
        .'dell\'esercizio «Esercizio 2026», attivo sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 2.400,00) supera il suo '
        .'budget (€ 1.200,00), e il già versato verrebbe tolto in tutti e due. Se il già versato è di un esercizio solo, indicalo nella pagina «Già versato» della '
        .'voce: varrà solo nei piani di quell\'esercizio. Il piano «'.$piano.'» non ha voci associate: prende le voci che nessun altro piano del suo esercizio ha '
        .'già, compresa questa.';
}

it('Coda 486 e 489, rilievo 1 dello scettico — S-B4: sulla gestione usata nel 2026 e nel 2027, con il P26 della 1.10 senza voci e il già versato «per tutti gli esercizi», il Q27 con «Spese generali» non si genera, prima per il P26 da ricalcolare e poi per la guardia: i € 300,00 di Ugo non si tolgono due volte', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    // Il P26 della 1.10, con la riga vuota: prende le due voci (nessun piano del 2026 le ha), (€ 1.200,00 + € 200,00) / 2 = € 700,00 a testa; a Ugo si
    // tolgono i € 300,00: Ugo € 400,00, Bice € 700,00.
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(b14gRiga($p26))->toBe([]);

    // Il Q27 con «Spese generali» € 1.200,00. Prima si generava: Ugo € 600,00 − € 300,00 = € 300,00, Bice € 600,00; i € 300,00 di Ugo tolti due volte, e
    // € 300,00 della spesa del 2027 non li chiedeva nessuno. Ora si ferma prima: il P26 si ricalcola ancora (decisione 685 (3)).
    expect(g112Errore(g112Crea($this, $s, '2027', 'Q27', ['spesa' => '1.200,00'])))->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Q27'))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);

    // Il P26 ricalcolato: la riga si scrive con le due voci, senza importo, e le cifre restano € 400,00 e € 700,00. Il Q27 si ferma per la guardia: con i
    // € 1.200,00 che il P26 chiede fa € 2.400,00 sulla voce da € 1.200,00, e il già versato vale per tutti gli esercizi.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null])
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Q27', ['spesa' => '1.200,00'])))->toBe(str_replace('«Preventivo 2026»', '«P26»', B14G_TUTTI_Q27))
        ->and(g112Piano($s, 'Q27'))->toBeNull();
});

it('Coda 486 e 489, rilievo 1 dello scettico — i dati che ci sono già: il Q27 generato accanto al P26 della 1.10 senza voci, poi il già versato «per tutti gli esercizi»: il ricalcolo del Q27 si ferma e dice perché', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    // Prima del già versato: il Q27 chiede le due voci, € 700,00 a testa; il P26 della 1.10, nato con una versione di prima, le prende tutte e due, € 700,00
    // a testa. (Oggi il Q27 dopo il P26 si fermerebbe: decisione 685 (3).)
    expect(g112Errore(g112Crea($this, $s, '2027', 'Q27')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Q27'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();

    // «Ricalcola» del Q27: con il P26 la voce è chiesta per € 2.400,00, e il già versato vale per tutti gli esercizi. Prima si ricalcolava: Ugo € 600,00 +
    // € 100,00 − € 300,00 = € 400,00, e il P26, al suo ricalcolo, gli avrebbe tolto i € 300,00 un'altra volta. Le rate del Q27 restano quelle di prima.
    expect(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Q27'), '2027')))->toBe('Errore durante il ricalcolo: '.b14gTuttiSenzaVoci('P26'))
        ->and(g112Chiesto(g112Piano($s, 'Q27'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
    // Il P26 non si ricalcola (decisione 192.1): la strada è nel suo messaggio.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toStartWith('Impossibile ricalcolare: il piano rate «P26» non ha voci associate, e la gestione');
});

it('controprova — il già versato del solo 2026: il piano della 1.10 senza voci del 2026 non conta per il Q27, che si ricalcola con il già versato non tolto', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    // Dalla regola (183.1): il Q27 non toglie i € 300,00, € 600,00 a testa; il P26 della 1.10, nato dopo con una versione di prima, li toglie: Ugo € 400,00 e
    // Bice € 700,00.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Q27', ['spesa' => '1.200,00'])))->toBeNull();
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Q27'), '2027')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Q27'), $s))->toBe(['ugo' => 60000, 'bice' => 60000]);
});

it('Coda 489, rilievo 1 dello scettico — la creazione senza voci scelte guarda tutta la gestione: finché il P26 della 1.10 non ha voci associate, il piano del 2027 senza voci si ferma e dice di ricalcolarlo; dopo il ricalcolo si crea', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    // Il P26 della 1.10 prende le due voci: € 700,00 a testa (nessun già versato).
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);

    // Prima l'integrativo del 2027 si creava con le due voci (libere nel 2027), e da lì il P26, senza voci e con un piano in un altro esercizio, non si
    // ricalcolava più (decisione 192.1).
    expect(g112Errore(g112Crea($this, $s, '2027', 'Integrativo 2027', null)))->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Integrativo 2027'))->toBeNull();

    // Il ricalcolo del P26 gli scrive le due voci, € 700,00 a testa come prima; poi l'integrativo del 2027 senza voci si crea, e prende le due voci, libere nel
    // 2027 (decisione 183.2): € 700,00 a testa.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null])
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Integrativo 2027', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Integrativo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('controprova — il piano senza voci di un altro esercizio che non si riscrive più (le prime rate a giornale): non ferma la creazione senza voci del 2027, che non prende le sue voci', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    // Il P26 della 1.10, € 700,00 a testa, approvato e con la rata di gennaio a giornale: non si ricalcola né si elimina finché l'emissione c'è.
    $p26 = b14gPianoDella110($this, $s, 'P26');
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['es']['2026'], $p26]), ['approvato' => true, 'data_delibera_assemblea' => '2025-12-15'])
        ->assertSessionHasNoErrors();
    $gennaio = DB::table('rate')->where('piano_rate_id', $p26->id)->where('data_scadenza', '<=', '2026-01-31 23:59:59')->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $p26]), ['rate_ids' => $gennaio, 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    expect($p26->fresh()->eImmutabile())->toBeTrue()
        ->and(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBe('Impossibile ricalcolare: ci sono rate già emesse in contabilità. Annulla prima le emissioni.');

    // Il piano del 2027 senza voci scelte prende le due voci, libere nel 2027 (decisione 183.2), € 700,00 a testa: il P26 non le impegna per il 2027, e
    // fermarsi per lui chiederebbe le voci a ogni piano degli esercizi dopo, senza una strada (né il ricalcolo né l'eliminazione passano).
    // Lo stesso con le voci scelte: una voce libera nel 2027, «Giardino» € 400,00 (nata dopo), € 200,00 a testa. Le Pulizie no: il «Preventivo 2027» le chiede già
    // per intero, e un secondo piano del 2027 sulla stessa voce si rifiuta, con quanto resta da chiedere e il piano che la chiede.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Pulizie 2027', ['pulizie' => '200,00'])))
        ->toBe('La voce «Pulizie» vale € 200,00 ed è già chiesta dal piano «Preventivo 2027» (€ 200,00): restano € 0,00 da chiedere. Toglila da questo piano: chiesta di '
            .'nuovo, i condòmini la pagherebbero due volte. Se la spesa è più alta del preventivo, registra prima la fattura: lo speso alza il tetto.')
        ->and(g112Piano($s, 'Pulizie 2027'))->toBeNull();
    $s['giardino'] = be52Voce($this, $s, $s['pc'], 'Giardino', 'spesa', '400,00');
    expect(g112Errore(g112Crea($this, $s, '2027', 'Giardino 2027', ['giardino' => '400,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Giardino 2027'), $s))->toBe(['ugo' => 20000, 'bice' => 20000]);
});

it('Coda 489, rilievo 10 dello scettico — il piano senza voci che non si ricalcola più (la gestione ha un piano in un altro esercizio): la creazione senza voci scelte dice subito di eliminarlo e crearlo di nuovo', function (string $anno, bool $giaChiesta) {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    // Il Pul27 con le sole Pulizie, € 100,00 a testa, e il P26 della 1.10 nato dopo con una versione di prima (€ 700,00 a testa): il P26, senza voci, con un
    // piano in un altro esercizio, non si ricalcola (decisione 192.1).
    expect(g112Errore(g112Crea($this, $s, '2027', 'Pul27', ['pulizie' => '200,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Pul27'), $s))->toBe(['ugo' => 10000, 'bice' => 10000]);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toStartWith('Impossibile ricalcolare: il piano rate «P26» non ha voci associate');

    // Prima la frase diceva «ricalcolalo» (dalla pagina del 2026), una strada che si rifiuta; dalla pagina del 2027 il piano si creava.
    $perche = $p26->fresh()->fraseDaRifare('Il piano «P26» non si può ricalcolare');
    expect(g112Errore(g112Crea($this, $s, $anno, 'Integrativo', null)))
        ->toBe('Impossibile creare un piano senza scegliere le voci finché il piano «P26» non ha voci associate: eliminalo e crealo di nuovo scegliendo le voci, '
            .'oppure scegli le voci di questo piano. '.$perche)
        ->and($perche)->toContain('Per rifarlo, eliminalo dall\'elenco dei piani rate e crealo di nuovo dalla pagina dei piani rate dell\'esercizio «Esercizio 2026», scegliendo le voci.')
        ->and(g112Piano($s, 'Integrativo'))->toBeNull();
    // Il piano con le voci scelte: il P26 si può solo eliminare e rifare, e fermarsi per lui non gli darebbe la riga. Dalla pagina del 2027 «Spese generali» € 300,00
    // si crea, € 150,00 a testa: il P26 è del 2026. Dalla pagina del 2026 la voce è già chiesta per intero dal P26 (€ 1.200,00, € 700,00 a testa con le Pulizie), e
    // un piano del suo stesso esercizio non la chiede di nuovo: si rifiuta, con quanto resta da chiedere e il piano che la chiede.
    $r = g112Crea($this, $s, $anno, 'Con le voci', ['spesa' => '300,00']);
    if ($giaChiesta) {
        expect(g112Errore($r))->toBe('La voce «Spese generali» vale € 1.200,00 ed è già chiesta dal piano «P26» (€ 1.200,00): restano € 0,00 da chiedere. Toglila da questo piano: '
                .'chiesta di nuovo, i condòmini la pagherebbero due volte. Se la spesa è più alta del preventivo, registra prima la fattura: lo speso alza il tetto.')
            ->and(g112Piano($s, 'Con le voci'))->toBeNull();
    } else {
        expect(g112Errore($r))->toBeNull()
            ->and(g112Chiesto(g112Piano($s, 'Con le voci'), $s))->toBe(['ugo' => 15000, 'bice' => 15000]);
    }
})->with(['dalla pagina del 2026' => ['2026', true], 'dalla pagina del 2027' => ['2027', false]]);

it('Coda 489, rilievo 10 dello scettico — due piani senza voci, uno che si ricalcola e uno che non si ricalcola più (senza il suo esercizio salvato): la frase dà a ognuno la sua strada', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    // Il R26, creato a giugno 2026 da una versione che non salvava l'esercizio: il programma lo legge del 2026 dalla data di creazione, e sulla gestione
    // usata in due esercizi non lo ricalcola (decisione 192.1). Il P26 invece si ricalcola: la gestione non ha piani in un altro esercizio.
    $r26 = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => null, 'nome' => 'R26', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-07-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
    ]);
    DB::table('piani_rate')->where('id', $r26->id)->update(['created_at' => '2026-06-01 10:00:00']);
    $perche = $r26->fresh()->fraseDaRifare('Il piano «R26» non si può ricalcolare');
    expect($p26->fresh()->fraseDaRifare('Impossibile ricalcolare'))->toBeNull()
        ->and($perche)->toStartWith('Il piano «R26» non si può ricalcolare: il piano rate «R26» non ha il suo esercizio salvato');

    // Prima: «… finché i piani «P26» e «R26» non hanno voci associate: ricalcolali …», e il ricalcolo del R26 si rifiutava. Niente «oppure scegli le voci»:
    // finché il P26 si ricalcola, anche il piano con le voci scelte si ferma, e dice solo del P26 (decisione 685 (3)).
    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))
        ->toBe('Impossibile creare un piano senza scegliere le voci finché i piani «P26» e «R26» non hanno voci associate: ricalcola il piano «P26» (il ricalcolo '
            .'gli scrive le voci che chiede) ed elimina il piano «R26» e crealo di nuovo scegliendo le voci. '.$perche)
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Con le voci', ['spesa' => '300,00'])))->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Con le voci'))->toBeNull();
});

it('controprova — due piani della 1.10 senza voci nello stesso esercizio, il secondo mai generato: l\'anteprima di stampa del secondo dice ciò che fa il suo ricalcolo, che gli scrive la riga prima del motore', function () {
    $s = g112ScenarioUnEsercizio($this);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    // Il P26 generato: «Spese generali» € 1.200,00 a metà, a Ugo tolti € 300,00: Ugo € 300,00, Bice € 600,00.
    $p = b14gPianoDella110($this, $s, 'P26');
    $q = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Q26', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
    ]);

    // L'anteprima del Q26 è quella del motore: € 600,00 a testa, a Ugo tolti € 300,00, € 900,00 in tutto; non «non si genera così», perché il ricalcolo del Q26
    // gli scrive la voce e il P26, senza voci, non la prende più.
    [$testo, $matrice] = b14gStampa($this, $s, '2026', $q);
    expect($matrice['fonte']['non_si_genera'] ?? null)->toBeNull()
        ->and($matrice['gran_totale'])->toBe(90000)
        ->and($testo)->not->toContain('non si genera così');
    expect(g112Errore(g112Ricalcola($this, $s, $q, '2026')))->toBeNull()
        ->and(b14gRiga($q))->toBe([$s['spesa']->id => null])
        ->and(g112Chiesto($q, $s))->toBe(['ugo' => 30000, 'bice' => 60000]);
    // Il P26 resta com'era finché non si ricalcola; ricalcolato, vede la voce nel Q26, non ha più niente da chiedere e la frase di AD3 lo dice, come nella
    // prova dei due piani della 1.10: da eliminare.
    expect(g112Errore(g112Ricalcola($this, $s, $p, '2026')))
        ->toBe('Errore durante il ricalcolo: Impossibile generare il piano rate: tutte le voci di questa gestione sono già nel piano «Q26», attivo nello stesso '
            .'esercizio, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta '
            .'nessuna voce da chiedere.')
        ->and(b14gRiga($p))->toBe([]);
});

// --- Decisione 685 (3), «qualsiasi piano nuovo»: anche il piano con le voci scelte si ferma finché un piano senza voci si ricalcola ancora -----

it('Coda 489, decisione 685 (3) — il piano del 2027 con le voci scelte, mentre il P26 della 1.10 senza voci si ricalcola ancora: si ferma e dice di ricalcolarlo prima; ricalcolato il P26, il 2027 si crea', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    // Il P26 della 1.10 prende le due voci: (€ 1.200,00 + € 200,00) / 2 = € 700,00 a testa (nessun già versato).
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);

    // Prima il Q27 con le due voci si creava, € 700,00 a testa, e da lì il P26, senza voci e con un piano in un altro esercizio, non si ricalcolava più
    // (decisione 192.1): la sola strada restava eliminarlo e crearlo di nuovo.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Q27')))
        ->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Q27'))->toBeNull()
        ->and($p26->fresh()->fraseDaRifare('Impossibile ricalcolare'))->toBeNull();

    // «Ricalcola» del P26: la riga si scrive con le due voci, senza importo, € 700,00 a testa come prima; poi il Q27 si crea, € 700,00 a testa.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null])
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Q27')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Q27'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 489, decisione 685 (3) — nello stesso esercizio il piano con le voci scelte, creato prima del ricalcolo del P26 senza voci, gli toglierebbe una voce e le sue cifre: si ferma; dopo il ricalcolo le cifre del P26 restano', function () {
    $s = g112ScenarioUnEsercizio($this);
    $s['pulizie'] = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');
    // Il P26 della 1.10 prende «Spese generali» e «Pulizie»: € 700,00 a testa.
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);

    // Prima lo «Spese 2026» con «Spese generali» € 300,00 si creava (€ 150,00 a testa), e il ricalcolo dopo scriveva al P26 le sole Pulizie, che non sono
    // nello «Spese 2026»: il P26 passava da € 700,00 a € 100,00 a testa (€ 200,00 / 2).
    expect(g112Errore(g112Crea($this, $s, '2026', 'Spese 2026', ['spesa' => '300,00'])))
        ->toBe(B14G_RICALCOLA_P26)
        ->and(g112Piano($s, 'Spese 2026'))->toBeNull();

    // Ricalcolato il P26 (le due voci nella riga, € 700,00 a testa), un piano nuovo si crea con una voce nata dopo e libera, «Servizi» € 300,00, € 150,00 a testa, e il
    // P26 ricalcolato dopo resta € 700,00. Le «Spese generali» no: il P26 le chiede già per intero, e non si chiedono di nuovo.
    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull();
    $s['servizi'] = be52Voce($this, $s, $s['pc'], 'Servizi', 'spesa', '300,00');
    expect(g112Errore(g112Crea($this, $s, '2026', 'Servizi 2026', ['servizi' => '300,00'])))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Servizi 2026'), $s))->toBe(['ugo' => 15000, 'bice' => 15000])
        ->and(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null]);
});

// --- Il secondo giro di correzioni (rilievo 1 del secondo scettico): i piani che non prendono voci non fanno del piano della 1.10 un piano da rifare -----

/** Una fattura del 2027 sulla voce «Spese generali» dell'ordinaria, con la riga «Fuori preventivo»: la mette a rate «Spese fuori preventivo». */
function b14gFatturaFuoriPreventivo(array $s, int $cents = 50000): \App\Models\Gestionale\FatturaPassiva
{
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Idraulica Fuori Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $f = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['es']['2027']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FUORI', 'data_documento' => '2027-03-10', 'data_scadenza' => '2027-04-10', 'is_pregresso' => false,
        'importo_imponibile' => $cents, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => $cents, 'netto_a_pagare' => $cents, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $f->id, 'conto_id' => $s['spesa']->id, 'immobile_id' => null, 'descrizione' => 'Riparazione urgente',
        'aliquota_iva' => 0, 'importo_imponibile' => $cents, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);

    return $f;
}

it('Coda 489, rilievo 1 del secondo scettico — «Spese fuori preventivo» del 2027 sulla gestione ordinaria, accanto al P26 della 1.10 senza voci: non prende voci, si crea, e il P26 si ricalcola ancora e si scrive la riga', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
    $fattura = b14gFatturaFuoriPreventivo($s);

    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es']['2027']]), [
        'gestione_id' => $s['g']->id, 'nome' => 'Fuori 27', 'tipo' => 'straordinario', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova',
        'data_delibera_assemblea' => '2027-03-01', 'fatture_config' => [['id' => $fattura->id, 'importo' => '500,00']], 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 2, 'giorno_scadenza' => 10, 'capitoli_ids' => [], 'genera_subito' => false,
    ])->assertSessionHasNoErrors();
    expect(g112Piano($s, 'Fuori 27')?->only(['tipo', 'esercizio_id']))->toBe(['tipo' => 'straordinario', 'esercizio_id' => $s['es']['2027']->id]);

    // Prima il P26 da lì non si ricalcolava più: «… la gestione «Gestione ordinaria» ha un piano in un altro esercizio: «Fuori 27» …». Lo straordinario
    // non ha la riga delle voci (prende le fatture): il P26 non può chiedere di nuovo le sue voci.
    expect($p26->fresh()->fraseDaRifare('Impossibile ricalcolare'))->toBeNull()
        ->and(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null])
        ->and(g112Chiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 489, rilievo 1 del secondo scettico — il piano degli addebiti del 2027 (nasce al primo addebito individuale, senza la creazione) accanto al P26 della 1.10 senza voci: il P26 si ricalcola ancora', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    // Il piano degli addebiti com'è al primo addebito di un esercizio (`AddebitiIndividuali::pianoPer`).
    $addebiti = (fn () => $this->pianoPer($s['c'], $s['g'], $s['es']['2027'], null))->call(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class));
    expect($addebiti->eDegliAddebiti())->toBeTrue()->and((int) $addebiti->esercizio_id)->toBe((int) $s['es']['2027']->id);

    expect($p26->fresh()->fraseDaRifare('Impossibile ricalcolare'))->toBeNull()
        ->and(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))->toBeNull()
        ->and(b14gRiga($p26))->toBe([$s['spesa']->id => null, $s['pulizie']->id => null]);
});

it('controprova — un piano ordinario del 2027 accanto al P26 della 1.10 senza voci (i dati che ci sono già): il P26 resta da rifare, con la frase di sempre', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $p26 = b14gPianoDella110($this, $s, 'P26');
    // Il Q27 nato prima della decisione 691 (2), scritto senza passare dalla creazione.
    PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['es']['2027']->id, 'nome' => 'Q27', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'metodo_distribuzione' => 'tutte_rate']);

    expect(g112Errore(g112Ricalcola($this, $s, $p26, '2026')))
        ->toStartWith('Impossibile ricalcolare: il piano rate «P26» non ha voci associate, e la gestione «' . $s['g']->nome . '» ha un piano in un altro esercizio: «Q27» («Esercizio 2027»).')
        ->and(b14gRiga($p26))->toBe([]);
});
