<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # «Modifica proprie» ed «Elimina proprie scadenze in agenda» valgono solo per gli appuntamenti dell'agenda (Coda 643)
 *
 * Il compito di una segnalazione e il compito di un bonifico segnalato nascono con `created_by` di chi li ha fatti nascere, ma sono del lavoro dello studio:
 * il ramo «proprie» di `EventoPolicy` non li tocca. Gli appuntamenti propri (senza `meta.type`, senza un oggetto collegato, tipo d'agenda) restano
 * modificabili ed eliminabili da chi li ha creati.
 *
 * La catena: segnalazione dal portale, compito nella casella delle attività dello studio, rifiuto del condòmino, compito ancora lì.
 */

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->aurora = \App\Models\Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->admin = ccmUtente([$this->aurora->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
    $this->lucia = ccmUtente([$this->aurora->id], 'Lucia Verdi');
    $this->lucia->givePermissionTo([Permission::EDIT_OWN_EVENTS->value, Permission::DELETE_OWN_EVENTS->value]);
    $this->lucia = $this->lucia->fresh();
    $this->categoria = CategoriaEvento::firstOrCreate(['name' => 'Agenda'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
});

/** Un appuntamento d'agenda di chi lo crea: tipo d'agenda, senza `meta.type`, senza oggetto collegato. */
function prpAppuntamento($test, User $autore, array $campi = []): Evento
{
    $evento = Evento::create(array_merge([
        'title' => 'Il mio promemoria', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(),
        'created_by' => $autore->id, 'category_id' => $test->categoria->id, 'visibility' => 'hidden', 'is_approved' => false,
        'timezone' => config('app.timezone'),
    ], $campi));
    $evento->condomini()->attach($test->aurora->id);

    return $evento;
}

/** I campi del modulo del portale per modificare un evento. */
function prpModulo($test, Evento $evento, array $campi = []): array
{
    return array_merge([
        'title' => 'Titolo cambiato', 'start_time' => now()->addDays(4)->format('Y-m-d\TH:i'), 'end_time' => now()->addDays(4)->addHour()->format('Y-m-d\TH:i'),
        'category_id' => $test->categoria->id, 'visibility' => 'hidden', 'condomini_ids' => [$test->aurora->id], 'mode' => 'all',
    ], $campi);
}

/** Il compito che la segnalazione di Lucia fa nascere nella casella dello studio. */
function prpCompitoDellaSegnalazione($test): Evento
{
    ccmSegnalazione($test->aurora, $test->lucia, ['subject' => 'Infiltrazione nel box']);
    $compito = Evento::where('tipo', EventoTipo::SEGNALAZIONE_GUASTO->value)->sole();
    expect((int) $compito->created_by)->toBe($test->lucia->id)->and($compito->meta['requires_action'] ?? false)->toBeTrue();

    return $compito;
}

/** Il compito del bonifico che Lucia segnala sulla sua rata. */
function prpCompitoDelBonifico($test): Evento
{
    $rata = Evento::create([
        'title' => 'Scadenza rata 1 - Piano di prova', 'start_time' => now()->addDays(10), 'end_time' => now()->addDays(10)->endOfDay(),
        'created_by' => $test->admin->id, 'category_id' => $test->categoria->id, 'visibility' => 'private', 'is_approved' => true,
        'timezone' => config('app.timezone'), 'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
        'meta' => ['type' => EventoTipo::SCADENZA_RATA_CONDOMINO->value, 'status' => 'pending', 'importo_restante' => 10000, 'context' => []],
    ]);
    $rata->condomini()->attach($test->aurora->id);
    $rata->anagrafiche()->attach($test->lucia->anagrafica->id);

    $test->actingAs($test->lucia)->post(route('user.eventi.report_payment', $rata))->assertRedirect()->assertSessionHasNoErrors();

    $compito = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->sole();
    expect((int) $compito->created_by)->toBe($test->lucia->id);

    return $compito;
}

it('il compito della propria segnalazione non si apre, non si modifica e non si elimina dal portale', function () {
    $compito = prpCompitoDellaSegnalazione($this);
    $titolo = $compito->title;

    $this->actingAs($this->lucia)->get(route('user.eventi.edit', $compito))->assertForbidden();
    $this->actingAs($this->lucia)->put(route('user.eventi.update', $compito), prpModulo($this, $compito))->assertForbidden();
    $this->actingAs($this->lucia)->delete(route('user.eventi.destroy', $compito))->assertForbidden();

    $ora = $compito->fresh();
    expect($ora)->not->toBeNull()->and($ora->title)->toBe($titolo)->and($ora->is_completed)->toBeFalse();
    // La catena: resta nella casella delle attività dello studio.
    expect(Evento::query()->compitiAperti()->whereKey($compito->id)->exists())->toBeTrue();
});

it('il compito del proprio bonifico segnalato non si modifica e non si elimina dal portale', function () {
    $compito = prpCompitoDelBonifico($this);

    $this->actingAs($this->lucia)->put(route('user.eventi.update', $compito), prpModulo($this, $compito))->assertForbidden();
    $this->actingAs($this->lucia)->delete(route('user.eventi.destroy', $compito))->assertForbidden();

    expect($compito->fresh())->not->toBeNull()->and(Evento::query()->compitiAperti()->whereKey($compito->id)->exists())->toBeTrue();
});

it('il proprio appuntamento d\'agenda si modifica e si elimina ancora', function () {
    $mio = prpAppuntamento($this, $this->lucia);

    $this->actingAs($this->lucia)->put(route('user.eventi.update', $mio), prpModulo($this, $mio))->assertRedirect()->assertSessionHasNoErrors();
    expect($mio->fresh()->title)->toBe('Titolo cambiato');

    $this->actingAs($this->lucia)->delete(route('user.eventi.destroy', $mio))->assertRedirect();
    expect(Evento::whereKey($mio->id)->exists())->toBeFalse();
});

it('l\'appuntamento di un altro resta di un altro, anche per chi ha «proprie»', function () {
    $altrui = prpAppuntamento($this, $this->admin);

    $this->actingAs($this->lucia)->delete(route('user.eventi.destroy', $altrui))->assertForbidden();
    expect($altrui->fresh())->not->toBeNull();
});
