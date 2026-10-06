<?php

/**
 * Chi può invitare qualcuno dentro l'installazione.
 *
 * ## Perché questo file esiste
 *
 * `Route::resource('/inviti')` monta solo `auth` e `verified`, e fino alla 1.10.0-beta.55
 * `InvitoController` non conteneva **nessun** `Gate::authorize`. Un invito non è una scalata di privilegi — chi accetta si registra
 * con il ruolo predefinito delle impostazioni, e la tabella `inviti` non ha nemmeno una colonna
 * per il ruolo — ma restano tre azioni di governo alla portata di chiunque:
 *
 * 1. **leggere** l'elenco degli invitati, cioè gli indirizzi email di persone in ingresso;
 * 2. **spedire** email di invito a indirizzi scelti da chi vuole, con il nostro dominio;
 * 3. **cancellare** un invito in sospeso, che è sabotaggio dell'accoglienza.
 *
 * Dalla 1.11.0-beta.45 copre anche come nasce un invito: dura tre giorni e il link della mail non
 * ha una scadenza sua, l'email si salva in minuscolo, e un indirizzo già invitato è un errore di
 * validazione che nomina l'indirizzo, non un «errore durante l'invio».
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la registrazione via invito (`/invito/register`), che ha il suo file,
 * `tests/Feature/Auth/RegistrazioneDaInvitoTest.php`. *(Fino alla
 * 1.11.0-beta.44 questa riga diceva che la registrazione «passa da un link firmato»: era firmato
 * solo il link che apre il modulo, non il salvataggio — Coda 223.)*
 */

use App\Enums\Role as RoleEnum;
use App\Models\Invito;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

it('un condòmino non può leggere l\'elenco degli invitati', function () {
    $this->actingAs($this->condomino)
        ->get(route('inviti.index'))
        ->assertForbidden();
});

it('un condòmino non può spedire un invito', function () {
    $this->actingAs($this->condomino)
        ->post(route('inviti.store'), [
            'email'          => 'estraneo@example.test',
            'building_codes' => [],
        ])
        ->assertForbidden();

    expect(Invito::where('email', 'estraneo@example.test')->exists())->toBeFalse();
});

it('un condòmino non può cancellare un invito in sospeso', function () {
    $invito = Invito::create([
        'email'          => 'in.arrivo@example.test',
        'building_codes' => [],
        'expires_at'     => now()->addWeek(),
    ]);

    $this->actingAs($this->condomino)
        ->delete(route('inviti.destroy', ['inviti' => $invito->id]))
        ->assertForbidden();

    expect(Invito::find($invito->id))->not->toBeNull();
});

it('l\'amministratore continua a vedere l\'elenco', function () {
    $this->actingAs($this->amministratore)
        ->get(route('inviti.index'))
        ->assertOk();
});

it('l\'invito vale tre giorni, e il link della mail non ha una scadenza sua', function () {
    $condominio = \App\Models\Condominio::factory()->create(['codice_identificativo' => 'TRE123']);

    $this->actingAs($this->amministratore)
        ->post(route('inviti.store'), ['emails' => ['tre.giorni@example.test'], 'buildings' => [$condominio->codice_identificativo]])
        ->assertSessionHasNoErrors();

    $invito = Invito::where('email', 'tre.giorni@example.test')->firstOrFail();
    expect((int) round(now()->diffInHours($invito->expires_at)))->toBe(72);

    // La scadenza è una sola, quella dell'invito, che il controller controlla e spiega: un link con
    // una scadenza sua farebbe rispondere la firma prima, con il 403 generico.
    Notification::assertSentTo($invito, \App\Notifications\InviteUserNotification::class, function ($notifica) use ($invito) {
        parse_str((string) parse_url($notifica->toMail($invito)->actionUrl, PHP_URL_QUERY), $query);

        return ! isset($query['expires']) && isset($query['signature']);
    });
});

it('l\'email dell\'invito si salva in minuscolo', function () {
    $condominio = \App\Models\Condominio::factory()->create(['codice_identificativo' => 'MIN123']);

    $this->actingAs($this->amministratore)
        ->post(route('inviti.store'), ['emails' => ['Anna.Bianchi@Example.test'], 'buildings' => [$condominio->codice_identificativo]]);

    expect(Invito::where('email', 'anna.bianchi@example.test')->exists())->toBeTrue();
});

it('un secondo invito allo stesso indirizzo dice che l\'indirizzo è già invitato, non che l\'invio è fallito', function () {
    $condominio = \App\Models\Condominio::factory()->create(['codice_identificativo' => 'DUE123']);
    Invito::create(['email' => 'gia.invitato@example.test', 'building_codes' => ['DUE123'], 'expires_at' => now()->addDay()]);

    $this->actingAs($this->amministratore)
        ->post(route('inviti.store'), ['emails' => ['gia.invitato@example.test'], 'buildings' => [$condominio->codice_identificativo]])
        ->assertSessionHasErrors(['emails.0' => __('users.invito_gia_presente', ['input' => 'gia.invitato@example.test'])]);

    expect(Invito::where('email', 'gia.invitato@example.test')->count())->toBe(1);
});
