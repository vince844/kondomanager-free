<?php

/**
 * beta.54 — l'ordinamento di un elenco paginato vale su **tutti** i record, non sulla pagina.
 *
 * ## Il difetto che questi test fissano
 *
 * Le tabelle avevano la paginazione lato server e l'ordinamento lato client: la libreria riceveva
 * dieci righe e ordinava quelle. Cliccando su un'intestazione con cinquanta record e la vista a
 * dieci, l'amministratore vedeva il primo **dei primi dieci** presentato come il primo.
 *
 * È un difetto che non dà errore e non lascia traccia: la schermata è coerente con sé stessa e
 * risponde il falso. Segnalato sul forum il 16/08/2026 — *«se ho 50 record ma la visualizzazione è
 * filtrata per 10 alla volta l'ordinamento si applica soltanto su quei 10»*.
 *
 * ## Perché il test guarda la prima pagina e non l'elenco intero
 *
 * Perché è lì che il difetto si manifesta. Un test che chiedesse cinquanta record su cinquanta
 * passerebbe **anche con il codice rotto**: senza paginazione, ordinare la pagina e ordinare tutto
 * sono la stessa cosa. La prova sta nel chiedere la prima pagina di un insieme più grande e
 * pretendere che contenga i primi in assoluto.
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
    $this->tipologia = TipologiaImmobile::firstOrCreate(['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa']);

    // Venticinque unità con nomi in ordine sparso. Il nome che verrebbe **per ultimo** in ordine
    // crescente è inserito per primo, e viceversa: così l'ordine di inserimento non può essere
    // scambiato per un ordinamento riuscito.
    foreach (['Zeta', 'Alfa', 'Mike', 'Bravo', 'Yankee', 'Charlie', 'Xray', 'Delta', 'Whiskey',
              'Echo', 'Victor', 'Foxtrot', 'Uniform', 'Golf', 'Tango', 'Hotel', 'Sierra', 'India',
              'Romeo', 'Juliett', 'Quebec', 'Kilo', 'Papa', 'Lima', 'Oscar'] as $k => $nome) {
        Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome'          => $nome,
            'interno'       => str_pad((string) (25 - $k), 3, '0', STR_PAD_LEFT),
            // ⚠️ Il codice catastale è assegnato **al contrario** del nome: se il codice ignorasse
            // la colonna chiesta e ordinasse comunque per nome, il test lo prenderebbe.
            'codice_catasto' => 'CAT-'.str_pad((string) (25 - $k), 3, '0', STR_PAD_LEFT),
            'descrizione'   => 'Unità di prova',
            'tipologia_id'  => $this->tipologia->id,
        ]);
    }
});

/**
 * ⚠️ `per_page` è **10 e non 5** perché dalla beta.54 i valori ammessi sono quelli che il selettore
 * offre davvero (`config('pagination.consentite')`), e 5 non è fra questi: verrebbe normalizzato a
 * 10 e le attese non tornerebbero. Ciò che il test deve garantire non cambia — venticinque unità
 * chieste dieci alla volta restano un insieme più grande della pagina, che è l'unica condizione in
 * cui il difetto si manifesta.
 */
function primaPagina(array $query = []): array
{
    $r = test()->actingAs(test()->user)
        ->get(route('admin.gestionale.immobili.index', array_merge(
            ['condominio' => test()->condominio, 'per_page' => 10], $query)));

    return collect($r->viewData('page')['props']['immobili'])->pluck('nome')->all();
}

it('la prima pagina contiene i primi in assoluto, non i primi della pagina', function () {
    // Con 25 unità e 10 per pagina: se l'ordinamento valesse solo sulle righe già estratte,
    // qui uscirebbero dieci nomi qualsiasi riordinati fra loro.
    expect(primaPagina(['sort' => 'nome', 'direction' => 'asc']))
        ->toBe(['Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo',
                'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliett']);
});

it('e in ordine decrescente contiene gli ultimi in assoluto', function () {
    expect(primaPagina(['sort' => 'nome', 'direction' => 'desc']))
        ->toBe(['Zeta', 'Yankee', 'Xray', 'Whiskey', 'Victor',
                'Uniform', 'Tango', 'Sierra', 'Romeo', 'Quebec']);
});

it('l\'ordinamento su un campo diverso dal nome non si confonde con l\'ordine di inserimento', function () {
    // Il codice catastale è assegnato al contrario del nome: ordinando per catasto crescente
    // esce l'ultima unità inserita, non «Alfa». Se il codice ignorasse la colonna chiesta e
    // ordinasse comunque per nome, questo test lo direbbe.
    expect(primaPagina(['sort' => 'catasto', 'direction' => 'asc']))
        ->toBe(['Oscar', 'Lima', 'Papa', 'Kilo', 'Quebec',
                'Juliett', 'Romeo', 'India', 'Sierra', 'Hotel']);
});

