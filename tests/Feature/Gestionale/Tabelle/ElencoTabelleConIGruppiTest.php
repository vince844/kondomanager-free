<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Condominio;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * L'elenco delle tabelle con il badge «Di un gruppo» (Coda 734, tappa 3 della 1.12.0-beta.14). Trovato nella prova a video della Fase 1-bis: l'elenco
 * rispondeva 500 su ogni condominio con almeno una tabella, perché leggeva i gruppi dalla pagina dopo che `TabellaResource::collection()` ne aveva
 * sostituito le righe con le risorse. Nessuna prova chiamava l'elenco con una tabella dentro.
 *
 * Cosa resta scoperto: l'ordine e i filtri dell'elenco (le loro prove sono altrove); qui solo che l'elenco si apre e dice quali tabelle sono di un gruppo.
 */
uses(RefreshDatabase::class);

function condominioPerLElencoDelleTabelle(): Condominio
{
    $condominio = Condominio::factory()->create();
    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione Ordinaria', 'tipo' => 'ordinaria',
        'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('piani_conti')->insert([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestioneId,
        'nome' => 'Piano dei conti 2026', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $condominio;
}

function tabellaDellElenco(Condominio $condominio, string $nome, bool $diUnGruppo): Tabella
{
    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => $nome, 'tipo' => 'standard', 'quota' => 'millesimi',
        'numero_decimali' => 2, 'attiva' => true, 'data_inizio' => now(),
    ]);
    $tabella->forceFill(['di_un_gruppo' => $diUnGruppo])->save();

    return $tabella;
}

it('l\'elenco delle tabelle si apre e dice quali sono di un gruppo', function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $utente = User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore');
    $condominio = condominioPerLElencoDelleTabelle();
    tabellaDellElenco($condominio, 'Generale', false);
    $scalaB = tabellaDellElenco($condominio, 'Scala B', true);

    $risposta = $this->actingAs($utente)->get(route('admin.gestionale.tabelle.index', ['condominio' => $condominio]));

    $risposta->assertOk();
    $props = $risposta->viewData('page')['props'];
    expect(collect($props['tabelle'])->pluck('nome')->sort()->values()->all())->toBe(['Generale', 'Scala B'])
        ->and($props['gruppi'])->toBe([(int) $scalaB->id]);
});
