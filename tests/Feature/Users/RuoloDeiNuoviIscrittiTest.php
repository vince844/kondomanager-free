<?php

/**
 * Coda 258 — il ruolo dei nuovi iscritti deve esistere (decisione 136.2).
 *
 * ## Perché questo file esiste
 *
 * Il ruolo che ricevono i nuovi iscritti dalla registrazione pubblica (e dagli inviti, quando è «utente» o
 * «fornitore»: `RuoloDiChiAccettaUnInvitoTest.php`) è un nome salvato nelle impostazioni generali
 * (`default_user_role`), e l'amministratore lo sceglie fra tutti i ruoli, anche uno su misura (decisione 136.2:
 * resta così). Eliminare o rinominare quel ruolo dalla pagina dei ruoli lasciava il nome senza ruolo: chi si
 * registrava trovava un errore 500, e chi gestisce le impostazioni generali senza essere amministratore non
 * salvava più niente, perché il modulo rimanda il ruolo com'è e la validazione non lo trovava. Ora:
 *
 * - il ruolo dei nuovi iscritti non si elimina, e la pagina dice di sceglierne prima un altro nelle
 *   impostazioni generali;
 * - rinominarlo aggiorna il nome salvato, nella stessa transazione;
 * - se il nome salvato non è più un ruolo (dati di prima di questa versione), chi si registra riceve «utente»;
 * - chi non è amministratore salva le impostazioni generali senza toccare quel nome.
 *
 * ## Cosa questo file NON copre
 *
 * Chi cambia il ruolo dei nuovi iscritti e con quale conferma (`ChiPuoToccareChiTest.php`,
 * `ConfermaPasswordPerIPoteriTest.php`, `ConfermaPasswordPermessiEmailRegistrazioneTest.php`).
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Invito;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
});

function rniRuoloDeiNuoviIscritti(string $nome, bool $registrazione = false): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_user_role = $nome;
    $impostazioni->user_frontend_registration = $registrazione;
    $impostazioni->save();
}

function rniRuolo(string $nome): Role
{
    $ruolo = Role::create(['name' => $nome, 'guard_name' => 'web', 'description' => $nome]);
    $ruolo->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value);

    return $ruolo;
}

/** Il modulo delle impostazioni generali come lo manda la pagina, dai dati che riceve. */
function rniImpostazioni($test, User $chi, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];

    return array_merge([
        'user_frontend_registration' => (bool) $props['can_register'],
        'language'                   => $props['language'],
        'app_name'                   => $props['app_name'],
        'open_condominio_on_login'   => (bool) $props['open_condominio_on_login'],
        'default_condominio_id'      => $props['default_condominio_id'] ? (string) $props['default_condominio_id'] : '',
        'default_user_role'          => $props['default_user_role'],
        'force_comment_moderation'   => (bool) $props['force_comment_moderation'],
        'default_per_page'           => (int) $props['default_per_page'],
    ], $modifiche);
}

// ─── Il ruolo non si elimina ──────────────────────────────────────────────────────────────────────────

it('Coda 258 — il ruolo dei nuovi iscritti non si elimina: la pagina dei ruoli dice di sceglierne prima un altro nelle impostazioni generali', function () {
    $ruolo = rniRuolo('Condomino plus');
    $socio = User::factory()->create(['email_verified_at' => now()]);
    $socio->assignRole($ruolo);
    rniRuoloDeiNuoviIscritti('Condomino plus', registrazione: true);

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'error')
        ->assertSessionHas('message.message', __('ruoli.cannot_delete_registration_role', ['role' => 'Condomino plus']));

    expect(Role::where('name', 'Condomino plus')->exists())->toBeTrue()
        ->and($socio->fresh()->getRoleNames()->all())->toBe(['Condomino plus']);
});

it('Coda 258 — il ruolo dei nuovi iscritti non si elimina nemmeno se il nome salvato ha altre maiuscole', function () {
    // Con il MySQL di serie (collazione che non distingue le maiuscole) «condomino plus» trova il ruolo
    // «Condomino plus», e la registrazione glielo assegna: il ruolo è in uso anche così.
    $ruolo = rniRuolo('Condomino plus');
    rniRuoloDeiNuoviIscritti('condomino plus');

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'error');

    expect(Role::where('name', 'Condomino plus')->exists())->toBeTrue();
});

it('Coda 258 — l\'elenco dei ruoli dice quale è il ruolo dei nuovi iscritti, anche se il nome salvato ha altre maiuscole', function () {
    // Con questo la riga apre, prima dell'invio, la finestra che dice di sceglierne prima un altro.
    rniRuolo('Condomino plus');
    rniRuolo('Consigliere');
    rniRuoloDeiNuoviIscritti('condomino plus');

    $ruoli = collect($this->actingAs($this->amministratore)->get(route('ruoli.index'))->assertOk()->viewData('page')['props']['roles']);
    $ruoli = collect($ruoli['data'] ?? $ruoli);

    expect($ruoli->where('id', Role::where('name', 'Condomino plus')->value('id'))->first()['is_registration_role'])->toBeTrue()
        ->and($ruoli->reject(fn ($r) => $r['id'] === Role::where('name', 'Condomino plus')->value('id'))->pluck('is_registration_role')->unique()->values()->all())->toBe([false]);
});

