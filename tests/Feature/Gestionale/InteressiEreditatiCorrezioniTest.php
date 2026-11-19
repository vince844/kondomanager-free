<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il giro delle correzioni della 1.12.0-beta.12 sugli interessi del debito ereditato, sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`):
 * la rata di aprile da € 300,00 scaduta il 30/04/2026, il 2 % l'anno dal 01/01/2026, il decesso il 17/11/2026, Elena e Marco al 50 %; il piano 2027 che assorbe
 * l'arretrato ha la prima rata il 31/03/2027 ed è emesso il 15/01/2027.
 *
 * - Decisione 516 (E1 della seconda prova): il debito ereditato resta in «Rate scadute», nei sei mesi, nella tesoreria e nelle lettere finché corrono i suoi
 *   interessi, anche dopo l'emissione della rata nuova, con «nella Rata 1 — Preventivo 2027 che scade il 31/03/2027»; niente contato due volte.
 * - E2: l'avviso accanto al defunto anche quando la sua riga si ferma prima per un accordo di rientro.
 * - E4: con un piano a più rate la parte ereditata di ogni rata è nella proporzione dell'arretrato (niente credito finto).
 * - E5: registrando in due volte, lo stesso credito non copre due volte gli stessi giorni (anche senza successione).
 * - E6, E7, E8: le frasi dell'annullamento e le cause dei tratti.
 * - La proposta per un solo erede porta il defunto con le sole righe della successione, non il coerede.
 * - «Cambia per questa successione» tiene solo le scelte cambiate.
 * - E3: l'interruttore 3 dice il campo vero, «Interessi dopo la scadenza».
 * - Decisioni 511 e 512: il tasso accanto alla cifra; «Cambia» con il tasso.
 * - La voce di entrata con il preventivo a zero.
 *
 * Che cosa NON copre: la pagina a video (vitest `RateScaduteEreditato.test.ts`, `ProponiInteressiDialogTasso.test.ts`); il tema scuro e il telefono; MySQL;
 * un tasso legale del 2027 (qui c'è un tasso scritto); le successioni a catena; il piano che assorbe in un'altra gestione; più successioni sulla stessa rata
 * nuova dello stesso erede (la lettura le tiene, una prova sua non c'è).
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Accessori\RegoleDegliEredi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le scelte del condominio, con quelle date cambiate. */
function iecRegole(array $s, array $scelte): void
{
    $colonne = [];
    foreach ($scelte as $chiave => $valore) {
        $colonne[RegoleDegliEredi::SCELTE[$chiave][0]] = $valore;
    }
    ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id], $colonne);
}

/** «Rate scadute» alla data, «oggi» spostato a quel giorno: persona => la riga. */
function iecElenco($test, array $s, string $alla): \Illuminate\Support\Collection
{
    $test->travelTo($alla . ' 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);

    return $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => $alla]))->keyBy('anagrafica_id');
}

/** Registra le righe date della proposta, con le cifre o i tassi cambiati e il perché. */
function iecRegistra($test, array $s, array $proposta, array $chiavi, array $importi = [], ?string $motivo = null, array $tassi = [])
{
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => $p['righe'])->whereIn('chiave', $chiavi);

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), array_filter([
        'alla' => $proposta['alla'], 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
        'importi' => $importi ?: null, 'tassi' => $tassi ?: null, 'motivo' => $motivo,
    ], fn ($v) => $v !== null));
}

/** L'accordo di rientro di Ettore del 15/07/2026 sulla rata 1, € 300,00, in regola al decesso. */
function iecAccordo($test, array $s): void
{
    $test->travelTo('2026-07-15 12:00:00');
    $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        'scadenze' => [['data' => '2027-07-31', 'importo' => '150,00'], ['data' => '2027-08-31', 'importo' => '150,00']], 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();
}

it('decisione 516: dopo l\'emissione della rata nuova il debito ereditato resta in «Rate scadute» finché corrono i suoi interessi, con la rata nuova e la sua scadenza', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $marco = $s['persone']['Marco']->id;

    // Il 20/02/2027 la rata 1/2027 è emessa ma non scaduta; la proposta di Marco conta ancora il debito ereditato, dal 17/11/2026.
    $elenco = iecElenco($this, $s, '2027-02-20');
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-02-20'), $s, 'Marco');
    expect([$r['dal'], $r['al']])->toBe(['2026-11-17', '2027-02-20']);
    // Con il codice di prima: Marco non c'era più (la parte usciva all'emissione, e la rata nuova non è scaduta): € 150,00 spariti dalla pagina.
    expect($elenco->has($marco))->toBeTrue()
        ->and($elenco[$marco]['importo'])->toBe(15000)
        ->and($elenco[$marco]['rate'][0]['ereditato']['frase'])->toBe('da Ettore Fabbri, successione del 17/11/2026, nella Rata 1 — Preventivo 2027 che scade il 31/03/2027')
        ->and($elenco[$marco]['rate'][0]['ereditato']['nelle_rate'][0])->toMatchArray(['rata_id' => $rata1, 'scadenza' => '2027-03-31', 'parte' => 15000]);
    // Niente contato due volte: nella lettura la rata 1/2027 di Marco ha i € 150,00 in meno finché sono una riga «ereditato».
    $quota = (int) RataQuote::where('rata_id', $rata1)->where('anagrafica_id', $marco)->value('importo');
    $lettura = app(\App\Services\Gestionale\Accessori\DebitoEreditato::class)->sullaLettura((int) $s['c']->id, '2027-02-20', null,
        [$marco => app(\App\Services\Gestionale\Debiti\LetturaDebiti::class)->perPersona((int) $s['c']->id, $marco, '2027-02-20')], [$marco]);
    $nuova = collect($lettura[$marco]['rate'])->firstWhere('rata_id', $rata1);
    expect($nuova['residuo'])->toBe($quota - 15000)->and($nuova['nelle_righe_ereditate'])->toBe(15000);

    // I sei mesi, la tesoreria e la lettera dicono la stessa cosa.
    $seiMesi = collect(app(\App\Services\Gestionale\Debiti\SeiMesiPerAgire::class)->perCondominio($s['c'])['righe'])->flatMap(fn ($x) => $x['persone'] ?? [])
        ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('cifra'));
    $tesoreria = collect(app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['morositaImpattanti'])->pluck('importoCents', 'anagraficaId');
    $lettera = app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->contenuti($s['c'], 'sollecito', ['persone' => [$marco]])['persone'][$marco] ?? null;
    expect($seiMesi[$marco] ?? null)->toBe(15000)->and($tesoreria[$marco] ?? null)->toBe(15000)
        ->and(collect($lettera['righe'] ?? [])->pluck('ereditato')->filter()->first())->toBe('Ereditato da Ettore Fabbri, successione del 17/11/2026, nella Rata 1 — Preventivo 2027 che scade il 31/03/2027');

    // Dal 01/04/2027 la rata nuova conta i suoi interessi: il debito è nella rata di Marco, scaduta, e la riga «ereditato» non c'è più.
    $dopo = iecElenco($this, $s, '2027-04-01');
    expect(collect($dopo[$marco]['rate'])->filter(fn ($x) => $x['ereditato'] !== null)->all())->toBe([])
        ->and(collect($dopo[$marco]['rate'])->firstWhere('rata_id', $rata1)['residuo'])->toBe($quota)
        // Decisione 528 (N1 della terza prova): Ettore ha i suoi € 4,38 della rata nuova, scaduta; con il codice di prima spariva.
        ->and($dopo[$s['persone']['Neri']->id]['importo'] ?? null)->toBe(438);
});

it('decisione 516 con 10 giorni di «Interessi dopo la scadenza»: fino al 10/04 la riga «ereditato» dice «scaduta il 31/03/2027», e la rata nuova ha quella parte in meno', function () {
    $s = ideCaso($this, 10);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $marco = $s['persone']['Marco']->id;
    $quota = (int) RataQuote::where('rata_id', $rata1)->where('anagrafica_id', $marco)->value('importo');

    $elenco = iecElenco($this, $s, '2027-04-05');
    $righe = collect($elenco[$marco]['rate']);
    // Il debito una volta sola: € 150,00 nella riga «ereditato» e il resto della rata nuova, scaduta. Con il codice di prima la riga «ereditato» non c'era
    // dal 15/01, e la proposta la contava fino al 10/04.
    expect($elenco[$marco]['importo'])->toBe($quota)
        ->and($righe->firstWhere('rata_id', $rata1)['residuo'])->toBe($quota - 15000)
        ->and($righe->first(fn ($x) => $x['ereditato'] !== null)['ereditato']['frase'])->toBe('da Ettore Fabbri, successione del 17/11/2026, nella Rata 1 — Preventivo 2027 scaduta il 31/03/2027');
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-05'), $s, 'Marco')['al'])->toBe('2027-04-05');

    $tardi = iecElenco($this, $s, '2027-04-11');
    expect(collect($tardi[$marco]['rate'])->filter(fn ($x) => $x['ereditato'] !== null)->all())->toBe([])->and($tardi[$marco]['importo'])->toBe($quota);
});

it('E4: il piano divide l\'arretrato di Marco su quattro rate, con la sua parte delle bozze: la parte ereditata scende a scalini, € 37,50 a rata, € 2,24 al 31/12/2027', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, 'tutte_rate', 4, '2027-01-15', [1, 2, 3, 4]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-12-31'), $s, 'Marco');
    // Come la prova della scelta 4 con l'arretrato di Marco ridotto a € 150,00: 15000 fino al 31/03, 11250 al 30/06, 7500 al 30/09, 3750 al 31/12. Con il codice
    // di prima la prima rata prendeva tutto quello che la sua fetta dell'arretrato teneva, e la riga si fermava prima.
    expect([$r['interessi'], $r['al']])->toBe([224, '2027-12-31'])
        ->and(array_column($r['tratti'], 'capitale'))->toBe([15000, 15000, 11250, 7500, 3750]);
});

it('E5: registrando in due volte lo stesso credito non copre due volte gli stessi giorni (senza successione)', function () {
    $s = ideCaso($this);
    // Ettore deve anche la rata 2 (30/06), emessa il 15/06, e ha € 100,00 a credito dal 15/05.
    $this->travelTo('2026-06-15 10:00:00');
    lcdEmetti($this, $s, [2], '2026-06-15');
    ideRigaNelGiornale($s, 'Neri', null, '2026-05-15', -10000, 'incasso_rata');
    $chiave = fn (int $n) => $s['persone']['Neri']->id . '|' . lcdRata($s, $n) . '|' . $s['unita']['U1']->id;
    $prima = ideProposta($this, $s, ['Neri'], '2026-10-31');
    $rata2 = collect(accDi($prima, $s, 'Neri')['righe'])->firstWhere('chiave', $chiave(2))['interessi'];
    // La rata 1 da sola: il credito copre lei, la più vecchia.
    iecRegistra($this, $s, $prima, [$chiave(1)])->assertCreated();
    $dopo = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-10-31'), $s, 'Neri')['righe'])->firstWhere('chiave', $chiave(2));
    // Con il codice di prima la rata 1, registrata fino alla data, usciva dal calcolo e il suo credito passava alla rata 2 negli stessi giorni: meno interessi.
    expect($dopo['interessi'])->toBe($rata2)->and($rata2)->toBeGreaterThan(0);
});

it('E2: accanto a Ettore l\'avviso che i suoi interessi passeranno agli eredi anche quando la sua riga si ferma prima per l\'accordo di rientro', function () {
    $s = ideCaso($this);
    iecAccordo($this, $s);
    ideRegistra($this, $s, ideSuccessione($s));
    $ettore = accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri');
    expect($ettore['righe'][0]['al'])->toBe('2026-07-14')
        // Con il codice di prima: nessun avviso (la riga si fermava per l'accordo, non al decesso), e la registrazione li passava lo stesso.
        ->and($ettore['dopo_il_decesso'])->toBe('Registrati, gli interessi di Ettore Fabbri fino al decesso passano a Elena e Marco per quota, come quelli già addebitati prima della successione.');
});

it('E6: l\'avviso dell\'annullamento dice la cifra dell\'addebito vero, anche con una riga ferma prima per l\'accordo', function () {
    $s = ideCaso($this);
    iecRegole($s, ['interessi_defunto_agli_eredi' => false]);
    $this->travelTo('2026-06-15 10:00:00');
    lcdEmetti($this, $s, [2], '2026-06-15');
    iecAccordo($this, $s);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    $righe = collect(accDi($p, $s, 'Neri')['righe']);
    // La rata 1 si ferma al 14/07 (l'accordo), la rata 2 al 16/11 (il decesso): un addebito solo per U1.
    expect($righe->pluck('al')->sort()->values()->all())->toBe(['2026-07-14', '2026-11-16']);
    iecRegistra($this, $s, $p, $righe->pluck('chiave')->all())->assertCreated();
    $addebito = (int) RataQuote::where('anagrafica_id', $s['persone']['Neri']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->value('importo');
    expect($addebito)->toBe((int) $righe->sum('interessi'));
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    // Con il codice di prima la cifra era quella della sola rata 2.
    expect(collect($storico['annullabile']['avvisi'])->first(fn ($a) => str_contains($a, 'registrati con la scelta')))->toContain(', ' . \App\Helpers\MoneyHelper::format($addebito) . ', fino al 16/11/2026)');
});

it('E7: con l\'accordo di rientro in regola che l\'annullamento riapre, la frase non promette i giorni dopo il decesso', function () {
    $s = ideCaso($this);
    iecAccordo($this, $s);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-12-31 10:00:00');
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    // Con il codice di prima: «… torna a contare i giorni dopo il decesso, dal 17/11/2026, …», e dopo l'annullamento la proposta di Ettore era a zero.
    expect($storico['annullabile']['effetti'])->toContain('La proposta degli interessi di Ettore Fabbri non conta i giorni dopo il decesso: l\'accordo di rientro del 15/07/2026, che si '
        . 'riapre, li tiene fuori finché regge, e quella di Elena e Marco non conta più il debito ereditato.');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect()->assertSessionHas('message', fn ($m) => str_contains(is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE) : (string) $m, 'che si è riaperto, li tiene fuori'));
    $riga = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri')['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));
    expect($riga['al'] ?? null)->toBe('2026-07-14');
});

it('E8: con «Partono dal giorno del decesso» spento il primo tratto dice «dal giorno dopo il decesso»', function () {
    $s = ideCaso($this);
    iecRegole($s, ['dal_decesso' => false]);
    ideRegistra($this, $s, ideSuccessione($s));
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    // Con il codice di prima: «decesso», cioè «dal giorno del decesso», per un tratto che parte il 18/11.
    expect([$r['tratti'][0]['dal'], $r['tratti'][0]['causa']])->toBe(['2026-11-18', 'dopo_il_decesso']);
});

it('la proposta chiesta per la sola Elena porta Ettore con le sole righe della successione, non Marco; chiesta per Ettore, tutte le sue', function () {
    $s = ideCaso($this);
    // Ettore è anche comproprietario di U3, con un debito suo lì.
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U3']->id, 'tipologia' => 'proprietario', 'quota' => 10,
        'data_inizio' => '2020-01-01', 'attivo' => true, 'created_at' => now(), 'updated_at' => now()]);
    ideRigaNelGiornale($s, 'Neri', lcdRata($s, 1), '2026-01-15', 5000, 'emissione_rata', 'U3');
    ideRegistra($this, $s, ideSuccessione($s));
    $perElena = ideProposta($this, $s, ['Elena'], '2026-12-31');
    $ettore = accDi($perElena, $s, 'Neri');
    // Con il codice di prima: anche Marco, e Ettore con la riga di U3.
    expect(collect($perElena['persone'])->pluck('nome')->sort()->values()->all())->toBe(['Elena', 'Ettore Fabbri'])
        ->and(collect($ettore['righe'])->pluck('immobile_id')->unique()->values()->all())->toBe([(int) $s['unita']['U1']->id])
        ->and($ettore['totale'])->toBe((int) collect($ettore['righe'])->sum('interessi'));
    $perEttore = accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri');
    expect(collect($perEttore['righe'])->pluck('immobile_id')->unique()->sort()->values()->all())->toBe([(int) $s['unita']['U1']->id, (int) $s['unita']['U3']->id]);
});

it('«Cambia per questa successione» tiene solo la scelta cambiata: le altre seguono il condominio anche se cambia dopo', function () {
    $s = ideCaso($this);
    // La pagina di prima mandava le otto: il server tiene solo quella diversa dal condominio.
    $passaggio = ideRegistra($this, $s, ideSuccessione($s, eredi: array_merge(RegoleDegliEredi::diSerie(), ['dal_decesso' => false])));
    expect($passaggio->interessi_eredi)->toBe(['dal_decesso' => false]);
    iecRegole($s, ['anche_accordo' => false]);
    $scelte = RegoleDegliEredi::perSuccessione($passaggio->fresh());
    // Con il codice di prima la successione teneva le otto, e «anche_accordo» restava acceso.
    expect($scelte['dal_decesso'])->toBeFalse()->and($scelte['anche_accordo'])->toBeFalse();
});

it('E3: l\'interruttore 3 dice il campo vero, «Interessi dopo la scadenza», con le stesse parole nella guida', function () {
    $t = RegoleDegliEredi::TESTI['tolleranza_rata_nuova'];
    // Con il codice di prima: «Il debito ereditato ha la tolleranza della rata nuova», e la «i» diceva «il regolamento dà 10 giorni».
    expect($t['titolo'])->toContain('«Interessi dopo la scadenza»')->and($t['acceso'])->toContain('«Interessi dopo la scadenza»')
        ->and($t['spento'])->toContain('«Interessi dopo la scadenza»')->and($t['esempio'])->toContain('«Interessi dopo la scadenza» è 10 giorni')
        ->and(implode(' ', $t))->not->toContain('tolleranza della rata nuova');
    // La guida della pagina ha gli stessi titoli ed esempi del server.
    $guida = str_replace("\\'", "'", file_get_contents(base_path('resources/js/lib/gestionale/addebiti/interessiDegliEredi.ts')));
    foreach (RegoleDegliEredi::TESTI as $testi) {
        expect($guida)->toContain($testi['titolo'])->toContain($testi['esempio']);
    }
});

it('decisione 511: ogni riga dice il tasso accanto alla cifra, e i tassi dei tratti se nel periodo cambia', function () {
    $s = ideCaso($this);
    \App\Models\Gestionale\RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2027-01-01', 'tasso_tipo' => \App\Models\Gestionale\RegolaRecupero::TASSO_SCRITTO,
        'tasso_annuo' => '3.0000', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'giorni_decorrenza' => 0]);
    ideRegistra($this, $s, ideSuccessione($s));
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco')['tassi'])->toBe(["2,00\u{00A0}%"]);
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2027-01-31'), $s, 'Marco')['tassi'])->toBe(["2,00\u{00A0}%", "3,00\u{00A0}%"]);
});

it('decisione 512: «Cambia» con il tasso rifà la riga sugli stessi giorni, solo in meno; l\'addebito ricorda il tasso e l\'estratto conto lo dice', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Marco'], '2026-12-31');
    $chiave = ideEreditata($p, $s, 'Marco')['chiave'];
    iecRegistra($this, $s, $p, [$chiave], [], 'Accordo con l\'erede', [$chiave => '2,50'])->assertStatus(422)
        ->assertJsonValidationErrors(['tassi' => "Più del tasso delle regole no: per questa riga è 2,00\u{00A0}%. Per un tasso diverso su tutte le righe cambia la regola in «Recupero dei crediti»."]);
    iecRegistra($this, $s, $p, [$chiave], [], 'Accordo con l\'erede', [$chiave => '0'])->assertStatus(422)->assertJsonValidationErrors(['tassi' => 'Per non chiedere niente, togli la riga.']);
    iecRegistra($this, $s, $p, [$chiave], [$chiave => '0,30'], 'Accordo con l\'erede', [$chiave => '1,00'])->assertStatus(422)
        ->assertJsonValidationErrors(['tassi' => 'Per una riga cambia la cifra o il tasso, non tutti e due.']);
    iecRegistra($this, $s, $p, [$chiave], [], null, [$chiave => '1,00'])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    iecRegistra($this, $s, $p, [$chiave], [], 'Accordo con l\'erede', [$chiave => 'tanto'])->assertStatus(422)->assertJsonValidationErrors(['tassi.' . $chiave => 'Scrivi il tasso in percento, per esempio 1,00.']);
    expect(RataQuote::where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->exists())->toBeFalse();

    iecRegistra($this, $s, $p, [$chiave], [], 'Accordo con l\'erede', [$chiave => '1,00'])->assertCreated();
    $quota = RataQuote::with('rata')->where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->firstOrFail();
    $riga = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($quota)['interessi']['righe'][0];
    // 15000 × 1 % × 45 / 365 = 18,49 → € 0,18 (con il codice di prima il tasso non si leggeva: € 0,37).
    expect((int) $quota->importo)->toBe(18)
        ->and([$riga['interessi'], $riga['proposti'], $riga['tasso']])->toBe([18, 37, ['e4' => 10000, 'etichetta' => "1,00\u{00A0}%"]])
        // Decisione 526: la causale dice anche da quale tasso è ridotto, come la direbbe senza «Cambia».
        ->and(trim((string) $quota->rata->descrizione))->toBe("Interessi di mora al 31/12/2026 (1,00\u{00A0}% annuo, scelto dall'amministratore, ridotto dal 2,00\u{00A0}% annuo, art. 12 del regolamento)")
        ->and(accEmissione((int) $quota->rata_id)[0][2])->toBe(18);
    $estratto = collect(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona((int) $s['c']->id, (int) $s['persone']['Marco']->id))->firstWhere('rata_id', $quota->rata_id);
    expect($estratto['cifre_cambiate'])->toBe("Proposti € 0,37 al 2,00\u{00A0}%, registrati € 0,18 all'1,00\u{00A0}% il 31/12/2026: «Accordo con l'erede».");
    // I giorni fino al 31/12 restano chiusi.
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2027-01-31'), $s, 'Marco')['dal'])->toBe('2027-01-01');
});

it('una voce di entrata si crea con il preventivo a zero, anche lasciato vuoto; una voce di spesa vuota no', function () {
    $s = ideCaso($this);
    $crea = fn (string $nome, string $tipo, string $importo) => $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pianoConto']]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['generali']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ]);
    // Con il codice di prima: «L'importo è obbligatorio per le voci di spesa», anche per un'entrata.
    $crea('Interessi di mora del 2027', 'entrata', '')->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::where('nome', 'Interessi di mora del 2027')->value('importo'))->toBe(0);
    $crea('Giardiniere', 'spesa', '')->assertSessionHasErrors(['importo' => 'L\'importo è obbligatorio per le voci di spesa']);
    $crea('Giardiniere a zero', 'spesa', '0,00')->assertSessionHasNoErrors();
});
