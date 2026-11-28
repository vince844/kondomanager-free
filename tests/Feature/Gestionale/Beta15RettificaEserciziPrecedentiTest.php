<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La rettifica della spesa ad personam contata due volte, scritta nell'anno aperto per un'emissione di un anno chiuso, si chiama
 * «Rettifica di esercizi precedenti» e non sta fra le quote registrate dell'anno aperto.
 *
 * Il caso: le due rate del piano «Balcone interno 1» (€ 1.220,00 ad personam su Interno 1) emesse nel 2026 come le scriveva la .58; il
 * 2026 si chiude, il 2027 è aperto, oggi è il 15/02/2027. Dopo la conferma del riallineamento la rettifica (DARE «Gestione rate»
 * € 1.220,00) sta nel 2027. A mano: quote registrate nel 2027 € 0,00 (nessuna rata emessa nel 2027), rettifica di esercizi precedenti
 * −€ 1.220,00, costi € 0,00, risultato −€ 1.220,00 (lo stesso di prima: cambia il nome della riga, non il totale). La rettifica dello
 * stesso anno, con l'anno aperto, resta fra le quote registrate.
 *
 * Nelle frasi del risultato il segno sta davanti alla voce («− rettifica di esercizi precedenti € 1.220,00», «+» quando è positiva), mai
 * dentro la cifra; nelle righe delle tabelle la cifra resta con il suo segno. La frase G1 della nota sintetica nomina la rettifica, così il
 * conto torna: 0,00 di quote − 1.220,00 di rettifica − 0,00 di spese = disavanzo di 1.220,00.
 *
 * Cifre in centesimi. Prefisso `b15rep`.
 */

use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** Le due rate emesse nel 2026 come prima, il 2026 chiuso, il 2027 aperto e oggi il 15/02/2027; con `$conferma` il riallineamento confermato. */
function b15repScenario($test, bool $lega = true, bool $conferma = true): array
{
    $s = b59apBase();
    $piano = b59apPiano($test, $s, b59apFattura($test, $s, [b59apRigaAdPersonam($s)]));
    b59apEmettiComePrima($s, $piano, 1, '2026-04-01');
    b59apEmettiComePrima($s, $piano, 2, '2026-05-01');
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $id = (int) DB::table('esercizi')->insertGetId(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto',
        'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()]);
    $s['nuovo'] = \App\Models\Esercizio::findOrFail($id);
    if ($lega) {
        legaAEsercizio($s['nuovo'], (int) $s['g']->id);
    }
    $test->travelTo(\Illuminate\Support\Carbon::parse('2027-02-15 10:00:00', 'Europe/Rome'));
    if ($conferma) {
        $test->actingAs($test->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
    }

    return $s;
}

function b15repPagina($test, array $s, $esercizio): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $esercizio]))
        ->assertOk()->viewData('page')['props']['pagina'];
}

