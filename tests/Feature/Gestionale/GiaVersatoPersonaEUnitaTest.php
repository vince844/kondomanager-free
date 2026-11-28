<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il già versato della persona e quello dell'unità sullo stesso conto: il piano toglie prima la parte dell'unità, in proporzione ai lordi, e poi quella di
 * ciascuna persona dalla sua riga, come il conguaglio del passaggio. Quel che la persona ha versato oltre la sua parte resta suo (l'eccedenza a suo nome).
 *
 * Il caso a mano: Ugo vende a Elsa a metà anno; Pulizie dell'unità € 700,00, € 350,00 a testa per giorni. Già versato: Ugo di tasca sua € 300,00, l'unità
 * (senza nome) € 200,00. € 700,00 − € 200,00 = € 500,00, € 250,00 a testa; Ugo € 250,00 − € 300,00: € 0,00 e € 50,00 a suo credito; Elsa € 250,00.
 * Prima il piano toglieva prima i € 300,00 di Ugo (Ugo € 50,00) e poi divideva i € 200,00 dell'unità 50 a 350: Ugo € 25,00, Elsa € 175,00.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/**
 * Un'unità sola (1000 millesimi), «Pulizie» € 700,00 tutta al proprietario, un esercizio di 364 giorni (01/01–30/12/2026) perché le due metà siano
 * uguali: Ugo fino al 01/07 (182 giorni), Elsa dal 02/07 (182), il passaggio registrato. Il già versato: `$ugo` e `$unita` centesimi.
 */
function gvpuCaso(?int $ugo, ?int $unita): array
{
    $c = Condominio::factory()->create();
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-30']);
    $g = Gestione::create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'descrizione' => 'Ordinaria']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 70000]);
    $t = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Generali', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $t->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $u = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => 'GVPU-'.$c->id, 'nome' => 'Int. 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $u->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    $p = [];
    $righe = [];
    foreach ([['Ugo', '2015-01-01', '2026-07-01'], ['Elsa', '2026-07-02', null]] as [$nome, $dal, $al]) {
        $p[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-gvpu'.$c->id.'@test.it', 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.$c->id), 0, 16))]);
        $righe[$nome] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p[$nome]->id, 'immobile_id' => $u->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('subentri')->insert(['condominio_id' => $c->id, 'immobile_id' => $u->id, 'anagrafica_uscente_id' => $p['Ugo']->id, 'anagrafica_entrante_id' => $p['Elsa']->id,
        'riga_uscente_id' => $righe['Ugo'], 'riga_entrante_id' => $righe['Elsa'], 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-07-02',
        'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$ugo, $p['Ugo']->id], [$unita, null]] as [$importo, $chi]) {
        if ($importo) {
            ContributoVersato::create(['condominio_id' => $c->id, 'target_type' => Conto::class, 'target_id' => $conto->id, 'immobile_id' => $u->id, 'anagrafica_id' => $chi,
                'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione']);
        }
    }
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 1]);

    return compact('c', 'e', 'g', 'conto', 'u', 'p', 'piano');
}

/** Il motore sul caso: [netti per persona, eccedenze per persona, già versato tolto per persona]. */
function gvpuCalcola(array $s): array
{
    $motore = new CalcoloQuoteService();
    $competenza = app(CompetenzaDelPiano::class)->perPiano($s['piano']->fresh(), $s['e']);
    $totali = $motore->calcolaPerGestione($s['g'], $s['piano']->fresh(), false, $competenza);
    $nomi = collect($s['p'])->mapWithKeys(fn ($a, $n) => [$a->id => $n])->all();
    $netti = [];
    foreach ($totali as $aid => $perUnita) {
        $netti[$nomi[$aid]] = (int) array_sum($perUnita);
    }
    $eccedenze = [];
    foreach ($motore->getEccedenzeCopertura() as $e) {
        $chi = isset($e['anagrafica_id']) ? $nomi[$e['anagrafica_id']] : 'unità';
        $eccedenze[$chi] = ($eccedenze[$chi] ?? 0) + (int) $e['eccedenza'];
    }
    $tolto = [];
    foreach (collect($motore->getRigheDettaglio())->where('tipo', 'netting') as $r) {
        $tolto[$nomi[$r['anagrafica_id']]][$r['riga_descrizione']] = -(int) $r['importo'];
    }

    return [$netti, $eccedenze, $tolto];
}

