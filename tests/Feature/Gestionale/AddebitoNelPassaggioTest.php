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

// ─── Il promemoria del defunto e quelli degli eredi (Coda 652) ─────────────────────────────────────────────────────────────
//
// Passati gli interessi e le spese agli eredi, il promemoria del portale segue le quote come nella successione delle rate in bozza
// (`EventiRataCondomino::seguonoLeQuote`): quello del defunto si riallinea alla sua quota chiusa al versato e dice «pagato»; ogni erede ha il suo, nato
// come quello di un addebito (emesso, con la causale nel titolo). L'annullamento del passaggio li rimette com'erano: via quelli degli eredi, quello del
// defunto di nuovo da versare.

/** Il promemoria del portale di ogni persona sulla rata: persona => [stato, pagato, versato, da compensare, titolo, emesso]. */
function adbPromemoria(int $rataId): array
{
    $esito = [];
    foreach (\App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
        ->where(fn ($q) => $q->where('meta->context->rata_id', $rataId)->orWhere('meta->context->rata_id', (string) $rataId))->with('anagrafiche:id')->orderBy('id')->get() as $e) {
        foreach ($e->anagrafiche as $a) {
            $esito[(int) $a->id] = [$e->meta['status'] ?? null, (int) ($e->meta['importo_pagato'] ?? -1), (int) ($e->meta['importo_versato'] ?? -1),
                (int) ($e->meta['da_compensare'] ?? -1), (string) $e->title, $e->meta['is_emitted'] ?? null];
        }
    }

    return $esito;
}

/** Neri con le spese di sollecito di € 25,00 su U1 (senza fattura), versate per i centesimi dati, e la sua successione dal 01/09 ad Anna e Bruno al 50 %. */
function adbSollecitoNellaSuccessione($test, int $versati = 0, string $euro = '25,00'): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-07-10 10:00:00');
    $rataId = (int) adbRegistra($test, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => $euro, 'causale' => 'Spese di sollecito', 'scadenza' => '2026-07-10',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');
    if ($versati > 0) {
        $test->travelTo('2026-07-12 10:00:00');
        lcdIncassa($test, $s, 'Neri', [adbQuota($rataId)->id => $versati], '2026-07-12');
    }
    $anna = adbErede($s, 'Anna');
    $bruno = adbErede($s, 'Bruno');
    $test->travelTo('2026-09-10 10:00:00');

    return [$s, $rataId, $anna, $bruno, adbSuccessione($s, $anna, $bruno, 'eredi')];
}

it('Coda 652 — il sollecito di Neri, € 16,01 non versati, passa ad Anna e Bruno: il promemoria di Neri si chiude, Anna e Bruno hanno il loro con la loro parte', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    $prima = adbPromemoria($rataId);
    expect(array_keys($prima))->toBe([$neri])->and($prima[$neri][0])->toBe('pending');
    $titolo = $prima[$neri][4];

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    // € 16,01 per metà: 800,5 e 800,5; il centesimo che resta va al primo erede (resti uguali): € 8,01 ad Anna, € 8,00 a Bruno.
    $quote = \App\Models\Gestionale\RataQuote::where('rata_id', $rataId)->orderBy('id')->get()->mapWithKeys(fn ($q) => [(int) $q->anagrafica_id => [(int) $q->importo, (int) $q->importo_pagato]])->all();
    expect($quote)->toBe([$neri => [0, 0], $anna->id => [801, 0], $bruno->id => [800, 0]]);
    $dopo = adbPromemoria($rataId);
    // Neri: niente da versare, pagato e versato zero, chiuso. Gli eredi: da versare, la loro quota, nati come il promemoria di un addebito.
    expect($dopo[$neri][0])->toBe('paid')->and(array_slice($dopo[$neri], 1, 3))->toBe([0, 0, 0])
        ->and($dopo[$anna->id])->toBe(['pending', 0, 0, 0, $titolo, true])
        ->and($dopo[$bruno->id])->toBe(['pending', 0, 0, 0, $titolo, true]);
    $restante = fn (int $chi) => (int) \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $chi))->first()->meta['importo_restante'];
    expect([$restante($anna->id), $restante($bruno->id)])->toBe([801, 800]);
});

