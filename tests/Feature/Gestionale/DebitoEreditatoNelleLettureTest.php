<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il giro A delle correzioni della 1.12.0-beta.12 sulla lettura del debito ereditato (`DebitoEreditato::perLaLettura()` e `sullaLettura()`), sul caso di
 * Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la rata di aprile da € 300,00 scaduta il 30/04/2026, il decesso il 17/11/2026, Elena e Marco al
 * 50 %; il piano 2027 che assorbe l'arretrato ha la rata 1 da € 10,00 di voce che scade il 31/03/2027, emessa il 15/01/2027. A giornale, nella rata nuova,
 * Ettore ha € 4,38 suoi (i giorni da proprietario) e − € 300,00 di contropartita dell'arretrato passato agli eredi.
 *
 * - N1 della terza prova (decisione 528): dopo l'emissione del piano che assorbe, il debito proprio del defunto nella rata nuova non sparisce più dalle
 *   letture: la parte della rata vecchia passata agli eredi si nasconde, e la stessa cifra si restituisce sulla rata nuova, al più la contropartita.
 * - N1, l'altra faccia: dopo «Usa credito» del defunto sulla rata vecchia, la riga «ereditato» degli eredi resta la loro parte.
 * - P1 della lente P: un'istanza sola di `DebitoEreditato` in una richiesta di «Rate scadute».
 * - P3 della lente P: le prove che uccidono le mutazioni sopravvissute di `DebitoEreditato.php` (M-D4, M-D7, M-D10, M-D14, D15).
 *
 * Che cosa NON copre: la pagina a video; MySQL (qui sqlite); i tempi delle letture (si contano, non si misurano); il cruscotto (stessa lettura, che con
 * questa correzione la chiama una volta per servizio); un piano che assorbe in un'altra gestione.
 */

use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\DebitoEreditato;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** «Rate scadute» alla data, «oggi» spostato a quel giorno: persona => la riga. */
function delElenco($test, array $s, string $alla): \Illuminate\Support\Collection
{
    $test->travelTo($alla . ' 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);

    return $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => $alla]))->keyBy('anagrafica_id');
}

/** Le righe «ereditato» di una persona in «Rate scadute», sommate. */
function delEreditato(\Illuminate\Support\Collection $elenco, int $persona): int
{
    return (int) collect($elenco[$persona]['rate'] ?? [])->filter(fn ($r) => ($r['ereditato'] ?? null) !== null)->sum('residuo');
}

it('N1: dopo l\'emissione del piano che assorbe, Ettore deve € 4,38 a giornale, e la lettura e «Rate scadute» dicono € 4,38', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s);
    $ettore = $s['persone']['Neri']->id;
    $cid = (int) $s['c']->id;
    $this->travelTo('2027-04-30 10:00:00');
    $grezza = app(LetturaDebiti::class)->perPersona($cid, $ettore, '2027-04-30');
    expect($grezza['totali']['netto'])->toBe(438);
    $sopra = app(DebitoEreditato::class)->sullaLettura($cid, '2027-04-30', null, [$ettore => $grezza], [$ettore]);
    $elenco = delElenco($this, $s, '2027-04-30');
    // Con il codice di prima: netto − € 295,62 (la contropartita tolta due volte: dalla rata di aprile, nascosta, e lasciata come credito nella rata nuova), e
    // Ettore non era in «Rate scadute».
    $nuova = collect($sopra[$ettore]['rate'])->firstWhere('residuo', 438);
    expect($sopra[$ettore]['totali']['netto'])->toBe(438)
        ->and($nuova)->not->toBeNull()
        ->and($elenco[$ettore]['importo'] ?? null)->toBe(438);
    // Gli eredi restano come prima: Marco la sua rata nuova (dal 01/04 la conta), Elena con il conguaglio a credito.
    expect($elenco[$s['persone']['Marco']->id]['importo'])->toBe(60031);
});

it('N1 prima dell\'emissione: il piano approvato e non emesso non ha la contropartita a giornale, e Ettore non deve niente sulla rata passata agli eredi', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, emissione: null);
    $ettore = $s['persone']['Neri']->id;
    $elenco = delElenco($this, $s, '2027-02-01');
    // La rata di aprile è degli eredi, € 150,00 a testa «non ancora in una rata»; niente restituito a Ettore, che non ha la rata nuova a giornale.
    expect($elenco->has($ettore))->toBeFalse()
        ->and(delEreditato($elenco, $s['persone']['Elena']->id))->toBe(15000)
        ->and(delEreditato($elenco, $s['persone']['Marco']->id))->toBe(15000);
});

