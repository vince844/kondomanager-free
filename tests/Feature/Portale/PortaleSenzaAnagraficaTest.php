<?php

/**
 * # Il portale di un account senza anagrafica (decisione 134.4)
 *
 * Le pagine del portale leggono i palazzi dall'anagrafica collegata all'account. Un account con il
 * ruolo di condòmino o di fornitore (o un ruolo su misura senza il pannello) e senza anagrafica — il
 * modulo degli utenti lo permette — trovava la dashboard, gli elenchi di segnalazioni, comunicazioni,
 * documenti e agenda in errore 500: dalla dashboard, la prima pagina dopo l'accesso, voleva dire non
 * entrare affatto.
 *
 * Ora il portale lo manda alla pagina dove completa i suoi dati, la stessa dove lo porta già
 * l'accesso (`RedirectHelper::userHomeRoute`), e la pagina dice perché. Chi ha il pannello e non ha
 * un'anagrafica trovava la dashboard e gli elenchi del portale nello stesso errore: va alla dashboard
 * del pannello, la sua casa, con una frase che dice perché. Chi ha il pannello e un'anagrafica apre il
 * portale come prima.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);
    $altro = User::factory()->create();
    $this->segnalazione = Segnalazione::create([
        'subject' => 'Cancello rotto', 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'created_by' => $altro->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ]);
    $this->comunicazione = Comunicazione::create([
        'subject' => 'Turni', 'description' => 'x', 'priority' => 'media', 'created_by' => $altro->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ]);
    $this->comunicazione->condomini()->attach($this->condominio->id);
    $this->categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'x']);
});

function senzaAnagraficaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Le pagine del portale che chi ha un'anagrafica apre, con i loro parametri. */
function senzaAnagraficaPagine(): array
{
    return [
        'user.dashboard'                 => [],
        'user.segnalazioni.index'        => [],
        'user.segnalazioni.create'       => [],
        'user.segnalazioni.show'         => [test()->segnalazione],
        'user.comunicazioni.index'       => [],
        'user.comunicazioni.create'      => [],
        'user.comunicazioni.show'        => [test()->comunicazione],
        'user.categorie-documenti.index' => [],
        'user.categorie-documenti.show'  => [test()->categoria],
        'user.documenti.create'          => [],
        'user.eventi.index'              => [],
        'user.eventi.create'             => [],
    ];
}

test('decisione 134 — il portale di un condòmino o di un fornitore senza anagrafica non risponde con un errore: lo manda a completare i suoi dati, e la pagina dice perché', function () {
    RuoloSpatie::findOrCreate('Solo consultazione', 'web')->syncPermissions([
        Permission::VIEW_SEGNALAZIONI->value, Permission::VIEW_COMUNICAZIONI->value, Permission::VIEW_ARCHIVE_DOCUMENTS->value,
    ]);
    $frase = __('anagrafiche.account_without_anagrafica');

    foreach ([Role::UTENTE->value, Role::FORNITORE->value, 'Solo consultazione'] as $ruolo) {
        $user = senzaAnagraficaUtente($ruolo);

        foreach (senzaAnagraficaPagine() as $rotta => $parametri) {
            $this->actingAs($user)->get(route($rotta, $parametri))
                ->assertRedirect(route('user.anagrafiche.create'))
                ->assertSessionHas('message', ['type' => 'warning', 'message' => $frase]);
        }

        // La pagina dove arriva si apre (nessun giro di rimandi) e riceve la frase.
        $this->actingAs($user)->get(route('user.dashboard'));
        $pagina = $this->actingAs($user)->get(route('user.anagrafiche.create'))->assertOk();
        expect($pagina->viewData('page')['component'])->toBe('anagrafiche/UserAnagraficaNew')
            ->and($pagina->viewData('page')['props']['flash']['message'] ?? null)->toBe(['type' => 'warning', 'message' => $frase]);
    }
});

test('decisione 134 — senza anagrafica nessun invio dal portale va in porto: la segnalazione non si crea, e la risposta è la stessa pagina', function () {
    $user = senzaAnagraficaUtente(Role::UTENTE->value);

    $this->actingAs($user)->post(route('user.segnalazioni.store'), [
        'subject' => 'Perdita in cantina', 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_private' => false,
    ])->assertRedirect(route('user.anagrafiche.create'));

    expect(Segnalazione::where('subject', 'Perdita in cantina')->exists())->toBeFalse();
});

