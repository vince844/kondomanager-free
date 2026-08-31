<?php

use App\Models\CategoriaFornitore;
use App\Models\Fornitore;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * «Mostrami tutti gli idraulici»: il filtro per categoria dell'elenco fornitori.
 *
 * ## Perché esiste, e perché è arrivato solo nella 1.11.0-beta.9
 *
 * ⚠️ **La categoria arrivava al browser da sempre e non la usava nessuno.** `FornitoreController::index`
 * la carica con `with(['categoria'])` e `FornitoreResource` la serializza in ogni riga — ma l'elenco
 * **non aveva né una colonna che la mostrasse né un filtro che la usasse**. Si poteva classificare
 * un fornitore e poi non c'era, in nessuna schermata, il modo di chiedere chi fosse cosa.
 *
 * È molto probabilmente la spiegazione della misura che ha aperto questa beta: **sei fornitori su
 * otto senza categoria** (30/08/2026). Non mancavano le categorie giuste — compilarle non serviva
 * a niente.
 *
 * ## Cosa copre
 *
 * Il filtro, la sua forma ad array, il rifiuto di un id inventato, e il fatto che i **filtri
 * applicati tornino al frontend**: senza quest'ultimo la barra nasce vuota su una pagina filtrata e
 * il filtro si perde al primo tocco — è la trappola che l'elenco documenti ha già pagato nella
 * beta.62, e che qui diventa raggiungibile perché il nome di una categoria è un link che porta
 * esattamente lì.
 *
 * ## Cosa NON copre
 *
 * Non copre la resa a schermo della pillola né l'ordinamento per nome della categoria oltre al
 * fatto che sia accettato: la sottoquery correlata che lo esegue è di Eloquent, non nostra.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($this->user);
});

function fornitoreDiCategoria(string $ragioneSociale, ?CategoriaFornitore $categoria): Fornitore
{
    return Fornitore::create([
        'ragione_sociale'            => $ragioneSociale,
        'stato'                      => 'attivo',
        'giorni_scadenza'            => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta'          => false,
        'perc_imponibile_ritenuta'   => 100,
        'categoria_id'               => $categoria?->id,
    ]);
}

it('filtra i fornitori per categoria', function () {
    $idraulico = CategoriaFornitore::where('name', 'Idraulico')->firstOrFail();
    $elettricista = CategoriaFornitore::where('name', 'Elettricista')->firstOrFail();

    fornitoreDiCategoria('Idraulica Tevere S.r.l.', $idraulico);
    fornitoreDiCategoria('Elettro Roma S.n.c.', $elettricista);
    fornitoreDiCategoria('Ditta senza categoria', null);

    $this->get(route('admin.fornitori.index', ['categoria_id' => [$idraulico->id]]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $nomi = collect($pagina->toArray()['props']['fornitori'])->pluck('ragione_sociale');

            expect($nomi)->toContain('Idraulica Tevere S.r.l.')
                ->and($nomi)->not->toContain('Elettro Roma S.n.c.')
                ->and($nomi)->not->toContain('Ditta senza categoria');
        });
});

it('⚠️ ne accetta più d\'una insieme: è un array, non un valore', function () {
    // Chi cerca chi può salire su un tetto vuole muratori **e** lattonieri nello stesso elenco. Con
    // un filtro a valore singolo dovrebbe fare due giri e confrontare a mente.
    $idraulico = CategoriaFornitore::where('name', 'Idraulico')->firstOrFail();
    $elettricista = CategoriaFornitore::where('name', 'Elettricista')->firstOrFail();

    fornitoreDiCategoria('Idraulica Tevere S.r.l.', $idraulico);
    fornitoreDiCategoria('Elettro Roma S.n.c.', $elettricista);
    fornitoreDiCategoria('Ditta senza categoria', null);

    $this->get(route('admin.fornitori.index', ['categoria_id' => [$idraulico->id, $elettricista->id]]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $nomi = collect($pagina->toArray()['props']['fornitori'])->pluck('ragione_sociale');

            expect($nomi)->toContain('Idraulica Tevere S.r.l.')
                ->and($nomi)->toContain('Elettro Roma S.n.c.')
                ->and($nomi)->not->toContain('Ditta senza categoria');
        });
});

it('⚠️ i filtri applicati tornano al frontend, altrimenti la barra nasce vuota', function () {
    // ⚠️ **Questo è il test che protegge il link.** Il nome di una categoria porta all'elenco già
    // filtrato: se `filters` non tornasse indietro, la barra si disegnerebbe senza pillola, e al
    // primo tocco su un altro filtro la richiesta ripartirebbe **senza** `categoria_id`, buttando
    // via un filtro che l'utente non sapeva di avere. Perdita silenziosa, nessun errore.
    $idraulico = CategoriaFornitore::where('name', 'Idraulico')->firstOrFail();

    $this->get(route('admin.fornitori.index', ['categoria_id' => [$idraulico->id]]))
        ->assertOk()
        ->assertInertia(function ($pagina) use ($idraulico) {
            $props = $pagina->toArray()['props'];

            expect($props['filters']['categoria_id'])->toBe([(string) $idraulico->id]);

            // E le categorie per la tendina viaggiano sempre: caricarle pigramente all'apertura del
            // menù lascerebbe la pillola accesa e senza nome quando si arriva già filtrati.
            expect(collect($props['categorie'])->pluck('name'))->toContain('Idraulico');
        });
});

it('⚠️ un id di categoria non valido porta all\'elenco completo, non in un ciclo di redirect', function () {
    // ⚠️ **Il rifiuto ordinario di una FormRequest è `redirect()->back()`, e su un elenco filtrato
    // il `back()` è la pagina filtrata stessa: ciclo infinito.** Non serve un indirizzo scritto a
    // mano per arrivarci: basta tenere aperta la scheda dei fornitori filtrata per «Idraulico»,
    // cancellare quella categoria da un'altra scheda, e ricaricare.
    //
    // Si esce mandando all'elenco **senza filtro**, con la spiegazione: un elenco completo e un
    // messaggio è la cosa giusta quando il filtro chiesto non esiste più. Un elenco vuoto e muto
    // — l'altra strada — farebbe concludere che di idraulici non ce ne sono.
    $risposta = $this->get(route('admin.fornitori.index', ['categoria_id' => [999999]]));

    $risposta->assertRedirect(route('admin.fornitori.index'));

    expect($risposta->getSession()->get('message')['type'])->toBe('info')
        ->and($risposta->getSession()->get('message')['message'])->toContain('non esiste più');

    // E la destinazione **non rifiuta a sua volta**: è questa asserzione che prova l'assenza del
    // ciclo, non la precedente.
    $this->get(route('admin.fornitori.index'))->assertOk();
});

it('un errore di validazione che NON riguarda la categoria torna al comportamento normale', function () {
    // La deviazione vale solo per il filtro della categoria: per tutto il resto il `back()` è
    // giusto, e allargarla sarebbe un modo silenzioso di nascondere gli errori veri.
    $this->get(route('admin.fornitori.index', ['page' => 0]))
        ->assertSessionHasErrors('page');
});

it('l\'elenco si può ordinare per categoria', function () {
    // La colonna è ordinabile con una sottoquery correlata sul nome della categoria, come fa
    // l'elenco documenti: qui si verifica che il nome sia accettato fra le colonne ordinabili —
    // se non lo fosse, la richiesta verrebbe rifiutata e l'intestazione cliccabile non farebbe
    // niente.
    $this->get(route('admin.fornitori.index', ['sort' => 'categoria', 'direction' => 'asc']))
        ->assertOk()
        ->assertSessionHasNoErrors();
});