it('senza ordinamento chiesto ne applica uno stabile, e non lascia decidere al database', function () {
    // ⚠️ Senza `orderBy` MySQL non garantisce nulla fra le pagine: la stessa riga può comparire
    // a pagina 1 e a pagina 2 mentre un'altra non compare affatto. L'elenco unità ordina per
    // nome quando nessuno ha chiesto niente.
    expect(primaPagina())->toBe(['Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo',
        'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliett']);
});

it('una colonna non consentita viene ignorata, non interpolata nella query', function () {
    // `orderBy()` interpola il nome della colonna: un valore che arriva dalla richiesta e finisce
    // lì dentro è un'iniezione. La lista delle colonne ammesse è il confine, non una comodità.
    $r = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.index', [
            'condominio' => $this->condominio,
            'sort'       => 'id) FROM immobili; --',
            'per_page'   => 10,
        ]));

    $r->assertSessionHasErrors('sort');
});

it('«Soggetti» non è fra le colonne ordinabili, perché contiene un elenco', function () {
    // La cella ha per valore un array di persone: senza scegliere quale faccia da chiave,
    // l'ordinamento sarebbe per **quante** ce ne sono. L'intestazione non è cliccabile a video
    // (`enableSorting: false`), e il server non accetta comunque quella chiave.
    expect(array_keys(\App\Http\Requests\Gestionale\Immobile\ImmobileIndexRequest::colonneOrdinabili()))
        ->not->toContain('anagrafiche')
        ->and(array_keys(\App\Http\Requests\Gestionale\Immobile\ImmobileIndexRequest::colonneOrdinabili()))
        ->not->toContain('dati_tecnici');
});

it('l\'ordinamento sopravvive al filtro, e il filtro all\'ordinamento', function () {
    // I due si perdevano a vicenda: la ricerca ricostruiva la richiesta senza `per_page`, la
    // paginazione senza i filtri. Qui viaggiano insieme.
    $nomi = primaPagina(['nome' => 'a', 'sort' => 'nome', 'direction' => 'desc']);

    expect($nomi)->not->toBeEmpty()
        ->and($nomi)->each->toContain('a')
        ->and($nomi)->toBe(collect($nomi)->sortDesc()->values()->all());
});

it('il server dichiara al frontend l\'ordinamento che ha applicato', function () {
    // La freccetta nell'intestazione legge questo, non uno stato locale: così non può dichiarare
    // un ordinamento diverso da quello con cui le righe sono state estratte.
    $props = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.index', [
            'condominio' => $this->condominio, 'sort' => 'nome', 'direction' => 'desc',
        ]))->viewData('page')['props'];

    expect($props['sort'])->toBe('nome')->and($props['direction'])->toBe('desc');
});

/*
|--------------------------------------------------------------------------
| L'invariante che impedisce la trappola di tornare
|--------------------------------------------------------------------------
*/

it('ogni intestazione cliccabile è ammessa dal server, e viceversa', function () {
    // ⚠️ È il controllo che vale più di tutti gli altri, perché la deriva è silenziosa nei due versi:
    //
    //   · una colonna **offerta a video e non ammessa** manda l'amministratore in un errore di
    //     validazione al primo clic — è la trappola: si offre a monte ciò che si vieta a valle;
    //   · una colonna **ammessa e non offerta** è configurazione morta, che nessuno noterà finché
    //     qualcuno non la userà credendola supportata.
    //
    // Il difetto reale, trovato a video il 16/08/2026: «Ubicazione» e «Dati tecnici» erano
    // rimaste cliccabili mentre la lista del server aveva solo tre chiavi.
    $colonne = file_get_contents(resource_path('js/components/gestionale/immobili/columns.ts'));

    preg_match_all("/(?:accessorKey|id):\s*'([^']+)'/", $colonne, $m, PREG_OFFSET_CAPTURE);

    $cliccabili = [];
    foreach ($m[1] as $i => [$chiave, $pos]) {
        if (in_array($chiave, ['actions', 'select'], true)) {
            continue;
        }
        $fine = $m[0][$i + 1][1] ?? strlen($colonne);
        $blocco = substr($colonne, $pos, $fine - $pos);

        if (! str_contains($blocco, 'enableSorting: false')) {
            $cliccabili[] = $chiave;
        }
    }

    sort($cliccabili);
    $ammesse = array_keys(\App\Http\Requests\Gestionale\Immobile\ImmobileIndexRequest::colonneOrdinabili());
    sort($ammesse);

    expect($cliccabili)->toBe($ammesse,
        'le intestazioni cliccabili e le colonne ammesse dal server devono coincidere');
});
