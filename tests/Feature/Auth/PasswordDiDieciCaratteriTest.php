<?php

/**
 * Coda 262 — le password nuove hanno almeno dieci caratteri, e i login falliti hanno un tetto anche
 * per indirizzo.
 *
 * ## Perché questo file esiste
 *
 * `Password::defaults()` vale per ogni password nuova: la registrazione, l'invito, il primo accesso
 * di un utente creato dall'amministratore, «password dimenticata», il profilo e l'amministratore
 * dell'installazione (wizard e `kondomanager:installa`). La decisione 127.1 vuole dieci caratteri
 * dappertutto, senza nessuna chiamata a un servizio esterno, e solo per le password nuove o
 * cambiate: chi ne ha già una più corta continua a entrare.
 *
 * Il limite del login conta per email e indirizzo insieme, e c'è anche un tetto per indirizzo su
 * tutte le email, alto (venti tentativi falliti al minuto) perché dietro un proxy senza
 * `TRUSTED_PROXIES` tutti gli utenti arrivano con lo stesso indirizzo. Il rifiuto è un errore sul
 * modulo, come quello per email, non un 429 senza pagina; e il tetto si somma a quello per email, non
 * lo sostituisce.
 *
 * ## Cosa questo file NON copre
 *
 * La 2FA obbligatoria (Coda 384) e il limite della sfida 2FA (`LimiteAccessoDueFattoriTest.php`). Il
 * minimo di `kondomanager:installa` è in `InstallaCommandTest.php`, che ha il database vuoto apposta.
 */

use App\Enums\Role as RoleEnum;
use App\Livewire\Installer\CreateAdmin;
use App\Models\Invito;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Notifications\NewUserEmailNotification;
use App\Services\Installer\CreaAmministratore;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const NOVE_CARATTERI = 'abcdefgh1';
const DIECI_CARATTERI = 'abcdefgh12';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function dueVolte(string $password): array
{
    return ['password' => $password, 'password_confirmation' => $password];
}

it('Coda 262 — la registrazione pubblica rifiuta nove caratteri e accetta dieci', function () {
    app(GeneralSettings::class)->user_frontend_registration = true;
    app(GeneralSettings::class)->save();

    $this->post('/register', ['name' => 'Chi si registra', 'email' => 'nuovo@example.com'] + dueVolte(NOVE_CARATTERI))
        ->assertSessionHasErrors('password');
    expect(User::where('email', 'nuovo@example.com')->exists())->toBeFalse();

    $this->post('/register', ['name' => 'Chi si registra', 'email' => 'nuovo@example.com'] + dueVolte(DIECI_CARATTERI))
        ->assertSessionHasNoErrors();
    expect(User::where('email', 'nuovo@example.com')->exists())->toBeTrue();
});

it('Coda 262 — il link di «password dimenticata» rifiuta nove caratteri e accetta dieci', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $token = Password::broker()->createToken($utente);

    $this->post('/reset-password', ['token' => $token, 'email' => $utente->email] + dueVolte(NOVE_CARATTERI))
        ->assertSessionHasErrors('password');
    expect(Hash::check(NOVE_CARATTERI, $utente->fresh()->password))->toBeFalse();

    $this->post('/reset-password', ['token' => $token, 'email' => $utente->email] + dueVolte(DIECI_CARATTERI))
        ->assertSessionHasNoErrors();
    expect(Hash::check(DIECI_CARATTERI, $utente->fresh()->password))->toBeTrue();
});

it('Coda 262 — il cambio dal profilo rifiuta nove caratteri e accetta dieci', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($utente)
        ->put('/settings/password', ['current_password' => 'password'] + dueVolte(NOVE_CARATTERI))
        ->assertSessionHasErrors('password');
    expect(Hash::check('password', $utente->fresh()->password))->toBeTrue();

    $this->actingAs($utente)
        ->put('/settings/password', ['current_password' => 'password'] + dueVolte(DIECI_CARATTERI))
        ->assertSessionHasNoErrors();
    expect(Hash::check(DIECI_CARATTERI, $utente->fresh()->password))->toBeTrue();
});

