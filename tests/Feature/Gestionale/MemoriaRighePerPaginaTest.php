<?php

/**
 * beta.54 — quante righe mostra un elenco, e come se lo ricorda.
 *
 * ## Il difetto che questi test fissano
 *
 * *«Nell'anagrafica condomini faccio una ricerca avendo impostato 40 elementi per pagina, me ne
 * vengono fuori solo 10.»* La scelta dell'amministratore non sopravviveva a nulla: né a una
 * ricerca, né a un clic su un'intestazione, né a uscire dall'elenco e rientrarci.
 *
 * ## Cosa NON si ricorda, e perché è qui dentro
 *
 * L'ordinamento è **deliberatamente** escluso dalla memoria, e c'è un test che lo presidia. Il
 * motivo è meccanico: il composable scarta i parametri nulli, quindi «ho tolto l'ordinamento» e
 * «non ne ho parlato» arrivano al server identici. Un ordinamento memorizzato renderebbe il terzo
 * clic sull'intestazione — quello che riporta l'elenco all'ordine naturale — un comando senza
 * effetto, su tutte le tabelle e senza dare errore.
 */

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\PreferenzaTabellaUtente;
use App\Models\TipologiaImmobile;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    $tipologia = TipologiaImmobile::firstOrCreate(['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa']);

    // Sessanta unità: più di ogni valore ammesso tranne il 100, così la pagina è sempre piena e il
    // numero di righe restituite è esattamente il `per_page` risolto.
    for ($i = 1; $i <= 60; $i++) {
        Immobile::create([
            'condominio_id'  => $this->condominio->id,
            'nome'           => 'Unità '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'interno'        => str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'codice_catasto' => 'CAT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'descrizione'    => 'Unità di prova',
            'tipologia_id'   => $tipologia->id,
        ]);
    }

    // ⚠️ **Una richiesta a vuoto per far calcolare a Inertia la versione degli asset.** Prima che
    // una richiesta sia passata dal middleware, `Inertia::getVersion()` è la stringa vuota: i test
    // manderebbero `X-Inertia-Version: ` e il middleware risponderebbe **409** — «gli asset sono
    // cambiati, ricarica» — senza mai arrivare al controller. Peggio, l'esito dipenderebbe
    // dall'ordine, perché la versione una volta calcolata resta impostata per i test successivi
    // dello stesso processo: un test verde da solo e rosso in compagnia, o viceversa.
    test()->actingAs(test()->user)
        ->get(route('admin.gestionale.immobili.index', ['condominio' => test()->condominio]));

    PreferenzaTabellaUtente::query()->delete();
});

/**
 * Una richiesta all'elenco unità. `inertia` distingue la navigazione **dentro** il programma da un
 * indirizzo seguito da fuori, che è la distinzione su cui si decide se ricordare la scelta.
 *
 * ⚠️ `X-Inertia-Version` va mandata insieme a `X-Inertia`: senza, il middleware risponde **409** —
 * il codice con cui Inertia dice al browser «gli asset sono cambiati, ricarica» — e la richiesta
 * non arriva mai al controller.
 */
function elenco(array $query = [], bool $inertia = true)
{
    return test()->actingAs(test()->user)
        ->withHeaders($inertia ? [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion(),
        ] : [])
        ->get(route('admin.gestionale.immobili.index',
            array_merge(['condominio' => test()->condominio], $query)));
}

/**
 * Le props della pagina, da qualunque forma abbia la risposta.
 *
 * ⚠️ Con l'intestazione `X-Inertia` la risposta è **JSON**, non la vista che porta `page` nei suoi
 * dati: è la stessa differenza che distingue la navigazione dentro il programma dall'indirizzo
 * seguito da fuori, cioè proprio ciò che questi test devono saper separare.
 */
function props($risposta): array
{
    return str_contains((string) $risposta->headers->get('content-type'), 'json')
        ? $risposta->json('props')
        : $risposta->viewData('page')['props'];
}

function righeRese($risposta): int
{
    return count(props($risposta)['immobili']);
}

/*
|--------------------------------------------------------------------------
| La memoria
|--------------------------------------------------------------------------
*/

it('ricorda le righe scelte quando si torna sull\'elenco', function () {
    elenco(['per_page' => 50]);

    // Nessun `per_page` nell'indirizzo: è il caso di chi esce dall'elenco e ci rientra dal menu.
    expect(righeRese(elenco()))->toBe(50);
});

it('la scelta su un elenco non tocca gli altri', function () {
    elenco(['per_page' => 50]);

    expect(PreferenzaTabellaUtente::where('user_id', $this->user->id)->count())->toBe(1)
        ->and(PreferenzaTabellaUtente::first()->chiave)->toBe('admin.gestionale.immobili.index');
});

it('la scelta è di chi l\'ha fatta, non di tutti', function () {
    elenco(['per_page' => 50]);

    $altro = User::factory()->create();
    $altro->assignRole('admin');

    $risposta = $this->actingAs($altro)
        ->get(route('admin.gestionale.immobili.index', ['condominio' => $this->condominio]));

    expect(righeRese($risposta))->toBe(10);
});

/*
|--------------------------------------------------------------------------
| Quando si scrive, e quando no
|--------------------------------------------------------------------------
*/

it('un indirizzo aperto da fuori vale per quella volta e non riscrive la preferenza', function () {
    // ⚠️ È il link che un collega incolla in chat, col suo `?per_page=50` dentro. Una `GET` che
    // scrive non è idempotente: senza questa distinzione, aprirlo riscriverebbe la scelta di chi
    // lo apre. Dentro il programma la navigazione passa sempre da Inertia; un indirizzo seguito
    // da fuori no.
    $risposta = elenco(['per_page' => 50], inertia: false);

    expect(righeRese($risposta))->toBe(50)
        ->and(PreferenzaTabellaUtente::count())->toBe(0);
});