it('Coda 258 — in portoghese la finestra e la frase del server chiamano il ruolo con la stessa parola del resto delle frasi dei ruoli', function () {
    // La finestra di «Elimina» sul ruolo dei nuovi iscritti mostra il titolo (`registration_role_dialog_title`) e la
    // frase che il server dà anche al rifiuto (`cannot_delete_registration_role`): un ruolo ha un nome solo, quello
    // che le frasi dei ruoli usano già in quella lingua (`success_delete_with_reassign`).
    $ruolo = rniRuolo('Condomino plus');
    rniRuoloDeiNuoviIscritti('Condomino plus');
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = 'pt';
    $impostazioni->save();

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertSessionHas('message.type', 'error');

    $restoDelFile = mb_strtolower(__('ruoli.success_delete_with_reassign', ['count' => 1, 'default_role' => 'utente'], 'pt'));
    expect($restoDelFile)->toContain('perfil')->not->toContain('função');

    foreach ([session('message')['message'], __('ruoli.registration_role_dialog_title', [], 'pt')] as $frase) {
        expect(mb_strtolower($frase))->toContain('perfil')->not->toContain('função');
    }
});

it('Coda 258 — scelto un altro ruolo per i nuovi iscritti, il ruolo di prima si elimina', function () {
    $ruolo = rniRuolo('Condomino plus');
    rniRuoloDeiNuoviIscritti(RoleEnum::UTENTE->value);

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'success');

    expect(Role::where('name', 'Condomino plus')->exists())->toBeFalse();
});

// ─── La rinomina ──────────────────────────────────────────────────────────────────────────────────────

/** Il corpo di `ModificaRuolo.vue` aperta e inviata, costruito dai dati che la pagina riceve. */
function rniCorpoModificaRuolo($test, User $chi, Role $ruolo, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('ruoli.edit', ['ruoli' => $ruolo->id]))->assertOk()->viewData('page')['props'];
    $permessi = collect($props['role']['permissions']);
    $pannello = PermissionEnum::ACCESS_ADMIN_PANEL->value;

    return array_merge([
        'name'        => (string) $props['role']['name'],
        'description' => $props['role']['description'],
        'permissions' => $permessi->reject(fn ($p) => $p['name'] === $pannello)->pluck('id')->values()->all(),
        'accessAdmin' => $permessi->contains(fn ($p) => $p['name'] === $pannello),
    ], $modifiche);
}

function rniRegistrati($test, string $email)
{
    return $test->post('/register', [
        'name'                  => 'Nuovo iscritto',
        'email'                 => $email,
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ]);
}

it('Coda 258 — rinominare il ruolo dei nuovi iscritti aggiorna le impostazioni generali: chi si registra dopo riceve il ruolo con il nome nuovo', function () {
    // Decisione 136.2: nella demo il ruolo dei nuovi iscritti è «Amministratore Demo».
    $ruolo = rniRuolo('Amministratore Demo');
    rniRuoloDeiNuoviIscritti('Amministratore Demo', registrazione: true);

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), rniCorpoModificaRuolo($this, $this->amministratore, $ruolo, ['name' => 'Demo amministratore']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'success');

    expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe('Demo amministratore');

    auth()->logout();
    rniRegistrati($this, 'dopo.la.rinomina@example.com')->assertRedirect(route('verification.notice', absolute: false));

    expect(User::where('email', 'dopo.la.rinomina@example.com')->first()?->getRoleNames()->all())->toBe(['Demo amministratore']);
});

it('Coda 258 — la pagina «Modifica ruolo» del ruolo dei nuovi iscritti aperta e inviata senza toccare niente: chi si registra riceve ancora quel ruolo', function () {
    // Un nome scritto in minuscolo: la pagina lo riceve con l'iniziale maiuscola e, rimandato com'è, il nome
    // del ruolo cambia in quella forma. Le impostazioni seguono il ruolo.
    $ruolo = rniRuolo('condomino plus');
    rniRuoloDeiNuoviIscritti('condomino plus', registrazione: true);

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), rniCorpoModificaRuolo($this, $this->amministratore, $ruolo))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ruoli.index'));

    expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe($ruolo->fresh()->name);

    auth()->logout();
    rniRegistrati($this, 'pagina.invariata@example.com')->assertRedirect(route('verification.notice', absolute: false));

    expect(User::where('email', 'pagina.invariata@example.com')->first()?->roles->pluck('id')->all())->toBe([$ruolo->id]);
});

it('Coda 258 — rinominare il ruolo dei nuovi iscritti aggiorna le impostazioni anche se il nome salvato ha altre maiuscole', function () {
    $ruolo = rniRuolo('Amministratore Demo');
    rniRuoloDeiNuoviIscritti('amministratore demo');

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), rniCorpoModificaRuolo($this, $this->amministratore, $ruolo, ['name' => 'Demo amministratore']))
        ->assertSessionHasNoErrors();

    expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe('Demo amministratore');
});

