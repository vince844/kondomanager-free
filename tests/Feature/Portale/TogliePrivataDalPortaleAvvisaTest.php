<?php

/**
 * # L'autore toglie «privata» dal portale: avvisa come «Rendi pubblica» (Coda 304, decisione 134.5)
 *
 * Quando l'autore toglie «privata» dalla sua segnalazione nel portale, la segnalazione diventa del
 * palazzo (o delle persone a cui l'amministratore l'aveva indirizzata). Per chi non la vedeva è come
 * una prima pubblicazione, e riceve «nuova segnalazione», come con «Rendi pubblica»: la platea dopo il
 * salvataggio meno quella di prima (`DestinatariNotifica`). L'autore no: è lui che l'ha resa visibile,
 * e non riceve né «nuova» della propria né «resa pubblica».
 *
 * Su una privata non ancora pubblicata e approvata nessuno la vede adesso: l'avviso «nuova» parte con
 * l'approvazione, come sempre. Una modifica dal portale che non toglie «privata» non avvisa nessuno.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\ApprovedSegnalazioneNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\SegnalazioneResaPubblicaNotification;
use App\Services\Notifiche\DestinatariNotifica;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    // Mario l'autore, che toglie «privata» dal suo modulo; Luigi e Anna nel palazzo; Zeno nel palazzo
    // con l'avviso «nuova segnalazione» spento; Bruno in un altro palazzo.
    $this->mario = avvisaTogliePersona([$this->aurora->id], 'Mario Rossi', [Permission::EDIT_OWN_SEGNALAZIONI->value, Permission::PUBLISH_SEGNALAZIONI->value]);
    $this->luigi = avvisaTogliePersona([$this->aurora->id], 'Luigi Verdi');
    $this->anna  = avvisaTogliePersona([$this->aurora->id], "Anna D'Amico");
    $this->zeno  = avvisaTogliePersona([$this->aurora->id], 'Zeno Galli');
    DB::table('notification_preferences')->where('user_id', $this->zeno->id)->where('type', NotificationType::NEW_TICKET->value)->update(['enabled' => false]);
    $this->bruno = avvisaTogliePersona([$this->borea->id], 'Bruno Fabbri');
});

function avvisaTogliePersona(array $condominiIds, string $nome, array $permessi = []): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominiIds);

    return $user->fresh();
}

/** La privata aperta dal portale da `$autore`, come la manda la pagina «nuova»; poi gli avvisi di prima si dimenticano. */
function avvisaToglieApre(User $autore, Condominio $condominio): Segnalazione
{
    test()->actingAs($autore)->post(route('user.segnalazioni.store'), [
        'subject' => "Muffa nella camera «grande»", 'description' => "Riguarda l'appartamento\ndel terzo piano",
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'is_private' => true,
    ])->assertSessionHasNoErrors();
    Notification::fake();

    return Segnalazione::query()->where('created_by', $autore->id)->latest('id')->firstOrFail();
}

/** Il corpo che il modulo di modifica del portale manda, dai dati che la pagina riceve. */
function avvisaToglieCorpo(Segnalazione $s, array $cambi = []): array
{
    return array_merge([
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority,
        'stato' => $s->stato, 'condominio_id' => $s->condominio_id, 'is_private' => (bool) $s->is_private,
    ], $cambi);
}

test('Coda 304 — l\'autore toglie «privata» dal portale: chi la vede per la prima volta riceve «nuova segnalazione», e l\'autore niente', function () {
    $s = avvisaToglieApre($this->mario, $this->aurora);
    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertForbidden();

    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $s), avvisaToglieCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', ['type' => 'success', 'message' => __('segnalazioni.success_update_ticket')]);

    // Luigi e Anna ora la aprono, e ricevono l'avviso; Zeno la apre ma ha spento quell'avviso.
    foreach ([$this->luigi, $this->anna] as $nuovo) {
        Notification::assertSentTo($nuovo->anagrafica, NewSegnalazioneNotification::class);
        $this->actingAs($nuovo)->get(route('user.segnalazioni.show', $s))->assertOk();
    }
    Notification::assertNotSentTo($this->zeno->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->bruno->anagrafica, NewSegnalazioneNotification::class);

    // L'autore: né «nuova» della propria, né «resa pubblica».
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($this->mario, SegnalazioneResaPubblicaNotification::class);
});

test('Coda 304 — l\'autore toglie «privata» a una privata indirizzata anche a Luigi: la vedeva già solo Luigi, e nessuno riceve «nuova»', function () {
    $s = avvisaToglieApre($this->mario, $this->aurora);
    $s->anagrafiche()->attach($this->luigi->anagrafica->id);

    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $s), avvisaToglieCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors();

    // Resta indirizzata a Luigi, che la vedeva già: per nessuno è nuova.
    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    Notification::assertNothingSent();
});

test('Coda 198 — l\'autore toglie «privata» a una privata in moderazione: nessun avviso adesso; l\'approvazione poi lo manda, come la prima pubblicazione', function () {
    // Senza «Pubblica segnalazioni» la privata nasce in moderazione.
    $gino = avvisaTogliePersona([$this->aurora->id], 'Gino Rossi', [Permission::EDIT_OWN_SEGNALAZIONI->value]);
    $s = avvisaToglieApre($gino, $this->aurora);
    expect((bool) $s->is_approved)->toBeFalse();

    $this->actingAs($gino)->put(route('user.segnalazioni.update', $s), avvisaToglieCorpo($s, ['is_private' => false]))
        ->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s->fresh()))->assertRedirect();

    Notification::assertSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertNotSentTo($gino->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($gino, ApprovedSegnalazioneNotification::class);
});

test('Coda 304 — una modifica dal portale che non toglie «privata» non manda «nuova» a nessuno', function () {
    // Resta privata: l'autore cambia solo il testo.
    $privata = avvisaToglieApre($this->mario, $this->aurora);
    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $privata), avvisaToglieCorpo($privata, ['description' => "Ora anche in cucina, l'acqua"]))
        ->assertSessionHasNoErrors();

    // Non era privata: la vedeva già tutto il palazzo.
    $nonPrivata = avvisaToglieApre($this->mario, $this->aurora);
    $nonPrivata->update(['is_private' => false]);
    $nonPrivata->anagrafiche()->detach();
    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $nonPrivata), avvisaToglieCorpo($nonPrivata->fresh(), ['subject' => 'Muffa anche in cucina']))
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('Coda 304 — se l\'avviso ai condòmini non parte, la modifica resta salvata e il messaggio lo dice', function () {
    $s = avvisaToglieApre($this->mario, $this->aurora);

    // La platea si legge due volte (prima e dopo il salvataggio): alla seconda il calcolo si rompe.
    $vera = app(DestinatariNotifica::class);
    $letture = 0;
    $this->mock(DestinatariNotifica::class, function ($mock) use ($vera, &$letture) {
        $mock->shouldReceive('perModello')->andReturnUsing(function ($modello) use ($vera, &$letture) {
            if (++$letture > 1) {
                throw new RuntimeException('platea non disponibile');
            }

            return $vera->perModello($modello);
        });
    });

    $this->actingAs($this->mario)->put(route('user.segnalazioni.update', $s), avvisaToglieCorpo($s, ['is_private' => false]))
        ->assertRedirect(route('user.segnalazioni.index'))
        ->assertSessionHas('message', [
            'type' => 'warning',
            'message' => "La segnalazione non è più privata, ma l'avviso ai condòmini che non la vedevano non è partito.",
        ]);

    expect((bool) $s->fresh()->is_private)->toBeFalse();
    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertOk();
});
