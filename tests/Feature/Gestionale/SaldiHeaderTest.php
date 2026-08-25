<?php

/**
 * beta.34 — L'header dei Saldi Iniziali.
 *
 * La pagina dei Saldi era l'unica dello Struttura da cui non si potesse cambiare
 * condominio. Non per un limite del componente: `PageHeaderGuide` accetta sia
 * `condominio` sia `condomini`, e con entrambi mostra il menu — lo fa già in una
 * ventina di pagine. Il controller dei saldi semplicemente non passava la lista,
 * quindi il nome restava un testo fisso.
 *
 * Il test guarda il payload Inertia, non la UI: è lì che il difetto viveva, ed è
 * l'unico punto in cui una regressione può rientrare in silenzio.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function headerCondominio(string $nome): Condominio
{
    $condominio = Condominio::create([
        'nome' => $nome, 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    // L'index dei saldi rimanda indietro se non c'è un esercizio aperto: senza
    // questo il test misurerebbe il redirect, non il payload.
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione ordinaria 2026',
        'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'attiva' => true,
        'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    return $condominio;
}

function headerUtenteAdmin(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

test('la pagina dei saldi riceve la lista dei condomini per il selettore', function () {
    $primo = headerCondominio('Condominio Alfa');
    $secondo = headerCondominio('Condominio Beta');

    $this->actingAs(headerUtenteAdmin())
        ->get(route('admin.gestionale.saldi.index', $primo->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/saldi/SaldiList')
            ->has('condomini', 2)
            // Il componente mostra il menu solo con più di un condominio: se la
            // lista arrivasse col solo condominio corrente il difetto sarebbe
            // identico a prima, ma il test passerebbe lo stesso.
            ->where('condomini.0.nome', 'Condominio Alfa')
            ->where('condomini.1.nome', 'Condominio Beta')
            ->where('condominio.id', $primo->id)
        );
});

test('il selettore elenca anche i condomini diversi da quello aperto', function () {
    $primo = headerCondominio('Condominio Alfa');
    headerCondominio('Condominio Beta');
    headerCondominio('Condominio Gamma');

    $this->actingAs(headerUtenteAdmin())
        ->get(route('admin.gestionale.saldi.index', $primo->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('condomini', 3)
            ->where('condomini.2.nome', 'Condominio Gamma')
        );
});
