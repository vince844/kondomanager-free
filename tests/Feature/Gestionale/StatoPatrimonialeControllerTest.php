<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function adminStatoPatrimoniale(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

function condominioConEsercizio(): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    return [$condominio, $esercizio];
}

/** Stessa misura del registro: `scopeBindings()` rifiuta l'esercizio di un altro condominio prima del controller. */
test('un esercizio di un altro condominio non si risolve nemmeno per rotta', function () {
    $user = adminStatoPatrimoniale();
    [$condominio] = condominioConEsercizio();
    [, $esercizioAltrui] = condominioConEsercizio();

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$condominio, $esercizioAltrui]))
        ->assertNotFound();
});

test('index rende la pagina con le sezioni e i sei controlli, anche su un condominio senza movimenti', function () {
    $user = adminStatoPatrimoniale();
    [$condominio, $esercizio] = condominioConEsercizio();

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/statoPatrimoniale/Show')
            ->has('pagina.situazione')
            ->has('pagina.riepilogo')
            ->has('pagina.liquidita')
            ->has('pagina.raccordo')
            ->has('pagina.controlli', 6)
            ->where('pagina.situazione.quadra', true)
            ->where('pagina.controlli.0.id', 'PD')
            ->where('pagina.controlli.1.id', 'R6')
        );
});

test('stampa risponde con un PDF valido e senza firma', function () {
    $user = adminStatoPatrimoniale();
    [$condominio, $esercizio] = condominioConEsercizio();

    $catturati = [];
    View::composer('pdf.gestionale.stato_patrimoniale', function ($view) use (&$catturati) {
        $catturati = $view->getData();
    });

    $risposta = $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$condominio, $esercizio]));

    $risposta->assertOk();
    $risposta->assertHeader('Content-Type', 'application/pdf');
    // Un nome vero, non «print.pdf»: libro, condominio, anno e data della fotografia.
    expect($risposta->headers->get('Content-Disposition'))->toStartWith('inline; filename="stato-patrimoniale-')->toEndWith('.pdf"');
    expect($catturati['senza_firma'])->toBeTrue()
        ->and(view('pdf.gestionale.stato_patrimoniale', $catturati)->render())
            ->toContain('Situazione al')
            ->toContain('Controlli di quadratura')
            ->not->toContain('pareggio');
});
