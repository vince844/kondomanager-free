<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (2), il giro di correzioni della tappa 6: il bonifico parlante di una fattura con la ritenuta che ha già un pagamento.
 *
 * Fattura n. 12 di Edilnord, € 1.220,00 con € 40,00 di ritenuta, netto € 1.180,00. Un acconto di € 500,00 con il bonifico ordinario.
 *  - La frase del rifiuto non manda a una «Modifica» che si rifiuta: dice di stornare il pagamento di € 500,00, poi togliere la ritenuta,
 *    poi registrare i € 1.220,00 della fattura intera.
 *  - Un pagamento scritto prima con la spunta, su una fattura con la ritenuta, non si alza di importo (come la regola dei contanti,
 *    Coda 466); può restare com'è e scendere.
 *
 * Tutto dalle rotte: `fatture.store`, `pagamenti-fornitori.store` e `.update`.
 */

use App\Enums\MetodoPagamento;
use App\Enums\Role;
use App\Enums\TipoAllocazioneFattura;
use App\Models\CategoriaEvento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    $this->admin = $this->admin->fresh();
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'x']);
    $this->travelTo(Carbon::parse('2026-03-20 10:00', 'Europe/Rome')->utc());
});

function b14dpCtx(): array
{
    $ctx = setupPagamentiHttp();
    $ctx[3]->update(['ragione_sociale' => 'Edilnord', 'soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4', 'natura_percipiente' => 'persona_fisica_irpef']);
    $ctx[3]->refresh();

    return $ctx;
}

function b14dpFattura($t, array $ctx): FatturaPassiva
{
    [, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;
    $t->actingAs($t->admin)->post(route('admin.gestionale.fatture.store', ['condominio' => $ctx[0]->id]), [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id, 'tipo_documento' => 'fattura',
        'numero_documento' => '12', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata', 'applica_ritenuta' => true,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Facciata', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]],
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::where('numero_documento', '12')->firstOrFail();
}

function b14dpPaga($t, array $ctx, FatturaPassiva $f, int $cents, bool $parlante)
{
    [, $esercizio, , $fornitore, $conto] = $ctx;

    return $t->actingAs($t->admin)->from(route('admin.gestionale.pagamenti-fornitori.create', [$ctx[0]]))
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$ctx[0]]), [
            'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'conto_corrente_id' => $conto, 'data_pagamento' => '2026-03-20',
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value, 'iban_beneficiario' => 'IT60X0542811101000000123456', 'iban_confermato_manualmente' => true,
            'conferma_duplicato_verificato' => true, 'allow_overdraft' => true, 'bonifico_parlante' => $parlante,
            'tipo_detrazione' => $parlante ? 'ristrutturazione' : null,
            'allocazioni' => [['fattura_id' => $f->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => $cents]],
        ]);
}

it('dopo un acconto la frase dice di stornare il pagamento, togliere la ritenuta e registrare i € 1.220,00 della fattura intera', function () {
    $ctx = b14dpCtx();
    $f = b14dpFattura($this, $ctx);
    b14dpPaga($this, $ctx, $f, 50000, false)->assertSessionHasNoErrors();

    b14dpPaga($this, $ctx, $f, 68000, true);
    $frase = session('errors')?->first('bonifico_parlante');

    expect($frase)->toBe('La fattura n. 12 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e il '
        .'condominio non la trattiene: la fattura ha già il pagamento di € 500,00 del 20/03/2026; per pagarla con il bonifico parlante storna prima quel '
        .'pagamento, poi togli la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante») e registra il pagamento di € 1.220,00.')
        ->and($frase)->not->toContain('togli prima la ritenuta dalla fattura (Modifica');
    expect(PagamentoFornitore::count())->toBe(1);
});

it('la pagina «Nuovo pagamento» porta la stessa frase per la fattura con l\'acconto', function () {
    $ctx = b14dpCtx();
    $f = b14dpFattura($this, $ctx);
    b14dpPaga($this, $ctx, $f, 50000, false)->assertSessionHasNoErrors();

    $frasi = collect($this->actingAs($this->admin)
        ->getJson(route('admin.gestionale.pagamenti-fornitori.pendenze', [$ctx[0]]).'?fornitore_id='.$ctx[3]->id)
        ->assertOk()->json('pendenze'))->pluck('bonifico_parlante_frase')->filter()->values()->all();

    expect($frasi)->not->toBeEmpty()
        ->and($frasi[0])->toContain('storna prima quel pagamento')->and($frasi[0])->toContain('€ 1.220,00');
});

it('un pagamento di prima con la spunta su una fattura con la ritenuta non si alza di importo, e può restare com\'è', function () {
    $ctx = b14dpCtx();
    $f = b14dpFattura($this, $ctx);
    b14dpPaga($this, $ctx, $f, 50000, false)->assertSessionHasNoErrors();
    $p = PagamentoFornitore::firstOrFail();
    // Come lo scriveva la beta.13: la spunta e la ritenuta a zero.
    DB::table('pagamenti_fornitori')->where('id', $p->id)->update(['bonifico_parlante' => true, 'tipo_detrazione' => 'ristrutturazione',
        'importo_ritenuta' => 0, 'importo_lordo' => 50000]);

    $corpo = fn (int $netto) => [
        'conto_corrente_id' => $ctx[4], 'data_pagamento' => '2026-03-20', 'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $netto, 'importo_ritenuta_cents' => 0, 'importo_netto_cents' => $netto, 'importo_commissioni_cents' => 0,
        'bonifico_parlante' => true, 'tipo_detrazione' => 'ristrutturazione', 'allow_overdraft' => true,
    ];
    $vai = fn (int $netto) => $this->actingAs($this->admin)->from(route('admin.gestionale.pagamenti-fornitori.edit', [$ctx[0], $p]))
        ->put(route('admin.gestionale.pagamenti-fornitori.update', [$ctx[0], $p]), $corpo($netto));

    $vai(118000);
    expect(session('errors')?->first('bonifico_parlante'))->toContain('Con il bonifico parlante la ritenuta la opera la banca');
    expect((int) $p->fresh()->importo_netto)->toBe(50000);

    // Com'è (50.000) e più basso (40.000): passano.
    $vai(50000)->assertSessionHasNoErrors();
    $vai(40000)->assertSessionHasNoErrors();
    expect((int) $p->fresh()->importo_netto)->toBe(40000);
});
