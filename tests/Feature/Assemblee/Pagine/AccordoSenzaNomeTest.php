<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\MemoDellAssemblea;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\PropostaDelPunto;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.14 (Coda 749, decisione 673 (4)): il punto dell'accordo di rientro non porta il nome del condòmino. La frase della finestra del memo è quella
 * della bacheca («Autorizzazione di un accordo di rientro di € 1.200,00 in 6 rate, fino al 31/03/2027»), il nome sta solo nella nota dello studio, e l'avviso
 * di convocazione, che stampa il testo del punto a tutti i condòmini, non lo contiene.
 *
 * Che cosa non copre: la finestra a video (`FinestraDelMemo.test.ts`).
 */

beforeEach(function () {
    $this->withoutVite();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function adsAccordoDiGallo($s): int
{
    $id = DB::table('accordi_rientro')->insertGetId(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['gallo']->id,
        'data_accordo' => '2026-10-01', 'fino_al' => '2027-03-31', 'autorizzato_assemblea' => false, 'created_at' => now(), 'updated_at' => now()]);
    foreach (range(1, 6) as $n) {
        DB::table('accordo_rientro_scadenze')->insert(['accordo_rientro_id' => $id, 'numero' => $n, 'data' => '2026-' . (10 + $n - 1 > 12 ? '12' : (string) (10 + $n - 1)) . '-28',
            'importo' => 20000, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

it('la finestra del memo propone il punto dell\'accordo senza il nome, e il nome nella nota', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $id = adsAccordoDiGallo($s);

    $r = $this->actingAs($s->admin)->getJson(route('admin.gestionale.memo.proposta', [$s->c, 'origine_tipo' => 'accordo_rientro', 'origine_id' => $id]))->assertOk();

    expect($r->json('testo'))->toBe('Autorizzazione di un accordo di rientro di € 1.200,00 in 6 rate, fino al 31/03/2027')
        ->and($r->json('testo'))->not->toContain('Gallo')
        ->and($r->json('nota'))->toBe('Accordo con Gallo Enzo del 01/10/2026.');
});

it('la frase per la bacheca e quella della finestra sono la stessa, senza scadenze l\'accordo è detto senza cifre', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $id = adsAccordoDiGallo($s);
    $p = PropostaDelPunto::per($s->c, 'accordo_rientro', $id);
    expect($p['testo'])->toBe($p['testo_pubblico']);

    DB::table('accordo_rientro_scadenze')->where('accordo_rientro_id', $id)->delete();
    expect(PropostaDelPunto::per($s->c, 'accordo_rientro', $id)['testo'])->toBe('Autorizzazione di un accordo di rientro');
});

it('dal memo al punto all\'avviso: il nome del condòmino non arriva a nessun condòmino', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $id = adsAccordoDiGallo($s);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $testo = $this->actingAs($s->admin)->getJson(route('admin.gestionale.memo.proposta', [$s->c, 'origine_tipo' => 'accordo_rientro', 'origine_id' => $id]))->json('testo');
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => $testo, 'origine_tipo' => 'accordo_rientro', 'origine_id' => $id], $s->admin);
    expect($memo)->toBeInstanceOf(MemoDellAssemblea::class);
    $punto = MemoriaDelCondominio::nellOrdine($memo->refresh(), $a, $s->admin);
    expect($punto->testo)->not->toContain('Gallo');

    $a = $a->refresh();
    foreach (app(ConvocatiDellAssemblea::class)->alGiorno($a, '2026-12-15') as $voce) {
        $avviso = EmissioneDelleConvocazioni::avviso($a, $voce, 'raccomandata');
        expect(json_encode($avviso, JSON_UNESCAPED_UNICODE))->not->toContain('Autorizzazione dell\'accordo')
            ->and(json_encode(array_column($avviso['punti'] ?? [], 'testo'), JSON_UNESCAPED_UNICODE))->not->toContain('Gallo Enzo');
    }
});
