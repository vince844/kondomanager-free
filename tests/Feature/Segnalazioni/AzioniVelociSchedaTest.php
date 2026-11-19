<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le azioni veloci sulla scheda della segnalazione del pannello (Coda 671, decisioni 466 (2), 486 e 487): lo stato lavorazione e la priorità si
 * cambiano dalla card dei parametri, e la scelta si salva subito per la stessa strada della pagina «Modifica».
 *
 * - La rotta `admin.segnalazioni.parametro` (PATCH) riceve un campo solo, `stato` o `priority`, con i valori ammessi; chiede lo stesso permesso di
 *   «Modifica» (`SegnalazionePolicy::update`) e risponde tornando alla scheda.
 * - Come «Modifica»: scrive chi ha cambiato (`updated_by`); il compito dell'Action inbox si chiude con «Chiusa» e torna riaprendo, e segue la
 *   priorità; gli avvisi sono quelli di «Modifica» senza la casella «Avvisa i destinatari»: cambiare lo stato o la priorità non manda email,
 *   da «Modifica» come da qui (provato con le due strade, una accanto all'altra).
 * - La scheda dice alla pagina se chi guarda può cambiarli (`puo_modificare`, con la stessa regola).
 *
 * Che cosa non copre: il menu e la conferma prima di «Chiusa» (li prova il vitest `resources/js/pages/segnalazioni/SegnalazioniViewAzioniVeloci.test.ts`),
 * le email di «Modifica» quando cambiano i destinatari (le prova `tests/Feature/Notifiche/AvvisoDopoLaModificaSegnalazioneTest.php`).
 */

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->admin = User::factory()->create(['name' => 'Vincenzo', 'email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    $this->segnalazione = Segnalazione::create([
        'subject' => 'Ascensore fermo', 'description' => 'Si è fermato al secondo piano.', 'created_by' => $this->admin->id,
        'condominio_id' => $this->condominio->id, 'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true,
        'is_private' => false, 'can_comment' => true,
    ]);
});

function avsCompito(Segnalazione $s): ?Evento
{
    return Evento::where('tipo', 'segnalazione_guasto')->where('eventable_id', $s->id)->first();
}

it('cambia lo stato lavorazione e torna alla scheda, scrivendo chi l\'ha cambiato', function () {
    $altro = User::factory()->create(['email_verified_at' => now()]);
    $altro->assignRole(Role::COLLABORATORE->value);

    $this->actingAs($altro)->from(route('admin.segnalazioni.show', $this->segnalazione))
        ->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['stato' => 'in lavorazione'])
        ->assertRedirect(route('admin.segnalazioni.show', $this->segnalazione))
        ->assertSessionHasNoErrors();

    $s = $this->segnalazione->fresh();
    expect($s->stato)->toBe('in lavorazione')
        ->and((int) $s->updated_by)->toBe($altro->id)
        ->and($s->priority)->toBe('media');
});

it('con «Chiusa» il compito esce dall\'Action inbox, riaprendo torna; la priorità arriva al compito', function () {
    expect(avsCompito($this->segnalazione)->is_completed)->toBeFalse();

    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['stato' => 'chiusa'])->assertSessionHasNoErrors();
    expect(avsCompito($this->segnalazione)->is_completed)->toBeTrue();

    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['stato' => 'aperta'])->assertSessionHasNoErrors();
    expect(avsCompito($this->segnalazione)->is_completed)->toBeFalse();

    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['priority' => 'urgente'])->assertSessionHasNoErrors();
    expect($this->segnalazione->fresh()->priority)->toBe('urgente')
        ->and(avsCompito($this->segnalazione)->meta['priority'])->toBe('urgente');
});

it('non manda email, come «Modifica» che cambia solo lo stato', function () {
    // Da qui.
    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['stato' => 'chiusa'])->assertSessionHasNoErrors();
    Notification::assertNothingSent();

    // Da «Modifica», con gli stessi campi della segnalazione e lo stato cambiato, senza la casella «Avvisa i destinatari».
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $this->segnalazione), [
        'subject' => 'Ascensore fermo', 'description' => 'Si è fermato al secondo piano.', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_published' => true, 'is_private' => false, 'can_comment' => true, 'is_featured' => false,
    ])->assertSessionHasNoErrors();
    expect($this->segnalazione->fresh()->stato)->toBe('aperta');
    Notification::assertNothingSent();
});

it('rifiuta un valore che non c\'è, due campi insieme, nessun campo; e non cambia niente', function () {
    foreach ([['stato' => 'risolta'], ['priority' => 'altissima'], ['stato' => 'chiusa', 'priority' => 'alta'], [], ['subject' => 'Altro']] as $dati) {
        $this->actingAs($this->admin)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), $dati)->assertSessionHasErrors();
    }
    $s = $this->segnalazione->fresh();
    expect($s->stato)->toBe('aperta')->and($s->priority)->toBe('media')->and($s->subject)->toBe('Ascensore fermo');
});

/*
 * P6 della lente P (Fase 1-bis della beta.12): il messaggio sotto il valore veloce diceva il nome della colonna («priority selezionato non è valido.»).
 * Ogni rifiuto dice che cosa scegliere, con le parole della pagina. Che cosa non copre: la pagina che lo mostra (`ParametroVeloce.vue`).
 */
it('i rifiuti dicono che cosa scegliere con le parole della pagina, mai il nome della colonna', function () {
    $casi = [
        [['stato' => 'risolta'], 'stato', 'Scegli uno stato dell\'elenco: aperta, in lavorazione o chiusa.'],
        [['priority' => 'altissima'], 'priority', 'Scegli una priorità dell\'elenco: bassa, media, alta o urgente.'],
        [['stato' => 'chiusa', 'priority' => 'alta'], 'stato', 'Cambia una cosa per volta: lo stato o la priorità.'],
        [[], 'stato', 'Scegli lo stato o la priorità.'],
    ];
    foreach ($casi as [$dati, $campo, $frase]) {
        $this->actingAs($this->admin)->from(route('admin.segnalazioni.show', $this->segnalazione))->patch(route('admin.segnalazioni.parametro', $this->segnalazione), $dati)
            ->assertSessionHasErrors($campo);
        $messaggi = collect(session('errors')->getBag('default')->all());
        expect($messaggi->first())->toBe($frase)
            ->and($messaggi->filter(fn ($m) => preg_match('/priority|\bstato selezionato|richiesto/i', $m))->all())->toBe([]);
    }
});

it('chiede lo stesso permesso di «Modifica»: chi vede e non modifica riceve 403, e la scheda gli dice che non può', function () {
    $ruolo = \Spatie\Permission\Models\Role::create(['name' => 'Solo lettura', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['Accesso pannello amministratore', 'Visualizza segnalazioni']);
    $lettore = User::factory()->create(['email_verified_at' => now()]);
    $lettore->assignRole('Solo lettura');

    $this->actingAs($lettore)->patch(route('admin.segnalazioni.parametro', $this->segnalazione), ['stato' => 'chiusa'])->assertForbidden();
    expect($this->segnalazione->fresh()->stato)->toBe('aperta');

    $props = fn (User $chi) => $this->actingAs($chi)->get(route('admin.segnalazioni.show', $this->segnalazione))->assertOk()->viewData('page')['props'];
    expect($props($lettore)['puo_modificare'] ?? null)->toBeFalse()
        ->and($props($this->admin)['puo_modificare'] ?? null)->toBeTrue();
});