it('Coda 652 — Neri aveva versato € 8,99 del sollecito di € 25,00: il suo promemoria passa da «in parte pagato» a «pagato», gli eredi hanno i € 16,01 che restano', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbSollecitoNellaSuccessione($this, 899);
    $neri = (int) $s['persone']['Neri']->id;
    expect(adbPromemoria($rataId)[$neri][0])->toBe('partial');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    // La quota di Neri si chiude al versato, € 8,99; i € 16,01 aperti per metà, € 8,01 ad Anna e € 8,00 a Bruno.
    $quote = \App\Models\Gestionale\RataQuote::where('rata_id', $rataId)->orderBy('id')->get()->mapWithKeys(fn ($q) => [(int) $q->anagrafica_id => [(int) $q->importo, (int) $q->importo_pagato]])->all();
    expect($quote)->toBe([$neri => [899, 899], $anna->id => [801, 0], $bruno->id => [800, 0]]);
    $dopo = adbPromemoria($rataId);
    // Il versato di Neri resta il suo: € 8,99, pagato e versato.
    expect(array_slice($dopo[$neri], 0, 4))->toBe(['paid', 899, 899, 0])
        ->and(array_slice($dopo[$anna->id], 0, 4))->toBe(['pending', 0, 0, 0])
        ->and(array_slice($dopo[$bruno->id], 0, 4))->toBe(['pending', 0, 0, 0]);
});

it('Coda 652 — annullata la successione, il promemoria di Neri torna com\'era e quelli degli eredi non ci sono più', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbSollecitoNellaSuccessione($this, 899);
    $neri = (int) $s['persone']['Neri']->id;
    $evento = fn () => \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $neri))->first();
    $prima = [$evento()->meta, $evento()->description, adbPromemoria($rataId)];

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    expect(count(adbPromemoria($rataId)))->toBe(3);
    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();

    // Lo stato, le cifre, il dettaglio e il testo di Neri come prima del passaggio; nessun promemoria degli eredi, nemmeno nella tabella ponte.
    expect([$evento()->meta, $evento()->description, adbPromemoria($rataId)])->toBe($prima)
        ->and(\Illuminate\Support\Facades\DB::table('anagrafica_evento')->whereIn('anagrafica_id', [$anna->id, $bruno->id])->count())->toBe(0);
});

it('Coda 652 — un pagamento segnalato dal portale da un erede sul sollecito passato con la successione ferma l\'annullamento, e lo dice', function () {
    [$s, $rataId, $anna, , $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    $suo = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $anna->id))->firstOrFail();
    $suo->update(['meta' => ['status' => 'reported'] + $suo->meta]);

    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertJsonValidationErrors(['passaggio' => 'Su «Spese di sollecito», passato agli eredi con la successione, c\'è un pagamento segnalato dal portale da Anna, non ancora verificato. Verificalo o rifiutalo, poi torna qui.']);
    expect($suo->fresh()->meta['status'])->toBe('reported')->and($passaggio->fresh()->annullato())->toBeFalse();
});

it('Coda 652 — dopo la successione il promemoria di Neri, chiuso, non dice più € 16,01 da versare: il portale legge un restante a zero', function () {
    [$s, $rataId, , , $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    // Quello che il portale riceve del promemoria di Neri (`EventoPortaleResource`): «pagato», niente da versare. Prima diceva «Totale da versare € 16,01».
    $evento = adbPromemoriaDi($rataId, $neri);
    $meta = (new \App\Http\Resources\Portale\EventoPortaleResource($evento))->resolve(request())['meta'];
    expect([$meta['status'], $meta['importo_restante'], $meta['importo_pagato']])->toBe(['paid', 0, 0]);
});

it('Coda 652 — con € 8,99 versati su € 25,00, dopo la successione il promemoria di Neri dice pagato € 8,99 e niente da versare', function () {
    [$s, $rataId, , , $corpo] = adbSollecitoNellaSuccessione($this, 899);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $meta = adbPromemoriaDi($rataId, (int) $s['persone']['Neri']->id)->meta;
    expect([$meta['status'], $meta['importo_restante'], $meta['importo_pagato'], $meta['importo_versato']])->toBe(['paid', 0, 899, 899]);
});

// ─── Un pagamento segnalato dal portale e non verificato sull'accessorio del defunto (decisione 448) ───────────────────────────────────────────
//
// Di serie l'accessorio resta al defunto, come la bozza segnalata (R17, `ConguaglioPassaggio`): l'anteprima lo dice, e la segnalazione e il suo compito
// restano com'erano. L'amministratore può scegliere, nell'anteprima, di passarlo comunque agli eredi per quota: la frase dice che la segnalazione resta da
// verificare, e il promemoria del defunto resta «segnalato». Il controllo si rifà sotto lock nella scrittura; l'annullamento rimette tutto com'era.

/** Il promemoria del portale di una persona sulla rata. */
function adbPromemoriaDi(int $rataId, int $persona): \App\Models\Evento
{
    return \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $persona))->firstOrFail();
}

