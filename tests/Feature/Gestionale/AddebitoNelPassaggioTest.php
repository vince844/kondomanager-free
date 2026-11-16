<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un addebito resta a chi l'ha avuto attraverso un passaggio di proprietà: il conguaglio del passaggio non prende le quote del piano degli addebiti e
 * non le divide per giorni, e l'anteprima lo dice. Nella successione con l'arretrato agli eredi l'addebito aperto del defunto è un suo debito come gli
 * altri: entra nell'arretrato, e le righe di saldo lo portano agli eredi per quota; l'anteprima dice che passa, una cosa sola. Gli interessi e le spese
 * senza fattura passano agli eredi per quota come interessi e spese, non come un saldo: restano da incassare, e il versamento di un erede va alla voce.
 *
 * Il caso: Via Verdi con le quattro rate emesse, U1 di Neri (500 millesimi, € 2.000,00 di rate). Neri vende U1 a Gialli dal 01/09/2026: a chi entra
 * vanno i giorni dal 01/09 al 31/12, 122 su 365, cioè € 2.000,00 × 122/365 = 668,4932 → € 668,49. Con un addebito di € 50,00 a Neri su U1, se il
 * conguaglio lo prendesse, a Gialli andrebbero anche € 50,00 × 122/365 = 16,7123 → € 16,71 (€ 685,20 in tutto): un debito personale di Neri.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';
require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** I dati del passaggio di U1 da Neri a Gialli dal 01/09/2026, come li manda il modulo. */
function adbDatiDelPassaggio(array $s, Anagrafica $gialli): array
{
    return ['tipo' => 'vendita', 'riga_uscente_id' => $s['righe']['Neri'], 'anagrafica_entrante_id' => $gialli->id, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-09-03',
        'estremi_titolo' => 'atto notaio Bruni, rep. 7', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito del 01/09, letto il pannello'];
}

/** Gialli, chi compra U1. */
function adbGialli(array $s): Anagrafica
{
    $gialli = Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'GLLADB' . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $gialli->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $gialli;
}

/** Il conguaglio a chi entra: la somma delle sue righe nei saldi del passaggio. */
function adbConguaglioAChiEntra(array $s, Anagrafica $gialli): int
{
    return (int) DB::table('saldi')->where('condominio_id', $s['c']->id)->where('anagrafica_id', $gialli->id)->whereNotNull('subentro_id')->sum('saldo_iniziale');
}

it('il passaggio di U1 da Neri a Gialli dà a chi entra € 668,49, con o senza un addebito di € 50,00 a Neri; l\'addebito resta a Neri', function () {
    // Senza addebito.
    $senza = cpcViaVerdi($this);
    cpcRateEIncassi($this, $senza);
    $gialliSenza = adbGialli($senza);
    $this->travelTo('2026-09-05 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$senza['c'], $senza['unita']['U1']]), adbDatiDelPassaggio($senza, $gialliSenza))
        ->assertSessionHasNoErrors()->assertRedirect();

    // Con l'addebito di € 50,00 a Neri su U1, registrato il 01/05.
    $con = cpcViaVerdi($this);
    cpcRateEIncassi($this, $con);
    $gialliCon = adbGialli($con);
    $this->travelTo('2026-05-01 10:00:00');
    $rataId = adbRegistra($this, $con, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Spesa della cantina', 'scadenza' => '2026-06-01']])
        ->assertCreated()->json('rate.0');
    $this->travelTo('2026-09-05 10:00:00');

    // L'anteprima: l'addebito non è fra le rate che il conguaglio riguarda, e una frase dice che resta a Neri.
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$con['c'], $con['unita']['U1']]), adbDatiDelPassaggio($con, $gialliCon))
        ->assertOk()->json();
    expect(collect($anteprima['rate']['emesse'])->pluck('piano')->unique()->values()->all())->toBe(['Preventivo 2026'])
        ->and($anteprima['rate']['frasi'])->toContain('Neri ha addebiti aperti su U1 per € 50,00: restano a suo nome, il passaggio non li sposta.');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$con['c'], $con['unita']['U1']]), adbDatiDelPassaggio($con, $gialliCon))
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(adbConguaglioAChiEntra($senza, $gialliSenza))->toBe(66849)
        ->and(adbConguaglioAChiEntra($con, $gialliCon))->toBe(66849)
        // La quota dell'addebito resta di Neri, emessa, senza la traccia di una riassegnazione.
        ->and(RataQuote::where('rata_id', $rataId)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $con['persone']['Neri']->id])
        ->and(RataQuote::where('rata_id', $rataId)->first()->regole_calcolo['riassegnazione'] ?? null)->toBeNull()
        ->and(RataQuote::where('rata_id', $rataId)->whereNotNull('scrittura_contabile_id')->exists())->toBeTrue();
});

