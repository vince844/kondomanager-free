<?php

/**
 * La pagina del 429: chi supera un limite legge che cosa è successo e quanto aspettare, nella lingua
 * dell'installazione.
 *
 * ## Perché questo file esiste
 *
 * I limiti di rotta (le prove della password attuale, i moduli per ospiti, il login) rispondono 429.
 * Senza una pagina nostra il framework mostrava la sua, in inglese e senza il tempo da aspettare: per un
 * modulo Inertia (la conferma della password, il profilo, l'eliminazione dell'account) dentro la finestra
 * che Inertia apre per una risposta che non è una pagina. Le chiamate JSON ricevono ancora il JSON del
 * framework, con `Retry-After`: le pagine che le fanno scrivono il loro messaggio (`impostazioniBackups.vue`).
 */

use App\Enums\BackupStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->utente = User::factory()->create(['email_verified_at' => now()]);
    $this->utente->assignRole(RoleEnum::UTENTE->value);
});

function intestazioniInertia(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
}

it('Coda 261 — il 429 della conferma della password si legge nella lingua dell\'installazione, con il tempo da aspettare', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
            ->post('/confirm-password', ['password' => "sbagliata-{$i}"]);
    }

    $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
        ->post('/confirm-password', ['password' => 'password'])
        ->assertStatus(429)
        ->assertSee(__('errors.429_heading'))
        ->assertSee(__('errors.429_message'))
        ->assertSee(trans_choice('errors.429_retry_minutes', 1))
        ->assertDontSee('Too Many Requests')
        ->assertSee(route('user.dashboard'), false);
});

it('Coda 261 — il 429 dell\'eliminazione dell\'account dice i minuti che mancano alla fine dell\'ora', function () {
    // Il tempo fermo: l'ora comincia con la prima prova, al secondo, e i secondi veri che le prove impiegano non
    // spostano il `Retry-After` atteso.
    $this->freezeTime();

    // Trenta prove in sei minuti: si ferma il limite dell'ora, e alla sua fine mancano cinquantaquattro minuti.
    for ($minuto = 0; $minuto < 6; $minuto++) {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->utente)->from('/settings/profile')->withHeaders(intestazioniInertia())
                ->delete('/settings/profile', ['password' => "sbagliata-{$minuto}-{$i}"]);
        }
        $this->travel(61)->seconds();
    }

    $risposta = $this->actingAs($this->utente)->from('/settings/profile')->withHeaders(intestazioniInertia())
        ->delete('/settings/profile', ['password' => 'password']);

    $risposta->assertStatus(429)
        ->assertSee(trans_choice('errors.429_retry_minutes', 54))
        ->assertDontSee('Too Many Requests');

    expect((int) $risposta->headers->get('Retry-After'))->toBe(3600 - 6 * 61)
        ->and(User::find($this->utente->id))->not->toBeNull();
});

it('il 429 di un modulo per ospiti si legge nella lingua dell\'installazione e riporta al login', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->post('/forgot-password', ['email' => "nessuno-{$i}@example.com"]);
    }

    $this->post('/forgot-password', ['email' => 'nessuno@example.com'])
        ->assertStatus(429)
        ->assertSee(__('errors.429_heading'))
        ->assertSee(route('login'), false)
        ->assertDontSee('Too Many Requests');
});

it('una chiamata JSON che supera il limite riceve ancora il JSON, con i secondi da aspettare', function () {
    // È la forma che legge la finestra del ripristino: la pagina scrive il suo messaggio, sotto la password.
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($this->utente)->postJson('/confirm-password', ['password' => "sbagliata-{$i}"]);
    }

    $risposta = $this->actingAs($this->utente)->postJson('/confirm-password', ['password' => 'password']);

    $risposta->assertStatus(429)->assertJsonStructure(['message']);
    expect((int) $risposta->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

afterEach(function () {
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
});

/** La lingua scelta nelle impostazioni generali dell'installazione. */
function linguaDellInstallazione(string $lingua): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = $lingua;
    $impostazioni->save();
}

/**
 * Una richiesta nuova parte dalla lingua del `.env` (`APP_LOCALE`), come all'avvio di un processo: nei test
 * l'applicazione è la stessa per tutte le richieste, e quelle di prima hanno già messo la lingua delle impostazioni
 * (anche in `config('app.locale')`, che `setLocale` riscrive).
 */
function comeUnaRichiestaNuova(string $linguaDelEnv): void
{
    app()->setLocale($linguaDelEnv);
}

it('Coda 261 — quando il limite del minuto e quello dell\'ora finiscono insieme, la pagina del 429 dice i minuti dell\'ora', function () {
    // Il tempo fermo: le attese si contano dalla prima prova al secondo, senza i secondi veri che le prove impiegano.
    $this->freezeTime();

    // Quattro minuti da sei prove sbagliate e sei nel quinto: la trentesima prova dell'ora è anche la sesta del minuto.
    for ($minuto = 0; $minuto < 5; $minuto++) {
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
                ->post('/confirm-password', ['password' => "sbagliata-{$minuto}-{$i}"]);
        }
        if ($minuto < 4) {
            $this->travel(61)->seconds();
        }
    }

    // Alla fine dell'ora, che comincia con la prima prova, mancano 3600 − 4 × 61 secondi: 56 minuti, per eccesso.
    $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
        ->post('/confirm-password', ['password' => 'password'])
        ->assertStatus(429)
        ->assertSee(trans_choice('errors.429_retry_minutes', (int) ceil((3600 - 4 * 61) / 60)))
        ->assertDontSee(trans_choice('errors.429_retry_minutes', 1));
});

