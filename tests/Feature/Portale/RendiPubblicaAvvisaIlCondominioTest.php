<?php

/**
 * # «Rendi pubblica» avvisa anche il condominio (decisione 134.5, Code 304 e 198)
 *
 * Rendere pubblica una privata è, per i condòmini che non la vedevano, come una prima pubblicazione:
 * chi la vede per la prima volta riceve «nuova segnalazione». Gli altri no: chi la vedeva già (le
 * persone collegate) e l'autore, che riceve «resa pubblica». La platea è quella di tutti gli avvisi
 * (`DestinatariNotifica`), con la stessa differenza «dopo meno prima» della modifica.
 *
 * Su una privata non ancora pubblicata e approvata il comando non la mostra a nessuno: nessun avviso
 * adesso, e l'avviso «nuova» parte quando sarà approvata, come sempre.
 */

use App\Enums\NotificationType;
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

    // Mario l'autore; Luigi collegato alla privata; Anna e Zeno nel palazzo, non collegati; Carla
    // collegata ma fuori dal palazzo; Bruno in un altro palazzo.
    $this->mario = condominioResidente([$this->aurora->id], 'Mario Rossi');
    $this->luigi = condominioResidente([$this->aurora->id], 'Luigi Verdi');
    $this->anna  = condominioResidente([$this->aurora->id], "Anna D'Amico");
    $this->zeno  = condominioResidente([$this->aurora->id, $this->borea->id], 'Zeno Galli');
    $this->carla = condominioResidente([$this->borea->id], 'Carla Neri');
    $this->bruno = condominioResidente([$this->borea->id], 'Bruno Fabbri');
});

function condominioResidente(array $condominiIds, string $nome): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominiIds);

    return $user->fresh();
}

function condominioPrivata(Condominio $condominio, User $autore, array $collegate, array $stato = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => "Muffa nella camera «grande»", 'description' => "Riguarda l'appartamento\ndel terzo piano",
        'priority' => 'alta', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => true, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
    $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));

    return $s->fresh();
}

test('Coda 304 — «Rendi pubblica» su una privata pubblicata e approvata: chi la vede per la prima volta riceve «nuova segnalazione», chi la vedeva già no', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario, $this->luigi, $this->carla]);
    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertForbidden();

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('message', ['type' => 'success', 'message' => __('segnalazioni.privacy.success_make_public')]);

    // I nuovi del palazzo: Anna e Zeno, che ora la aprono.
    foreach ([$this->anna, $this->zeno] as $nuovo) {
        Notification::assertSentTo($nuovo->anagrafica, NewSegnalazioneNotification::class);
        $this->actingAs($nuovo)->get(route('user.segnalazioni.show', $s))->assertOk();
    }
    // Luigi la vedeva già; Carla era collegata ma è fuori dal palazzo (non la vede più); Bruno sta in un altro palazzo.
    foreach ([$this->luigi, $this->carla, $this->bruno] as $altro) {
        Notification::assertNotSentTo($altro->anagrafica, NewSegnalazioneNotification::class);
    }
    // L'autore riceve «resa pubblica», non «nuova» della propria.
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->mario, SegnalazioneResaPubblicaNotification::class);
});

test('Coda 304 — «Rendi pubblica» non manda «nuova segnalazione» a chi ha spento quell\'avviso o non vede le segnalazioni', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario]);
    DB::table('notification_preferences')->where('user_id', $this->anna->id)->where('type', NotificationType::NEW_TICKET->value)->update(['enabled' => false]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    Notification::assertNotSentTo($this->anna->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->luigi->anagrafica, NewSegnalazioneNotification::class);
});

test('Coda 198 — «Rendi pubblica» su una privata in moderazione non avvisa nessuno del palazzo adesso; l\'approvazione poi lo fa, come la prima pubblicazione', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario, $this->luigi], ['is_published' => true, 'is_approved' => false]);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    foreach ([$this->luigi, $this->anna, $this->zeno, $this->mario] as $chi) {
        Notification::assertNotSentTo($chi->anagrafica, NewSegnalazioneNotification::class);
    }
    $this->actingAs($this->anna)->get(route('user.segnalazioni.show', $s))->assertForbidden();

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s->fresh()))->assertRedirect();

    foreach ([$this->luigi, $this->anna, $this->zeno] as $chi) {
        Notification::assertSentTo($chi->anagrafica, NewSegnalazioneNotification::class);
    }
    Notification::assertNotSentTo($this->mario->anagrafica, NewSegnalazioneNotification::class);
    Notification::assertSentTo($this->mario, ApprovedSegnalazioneNotification::class);
});

test('Coda 304 — se l\'avviso ai condòmini non parte, la segnalazione resta pubblica e il messaggio lo dice', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario]);

    // La platea si legge due volte (prima e dopo il comando): alla seconda il calcolo si rompe.
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

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))
        ->assertRedirect()
        ->assertSessionHas('message', ['type' => 'warning', 'message' => __('segnalazioni.privacy.error_notify_residents')]);

    expect((bool) $s->fresh()->is_private)->toBeFalse();
    Notification::assertSentTo($this->mario, SegnalazioneResaPubblicaNotification::class);
});

test('Coda 304 — se non partono né l\'avviso all\'autore né quello ai condòmini, la segnalazione resta pubblica e il messaggio li dice tutti e due', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario]);

    // La coda non accetta lavori: i due avvisi falliscono insieme. La platea di prima si legge, quella
    // di dopo no, e l'invio all'autore solleva.
    $vera = app(DestinatariNotifica::class);
    $letture = 0;
    $this->mock(DestinatariNotifica::class, function ($mock) use ($vera, &$letture) {
        $mock->shouldReceive('perModello')->andReturnUsing(function ($modello) use ($vera, &$letture) {
            if (++$letture > 1) {
                throw new RuntimeException('coda non disponibile');
            }

            return $vera->perModello($modello);
        });
    });
    Notification::shouldReceive('send')->andThrow(new RuntimeException('coda non disponibile'));

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))
        ->assertRedirect()
        ->assertSessionHas('message', [
            'type' => 'warning',
            'message' => "La segnalazione non è più privata, ma non sono partiti né l'avviso all'autore né quello ai condòmini che non la vedevano.",
        ]);

    expect((bool) $s->fresh()->is_private)->toBeFalse();
});

test('Coda 304 — se non parte solo l\'avviso all\'autore, il messaggio dice quello', function () {
    $s = condominioPrivata($this->aurora, $this->mario, [$this->mario]);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('coda non disponibile'));

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))
        ->assertRedirect()
        ->assertSessionHas('message', ['type' => 'warning', 'message' => __('segnalazioni.privacy.error_notify_author')]);

    expect((bool) $s->fresh()->is_private)->toBeFalse();
});

test('Coda 304 — la conferma di «Rendi pubblica» dice che chi non la vedeva riceverà l\'avviso, e la frase dell\'errore c\'è, in ogni lingua', function () {
    expect(trans('segnalazioni.privacy.make_public_description', [], 'it'))->toContain("riceverà l'avviso di una nuova segnalazione");

    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        $conferma = trans('segnalazioni.privacy.make_public_description', [], $lingua);
        expect($conferma)->toContain(':condominio')->toContain(':autore');
        foreach (['error_notify_residents', 'error_notify_both'] as $chiave) {
            expect(trans("segnalazioni.privacy.{$chiave}", [], $lingua))
                ->not->toBe("segnalazioni.privacy.{$chiave}", "{$lingua}: manca {$chiave}");
        }
    }
});
