<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Code 190 e 595: la ricerca nell'elenco delle fatture passive resta nel condominio aperto e nei suoi filtri.
 *
 * In `FatturaPassivaController::index` la ricerca era `->where('numero_documento', …)->orWhereHas('fornitore', …)` senza
 * parentesi: l'«oppure» del fornitore saliva al primo livello della query e scavalcava il condominio e i filtri di stato. I
 * fornitori non hanno un condominio, quindi cercando il nome di un fornitore l'elenco di un condominio mostrava le fatture di
 * quel fornitore in tutti i condomini (c'è anche nella 1.11.0). Un test per campo di ricerca: il fornitore (dove il difetto
 * stava), il numero del documento (che non usciva, e non deve cominciare), e la ricerca insieme a un filtro di stato.
 *
 * Non copre le altre pagine con una ricerca: la stessa famiglia (un `orWhere` fuori parentesi dopo il filtro del
 * condominio) è stata cercata nei controller, nei servizi e nelle azioni il 17/11/2026, senza altri casi.
 */

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

/** Una fattura passiva registrata con il servizio, sulla voce di spesa del suo condominio. */
function rfcFattura(array $ctx): \App\Models\Gestionale\FatturaPassiva
{
    [, , , , $capitolo] = $ctx;

    return registraFatturaServiceTest($ctx, ['righe' => [[
        'descrizione' => 'Manutenzione ascensore',
        'importo_imponibile' => 1000,
        'aliquota_iva' => 22,
        'conto_id' => $capitolo->id,
        'is_sopravvenienza' => false,
    ]]]);
}

/**
 * Due condomini con lo stesso fornitore: una fattura nel primo («FT-A-1») e una nel secondo («FT-B-1»), tutte e due del
 * fornitore del primo condominio, con un nome che si cerca.
 *
 * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
 */
function rfcDueCondomini(): array
{
    $ctxA = setupContabile();
    [$condominioA, , , $fornitore] = $ctxA;
    $fatturaA = rfcFattura($ctxA);

    $ctxB = setupContabile();
    $fatturaB = rfcFattura($ctxB);

    DB::table('fornitori')->where('id', $fornitore->id)->update(['ragione_sociale' => 'Ascensori Zeta S.r.l.']);
    DB::table('fatture_passive')->where('id', $fatturaA->id)->update(['numero_documento' => 'FT-A-1']);
    DB::table('fatture_passive')->where('id', $fatturaB->id)->update(['numero_documento' => 'FT-B-1', 'fornitore_id' => $fornitore->id]);

    return [$condominioA, $fatturaA, $fatturaB, $ctxA];
}

test('cercando il nome del fornitore, l\'elenco di un condominio non mostra le fatture dello stesso fornitore in un altro condominio', function () {
    [$condominioA, $fatturaA] = rfcDueCondomini();

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.index', [$condominioA]).'?search='.urlencode('Ascensori Zeta'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('fatture.data', 1)
            ->where('fatture.data.0.id', $fatturaA->id)
        );
});

test('cercando il numero del documento, l\'elenco resta nel condominio', function () {
    [$condominioA, $fatturaA] = rfcDueCondomini();

    // «FT-» c'è nei numeri delle due fatture: si vede solo quella del condominio aperto.
    $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.index', [$condominioA]).'?search=FT-')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('fatture.data', 1)
            ->where('fatture.data.0.id', $fatturaA->id)
        );
});

test('la ricerca del fornitore insieme a un filtro di stato tiene il filtro', function () {
    [$condominioA, $fatturaA, , $ctxA] = rfcDueCondomini();
    $pagata = rfcFattura($ctxA);
    DB::table('fatture_passive')->where('id', $pagata->id)->update(['stato_pagamento' => 'pagata', 'numero_documento' => 'FT-A-2']);

    // La prima fattura non è pagata, e con l'«oppure» fuori parentesi rientrava dal nome del fornitore.
    $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.index', [$condominioA]).'?stato_pagamento=pagata&search='.urlencode('Ascensori Zeta'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('fatture.data', 1)
            ->where('fatture.data.0.id', $pagata->id)
        );
});
