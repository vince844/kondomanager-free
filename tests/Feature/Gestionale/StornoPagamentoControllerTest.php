<?php

use App\Enums\StatoPagamentoFattura;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use App\Models\Gestionale\PagamentoFornitore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

test('storno cross-esercizio (B1): usa esercizio aperto per stornare pagamento di esercizio chiuso', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiHttp();
    
    // Registra e paga la fattura nell'esercizio 1
    $fattura = registraFatturaServiceTest([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);
    
    $pagamento = (new \App\Services\Gestionale\PagamentoFornitoreService())->registraPagamento(
        datiPagamento([$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo], $fattura, ['importo' => 122000]),
        $condominio->id
    );

    // Chiudi l'esercizio 1
    $esercizio->update(['stato' => 'chiuso']);

    // Crea l'esercizio 2 aperto (nome esplicito: il primo esercizio creato da
    // setupPagamentiHttp() è "Esercizio 2026" e l'indice è unico su condominio_id + nome)
    $esercizio2 = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2027',
        'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31',
        'stato' => 'aperto'
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.storno', [$condominio, $pagamento]), [
            'esercizio_id' => $esercizio2->id,
            'data_storno'  => now()->toDateString(),
            'motivo'  => 'Storno di test per errore contabile',
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    // Verify storno
    $pagamento->refresh();
    expect($pagamento->stato)->toBe(\App\Enums\StatoPagamentoFornitore::STORNATO);
    expect($pagamento->motivo_storno)->not->toBeNull();

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toBe(StatoPagamentoFattura::APERTA);

    // Verify scrittura was appended in esercizio 2
    $scritturaOriginale = $pagamento->scrittura;
    $scritturaStorno = $pagamento->storno->scrittura;
    
    expect($scritturaOriginale->esercizio_id)->toBe($esercizio->id);
    expect($scritturaStorno->esercizio_id)->toBe($esercizio2->id);
    // Note is not updated by stornaPagamento
});