/** Il 20/07 Neri (o chi usa il suo accesso) segnala dal portale il pagamento del sollecito; poi si torna al 10/09. L'utente del portale. */
function adbSegnalaNeri($test, array $s, int $rataId): \App\Models\User
{
    $portale = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $portale->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::VIEW_EVENTS->value, 'guard_name' => 'web']));
    $s['persone']['Neri']->update(['user_id' => $portale->id]);
    $test->travelTo('2026-07-20 10:00:00');
    $test->actingAs($portale)->post(route('user.eventi.report_payment', adbPromemoriaDi($rataId, (int) $s['persone']['Neri']->id)))->assertSessionHas('success');
    $test->travelTo('2026-09-10 10:00:00');

    return $portale;
}

/** I compiti «verifica il pagamento» ancora aperti. */
function adbCompitiAperti(): int
{
    return \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::VERIFICA_PAGAMENTO->value)->where('is_completed', false)->count();
}

/** Le quote della rata: persona => [importo, pagato]. */
function adbQuoteDellaRata(int $rataId): array
{
    return RataQuote::where('rata_id', $rataId)->orderBy('id')->get()->mapWithKeys(fn ($q) => [(int) $q->anagrafica_id => [(int) $q->importo, (int) $q->importo_pagato]])->all();
}

it('decisione 448 — di serie il sollecito con un pagamento segnalato resta a Neri: l\'anteprima lo dice e la registrazione non lo passa; la segnalazione resta', function () {
    [$s, $rataId, , , $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    adbSegnalaNeri($this, $s, $rataId);
    $quota = (int) adbQuota($rataId)->id;

    $rate = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate');
    expect($rate['arretrato']['accessori'])->toBe([])
        ->and($rate['arretrato']['frase_accessori'])->toBeNull()
        ->and($rate['arretrato']['accessori_segnalati'])->toBe([['quota_id' => $quota, 'causale' => 'Spese di sollecito', 'aperto' => 1601, 'aperto_formattato' => '€ 16,01', 'passa' => false]])
        ->and($rate['frasi'])->toContain('«Spese di sollecito» (€ 16,01) ha un pagamento segnalato dal portale e non ancora verificato: resta a nome di Neri e non passa '
            . 'agli eredi, e la segnalazione resta da verificare. Puoi passarlo comunque agli eredi con la casella in «L\'arretrato del defunto».')
        ->and(collect($rate['frasi'])->filter(fn ($f) => str_contains($f, 'addebiti aperti'))->values()->all())->toBe([])
        // Né con le righe dell'arretrato: il sollecito non passa agli eredi nemmeno come saldo.
        ->and(collect($rate['arretrato']['righe'])->pluck('di_cui_addebiti')->filter()->values()->all())->toBe([]);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    expect(adbQuoteDellaRata($rataId))->toBe([$neri => [1601, 0]])
        ->and(adbPromemoriaDi($rataId, $neri)->meta['status'])->toBe('reported')
        ->and(adbCompitiAperti())->toBe(1);
});

it('decisione 448 — con la scelta dell\'amministratore il sollecito segnalato passa agli eredi per quota; la segnalazione resta da verificare', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    adbSegnalaNeri($this, $s, $rataId);
    $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];

    $rate = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate');
    expect(array_column($rate['arretrato']['accessori'], 'aperto'))->toBe([1601])
        ->and($rate['arretrato']['accessori_segnalati'][0]['passa'])->toBeTrue()
        ->and($rate['frasi'])->toContain('«Spese di sollecito» (€ 16,01) ha un pagamento segnalato dal portale e non ancora verificato: come hai scelto, passa comunque agli '
            . 'eredi per quota, e la segnalazione resta da verificare. Se il pagamento risulta arrivato, registralo con «Registra l\'incasso» dal compito del bonifico '
            . 'segnalato, sulla quota di ogni erede con «Versato da» Neri: il primo incasso chiude il compito.');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    expect(adbQuoteDellaRata($rataId))->toBe([$neri => [0, 0], $anna->id => [801, 0], $bruno->id => [800, 0]])
        // Il promemoria di Neri resta «segnalato», senza niente da versare; il compito resta aperto.
        ->and([adbPromemoriaDi($rataId, $neri)->meta['status'], adbPromemoriaDi($rataId, $neri)->meta['importo_restante']])->toBe(['reported', 0])
        ->and(adbCompitiAperti())->toBe(1)
        ->and(adbPromemoriaDi($rataId, $anna->id)->meta['status'])->toBe('pending');
});

