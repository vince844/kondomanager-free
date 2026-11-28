<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La verifica in due passaggi obbligatoria per chi entra nel pannello (Coda 384).
 *
 * Con l'impostazione generale `due_fattori_obbligatoria_pannello` accesa, chi entra nel pannello e non ha
 * confermato la verifica è mandato alla pagina della verifica da ogni pagina del pannello, del gestionale
 * e delle impostazioni; la pagina della verifica lo dice; attivata, entra; disattivarla gli è rifiutato.
 * Il condòmino entra nel portale come sempre. Spenta, nessuno cambia.
 *
 * La catena: impostazione salvata → il rimando → la pagina della verifica → la conferma della password dal suo
 * modulo (`POST /confirm-password`, o la finestra sulla stessa pagina) → le chiamate della verifica → l'avviso di
 * sicurezza «verifica attivata» al titolare. La conferma della password è fra le rotte aperte come le altre:
 * senza, chi deve attivare la verifica resta chiuso fuori.
 */

use App\Enums\Role as RoleEnum;
use App\Http\Middleware\DueFattoriPerIlPannello;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Condominio;
use App\Models\User;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
use App\Services\Notifiche\AvvisiDiSicurezza;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);

    $this->condominio = Condominio::factory()->create();
});

function accendiDueFattoriObbligatoria(bool $accesa = true): void
{
    $settings = app(GeneralSettings::class);
    $settings->due_fattori_obbligatoria_pannello = $accesa;
    $settings->save();
}

function confermaDueFattoriDi(User $user): void
{
    $user->forceFill([
        'two_factor_secret'         => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at'   => now(),
    ])->save();
}

it('accesa, il collaboratore senza verifica è mandato alla pagina della verifica dal pannello e dal gestionale', function () {
    accendiDueFattoriObbligatoria();

    $this->actingAs($this->collaboratore)->get('/admin/dashboard')
        ->assertRedirect(route('two-factor.show'));
    $this->actingAs($this->collaboratore)->get('/admin/gestionale/'.$this->condominio->id.'/struttura')
        ->assertRedirect(route('two-factor.show'));
    $this->actingAs($this->collaboratore)->get('/impostazioni')
        ->assertRedirect(route('two-factor.show'));
});

it('accesa, una scrittura del pannello senza verifica non arriva al controller', function () {
    accendiDueFattoriObbligatoria();

    $this->actingAs($this->collaboratore)
        ->postJson('/impostazioni/generali', ['app_name' => 'Altro nome'])
        ->assertStatus(403)
        ->assertJson(['message' => __('settings.two_factor.obbligatoria_per_il_pannello')]);

    expect(app(GeneralSettings::class)->app_name)->not->toBe('Altro nome');
});

it('accesa, la pagina della verifica si apre e dice perché', function () {
    accendiDueFattoriObbligatoria();

    $this->actingAs($this->collaboratore)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/TwoFactor')
            ->where('obbligatoria', true)
            ->where('confirmed', false));
});

it('accesa, con la verifica confermata il collaboratore entra', function () {
    accendiDueFattoriObbligatoria();
    confermaDueFattoriDi($this->collaboratore);

    $this->actingAs($this->collaboratore)->get('/admin/dashboard')->assertOk();
    $this->actingAs($this->collaboratore)->get('/admin/gestionale/'.$this->condominio->id.'/struttura')
        ->assertOk();
});

it('accesa, il condòmino senza verifica entra nel portale come sempre', function () {
    accendiDueFattoriObbligatoria();

    $risposta = $this->actingAs($this->condomino)->get('/user/dashboard');

    expect($risposta->headers->get('Location'))->not->toBe(route('two-factor.show'));
});

it('spenta, il collaboratore senza verifica entra come sempre', function () {
    accendiDueFattoriObbligatoria(false);

    $this->actingAs($this->collaboratore)->get('/admin/dashboard')->assertOk();
});

it('l\'impostazione che non si legge vale spenta: l\'accesso non si rompe', function () {
    \Illuminate\Support\Facades\DB::table('settings')
        ->where('group', 'general')->where('name', 'due_fattori_obbligatoria_pannello')->delete();
    app()->forgetInstance(GeneralSettings::class);

    expect(DueFattoriPerIlPannello::obbligatoria())->toBeFalse();
    $this->actingAs($this->collaboratore)->get('/admin/dashboard')->assertOk();
});

it('accesa, chi entra nel pannello non disattiva la verifica, e la pagina non lo offre', function () {
    accendiDueFattoriObbligatoria();
    confermaDueFattoriDi($this->collaboratore);

    $this->actingAs($this->collaboratore)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertStatus(403);

    expect($this->collaboratore->fresh()->two_factor_confirmed_at)->not->toBeNull();
    Notification::assertNotSentTo($this->collaboratore, AvvisoDiSicurezzaNotification::class);
});

it('accesa, il condòmino disattiva la sua verifica come sempre', function () {
    accendiDueFattoriObbligatoria();
    confermaDueFattoriDi($this->condomino);

    $this->actingAs($this->condomino)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertSuccessful();

    expect($this->condomino->fresh()->two_factor_confirmed_at)->toBeNull();
});

