<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 166 (1.12.0-beta.14): l'importo di una fattura messa a rate con «Spese fuori preventivo» ha un tetto, e non è zero.
 *
 * Fino alla 1.12.0-beta.13 `CreatePianoRateRequest` chiedeva per ogni fattura un importo qualunque (`required_with`): una richiesta
 * costruita a mano metteva a rate € 5.000,00 di una fattura da € 3.000,00. E l'importo zero voleva dire due cose: «tutto» per il
 * motore (`CalcoloQuoteService`, il ripiego su `importo_collegato` = 0) e «niente» per il carrello, che sommando gli importi degli
 * altri piani la considerava ancora tutta da finanziare: la stessa fattura si poteva chiedere due volte.
 *
 * Ora, per ogni fattura senza note collegate, l'importo è più di zero e al massimo quanto ne resta da mettere a rate, con il numero
 * del carrello (`CarrelloDelloStraordinario::righe()`, lo stesso che la pagina propone). Le fatture rettificate da una nota collegata
 * hanno già il loro tetto sul netto dalla 1.11.0-beta.36 (Coda 165, `guardiaFattureRettificate`), e per loro lo zero vuol dire
 * ancora «il netto» (rilievo R2 di quella beta): non cambiano.
 *
 * **Cosa NON copre.** Le righe di prima con l'importo zero nei piani già salvati: il motore le legge ancora come «tutto».
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Models\User;
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

/** Il condominio di `ncBase` (NotaCreditoCollegataTest): un'unità con il suo proprietario, la tabella legata al capitolo. */
function t166Base(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();
    DB::table('gestioni')->where('id', $gestione->id)->update(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Tabella Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01']);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $fattura = FatturaPassiva::create([
        'condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-166', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
        'importo_imponibile' => 300000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 300000, 'netto_a_pagare' => 300000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Rifacimento cornicione', 'aliquota_iva' => 0,
        'importo_imponibile' => 300000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('condominio', 'esercizio', 'gestione', 'fattura');
}

/** Il corpo della scheda «Spese fuori preventivo» con la fattura e l'importo scritto nel carrello. */
function t166Corpo(array $b, string $importo, string $nome): array
{
    return [
        'gestione_id' => $b['gestione']->id, 'nome' => $nome, 'tipo' => 'straordinario',
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-03-01',
        'fatture_config' => [['id' => $b['fattura']->id, 'importo' => $importo]], 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 10,
        'capitoli_ids' => [], 'genera_subito' => false,
    ];
}

function t166Crea($test, array $b, string $importo, string $nome = 'Cornicione')
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$b['condominio']->id, $b['esercizio']->id]), t166Corpo($b, $importo, $nome));
}

it('Coda 166 — una fattura da € 3.000,00 senza note: più del documento si rifiuta, sul campo della fattura e con la cifra che resta; zero si rifiuta; € 3.000,00 passa', function () {
    $b = t166Base();

    $oltre = t166Crea($this, $b, '5000,00', 'Oltre');
    expect($oltre->status())->toBe(422)
        ->and($oltre->json('errors')['fatture_config.0.importo'][0] ?? '')->toContain('FT-166')->toContain('€ 3.000,00')
        ->and(PianoRate::where('nome', 'Oltre')->exists())->toBeFalse();

    $zero = t166Crea($this, $b, '0,00', 'Zero');
    expect($zero->status())->toBe(422)
        ->and($zero->json('errors')['fatture_config.0.importo'][0] ?? '')->toContain('FT-166')
        ->and(PianoRate::where('nome', 'Zero')->exists())->toBeFalse();

    t166Crea($this, $b, '3000,00', 'Tutta')->assertSessionHasNoErrors();
    expect(DB::table('piano_rate_fatture')->where('fattura_passiva_id', $b['fattura']->id)->value('importo_collegato'))->toBe(300000);
});

it('Coda 166 — con un altro piano che ne chiede già € 1.000,00 restano € 2.000,00: € 2.500,00 si rifiuta, € 2.000,00 passa', function () {
    $b = t166Base();
    $altro = PianoRate::create(['gestione_id' => $b['gestione']->id, 'condominio_id' => $b['condominio']->id, 'nome' => 'Primo lotto', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $altro->fatture()->attach($b['fattura']->id, ['importo_collegato' => 100000]);

    $troppo = t166Crea($this, $b, '2500,00', 'Secondo lotto troppo');
    expect($troppo->status())->toBe(422)
        ->and($troppo->json('errors')['fatture_config.0.importo'][0] ?? '')->toContain('€ 1.000,00')->toContain('€ 2.000,00')
        ->and(PianoRate::where('nome', 'Secondo lotto troppo')->exists())->toBeFalse();

    t166Crea($this, $b, '2000,00', 'Secondo lotto')->assertSessionHasNoErrors();
    expect(PianoRate::where('nome', 'Secondo lotto')->exists())->toBeTrue();
});

it('Coda 166, la catena — il carrello propone ciò che resta, e la creazione accetta proprio quel numero: dopo il primo lotto il carrello dice € 2.000,00, e con il resto la fattura non si offre più', function () {
    $b = t166Base();
    $carrello = fn () => collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', $b['condominio']->id)
        . '?esercizio_id=' . $b['esercizio']->id . '&gestione_id=' . $b['gestione']->id)->assertOk()->json())->keyBy('id');

    t166Crea($this, $b, '1000,00', 'Primo lotto')->assertSessionHasNoErrors();
    expect((float) $carrello()[$b['fattura']->id]['residuo_da_finanziare'])->toEqual(2000.0);

    t166Crea($this, $b, '2000,00', 'Secondo lotto')->assertSessionHasNoErrors();
    expect($carrello()->has($b['fattura']->id))->toBeFalse()
        ->and(t166Crea($this, $b, '0,01', 'Terzo lotto')->status())->toBe(422);
});
