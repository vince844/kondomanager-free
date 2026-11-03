<?php

/**
 * # «Rendi pubblica» su una privata che i condòmini non vedono ancora (Coda 304)
 *
 * «Rendi pubblica» toglie la privacy, non approva e non pubblica: la visibilità la decidono ancora
 * la pubblicazione e l'approvazione (decisione 127.2: l'approvazione cambia solo dall'interruttore).
 * Una privata aperta dal portale da un condòmino di serie nasce in moderazione; l'amministratore la
 * può rendere pubblica prima di approvarla, e allora i vicini la vedranno solo quando sarà pubblicata
 * e approvata.
 *
 * Le frasi lo dicono: la mail all'autore e il messaggio dopo il comando dicono «ora la vedono» solo
 * se è già pubblicata e approvata, altrimenti «la vedranno quando sarà pubblicata e approvata». La
 * conferma della pagina segue la stessa regola (`resources/js/lib/segnalazioni/privacy.test.ts`).
 * Il comando resta disponibile in tutti e due i casi.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\SegnalazioneResaPubblicaNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);

    $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);

    $this->mario = moderazioneResidente($this->condominio, 'Mario Rossi');
    $this->luigi = moderazioneResidente($this->condominio, 'Luigi Verdi');
});

function moderazioneResidente(Condominio $condominio, string $nome): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

function moderazionePrivata(Condominio $condominio, User $autore, bool $pubblicata, bool $approvata): Segnalazione
{
    $s = Segnalazione::create([
        'subject' => 'Muffa in camera', 'description' => 'Riguarda il mio appartamento', 'priority' => 'alta',
        'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id, 'is_private' => true,
        'is_published' => $pubblicata, 'is_approved' => $approvata, 'is_featured' => false, 'can_comment' => true,
    ]);
    $s->anagrafiche()->attach($autore->anagrafica->id);

    return $s->fresh();
}

/** Oggetto e righe della mail «resa pubblica» mandata a `$autore`, come testo solo. */
function moderazioneTestoMail(User $autore): string
{
    $testo = null;
    Notification::assertSentTo($autore, SegnalazioneResaPubblicaNotification::class, function ($notifica) use ($autore, &$testo) {
        $mail = $notifica->toMail($autore);
        $testo = implode("\n", array_merge([$mail->subject], $mail->introLines, $mail->outroLines));

        return true;
    });

    return (string) $testo;
}

test('Coda 304 — «Rendi pubblica» su una privata in moderazione: la mail all\'autore dice che la vedranno quando sarà pubblicata e approvata, non che la vedono ora', function () {
    // Il condòmino di serie la apre dal portale: nasce non pubblicata e non approvata.
    $s = moderazionePrivata($this->condominio, $this->mario, pubblicata: false, approvata: false);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))
        ->assertRedirect()->assertSessionHasNoErrors();

    $testo = moderazioneTestoMail($this->mario);
    expect($testo)->toContain('quando sarà pubblicata e approvata')
        ->toContain('Condominio Aurora')
        ->not->toContain('Ora la vedono')
        ->not->toContain('ora è pubblica');

    // Ed è vero: il vicino non la vede, finché nessuno la approva.
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — «Rendi pubblica» su una privata approvata e rimessa in bozza: la mail dice lo stesso', function () {
    $s = moderazionePrivata($this->condominio, $this->mario, pubblicata: false, approvata: true);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    expect(moderazioneTestoMail($this->mario))->toContain('quando sarà pubblicata e approvata')->not->toContain('Ora la vedono');
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — «Rendi pubblica» su una privata scelta «Pubblicata» ma non approvata: la mail dice che la vedranno quando sarà approvata', function () {
    // È lo stato in cui la lascia il modulo di modifica: la tendina «Pubblicata» non approva (127.2).
    $s = moderazionePrivata($this->condominio, $this->mario, pubblicata: true, approvata: false);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    expect(moderazioneTestoMail($this->mario))->toContain('quando sarà pubblicata e approvata')->not->toContain('Ora la vedono');
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertForbidden();
});

test('Coda 304 — «Rendi pubblica» su una privata pubblicata e approvata: la mail dice che ora la vedono tutti', function () {
    // Il controesempio: la frase di prima resta dove è vera.
    $s = moderazionePrivata($this->condominio, $this->mario, pubblicata: true, approvata: true);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    expect(moderazioneTestoMail($this->mario))->toContain('Ora la vedono tutti i condòmini di Condominio Aurora, con il tuo nome.')
        ->not->toContain('quando sarà pubblicata e approvata');
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
});

test('Coda 304 — il messaggio dopo «Rendi pubblica» dice «ora è pubblica» solo se i condòmini la vedono già', function () {
    $visibile = moderazionePrivata($this->condominio, $this->mario, pubblicata: true, approvata: true);

    // In moderazione, pubblicata ma non approvata, approvata ma in bozza: i condòmini non la vedono.
    foreach ([[false, false], [true, false], [false, true]] as [$pubblicata, $approvata]) {
        $nonVisibile = moderazionePrivata($this->condominio, $this->mario, $pubblicata, $approvata);

        $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $nonVisibile))
            ->assertSessionHas('message.type', 'success')
            ->assertSessionHas('message.message', fn (string $m) => str_contains($m, 'quando sarà pubblicata e approvata') && ! str_contains($m, 'ora è pubblica'));
    }

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $visibile))
        ->assertSessionHas('message.type', 'success')
        ->assertSessionHas('message.message', fn (string $m) => str_contains($m, 'ora è pubblica'));
});

test('Coda 304 — «Rendi pubblica» resta disponibile su una privata in moderazione, e non la approva né la pubblica', function () {
    $s = moderazionePrivata($this->condominio, $this->mario, pubblicata: false, approvata: false);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $s))->assertRedirect();

    $s->refresh();
    expect((bool) $s->is_private)->toBeFalse()
        ->and([(bool) $s->is_published, (bool) $s->is_approved])->toBe([false, false]);
});
