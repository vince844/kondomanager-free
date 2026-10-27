<?php

/**
 * # L'autore toglie «privata» dal portale (Code 304 e 306)
 *
 * Una privata aperta dal portale nasce collegata al suo autore (`Utenti\SegnalazioneController::store`):
 * è il collegamento che la indirizza a lui. Un autore con un ruolo su misura («Modifica proprie
 * segnalazioni») può togliere «privata» dal suo modulo; il collegamento restava, e una segnalazione non
 * privata indirizzata a una persona la vede solo quella persona. La guida «Chi la vede» gli diceva «la
 * vedono i condòmini a cui è indirizzata (tutto il condominio, se non è indirizzata a persone)», e il
 * lucchetto spariva: la vedeva solo lui.
 *
 * Ora, quando dal portale «privata» viene tolta, il server toglie il collegamento dell'autore, come
 * «Rendi pubblica» toglie i collegamenti. Le persone a cui l'amministratore l'aveva indirizzata restano.
 * Un autore che non è più nel palazzo tiene il collegamento: senza, non la vedrebbe più nemmeno lui.
 */

use App\Enums\Permission;
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

    $this->aurora = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);
});

function togliePrivataResidente(array $condominiIds, string $nome, array $permessi = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominiIds);

    return $user->fresh();
}

/** Il corpo che il modulo di modifica del portale manda, dai dati che la pagina riceve. */
function togliePrivataCorpo(Segnalazione $s, array $cambi = []): array
{
    return array_merge([
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority,
        'stato' => $s->stato, 'condominio_id' => $s->condominio_id, 'is_private' => (bool) $s->is_private,
    ], $cambi);
}

/** La privata aperta dal portale da `$autore`, come la manda la pagina «nuova». */
function togliePrivataApre(User $autore, Condominio $condominio): Segnalazione
{
    test()->actingAs($autore)->post(route('user.segnalazioni.store'), [
        'subject' => "Muffa nella camera «grande»", 'description' => "Riguarda l'appartamento\ndel terzo piano",
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'is_private' => true,
    ])->assertSessionHasNoErrors();

    return Segnalazione::query()->where('created_by', $autore->id)->latest('id')->firstOrFail();
}

function togliePrivataPermessi(): array
{
    return [Permission::EDIT_OWN_SEGNALAZIONI->value, Permission::PUBLISH_SEGNALAZIONI->value];
}

test('Coda 304 — l\'autore toglie «privata» dal portale: il suo collegamento si toglie, e la vede il condominio come la guida dice', function () {
    $mario = togliePrivataResidente([$this->aurora->id], 'Mario Rossi', togliePrivataPermessi());
    $luigi = togliePrivataResidente([$this->aurora->id], 'Luigi Verdi');
    $s = togliePrivataApre($mario, $this->aurora);
    expect($s->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$mario->anagrafica->id]);
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();

    $this->actingAs($mario)->put(route('user.segnalazioni.update', $s), togliePrivataCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors();

    $s = $s->fresh();
    expect((bool) $s->is_private)->toBeFalse()
        ->and($s->anagrafiche()->count())->toBe(0);
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 304 — l\'autore toglie «privata» a una privata che l\'amministratore aveva indirizzato anche a Luigi: si toglie solo il collegamento dell\'autore', function () {
    $mario = togliePrivataResidente([$this->aurora->id], 'Mario Rossi', togliePrivataPermessi());
    $luigi = togliePrivataResidente([$this->aurora->id], 'Luigi Verdi');
    $anna  = togliePrivataResidente([$this->aurora->id], "Anna D'Amico");
    $s = togliePrivataApre($mario, $this->aurora);
    $s->anagrafiche()->attach($luigi->anagrafica->id);

    $this->actingAs($mario)->put(route('user.segnalazioni.update', $s), togliePrivataCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors();

    $s = $s->fresh();
    expect($s->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$luigi->anagrafica->id]);
    $this->actingAs($luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertOk();
    // Indirizzata a Luigi: Anna, nel palazzo, non la vede (la guida dice «i condòmini a cui è indirizzata»).
    $this->actingAs($anna)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — l\'autore uscito dal palazzo che toglie «privata» tiene il suo collegamento, e la segnalazione non la perde', function () {
    $mario = togliePrivataResidente([$this->aurora->id, $this->borea->id], 'Mario Rossi', togliePrivataPermessi());
    $s = togliePrivataApre($mario, $this->aurora);
    $mario->anagrafica->condomini()->detach($this->aurora->id);
    $mario = $mario->fresh();

    $this->actingAs($mario)->put(route('user.segnalazioni.update', $s), togliePrivataCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors();

    expect($s->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$mario->anagrafica->id]);
    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 304 — una modifica dal portale che non toglie «privata» non tocca i collegamenti', function () {
    $mario = togliePrivataResidente([$this->aurora->id], 'Mario Rossi', togliePrivataPermessi());
    $luigi = togliePrivataResidente([$this->aurora->id], 'Luigi Verdi');

    // Resta privata: l'autore cambia solo il testo.
    $privata = togliePrivataApre($mario, $this->aurora);
    $this->actingAs($mario)->put(route('user.segnalazioni.update', $privata), togliePrivataCorpo($privata, ['description' => "Ora anche in cucina, l'acqua"]))
        ->assertSessionHasNoErrors();
    expect($privata->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$mario->anagrafica->id]);

    // Non era privata: indirizzata dall'amministratore all'autore e a Luigi, resta così.
    $nonPrivata = togliePrivataApre($mario, $this->aurora);
    $nonPrivata->update(['is_private' => false]);
    $nonPrivata->anagrafiche()->attach($luigi->anagrafica->id);
    $nonPrivata = $nonPrivata->fresh();
    $this->actingAs($mario)->put(route('user.segnalazioni.update', $nonPrivata), togliePrivataCorpo($nonPrivata))->assertSessionHasNoErrors();
    expect($nonPrivata->fresh()->anagrafiche()->pluck('anagrafiche.id')->sort()->values()->all())
        ->toBe(collect([$mario->anagrafica->id, $luigi->anagrafica->id])->sort()->values()->all());

    // Senza il campo «privata» nel corpo (`sometimes`): niente cambia.
    $senzaCampo = togliePrivataApre($mario, $this->aurora);
    $corpo = togliePrivataCorpo($senzaCampo);
    unset($corpo['is_private']);
    $this->actingAs($mario)->put(route('user.segnalazioni.update', $senzaCampo), $corpo)->assertSessionHasNoErrors();
    expect((bool) $senzaCampo->fresh()->is_private)->toBeTrue()
        ->and($senzaCampo->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$mario->anagrafica->id]);
});
