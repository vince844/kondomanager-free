<?php

/**
 * # I residenti di una segnalazione privata nel modulo dell'amministratore (Coda 304)
 *
 * Decisione 127.4: i residenti di una privata non si svuotano dal modulo; per renderla visibile a
 * tutto il condominio c'è «Rendi pubblica», con la conferma. Due precisazioni, provate qui dalla rotta:
 *
 * - **la regola guarda il cambiamento**: l'errore scatta solo se in questo salvataggio i residenti
 *   passano da almeno uno a nessuno. Una privata che i residenti non li aveva già (un dato che può
 *   esistere nel database) si apre e si salva com'è, per cambiarne lo stato o la priorità; resta
 *   privata e la vede il suo autore.
 * - **il messaggio dice una strada che chi salva ha**: a chi può usare «Rendi pubblica» (chi approva
 *   le segnalazioni) indica il comando; al collaboratore di serie, che non lo ha, dice chi lo può fare.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(Role::COLLABORATORE->value);

    $this->mario = residentiPrivataResidente($this->condominio, 'Mario Rossi');
    $this->luigi = residentiPrivataResidente($this->condominio, 'Luigi Verdi');
});

function residentiPrivataResidente(Condominio $condominio, string $nome): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

/** Una privata «scomoda»: testo con apostrofi e virgolette, su più righe, in bozza, in evidenza, senza commenti. */
function residentiPrivata(Condominio $condominio, User $autore, array $collegate): Segnalazione
{
    $s = Segnalazione::create([
        'subject' => "Muffa nell'angolo della camera «nord»", 'description' => "Prima riga\nseconda riga — con l'apostrofo",
        'priority' => 'urgente', 'stato' => 'in lavorazione', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => true, 'is_published' => false, 'is_approved' => true, 'is_featured' => true, 'can_comment' => false,
    ]);
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

/**
 * Il corpo che manda `resources/js/pages/segnalazioni/SegnalazioniEdit.vue`, costruito dalle props che
 * la pagina riceve (`useForm` alle righe 74-87), con i cambi indicati.
 */
function residentiModulo($test, User $chi, Segnalazione $s, array $cambi = []): array
{
    $p = $test->actingAs($chi)->get(route('admin.segnalazioni.edit', $s))->assertOk()->viewData('page')['props']['segnalazione'];
    $p = $p['data'] ?? $p;

    return array_merge([
        'subject'            => $p['subject'],
        'description'        => $p['description'],
        'priority'           => $p['priority'],
        'stato'              => $p['stato'],
        'condominio_id'      => $p['condominio']['option']['value'],
        'can_comment'        => (bool) $p['can_comment'],
        'is_featured'        => (bool) $p['is_featured'],
        'is_published'       => (bool) $p['is_published'],
        'anagrafiche'        => collect($p['anagrafiche'])->pluck('id')->all(),
        'avvisa_destinatari' => false,
    ], $cambi);
}

function residentiCampi(Segnalazione $s): array
{
    return $s->fresh()->only(['subject', 'description', 'priority', 'stato', 'condominio_id', 'created_by',
        'is_private', 'is_published', 'is_approved', 'is_featured', 'can_comment']);
}

// ─── La regola guarda il cambiamento ────────────────────────────────────────────────────────

test('Coda 304 — una privata già senza residenti, aperta e salvata senza toccare niente, si salva e non cambia', function () {
    $s = residentiPrivata($this->condominio, $this->mario, []);
    $prima = residentiCampi($s);

    $corpo = residentiModulo($this, $this->admin, $s);
    expect($corpo['anagrafiche'])->toBe([]);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), $corpo)
        ->assertRedirect(route('admin.segnalazioni.index'))->assertSessionHasNoErrors();

    expect(residentiCampi($s))->toEqual($prima)
        ->and($s->fresh()->anagrafiche()->count())->toBe(0);
});

test('Coda 304 — una privata già senza residenti si salva cambiando solo lo stato, e resta del solo autore', function () {
    $s = residentiPrivata($this->condominio, $this->mario, []);
    $s->update(['is_published' => true]);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->admin, $s, ['stato' => 'chiusa']))
        ->assertSessionHasNoErrors();

    expect($s->fresh()->stato)->toBe('chiusa')
        ->and((bool) $s->fresh()->is_private)->toBeTrue();
    $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s))->assertOk();
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — una privata con i residenti, aperta e salvata senza toccare niente, si salva e non cambia', function () {
    $s = residentiPrivata($this->condominio, $this->mario, [$this->mario]);
    $prima = residentiCampi($s);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->admin, $s))
        ->assertSessionHasNoErrors();

    expect(residentiCampi($s))->toEqual($prima)
        ->and($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);
});

test('Coda 304 — i residenti di una privata che ne ha non si tolgono tutti dal modulo', function () {
    // La guardia della decisione 127.4 resta: il cambiamento da pieni a vuoti si rifiuta.
    $s = residentiPrivata($this->condominio, $this->mario, [$this->mario]);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->admin, $s, ['anagrafiche' => []]))
        ->assertSessionHasErrors('anagrafiche');

    expect($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);
});

test('Coda 304 — a una privata già senza residenti se ne aggiunge uno dal modulo', function () {
    $s = residentiPrivata($this->condominio, $this->mario, []);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->admin, $s, ['anagrafiche' => [$this->mario->anagrafica->id]]))
        ->assertSessionHasNoErrors();

    expect($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->mario->anagrafica->id]);
});

// ─── Il messaggio indica una strada che chi salva ha ────────────────────────────────────────

test('Coda 304 — il collaboratore che toglie i residenti di una privata legge chi la può rendere pubblica, non «usa Rendi pubblica»', function () {
    $s = residentiPrivata($this->condominio, $this->mario, [$this->mario]);

    $risposta = $this->actingAs($this->collaboratore)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->collaboratore, $s, ['anagrafiche' => []]));

    $risposta->assertSessionHasErrors('anagrafiche');
    $messaggio = session('errors')->first('anagrafiche');
    expect($messaggio)->toContain('chi approva le segnalazioni')
        ->not->toContain('usa «Rendi pubblica»');

    // Ed è vero: il comando a lui è negato.
    $this->actingAs($this->collaboratore)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertForbidden();
});

test('Coda 304 — l\'amministratore che toglie i residenti di una privata legge di usare «Rendi pubblica»', function () {
    $s = residentiPrivata($this->condominio, $this->mario, [$this->mario]);

    $this->actingAs($this->admin)->from(route('admin.segnalazioni.edit', $s))
        ->put(route('admin.segnalazioni.update', $s), residentiModulo($this, $this->admin, $s, ['anagrafiche' => []]))
        ->assertSessionHasErrors('anagrafiche');

    expect(session('errors')->first('anagrafiche'))->toContain('usa «Rendi pubblica»');
});