foreach (['gestione agganciata al 2027' => true, 'gestione non agganciata' => false] as $nome => $lega) {
    test("la rettifica di un anno chiuso, $nome: lo stato patrimoniale del 2027 ha la riga «Rettifica di esercizi precedenti» −€ 1.220,00 e le quote registrate invariate", function () use ($lega) {
        $s = b15repScenario($this, $lega, false);
        $prima = b15repPagina($this, $s, $s['nuovo']);
        $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
        $p = b15repPagina($this, $s, $s['nuovo']);
        $rg = $p['risultato_gestione'];

        expect($rg['quote_emesse'])->toBe($prima['risultato_gestione']['quote_emesse'])->toBe(0)
            ->and($rg['rettifica_esercizi_precedenti'])->toBe(-122000)
            ->and($rg['costi'])->toBe(0)
            ->and($rg['risultato'])->toBe(-122000)
            ->and($rg['risultato'])->toBe($rg['quote_emesse'] + $rg['rettifica_esercizi_precedenti'] - $rg['costi'])
            ->and($p['situazione']['quadra'])->toBeTrue();

        // Il blocco per gestione: le quote senza la rettifica, la rettifica in una colonna sua; le righe sommano il totale.
        $pg = $p['per_gestione'];
        $somma = fn (string $k) => (int) array_sum(array_column($pg['righe'], $k)) + (int) ($pg['senza_gestione'][$k] ?? 0);
        expect($pg['totale']['quote_emesse'])->toBe(0)
            ->and($pg['totale']['rettifica_esercizi_precedenti'])->toBe(-122000)
            ->and($somma('quote_emesse'))->toBe(0)
            ->and($somma('rettifica_esercizi_precedenti'))->toBe(-122000);

        // La stampa dello stato patrimoniale dice la riga con il suo nome.
        $catturati = null;
        View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$catturati) {
            $catturati ??= $v->getData();
        });
        $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['nuovo']]))->assertOk();
        $testo = vrmTesto(view('pdf.gestionale.stato_patrimoniale', $catturati)->render());
        expect($testo)->toContain('quote registrate in questo esercizio € 0,00 − rettifica di esercizi precedenti € 1.220,00 − costi € 0,00');

        // Positiva, il «+».
        $catturati['pagina']['risultato_gestione']['rettifica_esercizi_precedenti'] = 122000;
        expect(vrmTesto(view('pdf.gestionale.stato_patrimoniale', $catturati)->render()))
            ->toContain('quote registrate in questo esercizio € 0,00 + rettifica di esercizi precedenti € 1.220,00 − costi € 0,00');
    });
}

