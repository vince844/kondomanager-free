<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Code 708 e 757 (1), decisione 690 (3)) — la successione non si annulla dopo il versamento di un erede sul debito ereditato.
 *
 * Il caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la rata di aprile da € 300,00 non pagata, il decesso il 17/11/2026, Elena e Marco al
 * 50 %, l'arretrato agli eredi, la successione registrata il 20/11/2026. Fino alla beta.13 l'annullamento passava (302): toglieva la coppia del Wallet e lasciava
 * l'incasso dell'erede sulla rata del defunto, che tornava debitore pagato col denaro di un altro. Ora si ferma con il nome dell'erede, la cifra e l'incasso
 * da stornare prima, come per gli interessi già registrati agli eredi (decisione 493 (7)).
 *
 * - Elena versa € 150,00: il fermo, niente cambia; lo storico dice lo stesso motivo; stornato l'incasso, l'annullamento passa.
 * - Elena e Marco versano: l'elenco con tutti e due; stornato uno, la frase dice l'altro.
 * - Dal conto di Ettore «per il debito degli eredi»: con la data dal decesso in poi si ferma (anche con «Versato da» Elena); con la data di prima del
 *   decesso l'annullamento passa e il versamento resta di Ettore (il caso contrario della decisione 529).
 * - La coppia già presa dal piano 2027: il fermo dice lo storno, non la frase dei saldi legati; dopo lo storno resta il piano da togliere.
 * - La catena da capo a capo: la successione, «Rate scadute», l'incasso di Elena, il dettaglio e la K4 della nota sintetica, il fermo, lo storno, l'annullamento.
 *
 * Che cosa NON copre: le successioni già annullate dopo un versamento d'erede, dalla beta.12 a oggi (si storna a mano, una frase nel changelog); un erede
 * della catena (decisione 530), le cui coppie stanno nel registro della seconda successione, che si annulla per prima; MySQL (qui sqlite).
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** «Rate scadute» alla data, «oggi» spostato a quel giorno: persona => la riga. */
function b14avElenco($test, array $s, string $alla): \Illuminate\Support\Collection
{
    $test->travelTo($alla . ' 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);

    return $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => $alla]))->keyBy('anagrafica_id');
}

/** Le righe «ereditato» di una persona in «Rate scadute», sommate. */
function b14avEreditato(\Illuminate\Support\Collection $elenco, int $persona): int
{
    return (int) collect($elenco[$persona]['rate'] ?? [])->filter(fn ($r) => ($r['ereditato'] ?? null) !== null)->sum('residuo');
}

/** La quota di Ettore sulla rata di aprile. */
function b14avQuotaEttore(array $s): RataQuote
{
    return RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Neri']->id)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
}

/** L'erede pagante, sul suo debito ereditato: `[subentro => centesimi]`, dalla rotta dell'incasso. Restituisce l'incasso. */
function b14avVersaErede($test, array $s, string $chi, array $ereditato, string $data): ScritturaContabile
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($ereditato);
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [],
        'debito_ereditato' => array_map(fn ($id, $c) => ['subentro_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($ereditato), array_values($ereditato)),
    ])->assertSessionHasNoErrors();

    return ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
}

/** Un versamento sulla rata di aprile di Ettore, pagante Ettore, con la data, «Versato da» e la scelta «per il debito degli eredi». Restituisce l'incasso. */
function b14avVersaSuEttore($test, array $s, int $cent, string $data, bool $agliEredi, ?string $versatoDa = null, string $registrato = '2026-11-25'): ScritturaContabile
{
    $test->travelTo($registrato . ' 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), array_filter([
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => b14avQuotaEttore($s)->id, 'importo' => number_format($cent / 100, 2, '.', '')]],
        'versato_da_id' => $versatoDa === null ? null : $s['persone'][$versatoDa]->id, 'agli_eredi' => $agliEredi ? '1' : '0',
    ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

    return ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
}

/** Le righe del Wallet legate al passaggio: persona => cifra. */
function b14avCoppia(Subentro $p): array
{
    $esito = DB::table('saldi')->where('passaggio_id', $p->id)->get(['anagrafica_id', 'saldo_iniziale'])
        ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->all();
    ksort($esito);

    return array_filter($esito);
}

/** L'annullamento della successione dalla rotta dello storico, il giorno dato. */
function b14avAnnulla($test, array $s, Subentro $p, string $giorno = '2026-11-28')
{
    $test->travelTo($giorno . ' 10:00:00');

    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $p]),
        ['nota_annullamento' => 'Successione registrata per errore']);
}

/** Lo storno dell'incasso dalla rotta della pagina, il giorno dato. */
function b14avStorna($test, array $s, ScritturaContabile $incasso, string $giorno = '2026-11-27'): void
{
    $test->travelTo($giorno . ' 10:00:00');
    $test->actingAs($test->user)->from('/incassi')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso->id]), stornoVersamentoTornato())
        ->assertSessionHasNoErrors();
}