it('N1, l\'altra faccia: «Usa credito» di Ettore sulla rata di aprile non toglie il debito ereditato di Marco, e i € 4,38 restano a Ettore', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $ettore = $s['persone']['Neri']->id;
    $marco = $s['persone']['Marco']->id;
    expect(delEreditato(delElenco($this, $s, '2027-02-01'), $marco))->toBe(15000);
    // L'amministratore usa il credito che Ettore ha nella rata nuova (− € 295,62) sulla sua rata di aprile.
    $q5 = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $ettore)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
    $q9 = RataQuote::whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id'))->where('anagrafica_id', $ettore)->firstOrFail();
    $this->travelTo('2027-02-02 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $ettore, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2027-02-02', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $q5->id, 'importo' => '295.62'], ['rata_id' => $q9->id, 'importo' => '-295.62']],
    ])->assertSessionHasNoErrors();
    $elenco = delElenco($this, $s, '2027-02-03');
    // Con il codice di prima: la riga «ereditato» di Marco era € 2,19 (la metà dei € 4,38 rimasti sulla rata di aprile di Ettore).
    expect(delEreditato($elenco, $marco))->toBe(15000);
    // Ettore: i suoi € 4,38 sono nella rata nuova, non ancora scaduta; la lettura non gli dà né un credito né un debito in più.
    $lettura = app(DebitoEreditato::class)->sullaLettura((int) $s['c']->id, '2027-02-03', null,
        [$ettore => app(LetturaDebiti::class)->perPersona((int) $s['c']->id, $ettore, '2027-02-03')], [$ettore]);
    expect($lettura[$ettore]['totali']['netto'])->toBe(438);
    // Dal 01/04 la rata nuova è scaduta: Ettore € 4,38 in «Rate scadute».
    expect(delElenco($this, $s, '2027-04-30')[$ettore]['importo'] ?? null)->toBe(438);
});

/*
 * P3 della lente P: le prove che uccidono le mutazioni sopravvissute di `DebitoEreditato.php` (M-D4, M-D7, M-D10, M-D14 e D15), portate qui dopo le correzioni
 * di N1, D1 e D3 e rilanciate. Ognuna dice un comportamento che il docblock della classe promette.
 */

it('M-D14: Marco versa la rata nuova tranne € 50,00 prima della scadenza: in «Rate scadute» resta un debito di € 50,00, non di € 150,00', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $marco = (int) $s['persone']['Marco']->id;
    $q = RataQuote::where('rata_id', $rata1)->where('anagrafica_id', $marco)->firstOrFail();
    $this->travelTo('2027-02-01 10:00:00');
    lcdIncassa($this, $s, 'Marco', [$q->id => (int) $q->importo - 5000], '2027-02-01');
    $elenco = delElenco($this, $s, '2027-02-20');
    // Con `tenute = parte` la riga «ereditato» resterebbe di € 150,00 anche se la rata nuova ne ha solo € 50,00.
    expect($elenco[$marco]['importo'])->toBe(5000)
        ->and(collect($elenco[$marco]['rate'])->first(fn ($r) => $r['ereditato'] !== null)['residuo'])->toBe(5000);
});

it('M-D10: con la rata 4 di Ettore emessa e non ancora scaduta, i saldi «non ancora in un piano» scendono solo della parte scaduta mostrata come «ereditato»', function () {
    $s = ideCaso($this);
    $this->travelTo('2026-10-01 10:00:00');
    lcdEmetti($this, $s, [4], '2026-10-01');
    $q4 = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 4))->where('anagrafica_id', $s['persone']['Neri']->id)->value('importo');
    ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-11-25 12:00:00');
    $nonEmesse = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class)->nonEmesse($s['c'], '2026-11-25', null);
    $lettura = (new DebitoEreditato())->perLaLettura((int) $s['c']->id, '2026-11-25');
    // Per gruppo (persona, gestione, unità) come li assorbirà il piano, senza la parte mostrata: la sola rata 1 (scaduta), € 150,00 a erede; la rata 4, non
    // scaduta, resta nei saldi. Con la mutazione (`$inBozza` senza `scaduta`) anche la rata 4 si toglieva, e € 300,00 sparivano dalla cifra.
    $perGruppo = DB::table('saldi')->where('condominio_id', $s['c']->id)->where('is_applicato', false)->get(['anagrafica_id', 'gestione_id', 'immobile_id', 'saldo_iniziale'])
        ->groupBy(fn ($r) => $r->anagrafica_id . '|' . $r->gestione_id . '|' . $r->immobile_id)->map(fn ($g) => (int) $g->sum('saldo_iniziale'));
    $atteso = (int) $perGruppo->map(fn ($n, $k) => max(0, $n - (int) ($lettura['saldi_mostrati_per'][$k] ?? 0)))->sum();
    expect($q4)->toBe(30000)->and($lettura['saldi_mostrati'])->toBe(30000)->and(array_sum($lettura['saldi_mostrati_per']))->toBe(30000)
        ->and($nonEmesse['saldi'])->toBe($atteso);
});

