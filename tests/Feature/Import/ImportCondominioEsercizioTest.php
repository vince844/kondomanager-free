<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\SpreadsheetReader;
use Carbon\CarbonImmutable;

/**
 * Lo scheletro verticale: da un file di Danea a un condominio e un esercizio in archivio.
 *
 * È la prima volta che l'importatore **scrive**, e la maggior parte di questi test riguarda i
 * casi in cui deve rifiutarsi di farlo.
 *
 * ## Cosa questi test NON coprono
 *
 * - **L'annullamento del lotto** (§13.2): le righe di `import_batch_items` che lo rendono
 *   possibile vengono scritte e verificate qui, ma il percorso che le rilegge per disfare non
 *   esiste ancora.
 * - **La ripresa dopo interruzione**: lo stato `parziale` e `livello_corrente` vengono scritti,
 *   ma nessuno li rilegge ancora per ripartire da lì.
 * - **I livelli oltre il secondo**: soggetti, unità e titolarità non sono in `ImportRunner`.
 */
function contesto(): ImportContext
{
    return new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
}

function canonicoCondominio(array $override = []): CanonicalCondominio
{
    return new CanonicalCondominio(
        nome: $override['nome'] ?? 'Residenza Prova',
        codiceFiscale: array_key_exists('cf', $override) ? $override['cf'] : '00321760282',
        indirizzo: array_key_exists('indirizzo', $override) ? $override['indirizzo'] : 'Via Roma 15',
        cap: '35100',
        comune: 'Padova',
        provincia: 'PD',
    );
}

function canonicoEsercizio(string $inizio = '2024-11-01', string $fine = '2025-10-31', string $etichetta = '2024/2025'): CanonicalEsercizio
{
    return new CanonicalEsercizio(
        etichetta: $etichetta,
        dataInizio: CarbonImmutable::parse($inizio),
        dataFine: CarbonImmutable::parse($fine),
        tipo: 'ordinario',
    );
}

it('si ferma alle persone quando manca l\'export che le contiene', function () {
    // Il riparto consuntivo porta la testata — condominio ed esercizio — ma non le persone.
    // È il caso di chi carica un file solo: i primi due livelli passano, il terzo dice cosa
    // manca invece di inventarselo.
    $fogli = (new SpreadsheetReader)->leggi(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'));
    $banner = (new BannerParser)->estrai($fogli[0]);

    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, $banner['condominio'])
        ->conCanonico(LivelloEsercizi::CHIAVE, $banner['esercizio']);

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['condominio']->riuscito())->toBeTrue()
        ->and($esiti['esercizi']->riuscito())->toBeTrue()
        ->and($esiti['soggetti']->riuscito())->toBeFalse()
        ->and($esiti['soggetti']->prerequisitiMancanti[0]->codice)->toBe('soggetti.dati_assenti')
        ->and($esiti['soggetti']->prerequisitiMancanti[0]->rimedio)->toContain('Elenco unità');

    $condominio = Condominio::where('codice_fiscale', '00000000001')->first();
    expect($condominio)->not->toBeNull()
        ->and($condominio->nome)->toBe('Fixture')
        ->and($condominio->comune)->toBe('Roma');

    // Le date vengono dal «Periodo:», non dall'etichetta: novembre–ottobre, non l'anno solare.
    $esercizio = Esercizio::where('condominio_id', $condominio->id)->first();
    expect($esercizio->data_inizio->toDateString())->toBe('2024-11-01')
        ->and($esercizio->data_fine->toDateString())->toBe('2025-10-31')
        ->and($esercizio->stato)->toBe('aperto');

    // Quel che è entrato resta: il lotto è parziale, non annullato.
    $batch = $ctx->batch->fresh();
    expect($batch->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and($batch->livello_corrente)->toBe('soggetti')
        ->and($batch->itemsCreati()->count())->toBe(2);
});

it('il gate ferma l\'esercizio quando il condominio non è in archivio', function () {
    // È il caso del concorrente, in miniatura: là erano fatture entrate mentre il piano dei
    // conti non aveva i conti che richiedevano. La verifica non guarda cosa dice il file:
    // guarda cosa c'è a database. Qui il condominio viene risolto e poi **cancellato**, che è
    // il modo più diretto di dimostrare la differenza fra le due domande.
    $condominio = Condominio::create(['nome' => 'Sparito', 'indirizzo' => 'Via X 1']);

    $ctx = contesto()
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio())
        ->risolvi(LivelloCondominio::CHIAVE, $condominio);

    $condominio->delete();

    $mancanti = (new LivelloEsercizi)->verificaPrerequisiti($ctx);

    expect($mancanti)->toHaveCount(1)
        ->and($mancanti[0]->codice)->toBe('esercizi.condominio_mancante')
        // Il rimedio non è opzionale: una diagnosi senza cura lascia l'utente peggio di prima.
        ->and($mancanti[0]->rimedio)->toContain('Importa prima');

    expect(Esercizio::count())->toBe(0);
});

