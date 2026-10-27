<?php

/**
 * Coda 261 — la password attuale di chi è collegato si prova con un conto solo, qualunque sia la rotta.
 *
 * ## Perché questo file esiste
 *
 * Cinque rotte per chi è collegato verificano la password dell'account: la conferma della
 * password, il cambio della password, il cambio dell'email dal profilo, l'eliminazione
 * dell'account e l'avvio del ripristino. Hanno un limite con nome solo, `password-attuale`,
 * con una chiave per utente: sei prove al minuto e trenta all'ora in tutto, qualunque sia la
 * rotta.
 *
 * Ogni prova è mandata come la manda la sua pagina: i moduli Inertia della conferma, della password e del
 * profilo, e la chiamata JSON della finestra del ripristino (`impostazioniBackups.vue`).
 *
 * ## Cosa questo file NON copre
 *
 * Le rotte per gli ospiti (login, password dimenticata, recupero del ripristino), che hanno i loro limiti
 * per indirizzo (`LimitiPerReteIpv6Test.php`, `RestoreHttpTest.php`).
 */

use App\Enums\BackupStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    // Un secondo amministratore attivo: l'eliminazione dell'account del primo arriva alla password.
    User::factory()->create(['email_verified_at' => now()])->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->backup = Backup::create([
        'uuid' => (string) Str::uuid(),
        'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);
});

afterEach(function () {
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
});

/**
 * Una prova della password attuale, come la manda la pagina della rotta.
 */
function provaLaPassword($test, User $utente, string $rotta, string $password, $backup = null)
{
    $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    return match ($rotta) {
        'conferma' => $test->actingAs($utente)->from('/confirm-password')->withHeaders($inertia)
            ->post('/confirm-password', ['password' => $password]),
        'password' => $test->actingAs($utente)->from('/settings/password')->withHeaders($inertia)
            ->put('/settings/password', ['current_password' => $password, 'password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123']),
        'email' => $test->actingAs($utente)->from('/settings/profile')->withHeaders($inertia)
            ->patch('/settings/profile', ['name' => $utente->name, 'email' => 'nuova-'.$utente->id.'@example.com', 'current_password' => $password]),
        'elimina' => $test->actingAs($utente)->from('/settings/profile')->withHeaders($inertia)
            ->delete('/settings/profile', ['password' => $password]),
        'ripristino' => $test->actingAs($utente)
            ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", ['account_password' => $password, 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false]),
    };
}

/** Che cosa la prova giusta avrebbe fatto: nessuna di queste cose deve essere successa dopo un 429. */
function nienteFatto(User $utente, string $rotta): void
{
    $fresco = User::find($utente->id);

    match ($rotta) {
        'conferma' => expect(session('auth.password_confirmed_at'))->toBeNull(),
        'password' => expect(Hash::check('password', $fresco->password))->toBeTrue(),
        'email' => expect($fresco->email)->toBe($utente->email),
        'elimina' => expect($fresco)->not->toBeNull(),
        'ripristino' => expect(app(RestoreMode::class)->active())->toBeFalse(),
    };
}

/** Le cinque rotte, nell'ordine in cui le prove le girano. */
function rotteDellaPassword(): array
{
    return ['conferma', 'password', 'email', 'elimina', 'ripristino'];
}

it('Coda 261 — la settima prova della password attuale si ferma anche su una rotta diversa dalle prime sei, con la password giusta', function (string $settima) {
    // Le prime sei, sbagliate, girano sulle altre quattro rotte: nessuna rotta ne riceve più di due.
    $altre = array_values(array_diff(rotteDellaPassword(), [$settima]));

    for ($i = 0; $i < 6; $i++) {
        $risposta = provaLaPassword($this, $this->amministratore, $altre[$i % 4], "sbagliata-{$i}", $this->backup);
        expect($risposta->status())->not->toBe(429);
    }

    provaLaPassword($this, $this->amministratore, $settima, 'password', $this->backup)->assertStatus(429);

    nienteFatto($this->amministratore, $settima);
})->with([
    'la conferma della password' => ['conferma'],
    'il cambio della password' => ['password'],
    'il cambio dell\'email dal profilo' => ['email'],
    'l\'eliminazione dell\'account' => ['elimina'],
    'l\'avvio del ripristino' => ['ripristino'],
]);

it('Coda 261 — la trentunesima prova della password attuale nell\'ora si ferma, anche al ritmo di cinque al minuto', function () {
    $stati = [];
    for ($minuto = 0; $minuto < 6; $minuto++) {
        for ($i = 0; $i < 5; $i++) {
            $stati[] = provaLaPassword($this, $this->amministratore, rotteDellaPassword()[$i], "sbagliata-{$minuto}-{$i}", $this->backup)->status();
        }
        $this->travel(61)->seconds();
    }

    // Le trenta sbagliate hanno ricevuto ognuna la sua risposta: nessuna si è fermata al limite del minuto.
    expect($stati)->not->toContain(429)->and($stati)->toHaveCount(30);

    provaLaPassword($this, $this->amministratore, 'conferma', 'password')->assertStatus(429);
    nienteFatto($this->amministratore, 'conferma');
});

