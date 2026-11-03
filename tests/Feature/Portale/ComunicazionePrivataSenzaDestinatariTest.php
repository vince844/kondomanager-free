<?php

/**
 * # I destinatari di una comunicazione privata non si svuotano dal modulo (Coda 304)
 *
 * La gemella della regola delle segnalazioni (`CreateSegnalazioneRequest::residentiDiUnaPrivata`). Una
 * comunicazione privata la vedono solo l'autore e le persone a cui è indirizzata: togliendo tutti i
 * destinatari resta privata, e la vede solo chi l'ha scritta. Il salvataggio riusciva con il messaggio
 * di successo, e l'amministratore credeva di averla mandata a tutto il palazzo.
 *
 * Ora il salvataggio si rifiuta con una frase sul campo. Per le comunicazioni non c'è un comando
 * «Rendi pubblica», e la frase non ne indica uno. La regola guarda il cambiamento: una privata che
 * non aveva già destinatari si apre e si salva com'è; una non privata si svuota come prima.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->autore = svuotataResidente($this->condominio, 'Carla Bianchi', [Permission::CREATE_COMUNICAZIONI->value, Permission::PUBLISH_COMUNICAZIONI->value]);
    $this->vicino = svuotataResidente($this->condominio, 'Luigi Verdi');
});

function svuotataResidente(Condominio $condominio, string $nome, array $permessi = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominio->id);

    return $user->fresh();
}

/** Il corpo che la pagina di modifica del pannello manda, dai dati della comunicazione. */
function svuotataCorpo(Comunicazione $c, array $cambi = []): array
{
    return array_merge([
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority,
        'can_comment' => (bool) $c->can_comment, 'is_featured' => (bool) $c->is_featured, 'is_published' => (bool) $c->is_published,
        'condomini_ids' => $c->condomini()->pluck('condomini.id')->all(),
        'anagrafiche' => $c->anagrafiche()->pluck('anagrafiche.id')->all(),
        'avvisa_destinatari' => false,
    ], $cambi);
}

/** La privata scritta dal portale: collegata al solo autore. */
function svuotataPrivata(User $autore, Condominio $condominio): Comunicazione
{
    test()->actingAs($autore)->post(route('user.comunicazioni.store'), [
        'subject' => "Rumori dal piano «di sopra»", 'description' => "Dopo le 23\nogni sera", 'priority' => 'bassa',
        'is_featured' => false, 'is_private' => true, 'condomini_ids' => [$condominio->id],
    ])->assertSessionHasNoErrors();

    return Comunicazione::query()->where('created_by', $autore->id)->latest('id')->firstOrFail();
}

test('Coda 304 — togliere tutti i destinatari di una comunicazione privata si rifiuta, con una frase che non indica un comando che non c\'è', function () {
    $c = svuotataPrivata($this->autore, $this->condominio);

    $this->actingAs($this->admin)->from(route('admin.comunicazioni.edit', $c))
        ->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c, ['anagrafiche' => []]))
        ->assertRedirect(route('admin.comunicazioni.edit', $c))
        ->assertSessionHasErrors(['anagrafiche' => __('comunicazioni.privacy.recipients_required')]);

    expect($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->autore->anagrafica->id])
        ->and(__('comunicazioni.privacy.recipients_required'))->not->toContain('Rendi pubblica');
});

test('Coda 304 — anche un elenco dei destinatari nullo, su una privata, si rifiuta', function () {
    $c = svuotataPrivata($this->autore, $this->condominio);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c, ['anagrafiche' => null]))
        ->assertSessionHasErrors('anagrafiche');

    expect($c->fresh()->anagrafiche()->count())->toBe(1);
});

test('Coda 304 — una privata che non aveva già destinatari si apre e si salva com\'è', function () {
    $c = svuotataPrivata($this->autore, $this->condominio);
    $c->anagrafiche()->detach();

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c->fresh(), ['subject' => "Rumori dal piano «di sopra», di nuovo"]))
        ->assertSessionHasNoErrors();

    expect($c->fresh()->subject)->toBe("Rumori dal piano «di sopra», di nuovo");
});

test('Coda 304 — su una privata si possono cambiare i destinatari, e lasciarli com\'erano', function () {
    $c = svuotataPrivata($this->autore, $this->condominio);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c, ['anagrafiche' => [$this->vicino->anagrafica->id]]))
        ->assertSessionHasNoErrors();

    expect($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->vicino->anagrafica->id]);
});

test('Coda 304 — una comunicazione non privata si svuota come prima, e la vede tutto il palazzo', function () {
    $c = Comunicazione::create([
        'subject' => 'Turni', 'description' => 'x', 'priority' => 'media', 'created_by' => $this->admin->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ]);
    $c->condomini()->attach($this->condominio->id);
    $c->anagrafiche()->attach($this->autore->anagrafica->id);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), svuotataCorpo($c->fresh(), ['anagrafiche' => []]))
        ->assertSessionHasNoErrors();

    expect($c->fresh()->anagrafiche()->count())->toBe(0);
    $this->actingAs($this->vicino)->get(route('user.comunicazioni.show', $c))->assertOk();
});

test('Coda 307 — una richiesta senza il campo dei destinatari, su una privata, si salva e non li tocca', function () {
    // Senza campo non si tocca: la regola dei destinatari di una privata tace.
    $c = svuotataPrivata($this->autore, $this->condominio);
    $corpo = svuotataCorpo($c, ['subject' => 'Rumori notturni']);
    unset($corpo['anagrafiche']);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), $corpo)->assertSessionHasNoErrors();

    expect($c->fresh()->subject)->toBe('Rumori notturni')
        ->and($c->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->autore->anagrafica->id]);
});

test('Coda 304 — la frase sui destinatari di una privata esiste in ogni lingua', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(trans('comunicazioni.privacy.recipients_required', [], $lingua))
            ->not->toBe('comunicazioni.privacy.recipients_required', "{$lingua}: manca la frase");
    }
});