/** Un erede di Via Verdi. */
function adbErede(array $s, string $nome): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-erede-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'ERE' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** La successione di U1 dal 01/09/2026 ad Anna e Bruno al 50 %, con l'arretrato dato, come la manda il modulo. */
function adbSuccessione(array $s, Anagrafica $anna, Anagrafica $bruno, string $arretrato): array
{
    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => $arretrato, 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'];
}

// I saldi del passaggio (le coppie del conguaglio e le righe dell'arretrato) di Neri, Anna e Bruno. Senza l'addebito, con l'arretrato agli eredi:
// Neri −€ 300,00, Anna e Bruno € 150,00 ciascuno; con l'addebito di € 50,00 aperto, € 25,00 in più a ciascun erede. Con l'arretrato a nome del
// defunto ci sono le sole coppie dei giorni dal 01/09 (Neri −€ 668,49, Anna € 334,25, Bruno € 334,24): l'addebito resta suo, e non cambiano.
dataset('arretrato e addebito', [
    'agli eredi, con l\'addebito' => ['eredi', true, [-35000, 17500, 17500]],
    'agli eredi, senza' => ['eredi', false, [-30000, 15000, 15000]],
    'al defunto, con l\'addebito' => ['defunto', true, [-66849, 33425, 33424]],
    'al defunto, senza' => ['defunto', false, [-66849, 33425, 33424]],
]);

it('la successione di Neri con un contributo di € 50,00 aperto: i saldi del passaggio e le frasi dell\'anteprima dicono la stessa cosa', function (string $arretrato, bool $conAddebito, array $attesi) {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $eredeA = adbErede($s, 'Anna');
    $eredeB = adbErede($s, 'Bruno');
    $rataId = null;
    if ($conAddebito) {
        $this->travelTo('2026-05-01 10:00:00');
        $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-06-01']])
            ->assertCreated()->json('rate.0');
    }
    $corpo = adbSuccessione($s, $eredeA, $eredeB, $arretrato) + ($arretrato === 'defunto' ? ['conguaglio' => \App\Models\Gestionale\Subentro::SCRIVI_IL_CONGUAGLIO] : []);

    $this->travelTo('2026-09-10 10:00:00');
    $frasi = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate.frasi');
    $degliAddebiti = array_values(array_filter($frasi, fn (string $f) => str_contains($f, 'addebiti aperti')));
    expect($degliAddebiti)->toBe(match (true) {
        ! $conAddebito => [],
        $arretrato === 'eredi' => ['Neri ha addebiti aperti su U1 per € 50,00: passano agli eredi con l\'arretrato.'],
        default => ['Neri ha addebiti aperti su U1 per € 50,00: restano a suo nome, il passaggio non li sposta.'],
    });
    if ($conAddebito && $arretrato === 'eredi') {
        // La frase della morosità conta l'addebito fra i € 350,00 che passano agli eredi: le due frasi dicono la stessa cosa.
        expect(collect($frasi)->contains(fn (string $f) => str_contains($f, 'Neri ha € 350,00 scaduti e non pagati: passano agli eredi')))->toBeTrue();
    }

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    $saldi = DB::table('saldi')->where('condominio_id', $s['c']->id)->whereNotNull('subentro_id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'));
    expect([(int) ($saldi[$s['persone']['Neri']->id] ?? 0), (int) ($saldi[$eredeA->id] ?? 0), (int) ($saldi[$eredeB->id] ?? 0)])->toBe($attesi);
    if ($rataId !== null) {
        // La quota dell'addebito resta dov'è, a nome di Neri.
        expect(RataQuote::where('rata_id', $rataId)->get(['anagrafica_id', 'importo', 'importo_pagato'])->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo, (int) $q->importo_pagato])->all())
            ->toBe([[(int) $s['persone']['Neri']->id, 5000, 0]]);
    }
})->with('arretrato e addebito');