/** «incasso INC-2026-00002 del 25/11/2026». */
function b14avIncasso(ScritturaContabile $sc): string
{
    return 'incasso ' . $sc->numero_protocollo . ' del ' . $sc->data_competenza->format('d/m/Y');
}

/** Le righe di titolarità dell'unità, com'erano. */
function b14avRighe(array $s): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U1']->id)->orderBy('id')->get()
        ->map(fn ($r) => collect((array) $r)->except(['updated_at'])->all())->all();
}

/** Il pulsante «Annulla» dello storico della successione. */
function b14avStorico(array $s, Subentro $p): array
{
    return collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $p->id)['annullabile'];
}

it('Coda 708: Elena ha versato € 150,00 sul debito ereditato; l\'annullamento si ferma con il suo nome, la cifra e l\'incasso, e niente cambia', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');
    [$ettore, $elena] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id];
    $righe = b14avRighe($s);
    expect($incasso->numero_protocollo)->toStartWith('INC-2026-');

    // Con il codice di prima l'annullamento passava (302): la coppia tolta, l'incasso di Elena sulla rata di Ettore, Ettore di nuovo debitore.
    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Elena ha già versato € 150,00 sul debito ereditato con questa '
        . 'successione (' . b14avIncasso($incasso) . '). Storna prima il suo incasso dall\'elenco degli incassi, poi torna qui.']);

    expect(Subentro::conAnnullati()->findOrFail($p->id)->annullato())->toBeFalse()
        ->and(b14avRighe($s))->toBe($righe)
        ->and(b14avCoppia($p))->toBe([$ettore => 15000, $elena => -15000])
        ->and((int) b14avQuotaEttore($s)->fresh()->importo_pagato)->toBe(15000)
        ->and($incasso->fresh()->stato)->toBe('registrata');
});

it('Coda 708: lo storico dice che la successione non si annulla, con lo stesso motivo', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');

    $storico = b14avStorico($s, $p);

    // Con il codice di prima: `si` vero e nessun motivo.
    expect($storico['si'])->toBeFalse()
        ->and($storico['motivo'])->toBe('Elena ha già versato € 150,00 sul debito ereditato con questa successione (' . b14avIncasso($incasso) . '). Storna prima il '
            . 'suo incasso dall\'elenco degli incassi, poi torna qui.')
        ->and($storico['avvisi'])->toBe([]);
});

it('Coda 708: stornato l\'incasso di Elena, l\'annullamento passa: Ettore in «Rate scadute» con la rata di aprile intera, gli eredi senza righe «ereditato», nessuna coppia', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');
    [$ettore, $elena, $marco] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id, $s['persone']['Marco']->id];
    b14avStorna($this, $s, $incasso);

    b14avAnnulla($this, $s, $p)->assertRedirect();

    $elenco = b14avElenco($this, $s, '2026-12-31');
    $aprile = collect($elenco[$ettore]['rate'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));
    expect(Subentro::conAnnullati()->findOrFail($p->id)->annullato())->toBeTrue()
        ->and($aprile['residuo'] ?? null)->toBe(30000)
        ->and([b14avEreditato($elenco, $elena), b14avEreditato($elenco, $marco)])->toBe([0, 0])
        ->and(b14avCoppia($p))->toBe([])
        ->and((int) b14avQuotaEttore($s)->fresh()->importo_pagato)->toBe(0);
});

it('Coda 708: Elena e Marco hanno versato; la frase li dice tutti e due; stornato l\'incasso di Elena, dice solo Marco', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $diElena = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');
    $diMarco = b14avVersaErede($this, $s, 'Marco', [$p->id => 5000], '2026-11-26');

    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Gli eredi hanno già versato sul debito ereditato con questa '
        . 'successione: Elena € 150,00 (' . b14avIncasso($diElena) . '), Marco € 50,00 (' . b14avIncasso($diMarco) . '). Storna prima quegli incassi '
        . 'dall\'elenco degli incassi, poi torna qui.']);

    b14avStorna($this, $s, $diElena);
    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Marco ha già versato € 50,00 sul debito ereditato con questa '
        . 'successione (' . b14avIncasso($diMarco) . '). Storna prima il suo incasso dall\'elenco degli incassi, poi torna qui.']);

    b14avStorna($this, $s, $diMarco);
    b14avAnnulla($this, $s, $p)->assertRedirect();
    expect(Subentro::conAnnullati()->findOrFail($p->id)->annullato())->toBeTrue();
});

it('Coda 708: dal conto di Ettore «per il debito degli eredi» con la data del 25/11 si ferma; con «Versato da» Elena dice il suo nome', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $perGliEredi = b14avVersaSuEttore($this, $s, 10000, '2026-11-25', true);

    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Sul debito ereditato con questa successione c\'è un versamento '
        . 'di € 100,00 registrato per gli eredi Elena e Marco (' . b14avIncasso($perGliEredi) . '). Storna prima quell\'incasso dall\'elenco degli incassi, poi '
        . 'torna qui.']);

    b14avStorna($this, $s, $perGliEredi);
    $diElena = b14avVersaSuEttore($this, $s, 15000, '2026-11-27', true, 'Elena', '2026-11-27');
    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Elena ha già versato € 150,00 sul debito ereditato con questa '
        . 'successione (' . b14avIncasso($diElena) . '). Storna prima il suo incasso dall\'elenco degli incassi, poi torna qui.']);
});

