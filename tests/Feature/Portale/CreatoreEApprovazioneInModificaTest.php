<?php

/**
 * # La modifica non riscrive l'autore e non cambia l'approvazione (Coda 303), e senza il campo
 * # `anagrafiche` non tocca i destinatari (Coda 307)
 *
 * ## Le regole
 *
 * - **`created_by` si scrive solo in creazione.** È il dato che dice chi ha aperto la segnalazione,
 *   la comunicazione o il documento: le pagine lo mostrano («Inviata da …»), gli avvisi dei commenti
 *   di una privata lo leggono, `EDIT_OWN_*` lo confronta. Chi modifica ha già il suo campo,
 *   `updated_by`: la richiesta di modifica non porta `created_by`, e aprire e salvare senza toccare
 *   niente lascia l'autore com'è. Sei porte, tre del pannello e tre del portale (con un ruolo su
 *   misura).
 * - **La modifica non approva e non toglie l'approvazione** (decisione 127.2 del 26/10/2026):
 *   `is_approved` si calcola alla creazione dal permesso di chi crea, e dopo cambia solo
 *   dall'interruttore di approvazione, che avvisa il creatore. Dal portale, dove anche
 *   `is_published` si calcola dal permesso e non arriva dal modulo, la modifica lascia com'è anche
 *   quello.
 * - **Senza il campo `anagrafiche` la modifica non tocca i destinatari** (Coda 307): è la regola già
 *   scelta per le categorie dei documenti («senza campo = non toccare»). Con il campo vuoto i
 *   destinatari si tolgono, con un elenco si sostituiscono.
 *
 * I dati già salvati restano come sono (decisione 127.3).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(Role::COLLABORATORE->value);

    $this->mario = creatoreResidente($this->condominio);
    $this->luigi = creatoreResidente($this->condominio);
});

function creatoreResidente(Condominio $condominio, array $permessiInPiu = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessiInPiu !== []) {
        $user->givePermissionTo($permessiInPiu);
    }
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

/** Un ruolo su misura con l'accesso al pannello e la modifica, ma senza il permesso di pubblicare. */
function creatoreRedattoreSenzaPubblicare(array $permessi): User
{
    SpatieRole::firstOrCreate(['name' => 'redattore-di-prova']);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('redattore-di-prova');
    $user->givePermissionTo(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return $user->fresh();
}

function creatoreSegnalazione(Condominio $condominio, User $autore, bool $approvata = true): Segnalazione
{
    return Segnalazione::create([
        'subject'       => 'Citofono guasto',
        'description'   => 'Non suona al terzo piano',
        'priority'      => 'media',
        'stato'         => 'aperta',
        'condominio_id' => $condominio->id,
        'created_by'    => $autore->id,
        'is_private'    => false,
        'is_published'  => $approvata,
        'is_approved'   => $approvata,
        'is_featured'   => false,
        'can_comment'   => false,
    ]);
}

function creatoreModuloSegnalazione(Segnalazione $s, bool $pubblica): array
{
    return [
        'subject'            => $s->subject,
        'description'        => $s->description,
        'priority'           => $s->priority,
        'stato'              => $s->stato,
        'condominio_id'      => $s->condominio_id,
        'can_comment'        => false,
        'is_featured'        => false,
        'is_published'       => $pubblica,
        'anagrafiche'        => $s->anagrafiche()->pluck('anagrafiche.id')->all(),
        'avvisa_destinatari' => false,
    ];
}

function creatoreComunicazione(Condominio $condominio, User $autore, bool $approvata = true, array $anagrafiche = []): Comunicazione
{
    $c = Comunicazione::create([
        'subject'      => 'Pulizia scale',
        'description'  => 'Martedì mattina',
        'priority'     => 'bassa',
        'created_by'   => $autore->id,
        'is_featured'  => false,
        'is_private'   => false,
        'is_published' => $approvata,
        'is_approved'  => $approvata,
        'can_comment'  => false,
    ]);
    $c->condomini()->attach($condominio->id);
    if ($anagrafiche !== []) {
        $c->anagrafiche()->attach($anagrafiche);
    }

    return $c->fresh();
}

function creatoreModuloComunicazione(Comunicazione $c, bool $pubblica, ?array $anagrafiche = null): array
{
    $dati = [
        'subject'            => $c->subject,
        'description'        => $c->description,
        'priority'           => $c->priority,
        'can_comment'        => false,
        'is_featured'        => false,
        'is_published'       => $pubblica,
        'condomini_ids'      => $c->condomini()->pluck('condomini.id')->all(),
        'avvisa_destinatari' => false,
    ];
    if ($anagrafiche !== null) {
        $dati['anagrafiche'] = $anagrafiche;
    }

    return $dati;
}

function creatoreDocumento(Condominio $condominio, User $autore, bool $approvato = true, array $anagrafiche = []): Documento
{
    $d = Documento::create([
        'name'         => 'Verbale di assemblea',
        'description'  => 'Assemblea ordinaria',
        'created_by'   => $autore->id,
        'is_published' => $approvato,
        'is_approved'  => $approvato,
        'path'         => 'documenti/prova.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
    ]);
    $d->condomini()->attach($condominio->id);
    if ($anagrafiche !== []) {
        $d->anagrafiche()->attach($anagrafiche);
    }

    return $d->fresh();
}

// ─── Coda 303: l'autore resta, dal pannello ──────────────────────────────────────────────────

test('Coda 303 — la modifica dell\'amministratore non riscrive l\'autore di una comunicazione', function () {
    $c = creatoreComunicazione($this->condominio, $this->collaboratore);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, []))
        ->assertRedirect()->assertSessionHasNoErrors();

    $c->refresh();
    expect((int) $c->created_by)->toBe($this->collaboratore->id)
        ->and((int) $c->updated_by)->toBe($this->admin->id);
});