/** Neri con gli interessi al 29/06/2026 registrati (€ 1,18), e la sua successione dal 01/09 ad Anna e Bruno al 50 % con l'arretrato agli eredi. */
function adbInteressiNellaSuccessione($test): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    $anna = adbErede($s, 'Anna');
    $bruno = adbErede($s, 'Bruno');
    $test->travelTo('2026-09-10 10:00:00');

    return [$s, $rataId, $anna, $bruno, adbSuccessione($s, $anna, $bruno, 'eredi')];
}

function adbAccessoriDaIncassare(array $s): int
{
    $conto = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'accessori_da_incassare')->value('id');

    return (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto)->sum(DB::raw("CASE WHEN tipo_riga = 'avere' THEN importo ELSE -importo END"));
}

it('la successione con l\'arretrato agli eredi: gli interessi di Neri passano ad Anna e Bruno come interessi, per quota, non nei saldi; restano da incassare, e il versamento di Anna va alla voce', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbInteressiNellaSuccessione($this);
    $frasi = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate.frasi');
    expect($frasi)->toContain('Gli interessi e le spese addebitati a Neri e non versati, € 1,18, passano agli eredi come interessi e spese, per quota: € 0,59 ad Anna, '
        . '€ 0,59 a Bruno. Restano da incassare finché un erede li versa, e il versamento va alla loro voce.')
        ->and(collect($frasi)->filter(fn ($f) => str_contains($f, 'addebiti aperti'))->all())->toBe([]);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    // I saldi del passaggio senza gli interessi: quelli di una successione senza addebiti (con € 1,18 erano −301,18, −299,41, 600,59).
    $saldi = DB::table('saldi')->where('condominio_id', $s['c']->id)->whereNotNull('subentro_id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'));
    expect([$saldi[$s['persone']['Neri']->id], $saldi[$anna->id], $saldi[$bruno->id]])->toBe([-30000, -30000, 60000]);

    // Le quote: quella di Neri chiusa a zero, una per erede con i dati dell'addebito, sulla stessa rata; «Accessori da incassare» resta € 1,18.
    $quote = RataQuote::where('rata_id', $rataId)->orderBy('id')->get();
    expect($quote->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo, (int) $q->importo_pagato, $q->tipo])->all())->toBe([
        [$s['persone']['Neri']->id, 0, 0, 'accessorio'], [$anna->id, 59, 0, 'accessorio'], [$bruno->id, 59, 0, 'accessorio'],
    ])->and(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($quote[1])['tipo'])->toBe('interessi')
        ->and(adbAccessoriDaIncassare($s))->toBe(118);
    // Nel giornale il debito della rata è degli eredi.
    $netto = fn ($chi) => (int) DB::table('righe_scritture')->where('rata_id', $rataId)->where('anagrafica_id', $chi)
        ->sum(DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END"));
    expect([$netto($s['persone']['Neri']->id), $netto($anna->id), $netto($bruno->id)])->toBe([0, 59, 59]);

    // Anna versa i suoi € 0,59: la coppia va alla voce, e da incassare restano € 0,59.
    $this->travelTo('2026-09-20 10:00:00');
    $s['persone']['Anna'] = $anna;
    lcdIncassa($this, $s, 'Anna', [$quote[1]->id => 59], '2026-09-20');
    expect(adbAccessoriDaIncassare($s))->toBe(59)
        ->and((int) DB::table('righe_scritture')->where('voce_spesa_id', $s['interessi']->id)->sum(DB::raw("CASE WHEN tipo_riga = 'avere' THEN importo ELSE -importo END")))->toBe(59);

    // Con un versamento di un erede il passaggio non si annulla, e la frase dice perché.
    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertJsonValidationErrors(['passaggio' => 'Un erede ha già versato su «Interessi di mora al 29/06/2026», passato agli eredi con la successione. Storna prima l\'incasso, poi torna qui.']);
});

it('annullata la successione, gli interessi tornano a Neri come erano: via le quote degli eredi e la loro scrittura', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbInteressiNellaSuccessione($this);
    $scritture = DB::table('scritture_contabili')->count();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();

    expect(RataQuote::where('rata_id', $rataId)->get()->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo, $q->stato])->all())
        ->toBe([[$s['persone']['Neri']->id, 118, 'da_pagare']])
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture)
        ->and(adbAccessoriDaIncassare($s))->toBe(118);
});
