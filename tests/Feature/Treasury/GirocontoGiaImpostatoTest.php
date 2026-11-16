<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il giroconto che arriva già impostato dall'indirizzo, come lo apre l'azione consigliata della tesoreria «Sposta € … da … a …»
 * (`?origine=…&destinazione=…&importo=…`): la pagina riceve le due casse e la cifra in centesimi, controllate. Un parametro che non torna si ignora da solo
 * (una cassa di un altro condominio, la stessa cassa due volte, un fondo che non si può usare in partenza, un fondo con i contanti, una cifra non intera, a
 * zero o sotto, un elenco al posto di un numero) e la pagina si apre lo stesso. Niente si scrive.
 */

use App\Models\Gestionale\Cassa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function ggiCassa(int $condominioId, string $tipo, array $altro = []): Cassa
{
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominioId, 'ruolo' => 'ggi_'.$tipo.'_'.uniqid(), 'codice' => '1010.'.uniqid(),
        'nome' => 'Conto '.$tipo, 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create($altro + ['condominio_id' => $condominioId, 'nome' => ucfirst($tipo).' '.uniqid(), 'tipo' => $tipo, 'conto_contabile_id' => $conto,
        'saldo_iniziale' => 0, 'attiva' => true, 'sottotipo_fondo' => $tipo === 'fondo' ? 'generico' : null]);
}

/** La preselezione che riceve la pagina con questi parametri. */
function ggiPreselezione($test, $condominio, array $parametri): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.giroconti.create', ['condominio' => $condominio->id] + $parametri))
        ->assertOk()->viewData('page')['props']['preselezione'];
}

it('le due casse e la cifra arrivano alla pagina, controllate; niente si scrive', function () {
    [$condominio] = setupContabile();
    $banca = ggiCassa($condominio->id, 'banca');
    $contanti = ggiCassa($condominio->id, 'contanti');
    $scritture = DB::table('scritture_contabili')->count();

    expect(ggiPreselezione($this, $condominio, ['origine' => $contanti->id, 'destinazione' => $banca->id, 'importo' => 30000]))
        ->toBe(['origine' => $contanti->id, 'destinazione' => $banca->id, 'importo_cents' => 30000])
        ->and(ggiPreselezione($this, $condominio, []))->toBe(['origine' => null, 'destinazione' => null, 'importo_cents' => null])
        ->and(DB::table('scritture_contabili')->count())->toBe($scritture);
});

it('un parametro che non torna si ignora da solo, e la pagina si apre lo stesso', function () {
    [$condominio] = setupContabile();
    [$altro] = setupContabile();
    $banca = ggiCassa($condominio->id, 'banca');
    $contanti = ggiCassa($condominio->id, 'contanti');
    $fondoLibero = ggiCassa($condominio->id, 'fondo');
    $fondoVincolato = ggiCassa($condominio->id, 'fondo', ['sottotipo_fondo' => 'vincolato_lavori']);
    $chiusa = ggiCassa($condominio->id, 'contanti', ['attiva' => false]);
    $diUnAltro = ggiCassa($altro->id, 'banca');

    // Una cassa di un altro condominio, o una cassa chiusa: non c'è.
    expect(ggiPreselezione($this, $condominio, ['origine' => $diUnAltro->id, 'destinazione' => $banca->id, 'importo' => 100]))
        ->toBe(['origine' => null, 'destinazione' => $banca->id, 'importo_cents' => 100])
        ->and(ggiPreselezione($this, $condominio, ['origine' => $chiusa->id, 'destinazione' => $banca->id])['origine'])->toBeNull()
        // La stessa cassa due volte: l'arrivo si toglie.
        ->and(ggiPreselezione($this, $condominio, ['origine' => $banca->id, 'destinazione' => $banca->id])['destinazione'])->toBeNull()
        // Un fondo vincolato non parte senza la delibera; uno libero sì, con la banca.
        ->and(ggiPreselezione($this, $condominio, ['origine' => $fondoVincolato->id, 'destinazione' => $banca->id])['origine'])->toBeNull()
        ->and(ggiPreselezione($this, $condominio, ['origine' => $fondoLibero->id, 'destinazione' => $banca->id]))
            ->toBe(['origine' => $fondoLibero->id, 'destinazione' => $banca->id, 'importo_cents' => null])
        // Un fondo va solo con una banca o un altro fondo: con i contanti l'arrivo si toglie.
        ->and(ggiPreselezione($this, $condominio, ['origine' => $fondoLibero->id, 'destinazione' => $contanti->id])['destinazione'])->toBeNull();

    // La cifra: in centesimi interi, sopra zero.
    foreach (['abc', '12.50', '0', '-500', '1e3'] as $sbagliata) {
        expect(ggiPreselezione($this, $condominio, ['importo' => $sbagliata])['importo_cents'], $sbagliata)->toBeNull();
    }
    // Un elenco al posto di un numero.
    expect(ggiPreselezione($this, $condominio, ['origine' => [$banca->id], 'importo' => [100]]))->toBe(['origine' => null, 'destinazione' => null, 'importo_cents' => null]);
});