test('decisione 134 — senza anagrafica restano aperte la pagina dei propri dati e le preferenze delle notifiche', function () {
    $user = senzaAnagraficaUtente(Role::UTENTE->value);

    $this->actingAs($user)->get(route('user.anagrafiche.create'))->assertOk();
    $this->actingAs($user)->get(route('user.settings.notifications.index'))->assertOk();
});

test('decisione 134 — completati i dati, il portale si apre', function () {
    $user = senzaAnagraficaUtente(Role::UTENTE->value);

    $this->actingAs($user)->post(route('user.anagrafiche.store'), [
        'nome' => "Mario D'Angelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U',
    ]);
    expect($user->fresh()->anagrafica)->not->toBeNull();

    $this->actingAs($user->fresh())->get(route('user.dashboard'))->assertOk();
    $this->actingAs($user->fresh())->get(route('user.segnalazioni.index'))->assertOk();
});

test('decisione 134 — chi ha un\'anagrafica apre il portale come prima', function () {
    $user = senzaAnagraficaUtente(Role::UTENTE->value);
    Anagrafica::factory()->create(['user_id' => $user->id])->condomini()->attach($this->condominio->id);
    $user = $user->fresh();

    foreach (['user.dashboard', 'user.segnalazioni.index', 'user.comunicazioni.index', 'user.categorie-documenti.index', 'user.eventi.index'] as $rotta) {
        $this->actingAs($user)->get(route($rotta))->assertOk();
    }
    $this->actingAs($user)->get(route('user.segnalazioni.show', $this->segnalazione))->assertOk();
});

test('decisione 134 — chi ha il pannello e non ha un\'anagrafica non trova il portale in errore: va alla dashboard del pannello, e la pagina dice perché', function () {
    // Prima lo lasciava passare, e la dashboard e gli elenchi del portale rispondevano 500. Non va a
    // completare i dati: la sua casa è il pannello, che non chiede un'anagrafica.
    RuoloSpatie::findOrCreate('Segreteria', 'web')->syncPermissions([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_SEGNALAZIONI->value, Permission::VIEW_COMUNICAZIONI->value,
    ]);
    $frase = "Il portale dei condòmini si apre solo con un'anagrafica collegata all'account, e il tuo non ne ha una: usa il pannello.";

    foreach ([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value, 'Segreteria'] as $ruolo) {
        $user = senzaAnagraficaUtente($ruolo);

        foreach (senzaAnagraficaPagine() as $rotta => $parametri) {
            $this->actingAs($user)->get(route($rotta, $parametri))
                ->assertRedirect(route('admin.dashboard'))
                ->assertSessionHas('message', ['type' => 'warning', 'message' => $frase]);
        }

        // La dashboard del pannello si apre e riceve la frase.
        $this->actingAs($user)->get(route('user.dashboard'));
        $pagina = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        expect($pagina->viewData('page')['component'])->toBe('dashboard/Dashboard')
            ->and($pagina->viewData('page')['props']['flash']['message'] ?? null)->toBe(['type' => 'warning', 'message' => $frase]);
    }
});

test('decisione 134 — chi ha il pannello e un\'anagrafica apre il portale come prima', function () {
    $user = senzaAnagraficaUtente(Role::COLLABORATORE->value);
    Anagrafica::factory()->create(['user_id' => $user->id])->condomini()->attach($this->condominio->id);
    $user = $user->fresh();

    foreach (['user.dashboard', 'user.segnalazioni.index', 'user.comunicazioni.index', 'user.categorie-documenti.index', 'user.eventi.index'] as $rotta) {
        $this->actingAs($user)->get(route($rotta))->assertOk();
    }
    $this->actingAs($user)->get(route('user.segnalazioni.show', $this->segnalazione))->assertOk();
    $this->actingAs($user)->get(route('user.comunicazioni.show', $this->comunicazione))->assertOk();
});

test('decisione 134 — le frasi sulla pagina dei propri dati e sulla dashboard del pannello esistono in ogni lingua', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        foreach (['account_without_anagrafica', 'panel_without_anagrafica'] as $chiave) {
            expect(trans("anagrafiche.{$chiave}", [], $lingua))
                ->not->toBe("anagrafiche.{$chiave}", "{$lingua}: manca {$chiave}");
        }
    }
});
