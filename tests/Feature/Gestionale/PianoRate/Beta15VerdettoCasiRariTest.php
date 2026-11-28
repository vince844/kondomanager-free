<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il verdetto «allineato / quota aperta / disallineato» (`PianoRateQuoteService::statoAllineamento`) nei casi rari che `Beta59PianoQuoteNonAssegnateCasiTest`
 * lasciava scoperti. In ognuno l'amministratore accetta le quote non assegnate alla generazione, e niente cambia dopo: il piano deve essere in «quota
 * aperta», con lo scarto uguale a quello registrato.
 *
 * Le cifre, scritte prima:
 * (1) piano straordinario da una fattura di € 600,00 su «Proprietà» A 600, B 400 e l'unità C 100 senza proprietario (su 1.100): la parte di C è
 *     € 600,00 × 100 / 1.100 = € 54,55 (o € 54,54 con l'arrotondamento dell'ultima quota), che nessuno paga.
 * (3) «Riscaldamento» € 1.200,00 su A 500, C 470, Cantina 1 30 senza proprietario, con € 30,00 di già versato sulla Cantina: la parte della Cantina è
 *     € 36,00, il motore ne toglie € 30,00 e ne restano € 6,00 non assegnati. L'atteso è € 1.200,00 − € 30,00 = € 1.170,00, il generato € 1.164,00.
 *     (Il progetto proponeva € 300,00 di già versato: più della parte della Cantina, la guardia del già versato ferma la generazione.)
 * (4) capitolo «Impianti» con «Caldaia» € 1.000,00 e «Bruciatore» € 500,00, tutti e due sulla tabella del riscaldamento; nel piano la sola «Caldaia»:
 *     la Cantina ha € 30,00 non assegnati.
 * (5) due unità senza proprietario, la Cantina 1 (30) e la Cantina 2 (10, con la C a 460), e «Riscaldamento» € 1.000,03: le due quote € 30,00 e € 10,00
 *     con i loro arrotondamenti.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Beta59PianoScenari.php';
require_once __DIR__.'/../Beta15VoceAZeroScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

function b15vcVerdetto(PianoRate $piano): array
{
    $svc = app(PianoRateQuoteService::class);
    $piano = PianoRate::find($piano->id);

    return ['stato' => $svc->statoAllineamento($piano), 'scarto' => $svc->scartoCents($piano), 'registrato' => $svc->scartoRegistratoCents($piano)];
}

it('(1) il piano straordinario con l\'unità senza proprietario, accettata alla generazione: quota aperta', function () {
    $r = b15vzTetto($this);
    $s = $r['s'];
    $c = Immobile::create(['condominio_id' => $s->c->id, 'tipo' => 'appartamento', 'codice_immobile' => 'B15VC-C-' . $s->c->id, 'nome' => 'Interno 3', 'interno' => '3']);
    DB::table('quote_tabella')->insert(['tabella_id' => $s->tabelle['Proprietà']->id, 'immobile_id' => $c->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    $f = b15vzFattura($this, $r, [['Manutenzione tetto', 60000, null]]);

    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $f->id, 'importo' => '600,00']], 'genera_subito' => 1, 'accetta_scoperti' => true, 'nota_scoperti' => 'Interno 3 senza proprietario al rogito',
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Tetto a rate')->sole();

    $v = b15vcVerdetto($piano);
    expect($v['stato'])->toBe(PianoRateQuoteService::QUOTA_APERTA)
        ->and($v['scarto'])->toBeIn([5454, 5455])
        ->and($v['registrato'])->toBe($v['scarto']);
});

// Era rosso: la quota registrata della Cantina è lorda (€ 36,00) e lo scarto netto (€ 6,00), e il piano risultava disallineato.
it('(3) il già versato sull\'unità senza proprietario: € 6,00 non assegnati, quota aperta', function () {
    $s = b59pScenarioV34($this);
    DB::table('conti')->where('id', $s['riscaldamento']->id)->update(['importo' => 120000]);
    b52tGiaVersato($this, $s, $s['riscaldamento'], 'Cantina', 3000);
    $piano = b59pPianoV34($this, $s, extra: ['capitoli_config' => [['id' => $s['riscaldamento']->id, 'importo' => '1.200,00', 'note' => '']]]);

    expect(b59pMisura($piano))->toMatchArray(['atteso' => 117000, 'generato' => 116400])
        ->and(b15vcVerdetto($piano))->toBe(['stato' => PianoRateQuoteService::QUOTA_APERTA, 'scarto' => 600, 'registrato' => 600]);
});

it('(4) il capitolo con due sottoconti e uno solo nel piano: € 30,00 non assegnati sulla Caldaia, quota aperta', function () {
    $s = b59pScenarioV34($this);
    $impianti = b52tVoce($this, $s, 'Impianti', 'spesa', '0,00', $s['risc'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null,
        'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $caldaia = b52tVoce($this, $s, 'Caldaia', 'spesa', '1.000,00', $s['risc'], $impianti->id, ['isSottoConto' => true]);
    b52tVoce($this, $s, 'Bruciatore', 'spesa', '500,00', $s['risc'], $impianti->id, ['isSottoConto' => true]);
    $piano = b59pPianoV34($this, $s, extra: ['capitoli_ids' => [$caldaia->id], 'capitoli_config' => [['id' => $caldaia->id, 'importo' => '1.000,00', 'note' => '']]]);

    expect(b59pMisura($piano))->toMatchArray(['atteso' => 100000, 'generato' => 97000])
        ->and(b15vcVerdetto($piano))->toBe(['stato' => PianoRateQuoteService::QUOTA_APERTA, 'scarto' => 3000, 'registrato' => 3000]);
});

it('(5) due unità senza proprietario con gli arrotondamenti: € 40,00 non assegnati, quota aperta', function () {
    $s = b59pScenarioV34($this);
    $cantina2 = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'cantina', 'codice_immobile' => "B15VC-{$s['seq']}-CANT2", 'nome' => 'Cantina 2', 'interno' => 'C2']);
    DB::table('quote_tabella')->insert(['tabella_id' => $s['risc']->id, 'immobile_id' => $cantina2->id, 'valore' => 10.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->where('tabella_id', $s['risc']->id)->where('immobile_id', $s['unita']['C']->id)->update(['valore' => 460.0]);
    $piano = b59pPianoV34($this, $s, nota: 'Cantine senza proprietario al rogito', extra: ['capitoli_config' => [['id' => $s['riscaldamento']->id, 'importo' => '1.000,03', 'note' => '']]]);

    $v = b15vcVerdetto($piano);
    expect($v['stato'])->toBe(PianoRateQuoteService::QUOTA_APERTA)
        ->and($v['scarto'])->toBe($v['registrato'])
        ->and(abs($v['scarto'] - 4000))->toBeLessThanOrEqual(1);
});
