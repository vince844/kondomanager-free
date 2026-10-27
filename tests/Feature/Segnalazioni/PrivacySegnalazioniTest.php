<?php

/**
 * # La privacy delle segnalazioni, provata dalle rotte vere (Code 302–307 e 263)
 *
 * Undici casi provano che una segnalazione privata resta privata, con le richieste che
 * mandano le pagine, dal portale del condòmino e dal pannello dell'amministratore: la
 * scheda, l'elenco, i commenti, gli avvisi e le etichette. Ogni caso dice nel nome la Coda
 * che prova. Personaggi: Mario e Luigi, ruolo `utente`, stesso condominio; un
 * amministratore.
 *
 * La regola che li tiene insieme è la decisione Q1 del 17/10/2026: **la privacy la decide
 * `is_private`**; i destinatari collegati dicono solo a chi è indirizzata (elenco, scheda,
 * commenti, avvisi, etichette).
 *
 * Un caso è cambiato rispetto alla prima stesura, per la decisione 127.4 del 26/10/2026: togliere
 * i residenti di una privata dal modulo dell'amministratore non si può più (la richiesta lo
 * rifiuta con un errore sul campo); la privata con i residenti già tolti, dato che può già esistere
 * nel database, è provata in `tests/Feature/Portale/VisibilitaNelPortaleTest.php`.
 *
 * Non copre il frontend (le etichette si provano con vitest, `resources/js/lib/segnalazioni/`).
 */

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Models\Anagrafica;
use App\Models\Commento;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->mario = privacyResidente($this->condominio);   // chi apre la segnalazione privata
    $this->luigi = privacyResidente($this->condominio);   // il vicino dello stesso palazzo
});

function privacyResidente(Condominio $condominio): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

/** Mario apre una segnalazione privata dal portale, come farebbe dal modulo. */
function privacyPrivataDiMario($test): Segnalazione
{
    $test->actingAs($test->mario)->post(route('user.segnalazioni.store'), [
        'subject'       => 'Perdita in bagno',
        'description'   => 'Dettagli che riguardano solo me',
        'priority'      => 'media',
        'stato'         => 'aperta',
        'condominio_id' => $test->condominio->id,
        'is_private'    => true,
    ])->assertRedirect();

    return Segnalazione::latest('id')->firstOrFail();
}

/** Quello che manda il modulo di modifica dell'amministratore (SegnalazioniEdit.vue). */
function privacyModuloModifica(Segnalazione $s, ?array $anagrafiche, bool $avvisa = false): array
{
    $dati = [
        'subject'            => $s->subject,
        'description'        => $s->description,
        'priority'           => $s->priority,
        'stato'              => 'in lavorazione',
        'condominio_id'      => $s->condominio_id,
        'can_comment'        => true,
        'is_featured'        => false,
        'is_published'       => true,
        'avvisa_destinatari' => $avvisa,
    ];

    if ($anagrafiche !== null) {
        $dati['anagrafiche'] = $anagrafiche;
    }

    return $dati;
}

function privacyIdInElenco($test, User $user): array
{
    $props = $test->actingAs($user)->get(route('user.segnalazioni.index'))
        ->assertOk()->viewData('page')['props'];

    return collect($props['segnalazioni']['data'])->pluck('id')->all();
}

// ─── L'approvazione non rende pubblica una privata ───────────────────────────────────────────

test('Coda 304 — dopo l\'approvazione la privata resta privata e il vicino non la vede', function () {
    $s = privacyPrivataDiMario($this);

    expect((bool) $s->is_private)->toBeTrue()
        ->and((bool) $s->is_published)->toBeFalse()
        ->and($s->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();
    $s->refresh();

    // Il dato non cambia: is_private resta vero, cambia solo la pubblicazione.
    expect((bool) $s->is_private)->toBeTrue()
        ->and((bool) $s->is_published)->toBeTrue()
        ->and((bool) $s->is_approved)->toBeTrue();

    // Il vicino non la vede né in elenco né per indirizzo diretto; Mario sì.
    expect(privacyIdInElenco($this, $this->luigi))->not->toContain($s->id);
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    expect(privacyIdInElenco($this, $this->mario))->toContain($s->id);
    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();

    // L'avviso di approvazione non parte verso il vicino.
    Notification::assertNothingSentTo($this->luigi);
});

test('Coda 306 — la scheda dell\'amministratore riceve is_published e is_private come due dati distinti', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));

    $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $s))
        ->assertOk()->viewData('page')['props'];
    $dati = $props['segnalazione']['data'] ?? $props['segnalazione'];

    // L'etichetta della privacy si legge da is_private, quella della pubblicazione da
    // is_published: qui sono vere tutte e due, e la pagina deve poterle dire tutte e due.
    expect((bool) $dati['is_private'])->toBeTrue()
        ->and((bool) $dati['is_published'])->toBeTrue();
});

