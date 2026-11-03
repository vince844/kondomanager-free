<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 185 allargata agli eventi (decisione del 30/09/2026
 * dopo la revisione avversariale): `EventoPolicy::update` e `delete` rispondevano sì a **qualunque**
 * evento a chi aveva `EDIT_EVENTS` o `DELETE_EVENTS`. Con quei permessi concessi da soli, senza
 * «Accesso pannello amministratore», un condòmino di un palazzo nascondeva, spostava o cancellava la
 * rata di un altro palazzo, e con `DELETE_EVENTS` anche i compiti dell'amministratore
 * (`VERIFICA_PAGAMENTO`, `CONTROLLO_INCASSI`). Dopo una modifica `created_by` diventava il suo, e da
 * lì bastavano anche `EDIT_OWN_EVENTS` e `DELETE_OWN_EVENTS`.
 *
 * Con i ruoli predefiniti non si raggiunge: `UTENTE` non ha quei permessi, e `COLLABORATORE` ha
 * `EDIT_EVENTS` insieme al pannello.
 *
 * **Cosa resta scoperto**: `ComunicazionePolicy` e `DocumentoPolicy` hanno la stessa forma; sono
 * trattate nei loro file di test. Il ramo `EDIT_OWN_EVENTS` / `DELETE_OWN_EVENTS` non cambia.
 */

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Un condòmino con ruolo UTENTE, un'anagrafica in `$condominio` e i permessi indicati concessi
 * direttamente — mai l'accesso al pannello.
 *
 * @param  array<int, string>  $permessi
 * @return array{0: User, 1: Anagrafica}
 */
function condominoConPermessoEventi(Condominio $condominio, array $permessi): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $user->givePermissionTo($permessi);

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return [$user, $anagrafica];
}

/** Stessa forma di `EventiRataCondomino::crea()`. */
function rataDi(Anagrafica $anagrafica, Condominio $condominio, User $staff): Evento
{
    $evento = Evento::create([
        'title'       => 'Scadenza rata 1',
        'start_time'  => now()->addDays(10),
        'end_time'    => now()->addDays(10)->endOfDay(),
        'created_by'  => $staff->id,
        'visibility'  => VisibilityStatus::PRIVATE->value,
        'is_approved' => true,
        'tipo'        => EventoTipo::SCADENZA_RATA_CONDOMINO,
        'meta'        => ['status' => 'pending', 'importo_restante' => 10000],
    ]);
    $evento->anagrafiche()->attach($anagrafica->id);
    $evento->condomini()->attach($condominio->id);

    return $evento;
}

function eventoPubblicoDi(Condominio $condominio, User $staff): Evento
{
    $evento = Evento::create([
        'title'       => 'Assemblea',
        'start_time'  => now()->addDays(5),
        'end_time'    => now()->addDays(5)->addHours(2),
        'created_by'  => $staff->id,
        'visibility'  => VisibilityStatus::PUBLIC->value,
        'is_approved' => true,
    ]);
    $evento->condomini()->attach($condominio->id);

    return $evento;
}

test('EDIT_EVENTS senza pannello non tocca la rata di un altro palazzo, né via HTTP né dopo', function () {
    $a = Condominio::factory()->create();
    $b = Condominio::factory()->create();
    [, $titolare] = condominoConPermessoEventi($a, []);
    [$estraneo] = condominoConPermessoEventi($b, [Permission::EDIT_EVENTS->value, Permission::EDIT_OWN_EVENTS->value]);
    $rata = rataDi($titolare, $a, $this->staff);
    $categoria = CategoriaEvento::create(['name' => 'Prova', 'description' => 'Prova']);

    $this->actingAs($estraneo)->put(route('user.eventi.update', $rata), [
        'title'         => 'Titolo cambiato',
        'start_time'    => now()->addDay()->toDateTimeString(),
        'end_time'      => now()->addDay()->addHour()->toDateTimeString(),
        'category_id'   => $categoria->id,
        'condomini_ids' => [$b->id],
        'mode'          => 'all',
    ])->assertForbidden();

    $dopo = $rata->fresh();
    expect($dopo->title)->toBe('Scadenza rata 1')
        ->and($dopo->visibility)->toBe(VisibilityStatus::PRIVATE->value)
        ->and($dopo->created_by)->toBe($this->staff->id)
        ->and($dopo->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all())->toBe([$a->id]);
});