it('Coda 262 — la registrazione da invito rifiuta nove caratteri e accetta dieci', function () {
    $invito = Invito::create([
        'email'          => 'invitato@example.com',
        'building_codes' => [],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);
    $link = (new InviteUserNotification($invito))->toMail($invito)->actionUrl;

    $this->post($link, ['name' => 'Invitato'] + dueVolte(NOVE_CARATTERI))
        ->assertSessionHasErrors('password');
    expect(User::where('email', 'invitato@example.com')->exists())->toBeFalse();

    $this->post($link, ['name' => 'Invitato'] + dueVolte(DIECI_CARATTERI))
        ->assertSessionHasNoErrors();
    expect(User::where('email', 'invitato@example.com')->exists())->toBeTrue();
});

it('Coda 262 — il primo accesso di un utente creato dall\'amministratore rifiuta nove caratteri e accetta dieci', function () {
    $nuovo = User::factory()->create(['password' => null, 'email_verified_at' => now()]);
    $nuovo->assignRole(RoleEnum::UTENTE->value);
    $link = NewUserEmailNotification::link($nuovo);

    $this->post($link, dueVolte(NOVE_CARATTERI))->assertSessionHasErrors('password');
    expect($nuovo->fresh()->password)->toBeNull();

    $this->post($link, dueVolte(DIECI_CARATTERI))->assertSessionHasNoErrors();
    expect(Hash::check(DIECI_CARATTERI, $nuovo->fresh()->password))->toBeTrue();
});

it('Coda 262 — l\'amministratore del wizard rifiuta nove caratteri e accetta dieci', function () {
    Livewire::test(CreateAdmin::class)
        ->set('name', 'Mario Rossi')
        ->set('email', 'admin.nove@esempio.it')
        ->set('password', NOVE_CARATTERI)
        ->set('password_confirmation', NOVE_CARATTERI)
        ->call('completeStep')
        ->assertHasErrors(['password']);
    expect(User::where('email', 'admin.nove@esempio.it')->exists())->toBeFalse();

    // Anche il controllo mentre si scrive, con la conferma ancora vuota: è la regola che la pagina
    // mostra subito, e deve dire la stessa cosa del salvataggio.
    Livewire::test(CreateAdmin::class)
        ->set('password', NOVE_CARATTERI)
        ->assertHasErrors(['password']);

    Livewire::test(CreateAdmin::class)
        ->set('name', 'Mario Rossi')
        ->set('email', 'admin.dieci@esempio.it')
        ->set('password', DIECI_CARATTERI)
        ->set('password_confirmation', DIECI_CARATTERI)
        ->call('completeStep')
        ->assertHasNoErrors();
    expect(User::where('email', 'admin.dieci@esempio.it')->exists())->toBeTrue();
});

it('Coda 262 — il servizio dell\'installazione rifiuta nove caratteri e accetta dieci', function () {
    expect(fn () => app(CreaAmministratore::class)->esegui('Mario Rossi', 'mario@esempio.it', NOVE_CARATTERI))
        ->toThrow(ValidationException::class);
    expect(User::where('email', 'mario@esempio.it')->exists())->toBeFalse();

    $utente = app(CreaAmministratore::class)->esegui('Mario Rossi', 'mario@esempio.it', DIECI_CARATTERI);
    expect(Hash::check(DIECI_CARATTERI, $utente->password))->toBeTrue();
});

it('Coda 262 — chi ha già una password di otto caratteri continua a entrare', function () {
    // La regola vale per le password nuove o cambiate: nessuno è costretto a cambiarla.
    $utente = User::factory()->create(['email_verified_at' => now(), 'password' => Hash::make('otto1234')]);

    $this->post('/login', ['email' => $utente->email, 'password' => 'otto1234']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 262 — dopo venti login falliti da un indirizzo, il ventunesimo è fermato anche su un\'altra email', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->post('/login', ['email' => $utente->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain(trim(explode(':seconds', __('auth.throttle'))[0]));
});

it('Coda 262 — diciannove login falliti da un indirizzo non fermano il ventesimo', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 19; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 262 — il tetto è per indirizzo: da un altro indirizzo si entra', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 262 — il tetto per indirizzo dura un minuto', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 20; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->travel(61)->seconds();

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($utente);
});

it('Coda 262 — un login riuscito non azzera il tetto dell\'indirizzo', function () {
    // Chi ha un account suo non deve poter ripartire da zero entrando ogni tanto con la sua password.
    $suo = User::factory()->create(['email_verified_at' => now()]);
    $altro = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 19; $i++) {
        $this->post('/login', ['email' => "condomino{$i}@example.com", 'password' => 'una-password-comune']);
    }

    $this->post('/login', ['email' => $suo->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($suo);
    $this->post('/logout');

    $this->post('/login', ['email' => 'condomino20@example.com', 'password' => 'una-password-comune']);

    $this->post('/login', ['email' => $altro->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('Coda 262 — la frase accanto al campo della password dice il minimo vero della regola', function (string $lingua) {
    // Il numero sta nella frase di ogni lingua (`auth.hint.password_min`) e la regola in `Password::defaults()`:
    // senza questo confronto, cambiare l'una lasciava l'altra a dire un minimo diverso.
    $frase = __('auth.hint.password_min', [], $lingua);
    preg_match_all('/\d+/', $frase, $numeri);
    expect($numeri[0])->toHaveCount(1);
    $minimo = (int) $numeri[0][0];

    $valida = fn (string $password) => \Illuminate\Support\Facades\Validator::make(
        ['password' => $password],
        ['password' => [\Illuminate\Validation\Rules\Password::defaults()]],
    )->passes();

    expect($valida(str_repeat('a', $minimo - 1)))->toBeFalse()
        ->and($valida(str_repeat('a', $minimo)))->toBeTrue();
})->with(['it', 'en', 'pt', 'es']);