it('M-D7: con 10 giorni di «Interessi dopo la scadenza», un accordo di rientro di Marco sulla rata nuova del 05/04/2027: la riga ereditata si ferma il 04/04/2027 (decisione 438)', function () {
    $s = ideCaso($this, 10);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $marco = (int) $s['persone']['Marco']->id;
    $quota = (int) RataQuote::where('rata_id', $rata1)->where('anagrafica_id', $marco)->value('importo');
    $this->travelTo('2027-04-05 12:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $marco, 'data_accordo' => '2027-04-05', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => $rata1, 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => number_format($quota / 100, 2, ',', '')]],
        'scadenze' => [['data' => '2027-09-30', 'importo' => number_format($quota / 100, 2, ',', '')]], 'decadenza_rate' => 1, 'decadenza_giorni' => 15,
    ])->assertCreated();
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    // Senza la regola dell'accordo la riga conterebbe fino al 10/04: sei giorni di interessi su un debito coperto dall'accordo.
    expect($r['al'])->toBe('2027-04-04');
});

it('M-D4: gli interessi di Ettore registrati fino al giorno del decesso: la riga ereditata di Elena parte il giorno dopo, nessun giorno contato due volte', function () {
    $s = ideCaso($this);
    $this->travelTo('2026-11-17 10:00:00');
    $p = accProposta($this, $s, ['Neri'], '2026-11-17')->assertOk()->json();
    accRegistra($this, $s, $p)->assertCreated();
    ideRegistra($this, $s, ideSuccessione($s));
    $r = ideEreditata(ideProposta($this, $s, ['Elena'], '2026-12-31'), $s, 'Elena');
    // Con `>` al posto di `>=` la riga partirebbe il 17/11, il giorno che gli interessi di Ettore hanno già contato.
    expect($r['dal'])->toBe('2026-11-18');
});

it('D15: con «continuano a suo nome» la scelta 1 non aggiunge agli eredi gli interessi già addebitati a Ettore, anche con la scelta 7 accesa', function () {
    $s = ideCaso($this);
    \App\Models\Gestionale\RegolaRecupero::where('condominio_id', $s['c']->id)->update(['tasso_annuo' => '50.0000']);
    $p = ideProposta($this, $s, ['Neri'], '2026-10-31');
    accRegistra($this, $s, $p)->assertCreated();
    \App\Models\ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id], [
        \App\Services\Gestionale\Accessori\RegoleDegliEredi::SCELTE['interessi_sugli_interessi'][0] => true,
        \App\Services\Gestionale\Accessori\RegoleDegliEredi::SCELTE['buco_continuano'][0] => true,
    ]);
    ideRegistra($this, $s, ideSuccessione($s, interessi: \App\Models\Gestionale\Subentro::INTERESSI_CONTINUANO));
    idePianoNuovo($this, $s);
    $righe = collect(accDi(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco')['righe'] ?? []);
    // Con la mutazione (la scelta 1 senza la condizione di «si fermano al decesso») Marco avrebbe una riga sugli interessi di Ettore passati a lui.
    expect($righe->first(fn ($r) => ($r['ereditata']['accessorio'] ?? false) === true))->toBeNull()
        ->and($righe->first(fn ($r) => str_contains($r['chiave'], '|s')))->not->toBeNull();
});

/*
 * P1 della lente P: le letture del debito ereditato non crescono con le successioni e con gli eredi, e in una richiesta di «Rate scadute» si fanno una volta.
 * Nel caso di Ettore esteso ai tre proprietari di Via Verdi (Neri, Bianchi, Verdi), ognuno con la sua successione a più eredi in parti uguali, registrate il
 * 20/11/2026. Si contano le letture al database (sqlite), non i tempi.
 */