test('EDIT_EVENTS senza pannello non tocca la rata di un vicino dello stesso palazzo', function () {
    $condominio = Condominio::factory()->create();
    [, $titolare] = condominoConPermessoEventi($condominio, []);
    [$vicino] = condominoConPermessoEventi($condominio, [Permission::EDIT_EVENTS->value]);
    $rata = rataDi($titolare, $condominio, $this->staff);

    expect($vicino->can('update', $rata))->toBeFalse();
});

test('DELETE_EVENTS senza pannello non cancella la rata né il compito dell\'amministratore', function () {
    $condominio = Condominio::factory()->create();
    [, $titolare] = condominoConPermessoEventi($condominio, []);
    [$user] = condominoConPermessoEventi($condominio, [Permission::DELETE_EVENTS->value]);
    $rata = rataDi($titolare, $condominio, $this->staff);
    $compito = InboxService::createTask(
        tipo: EventoTipo::VERIFICA_PAGAMENTO,
        title: 'Verifica pagamento',
        description: 'Compito di prova',
        scadenza: now(),
        createdByUserId: $this->staff->id,
        condominioId: $condominio->id,
    );

    $this->actingAs($user)->delete(route('user.eventi.destroy', $rata))->assertForbidden();
    $this->actingAs($user)->delete(route('user.eventi.destroy', $compito))->assertForbidden();

    expect(Evento::whereKey([$rata->id, $compito->id])->count())->toBe(2);
});

test('un evento indirizzato ad altre persone resta fuori anche nel proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [, $altraPersona] = condominoConPermessoEventi($condominio, []);
    [$user] = condominoConPermessoEventi($condominio, [Permission::EDIT_EVENTS->value, Permission::DELETE_EVENTS->value]);
    // Un evento agenda senza tipo, creato dall'amministratore e indirizzato a un altro condòmino.
    $evento = eventoPubblicoDi($condominio, $this->staff);
    $evento->anagrafiche()->attach($altraPersona->id);

    expect($user->can('update', $evento))->toBeFalse()
        ->and($user->can('delete', $evento))->toBeFalse();
});

test('un evento con un palazzo altrui fra i suoi resta fuori', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessoEventi($suo, [Permission::EDIT_EVENTS->value]);
    $evento = eventoPubblicoDi($suo, $this->staff);
    $evento->condomini()->attach($altro->id);

    expect($user->can('update', $evento))->toBeFalse();
});

test('EDIT_EVENTS e DELETE_EVENTS senza pannello bastano per un evento pubblico del proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [$user] = condominoConPermessoEventi($condominio, [Permission::EDIT_EVENTS->value, Permission::DELETE_EVENTS->value]);
    $evento = eventoPubblicoDi($condominio, $this->staff);

    expect($user->can('update', $evento))->toBeTrue()
        ->and($user->can('delete', $evento))->toBeTrue();
});

test('un utente senza anagrafica con EDIT_EVENTS e senza pannello non modifica niente', function () {
    $condominio = Condominio::factory()->create();
    $evento = eventoPubblicoDi($condominio, $this->staff);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->givePermissionTo(Permission::EDIT_EVENTS->value);

    expect($user->can('update', $evento))->toBeFalse();
});

test('amministratore e collaboratore, con il pannello, modificano e cancellano ogni evento', function () {
    $condominio = Condominio::factory()->create();
    [, $titolare] = condominoConPermessoEventi($condominio, []);
    $rata = rataDi($titolare, $condominio, $this->staff);

    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(Role::AMMINISTRATORE->value);
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    expect($amministratore->can('update', $rata))->toBeTrue()
        ->and($amministratore->can('delete', $rata))->toBeTrue()
        ->and($collaboratore->can('update', $rata))->toBeTrue();
});