it('non scrive niente quando manca la testata con il condominio', function () {
    $ctx = contesto()->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['condominio']->riuscito())->toBeFalse()
        ->and($esiti['condominio']->prerequisitiMancanti[0]->codice)->toBe('condominio.dati_assenti')
        // Ci si ferma al primo livello che non passa: l'esercizio non è nemmeno stato tentato.
        ->and($esiti)->not->toHaveKey('esercizi')
        ->and(Condominio::count())->toBe(0)
        ->and($ctx->batch->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE);
});

it('rifiuta il condominio senza indirizzo invece di far fallire il database', function () {
    // `condomini.indirizzo` è NOT NULL senza default. Le due scorciatoie sono entrambe
    // peggiori del rifiuto: `null` produce un errore SQL incomprensibile, stringa vuota mette
    // in archivio un condominio senza indirizzo che nessuno correggerà mai.
    $ctx = contesto()->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio(['indirizzo' => null]));

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['condominio']->riuscito())->toBeFalse()
        ->and($esiti['condominio']->rilievi[0]->codice)->toBe('condominio.indirizzo_assente')
        ->and($esiti['condominio']->rilievi[0]->rimedio)->toContain('convocazioni')
        ->and(Condominio::count())->toBe(0);
});

it('chiede cosa fare quando il condominio esiste già, senza toccarlo', function () {
    $preesistente = Condominio::create([
        'nome' => 'Nome Vecchio',
        'indirizzo' => 'Via Vecchia 1',
        'codice_fiscale' => '00321760282',
    ]);

    $ctx = contesto()->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio());

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['condominio']->riuscito())->toBeFalse()
        ->and($esiti['condominio']->rilievi[0]->codice)->toBe('condominio.duplicato')
        // Mai sovrascritto in silenzio: finché non decide l'utente, quello che c'era resta.
        ->and($preesistente->fresh()->nome)->toBe('Nome Vecchio')
        ->and(Condominio::count())->toBe(1);
});

it('unisce al condominio esistente quando l\'utente lo decide', function () {
    $preesistente = Condominio::create([
        'nome' => 'Nome Vecchio',
        'indirizzo' => 'Via Vecchia 1',
        'codice_fiscale' => '00321760282',
    ]);

    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    $esiti = (new ImportRunner)->esegui($ctx, ['condominio:00321760282' => 'unisci']);

    expect($esiti['condominio']->riuscito())->toBeTrue()
        ->and($esiti['condominio']->uniti)->toBe(1)
        ->and($esiti['condominio']->giaAPosto())->toBeTrue()
        ->and($preesistente->fresh()->nome)->toBe('Residenza Prova')
        ->and(Condominio::count())->toBe(1);

    // «Unito» non è «creato»: un annullamento non deve eliminare un record che il lotto non
    // ha creato. La distinzione vive nella riga di `import_batch_items`.
    $item = ImportBatchItem::where('livello', 'condominio')->first();
    expect($item->azione)->toBe(ImportBatchItem::AZIONE_UNITO)
        ->and($ctx->batch->fresh()->itemsCreati()->count())->toBe(1); // solo l'esercizio
});

it('lascia intatto il condominio quando l\'utente sceglie di saltarlo, ma lo usa lo stesso', function () {
    $preesistente = Condominio::create([
        'nome' => 'Nome Vecchio',
        'indirizzo' => 'Via Vecchia 1',
        'codice_fiscale' => '00321760282',
    ]);

    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    $esiti = (new ImportRunner)->esegui($ctx, ['condominio:00321760282' => 'salta']);

    expect($esiti['condominio']->saltati)->toBe(1)
        ->and($preesistente->fresh()->nome)->toBe('Nome Vecchio')
        // «Salta» riguarda i dati, non il collegamento: l'esercizio deve comunque finire su
        // quel condominio, altrimenti l'importazione si interromperebbe senza motivo.
        ->and($esiti['esercizi']->riuscito())->toBeTrue()
        ->and(Esercizio::first()->condominio_id)->toBe($preesistente->id);
});

