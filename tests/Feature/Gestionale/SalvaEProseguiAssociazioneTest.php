<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * "Salva e prosegui" nella scheda "Nuova associazione" (issue #69).
 *
 * Accanto a "Salva associazione" (che resta com'era e manda all'elenco) ci sono "Salva e aggiungi
 * un'altra persona", che riapre la nuova associazione della stessa unità, e "Salva e crea un nuovo
 * immobile", che apre il nuovo immobile dello stesso condominio. Il modulo sceglie la destinazione
 * con il campo `next_route`. Qui si verificano il redirect, il messaggio e l'associazione salvata.
 *
 * «Nuovo immobile» chiede «Modifica condomini» (`ImmobileController::create`), l'associazione no: a chi
 * non ce l'ha il salvataggio torna all'elenco, come con il pulsante principale. Il rimando si segue: la
 * pagina dopo deve rispondere 200.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(
        Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web'])
    );

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    // Esiste ma l'utente non ce l'ha: la danno le prove che ne hanno bisogno.
    Permission::firstOrCreate(['name' => 'Modifica condomini', 'guard_name' => 'web']);

    $this->condominio = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);

    $this->immobile = Immobile::query()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Appartamento', 'interno' => '7']);
    $this->persona = Anagrafica::query()->create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 12', 'codice_fiscale' => 'RSSMRA70A01H501U']);
});

function spaSalva($test, array $extra = [])
{
    return $test->actingAs($test->user)->post(
        route('admin.gestionale.immobili.anagrafiche.store', ['condominio' => $test->condominio->id, 'immobile' => $test->immobile->id]),
        array_merge([
            'anagrafica_id' => $test->persona->id,
            'tipologia'     => 'proprietario',
            'quota'         => 100,
            'data_inizio'   => '2026-01-01',
        ], $extra)
    );
}

function spaAssociazioneSalvata($test): bool
{
    return $test->immobile->anagrafiche()->whereKey($test->persona->id)->exists();
}

it('con il solo "Salva associazione" torna all\'elenco, come prima', function () {
    spaSalva($this)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success')
        ->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [
            'condominio' => $this->condominio->id,
            'immobile'   => $this->immobile->id,
        ]));

    expect(spaAssociazioneSalvata($this))->toBeTrue();
});

it('con "Salva e aggiungi un\'altra persona" riapre la nuova associazione della stessa unità', function () {
    spaSalva($this, ['next_route' => 'associa_anagrafica'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success')
        ->assertRedirect(route('admin.gestionale.immobili.anagrafiche.create', [
            'condominio' => $this->condominio->id,
            'immobile'   => $this->immobile->id,
        ]));

    expect(spaAssociazioneSalvata($this))->toBeTrue();
});

/** La pagina a cui il salvataggio rimanda, aperta davvero: deve rispondere 200, con il messaggio di conferma. */
function spaSegui($test, $risposta, string $pagina): void
{
    $test->actingAs($test->user)->get($risposta->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($pagina)->where('flash.message.type', 'success'));
}

it('con "Salva e crea un nuovo immobile" apre il nuovo immobile dello stesso condominio', function () {
    $this->user->givePermissionTo('Modifica condomini');

    $risposta = spaSalva($this, ['next_route' => 'nuovo_immobile'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success')
        ->assertRedirect(route('admin.gestionale.immobili.create', ['condominio' => $this->condominio->id]));

    expect(spaAssociazioneSalvata($this))->toBeTrue();
    spaSegui($this, $risposta, 'gestionale/immobili/ImmobiliNew');
});

it('senza «Modifica condomini», "Salva e crea un nuovo immobile" salva e torna all\'elenco, non a una pagina che risponde 403', function () {
    $risposta = spaSalva($this, ['next_route' => 'nuovo_immobile'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success')
        ->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [
            'condominio' => $this->condominio->id,
            'immobile'   => $this->immobile->id,
        ]));

    expect(spaAssociazioneSalvata($this))->toBeTrue();
    spaSegui($this, $risposta, 'gestionale/immobili/anagrafiche/AnagraficheList');
});

it('rifiuta una destinazione non ammessa e non salva l\'associazione', function () {
    spaSalva($this, ['next_route' => 'altrove'])
        ->assertSessionHasErrors('next_route');

    expect(spaAssociazioneSalvata($this))->toBeFalse();
});