/** Via Verdi con le successioni dei primi `$quante` proprietari, ognuna a `$eredi` eredi in parti uguali. */
function delSuccessioni($test, int $quante, int $eredi): array
{
    $s = ideCaso($test);
    $test->travelTo('2026-11-20 10:00:00');
    foreach (array_slice([['Neri', 'U1'], ['Bianchi', 'U2'], ['Verdi', 'U3']], 0, $quante) as $i => [$chi, $u]) {
        $lista = [];
        for ($j = 0; $j < $eredi; $j++) {
            $p = \App\Models\Anagrafica::forceCreate(['nome' => "Erede {$i}-{$j}", 'email' => "del-{$s['c']->id}-{$i}-{$j}@test.it", 'indirizzo' => 'Via Verdi 1',
                'codice_fiscale' => 'DEL' . str_pad((string) ($s['c']->id * 100 + $i * 10 + $j), 13, '0', STR_PAD_LEFT)]);
            $p->condomini()->syncWithoutDetaching([$s['c']->id]);
            $lista[] = ['anagrafica_id' => $p->id, 'quota' => 100 / $eredi];
        }
        $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita'][$u]]), ['tipo' => 'successione', 'riga_uscente_id' => $s['righe'][$chi],
            'decorrenza' => '2026-11-17', 'quota' => 100, 'tipologia' => 'proprietario', 'eredi' => $lista, 'arretrato' => \App\Models\Gestionale\Subentro::ARRETRATO_AGLI_EREDI,
            'erede_di_riferimento' => $lista[0]['anagrafica_id'], 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. ' . ($i + 1), 'pertinenze' => [],
            'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'])->assertSessionHasNoErrors();
    }

    return $s;
}

/** Le letture al database durante la funzione, e quante volte si sono lette le successioni del condominio (una per ogni lettura del debito ereditato). */
function delConta(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return ['letture' => count($log), 'successioni_lette' => count(array_filter($log, fn ($q) => str_contains($q['query'], '"subentri"') && str_contains($q['query'], '"tipo_passaggio" = ?')
        && str_contains($q['query'], '"subentro_padre_id" is null')))];
}

it('P1: prima del piano le letture del debito ereditato non crescono con le successioni e con gli eredi', function () {
    $misure = [];
    foreach ([[1, 2], [3, 5]] as [$quante, $eredi]) {
        $s = delSuccessioni($this, $quante, $eredi);
        $this->travelTo('2026-11-25 10:00:00');
        $cid = (int) $s['c']->id;
        $misure["{$quante}x{$eredi}"] = ['letture' => delConta(fn () => (new DebitoEreditato())->perLaLettura($cid, '2026-11-25'))['letture'],
            'eredi' => count((new DebitoEreditato())->perLaLettura($cid, '2026-11-25')['eredi'])];
    }
    // Con il codice di prima: 13 letture con una successione e due eredi, 33 con tre e cinque (lente P).
    expect($misure['1x2']['eredi'])->toBe(2)->and($misure['3x5']['eredi'])->toBe(15)
        ->and($misure['3x5']['letture'])->toBeLessThanOrEqual($misure['1x2']['letture'] + 5);
});

it('P1: dopo l\'emissione del piano che assorbe, le letture del debito ereditato non crescono con gli eredi', function () {
    $misure = [];
    foreach ([[1, 2], [1, 5]] as [$quante, $eredi]) {
        $s = delSuccessioni($this, $quante, $eredi);
        idePianoNuovo($this, $s);
        $this->travelTo('2027-02-01 10:00:00');
        $cid = (int) $s['c']->id;
        $misure["{$quante}x{$eredi}"] = delConta(fn () => (new DebitoEreditato())->perLaLettura($cid, '2027-02-01'))['letture'];
    }
    // Con il codice di prima: 40 e 70 (lente P).
    expect($misure['1x5'])->toBeLessThanOrEqual($misure['1x2'] + 2);
});

it('P1: in una richiesta di «Rate scadute» il debito ereditato si legge una volta sola', function () {
    $s = delSuccessioni($this, 1, 2);
    $this->travelTo('2026-11-25 10:00:00');
    $pagina = delConta(fn () => $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => (int) $s['c']->id]))->assertOk());
    // Con il codice di prima: ogni `app(DebitoEreditato::class)` era un oggetto nuovo, e l'elenco, la riga dei saldi e i suggerimenti lo rileggevano da capo.
    expect($pagina['successioni_lette'])->toBe(1);
});