/** Le intestazioni di un modulo o di una visita di Inertia, e quelle di `fetch`/`axios`: le richieste che la pagina manda davvero. */
function dueFattoriInertia(): array
{
    return [
        'X-Inertia'         => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With'  => 'XMLHttpRequest',
        'Accept'            => 'text/html, application/xhtml+xml',
    ];
}

function dueFattoriJson(): array
{
    return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
}

/** Il codice a sei cifre del momento, dal segreto che la pagina ha ricevuto con il QR. */
function dueFattoriCodice(User $user): string
{
    return (new Google2FA)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
}

it('la catena: l\'amministratore la accende, il collaboratore conferma la password dal modulo, la attiva con ogni chiamata della pagina, entra e riceve l\'avviso', function () {
    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
    confermaDueFattoriDi($amministratore);

    $impostazioni = app(GeneralSettings::class);
    $this->actingAs($amministratore)->post('/impostazioni/generali', [
        'user_frontend_registration'        => false,
        'language'                          => 'it',
        'app_name'                          => $impostazioni->app_name,
        'open_condominio_on_login'          => false,
        'default_user_role'                 => $impostazioni->default_user_role,
        'default_per_page'                  => $impostazioni->default_per_page,
        'due_fattori_obbligatoria_pannello' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();
    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->due_fattori_obbligatoria_pannello)->toBeTrue();

    // Il collaboratore, da un'altra sessione: nessuna conferma della password messa a mano, si passa dal modulo.
    $this->flushSession();
    $this->actingAs($this->collaboratore)->get('/admin/dashboard')->assertRedirect(route('two-factor.show'));
    $this->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ConfirmPassword'));
    // `auth/ConfirmPassword.vue` manda il modulo con Inertia.
    $this->withHeaders(dueFattoriInertia())->from(route('password.confirm'))
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('two-factor.show'));
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    $this->flushHeaders();
    $this->get(route('two-factor.show'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/TwoFactor')->where('obbligatoria', true));

    // Le chiamate di `composables/useTwoFactorAuth.ts`, tutte: il QR e il segreto, la configurazione annullata, i codici di
    // recupero, il QR di nuovo, la conferma con il codice.
    $this->withHeaders(dueFattoriJson())->postJson(route('two-factor.enable'))->assertOk()->assertJsonStructure(['qrCode', 'secret']);
    $this->deleteJson(route('two-factor.disable'))->assertOk();
    $this->postJson(route('two-factor.regenerate-recovery-codes'))->assertOk()->assertJsonStructure(['recovery_codes']);
    $this->postJson(route('two-factor.enable'))->assertOk();
    $this->postJson(route('two-factor.confirm'), ['code' => dueFattoriCodice($this->collaboratore)])
        ->assertOk()->assertJsonStructure(['recovery_codes']);
    $this->flushHeaders();

    $this->actingAs($this->collaboratore->fresh())->get('/admin/dashboard')->assertOk();
    Notification::assertSentTo($this->collaboratore, AvvisoDiSicurezzaNotification::class,
        fn (AvvisoDiSicurezzaNotification $avviso) => $avviso->evento === AvvisiDiSicurezza::DUE_FATTORI_ATTIVATA);

    // Attivata: i codici si rigenerano, la disattivazione è rifiutata, e si esce.
    $this->withHeaders(dueFattoriJson())->postJson(route('two-factor.regenerate-recovery-codes'))->assertOk();
    $this->deleteJson(route('two-factor.disable'))->assertStatus(403)
        ->assertJson(['message' => __('settings.two_factor.disattivazione_bloccata')]);
    expect($this->collaboratore->fresh()->two_factor_confirmed_at)->not->toBeNull();
    $this->flushHeaders();
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('la finestra della password (423, JSON): l\'amministratore che l\'accende senza averla conferma, attiva e arriva alle impostazioni', function () {
    accendiDueFattoriObbligatoria();
    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($amministratore)->get('/impostazioni/generali')->assertRedirect(route('two-factor.show'));
    $this->withHeaders(dueFattoriJson())->postJson(route('two-factor.enable'))
        ->assertStatus(423)->assertJson(['conferma_password' => true]);
    // `FinestraConfermaPassword.vue` manda la password in JSON e rifà la richiesta di prima.
    $this->postJson('/confirm-password', ['password' => 'password'])->assertOk()->assertExactJson(['confermata' => true]);
    $this->postJson(route('two-factor.enable'))->assertOk()->assertJsonStructure(['qrCode', 'secret']);
    $this->postJson(route('two-factor.confirm'), ['code' => dueFattoriCodice($amministratore)])->assertOk();
    $this->flushHeaders();

    $this->actingAs($amministratore->fresh())->get('/impostazioni/generali')->assertOk();
});

it('la finestra della password su una visita di Inertia: il 423, la conferma, la pagina della verifica si apre', function () {
    accendiDueFattoriObbligatoria();

    $this->actingAs($this->collaboratore)->withHeaders(dueFattoriInertia())->get(route('two-factor.show'))
        ->assertStatus(423)->assertJson(['conferma_password' => true]);
    $this->flushHeaders()->withHeaders(dueFattoriJson())->postJson('/confirm-password', ['password' => 'password'])
        ->assertOk()->assertExactJson(['confermata' => true]);
    $this->flushHeaders()->withHeaders(dueFattoriInertia())->get(route('two-factor.show'))
        ->assertOk()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'settings/TwoFactor');
});