it('Coda 708, il caso contrario: € 100,00 dal conto di Ettore con la data del 10/11, prima del decesso: l\'annullamento passa e il versamento resta di Ettore', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = b14avVersaSuEttore($this, $s, 10000, '2026-11-10', true);
    expect(b14avCoppia($p))->toBe([$s['persone']['Neri']->id => 10000, $s['persone']['Elena']->id => -5000, $s['persone']['Marco']->id => -5000]);

    b14avAnnulla($this, $s, $p)->assertRedirect();

    // La coppia per quota se n'è andata con le righe legate; il versamento resta sulla rata di Ettore, a nome suo, senza legami agli eredi.
    expect(Subentro::conAnnullati()->findOrFail($p->id)->annullato())->toBeTrue()
        ->and(b14avCoppia($p))->toBe([])
        ->and((int) b14avQuotaEttore($s)->fresh()->importo_pagato)->toBe(10000)
        ->and($incasso->fresh()->stato)->toBe('registrata')
        ->and(DB::table('righe_scritture')->where('scrittura_id', $incasso->id)->whereNotNull('riferimento_id')->exists())->toBeFalse();
});

it('Coda 708: la coppia già presa dal piano 2027: il fermo dice lo storno, non la frase dei saldi legati; dopo lo storno resta il piano', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $incasso = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');
    idePianoNuovo($this, $s, emissione: null);
    expect(DB::table('saldi')->where('passaggio_id', $p->id)->where('is_applicato', false)->exists())->toBeFalse();

    $frase = b14avAnnulla($this, $s, $p, '2026-12-01')->assertStatus(422)->json('errors.passaggio.0');
    expect($frase)->toBe('Elena ha già versato € 150,00 sul debito ereditato con questa successione (' . b14avIncasso($incasso) . '). Storna prima il suo '
        . 'incasso dall\'elenco degli incassi, poi torna qui.')
        ->and($frase)->not->toContain('saldo del Wallet');

    // Lo storno scrive la coppia rovescia, legata alla successione; il piano tiene la prima: ora il fermo è il piano, e non dice più Elena.
    b14avStorna($this, $s, $incasso, '2026-12-02');
    $dopo = b14avAnnulla($this, $s, $p, '2026-12-03')->assertStatus(422)->json('errors.passaggio.0');
    expect($dopo)->toContain('Preventivo 2027')->and($dopo)->not->toContain('ha già versato');
});

it('la catena da capo a capo: successione → «Rate scadute» → incasso di Elena → dettaglio e K4 → annullamento fermo → storno → annullamento → «Rate scadute» con Ettore, l\'estratto di Elena senza la coppia', function () {
    $s = ideCaso($this);
    [$ettore, $elena, $marco] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id, $s['persone']['Marco']->id];
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $prima = b14avElenco($this, $s, '2026-11-24');
    expect([b14avEreditato($prima, $elena), b14avEreditato($prima, $marco)])->toBe([15000, 15000])->and($prima->has($ettore))->toBeFalse();

    $incasso = b14avVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25');
    // Il dettaglio dell'incasso: «Sul debito ereditato», non «fuori ordine» (Coda 713); la K4 della nota sintetica non lo conta.
    $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], $incasso->id]))->assertOk()
        ->assertInertia(fn ($x) => $x->where('imputazione.codice', 'debito_ereditato')->where('imputazione.testo', 'Sul debito ereditato')->etc());
    expect(\App\Services\Gestionale\FattiDellaNota::k4($s['e']->fresh())['frase'])->not->toContain('in un altro modo');

    b14avAnnulla($this, $s, $p)->assertStatus(422)->assertJsonValidationErrors(['passaggio']);
    expect(b14avStorico($s, $p)['si'])->toBeFalse();
    b14avStorna($this, $s, $incasso);
    b14avAnnulla($this, $s, $p)->assertRedirect();

    $dopo = b14avElenco($this, $s, '2026-12-31');
    expect(collect($dopo[$ettore]['rate'] ?? [])->firstWhere('rata_id', lcdRata($s, 1))['residuo'] ?? null)->toBe(30000)
        ->and([b14avEreditato($dopo, $elena), b14avEreditato($dopo, $marco)])->toBe([0, 0])
        ->and(app(\App\Services\Gestionale\EstrattoContoDellaPersona::class)->ledger($s['c'], $s['e']->fresh(), $s['persone']['Elena'])[2])->toBe(0)
        ->and(DB::table('saldi')->where('anagrafica_id', $elena)->exists())->toBeFalse();
});