it('cambiare pagina non viene scambiato per una scelta', function () {
    // ⚠️ Il difetto che questo test fissa. Il browser rimanda `per_page` a ogni azione — è così che
    // la scelta smette di perdersi cercando o ordinando — quindi anche un semplice «pagina 2»
    // arriva al server con un `per_page` esplicito. Registrarlo come decisione riempirebbe la
    // memoria di valori che nessuno ha scelto, e da lì in poi l'impostazione generale non
    // sposterebbe più nulla per chiunque abbia cambiato pagina almeno una volta.
    elenco(['page' => 2, 'per_page' => 10]);

    expect(PreferenzaTabellaUtente::count())->toBe(0);

    // E la prova che serve: l'impostazione generale continua a comandare.
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_per_page = 40;
    $impostazioni->save();

    expect(righeRese(elenco()))->toBe(40);
});

it('non riscrive quando il valore non cambia', function () {
    elenco(['per_page' => 50]);
    $primaScrittura = PreferenzaTabellaUtente::first()->updated_at;

    // Ogni cambio di pagina riporta `per_page` nell'indirizzo: senza il confronto sarebbe una
    // scrittura identica a ogni clic, su ogni elenco.
    elenco(['per_page' => 50, 'page' => 2]);

    expect(PreferenzaTabellaUtente::first()->updated_at->eq($primaScrittura))->toBeTrue()
        ->and(PreferenzaTabellaUtente::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Il tetto, che normalizza invece di rifiutare
|--------------------------------------------------------------------------
*/

it('un valore fuori dalla lista consentita non è un errore: si ignora', function () {
    // ⚠️ Un `Rule::in` sembrerebbe più rigoroso, ma trasformerebbe un segnalibro `?per_page=200` —
    // legittimo sugli elenchi dei movimenti fino alla beta.53 — in un 422 su una `GET`, che Inertia
    // rimanda indietro senza spiegare niente.
    $risposta = elenco(['per_page' => 1000000]);

    $risposta->assertOk();
    expect(righeRese($risposta))->toBe(10)
        ->and(PreferenzaTabellaUtente::count())->toBe(0);
});

it('una preferenza fuori lista, scritta a mano nel database, viene ignorata', function () {
    // ⚠️ La preferenza salvata e l'impostazione globale **non passano da nessuna validazione**: sono
    // due numeri in due tabelle. Se la lista consentita cambiasse, resterebbero al vecchio valore e
    // finirebbero dritti dentro un `LIMIT`. Il tetto sta dove i valori si risolvono.
    PreferenzaTabellaUtente::create([
        'user_id'  => $this->user->id,
        'chiave'   => 'admin.gestionale.immobili.index',
        'per_page' => 99999,
    ]);

    expect(righeRese(elenco()))->toBe(10);
});

/*
|--------------------------------------------------------------------------
| L'impostazione generale
|--------------------------------------------------------------------------
*/

it('senza una scelta personale vale l\'impostazione generale', function () {
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_per_page = 30;
    $impostazioni->save();

    expect(righeRese(elenco()))->toBe(30);
});

it('la scelta personale vince sull\'impostazione generale', function () {
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_per_page = 30;
    $impostazioni->save();

    elenco(['per_page' => 50]);

    expect(righeRese(elenco()))->toBe(50);
});

it('un elenco che dichiara un proprio valore lo tiene, e la scelta personale lo supera', function () {
    // Il libro giornale parte da venti righe e non da dieci: le scritture sono dense, e vederne
    // dieci significa non vedere una giornata di lavoro. È il gradino sopra le impostazioni
    // generali — con la conseguenza, voluta, che alzare quelle non muove questo elenco.
    $trait = new class
    {
        use \App\Traits\PaginaElenco;

        public function risolvi($request, ?int $predefinito = null): int
        {
            return $this->righePerPagina($request, predefinito: $predefinito);
        }
    };

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->default_per_page = 40;
    $impostazioni->save();

    $richiesta = request();
    $richiesta->setUserResolver(fn () => $this->user);

    expect($trait->risolvi($richiesta, 20))->toBe(20)
        ->and($trait->risolvi($richiesta))->toBe(40);
});

/*
|--------------------------------------------------------------------------
| Ciò che non si ricorda — l'invariante che protegge il terzo clic
|--------------------------------------------------------------------------
*/

it('l\'ordinamento NON si memorizza, così il terzo clic può ancora toglierlo', function () {
    // ⚠️ Il test che vale di più di questo file. Se un domani si aggiungesse la memoria
    // dell'ordinamento, il terzo clic sull'intestazione — quello che riporta l'elenco all'ordine
    // naturale — arriverebbe al server come una richiesta senza `sort`, indistinguibile da «non ne
    // ho parlato», e verrebbe servito con l'ordinamento memorizzato. Il comando smetterebbe di
    // funzionare su tutte le tabelle, senza dare errore.
    elenco(['sort' => 'nome', 'direction' => 'desc']);

    $props = props(elenco());

    expect($props['sort'])->toBeNull()
        ->and($props['direction'])->toBeNull()
        ->and(collect($props['immobili'])->first()['nome'])->toBe('Unità 001');
});

it('i filtri non si memorizzano', function () {
    // Un filtro ricordato fa sparire delle righe senza che nessuno abbia chiesto niente, e chi
    // rientra vede un sottoinsieme credendolo il tutto.
    elenco(['nome' => 'Unità 001']);

    expect(righeRese(elenco()))->toBe(10);
});