it('Coda 261 — il 429 di chi è collegato si legge nella lingua scelta nelle impostazioni generali, non in quella del .env', function (string $lingua) {
    linguaDellInstallazione($lingua);

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
            ->post('/confirm-password', ['password' => "sbagliata-{$i}"]);
    }

    comeUnaRichiestaNuova('it');

    $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
        ->post('/confirm-password', ['password' => 'password'])
        ->assertStatus(429)
        ->assertSee('<html lang="'.$lingua.'">', false)
        ->assertSee(__('errors.429_heading', [], $lingua))
        ->assertSee(trans_choice('errors.429_retry_minutes', 1, [], $lingua));
})->with(['en', 'pt', 'es']);

it('Coda 261 — il 429 di un modulo per ospiti si legge nella lingua scelta nelle impostazioni generali, non in quella del .env', function () {
    linguaDellInstallazione('en');

    for ($i = 0; $i < 6; $i++) {
        $this->post('/forgot-password', ['email' => "nessuno-{$i}@example.com"]);
    }

    comeUnaRichiestaNuova('it');

    $this->post('/forgot-password', ['email' => 'nessuno@example.com'])
        ->assertStatus(429)
        ->assertSee('<html lang="en">', false)
        ->assertSee(__('errors.429_heading', [], 'en'))
        ->assertSee(__('errors.back_to_login', [], 'en'));
});

it('una richiesta che non supera il limite risponde nella lingua delle impostazioni anche partendo da quella del .env', function () {
    // La controprova del modo in cui i due test qui sopra partono da `APP_LOCALE`: la lingua delle impostazioni arriva.
    linguaDellInstallazione('en');
    comeUnaRichiestaNuova('it');

    $this->actingAs($this->utente)->from('/confirm-password')->withHeaders(intestazioniInertia())
        ->post('/confirm-password', ['password' => 'sbagliata'])
        ->assertSessionHasErrors(['password' => __('auth.password', [], 'en')]);
});

it('Coda 261 — fermato dopo salvataggi che non provano la password, il 429 della finestra dei backup dice quanto aspettare senza parlare di password', function (string $lingua, string $parolaPassword, int $salvataggi, int $alMinuto, int $minutiAttesi) {
    // Il limite delle prove della password attuale conta tutta la rotta del profilo, anche il salvataggio del solo nome:
    // la frase che la finestra dei backup mostra al 429 deve essere vera anche quando nessuna password è stata provata,
    // sia quando è finito il limite del minuto (la forma «un minuto») sia quando è finito quello dell'ora (la forma plurale).
    $this->freezeTime();

    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
    $backup = Backup::create([
        'uuid' => (string) Str::uuid(), 'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);

    for ($i = 1; $i <= $salvataggi; $i++) {
        $this->actingAs($amministratore)->from('/settings/profile')->withHeaders(intestazioniInertia())
            ->patch('/settings/profile', ['name' => "Nome {$i}", 'email' => $amministratore->email])
            ->assertSessionHasNoErrors();
        if ($i % $alMinuto === 0 && $i < $salvataggi) {
            $this->travel(61)->seconds();
        }
    }

    $risposta = $this->actingAs($amministratore)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'password', 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false,
    ]);
    $risposta->assertStatus(429);

    // Come la finestra: i minuti da `Retry-After`, per eccesso (`impostazioniBackups.vue`, `troppeProveDellaPassword`).
    $minuti = (int) ceil(((int) $risposta->headers->get('Retry-After')) / 60);
    $frase = trans_choice('impostazioni.restore_error_too_many', $minuti, ['count' => $minuti], $lingua);
    $senzaTempo = __('impostazioni.restore_error_too_many_later', [], $lingua);

    expect($minuti)->toBe($minutiAttesi)
        ->and(mb_strtolower($frase))->not->toContain($parolaPassword)
        ->and(mb_strtolower($senzaTempo))->not->toContain($parolaPassword)
        ->and($frase)->not->toBe('impostazioni.restore_error_too_many')
        ->and($senzaTempo)->not->toBe('impostazioni.restore_error_too_many_later');
})->with([
    'italiano' => ['it', 'password'],
    'inglese' => ['en', 'password'],
    'portoghese' => ['pt', 'palavra-passe'],
    'spagnolo' => ['es', 'contraseña'],
])->with([
    // Sei salvataggi nello stesso minuto: è finito il minuto, si aspetta un minuto.
    'sei salvataggi in un minuto' => [6, 6, 1],
    // Trenta salvataggi, cinque al minuto per sei minuti: è finita l'ora, cominciata 5 × 61 secondi prima; mancano
    // 3600 − 305 secondi, 55 minuti per eccesso.
    'trenta salvataggi in sei minuti' => [30, 5, 55],
]);
