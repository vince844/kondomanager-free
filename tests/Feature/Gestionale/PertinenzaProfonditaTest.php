<?php

/**
 * beta.53 — la **profondità uno** del legame «Pertinenza di», dai due lati.
 *
 * ## Perché un file a parte
 *
 * `PertinenzaLegameTest` fissa la *cardinalità*: che una pertinenza ne abbia un solo principale e
 * che sciogliere il legame non porti via l'unità. Sono proprietà del modello, e si verificano
 * scrivendo sulle colonne. Qui invece si verificano le **regole applicative**, che vivono nella
 * richiesta di validazione e nel controller: passano dall'HTTP perché è lì che qualcuno le può
 * aggirare.
 *
 * ## Perché la profondità uno
 *
 * Una catena `A → B → C` non ha corrispettivo in diritto. L'art. 817 c.c. lega una pertinenza a
 * **un** bene principale, e il vincolo pertinenziale non si trasmette per gradi: se il box serve
 * la cantina che serve l'appartamento, quel che c'è davvero sono due destinazioni distinte
 * dichiarate dal proprietario, non una scala. Ammettere le catene renderebbe ambigua ogni lettura
 * futura — dal massimale sull'unità privata alla convocazione — senza rappresentare niente di reale.
 *
 * ## I due lati, e perché servono entrambi
 *
 * Le regole scritte in prima stesura guardavano tutte il **bersaglio**: l'unità scelta come
 * principale non dev'essere a sua volta una pertinenza. Nessuna guardava il **soggetto**, cioè chi
 * sta compilando. La catena restava perciò raggiungibile dall'altro capo, e senza che nulla
 * protestasse. E la regola sul bersaglio guardava una sola delle due colonne, dimenticando che il
 * caso Tognoli è una pertinenza tanto quanto le altre.
 */

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Models\User;
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
    $this->tipologia = TipologiaImmobile::firstOrCreate(
        ['nome' => 'Appartamento'],
        ['categoria' => 'unita_abitativa']
    );
});

/** Il corpo minimo che la richiesta di aggiornamento pretende, più i campi in prova. */
function corpoAggiornamento(Immobile $i, array $extra = []): array
{
    return array_merge([
        'nome'         => $i->nome,
        'descrizione'  => $i->descrizione ?? 'Unità di prova',
        'interno'      => $i->interno ?? 'X',
        'tipologia_id' => $i->tipologia_id,
    ], $extra);
}

function unitaDi(Condominio $c, string $nome, int $tipologiaId, array $extra = []): Immobile
{
    return Immobile::create(array_merge([
        'condominio_id' => $c->id,
        'nome'          => $nome,
        'interno'       => $nome,
        'descrizione'   => 'Unità di prova',
        'tipologia_id'  => $tipologiaId,
    ], $extra));
}

/*
|--------------------------------------------------------------------------
| Il lato del soggetto: chi ha pertinenze non può diventarne una
|--------------------------------------------------------------------------
*/

it('un\'unità che ha già pertinenze non può diventare a sua volta una pertinenza', function () {
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);
    $negozio      = unitaDi($this->condominio, 'Negozio 2', $this->tipologia->id);

    // L'appartamento ha già un box attaccato.
    unitaDi($this->condominio, 'Box 8', $this->tipologia->id, [
        'pertinenza_di_immobile_id' => $appartamento->id,
    ]);

    // Ora si prova a dichiarare l'appartamento pertinenza del negozio. Ogni regola scritta sul
    // *bersaglio* passa: il negozio non è una pertinenza, non è sé stesso, sta nel condominio.
    $risposta = $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $appartamento]),
            corpoAggiornamento($appartamento, ['pertinenza_di_immobile_id' => $negozio->id]));

    $risposta->assertSessionHasErrors('pertinenza_di_immobile_id');

    expect($appartamento->fresh()->pertinenza_di_immobile_id)->toBeNull();
});