it('decisione 448 — passato agli eredi, con «Non serve» sul compito il promemoria di Neri non si può segnalare di nuovo per € 16,01', function () {
    [$s, $rataId, , , $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    $portale = adbSegnalaNeri($this, $s, $rataId);
    $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $compito = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::VERIFICA_PAGAMENTO->value)->latest('id')->firstOrFail();
    app(\App\Services\Gestionale\RispostePromemoria::class)->nonServeDelCompito($compito, 'Bonifico non arrivato', $this->user);
    expect(adbPromemoriaDi($rataId, $neri)->meta['status'])->toBe('rejected');

    // Il portale rifiuta: su quella rata a Neri non resta niente da versare. Nessun compito nuovo.
    $compiti = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::VERIFICA_PAGAMENTO->value)->count();
    $this->actingAs($portale)->post(route('user.eventi.report_payment', adbPromemoriaDi($rataId, $neri)))
        ->assertSessionHasErrors(['evento' => 'Su questa rata non resta niente da versare. Non devi fare altro.']);
    expect(adbPromemoriaDi($rataId, $neri)->meta['status'])->toBe('rejected')
        ->and(\App\Models\Evento::where('tipo', \App\Enums\EventoTipo::VERIFICA_PAGAMENTO->value)->count())->toBe($compiti);
});

it('decisione 448 — annullata la successione, il promemoria di Neri torna «segnalato» com\'era, di serie e con la scelta', function (bool $passaComunque) {
    [$s, $rataId, $anna, $bruno, $corpo] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    adbSegnalaNeri($this, $s, $rataId);
    $prima = adbPromemoriaDi($rataId, $neri)->meta;
    if ($passaComunque) {
        $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];
    }
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();

    expect($passaggio->fresh()->annullato())->toBeTrue()
        ->and(adbPromemoriaDi($rataId, $neri)->meta)->toBe($prima)
        ->and(adbQuoteDellaRata($rataId))->toBe([$neri => [1601, 0]])
        ->and(DB::table('anagrafica_evento')->whereIn('anagrafica_id', [$anna->id, $bruno->id])->count())->toBe(0)
        ->and(adbCompitiAperti())->toBe(1);
})->with(['di serie' => false, 'passato comunque' => true]);

it('decisione 448 — il controllo si rifà nella scrittura, sul promemoria riletto: una segnalazione arrivata dopo l\'anteprima ferma il passaggio dell\'accessorio', function () {
    [$s, $rataId, $anna, $bruno] = adbSollecitoNellaSuccessione($this, 0, '16,01');
    $neri = (int) $s['persone']['Neri']->id;
    // La divisione come l'ha letta l'anteprima, senza segnalazioni.
    $divisione = \App\Services\Subentro\AccessoriAgliEredi::divisione(\App\Services\Subentro\AccessoriAgliEredi::aperti($neri, [(int) $s['unita']['U1']->id]),
        [$anna->id => 50.0, $bruno->id => 50.0]);
    expect(array_column($divisione, 'aperto'))->toBe([1601]);
    // Fra l'anteprima e la scrittura arriva la segnalazione dal portale.
    adbSegnalaNeri($this, $s, $rataId);
    $subentro = (new \App\Models\Gestionale\Subentro())->forceFill(['id' => 0, 'condominio_id' => $s['c']->id]);
    $avvisi = [];

    expect(fn () => DB::transaction(fn () => app(\App\Services\Subentro\AccessoriAgliEredi::class)->scrivi($subentro, $divisione, $neri,
        \Carbon\CarbonImmutable::parse('2026-09-01'), $avvisi, $this->user)))
        ->toThrow(\Illuminate\Validation\ValidationException::class, '«Spese di sollecito» è cambiato mentre registravi (un pagamento segnalato dal portale): ricarica il pannello e riprova.');
    expect(adbQuoteDellaRata($rataId))->toBe([$neri => [1601, 0]])
        ->and(adbPromemoriaDi($rataId, $neri)->meta['status'])->toBe('reported');

    // Con la scelta dell'amministratore nella divisione (l'anteprima l'ha vista segnalata), passa.
    $divisione[0]['segnalato'] = true;
    DB::transaction(fn () => app(\App\Services\Subentro\AccessoriAgliEredi::class)->scrivi($subentro, $divisione, $neri,
        \Carbon\CarbonImmutable::parse('2026-09-01'), $avvisi, $this->user));
    expect(adbQuoteDellaRata($rataId))->toBe([$neri => [0, 0], $anna->id => [801, 0], $bruno->id => [800, 0]])
        ->and(adbPromemoriaDi($rataId, $neri)->meta['status'])->toBe('reported');
});
