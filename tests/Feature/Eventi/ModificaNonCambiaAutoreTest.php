<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Modificare un evento non cambia l'autore, l'approvazione né, senza «Pubblica scadenze in agenda», la visibilità (Coda 396)
 *
 * `created_by` e `is_approved` si scrivono alla creazione; in modifica restano quelli che l'evento ha. La visibilità cambia solo se chi modifica ha
 * «Pubblica scadenze in agenda», e allora vale ciò che sceglie. Lo stesso per la ripetizione staccata da una serie («solo questa»): nasce con l'autore,
 * la visibilità e l'approvazione della serie. Vale per il pannello e per il portale.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->aurora = \App\Models\Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->admin = ccmUtente([$this->aurora->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
    $this->giulia = ccmUtente([$this->aurora->id], 'Giulia Redazione');
    $this->giulia->givePermissionTo([Permission::CREATE_EVENTS->value, Permission::EDIT_OWN_EVENTS->value]);
    $this->giulia = $this->giulia->fresh();
    $this->categoria = CategoriaEvento::firstOrCreate(['name' => 'Agenda'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
});

function mnaEvento($test, array $campi = []): Evento
{
    $evento = Evento::create(array_merge([
        'title' => 'Riunione con il fornitore', 'start_time' => now()->addDays(5)->setTime(10, 0), 'end_time' => now()->addDays(5)->setTime(11, 0),
        'created_by' => $test->giulia->id, 'category_id' => $test->categoria->id, 'visibility' => 'hidden', 'is_approved' => false,
        'timezone' => config('app.timezone'),
    ], $campi));
    $evento->condomini()->attach($test->aurora->id);

    return $evento;
}

/** Il modulo della pagina di modifica: manda i dati di oggi dell'evento, con ciò che chi salva cambia. */
function mnaModulo($test, Evento $evento, array $campi = []): array
{
    return array_merge([
        'title' => 'Titolo corretto', 'start_time' => $evento->start_time->format('Y-m-d\TH:i'), 'end_time' => $evento->end_time->format('Y-m-d\TH:i'),
        'category_id' => $test->categoria->id, 'visibility' => $evento->visibility, 'condomini_ids' => [$test->aurora->id], 'mode' => 'all',
    ], $campi);
}

it('l\'amministratore che corregge il titolo non diventa l\'autore, e l\'evento nascosto resta nascosto e non approvato', function () {
    $evento = mnaEvento($this);

    $this->actingAs($this->admin)->put(route('admin.eventi.update', $evento), mnaModulo($this, $evento))
        ->assertRedirect()->assertSessionHasNoErrors();

    $ora = $evento->fresh();
    expect($ora->title)->toBe('Titolo corretto')
        ->and((int) $ora->created_by)->toBe($this->giulia->id)
        ->and($ora->visibility)->toBe('hidden')
        ->and((bool) $ora->is_approved)->toBeFalse();
});

it('con «Pubblica scadenze in agenda» la visibilità scelta si scrive', function () {
    $evento = mnaEvento($this);

    $this->actingAs($this->admin)->put(route('admin.eventi.update', $evento), mnaModulo($this, $evento, ['visibility' => 'public']))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($evento->fresh()->visibility)->toBe('public')->and((int) $evento->fresh()->created_by)->toBe($this->giulia->id);
});

it('senza «Pubblica scadenze in agenda» la visibilità resta quella dell\'evento, qualunque cosa mandi il modulo', function (string $mandata) {
    $evento = mnaEvento($this, ['visibility' => 'public', 'is_approved' => true]);

    $this->actingAs($this->giulia)->put(route('user.eventi.update', $evento), mnaModulo($this, $evento, ['visibility' => $mandata]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $ora = $evento->fresh();
    expect($ora->title)->toBe('Titolo corretto')->and($ora->visibility)->toBe('public')->and((bool) $ora->is_approved)->toBeTrue()
        ->and((int) $ora->created_by)->toBe($this->giulia->id);
})->with(['hidden', 'private']);

it('dal pannello, chi non ha «Pubblica scadenze in agenda» non cambia visibilità né autore', function () {
    $sandra = ccmUtente([$this->aurora->id], 'Sandra Segreteria');
    $sandra->givePermissionTo([Permission::ACCESS_ADMIN_PANEL->value, Permission::EDIT_EVENTS->value, Permission::VIEW_EVENTS->value]);
    $evento = mnaEvento($this, ['visibility' => 'public', 'is_approved' => true]);

    $this->actingAs($sandra->fresh())->put(route('admin.eventi.update', $evento), mnaModulo($this, $evento, ['visibility' => 'hidden']))
        ->assertRedirect()->assertSessionHasNoErrors();

    $ora = $evento->fresh();
    expect($ora->title)->toBe('Titolo corretto')->and($ora->visibility)->toBe('public')->and((int) $ora->created_by)->toBe($this->giulia->id);
});

it('la ripetizione staccata da una serie tiene l\'autore, la visibilità e l\'approvazione della serie', function () {
    $ricorrenza = RicorrenzaEvento::create(['frequency' => 'weekly', 'interval' => 1, 'type' => 'rrule', 'rrule' => 'FREQ=WEEKLY;INTERVAL=1', 'timezone' => config('app.timezone')]);
    $serie = mnaEvento($this, ['recurrence_id' => $ricorrenza->id, 'visibility' => 'hidden', 'is_approved' => true]);
    $giorno = $serie->start_time->copy()->addWeek();

    $this->actingAs($this->admin)->put(route('admin.eventi.update', $serie), mnaModulo($this, $serie, [
        'mode' => 'only_this', 'occurrence_date' => $giorno->format('Y-m-d H:i:s'), 'start_time' => $giorno->format('Y-m-d\TH:i'),
        'end_time' => $giorno->copy()->addHour()->format('Y-m-d\TH:i'),
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $staccata = Evento::whereKeyNot($serie->id)->where('title', 'Titolo corretto')->sole();
    expect((int) $staccata->created_by)->toBe($this->giulia->id)
        ->and($staccata->visibility)->toBe('hidden')
        ->and((bool) $staccata->is_approved)->toBeTrue()
        ->and($staccata->recurrence_id)->toBeNull();
});

it('alla creazione l\'autore è chi crea', function () {
    $this->actingAs($this->giulia)->post(route('user.eventi.store'), [
        'title' => 'Nuovo appuntamento', 'start_time' => now()->addDays(2)->format('Y-m-d\TH:i'), 'end_time' => now()->addDays(2)->addHour()->format('Y-m-d\TH:i'),
        'category_id' => $this->categoria->id, 'condomini_ids' => [$this->aurora->id], 'visibility' => 'public', 'created_by' => $this->admin->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $nuovo = Evento::where('title', 'Nuovo appuntamento')->sole();
    expect((int) $nuovo->created_by)->toBe($this->giulia->id)->and($nuovo->visibility)->toBe('hidden');
});
