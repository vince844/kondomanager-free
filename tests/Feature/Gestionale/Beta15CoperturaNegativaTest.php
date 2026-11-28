<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 141 — la copertura «Fuori preventivo» di una fattura pregressa ha sempre il segno positivo: una negativa nasce solo su una nota di credito,
 * e una nota non entra in un piano (il carrello offre solo fatture, la richiesta rifiuta il resto). Il motore la prendeva con il valore assoluto,
 * cioè la chiedeva ai condòmini come se fosse una spesa; ora si ferma e dice quale fattura.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il condominio con un'unità, il suo proprietario e la tabella «Generale»; una fattura pregressa di € 1.000,00 fuori preventivo, e il piano straordinario che la finanzia. */
function b15cvScenario(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, , , $immobileId] = setupContabile();
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $persona = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);

    $fattura = (new FatturaPassivaService())->registraFattura(datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'numero_documento' => 'FT-141', 'is_pregresso' => true, 'imponibile_pregresso' => 1000.00, 'aliquota_iva_pregressa' => 0, 'coperture' => [],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'origine_decisionale' => 'gestione_corrente', 'motivazione_sforo' => 'Debito pregresso del fornitore', 'tipo_ripartizione' => 'millesimale',
            'nome_voce' => 'Debito pregresso', 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100,
        ]],
    ]), $condominio->id);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Debito pregresso', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 0]);

    return compact('condominio', 'esercizio', 'gestione', 'fattura', 'piano');
}

it('Coda 141 — una nota di credito pregressa nel carrello di un piano si rifiuta sul suo campo, con il suo numero, e il piano non nasce', function () {
    $s = b15cvScenario();
    DB::table('fatture_passive')->where('id', $s['fattura']->id)->update(['tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-141']);
    $prima = PianoRate::count();

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s['condominio'], $s['esercizio']]), [
        'tipo' => 'straordinario', 'gestione_id' => $s['gestione']->id, 'nome' => 'Con la nota', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 5,
        'genera_subito' => false, 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Infiltrazione dal tetto',
        'fatture_config' => [['id' => $s['fattura']->id, 'importo' => '1.000,00']],
    ]);

    expect($r->status())->toBe(422)
        ->and($r->json('errors')['fatture_config.0.id'][0] ?? '')->toContain('Il documento n. NC-141 è una nota di credito')
        ->and(PianoRate::count())->toBe($prima);
});

it('Coda 141 — una copertura negativa arrivata nel piano per un\'altra strada ferma il calcolo e dice la fattura; prima si chiedeva € 1.000,00 come una spesa', function () {
    $s = b15cvScenario();
    $copertura = DB::table('fattura_coperture')->where('fattura_passiva_id', $s['fattura']->id)->where('tipo_copertura', 'sopravvenienza')->first();
    expect((int) $copertura->importo)->toBe(100000);
    DB::table('fattura_coperture')->where('id', $copertura->id)->update(['importo' => -100000]);

    expect(fn () => app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($s['piano']->fresh()))
        ->toThrow(\RuntimeException::class, 'la fattura pregressa n. FT-141 ha una copertura «Fuori preventivo» negativa (€ 1.000,00)');
});

it('Coda 141, controprova — la copertura positiva si ripartisce come prima: € 1.000,00 al proprietario', function () {
    $s = b15cvScenario();

    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($s['piano']->fresh());

    expect(array_sum(array_map('array_sum', $totali)))->toBe(100000);
});