test('Coda 303 — la modifica dell\'amministratore non riscrive l\'autore di un documento', function () {
    $d = creatoreDocumento($this->condominio, $this->collaboratore);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), ['name' => 'Verbale corretto'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->name)->toBe('Verbale corretto')
        ->and((int) $d->created_by)->toBe($this->collaboratore->id)
        ->and((int) $d->updated_by)->toBe($this->admin->id);
});

test('Coda 303 — un created_by mandato nel corpo della modifica non si accetta', function () {
    $s = creatoreSegnalazione($this->condominio, $this->mario);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), creatoreModuloSegnalazione($s, true) + ['created_by' => $this->luigi->id])
        ->assertRedirect();

    expect((int) $s->fresh()->created_by)->toBe($this->mario->id);
});

// ─── Coda 303: l'approvazione non cambia in modifica (decisione 127.2) ───────────────────────

test('Coda 303 — l\'amministratore che modifica una segnalazione in moderazione non la approva', function () {
    $s = creatoreSegnalazione($this->condominio, $this->mario, approvata: false);

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.update', $s), creatoreModuloSegnalazione($s, true))
        ->assertRedirect()->assertSessionHasNoErrors();

    $s->refresh();
    expect((bool) $s->is_approved)->toBeFalse()
        ->and((int) $s->created_by)->toBe($this->mario->id);
    // E il vicino, con la segnalazione ancora in moderazione, non la apre.
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 303 — chi modifica senza il permesso di pubblicare non toglie l\'approvazione a una segnalazione', function () {
    $redattore = creatoreRedattoreSenzaPubblicare([Permission::VIEW_SEGNALAZIONI->value, Permission::EDIT_SEGNALAZIONI->value]);
    $s = creatoreSegnalazione($this->condominio, $this->mario, approvata: true);

    $this->actingAs($redattore)
        ->put(route('admin.segnalazioni.update', $s), creatoreModuloSegnalazione($s, true))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $s->fresh()->is_approved)->toBeTrue();
});

test('Coda 303 — l\'amministratore che modifica una comunicazione in moderazione non la approva', function () {
    $c = creatoreComunicazione($this->condominio, $this->mario, approvata: false);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, []))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $c->fresh()->is_approved)->toBeFalse();
});

