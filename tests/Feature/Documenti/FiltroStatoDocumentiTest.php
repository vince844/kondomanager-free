<?php

use App\Enums\Permission;
use App\Http\Requests\Documento\DocumentoIndexRequest;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Il filtro «Stato» dell'archivio — 1.11.0-beta.10.
 *
 * ## Il difetto che questo file esiste per non far tornare
 *
 * Il filtro è nato **rotto**, ed è stato trovato in Fase 1-bis provandolo a video: filtrando per
 * «Privato» su due documenti pubblici la tabella restava piena. Nessun errore a schermo, nessuna
 * riga nei log, la pillola «Privato» accesa sopra un elenco che la contraddiceva.
 *
 * La causa è un cambio di tipo che avviene **durante il viaggio**. La barra dei filtri costruisce
 * dei booleani veri — `is_published: [false]` — ma Inertia li serializza nella **query string**,
 * dove i tipi non esistono: al server arriva `['false']`, una stringa. E la regola `boolean` di
 * Laravel accetta `true`, `false`, `0`, `1`, `'0'`, `'1'` e **rifiuta `'true'` e `'false'`**. La
 * richiesta falliva la validazione, tornava indietro con l'elenco non filtrato, e l'unico posto
 * dove l'errore esisteva erano i `props` di Inertia, che nessuno legge.
 *
 * ⚠️ **Nessuno dei test che c'erano poteva vederlo**, e la ragione va detta: chiamavano la rotta
 * con `['is_published' => [true]]`, cioè con il tipo *giusto*. `route()` in un test costruisce sì
 * un indirizzo, ma il valore che ci finisce dentro (`1`) è quello che la regola accetta. **Provare
 * un filtro con un valore che il browser non manda non prova niente**: qui si passano le stringhe
 * `'true'` e `'false'`, che sono quello che viaggia davvero.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la barra dei filtri a schermo — che la pillola si accenda, che il conteggio sia giusto,
 * che «Resetta tutti i filtri» la spenga: quello vive nel template e si prova cliccando. Copre il
 * **contratto del server**, cioè che un indirizzo con dentro le stringhe del browser filtri davvero.
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();

    foreach ([
        Permission::ACCESS_ADMIN_PANEL->value,
        Permission::VIEW_ARCHIVE_DOCUMENTS->value,
    ] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $this->admin->givePermissionTo($nome);
    }

    $this->actingAs($this->admin);

    $condominio = Condominio::factory()->create();
    $categoria  = CategoriaDocumento::where('name', 'Verbali')->firstOrFail();

    $crea = function (string $nome, bool $pubblicato) use ($condominio, $categoria) {
        $documento = Documento::create([
            'name'         => $nome,
            'description'  => 'Documento di prova.',
            'path'         => 'documenti/'.md5($nome).'.pdf',
            'mime_type'    => 'application/pdf',
            'file_size'    => 1024,
            'created_by'   => $this->admin->id,
            'is_published' => $pubblicato,
            'is_approved'  => true,
        ]);

        $documento->categorie()->attach($categoria->id);
        $documento->condomini()->attach($condominio->id);

        return $documento;
    };

    $this->pubblico = $crea('Verbale pubblico', true);
    $this->privato  = $crea('Bozza riservata', false);
});

/** Gli id dei documenti che l'elenco ha davvero restituito. */
function idNellElenco($risposta): array
{
    $ids = [];

    $risposta->assertInertia(function ($pagina) use (&$ids) {
        $ids = collect($pagina->toArray()['props']['documenti'])->pluck('id')->all();
    });

    return $ids;
}

it('⚠️ filtra davvero con le stringhe che manda il browser, non solo con i booleani', function () {
    // È la riga che il difetto avrebbe fatto diventare rossa: `'false'` è **la stringa** che arriva
    // dalla query string, non il booleano che la barra ha in mano prima di partire.
    $risposta = $this->get(route('admin.documenti.index', ['is_published' => ['false']]));

    $risposta->assertOk()->assertSessionHasNoErrors();

    $trovati = idNellElenco($risposta);

    expect(in_array($this->privato->id, $trovati, true))
        ->toBeTrue('il documento privato manca dall\'elenco filtrato per «Privato»')
        ->and(in_array($this->pubblico->id, $trovati, true))
        ->toBeFalse('il documento pubblico compare nell\'elenco filtrato per «Privato»: il filtro non filtra');
});

it('e nell\'altro verso', function () {
    $risposta = $this->get(route('admin.documenti.index', ['is_published' => ['true']]));

    $risposta->assertOk()->assertSessionHasNoErrors();

    $trovati = idNellElenco($risposta);

    expect(in_array($this->pubblico->id, $trovati, true))->toBeTrue()
        ->and(in_array($this->privato->id, $trovati, true))->toBeFalse();
});

it('accetta anche le forme che Laravel riconosceva già, senza cambiarne il senso', function () {
    // ⚠️ La normalizzazione non deve **allargare** ciò che il filtro accetta oltre il necessario:
    // `'1'` e `'0'` passavano già, e devono continuare a voler dire la stessa cosa di prima.
    $trovati = idNellElenco($this->get(route('admin.documenti.index', ['is_published' => ['1']])));

    expect(in_array($this->pubblico->id, $trovati, true))->toBeTrue()
        ->and(in_array($this->privato->id, $trovati, true))->toBeFalse();
});

it('chiedere tutti e due gli stati insieme non nasconde niente', function () {
    $trovati = idNellElenco(
        $this->get(route('admin.documenti.index', ['is_published' => ['true', 'false']]))
    );

    expect(in_array($this->pubblico->id, $trovati, true))->toBeTrue()
        ->and(in_array($this->privato->id, $trovati, true))->toBeTrue();
});

it('⚠️ un valore che non è né vero né falso viene rifiutato, non convertito', function () {
    // La tentazione era `array_map('boolval', ...)`, che avrebbe risolto il difetto e ne avrebbe
    // aperto uno peggiore: `boolval('pippo')` è `true`, quindi un parametro senza senso sarebbe
    // diventato un filtro che *sembra* funzionare. La normalizzazione lascia passare il valore
    // com'è, e la regola `boolean` lo respinge — che è il posto giusto per farlo.
    $this->get(route('admin.documenti.index', ['is_published' => ['pippo']]))
        ->assertSessionHasErrors('is_published.0');
});

it('«Stato» non è fra le colonne ordinabili: al suo posto c\'è il filtro', function () {
    // La freccia sull'intestazione è stata tolta anche dalla tabella (`enableSorting: false` in
    // `resources/js/components/documenti/columns.ts`). Se qualcuno la rimettesse senza rimettere
    // anche la colonna qui, tornerebbe un'intestazione che si clicca e non fa niente.
    expect(array_keys(DocumentoIndexRequest::colonneOrdinabili()))
        ->not->toContain('is_published')
        ->and(array_keys(DocumentoIndexRequest::colonneOrdinabili()))
        ->not->toContain('categoria');
});
