<?php

/**
 * L'aggiornamento deve portare a database l'elenco dei Comuni.
 *
 * ## Perché questo file esiste
 *
 * È lo stesso guasto dei permessi della beta.55, sulla funzione principale della beta.59, e la
 * revisione avversariale l'ha trovato con la suite **verde**.
 *
 * L'elenco dei Comuni vive come file nel repository e a database ce lo porta `ComuniSeeder`. Quel
 * seeder era agganciato al solo `DatabaseSeeder`, cioè alla **prima installazione** — mentre
 * l'aggiornamento passa da `SystemFinalizer::finalize()`, che per scelta dichiarata **non esegue mai
 * `db:seed` intero**. Risultato: su ogni installazione già esistente la migrazione creava la tabella
 * `comuni` e la lasciava vuota, il pulsante di ricerca non trovava niente per nessuno, e non c'era
 * né un errore né una riga di log.
 *
 * ⚠️ **La suite non se ne accorgeva perché tutti i test chiamano il comando a mano** nel loro
 * `beforeEach`: provavano il caricamento e saltavano la consegna. Questo file prova la consegna.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la prima installazione, che passa da `DatabaseSeeder` e l'elenco l'ha sempre avuto.
 * Non copre il contenuto dell'elenco né la ricerca: quelli stanno in `ElencoComuniTest` e
 * `RicercaComuniTest`.
 */

use App\Models\Comune;
use App\Services\System\SystemFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('un\'installazione aggiornata si ritrova l\'elenco dei Comuni, non una tabella vuota', function () {
    // Il punto di partenza è quello vero di chi aggiorna: la migrazione ha creato la tabella e
    // nessuno l'ha popolata.
    expect(Comune::count())->toBe(0);

    app(SystemFinalizer::class)->finalize();

    expect(Comune::count())->toBeGreaterThan(7000)
        ->and(Comune::where('codice_catasto', 'H501')->value('nome'))->toBe('Roma');
});

it('rieseguire la finalizzazione non ricarica l\'elenco quando è già alla data della fonte', function () {
    app(SystemFinalizer::class)->finalize();

    $quando = Comune::where('codice_catasto', 'H501')->value('updated_at');

    app(SystemFinalizer::class)->finalize();

    // La guardia sulla data serve a questo: `finalize()` gira a ogni aggiornamento e riscrivere
    // 7.894 righe per non cambiare niente costerebbe a ogni installazione, ogni volta.
    expect(Comune::where('codice_catasto', 'H501')->value('updated_at')->toDateTimeString())
        ->toBe($quando->toDateTimeString());
});

it('una sola riga datata avanti non blocca il caricamento di tutte le altre', function () {
    // ⚠️ La via d'uscita `--da` permette di caricare un elenco **parziale**. Con una guardia scritta
    // come «esiste una riga con data ≥ quella spedita», un solo comune caricato a mano con una data
    // futura avrebbe impedito per sempre il caricamento degli altri 7.893. È il secondo modo di
    // ritrovarsi la tabella vuota, e non costa niente presidiarlo.
    Comune::create([
        'codice_catasto' => 'Z999',
        'nome'           => 'Comune Caricato A Mano',
        'sigla'          => 'ZZ',
        'provincia'      => 'Prova',
        'regione'        => 'Prova',
        'fonte_al'       => '2030-01-01',
    ]);

    app(SystemFinalizer::class)->finalize();

    expect(Comune::count())->toBeGreaterThan(7000)
        ->and(Comune::where('codice_catasto', 'Z999')->exists())->toBeTrue();
});
