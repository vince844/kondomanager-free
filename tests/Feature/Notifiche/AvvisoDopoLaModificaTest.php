<?php

/**
 * # Chi viene aggiunto in modifica riceve la comunicazione. Chi c'era già, solo se glielo si dice.
 *
 * ## La segnalazione, e cosa c'era davvero sotto
 *
 * Dal forum, agosto 2026: *«la notifica viene trasmessa agli utenti che desiderano averla soltanto
 * per le nuove comunicazioni. Nel caso di modifica della stessa questa non viene processata dal
 * cron job in quanto non crea il Json nella tab job. Proporrei di processare questo invio anche in
 * caso di modifica delle comunicazioni/segnalazioni/etc.»*
 *
 * L'osservazione è esatta e il perimetro che indovina — «comunicazioni/segnalazioni/etc» — è quello
 * giusto: misurato aprendo la beta.64, `store()` lancia un evento e `update()` no in **sei
 * controller su sei**, tre entità per due lati.
 *
 * Ma aprendo il codice le cose sono risultate **due**, con gravità diverse:
 *
 * 1. **Un difetto che nessuno aveva segnalato.** I destinatari si risolvevano dai dati del modulo
 *    **al momento della creazione**. Se poi si entrava in modifica e si aggiungeva un condominio o
 *    un'anagrafica, quelle persone non ricevevano **niente**, né allora né mai. Non è «non le
 *    avvisiamo di una modifica»: a loro la comunicazione **non è mai arrivata**, e la vedevano solo
 *    entrando a guardare.
 * 2. **La richiesta vera e propria**: avvisare chi l'ha già ricevuta che qualcosa è cambiato.
 *
 * ## Le due risposte, che non sono la stessa
 *
 * Il (1) si corregge e basta: i nuovi arrivati ricevono l'avviso di **creazione**, sempre, senza
 * chiedere niente a nessuno — per loro l'oggetto è nuovo davvero.
 *
 * Il (2) è una **casella che l'amministratore spunta**. Notificare a ogni salvataggio vorrebbe dire
 * mandare una mail a tutto il condominio perché si è corretto un refuso, e sarebbe la prima
 * lamentela. L'ultima parola resta a chi firma la comunicazione — il principio che il prodotto
 * applica già dappertutto.
 *
 * ## Cosa questo file NON copre
 *
 * - **Non copre l'invio vero**: `Notification::fake()` intercetta prima del canale mail. Che la
 *   mail parta e sia leggibile è di `LocalizedNotification` e della configurazione SMTP.
 * - **Non copre le altre due entità**: segnalazioni e documenti hanno un file per uno. Le tre
 *   correzioni passano dallo stesso servizio e dallo stesso listener, ma la lezione della beta.62
 *   vale qui più che altrove — *il test si scrive per rotta*, perché è la simmetria fra le porte a
 *   essere l'invariante.
 * - **Non copre il lato condòmino**: là la notifica va agli amministratori ed è un'altra platea.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\User;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Notifications\Comunicazioni\UpdatedComunicazioneNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission as SpatiePermission;

uses(RefreshDatabase::class);

/**
 * Un'anagrafica che riceve davvero: utente collegato, preferenza accesa, permesso concesso.
 *
 * ⚠️ Tutte e tre le condizioni servono — `filterByNotificationPreference()` le pretende tutte — e
 * una fixture che ne dimenticasse una produrrebbe zero destinatari, cioè un test verde che non
 * prova niente.
 */