it('lo stesso vale per il caso Tognoli: anche il campo in chiaro rende l\'unità una pertinenza', function () {
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);

    unitaDi($this->condominio, 'Cantina 4', $this->tipologia->id, [
        'pertinenza_di_immobile_id' => $appartamento->id,
    ]);

    $risposta = $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $appartamento]),
            corpoAggiornamento($appartamento, ['pertinenza_di_esterna' => 'Via Verdi 8, interno 2']));

    $risposta->assertSessionHasErrors('pertinenza_di_esterna');

    expect($appartamento->fresh()->pertinenza_di_esterna)->toBeNull();
});

it('il messaggio dice quante pertinenze ci sono e cosa fare, non solo che non si può', function () {
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);
    $altro        = unitaDi($this->condominio, 'Interno 9', $this->tipologia->id);

    foreach (['Box 8', 'Cantina 4', 'Soffitta 1'] as $nome) {
        unitaDi($this->condominio, $nome, $this->tipologia->id, [
            'pertinenza_di_immobile_id' => $appartamento->id,
        ]);
    }

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $appartamento]),
            corpoAggiornamento($appartamento, ['pertinenza_di_immobile_id' => $altro->id]))
        ->assertSessionHasErrors(['pertinenza_di_immobile_id']);

    $errore = session('errors')->first('pertinenza_di_immobile_id');

    expect($errore)->toContain('3 pertinenze')
        ->and($errore)->toContain('Interno 1')
        ->and($errore)->toContain('Scollega');
});

it('un\'unità senza pertinenze resta libera di diventarne una: la regola non è un divieto generale', function () {
    $box          = unitaDi($this->condominio, 'Box 8', $this->tipologia->id);
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $box]),
            corpoAggiornamento($box, ['pertinenza_di_immobile_id' => $appartamento->id]))
        ->assertSessionHasNoErrors();

    expect($box->fresh()->pertinenza_di_immobile_id)->toBe($appartamento->id);
});

/*
|--------------------------------------------------------------------------
| Il lato del bersaglio: entrambe le forme, non una sola
|--------------------------------------------------------------------------
*/

it('un\'unità già legata a un principale esterno non è scegliibile come principale', function () {
    // Il box è pertinenza di un appartamento in un altro condominio: caso Tognoli, art. 9 co. 5
    // L. 122/1989. È una pertinenza a tutti gli effetti, e non può fare da principale a nessuno.
    $boxTognoli = unitaDi($this->condominio, 'Box 15', $this->tipologia->id, [
        'pertinenza_di_esterna' => 'Via Roma 4, interno 6',
    ]);

    $cantina = unitaDi($this->condominio, 'Cantina 2', $this->tipologia->id);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $cantina]),
            corpoAggiornamento($cantina, ['pertinenza_di_immobile_id' => $boxTognoli->id]))
        ->assertSessionHasErrors('pertinenza_di_immobile_id');

    expect($cantina->fresh()->pertinenza_di_immobile_id)->toBeNull();
});

it('e non compare nemmeno nell\'elenco dei principali proponibili', function () {
    unitaDi($this->condominio, 'Box 15', $this->tipologia->id, [
        'pertinenza_di_esterna' => 'Via Roma 4, interno 6',
    ]);
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);
    $cantina      = unitaDi($this->condominio, 'Cantina 2', $this->tipologia->id);

    $risposta = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.edit', [$this->condominio, $cantina]));

    $nomi = collect($risposta->viewData('page')['props']['unitaPrincipali'])
        ->pluck('etichetta')
        ->implode(' | ');

    // Una regola che vieta a valle ciò che offre a monte non è una regola: è una trappola. Il
    // menù deve contenere l'appartamento e non il box già legato altrove.
    expect($nomi)->toContain('Interno 1')
        ->and($nomi)->not->toContain('Box 15')
        ->and($nomi)->not->toContain('Cantina 2');
});