test('Coda 303 — chi modifica senza il permesso di pubblicare non toglie l\'approvazione a una comunicazione', function () {
    $redattore = creatoreRedattoreSenzaPubblicare([Permission::VIEW_COMUNICAZIONI->value, Permission::EDIT_COMUNICAZIONI->value]);
    $c = creatoreComunicazione($this->condominio, $this->admin, approvata: true);

    $this->actingAs($redattore)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, []))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $c->fresh()->is_approved)->toBeTrue();
});

test('Coda 303 — l\'amministratore che modifica un documento in moderazione non lo approva', function () {
    $d = creatoreDocumento($this->condominio, $this->collaboratore, approvato: false);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), ['name' => 'Verbale', 'is_published' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $d->fresh()->is_approved)->toBeFalse();
});

test('Coda 303 — chi modifica senza il permesso di pubblicare non toglie l\'approvazione a un documento', function () {
    $redattore = creatoreRedattoreSenzaPubblicare([Permission::VIEW_ARCHIVE_DOCUMENTS->value, Permission::EDIT_ARCHIVE_DOCUMENTS->value]);
    $d = creatoreDocumento($this->condominio, $this->admin, approvato: true);

    $this->actingAs($redattore)
        ->put(route('admin.documenti.update', $d), ['name' => 'Verbale', 'is_published' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect((bool) $d->fresh()->is_approved)->toBeTrue();
});

test('Coda 303 — la creazione calcola ancora l\'approvazione dal permesso di chi crea', function () {
    // La guardia della modifica non deve spegnere il calcolo della creazione.
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject'       => 'Luce scale',
        'description'   => 'Spenta',
        'priority'      => 'bassa',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'can_comment'   => false,
        'is_featured'   => false,
        'is_published'  => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $s = Segnalazione::latest('id')->firstOrFail();
    expect((bool) $s->is_approved)->toBeTrue()
        ->and((int) $s->created_by)->toBe($this->admin->id);

    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject'       => 'Portone',
        'description'   => 'Non chiude',
        'priority'      => 'media',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'is_private'    => false,
    ])->assertRedirect();

    $t = Segnalazione::latest('id')->firstOrFail();
    expect((bool) $t->is_approved)->toBeFalse()
        ->and((bool) $t->is_published)->toBeFalse()
        ->and((int) $t->created_by)->toBe($this->mario->id);
});

// ─── Coda 303: le tre porte del portale, con un ruolo su misura ──────────────────────────────

