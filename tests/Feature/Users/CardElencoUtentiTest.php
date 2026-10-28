<?php

/**
 * Coda 398 — le tre card sopra l'elenco utenti (decisione 141.3) e il filtro «Verifica».
 *
 * ## Perché questo file esiste
 *
 * Sopra l'elenco ci sono tre numeri: gli utenti in totale, quelli da verificare (l'email non è verificata,
 * `email_verified_at` vuoto) e quelli sospesi (`suspended_at` pieno, la stessa condizione del filtro «Sospeso»).
 * Come le altre statistiche del progetto contano l'**archivio intero**: non seguono i filtri né la pagina, perché
 * con un filtro «Attivo» acceso la card dei sospesi a zero direbbe il falso. Vengono dal server con la pagina, in
 * una sola query, e contano ciò che l'elenco mostra: a chi ha «Visualizza utenti» l'elenco mostra tutti gli
 * account (la portata toglie i comandi della riga, non le righe), quindi le card contano tutti.
 *
 * Il filtro «Verifica» sta accanto a «Stato»: `verifica[]` con `verificato` e `non_verificato`, validato come
 * `stato`; entrambi i valori sono «tutti», come per `stato`.
 *
 * ## Cosa questo file NON copre
 *
 * La barra e le card a video (`resources/js/components/users/DataTableToolbar.test.ts`, `UtentiStats.test.ts`,
 * `resources/js/pages/utenti/ElencoUtenti.test.ts`); i filtri per ruolo e per stato, in `FiltriElencoUtentiTest.php`.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Sette account con numeri tutti diversi fra loro (7 in totale, 3 da verificare, 2 sospesi, 4 verificati, 5 non
    // sospesi): una condizione capovolta o spostata sul caso vicino cambia almeno una cifra.
    $this->amministratore = User::factory()->create(['name' => 'Anna Admin', 'email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['name' => 'Bruno Collaboratore', 'email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    // Un account verificato e sospeso, uno non verificato e sospeso (sta in due card), due non verificati attivi,
    // uno verificato attivo.
    User::factory()->create(['name' => 'Carla Sospesa', 'email_verified_at' => now(), 'suspended_at' => now()])
        ->assignRole(RoleEnum::UTENTE->value);
    User::factory()->create(['name' => 'Dario Invitato Sospeso', 'email_verified_at' => null, 'suspended_at' => now()])
        ->assignRole(RoleEnum::UTENTE->value);
    User::factory()->create(['name' => 'Elena Invitata', 'email_verified_at' => null])
        ->assignRole(RoleEnum::UTENTE->value);
    User::factory()->create(['name' => 'Fabio Invitato', 'email_verified_at' => null])
        ->assignRole(RoleEnum::FORNITORE->value);
    User::factory()->create(['name' => 'Giulia Condomina', 'email_verified_at' => now()])
        ->assignRole(RoleEnum::UTENTE->value);
});

/** Le props dell'elenco, come le riceve la pagina. */
function propsElencoUtenti(User $chi, array $parametri = []): array
{
    return test()->actingAs($chi)
        ->get(route('utenti.index', $parametri))
        ->assertOk()
        ->viewData('page')['props'];
}

/** I nomi che l'elenco restituisce con il filtro dato, in ordine alfabetico. */
function nomiConFiltro(array $parametri): array
{
    return collect(propsElencoUtenti(test()->amministratore, $parametri + ['per_page' => 50])['users'])->pluck('name')->all();
}

// ─── Le tre cifre ─────────────────────────────────────────────────────────────────────────────────────

it('Coda 398 — le card contano gli utenti in totale, quelli da verificare e quelli sospesi', function () {
    $stats = propsElencoUtenti($this->amministratore)['stats'];

    expect($stats)->toBe(['totale' => 7, 'da_verificare' => 3, 'sospesi' => 2]);
});

it('Coda 398 — l\'amministratore è contato nel totale, il sospeso che non ha verificato l\'email in due card', function () {
    // Il totale è l'elenco intero, amministratori compresi; un account sospeso e non verificato sta in entrambe
    // le card, perché sono due domande diverse.
    $stats = propsElencoUtenti($this->amministratore)['stats'];

    expect($stats['totale'])->toBe(User::count())
        ->and($stats['da_verificare'])->toBe(User::whereNull('email_verified_at')->count())
        ->and($stats['sospesi'])->toBe(User::whereNotNull('suspended_at')->count())
        ->and(User::whereNull('email_verified_at')->whereNotNull('suspended_at')->count())->toBe(1);
});

it('Coda 398 — i numeri sono zero, non assenti, quando nessun account è sospeso o da verificare', function () {
    // Il confine in basso: la pagina riceve `0` e lo mostra, non un vuoto.
    User::query()->update(['suspended_at' => null, 'email_verified_at' => now()]);

    $stats = propsElencoUtenti($this->amministratore)['stats'];

    expect($stats)->toBe(['totale' => 7, 'da_verificare' => 0, 'sospesi' => 0]);
});