/*
|--------------------------------------------------------------------------
| La tab del menù: presente ovunque, non solo dove qualcuno si è ricordato
|--------------------------------------------------------------------------
*/

it('il conteggio delle pertinenze arriva a tutte le pagine dell\'unità, non solo alla scheda', function () {
    $appartamento = unitaDi($this->condominio, 'Interno 1', $this->tipologia->id);
    unitaDi($this->condominio, 'Box 8', $this->tipologia->id, [
        'pertinenza_di_immobile_id' => $appartamento->id,
    ]);

    // La tab «Pertinenze» del menù dell'unità compare solo se `pertinenze_count` è valorizzato.
    // Con `whenCounted()` il campo mancava su ogni pagina il cui controller non avesse chiesto
    // il conteggio, e la voce di menù appariva e spariva secondo da dove la si guardava.
    $pagine = [
        'admin.gestionale.immobili.show',
        'admin.gestionale.immobili.anagrafiche.index',
        'admin.gestionale.immobili.documenti.index',
    ];

    foreach ($pagine as $rotta) {
        $props = $this->actingAs($this->user)
            ->get(route($rotta, [$this->condominio, $appartamento]))
            ->viewData('page')['props'];

        expect($props['immobile']['pertinenze_count'] ?? null)
            ->toBe(1, "«{$rotta}» non porta il conteggio: la tab sparisce su questa pagina");
    }
});

/*
|--------------------------------------------------------------------------
| Il perimetro del condominio: quello dell'unità, non quello dell'indirizzo
|--------------------------------------------------------------------------
*/

it('il principale si cerca nel condominio dell\'unità, non in quello scritto nell\'indirizzo', function () {
    $altro = Condominio::factory()->create();

    $cantina = unitaDi($this->condominio, 'Cantina 2', $this->tipologia->id);
    $estranea = unitaDi($altro, 'Interno 1 altrove', $this->tipologia->id);

    // La regola di validazione cerca il principale **nel condominio dell'unità che si sta
    // modificando**, non in quello scritto nell'indirizzo. Un legame fra condomìni diversi non ha
    // senso in diritto — il requisito soggettivo dell'art. 817 c.c. presuppone un solo proprietario
    // dei due beni — e soprattutto sfonda il perimetro con cui tutto il resto del gestionale ragiona.
    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$this->condominio, $cantina]),
            corpoAggiornamento($cantina, ['pertinenza_di_immobile_id' => $estranea->id]))
        ->assertSessionHasErrors('pertinenza_di_immobile_id');

    expect($cantina->fresh()->pertinenza_di_immobile_id)->toBeNull();
});

it('e cambiare il condominio nell\'indirizzo non porta da nessuna parte', function () {
    // ⚠️ **Questa è la parte che la beta.66 ha cambiato.** Fino alla beta.65
    // `/gestionale/{condominio}/immobili/{immobile}` risolveva l'unità per sola chiave, e il
    // condominio nell'indirizzo poteva essere un altro: bastava cambiare un numero nell'URL per
    // arrivare all'unità di qualcun altro, e l'unica cosa che ci si parava davanti era la regola di
    // validazione qui sopra — cioè una difesa che vale per *questo* campo e non per il resto.
    //
    // Ora la rotta è vincolata (`scopeBindings()`): l'unità viene cercata **dentro** il condominio
    // dell'indirizzo, e se non c'è la richiesta finisce in 404 senza mai arrivare al controller.
    // Il 404 e non un 403 è voluto: non conferma nemmeno che quell'unità esista.
    $altro = Condominio::factory()->create();
    $cantina = unitaDi($this->condominio, 'Cantina 3', $this->tipologia->id);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.immobili.update', [$altro, $cantina]),
            corpoAggiornamento($cantina, ['pertinenza_di_immobile_id' => null]))
        ->assertNotFound();

    expect($cantina->fresh()->pertinenza_di_immobile_id)->toBeNull();
});