// ─── La modifica dell'amministratore non cambia il creatore ──────────────────────────────────

test('Coda 303 — modificare la segnalazione non cambia chi l\'ha creata', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, [$this->mario->anagrafica->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($s->fresh()->created_by)->toBe($this->mario->id);
});

test('Coda 303 — dopo la modifica Mario non perde l\'accesso ai commenti della sua privata', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, [$this->mario->anagrafica->id]));

    // È il filtro che CommentoNotificationService usa per decidere chi avvisare delle risposte.
    expect($s->fresh()->utenteHaAccessoAiCommenti($this->mario))->toBeTrue();
});

// ─── I residenti di una privata ──────────────────────────────────────────────────────────────

test('Coda 304 — i residenti di una privata non si tolgono dal modulo, e il vicino continua a non vederla', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));

    // Decisione 127.4: la richiesta rifiuta, con l'errore sul campo; la privata si rende pubblica
    // solo con il comando apposito.
    $this->actingAs($this->admin)
        ->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, []))
        ->assertRedirect(route('admin.segnalazioni.edit', $s))
        ->assertSessionHasErrors('anagrafiche');
    $s->refresh();

    expect((bool) $s->is_private)->toBeTrue()
        ->and($s->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);

    expect(privacyIdInElenco($this, $this->luigi))->not->toContain($s->id);
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 307 — senza il campo anagrafiche la modifica si salva e non tocca i destinatari', function () {
    $s = privacyPrivataDiMario($this);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, null))
        ->assertRedirect();
    $s->refresh();

    expect($s->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);
    // E la modifica è stata salvata, non annullata in silenzio.
    expect($s->stato)->toBe('in lavorazione');
});

// ─── Commenti e avvisi ───────────────────────────────────────────────────────────────────────

test('Coda 305 — il vicino non può commentare la privata di Mario, che non può vedere', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));
    $s->update(['can_comment' => true]);

    $this->actingAs($this->luigi)
        ->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Ciao, ho visto la tua segnalazione'])
        ->assertForbidden();

    expect(Commento::where('user_id', $this->luigi->id)->count())->toBe(0);
});

test('Coda 302 — «avvisa i destinatari» su una privata non manda l\'avviso a tutto il palazzo', function () {
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));

    Event::fake([DestinatariDaAvvisare::class]);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, [$this->mario->anagrafica->id], avvisa: true))
        ->assertRedirect();

    $luigiId = $this->luigi->anagrafica->id;
    Event::assertNotDispatched(DestinatariDaAvvisare::class,
        fn (DestinatariDaAvvisare $e) => in_array($luigiId, $e->anagraficaIds, true));
});

test('Coda 302 — spostare la privata in un altro palazzo non la annuncia ai nuovi condòmini', function () {
    $s = privacyPrivataDiMario($this);
    $altro = Condominio::factory()->create();
    $estraneo = privacyResidente($altro);

    Event::fake([DestinatariDaAvvisare::class]);

    $dati = privacyModuloModifica($s, [$this->mario->anagrafica->id]);
    $dati['condominio_id'] = $altro->id;
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), $dati)->assertRedirect();

    $id = $estraneo->anagrafica->id;
    Event::assertNotDispatched(DestinatariDaAvvisare::class,
        fn (DestinatariDaAvvisare $e) => in_array($id, $e->anagraficaIds, true));
});

test('Coda 263 — una segnalazione pubblica ancora in moderazione non si apre per indirizzo diretto', function () {
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject'       => 'Testo non ancora moderato',
        'description'   => 'In attesa dell\'amministratore',
        'priority'      => 'media',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'is_private'    => false,
    ]);
    $s = Segnalazione::latest('id')->firstOrFail();

    expect((bool) $s->is_approved)->toBeFalse();
    expect(privacyIdInElenco($this, $this->luigi))->not->toContain($s->id);
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 302 — il vicino non riceve per mail l\'avviso di modifica della privata di Mario', function () {
    // Le notifiche sono a consenso: Luigi ha acceso «segnalazione modificata» nelle preferenze, così
    // il «non riceve» non passa per la ragione sbagliata.
    $this->luigi->notificationPreferences()->create([
        'type' => NotificationType::UPDATED_TICKET->value, 'enabled' => true,
    ]);
    $s = privacyPrivataDiMario($this);
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s));

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), privacyModuloModifica($s, [$this->mario->anagrafica->id], avvisa: true))
        ->assertRedirect();

    Notification::assertNotSentTo($this->luigi->anagrafica, UpdatedSegnalazioneNotification::class);
});