it('il caso a mano: il piano dà Elsa € 250,00 e Ugo € 0,00 con € 50,00 di eccedenza a suo nome, come il conguaglio (prima Elsa € 175,00 e Ugo € 25,00)', function () {
    [$netti, $eccedenze, $tolto] = gvpuCalcola(gvpuCaso(30000, 20000));

    // Lordi 35.000 e 35.000 (182 giorni a testa su 364). L'unità: 20.000 × 35.000/70.000 = 10.000 a testa. Ugo: 25.000 − 30.000 → 0, eccedenza 5.000.
    expect($netti)->toEqual(['Ugo' => 0, 'Elsa' => 25000])
        ->and($eccedenze)->toBe(['Ugo' => 5000])
        ->and($tolto)->toEqual(['Ugo' => [CalcoloQuoteService::NETTING_DELL_UNITA => 10000, CalcoloQuoteService::NETTING_DELLA_PERSONA => 25000],
            'Elsa' => [CalcoloQuoteService::NETTING_DELL_UNITA => 10000]]);
});

it('con una parte sola del già versato il piano non cambia: solo Ugo (€ 300,00) o solo l\'unità (€ 200,00)', function (?int $ugo, ?int $unita, array $attesi, array $eccedenze) {
    [$netti, $ecc] = gvpuCalcola(gvpuCaso($ugo, $unita));

    expect($netti)->toEqual($attesi)->and($ecc)->toBe($eccedenze);
})->with([
    // Solo Ugo: 35.000 − 30.000 = 5.000; Elsa 35.000.
    'solo Ugo' => [30000, null, ['Ugo' => 5000, 'Elsa' => 35000], []],
    // Solo l'unità: 10.000 a testa, 25.000 e 25.000.
    'solo l\'unità' => [null, 20000, ['Ugo' => 25000, 'Elsa' => 25000], []],
]);

it('l\'unità che copre tutta la spesa: le righe vanno a zero e il versato di Ugo resta tutto suo, come eccedenza', function () {
    // Unità € 800,00 su € 700,00: tutte e due le righe a 0, € 100,00 di eccedenza dell'unità; i € 300,00 di Ugo non trovano niente da togliere.
    [$netti, $eccedenze] = gvpuCalcola(gvpuCaso(30000, 80000));

    expect($netti)->toEqual(['Ugo' => 0, 'Elsa' => 0])->and($eccedenze)->toBe(['unità' => 10000, 'Ugo' => 30000]);
});

it('la catena: il conguaglio della vendita (piano generato prima) e il piano generato dopo la vendita danno la stessa cifra a Elsa', function () {
    // Lo scenario della vendita di Ugo a Elsa dall'1/5/2026 su «Spese generali» € 1.200,00 (Ugo € 300,00, l'unità € 200,00). Lordi 39.452 e 80.548; l'unità:
    // 20.000 × 39.452/120.000 = 6.575,3 → 6.575 e 13.424,7 → 13.425; Ugo 32.877 − 30.000 = 2.877; Elsa 80.548 − 13.425 = 67.123: € 671,23, come il conguaglio.
    [$s, $a] = gvpuVendita($this, 30000, 20000);
    $dopo = gvpuNettiDopo($this, 30000, 20000);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 67123]])
        ->and($dopo['Elsa'])->toBe(67123)->and($dopo['Ugo'] ?? 0)->toBe(2877);
});

/** Lo scenario della vendita, come `Beta51PassaggiFrasiTest`: il piano generato prima ed emesso, e l'anteprima della vendita dell'1/5. */
function gvpuVendita($test, ?int $persona, ?int $unita): array
{
    $s = gvpuVersato($persona, $unita);
    fclGenera($s);
    fclEmetti($test, $s, '2026-12-31');

    return [$s, fclAnteprima($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'))];
}

/** I netti per persona del piano generato dopo la vendita registrata. */
function gvpuNettiDopo($test, ?int $persona, ?int $unita): array
{
    $s = gvpuVersato($persona, $unita);
    fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'));
    fclGenera($s);

    return fclNetti($s);
}

function gvpuVersato(?int $persona, ?int $unita): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['ripartizione' => ['proprietario' => 100], 'inquilini' => []]);
    b51aPersone($s, ['Elsa']);
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    foreach ([[$persona, $s['persone']['Ugo']->id], [$unita, null]] as [$importo, $chi]) {
        if ($importo) {
            DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $conto->id,
                'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    return $s;
}