it('spiega perché non può creare un secondo condominio con lo stesso codice fiscale', function () {
    // Lo schema ha un indice unico su `codice_fiscale`: `crea_nuovo` non è scrivibile. Meglio
    // dirlo che lasciar fallire l'insert con un errore di database.
    Condominio::create(['nome' => 'Primo', 'indirizzo' => 'Via A 1', 'codice_fiscale' => '00321760282']);

    $ctx = contesto()->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio());

    $esiti = (new ImportRunner)->esegui($ctx, ['condominio:00321760282' => 'crea_nuovo']);

    expect($esiti['condominio']->riuscito())->toBeFalse()
        ->and($esiti['condominio']->rilievi[0]->codice)->toBe('condominio.cf_gia_usato')
        ->and($esiti['condominio']->rilievi[0]->rimedio)->toContain('Unisci')
        ->and(Condominio::count())->toBe(1);
});

it('chiede cosa fare quando l\'esercizio con lo stesso periodo esiste già', function () {
    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    (new ImportRunner)->esegui($ctx);
    expect(Esercizio::count())->toBe(1);

    // Secondo lotto, stessi dati.
    $ctx2 = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    $esiti = (new ImportRunner)->esegui($ctx2, ['condominio:00321760282' => 'salta']);

    expect($esiti['esercizi']->riuscito())->toBeFalse()
        ->and($esiti['esercizi']->rilievi[0]->codice)->toBe('esercizi.duplicato')
        ->and($esiti['esercizi']->rilievi[0]->rimedio)->toContain('sdoppierebbero i saldi')
        ->and(Esercizio::count())->toBe(1);
});

it('segnala l\'esercizio che si accavalla senza però bloccarlo', function () {
    // Un avviso non deve fermare niente. È il difetto che ho introdotto scrivendo `EsitoCommit`
    // e che questo test esiste per non far tornare: bastava tenere avvisi e rilievi nello
    // stesso elenco perché un'informazione utile diventasse un muro.
    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio('2024-11-01', '2025-10-31'));
    (new ImportRunner)->esegui($ctx);

    $ctx2 = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio('2025-06-01', '2026-05-31', '2025/2026'));

    $esiti = (new ImportRunner)->esegui($ctx2, ['condominio:00321760282' => 'salta']);

    expect($esiti['esercizi']->riuscito())->toBeTrue()
        ->and($esiti['esercizi']->creati)->toBe(1)
        ->and($esiti['esercizi']->avvisi)->toHaveCount(1)
        ->and($esiti['esercizi']->avvisi[0]->codice)->toBe('esercizi.sovrapposto')
        ->and(Esercizio::count())->toBe(2);
});

it('non segnala una sovrapposizione con sé stesso', function () {
    // Se le sovrapposizioni si calcolassero dopo la creazione, ogni singolo import
    // segnalerebbe un accavallamento inesistente — con l'esercizio appena scritto.
    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['esercizi']->avvisi)->toBeEmpty();
});

it('registra ogni entità toccata con la sua azione, per il rapporto e per l\'annullamento', function () {
    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio());

    (new ImportRunner)->esegui($ctx);

    $items = ImportBatchItem::where('import_batch_id', $ctx->batch->id)->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('livello')->all())->toBe(['condominio', 'esercizi'])
        ->and($items->pluck('azione')->unique()->all())->toBe([ImportBatchItem::AZIONE_CREATO]);

    // La relazione polimorfa risale all'entità vera: è ciò che rende l'annullamento
    // eseguibile senza una colonna su quattordici tabelle.
    expect($items->first()->importabile)->toBeInstanceOf(Condominio::class);
});

it('blocca due esercizi omonimi con periodi diversi, invece di far fallire il database', function () {
    // `esercizi` ha un indice unico su `(condominio_id, nome)`, presente sia su MySQL sia nello
    // schema dei test. Un omonimo con date diverse non è un duplicato: è un conflitto.
    //
    // «Salta» sarebbe la scelta peggiore: userebbe un esercizio che copre un periodo diverso da
    // quello del file, e i saldi finirebbero nell'anno sbagliato senza che niente lo segnali.
    $ctx = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio('2024-11-01', '2025-10-31'));
    (new ImportRunner)->esegui($ctx);

    $ctx2 = contesto()
        ->conCanonico(LivelloCondominio::CHIAVE, canonicoCondominio())
        // Stessa etichetta, periodo spostato di due mesi: è il caso di chi ha esportato con il
        // filtro di date sbagliato.
        ->conCanonico(LivelloEsercizi::CHIAVE, canonicoEsercizio('2025-01-01', '2025-12-31'));

    $esiti = (new ImportRunner)->esegui($ctx2, ['condominio:00321760282' => 'salta']);

    expect($esiti['esercizi']->riuscito())->toBeFalse()
        ->and($esiti['esercizi']->rilievi[0]->codice)->toBe('esercizi.nome_gia_usato')
        ->and($esiti['esercizi']->rilievi[0]->messaggio)->toContain('periodo diverso')
        ->and(Esercizio::count())->toBe(1);
});