it('Coda 398 — un account che viene sospeso o che perde la verifica cambia le card alla richiesta dopo', function () {
    expect(propsElencoUtenti($this->amministratore)['stats'])->toBe(['totale' => 7, 'da_verificare' => 3, 'sospesi' => 2]);

    User::where('name', 'Giulia Condomina')->update(['suspended_at' => now(), 'email_verified_at' => null]);

    expect(propsElencoUtenti($this->amministratore)['stats'])->toBe(['totale' => 7, 'da_verificare' => 4, 'sospesi' => 3]);
});

// ─── L'archivio intero, non le righe filtrate né la pagina ────────────────────────────────────────────

it('Coda 398 — le card non cambiano con i filtri attivi: contano l\'archivio, non le righe filtrate', function () {
    $senzaFiltri = propsElencoUtenti($this->amministratore)['stats'];

    $conFiltri = [
        ['stato' => ['attivo']],
        ['stato' => ['sospeso']],
        ['verifica' => ['verificato']],
        ['verifica' => ['non_verificato']],
        ['roles' => [RoleEnum::AMMINISTRATORE->value]],
        ['name' => 'Anna'],
        ['name' => 'nessuno si chiama così'],
        ['stato' => ['attivo'], 'verifica' => ['non_verificato'], 'roles' => [RoleEnum::UTENTE->value], 'name' => 'Elena'],
    ];

    foreach ($conFiltri as $parametri) {
        expect(propsElencoUtenti($this->amministratore, $parametri)['stats'])->toBe($senzaFiltri);
    }
});

it('Coda 398 — le card non cambiano con la pagina: contano l\'archivio, non le righe in pagina', function () {
    User::factory()->count(15)->create();
    $senzaPaginazione = propsElencoUtenti($this->amministratore, ['per_page' => 50])['stats'];

    expect($senzaPaginazione['totale'])->toBe(22);

    foreach ([['per_page' => 10], ['per_page' => 10, 'page' => 2], ['per_page' => 10, 'page' => 3]] as $parametri) {
        expect(propsElencoUtenti($this->amministratore, $parametri)['stats'])->toBe($senzaPaginazione);
    }
});

it('Coda 398 — le card non cambiano con l\'ordinamento', function () {
    $stats = propsElencoUtenti($this->amministratore)['stats'];

    expect(propsElencoUtenti($this->amministratore, ['sort' => 'last_login_at', 'direction' => 'desc'])['stats'])->toBe($stats)
        ->and(propsElencoUtenti($this->amministratore, ['sort' => 'suspended_at', 'direction' => 'asc'])['stats'])->toBe($stats);
});

it('Coda 398 — i tre numeri vengono da una sola query', function () {
    DB::enableQueryLog();
    propsElencoUtenti($this->amministratore);
    $query = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Il totale, i non verificati e i sospesi si leggono insieme: una somma di `CASE` per card, non tre `count`.
    $delleCard = $query->filter(fn (string $sql) => str_contains(strtolower($sql), 'sum(case'));

    expect($delleCard)->toHaveCount(1)
        ->and(strtolower($delleCard->first()))->toContain('email_verified_at')->toContain('suspended_at');
});

// ─── La portata: chi guarda l'elenco ──────────────────────────────────────────────────────────────────

it('Coda 398 — chi non è amministratore ma vede l\'elenco ha le stesse card, perché l\'elenco gli mostra tutti', function () {
    // L'elenco mostra tutti gli account a chi ha «Visualizza utenti»: la portata toglie ai non
    // amministratori i comandi della riga (`gestibile`), non le righe. Le card contano ciò che l'elenco mostra.
    $segreteria = User::factory()->create(['name' => 'Sara Segreteria', 'email_verified_at' => now()]);
    $segreteria->assignRole(Role::create(['name' => 'Segreteria', 'guard_name' => 'web', 'description' => 'Segreteria']));
    $segreteria->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    foreach ([$this->collaboratore, $segreteria] as $chi) {
        $props = propsElencoUtenti($chi, ['per_page' => 50]);

        expect($props['stats']['totale'])->toBe(count($props['users']))
            ->and($props['stats']['totale'])->toBe(User::count())
            ->and(collect($props['users'])->pluck('id')->all())->toContain($this->amministratore->id);
    }

    expect(propsElencoUtenti($this->collaboratore)['stats'])
        ->toBe(propsElencoUtenti($this->amministratore)['stats']);
});