function destinatarioReale(Condominio $condominio, string $nome): Anagrafica
{
    SpatiePermission::findOrCreate(Permission::VIEW_COMUNICAZIONI->value, 'web');

    $user = User::factory()->create(['name' => $nome]);
    $user->givePermissionTo(Permission::VIEW_COMUNICAZIONI->value);

    // ⚠️ **Due righe, non una.** Dalla beta.64 l'avviso di *modifica* ha una preferenza sua,
    // separata da quella delle cose *nuove*: una fixture che creasse solo la prima produrrebbe
    // zero destinatari sull'avviso di modifica, cioè un test rosso per la ragione sbagliata.
    foreach ([NotificationType::NEW_COMMUNICATION, NotificationType::UPDATED_COMMUNICATION] as $tipo) {
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

/** Una comunicazione già pubblicata, rivolta alle anagrafiche indicate. */
function comunicazionePubblicata(Condominio $condominio, User $autore, array $anagrafiche): Comunicazione
{
    $comunicazione = Comunicazione::create([
        'subject'      => 'Chiusura acqua giovedì',
        'description'  => 'Dalle 9 alle 13.',
        'priority'     => 'alta',
        'can_comment'  => true,
        'is_featured'  => false,
        'is_published' => true,
        'is_approved'  => true,
        'created_by'   => $autore->id,
    ]);

    $comunicazione->condomini()->attach($condominio->id);
    $comunicazione->anagrafiche()->attach(collect($anagrafiche)->pluck('id')->all());

    return $comunicazione;
}

/** L'amministratore che modifica, con i permessi per farlo. */
function amministratoreComunicazioni(): User
{
    // ⚠️ `PUBLISH_COMUNICAZIONI` serve anche se non lo si concede: `prepareForValidation()`
    // della Request lo interroga con `hasPermissionTo()`, e Spatie **solleva** se il permesso
    // non esiste affatto. Senza questa riga la PUT risponde 500 e ogni asserzione sugli invii
    // fallisce per una ragione che non c'entra niente con quello che si sta provando.
    foreach ([Permission::VIEW_COMUNICAZIONI, Permission::EDIT_COMUNICAZIONI, Permission::ACCESS_ADMIN_PANEL, Permission::PUBLISH_COMUNICAZIONI] as $p) {
        SpatiePermission::findOrCreate($p->value, 'web');
    }

    $user = User::factory()->create(['name' => 'Amministratore']);
    $user->givePermissionTo([
        Permission::VIEW_COMUNICAZIONI->value,
        Permission::EDIT_COMUNICAZIONI->value,
        Permission::ACCESS_ADMIN_PANEL->value,
    ]);

    return $user;
}

/** I dati del modulo di modifica, con la platea e la casella indicate. */
function datiDiModifica(Comunicazione $c, Condominio $condominio, array $anagrafiche, bool $avvisa = false): array
{
    return [
        'subject'            => $c->subject,
        'description'        => $c->description,
        'priority'           => $c->priority,
        'can_comment'        => $c->can_comment,
        'is_featured'        => $c->is_featured,
        'is_published'       => $c->is_published,
        'is_approved'        => $c->is_approved,
        'created_by'         => $c->created_by,
        'condomini_ids'      => [$condominio->id],
        'anagrafiche'        => collect($anagrafiche)->pluck('id')->all(),
        'avvisa_destinatari' => $avvisa,
    ];
}

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Notification::fake();

    $this->condominio = Condominio::factory()->create();
    $this->admin = amministratoreComunicazioni();

    $this->giaDestinatario = destinatarioReale($this->condominio, 'Rossi Mario');
    $this->nuovo = destinatarioReale($this->condominio, 'Bianchi Anna');

    $this->comunicazione = comunicazionePubblicata($this->condominio, $this->admin, [$this->giaDestinatario]);
});

it("chi viene aggiunto in modifica riceve la comunicazione, che per lui è nuova", function () {
    // ⚠️ **È il difetto.** Prima della correzione qui non partiva niente: `update()` sincronizzava
    // le pivot e non lanciava nessun evento. Bianchi Anna risultava destinataria in archivio e non
    // aveva mai ricevuto una riga.
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario, $this->nuovo])
    );

    Notification::assertSentTo($this->nuovo, NewComunicazioneNotification::class);
});

