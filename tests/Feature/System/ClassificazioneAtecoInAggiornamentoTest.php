<?php

/**
 * L'aggiornamento deve portare a database la classificazione ATECO.
 *
 * ## Perché questo file esiste
 *
 * ⚠️ **È lo stesso guasto della beta.59 sui Comuni, ripetuto nella 1.11.0-beta.8 — e la macchina per
 * non ripeterlo esisteva già.** La classificazione vive come file nel repository e a database ce la
 * porta `AtecoSeeder`. La prima stesura di questa beta aveva la migrazione, il comando, l'endpoint e
 * il componente, e **nessuno dei due agganci**: né `DatabaseSeeder` né
 * `SystemFinalizer::caricaClassificazioneAteco()`. Risultato: su ogni installazione — nuova **e**
 * aggiornata — la migrazione creava la tabella `codici_ateco` e la lasciava vuota, il pulsante
 * accanto al campo «Codice ATECO» non trovava niente per nessuno, e non c'era né un errore né una
 * riga di log. La schermata diceva «la classificazione non è ancora stata caricata su questa
 * installazione», dando la colpa all'installazione di qualcosa che non le era mai stato consegnato.
 *
 * ⚠️ **La suite non se ne accorgeva perché tutti i test chiamano il comando a mano**, o creano le
 * righe con `CodiceAteco::create()`: provavano il caricamento e saltavano la consegna. È la stessa
 * frase che sta scritta nel gemello sui Comuni, e non è bastata a evitarlo — l'ha trovato di nuovo
 * la revisione avversariale, con la suite verde.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il contenuto della classificazione né la ricerca: quelli stanno in
 * `tests/Feature/Ateco/ClassificazioneAtecoTest.php`. Non copre il caso di due revisioni conviventi
 * in tabella, che il comando di caricamento oggi non produce.
 */

use App\Models\CodiceAteco;
use App\Services\System\SystemFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('un\'installazione aggiornata si ritrova la classificazione, non una tabella vuota', function () {
    // Il punto di partenza è quello vero di chi aggiorna: la migrazione ha creato la tabella e
    // nessuno l'ha popolata.
    expect(CodiceAteco::count())->toBe(0);

    app(SystemFinalizer::class)->finalize();

    expect(CodiceAteco::count())->toBeGreaterThan(3000)
        ->and(CodiceAteco::where('codice', '43.22.01')->value('titolo'))
        ->toBe('Installazione di impianti geotermici');
});

it('una prima installazione se la ritrova allo stesso modo', function () {
    // L'altro aggancio, quello di `DatabaseSeeder`. Si chiama la sola classe che serve, come fa il
    // seeder vero: `db:seed` intero qui costruirebbe mezzo condominio dimostrativo.
    expect(CodiceAteco::count())->toBe(0);

    $this->seed(\Database\Seeders\AtecoSeeder::class);

    expect(CodiceAteco::count())->toBeGreaterThan(3000);
});

it('rieseguire la finalizzazione non ricarica la classificazione già presente', function () {
    app(SystemFinalizer::class)->finalize();

    $quando = CodiceAteco::where('codice', '43.22.01')->value('updated_at');

    app(SystemFinalizer::class)->finalize();

    // La guardia serve a questo: `finalize()` gira a ogni aggiornamento, e riscrivere 3.257 righe
    // per non cambiare niente costerebbe a ogni installazione, ogni volta. Sui Comuni la guardia è
    // sulla data; qui una data non esiste, quindi è sulla **revisione**.
    expect(CodiceAteco::where('codice', '43.22.01')->value('updated_at')->toDateTimeString())
        ->toBe($quando->toDateTimeString());
});

it('un caricamento interrotto a metà non viene scambiato per un lavoro finito', function () {
    // ⚠️ Senza la soglia sulle righe, la guardia direbbe «c'è già la revisione spedita» anche con
    // dieci righe in tabella, e l'installazione resterebbe monca per sempre. È il secondo modo di
    // ritrovarsi la classificazione incompleta, e non costa niente presidiarlo.
    CodiceAteco::create([
        'codice' => 'A', 'titolo' => 'Agricoltura', 'livello' => 1,
        'ordine' => 1, 'versione_fonte' => 'ATECO 2025',
    ]);

    app(SystemFinalizer::class)->finalize();

    expect(CodiceAteco::count())->toBeGreaterThan(3000);
});
