<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Chi è sospeso non riceve avvisi (Coda 694)
 *
 * Gli avvisi dell'account (comunicazioni, documenti, segnalazioni, commenti, allegati, pubblicazione programmata, assegnazione) non partono verso un utente
 * sospeso, né verso l'anagrafica di un utente sospeso; gli altri destinatari li ricevono come prima. Partono come sempre gli avvisi di sicurezza, il
 * ripristino della password e le email di accesso, e le lettere, le ricevute, i fogli e le convocazioni, che vanno all'indirizzo della persona.
 *
 * Qui gli invii sono veri (trasporto `array`, coda `sync`) e si contano con `NotificationSent`: `Notification::fake()` non fa partire l'evento
 * `NotificationSending`, che è dove la regola sta.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\User;
use App\Notifications\Allegati\NuovoAllegatoNotification;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Notifications\Comunicazioni\ApproveComunicazioneNotification;
use App\Notifications\Comunicazioni\ApprovedComunicazioneNotification;
use App\Notifications\Comunicazioni\ComunicazioneProgrammataPubblicataNotification;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Notifications\Gestionale\LetteraNotification;
use App\Notifications\Segnalazioni\ApproveSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\SegnalazioneAssegnataNotification;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../ComunicazioniProgrammate/ProgrammateScenario.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse(CPR_ADESSO, 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Storage::fake('local');

    $this->aurora = \App\Models\Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea = \App\Models\Condominio::factory()->create(['nome' => 'Condominio Borea']);
    $this->admin = nasPersona([$this->aurora->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
    // Chi nello studio approva: la collaboratrice sospesa, e Elena che lavora.
    $this->sara = nasCollaboratrice($this, 'Sara Sospesa');
    $this->elena = nasCollaboratrice($this, 'Elena Studio');
    $this->mario = nasPersona([$this->aurora->id], 'Mario Rossi');
    $this->paolo = nasPersona([$this->aurora->id], 'Paolo Gatti');
    foreach ([$this->mario, $this->paolo] as $condomino) {
        $condomino->givePermissionTo(['Crea comunicazioni', 'Crea segnalazioni']);
    }

    $this->inviate = new \ArrayObject();
    Event::listen(NotificationSent::class, function (NotificationSent $e) {
        $this->inviate->append([$e->notification::class, $e->notifiable]);
    });
});

/**
 * Una persona con l'anagrafica e un indirizzo valido: gli invii sono veri, e un indirizzo malformato fermerebbe tutto l'invio in coda `sync`. La fabbrica
 * dell'anagrafica può comporre un indirizzo con uno spazio (un cognome di due parole).
 */
function nasPersona(array $condomini, string $nome, string $ruolo = 'utente'): User
{
    $u = ccmUtente($condomini, $nome, $ruolo);
    $u->anagrafica->update(['email' => $u->email, 'email_secondaria' => null, 'pec' => null]);

    return $u->fresh();
}

function nasCollaboratrice($test, string $nome): User
{
    $u = nasPersona([$test->aurora->id], $nome, Role::COLLABORATORE->value);
    $u->givePermissionTo(['Approva comunicazioni', 'Approva segnalazioni', 'Approva commenti segnalazioni']);

    return $u->fresh();
}

function nasSospendi(User ...$utenti): void
{
    foreach ($utenti as $u) {
        $u->forceFill(['suspended_at' => now()])->save();
    }
}

/** Gli utenti (diretti o attraverso l'anagrafica) a cui è partita davvero una notifica della classe data. @return list<int> */
function nasRicevuta($test, string $classe): array
{
    $ids = [];
    foreach ($test->inviate as [$c, $chi]) {
        if ($c !== $classe) {
            continue;
        }
        $ids[] = $chi instanceof User ? (int) $chi->id : ($chi instanceof Anagrafica ? (int) $chi->user_id : 0);
    }
    sort($ids);

    return array_values(array_unique($ids));
}

/** A questi nessuna notifica della classe, e a quelli sì: la seconda metà è la prova che l'invio è partito davvero. */
function nasControlla($test, string $classe, array $sospesi, array $attivi): void
{
    $ricevuta = nasRicevuta($test, $classe);
    expect($ricevuta)->not->toBeEmpty("Nessun invio di {$classe}: la prova non prova niente");
    foreach ($sospesi as $u) {
        expect($ricevuta)->not->toContain($u->id);
    }
    foreach ($attivi as $u) {
        expect($ricevuta)->toContain($u->id);
    }
}

it('comunicazione nuova: niente al condòmino sospeso, agli altri come prima', function () {
    nasSospendi($this->paolo, $this->sara);

    cprCrea($this, $this->admin, cprModulo([$this->aurora], ['is_published' => true]))->assertRedirect()->assertSessionHasNoErrors();

    nasControlla($this, NewComunicazioneNotification::class, [$this->paolo, $this->sara], [$this->mario]);
});

it('comunicazione in attesa e approvata: niente alla collaboratrice né all\'autore sospesi', function () {
    nasSospendi($this->sara);
    $this->actingAs($this->paolo->fresh())->post(route('user.comunicazioni.store'), [
        'subject' => 'Bici in cortile', 'description' => 'Le bici bloccano il passaggio.', 'priority' => 'media', 'can_comment' => true,
        'is_private' => false, 'is_featured' => false, 'condomini_ids' => [$this->aurora->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    nasControlla($this, ApproveComunicazioneNotification::class, [$this->sara], [$this->elena]);

    nasSospendi($this->paolo);
    $c = Comunicazione::where('subject', 'Bici in cortile')->sole();
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

    expect(nasRicevuta($this, ApprovedComunicazioneNotification::class))->not->toContain($this->paolo->id);
    nasControlla($this, NewComunicazioneNotification::class, [$this->paolo], [$this->mario]);
});

it('documento nuovo: niente al sospeso', function () {
    nasSospendi($this->paolo);
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);

    $this->actingAs($this->admin)->post(route('admin.documenti.store'), [
        'name' => 'Verbale', 'description' => 'Verbale di assemblea', 'categorie' => [$categoria->id], 'is_published' => true,
        'condomini_ids' => [$this->aurora->id], 'anagrafiche' => [], 'file' => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    nasControlla($this, NewDocumentoNotification::class, [$this->paolo], [$this->mario]);
});

it('segnalazione dal portale e dal pannello: niente ai sospesi', function () {
    nasSospendi($this->sara);
    $this->actingAs($this->mario->fresh())->post(route('user.segnalazioni.store'), [
        'subject' => 'Cancello rotto', 'description' => 'Non chiude.', 'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $this->aurora->id,
        'can_comment' => true, 'is_private' => false, 'is_featured' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $perLoStudio = array_merge(nasRicevuta($this, ApproveSegnalazioneNotification::class), nasRicevuta($this, NewSegnalazioneNotification::class));
    expect($perLoStudio)->toContain($this->elena->id)->not->toContain($this->sara->id);

    nasSospendi($this->paolo);
    $this->inviate->exchangeArray([]);
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject' => 'Luce delle scale', 'description' => 'Spenta.', 'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $this->aurora->id,
        'can_comment' => true, 'is_private' => false, 'is_published' => true, 'is_featured' => false, 'anagrafiche' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    nasControlla($this, NewSegnalazioneNotification::class, [$this->paolo], [$this->mario]);
});

it('commento nuovo: chi segue la discussione ed è sospeso non lo riceve', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);
    ccmCommentoSalvato($c, $this->paolo, 'Io ci sono', $this->aurora);
    nasSospendi($this->paolo);

    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Anch\'io')->assertRedirect()->assertSessionHasNoErrors();

    nasControlla($this, NuovoCommentoNotification::class, [$this->paolo], [$this->admin]);
});

it('allegato nuovo su una segnalazione: niente all\'autore sospeso', function () {
    $s = ccmSegnalazione($this->aurora, $this->paolo);
    nasSospendi($this->paolo);

    $this->actingAs($this->admin)->post(route('admin.segnalazioni.allegati.store', $s), [
        'allegati' => [UploadedFile::fake()->create('preventivo.pdf', 12, 'application/pdf')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(nasRicevuta($this, NuovoAllegatoNotification::class))->not->toContain($this->paolo->id);
    // La prova che l'invio è vero: con l'autore attivo lo riceve.
    $this->paolo->forceFill(['suspended_at' => null])->save();
    $this->inviate->exchangeArray([]);
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.allegati.store', $s), [
        'allegati' => [UploadedFile::fake()->create('foto.pdf', 12, 'application/pdf')],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(nasRicevuta($this, NuovoAllegatoNotification::class))->toContain($this->paolo->id);
});

it('pubblicazione programmata: la conferma non va a chi l\'ha programmata ed è sospeso, i destinatari attivi la ricevono', function () {
    cprPreferenza($this->sara, CPR_TIPO_CONFERMA, true);
    cprPreferenza($this->elena, CPR_TIPO_CONFERMA, true);
    cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:59:00', [], $this->sara);
    cprProgrammata([$this->aurora], $this->admin, '2026-11-12 09:59:00', ['subject' => 'Seconda'], $this->elena);
    nasSospendi($this->sara, $this->paolo);

    cprPubblica($this);

    nasControlla($this, ComunicazioneProgrammataPubblicataNotification::class, [$this->sara], [$this->elena]);
    nasControlla($this, NewComunicazioneNotification::class, [$this->paolo], [$this->mario]);
});

it('assegnazione: l\'email «assegnata a te» in coda non parte se chi la riceve è stato sospeso nel frattempo', function () {
    nasSospendi($this->sara);
    foreach ([$this->sara, $this->elena] as $chi) {
        // La notifica rilegge a chi è assegnata la segnalazione: la si assegna dal database prima di mandarla.
        $s = ccmSegnalazione($this->aurora, $this->mario, ['subject' => "Guasto di {$chi->name}"]);
        \Illuminate\Support\Facades\DB::table('segnalazioni')->where('id', $s->id)->update(['assigned_to' => $chi->id]);
        $chi->fresh()->notify(new SegnalazioneAssegnataNotification($s->fresh(), 'Studio Bianchi'));
    }

    nasControlla($this, SegnalazioneAssegnataNotification::class, [$this->sara], [$this->elena]);
});

it('il caso dei venti: a venti destinatari di cui dieci sospesi partono dieci email, e nessun compito', function () {
    $ventuno = \App\Models\Condominio::factory()->create(['nome' => 'Condominio dei venti']);
    $venti = collect(range(1, 20))->map(fn (int $n) => nasPersona([$ventuno->id], "Persona {$n}"));
    nasSospendi(...$venti->take(10)->all());
    $compitiPrima = \App\Models\Evento::count();

    cprCrea($this, $this->admin, cprModulo([$ventuno], ['is_published' => true]))->assertRedirect()->assertSessionHasNoErrors();

    $ai = array_values(array_intersect(nasRicevuta($this, NewComunicazioneNotification::class), $venti->pluck('id')->all()));
    expect($ai)->toHaveCount(10)->and($ai)->toEqualCanonicalizing($venti->skip(10)->pluck('id')->all())
        ->and(\App\Models\Evento::count())->toBe($compitiPrima);
});

it('partono come sempre gli avvisi di sicurezza, il ripristino della password e le email di accesso', function () {
    nasSospendi($this->paolo);
    $paolo = $this->paolo->fresh();

    app(AvvisiDiSicurezza::class)->avvisa($paolo, AvvisiDiSicurezza::PASSWORD_CAMBIATA);
    $paolo->sendPasswordResetNotification('gettone-di-prova');
    $paolo->sendEmailVerificationNotification();
    $paolo->notify(new \App\Notifications\NewUserEmailNotification($paolo));

    foreach ([AvvisoDiSicurezzaNotification::class, \App\Notifications\CustomResetPasswordNotification::class,
        \App\Notifications\CustomVerifyEmailNotification::class, \App\Notifications\NewUserEmailNotification::class] as $classe) {
        expect(nasRicevuta($this, $classe))->toContain($paolo->id);
    }
});

it('le lettere e ciò che va all\'indirizzo della persona partono lo stesso', function () {
    nasSospendi($this->paolo);
    $lettera = new LetteraNotification([]);

    // All'indirizzo (come le manda `EmissioneLettere`) e anche, per sicurezza, se un giorno andassero all'anagrafica.
    foreach ([Notification::route('mail', $this->paolo->anagrafica->email), $this->paolo->anagrafica, $this->paolo->fresh()] as $chi) {
        expect(Event::until(new NotificationSending($chi, $lettera, 'mail')))->not->toBeFalse();
    }
    expect(Notification::route('mail', 'x@esempio.invalid'))->toBeInstanceOf(AnonymousNotifiable::class);
});

it('un\'anagrafica senza account riceve, e chi viene riattivato torna a ricevere', function () {
    $senzaAccount = Anagrafica::factory()->create(['user_id' => null]);
    $avviso = new NewComunicazioneNotification(ccmComunicazione([$this->aurora], $this->admin));

    expect(Event::until(new NotificationSending($senzaAccount, $avviso, 'mail')))->not->toBeFalse();

    nasSospendi($this->paolo);
    expect(Event::until(new NotificationSending($this->paolo->anagrafica->fresh(), $avviso, 'mail')))->toBeFalse();

    $this->paolo->forceFill(['suspended_at' => null])->save();
    expect(Event::until(new NotificationSending($this->paolo->anagrafica->fresh(), $avviso, 'mail')))->not->toBeFalse();
});
