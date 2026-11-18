<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Le proprie in attesa nell'elenco del portale (Coda 389, decisione 262)
 *
 * Un condòmino che manda una segnalazione o una comunicazione in moderazione non la ritrovava nell'elenco del portale, e
 * pensava che l'invio fosse andato perso: la scheda la apriva, l'elenco no. Ora l'elenco mostra ciò che la scheda apre,
 * comprese le proprie non ancora uscite, con il loro stato (`is_published`, `is_approved`) perché la pagina dica «Da
 * approvare» o «Bozza». Quelle degli altri in attesa restano fuori.
 */

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->mario = proprieAttesaUtente('Mario Rossi', $this->aurora);
    $this->paolo = proprieAttesaUtente('Paolo Neri', $this->aurora);
});

function proprieAttesaUtente(string $nome, Condominio $condominio): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole('utente');
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

function proprieAttesaSegnalazione(Condominio $condominio, User $autore, string $oggetto, array $campi = []): Segnalazione
{
    return Segnalazione::create(array_merge([
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $campi));
}

function proprieAttesaComunicazione(Condominio $condominio, User $autore, string $oggetto, array $campi = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ], $campi));
    $c->condomini()->attach($condominio->id);

    return $c;
}

/** Le righe dell'elenco del portale, per oggetto. */
function proprieAttesaElenco(User $user, string $rotta, string $chiave): array
{
    $props = test()->actingAs($user)->get(route($rotta))->assertOk()->viewData('page')['props'];

    return collect(json_decode(json_encode($props[$chiave]['data']), true))->keyBy('subject')->all();
}

test('Coda 389 — nell\'elenco delle segnalazioni il condòmino ritrova la sua in attesa, con il suo stato; quella del vicino in attesa no', function () {
    proprieAttesaSegnalazione($this->aurora, $this->paolo, 'Ascensore fermo');
    proprieAttesaSegnalazione($this->aurora, $this->mario, 'Perdita in cantina', ['is_approved' => false]);
    proprieAttesaSegnalazione($this->aurora, $this->paolo, 'Del vicino, in attesa', ['is_approved' => false]);

    $righe = proprieAttesaElenco($this->mario, 'user.segnalazioni.index', 'segnalazioni');

    expect(array_keys($righe))->toEqualCanonicalizing(['Ascensore fermo', 'Perdita in cantina'])
        ->and((bool) $righe['Perdita in cantina']['is_approved'])->toBeFalse()
        ->and((bool) $righe['Perdita in cantina']['is_published'])->toBeTrue();
});

test('Coda 389 — nell\'elenco delle comunicazioni il condòmino ritrova la sua in attesa; quella del vicino in attesa no', function () {
    proprieAttesaComunicazione($this->aurora, $this->paolo, 'Turni delle pulizie');
    proprieAttesaComunicazione($this->aurora, $this->mario, 'Rumori di notte', ['is_approved' => false]);
    proprieAttesaComunicazione($this->aurora, $this->paolo, 'Del vicino, in attesa', ['is_approved' => false]);

    $righe = proprieAttesaElenco($this->mario, 'user.comunicazioni.index', 'comunicazioni');

    expect(array_keys($righe))->toEqualCanonicalizing(['Turni delle pulizie', 'Rumori di notte'])
        ->and((bool) $righe['Rumori di notte']['is_approved'])->toBeFalse();
});

test('Coda 389 — la regola dell\'elenco è quella della scheda: ogni riga si apre, e una del vicino in attesa non si apre', function () {
    $propria = proprieAttesaSegnalazione($this->aurora, $this->mario, 'Perdita in cantina', ['is_approved' => false]);
    $delVicino = proprieAttesaSegnalazione($this->aurora, $this->paolo, 'Del vicino, in attesa', ['is_approved' => false]);

    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $propria))->assertOk();
    expect(in_array($this->actingAs($this->mario)->get(route('user.segnalazioni.show', $delVicino))->status(), [403, 404], true))->toBeTrue();
});
