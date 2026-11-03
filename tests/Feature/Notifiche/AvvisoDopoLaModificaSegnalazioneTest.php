<?php

/**
 * # La platea della segnalazione dopo una modifica
 *
 * ## Perché un file a parte e non due righe in più nell'altro
 *
 * È la lezione della beta.62: *il test si scrive per rotta*, perché è la simmetria fra le porte a
 * essere l'invariante.
 *
 * ## La platea, dopo la decisione Q1
 *
 * Dalla decisione Q1 del 17/10/2026 (Coda 302) **la pivot `anagrafiche` dice a chi è indirizzata**
 * la segnalazione (elenco, scheda, commenti, notifiche, etichette) e **`is_private` decide la
 * privacy**, come per le comunicazioni: la platea è chi trova la segnalazione nell'elenco del
 * portale. Una segnalazione indirizzata a persone avvisa solo loro, una privata l'autore e le
 * persone collegate, una non indirizzata il condominio. Perché la creazione e la modifica non
 * divergano, la platea la calcolano tutte e due da `DestinatariNotifica`.
 *
 * Il caso in cui la platea cambia per tutto un palazzo resta **cambiare il condominio** di una
 * segnalazione non indirizzata: da quel momento riguarda persone che non ne hanno mai saputo niente.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use App\Services\Notifiche\DestinatariNotifica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission as SpatiePermission;

uses(RefreshDatabase::class);

/** Un'anagrafica che riceve davvero: utente, preferenza accesa, permesso, e il condominio. */
function abitanteConNotifiche(Condominio $condominio, string $nome): Anagrafica
{
    SpatiePermission::findOrCreate(Permission::VIEW_SEGNALAZIONI->value, 'web');

    $user = User::factory()->create(['name' => $nome]);
    $user->givePermissionTo(Permission::VIEW_SEGNALAZIONI->value);

    // ⚠️ **Due righe, non una.** Dalla beta.64 l'avviso di *modifica* ha una preferenza sua,
    // separata da quella delle cose *nuove*: una fixture che creasse solo la prima produrrebbe
    // zero destinatari sull'avviso di modifica, cioè un test rosso per la ragione sbagliata.
    foreach ([NotificationType::NEW_TICKET, NotificationType::UPDATED_TICKET] as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id'    => $user->id,
            'type'       => $tipo->value,
            'enabled'    => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $anagrafica = Anagrafica::factory()->create(['nome' => $nome, 'user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $anagrafica;
}

function amministratoreSegnalazioni(): User
{
    foreach ([Permission::VIEW_SEGNALAZIONI, Permission::EDIT_SEGNALAZIONI, Permission::ACCESS_ADMIN_PANEL, Permission::PUBLISH_SEGNALAZIONI] as $p) {
        SpatiePermission::findOrCreate($p->value, 'web');
    }

    $user = User::factory()->create(['name' => 'Amministratore']);
    $user->givePermissionTo([
        Permission::VIEW_SEGNALAZIONI->value,
        Permission::EDIT_SEGNALAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);

    return $user;
}

function datiSegnalazione(Segnalazione $s, Condominio $condominio, array $anagrafiche = [], bool $avvisa = false): array
{
    return [
        'subject'            => $s->subject,
        'description'        => $s->description,
        'priority'           => $s->priority,
        'stato'              => $s->stato,
        'can_comment'        => $s->can_comment,
        'is_featured'        => $s->is_featured,
        'is_published'       => $s->is_published,
        'created_by'         => $s->created_by,
        'is_approved'        => $s->is_approved,
        'condominio_id'      => $condominio->id,
        'anagrafiche'        => collect($anagrafiche)->pluck('id')->all(),
        'avvisa_destinatari' => $avvisa,
    ];
}

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Notification::fake();

    $this->condominio = Condominio::factory()->create();
    $this->altroCondominio = Condominio::factory()->create();
    $this->admin = amministratoreSegnalazioni();

    $this->abitante = abitanteConNotifiche($this->condominio, 'Rossi Mario');
    $this->estraneo = abitanteConNotifiche($this->altroCondominio, 'Verdi Luigi');

    $this->segnalazione = Segnalazione::create([
        'subject'       => 'Ascensore fermo',
        'description'   => 'Bloccato al secondo piano.',
        'priority'      => 'alta',
        'stato'         => 'aperta',
        'can_comment'   => true,
        'is_featured'   => false,
        'is_published'  => true,
        'is_approved'   => true,
        'created_by'    => $this->admin->id,
        'condominio_id' => $this->condominio->id,
    ]);
});

it('la platea sono le persone a cui è indirizzata, e il condominio solo se non lo è a nessuno', function () {
    // Coda 302, decisione Q1 del 17/10/2026: la pivot dice a chi è indirizzata. Nel condominio ci
    // sono due persone e la lista ne nomina una: con una persona sola la lista e il condominio
    // darebbero la stessa platea, e il test non distinguerebbe le due regole (la lista, o tutto il
    // condominio).
    $vicino = abitanteConNotifiche($this->condominio, 'Bianchi Anna');
    $risolutore = app(DestinatariNotifica::class);

    $this->segnalazione->anagrafiche()->sync([$this->abitante->id]);

    expect($risolutore->perModello($this->segnalazione->fresh())->all())
        ->toBe([$this->abitante->id]);

    // Senza persone collegate è di tutto il condominio.
    $this->segnalazione->anagrafiche()->sync([]);

    expect($risolutore->perModello($this->segnalazione->fresh())->sort()->values()->all())
        ->toBe(collect([$this->abitante->id, $vicino->id])->sort()->values()->all());
});

it('cambiando condominio, chi non ne sapeva niente riceve la segnalazione', function () {
    // ⚠️ È il difetto, nella forma che le segnalazioni possono avere: la segnalazione passa a un
    // altro condominio e le persone di quel condominio non ricevono niente, né allora né mai.
    $this->actingAs($this->admin)->put(
        route('admin.segnalazioni.update', ['segnalazione' => $this->segnalazione->id]),
        datiSegnalazione($this->segnalazione, $this->altroCondominio)
    );

    Notification::assertSentTo($this->estraneo, NewSegnalazioneNotification::class);
});

it('e chi non ne fa più parte non riceve niente', function () {
    $this->actingAs($this->admin)->put(
        route('admin.segnalazioni.update', ['segnalazione' => $this->segnalazione->id]),
        datiSegnalazione($this->segnalazione, $this->altroCondominio, avvisa: true)
    );

    Notification::assertNothingSentTo($this->abitante);
});

it("con la casella spuntata, il condominio riceve l'avviso di modifica", function () {
    $this->actingAs($this->admin)->put(
        route('admin.segnalazioni.update', ['segnalazione' => $this->segnalazione->id]),
        datiSegnalazione($this->segnalazione, $this->condominio, avvisa: true)
    );

    Notification::assertSentTo($this->abitante, UpdatedSegnalazioneNotification::class);
});

it('senza la casella non parte niente', function () {
    $this->actingAs($this->admin)->put(
        route('admin.segnalazioni.update', ['segnalazione' => $this->segnalazione->id]),
        datiSegnalazione($this->segnalazione, $this->condominio, avvisa: false)
    );

    Notification::assertNothingSentTo($this->abitante);
});