test('la rettifica di un anno chiuso nel rendiconto del 2027: il riepilogo, la Sintesi e il frontespizio hanno la riga «Rettifica di esercizi precedenti», con lo stesso risultato', function () {
    $s = b15repScenario($this);

    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['nuovo']);
    expect($r['risultato'])->toBe(['quote_emesse' => 0, 'rettifica_esercizi_precedenti' => -122000, 'costi' => 0, 'risultato' => -122000])
        // Il raccordo delle quote dei piani non ha più la differenza della rettifica (prima: «debiti riportati dentro le rate» € 1.220,00).
        ->and($r['raccordo_quote']['quote_giornale'])->toBe(0)
        ->and($r['raccordo_quote']['altre_differenze'])->toBe(0);
    $html = vrmTesto(view('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $s['c'], 'esercizio' => $s['nuovo'], 'riepilogo' => $r, 'dettaglio' => false])->render());
    expect($html)->toContain('quote registrate in questo esercizio € 0,00 − rettifica di esercizi precedenti € 1.220,00 − costi € 0,00 = € -1.220,00');
    $positiva = array_replace_recursive($r, ['risultato' => ['rettifica_esercizi_precedenti' => 122000]]);
    expect(vrmTesto(view('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $s['c'], 'esercizio' => $s['nuovo'], 'riepilogo' => $positiva, 'dettaglio' => false])->render()))
        ->toContain('quote registrate in questo esercizio € 0,00 + rettifica di esercizi precedenti € 1.220,00 − costi € 0,00');

    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['nuovo']);
    expect($sintesi['prospetto']['quote_giornale'])->toBe(0)
        ->and($sintesi['prospetto']['rettifica_esercizi_precedenti'])->toBe(-122000)
        ->and($sintesi['prospetto']['risultato'])->toBe(-122000);

    $dati = null;
    View::composer('pdf.gestionale.rendiconto_frontespizio', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    app(FascicoloDelRendiconto::class)->genera($s['c'], $s['nuovo'], ['riepilogo']);
    $fronte = vrmTesto(view('pdf.gestionale.rendiconto_frontespizio', $dati)->render());
    // La riga del prospetto con la sua cifra, e la frase del risultato con il segno davanti alla voce.
    expect($fronte)->toContain('Rettifica di esercizi precedenti € -1.220,00')
        ->toContain('Risultato: quote registrate in questo esercizio € 0,00 − rettifica di esercizi precedenti € 1.220,00 − costi € 0,00');
    $dati['sintesi']['prospetto']['rettifica_esercizi_precedenti'] = 122000;
    expect(vrmTesto(view('pdf.gestionale.rendiconto_frontespizio', $dati)->render()))
        ->toContain('quote registrate in questo esercizio € 0,00 + rettifica di esercizi precedenti € 1.220,00 − costi € 0,00');

    // La pagina della Sintesi riceve la cifra.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['nuovo']]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('sintesi.prospetto.rettifica_esercizi_precedenti', -122000)->where('sintesi.prospetto.quote_giornale', 0)->etc());
});

test('la frase G1 della nota del 2027 nomina la rettifica di esercizi precedenti: 0,00 di quote, 1.220,00 in meno, 0,00 di spese, disavanzo di 1.220,00', function () {
    $s = b15repScenario($this);
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['nuovo']);
    $g1 = collect(app(FattiDellaNota::class)->perEsercizio($s['c'], $s['nuovo'], $letture)['fatti'])->firstWhere('chiave', 'G1');
    $e = fn (string $cifra) => '€'.FattiDellaNota::SPAZIO.$cifra;

    expect($g1['frase'])->toBe('Le spese dell\'esercizio sono '.$e('0,00').', le quote chieste ai condòmini con le rate sono '.$e('0,00')
        .' e la rettifica di esercizi precedenti è di '.$e('1.220,00').' in meno: il risultato è un disavanzo di '.$e('1.220,00').'.')
        ->and($g1['cifre'])->toBe(['costi' => 0, 'quote' => 0, 'rettifica' => -122000, 'risultato' => -122000])
        // Senza un voto: la frase non va fra i punti proposti per l'ordine del giorno.
        ->and($g1['voto'])->toBeNull();
});

test('la frase G1 con la rettifica: in più con un avanzo, e il pareggio quando la rettifica azzera le quote', function () {
    $e = fn (string $cifra) => '€'.FattiDellaNota::SPAZIO.$cifra;
    $inPiu = FattiDellaNota::g1(['costi' => 10000, 'quote_giornale' => 12000, 'rettifica_esercizi_precedenti' => 5000, 'risultato' => 7000]);
    $pari = FattiDellaNota::g1(['costi' => 0, 'quote_giornale' => 122000, 'rettifica_esercizi_precedenti' => -122000, 'risultato' => 0]);

    expect($inPiu['frase'])->toBe('Le spese dell\'esercizio sono '.$e('100,00').', le quote chieste ai condòmini con le rate sono '.$e('120,00')
        .' e la rettifica di esercizi precedenti è di '.$e('50,00').' in più: il risultato è un avanzo di '.$e('70,00').'.')
        ->and($pari['frase'])->toBe('Le spese dell\'esercizio sono '.$e('0,00').', le quote chieste ai condòmini con le rate sono '.$e('1.220,00')
            .' e la rettifica di esercizi precedenti è di '.$e('1.220,00').' in meno: il risultato è in pareggio.');
});

test('controprova — la rettifica dello stesso anno, con l\'anno aperto, resta fra le quote registrate e non ha la riga', function () {
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apEmettiComePrima($s, $piano, 1, '2026-04-01');
    b59apEmettiComePrima($s, $piano, 2, '2026-05-01');
    $prima = b15repPagina($this, $s, $s['e'])['risultato_gestione'];
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
    $rg = b15repPagina($this, $s, $s['e'])['risultato_gestione'];

    expect($prima['quote_emesse'])->toBe(122000)
        ->and($rg['quote_emesse'])->toBe(0)
        ->and($rg['rettifica_esercizi_precedenti'])->toBe(0)
        ->and($rg['risultato'])->toBe(0);
});

test('il 2026 chiuso resta com\'era: quote registrate € 1.220,00, nessuna rettifica di esercizi precedenti', function () {
    $s = b15repScenario($this);
    $rg = b15repPagina($this, $s, $s['e'])['risultato_gestione'];

    expect($rg['quote_emesse'])->toBe(122000)->and($rg['rettifica_esercizi_precedenti'])->toBe(0)->and($rg['risultato'])->toBe(122000);
});
