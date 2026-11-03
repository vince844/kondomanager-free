<?php

/**
 * ⚠️ **Preesistente, non introdotto dalla 1.11.0-beta.13** — trovato dalla revisione
 * avversariale della beta.13, ma il difetto esiste da quando l'indice unico su
 * `fatture_passive` esiste (24/02/2026). Stornando lo stesso giorno due fatture dello
 * stesso fornitore con lo stesso numero, `StornoFatturaController` genera due note di
 * credito entrambe chiamate `STORNO-<numero>` con `data_documento = oggi`: la seconda
 * collide sull'indice unico, e prima di questa correzione il messaggio SQL grezzo
 * arrivava a video con host, porta e nome del database MySQL.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

test('stornare due fatture gemelle lo stesso giorno mostra un messaggio di dominio, non l\'errore SQL grezzo', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    // Due fatture dello stesso fornitore, stesso numero, date DIVERSE: la creazione non
    // collide (l'indice include data_documento). Sono le "gemelle" che D4 lascia registrare.
    $fatturaA = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'FT-STORNO-DUP',
        'data_documento'   => '2026-03-01',
        'righe' => [[
            'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);
    $fatturaB = registraFatturaServiceTest($ctx, [
        'numero_documento' => 'FT-STORNO-DUP',
        'data_documento'   => '2026-04-01',
        'righe' => [[
            'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
        ]],
    ]);

    // Primo storno: genera "STORNO-FT-STORNO-DUP" datato oggi. Va a buon fine.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fatturaA]))
        ->assertSessionHasNoErrors();

    // Secondo storno: stesso fornitore, stesso numero originale, stesso giorno -> la NC
    // generata collide con quella del primo storno sull'indice unico.
    $risposta = $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.storno', [$condominio, $fatturaB]));

    $messaggio = session('message');
    expect($messaggio['type'])->toBe('error');
    expect($messaggio['message'])
        ->not->toContain('SQLSTATE')
        ->not->toContain('Duplicate entry')
        ->not->toContain('UNIQUE constraint')
        ->not->toContain('Connection:')
        ->not->toContain('mysql');

    // Il rollback ha funzionato: la fattura B non risulta stornata.
    expect($fatturaB->fresh()->dati_extra['is_stornata'] ?? false)->toBeFalse();

    // E non è rimasta una NC orfana o parziale dal tentativo fallito.
    expect(DB::table('fatture_passive')
        ->where('numero_documento', 'STORNO-FT-STORNO-DUP')
        ->count())->toBe(1);
});