test('Coda 303 — dal portale, chi modifica la segnalazione di un altro non ne diventa l\'autore e non la ritira', function () {
    $redattore = creatoreResidente($this->condominio, [Permission::EDIT_SEGNALAZIONI->value]);
    $s = creatoreSegnalazione($this->condominio, $this->mario, approvata: true);

    $this->actingAs($redattore)->put(route('user.segnalazioni.update', $s), [
        'subject'       => 'Citofono guasto (corretto)',
        'description'   => $s->description,
        'priority'      => $s->priority,
        'stato'         => $s->stato,
        'condominio_id' => $s->condominio_id,
        'is_private'    => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $s->refresh();
    expect($s->subject)->toBe('Citofono guasto (corretto)')
        ->and((int) $s->created_by)->toBe($this->mario->id)
        ->and((bool) $s->is_approved)->toBeTrue()
        ->and((bool) $s->is_published)->toBeTrue();
});

test('Coda 303 — dal portale, chi modifica la comunicazione di un altro non ne diventa l\'autore e non la ritira', function () {
    $redattore = creatoreResidente($this->condominio, [Permission::EDIT_COMUNICAZIONI->value]);
    $c = creatoreComunicazione($this->condominio, $this->mario, approvata: true);

    $this->actingAs($redattore)->put(route('user.comunicazioni.update', $c), [
        'subject'       => 'Pulizia scale (corretto)',
        'description'   => $c->description,
        'priority'      => $c->priority,
        'is_featured'   => false,
        'is_private'    => false,
        'condomini_ids' => [$this->condominio->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->subject)->toBe('Pulizia scale (corretto)')
        ->and((int) $c->created_by)->toBe($this->mario->id)
        ->and((bool) $c->is_approved)->toBeTrue()
        ->and((bool) $c->is_published)->toBeTrue();
});

test('Coda 303 — dal portale, chi modifica il documento di un altro non ne diventa l\'autore e non lo ritira', function () {
    $redattore = creatoreResidente($this->condominio, [Permission::EDIT_ARCHIVE_DOCUMENTS->value]);
    $d = creatoreDocumento($this->condominio, $this->mario, approvato: true);

    $this->actingAs($redattore)->put(route('user.documenti.update', $d), [
        'name' => 'Verbale (corretto)',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->name)->toBe('Verbale (corretto)')
        ->and((int) $d->created_by)->toBe($this->mario->id)
        ->and((bool) $d->is_approved)->toBeTrue()
        ->and((bool) $d->is_published)->toBeTrue();
});

// ─── Coda 307: senza il campo, i destinatari non si toccano ──────────────────────────────────

test('Coda 307 — una comunicazione modificata senza il campo anagrafiche tiene i destinatari', function () {
    $c = creatoreComunicazione($this->condominio, $this->admin, anagrafiche: [$this->mario->anagrafica->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, null))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);
});

test('Coda 307 — con il campo anagrafiche vuoto o pieno la comunicazione li toglie o li sostituisce', function () {
    $c = creatoreComunicazione($this->condominio, $this->admin, anagrafiche: [$this->mario->anagrafica->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, [$this->luigi->anagrafica->id]))
        ->assertSessionHasNoErrors();
    expect($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->luigi->anagrafica->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $c), creatoreModuloComunicazione($c, true, []))
        ->assertSessionHasNoErrors();
    expect($c->fresh()->anagrafiche()->count())->toBe(0);
});

test('Coda 307 — un documento modificato senza il campo anagrafiche tiene i destinatari', function () {
    $d = creatoreDocumento($this->condominio, $this->admin, anagrafiche: [$this->mario->anagrafica->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), ['name' => 'Verbale'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($d->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.documenti.update', $d), ['name' => 'Verbale', 'anagrafiche' => []])
        ->assertSessionHasNoErrors();
    expect($d->fresh()->anagrafiche()->count())->toBe(0);
});

test('Coda 307 — una segnalazione non privata modificata con il campo pieno o vuoto sostituisce o toglie i destinatari', function () {
    $s = creatoreSegnalazione($this->condominio, $this->admin);
    $s->anagrafiche()->attach($this->mario->anagrafica->id);

    $dati = creatoreModuloSegnalazione($s, true);
    $dati['anagrafiche'] = [$this->luigi->anagrafica->id];
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), $dati)->assertSessionHasNoErrors();
    expect($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->luigi->anagrafica->id]);

    $dati['anagrafiche'] = [];
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), $dati)->assertSessionHasNoErrors();
    expect($s->fresh()->anagrafiche()->count())->toBe(0);
});

test('Coda 303 — dal portale, autore, approvazione e pubblicazione mandati nel corpo della modifica non si accettano', function () {
    // Senza questo caso, una regola che tornasse ad accettare `created_by` in modifica dal portale
    // non farebbe diventare rosso niente: i moduli del portale il campo non lo mandano.
    $redattore = creatoreResidente($this->condominio, [Permission::EDIT_SEGNALAZIONI->value, Permission::EDIT_COMUNICAZIONI->value]);
    $s = creatoreSegnalazione($this->condominio, $this->mario, approvata: false);
    $c = creatoreComunicazione($this->condominio, $this->mario, approvata: false);

    $this->actingAs($redattore)->put(route('user.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $s->condominio_id, 'is_private' => false,
        'created_by' => $redattore->id, 'is_approved' => true, 'is_published' => true,
    ])->assertRedirect();

    $this->actingAs($redattore)->put(route('user.comunicazioni.update', $c), [
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority, 'is_featured' => false,
        'is_private' => false, 'condomini_ids' => [$this->condominio->id],
        'created_by' => $redattore->id, 'is_approved' => true, 'is_published' => true,
    ])->assertRedirect();

    foreach ([$s->fresh(), $c->fresh()] as $oggetto) {
        expect((int) $oggetto->created_by)->toBe($this->mario->id)
            ->and((bool) $oggetto->is_approved)->toBeFalse()
            ->and((bool) $oggetto->is_published)->toBeFalse();
    }
});