it('Coda 258 — rinominare un altro ruolo non tocca il ruolo dei nuovi iscritti', function () {
    rniRuolo('Amministratore Demo');
    $consigliere = rniRuolo('Consigliere');
    rniRuoloDeiNuoviIscritti('Amministratore Demo');

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $consigliere->id]))
        ->put(route('ruoli.update', ['ruoli' => $consigliere->id]), rniCorpoModificaRuolo($this, $this->amministratore, $consigliere, ['name' => 'Consigliere di scala']))
        ->assertSessionHasNoErrors();

    expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe('Amministratore Demo')
        ->and($consigliere->fresh()->name)->toBe('Consigliere di scala');
});

it('Coda 258 — se il salvataggio del ruolo non va a buon fine, né il nome del ruolo né le impostazioni generali cambiano', function () {
    $ruolo = rniRuolo('Amministratore Demo');
    rniRuoloDeiNuoviIscritti('Amministratore Demo');

    // Un errore dopo che le impostazioni sono state scritte, prima della fine del salvataggio: tutto torna com'era.
    \Illuminate\Support\Facades\Event::listen(\Spatie\LaravelSettings\Events\SettingsSaved::class, function () {
        throw new \RuntimeException('salvataggio interrotto');
    });

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), rniCorpoModificaRuolo($this, $this->amministratore, $ruolo, ['name' => 'Demo amministratore']))
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'error');

    expect($ruolo->fresh()->name)->toBe('Amministratore Demo')
        ->and(app(GeneralSettings::class)->refresh()->default_user_role)->toBe('Amministratore Demo');
});

// ─── Un nome salvato che non è più un ruolo ───────────────────────────────────────────────────────────

it('Coda 258 — chi si registra riceve il ruolo dei nuovi iscritti scelto dall\'amministratore, anche su misura', function () {
    // Decisione 136.2: il ruolo dei nuovi iscritti resta libero (per esempio «Amministratore Demo» nella demo).
    rniRuolo('Amministratore Demo');
    rniRuoloDeiNuoviIscritti('Amministratore Demo', registrazione: true);

    $this->post('/register', [
        'name'                  => 'Ospite della demo',
        'email'                 => 'ospite.demo@example.com',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice', absolute: false));

    expect(User::where('email', 'ospite.demo@example.com')->first()?->getRoleNames()->all())->toBe(['Amministratore Demo']);
});

it('Coda 258 — se il ruolo dei nuovi iscritti non esiste più, chi si registra riceve il ruolo utente invece di un errore', function () {
    rniRuoloDeiNuoviIscritti('Ruolo eliminato prima', registrazione: true);

    $this->post('/register', [
        'name'                  => 'Nuovo iscritto',
        'email'                 => 'nuovo.iscritto@example.com',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice', absolute: false));

    expect(User::where('email', 'nuovo.iscritto@example.com')->first()?->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 258 — se il ruolo dei nuovi iscritti non esiste più, chi accetta un invito riceve il ruolo utente invece di un errore', function () {
    rniRuoloDeiNuoviIscritti('Ruolo eliminato prima');
    $invito = Invito::create([
        'email'          => 'invitato@example.com',
        'building_codes' => [],
        'expires_at'     => now()->addDays(Invito::GIORNI_DI_VALIDITA),
    ]);

    $this->post((new InviteUserNotification($invito))->toMail($invito)->actionUrl, [
        'name'                  => 'Invitato',
        'email'                 => 'invitato@example.com',
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'invitato@example.com')->first()?->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 258 — chi non è amministratore salva le impostazioni generali anche se il ruolo dei nuovi iscritti non esiste più, e quel nome resta com\'era', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(Role::create(['name' => 'Contabile', 'guard_name' => 'web'])->givePermissionTo([
        PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::MANAGE_GENERAL_SETTINGS->value,
    ]));
    rniRuoloDeiNuoviIscritti('Ruolo eliminato prima');

    $this->actingAs($contabile)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), rniImpostazioni($this, $contabile, ['app_name' => 'Condominio «Aurora»']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));

    $impostazioni = app(GeneralSettings::class)->refresh();
    expect($impostazioni->app_name)->toBe('Condominio «Aurora»')
        ->and($impostazioni->default_user_role)->toBe('Ruolo eliminato prima');
});

it('Coda 258 — l\'amministratore, con il ruolo dei nuovi iscritti che non esiste più, ne sceglie uno che esiste', function () {
    rniRuoloDeiNuoviIscritti('Ruolo eliminato prima');

    // Rimandato com'è, il nome non passa: la tendina dell'amministratore offre i ruoli che esistono.
    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), rniImpostazioni($this, $this->amministratore))
        ->assertSessionHasErrors('default_user_role');

    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), rniImpostazioni($this, $this->amministratore, ['default_user_role' => RoleEnum::UTENTE->value]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));

    expect(app(GeneralSettings::class)->refresh()->default_user_role)->toBe(RoleEnum::UTENTE->value);
});
