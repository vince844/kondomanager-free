<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 755 (2) — le regole del recupero crediti si scrivono e si eliminano solo con «Modifica condomini».
 *
 * Fino alla 1.12.0-beta.13 `RegoleRecuperoController::store` e `::destroy` non avevano nessuna guardia di permesso: chi aveva il solo «Accesso
 * pannello amministratore» (il ruolo della demo pubblica, o un ruolo su misura) scriveva «dal 01/09/2026, 12 % annuo» e gli interessi proposti ai
 * condòmini passavano dall'1,60 % al 12 %. I vicini, gli interessi degli eredi e il tasso legale, davano già 403. La correzione è quella dei vicini:
 * `Gate::authorize('update', $condominio)` (decisione 677 (2)); la pagina nasconde il riquadro e il pulsante a chi non può (`puo_modificare_regole`).
 * Il modulo dei permessi per condominio viene dopo (decisione 671).
 *
 * Il caso: Mario, con il solo accesso al pannello, scrive una regola del 12 % su Via Verdi; poi la elimina.
 */

use App\Enums\Permission as Permesso;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // L'amministratore delle prove (con «Modifica condomini», come tutte le prove degli addebiti) e Mario, con il solo accesso al pannello.
    adbAdmin($this);
    $this->withoutVite();
    // Il permesso esiste nell'installazione (il seeder dei ruoli); qui `adbAdmin` lo crea, o `Gate::authorize` darebbe 500 invece di 403.
    $soloPannello = Role::firstOrCreate(['name' => 'solo-pannello', 'guard_name' => 'web']);
    $soloPannello->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->mario = User::factory()->create();
    $this->mario->assignRole($soloPannello);
});

/** La regola del 12 % annuo dal 01/09/2026, come la manda la pagina. */
function rrspRegola(string $dal = '2026-09-01'): array
{
    return ['dal' => $dal, 'tasso_tipo' => 'scritto', 'tasso_annuo' => '12', 'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento'];
}

it('T6_755_regole_senza_permesso: con il solo pannello Mario non scrive né elimina una regola (403), e la pagina non gli offre né l\'una né l\'altra', function () {
    $s = accViaVerdi($this);
    // Una regola già scritta da chi può, per provare anche l'eliminazione.
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola('2026-05-01'))->assertSessionHasNoErrors();
    $esistente = RegolaRecupero::where('condominio_id', $s['c']->id)->sole();
    $mario = $this->mario;
    expect($mario->can(Permesso::EDIT_CONDOMINI->value))->toBeFalse();

    $this->actingAs($mario)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola())->assertForbidden();
    expect(RegolaRecupero::where('condominio_id', $s['c']->id)->count())->toBe(1);

    $this->actingAs($mario)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $esistente->id]))->assertForbidden();
    expect(RegolaRecupero::where('condominio_id', $s['c']->id)->count())->toBe(1);

    // La stessa pagina, sullo stesso utente, in sola lettura: i vicini (eredi, tasso legale, impostazioni) erano già chiusi.
    $this->actingAs($mario)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('puo_modificare_regole', false)->where('puo_modificare_eredi', false));
    $this->actingAs($mario)->post(route('admin.gestionale.recupero-crediti.interessi-eredi', [$s['c']]), ['scelte' => ['dal_decesso' => false]])->assertForbidden();
});

it('T6_755_regole_senza_permesso: con «Modifica condomini» la regola si scrive e si elimina come prima, e la pagina offre i due gesti', function () {
    $s = accViaVerdi($this);

    $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()
        ->assertInertia(fn ($p) => $p->where('puo_modificare_regole', true));

    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola())->assertSessionHasNoErrors()->assertRedirect();
    $regola = RegolaRecupero::where('condominio_id', $s['c']->id)->sole();

    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$s['c'], $regola->id]))->assertSessionHasNoErrors()->assertRedirect();
    expect(RegolaRecupero::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('T6_755_regole_senza_permesso: la regola di un altro condominio resta un 404 anche per chi ha il permesso', function () {
    $s = accViaVerdi($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola())->assertSessionHasNoErrors();
    $regola = RegolaRecupero::where('condominio_id', $s['c']->id)->sole();
    $altro = \App\Models\Condominio::factory()->create();

    $this->actingAs($this->user)->delete(route('admin.gestionale.recupero-crediti.destroy', [$altro, $regola->id]))->assertNotFound();
    expect(RegolaRecupero::where('condominio_id', $s['c']->id)->count())->toBe(1);
});

it('catena · Mario non cambia gli interessi proposti ai condòmini: il 12 % scritto da lui è un 403 e la proposta resta all\'1,60 %; scritto da chi ha il permesso, la proposta lo usa', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-29 10:00:00');
    expect(accProposta($this, $s, ['Neri'])->json('totale'))->toBe(118);

    // Mario scrive «dal 01/04/2026, 12 % annuo»: 403, e la proposta è quella di prima.
    $this->actingAs($this->mario)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola('2026-04-01'))->assertForbidden();
    expect(RegolaRecupero::where('condominio_id', $s['c']->id)->count())->toBe(0)
        ->and(accProposta($this, $s, ['Neri'])->json('totale'))->toBe(118);

    // Con il permesso la regola nasce e la proposta la usa, come prima della correzione.
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.store', [$s['c']]), rrspRegola('2026-04-01'))->assertSessionHasNoErrors();
    expect(accProposta($this, $s, ['Neri'])->json('totale'))->toBeGreaterThan(118);
});
