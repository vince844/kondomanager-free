<?php

/**
 * # «Rendi pubblica»: l'unico modo di rendere pubblica una segnalazione privata (Coda 304)
 *
 * Decisione 127.4 del 26/10/2026: l'amministratore rende pubblica una privata solo con un comando
 * esplicito, con la conferma; i residenti di una privata non si svuotano più dal modulo. Decisione
 * 129.2: la conferma dice che la vedranno tutti i condòmini del palazzo, con il nome dell'autore, e
 * l'autore riceve l'avviso.
 *
 * Il comando:
 * - lo dà chi può **approvare** le segnalazioni (`APPROVE_SEGNALAZIONI`): è il permesso che decide
 *   già quando una segnalazione diventa visibile al condominio (l'interruttore di approvazione); il
 *   collaboratore di serie pubblica le proprie ma non approva quelle degli altri, e non rende
 *   pubbliche le private degli altri;
 * - mette `is_private` a falso e toglie i collegamenti alle persone, perché la segnalazione sia
 *   davvero di tutto il condominio come la conferma promette (con i collegamenti la vedrebbero solo
 *   le persone collegate); non tocca il testo, lo stato, la priorità, l'approvazione né l'autore;
 * - avvisa l'autore, anche se non ha acceso le notifiche: è il suo testo che diventa visibile ai
 *   vicini, con il suo nome; e manda «nuova segnalazione» a chi la vede per la prima volta
 *   (decisione 134.5, `RendiPubblicaAvvisaIlCondominioTest`).
 *
 * Il contrario («rendi privata») non esiste.
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

    $this->mario = rendiPubblicaResidente($this->condominio, 'Mario Rossi');
    $this->luigi = rendiPubblicaResidente($this->condominio, 'Luigi Verdi');

    $this->privata = Segnalazione::create([
        'subject'       => 'Muffa in camera',
        'description'   => 'Riguarda il mio appartamento',
        'priority'      => 'alta',
        'stato'         => 'in lavorazione',
        'condominio_id' => $this->condominio->id,
        'created_by'    => $this->mario->id,
        'is_private'    => true,
        'is_published'  => true,
        'is_approved'   => true,
        'is_featured'   => false,
        'can_comment'   => true,
    ]);
    $this->privata->anagrafiche()->attach($this->mario->anagrafica->id);
});

function rendiPubblicaResidente(Condominio $condominio, string $nome): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user->fresh();
}

test('Coda 304 — l\'amministratore rende pubblica una privata: la vede il vicino, e il resto non cambia', function () {
    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $this->privata))->assertForbidden();

    $this->actingAs($this->admin)
        ->put(route('admin.segnalazioni.rendi-pubblica', $this->privata))
        ->assertRedirect()->assertSessionHasNoErrors();

    $s = $this->privata->fresh();
    expect((bool) $s->is_private)->toBeFalse()
        ->and($s->anagrafiche()->count())->toBe(0)
        ->and($s->subject)->toBe('Muffa in camera')
        ->and($s->stato)->toBe('in lavorazione')
        ->and($s->priority)->toBe('alta')
        ->and((bool) $s->is_published)->toBeTrue()
        ->and((bool) $s->is_approved)->toBeTrue()
        ->and((int) $s->created_by)->toBe($this->mario->id)
        ->and((int) $s->updated_by)->toBe($this->admin->id);

    $this->actingAs($this->luigi)->get(route('user.segnalazioni.show', $s))->assertOk();
    $elenco = $this->actingAs($this->luigi)->get(route('user.segnalazioni.index'))->viewData('page')['props'];
    expect(collect($elenco['segnalazioni']['data'])->pluck('id')->all())->toContain($s->id);
});

test('Coda 304 — rendere pubblica una privata avvisa l\'autore, anche senza le notifiche accese; il vicino con l\'avviso spento non riceve niente', function () {
    // Decisione 134.5: il vicino che non la vedeva riceve «nuova segnalazione», come a una prima
    // pubblicazione, se ha acceso quell'avviso (`RendiPubblicaAvvisaIlCondominioTest`). Luigi qui non
    // ha acceso nessun avviso: non riceve niente. L'autore riceve «resa pubblica» comunque.
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $this->privata))->assertRedirect();

    Notification::assertSentTo($this->mario, SegnalazioneResaPubblicaNotification::class, function ($notifica) {
        $mail = $notifica->toMail($this->mario);
        $testo = implode("\n", array_merge([$mail->subject], $mail->introLines, $mail->outroLines));

        return str_contains($testo, 'Condominio Aurora')
            && str_contains($testo, 'Studio Bianchi')
            && str_contains($testo, 'Muffa in camera');
    });
    Notification::assertNothingSentTo($this->luigi);
    Notification::assertNothingSentTo($this->luigi->anagrafica);
});

test('Coda 304 — il collaboratore, che non approva le segnalazioni, non rende pubblica una privata', function () {
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    $this->actingAs($collaboratore)
        ->put(route('admin.segnalazioni.rendi-pubblica', $this->privata))
        ->assertForbidden();

    expect((bool) $this->privata->fresh()->is_private)->toBeTrue()
        ->and($this->privata->anagrafiche()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('Coda 304 — il condòmino non rende pubblica la privata, neanche la propria', function () {
    $this->actingAs($this->mario)
        ->put(route('admin.segnalazioni.rendi-pubblica', $this->privata))
        ->assertForbidden();
    $this->actingAs($this->luigi)
        ->put(route('admin.segnalazioni.rendi-pubblica', $this->privata))
        ->assertForbidden();

    expect((bool) $this->privata->fresh()->is_private)->toBeTrue();
    Notification::assertNothingSent();
});

test('Coda 304 — su una segnalazione già pubblica il comando non cambia niente e non avvisa', function () {
    $pubblica = Segnalazione::create([
        'subject'       => 'Lampione rotto',
        'description'   => 'Nel cortile',
        'priority'      => 'bassa',
        'stato'         => 'aperta',
        'condominio_id' => $this->condominio->id,
        'created_by'    => $this->mario->id,
        'is_private'    => false,
        'is_published'  => true,
        'is_approved'   => true,
        'is_featured'   => false,
        'can_comment'   => true,
    ]);
    $pubblica->anagrafiche()->attach($this->luigi->anagrafica->id);

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $pubblica))->assertRedirect();

    expect($pubblica->fresh()->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([$this->luigi->anagrafica->id])
        ->and($pubblica->fresh()->updated_by)->toBeNull();
    Notification::assertNothingSent();
});

test('Coda 304 — la scheda dell\'amministratore riceve ciò che serve alla conferma: privata, autore e condominio', function () {
    $props = $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $this->privata))
        ->assertOk()->viewData('page')['props'];
    $dati = $props['segnalazione']['data'] ?? $props['segnalazione'];

    expect((bool) $dati['is_private'])->toBeTrue()
        ->and($dati['created_by']['anagrafica']['nome'])->toBe('Mario Rossi')
        ->and($dati['condominio']['option']['label'])->toBe('Condominio Aurora');
});