it("chi era già destinatario non riceve un secondo «nuova comunicazione»", function () {
    // La controprova, e non è un di più: una correzione che avvisasse tutti a ogni salvataggio
    // sarebbe peggiore del difetto che cura, perché arriverebbe come posta indesiderata.
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario, $this->nuovo])
    );

    Notification::assertNotSentTo($this->giaDestinatario, NewComunicazioneNotification::class);
});

it('senza la casella, chi c\'era già non riceve niente del tutto', function () {
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario], avvisa: false)
    );

    Notification::assertNotSentTo($this->giaDestinatario, UpdatedComunicazioneNotification::class);
    Notification::assertNotSentTo($this->giaDestinatario, NewComunicazioneNotification::class);
});

it('con la casella spuntata, chi c\'era già riceve l\'avviso di MODIFICA', function () {
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario], avvisa: true)
    );

    Notification::assertSentTo($this->giaDestinatario, UpdatedComunicazioneNotification::class);
});

it("e il nuovo arrivato riceve comunque «nuova», non «aggiornata», anche con la casella spuntata", function () {
    // ⚠️ È la ragione per cui le due notifiche sono due classi e non un parametro. Dire «questa
    // comunicazione è cambiata» a chi non l'ha mai vista non significa niente: lui non sa
    // rispetto a cosa.
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario, $this->nuovo], avvisa: true)
    );

    Notification::assertSentTo($this->nuovo, NewComunicazioneNotification::class);
    Notification::assertNotSentTo($this->nuovo, UpdatedComunicazioneNotification::class);
});

it('chi viene tolto dalla platea non riceve niente', function () {
    // Togliere qualcuno è una decisione dell'amministratore, e non ha un avviso: non gli si manda
    // una mail per dirgli che non lo riguarda più.
    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->nuovo], avvisa: true)
    );

    Notification::assertNothingSentTo($this->giaDestinatario);
});

it('chi spegne solo «aggiornate» continua a ricevere le nuove', function () {
    // ⚠️ **È la prova che dà senso alla separazione delle due preferenze**, chiesta il 22/08/2026.
    // Prima l'avviso di modifica viaggiava sulla preferenza delle comunicazioni *nuove*: chi voleva
    // sapere delle nuove si trovava anche ogni correzione, e l'unico modo di non riceverle era
    // spegnere tutto. Sono due cose diverse — una è un fatto nuovo, l'altra una correzione a
    // qualcosa che hai già letto — e da qui in avanti si scelgono separatamente.
    DB::table('notification_preferences')
        ->where('user_id', $this->giaDestinatario->user_id)
        ->where('type', NotificationType::UPDATED_COMMUNICATION->value)
        ->update(['enabled' => false]);

    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario, $this->nuovo], avvisa: true)
    );

    // A lui l'avviso di modifica non arriva…
    Notification::assertNotSentTo($this->giaDestinatario, UpdatedComunicazioneNotification::class);

    // …ma la preferenza sulle nuove è rimasta accesa, e il nuovo arrivato riceve la sua.
    Notification::assertSentTo($this->nuovo, NewComunicazioneNotification::class);
});

it("e chi spegne solo «nuove» non riceve la comunicazione da neo-destinatario", function () {
    // La simmetria, che vale la pena presidiare: le due preferenze si spengono in modo
    // indipendente e nessuna delle due tira giù l'altra.
    DB::table('notification_preferences')
        ->where('user_id', $this->nuovo->user_id)
        ->where('type', NotificationType::NEW_COMMUNICATION->value)
        ->update(['enabled' => false]);

    $this->actingAs($this->admin)->put(
        route('admin.comunicazioni.update', ['comunicazione' => $this->comunicazione->id]),
        datiDiModifica($this->comunicazione, $this->condominio, [$this->giaDestinatario, $this->nuovo], avvisa: true)
    );

    Notification::assertNotSentTo($this->nuovo, NewComunicazioneNotification::class);
    Notification::assertSentTo($this->giaDestinatario, UpdatedComunicazioneNotification::class);
});
