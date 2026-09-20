<?php

/**
 * # `LIMITE_CONDOMINI`: il tetto al numero di condomini (1.11.0-beta.32)
 *
 * Chi ospita KondoManager può dire quanti condomini ha diritto ad avere un'installazione. Il
 * controllo vive in `CondominioService::createCondominioWithEsercizio()`, che è la porta di ogni
 * creazione dall'interfaccia — dimostrativo compreso — e il messaggio è neutro: non dice chi ha
 * messo il limite né come alzarlo.
 *
 * ## Cosa presidiano questi test
 *
 * 1. Senza variabile, o con `0`, non cambia niente per nessuno: è il caso di ogni installazione
 *    autonoma, e va provato prima di tutto il resto.
 * 2. Con un tetto, la creazione oltre il tetto è rifiutata con quel messaggio e senza lasciare
 *    niente a metà (nessun esercizio orfano).
 * 3. Il dimostrativo è un condominio: conta e viene rifiutato allo stesso modo.
 * 4. L'importatore non passa dal servizio e non viene bloccato: porta dentro un archivio che
 *    esiste già. Qui si prova che `Condominio::create()` — la strada che usa — resta libera.
 */

use App\Enums\Permission;
use App\Exceptions\LimiteCondominiRaggiunto;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use App\Services\CondominioService;
use Database\Seeders\CategoriaEventoSeeder;
use Database\Seeders\TipologieImmobiliSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([
        CategoriaEventoSeeder::class,
        TipologieImmobiliSeeder::class,
    ]);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (Permission::cases() as $permesso) {
        Spatie\Permission\Models\Permission::findOrCreate($permesso->value, 'web');
    }
    $ruolo->syncPermissions(Permission::cases());

    $utente = User::factory()->create();
    $utente->assignRole($ruolo);
    $this->actingAs($utente);
});

function datiCondominio(int $n): array
{
    return [
        'nome' => "Condominio {$n}",
        'codice_fiscale' => sprintf('9%010d', $n),
        'indirizzo' => "Via di Prova {$n}",
    ];
}

it('senza variabile, o con 0, non c\'è nessun limite', function (int $valore) {
    config(['kondomanager.limite_condomini' => $valore]);

    foreach (range(1, 3) as $n) {
        app(CondominioService::class)->createCondominioWithEsercizio(datiCondominio($n));
    }

    expect(Condominio::count())->toBe(3);
})->with([0, -1]);

it('con il tetto raggiunto la creazione dall\'interfaccia è rifiutata con un messaggio neutro e senza scrivere', function () {
    config(['kondomanager.limite_condomini' => 2]);

    foreach (range(1, 2) as $n) {
        $this->post(route('condomini.store'), datiCondominio($n))
            ->assertRedirect(route('condomini.index'))
            ->assertSessionHas('message.type', 'success');
    }

    $esercizi = Esercizio::count();

    $this->post(route('condomini.store'), datiCondominio(3))
        ->assertRedirect(route('condomini.index'))
        ->assertSessionHas('message.type', 'error')
        ->assertSessionHas('message.message', 'Questa installazione ha raggiunto il numero massimo di condomini consentiti.');

    expect(Condominio::count())->toBe(2)
        ->and(Esercizio::count())->toBe($esercizi);
});

it('il dimostrativo è un condominio: conta e viene rifiutato allo stesso modo', function () {
    config(['kondomanager.limite_condomini' => 1]);

    Condominio::factory()->create();

    $this->from(route('condomini.index'))
        ->post(route('condomini.dimostrativo.crea'))
        ->assertRedirect(route('condomini.index'))
        ->assertSessionHas('message.message', __('condomini.limite_condomini_raggiunto'));

    expect(Condominio::count())->toBe(1);
});

it('il servizio lancia un\'eccezione propria, che porta il limite', function () {
    config(['kondomanager.limite_condomini' => 1]);
    Condominio::factory()->create();

    expect(fn () => app(CondominioService::class)->createCondominioWithEsercizio(datiCondominio(2)))
        ->toThrow(LimiteCondominiRaggiunto::class);

    try {
        app(CondominioService::class)->createCondominioWithEsercizio(datiCondominio(2));
    } catch (LimiteCondominiRaggiunto $e) {
        expect($e->limite)->toBe(1);
    }
});

it('l\'importatore non passa dal servizio: Condominio::create resta libero oltre il tetto', function () {
    config(['kondomanager.limite_condomini' => 1]);

    Condominio::factory()->count(3)->create();

    expect(Condominio::count())->toBe(3)
        ->and(file_get_contents(app_path('Services/Import/Livelli/LivelloCondominio.php')))
        ->not->toContain('createCondominioWithEsercizio');
});

it('la chiave si legge con un ripiego: senza config/kondomanager.php in cache vale illimitato', function () {
    config()->offsetUnset('kondomanager');
    expect(config('kondomanager.limite_condomini'))->toBeNull();

    Condominio::factory()->count(2)->create();
    app(CondominioService::class)->createCondominioWithEsercizio(datiCondominio(3));

    expect(Condominio::count())->toBe(3);
});
