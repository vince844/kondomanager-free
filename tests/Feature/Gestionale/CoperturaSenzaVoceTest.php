<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Una copertura «sopravvenienza» di una fattura pregressa senza la sua voce: il piano straordinario non sa su quali tabelle chiederla, e la faceva pagare
 * con la tabella della parte che la voce ce l'ha. La registrazione la rifiuta; sui dati già scritti il carrello offre la sola parte con la voce, e la
 * generazione si ferma e dice di darle una voce.
 *
 * Il caso: una pregressa da € 1.000,00, € 500,00 di copertura su «Pulizie» e € 500,00 senza voce; la tabella «Proprietà» 600/400.
 */

use App\Events\Gestionale\FatturaRegistrata;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

function covsvScenario($t): array
{
    Event::fake([FatturaRegistrata::class]);
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Pulizie', 100000, ['Proprietà' => 100]);
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Edile Verdi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $t->actingAs($s->admin);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pulizie' => $s->voci['Pulizie']['conto'], 'fornitore' => $fornitore];
}

function csvDati(array $r, ?int $secondaFonte): array
{
    return [
        'fornitore_id' => $r['fornitore'], 'esercizio_id' => $r['s']->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'EV-2025',
        'data_documento' => '2025-11-20', 'data_scadenza' => '2025-12-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata', 'is_pregresso' => true,
        'imponibile_pregresso' => 1000.00, 'aliquota_iva_pregressa' => 0, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'sopravvenienza', 'importo' => 500.00, 'fonte_id' => $r['pulizie']->id],
            ['tipo_copertura' => 'sopravvenienza', 'importo' => 500.00, 'fonte_id' => $secondaFonte]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ];
}

/** Il dato delle versioni di prima: la pregressa scritta con la copertura senza voce, senza passare dalla richiesta. */
function csvDatoVecchio(array $r): FatturaPassiva
{
    $f = (new FatturaPassivaService())->registraFattura(csvDati($r, $r['pulizie']->id), $r['s']->c->id);
    $seconda = DB::table('fattura_coperture')->where('fattura_passiva_id', $f->id)->where('tipo_copertura', 'sopravvenienza')->orderByDesc('id')->value('id');
    DB::table('fattura_coperture')->where('id', $seconda)->update(['conto_id' => null]);

    return $f;
}

it('(a) la registrazione rifiuta la copertura «sopravvenienza» senza voce', function () {
    $r = covsvScenario($this);
    $this->actingAs($r['s']->admin)->from('/fatture/nuova')->post(route('admin.gestionale.fatture.store', [$r['s']->c]), csvDati($r, null))
        ->assertSessionHasErrors('coperture.1.fonte_id');
    expect(FatturaPassiva::query()->where('numero_documento', 'EV-2025')->exists())->toBeFalse();
});

it('(b) sul dato vecchio il carrello offre € 500,00, la parte con la voce, non € 1.000,00', function () {
    $r = covsvScenario($this);
    $f = csvDatoVecchio($r);
    expect(DB::table('fattura_coperture')->where('fattura_passiva_id', $f->id)->whereNull('conto_id')->where('tipo_copertura', 'sopravvenienza')->count())->toBe(1);
    $carrello = collect($this->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json());
    $riga = $carrello->firstWhere('numero_documento', 'EV-2025');
    expect($riga)->not->toBeNull()
        ->and((int) round((float) $riga['totale_straordinario'] * 100))->toBe(50000);
});

it('(c) sul dato vecchio la generazione del piano si ferma, nomina la fattura e dice di darle una voce', function () {
    $r = covsvScenario($this);
    $f = csvDatoVecchio($r);
    session()->forget(['message', 'errors']);
    $this->actingAs($r['s']->admin)->from('/piani-rate/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Lavori 2025', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 4',
        'fatture_config' => [['id' => $f->id, 'importo' => '500,00']], 'genera_subito' => 1,
    ])->assertRedirect();
    $messaggio = (string) (session('message')['message'] ?? '') . ' ' . implode(' ', collect(session('errors')?->getBag('default')->toArray() ?? [])->flatten()->all());
    expect(PianoRate::query()->where('nome', 'Lavori 2025')->exists())->toBeFalse()
        ->and($messaggio)->toContain('EV-2025')->toContain('senza voce');
});

it('(d) il messaggio dice ciò che si può fare: la pregressa non si modifica, e stornata non si registra di nuovo con lo stesso numero e la stessa data', function () {
    $r = covsvScenario($this);
    $f = csvDatoVecchio($r);
    session()->forget(['message', 'errors']);
    $this->actingAs($r['s']->admin)->from('/piani-rate/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Lavori 2025', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 4',
        'fatture_config' => [['id' => $f->id, 'importo' => '500,00']], 'genera_subito' => 1,
    ])->assertRedirect();
    expect((string) (session('message')['message'] ?? ''))
        ->toContain('Una pregressa non si modifica, e stornata non si registra di nuovo con lo stesso numero e la stessa data dello stesso fornitore: oggi questa fattura non entra in un piano. Crea il piano senza di lei.')
        ->not->toContain('Apri la fattura, dai una voce di spesa');

    // La modifica è chiusa.
    expect((new FatturaPassivaService())->motivoBloccoModifica($f->fresh()))->toContain('Le fatture pregresse non sono modificabili direttamente');
    // Lo storno passa, ma lo stesso documento con la voce non si registra di nuovo: l'indice unico del condominio lo rifiuta.
    session()->forget(['message', 'errors']);
    $this->actingAs($r['s']->admin)->from('/fatture/' . $f->id)->post(route('admin.gestionale.fatture.storno', [$r['s']->c, $f]))->assertSessionHasNoErrors()->assertRedirect();
    expect(session('message')['type'] ?? null, (string) (session('message')['message'] ?? ''))->toBe('success');
    $this->actingAs($r['s']->admin)->from('/fatture/nuova')->post(route('admin.gestionale.fatture.store', [$r['s']->c]),
        csvDati($r, $r['pulizie']->id) + ['competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31'])->assertRedirect()
        ->assertSessionHasErrors('numero_documento');
    expect(FatturaPassiva::query()->where('numero_documento', 'EV-2025')->count())->toBe(1);
});
