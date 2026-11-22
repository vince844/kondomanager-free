<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * «Urgenza» che arriva dalla scheda «Dalle voci del preventivo» di una gestione straordinaria (Coda 688, il difetto trovato prima dell'analisi): il piano si
 * salvava e poi non si generava più, perché chiedeva una data della delibera che con l'urgenza non si scrive. Con la Coda 688 la scelta chiede il motivo e
 * il giorno dei lavori (`CreatePianoRateRequest::URGENZA_DALLE_VOCI`), e il piano nasce approvato con l'urgenza e il motivo: il programma non resta in un vicolo
 * cieco. Sull'ordinaria la scelta non c'è e non si scrive.
 *
 * Che cosa non copre: il calcolo delle rate d'urgenza sulle voci (`UrgenzaSulleVociTest`); la pagina con la scelta sola sulle due schede.
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31']);
});

it('«Urgenza» dalla scheda delle voci di una straordinaria chiede il motivo e il giorno dei lavori: nessun piano che poi non si genera; sull\'ordinaria non si scrive', function () {
    $c = $this->condominio;
    $e = $this->esercizio;
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'tipo' => 'straordinaria', 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31']);
    $g->esercizi()->attach($e->id);
    DB::table('piani_conti')->insert(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'nome' => 'Piano conti', 'created_at' => now(), 'updated_at' => now()]);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => DB::table('piani_conti')->where('gestione_id', $g->id)->value('id'), 'nome' => 'Colonna',
        'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 122000]);
    $corpo = ['gestione_id' => $g->id, 'nome' => 'Colonna a rate', 'tipo' => 'ordinario', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'tipo_autorizzazione' => 'urgenza', 'capitoli_config' => [['id' => $conto->id, 'importo' => '1.220,00', 'note' => '']],
        'capitoli_ids' => [], 'genera_subito' => false, 'recurrence_enabled' => false];

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $e]), $corpo)
        ->assertSessionHasErrors('motivazione_autorizzazione');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $e]),
        $corpo + ['motivazione_autorizzazione' => 'Perdita dalla colonna, riparata subito'])
        ->assertSessionHasErrors('giorno_dei_lavori')->assertSessionDoesntHaveErrors('tipo_autorizzazione');

    expect(PianoRate::query()->count())->toBe(0);

    // Con il giorno dei lavori il piano nasce approvato, con l'urgenza e il motivo: il motivo non sparisce più (D-0).
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $e]),
        $corpo + ['motivazione_autorizzazione' => 'Perdita dalla colonna, riparata subito', 'giorno_dei_lavori' => '2026-03-02'])->assertSessionHasNoErrors();
    expect(PianoRate::query()->sole())->tipo_autorizzazione->toBe('urgenza')->motivazione_autorizzazione->toBe('Perdita dalla colonna, riparata subito')
        ->and(PianoRate::query()->sole()->stato)->toBe(StatoPianoRate::APPROVATO);
    PianoRate::query()->delete();

    // Su una gestione ordinaria l'urgenza dalla scheda delle voci non c'è: non si scrive.
    $o = Gestione::factory()->create(['condominio_id' => $c->id, 'tipo' => 'ordinaria', 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31']);
    $o->esercizi()->attach($e->id);
    DB::table('piani_conti')->insert(['gestione_id' => $o->id, 'condominio_id' => $c->id, 'nome' => 'Piano conti', 'created_at' => now(), 'updated_at' => now()]);
    $pulizie = \App\Models\Gestionale\Conto::create(['piano_conto_id' => DB::table('piani_conti')->where('gestione_id', $o->id)->value('id'), 'nome' => 'Pulizie',
        'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 122000]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $e]), [
        'gestione_id' => $o->id, 'nome' => 'Pulizie a rate', 'tipo' => 'ordinario', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Non vale qui',
        'capitoli_config' => [['id' => $pulizie->id, 'importo' => '1.220,00', 'note' => '']], 'capitoli_ids' => [], 'genera_subito' => false,
        'recurrence_enabled' => false,
    ])->assertSessionHasNoErrors();
    expect(PianoRate::query()->where('nome', 'Pulizie a rate')->sole()->tipo_autorizzazione)->toBeNull();
});
