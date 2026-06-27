<?php

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\SaldoEsercizioService;
use App\Services\PianoRateCreatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Mockery\MockInterface;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
    
    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto'
    ]);
});

test('piano rate straordinario crea subito eventi inbox se genera_subito è true', function () {
    $gestione = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id, 
        'saldo_applicato' => 0,
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31'
    ]);
    $gestione->esercizi()->attach($this->esercizio->id);
    
    DB::table('piani_conti')->insert([
        'gestione_id' => $gestione->id,
        'condominio_id' => $this->condominio->id,
        'nome' => 'Piano Conti Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore Test',
        'soggetto_ritenuta' => false,
        'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'esercizio_id' => $this->esercizio->id,
        'fornitore_id' => $fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => '123',
        'data_documento' => now(),
        'data_scadenza' => now(),
        'importo_imponibile' => 100000,
        'importo_iva' => 0,
        'netto_a_pagare' => 100000,
        'totale_documento' => 100000,
        'stato_approvazione' => 'da_approvare',
        'stato_pagamento' => 'aperta',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Dati per la request
    $requestData = [
        'gestione_id'          => $gestione->id,
        'nome'                 => 'Piano Straordinario Test',
        'tipo'                 => 'straordinario',
        'tipo_autorizzazione'  => 'delibera',
        'motivazione_autorizzazione' => 'Delibera test',
        'fatture_config'       => [
            ['id' => $fatturaId, 'importo' => '1000,00']
        ],
        'metodo_distribuzione' => 'prima_rata',
        'numero_rate'          => 2,
        'giorno_scadenza'      => 10,
        'capitoli_ids'         => [],
        'genera_subito'        => true, // Questo deve innescare la creazione rate e l'invio evento Inbox
        'recurrence_enabled'   => false,
    ];

    $this->mock(SaldoEsercizioService::class, function (MockInterface $mock) {
        $mock->shouldReceive('calcolaSaldoApplicabile')->andReturn([
            'saldo' => 0, 'applicabile' => false, 'has_movimenti' => false, 'motivo' => 'N/D'
        ]);
    });

    $this->mock(\App\Actions\PianoRate\GeneratePianoRateAction::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')->andReturnUsing(function ($pianoRate) {
            $pianoRate->rate()->create(['numero_rata' => 1, 'data_scadenza' => now()->addDays(10), 'importo_totale' => 10000]);
            $pianoRate->rate()->create(['numero_rata' => 2, 'data_scadenza' => now()->addDays(40), 'importo_totale' => 10000]);
            return [];
        });
    });

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), $requestData);

    $response->assertStatus(302);

    // Recuperiamo il piano rate
    $piano = \App\Models\Gestionale\PianoRate::first();
    expect($piano)->not->toBeNull();
    expect($piano->stato)->toEqual(StatoPianoRate::APPROVATO);
    expect($piano->rate()->count())->toBeGreaterThan(0); // Le rate devono essere state generate

    // VERIFICA INBOX: ci deve essere almeno un evento admin di controllo_incassi e uno di emissione_rata
    $eventiAdminEmissione = Evento::whereJsonContains('meta->type', 'emissione_rata')->count();
    $eventiAdminControllo = Evento::whereJsonContains('meta->type', 'controllo_incassi')->count();
    
    expect($eventiAdminEmissione)->toEqual(2);
    expect($eventiAdminControllo)->toEqual(2);
});

test('rigenerazione di un piano rate approvato aggiorna la inbox eliminando i vecchi task', function () {
    $gestione = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id, 
        'saldo_applicato' => 0,
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31'
    ]);
    $gestione->esercizi()->attach($this->esercizio->id);

    DB::table('piani_conti')->insert([
        'gestione_id' => $gestione->id,
        'condominio_id' => $this->condominio->id,
        'nome' => 'Piano Conti Test 2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore Test',
        'soggetto_ritenuta' => false,
        'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'esercizio_id' => $this->esercizio->id,
        'fornitore_id' => $fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => '123',
        'data_documento' => now(),
        'data_scadenza' => now(),
        'importo_imponibile' => 100000,
        'importo_iva' => 0,
        'netto_a_pagare' => 100000,
        'totale_documento' => 100000,
        'stato_approvazione' => 'da_approvare',
        'stato_pagamento' => 'aperta',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->mock(SaldoEsercizioService::class, function (MockInterface $mock) {
        $mock->shouldReceive('calcolaSaldoApplicabile')->andReturn([
            'saldo' => 0, 'applicabile' => false, 'has_movimenti' => false, 'motivo' => 'N/D'
        ]);
    });

    $this->mock(\App\Actions\PianoRate\GeneratePianoRateAction::class, function (MockInterface $mock) {
        $mock->shouldReceive('execute')->andReturnUsing(function ($pianoRate) {
            $pianoRate->rate()->create(['numero_rata' => 1, 'data_scadenza' => now()->addDays(10), 'importo_totale' => 10000]);
            return [];
        });
    });

    // 1. Creiamo un piano straordinario (crea eventi)
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), [
            'gestione_id'          => $gestione->id,
            'nome'                 => 'Piano da ricalcolare',
            'tipo'                 => 'straordinario',
            'tipo_autorizzazione'  => 'delibera',
            'motivazione_autorizzazione' => 'Delibera test',
            'fatture_config'       => [
                ['id' => $fatturaId, 'importo' => '1000,00']
            ],
            'metodo_distribuzione' => 'prima_rata',
            'numero_rate'          => 1,
            'giorno_scadenza'      => 10,
            'capitoli_ids'         => [],
            'genera_subito'        => true,
            'recurrence_enabled'   => false,
        ]);

    $piano = \App\Models\Gestionale\PianoRate::first();
    $oldRateIds = $piano->rate()->pluck('id')->toArray();
    $oldEventiIds = Evento::whereJsonContains('meta->context->piano_rate_id', $piano->id)->pluck('id')->toArray();
    
    expect(count($oldEventiIds))->toBeGreaterThan(0);

    // 2. Chiamiamo la route di ricalcolo (PianoRateGenerationController)
    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$this->condominio, $this->esercizio, $piano]), [
            'orphan_ids' => []
        ]);

    $response->assertStatus(302);
    $response->assertSessionHas('message.type', 'success');

    // 3. Verifichiamo che i vecchi eventi siano stati eliminati e ne siano stati creati di nuovi
    $newEventiIds = Evento::whereJsonContains('meta->context->piano_rate_id', $piano->id)->pluck('id')->toArray();
    
    expect(count($newEventiIds))->toBeGreaterThan(0);
    // Gli ID degli eventi non devono essere uguali, perché sono stati droppati e ricreati
    expect(array_intersect($oldEventiIds, $newEventiIds))->toBeEmpty();
});
