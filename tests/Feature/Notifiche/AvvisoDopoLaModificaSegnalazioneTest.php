<?php

/**
 * # La segnalazione ha una platea *derivata*, e cambia solo cambiando condominio
 *
 * ## Perché un file a parte e non due righe in più nell'altro
 *
 * È la lezione della beta.62, e qui vale più che altrove: *il test si scrive per rotta*, perché è
 * la simmetria fra le porte a essere l'invariante. Ma c'è una seconda ragione, più forte: le
 * segnalazioni **non funzionano come le comunicazioni**.
 *
 * - Una **comunicazione** ha una platea *scelta*: un elenco di anagrafiche, oppure i condomìni
 *   collegati. Modificarla può aggiungere destinatari.
 * - Una **segnalazione** ha una platea *derivata*: `SendNewSegnalazioneNotificationToUser` avvisa
 *   tutte le anagrafiche del condominio indicato da `condominio_id`, e basta.
 *
 * La pivot `anagrafiche` **esiste anche sulle segnalazioni** e viene sincronizzata in modifica — ma
 * **non decide chi riceve la notifica**. Se `DestinatariNotifica` l'avesse letta anche qui, come fa
 * per le comunicazioni, modificare quella lista avrebbe mandato mail a gente a cui il listener di
 * creazione non le manda mai: una notifica che nasce da una modifica e che la creazione non
 * avrebbe prodotto. Sarebbe stato il difetto peggiore dei due.
 *
 * Il caso in cui la platea cambia davvero esiste, ed è **cambiare il condominio**: da quel momento
 * la segnalazione riguarda persone che non ne hanno mai saputo niente.
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

it('la platea è il condominio, non la lista delle anagrafiche', function () {
    // ⚠️ È l'asserzione che impedisce la correzione sbagliata. Se `DestinatariNotifica` leggesse la
    // pivot `anagrafiche` anche per le segnalazioni, qui comparirebbe il solo Rossi quando la lista
    // lo nomina — e la modifica manderebbe mail che la creazione non manda mai.
    $risolutore = app(DestinatariNotifica::class);

    $this->segnalazione->anagrafiche()->sync([$this->abitante->id]);

    expect($risolutore->perModello($this->segnalazione->fresh())->all())
        ->toBe([$this->abitante->id]);

    // E resta la stessa anche svuotando la lista: la platea non dipende da quella pivot.
    $this->segnalazione->anagrafiche()->sync([]);

    expect($risolutore->perModello($this->segnalazione->fresh())->all())
        ->toBe([$this->abitante->id]);
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