it('Coda 398 — le card sono lo specchio dell\'elenco: stesse persone che l\'elenco mostra con i filtri', function () {
    // «Da verificare» e «Sospesi» contano esattamente le righe che l'elenco mostra con il filtro corrispondente.
    $props = propsElencoUtenti($this->amministratore);

    expect(count(nomiConFiltro(['verifica' => ['non_verificato']])))->toBe($props['stats']['da_verificare'])
        ->and(count(nomiConFiltro(['stato' => ['sospeso']])))->toBe($props['stats']['sospesi'])
        ->and(count(nomiConFiltro([])))->toBe($props['stats']['totale']);
});

it('Coda 398 — senza «Visualizza utenti» l\'elenco e le card non si vedono', function () {
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(RoleEnum::UTENTE->value);

    $this->actingAs($condomino)->get(route('utenti.index'))->assertForbidden();
});

// ─── Il filtro «Verifica» ─────────────────────────────────────────────────────────────────────────────

it('Coda 398 — il filtro «Verifica» mostra i verificati', function () {
    expect(nomiConFiltro(['verifica' => ['verificato']]))
        ->toBe(['Anna Admin', 'Bruno Collaboratore', 'Carla Sospesa', 'Giulia Condomina']);
});

it('Coda 398 — il filtro «Verifica» mostra i non verificati, sospesi compresi', function () {
    expect(nomiConFiltro(['verifica' => ['non_verificato']]))
        ->toBe(['Dario Invitato Sospeso', 'Elena Invitata', 'Fabio Invitato']);
});

it('Coda 398 — i due valori di «Verifica» insieme sono «tutti», non «nessun risultato»', function () {
    expect(nomiConFiltro(['verifica' => ['verificato', 'non_verificato']]))->toHaveCount(7)
        ->and(nomiConFiltro(['verifica' => ['non_verificato', 'verificato', 'verificato']]))->toHaveCount(7);
});

it('Coda 398 — lo stesso valore di «Verifica» ripetuto è un valore solo, non «tutti»', function () {
    expect(nomiConFiltro(['verifica' => ['verificato', 'verificato']]))
        ->toBe(['Anna Admin', 'Bruno Collaboratore', 'Carla Sospesa', 'Giulia Condomina'])
        ->and(nomiConFiltro(['verifica' => ['non_verificato', 'non_verificato']]))
        ->toBe(['Dario Invitato Sospeso', 'Elena Invitata', 'Fabio Invitato']);
});

it('Coda 398 — «Verifica» si combina con «Stato», con il ruolo e con il nome', function () {
    expect(nomiConFiltro(['verifica' => ['non_verificato'], 'stato' => ['sospeso']]))->toBe(['Dario Invitato Sospeso'])
        ->and(nomiConFiltro(['verifica' => ['non_verificato'], 'stato' => ['attivo']]))->toBe(['Elena Invitata', 'Fabio Invitato'])
        ->and(nomiConFiltro(['verifica' => ['verificato'], 'stato' => ['sospeso']]))->toBe(['Carla Sospesa'])
        ->and(nomiConFiltro(['verifica' => ['non_verificato'], 'roles' => [RoleEnum::FORNITORE->value]]))->toBe(['Fabio Invitato'])
        ->and(nomiConFiltro(['verifica' => ['non_verificato'], 'name' => 'Elena']))->toBe(['Elena Invitata']);
});

it('Coda 398 — «Verifica» lavora sull\'archivio, non sulla pagina', function () {
    // Con dieci righe per pagina e ventidue utenti, un filtro applicato alle sole righe scaricate non
    // troverebbe i non verificati che stanno in fondo all'elenco.
    User::factory()->count(15)->create(['name' => 'Zeta riempitivo', 'email_verified_at' => now()]);

    expect(nomiConFiltro(['verifica' => ['non_verificato'], 'per_page' => 10]))
        ->toBe(['Dario Invitato Sospeso', 'Elena Invitata', 'Fabio Invitato']);
});

it('Coda 398 — un valore inventato di «Verifica» viene rifiutato, non ignorato', function () {
    $this->actingAs($this->amministratore)
        ->get(route('utenti.index', ['verifica' => ['in-attesa']]))
        ->assertSessionHasErrors('verifica.0');

    // Il vecchio nome del valore non è un valore: il filtro non lo accetta.
    $this->actingAs($this->amministratore)
        ->get(route('utenti.index', ['verifica' => ['da_verificare']]))
        ->assertSessionHasErrors('verifica.0');
});

it('Coda 398 — «Verifica» deve essere un elenco', function () {
    $this->actingAs($this->amministratore)
        ->get(route('utenti.index', ['verifica' => 'verificato']))
        ->assertSessionHasErrors('verifica');
});

it('Coda 398 — i filtri attivi tornano alla pagina, «Verifica» compreso', function () {
    $this->actingAs($this->amministratore)
        ->get(route('utenti.index', ['verifica' => ['non_verificato'], 'stato' => ['attivo'], 'name' => 'E']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->where('filters.verifica', ['non_verificato'])
            ->where('filters.stato', ['attivo'])
            ->where('filters.name', 'E'));
});