it('Coda 261 — passata l\'ora le prove della password attuale ricominciano', function () {
    for ($minuto = 0; $minuto < 6; $minuto++) {
        for ($i = 0; $i < 5; $i++) {
            provaLaPassword($this, $this->amministratore, rotteDellaPassword()[$i], "sbagliata-{$minuto}-{$i}", $this->backup);
        }
        $this->travel(61)->seconds();
    }

    $this->travel(1)->hours();

    provaLaPassword($this, $this->amministratore, 'conferma', 'password')->assertSessionHasNoErrors()->assertRedirect();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('Coda 261 — le prove della password attuale di un utente non consumano quelle di un altro', function () {
    $altro = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 0; $i < 6; $i++) {
        provaLaPassword($this, $altro, 'conferma', "sbagliata-{$i}");
    }
    provaLaPassword($this, $altro, 'conferma', 'password')->assertStatus(429);

    provaLaPassword($this, $this->amministratore, 'conferma', 'password')->assertSessionHasNoErrors()->assertRedirect();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('Coda 261 — quando finiscono insieme il limite del minuto e quello dell\'ora prima dell\'ultimo minuto della finestra dell\'ora, il 429 dice l\'attesa dell\'ora, e dopo quell\'attesa la password giusta passa', function (string $rotta) {
    // Il tempo fermo: le attese si contano dalla prima prova al secondo, senza i secondi veri che le prove impiegano.
    $this->freezeTime();

    // Quattro minuti da sei prove sbagliate e sei nel quinto: la trentesima prova dell'ora è anche la sesta del minuto.
    for ($minuto = 0; $minuto < 5; $minuto++) {
        for ($i = 0; $i < 6; $i++) {
            $risposta = provaLaPassword($this, $this->amministratore, $rotta, "sbagliata-{$minuto}-{$i}", $this->backup);
            expect($risposta->status())->not->toBe(429);
        }
        if ($minuto < 4) {
            $this->travel(61)->seconds();
        }
    }

    // Il limite dell'ora finisce un'ora dopo la prima prova, fatta 4 × 61 secondi fa: è l'attesa vera, e la
    // pagina del 429 e la finestra dei backup la leggono da `Retry-After`.
    $fermata = provaLaPassword($this, $this->amministratore, $rotta, 'password', $this->backup);
    $fermata->assertStatus(429);
    expect((int) $fermata->headers->get('Retry-After'))->toBe(3600 - 4 * 61);
    nienteFatto($this->amministratore, $rotta);

    $this->travel(3600 - 4 * 61 + 1)->seconds();

    $dopo = provaLaPassword($this, $this->amministratore, $rotta, 'password', $this->backup);
    expect($dopo->status())->not->toBe(429)->not->toBe(422);
    $dopo->assertSessionHasNoErrors();
})->with([
    'la conferma della password (la pagina del 429)' => ['conferma'],
    'l\'avvio del ripristino (la finestra dei backup)' => ['ripristino'],
]);

it('Coda 261 — quando finiscono insieme nell\'ultimo minuto della finestra dell\'ora, Retry-After è più corto dell\'attesa vera, la pagina del 429 dice «fra un minuto», e passato il minuto la password giusta passa', function () {
    // Il tempo fermo: le attese si contano dalla prima prova al secondo, senza i secondi veri che le prove impiegano.
    $this->freezeTime();

    // Quattro minuti da sei prove sbagliate: ventiquattro prove nell'ora cominciata con la prima.
    for ($minuto = 0; $minuto < 4; $minuto++) {
        for ($i = 0; $i < 6; $i++) {
            provaLaPassword($this, $this->amministratore, 'conferma', "sbagliata-{$minuto}-{$i}");
        }
        $this->travel(61)->seconds();
    }

    // Venti secondi prima della fine dell'ora, altre sei: finiscono insieme l'ora (trenta prove) e il minuto (sei).
    $this->travel(3600 - 20 - 4 * 61)->seconds();
    for ($i = 0; $i < 6; $i++) {
        expect(provaLaPassword($this, $this->amministratore, 'conferma', "ultima-{$i}")->status())->not->toBe(429);
    }

    // Il framework dice l'attesa del primo limite che trova esaurito, l'ora: i venti secondi che mancano alla sua
    // fine, più corti del minuto che manca davvero. La pagina arrotonda i minuti per eccesso, e la sua frase resta vera.
    $fermata = provaLaPassword($this, $this->amministratore, 'conferma', 'password');
    $fermata->assertStatus(429)->assertSee(trans_choice('errors.429_retry_minutes', 1));
    expect((int) $fermata->headers->get('Retry-After'))->toBe(20);
    nienteFatto($this->amministratore, 'conferma');

    // Passati quei secondi l'ora è ricominciata, il minuto no: la prova si ferma ancora.
    $this->travel(21)->seconds();
    provaLaPassword($this, $this->amministratore, 'conferma', 'password')->assertStatus(429);

    // Passato il minuto dalle ultime sei, la password giusta passa.
    $this->travel(61 - 21)->seconds();
    $dopo = provaLaPassword($this, $this->amministratore, 'conferma', 'password');
    expect($dopo->status())->not->toBe(429);
    $dopo->assertSessionHasNoErrors();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('Coda 261 — quando è finito solo il limite del minuto, il 429 dice l\'attesa del minuto, e dopo quell\'attesa la password giusta passa', function (string $rotta) {
    for ($i = 0; $i < 6; $i++) {
        provaLaPassword($this, $this->amministratore, $rotta, "sbagliata-{$i}", $this->backup);
    }

    $fermata = provaLaPassword($this, $this->amministratore, $rotta, 'password', $this->backup);
    $fermata->assertStatus(429);
    $attesa = (int) $fermata->headers->get('Retry-After');
    expect($attesa)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $this->travel($attesa + 1)->seconds();

    $dopo = provaLaPassword($this, $this->amministratore, $rotta, 'password', $this->backup);
    expect($dopo->status())->not->toBe(429)->not->toBe(422);
    $dopo->assertSessionHasNoErrors();
})->with([
    'la conferma della password (la pagina del 429)' => ['conferma'],
    'l\'avvio del ripristino (la finestra dei backup)' => ['ripristino'],
]);
