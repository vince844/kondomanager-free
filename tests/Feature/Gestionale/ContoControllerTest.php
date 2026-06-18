<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

test('Bug 2: modifica di un sottoconto salva correttamente il campo codice', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    
    // Capitolo padre
    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'codice'         => null,
        'nome'           => 'Ascensore',
        'tipo'           => 'spesa',
    ]);

    // Sottoconto esistente
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => $capitolo->id,
        'codice'         => null,
        'nome'           => 'Manutenzione Ordinaria',
        'tipo'           => 'spesa',
    ]);

    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $sottoconto]), [
            'parent_id' => $capitolo->id,
            'codice'    => 'ASC-001',
            'nome'      => 'Manutenzione Ordinaria ASC',
            'tipo'      => 'spesa',
            'isCapitolo' => false,
            'isSottoConto' => true,
            'importo'   => '1000',
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 50,
            'percentuale_inquilino' => 50,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    // Verify DB state
    $sottoconto->refresh();
    expect($sottoconto->nome)->toBe('Manutenzione Ordinaria ASC');
    expect($sottoconto->codice)->toBe('ASC-001'); // Questo prima falliva perché 'codice' non era nel fillable!
});
