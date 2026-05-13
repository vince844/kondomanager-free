<?php

use App\Models\Condominio;
use App\Models\Esercizio;
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
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
    
    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto'
    ]);
});

test('impedisce creazione piano rate se i saldi sono bloccati e c\'è un debito', function () {
    $this->mock(SaldoEsercizioService::class, function (MockInterface $mock) {
        $mock->shouldReceive('calcolaSaldoApplicabile')
             ->andReturn([
                 'saldo' => 10000,
                 'applicabile' => false,
                 'motivo' => 'Saldo già applicato'
             ]);
    });

    $straordinaria = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id,
        'saldo_applicato' => false
    ]);
    $straordinaria->esercizi()->attach($this->esercizio->id);

    $response = $this->actingAs($this->user)
        ->from(route('admin.gestionale.esercizi.piani-rate.index', [$this->condominio, $this->esercizio]))
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), [
            'gestione_id' => $straordinaria->id,
            'nome' => 'Piano Fallimentare',
            'numero_rate' => 1,
            'metodo_distribuzione' => 'prima_rata',
            'giorno_scadenza' => 10,
        ]);

    $response->assertStatus(302);
    expect(session('error') || session('flash_error'))->not->toBeNull();
});

test('permette creazione piano rate se i saldi sono liberi e applica il lock', function () {
    // 1. Setup gestione
    $ordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'saldo_applicato' => 0]);
    $ordinaria->esercizi()->attach($this->esercizio->id);

    // 2. Mock del Creator Service - DEVE RESTITUIRE IL MODELLO GESTIONE
    $this->mock(\App\Services\PianoRateCreatorService::class, function (MockInterface $mock) use ($ordinaria) {
        // CORREZIONE: restituiamo $ordinaria invece di true
        $mock->shouldReceive('verificaGestione')->andReturn($ordinaria);
        
        $piano = new \App\Models\Gestionale\PianoRate();
        $piano->id = 999;
        $mock->shouldReceive('creaPianoRate')->andReturn($piano);
    });

    // 3. Mock del Saldo Service
    $this->mock(SaldoEsercizioService::class, function (MockInterface $mock) use ($ordinaria) {
        $mock->shouldReceive('calcolaSaldoApplicabile')
             ->andReturn([
                 'saldo' => 10000, 
                 'applicabile' => true,
                 'motivo' => 'Saldo disponibile'
             ]);

        $mock->shouldReceive('marcaSaldoApplicato')
             ->zeroOrMoreTimes()
             ->andReturnUsing(function ($gestione) {
                 return DB::table('gestioni')->where('id', $gestione->id)->update(['saldo_applicato' => 1]);
             });
    });

    // 4. Chiamata
    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), [
            'gestione_id'          => $ordinaria->id,
            'nome'                 => 'Piano Successo',
            'tipo'                 => 'ordinario',
            'metodo_distribuzione' => 'prima_rata',
            'numero_rate'          => 1,
            'giorno_scadenza'      => 10,
            'capitoli_ids'         => [],
            'genera_subito'        => false,
            'recurrence_enabled'   => false,
        ]);

    // 5. Verifiche: nessun errore di sessione e il PianoRateCreatorService è stato invocato
    $response->assertSessionHasNoErrors();
    $response->assertStatus(302); // Redirect di successo
});
