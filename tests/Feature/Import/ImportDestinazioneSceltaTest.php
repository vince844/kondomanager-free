<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Services\Import\ImportVerificaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * «Importa dentro **questo** condominio».
 *
 * Segnalato il 28/08/2026 da un amministratore che aveva esportato da Danea con «Import/Export
 * tramite Excel» invece che dalle stampe: quegli export sono elenchi senza testata, quindi
 * nessun file poteva dire in quale condominio andassero i dati. Non aveva nessuna strada — e
 * nemmeno l'aggiramento del riparto consuntivo, perché in Danea non aveva consuntivi.
 *
 * La destinazione vive in `import_batches.decisioni`: è una scelta presa prima che si scriva,
 * come «dividi il nome doppio», e `condominio_id` significa già un'altra cosa — il condominio
 * *creato* dall'importazione.
 */
function condominioDiDestinazione(): Condominio
{
    return Condominio::create([
        'nome' => 'CONDOMINIO DESTINAZIONE',
        'codice_fiscale' => '97123456780',
        'indirizzo' => 'Via della Destinazione 1',
        'cap' => '00100',
        'comune' => 'Roma',
        'provincia' => 'RM',
    ]);
}

it('senza file che dichiarino il condominio e senza scelta, si ferma e lo dice', function () {
    $batch = ImportBatch::create(['sorgente' => 'danea']);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    expect($letto['canonici'])->not->toHaveKey('condominio');

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->toContain('condominio.nessun_file_lo_dichiara');
});

it('scegliendo la destinazione, il condominio arriva dal record scelto e l\'errore sparisce', function () {
    $c = condominioDiDestinazione();

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $c->id],
    ]);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    // Non si inventa niente: nome, codice fiscale e indirizzo vengono dal record scelto, ed è
    // per quel codice fiscale che `RicercaEsistenti` lo ritroverà come esistente invece di
    // creare un secondo condominio.
    expect($letto['canonici']['condominio']->nome)->toBe('CONDOMINIO DESTINAZIONE')
        ->and($letto['canonici']['condominio']->codiceFiscale)->toBe('97123456780')
        ->and($letto['canonici']['condominio']->indirizzo)->toBe('Via della Destinazione 1');

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->not->toContain('condominio.nessun_file_lo_dichiara');
});

it('prende da sé l\'esercizio aperto, che serve alle titolarità', function () {
    $c = condominioDiDestinazione();
    $e = Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2025/2026',
        'data_inizio' => '2025-11-01',
        'data_fine' => '2026-10-31',
        'stato' => 'aperto',
    ]);

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $c->id],
    ]);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    // Nessuno l'ha scelto: le decisioni contengono **solo** il condominio.
    expect($letto['canonici']['esercizi']->etichetta)->toBe('2025/2026')
        ->and($letto['canonici']['esercizi']->dataInizio->toDateString())->toBe('2025-11-01')
        ->and($letto['canonici']['esercizi']->dataFine->toDateString())->toBe('2026-10-31')
        ->and($e->id)->toBeInt();
});

it('non tocca gli esercizi chiusi', function () {
    // ⚠️ È il difetto che la tendina aveva e che nessun test avrebbe visto: elencava **anche**
    // gli esercizi chiusi, e la data di inizio dell'esercizio diventa la `data_inizio` di ogni
    // titolarità scritta. Puntare a un anno chiuso non dà nessun errore: scrive numeri giusti
    // nel periodo sbagliato.
    $c = condominioDiDestinazione();
    Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2023/2024',
        'data_inizio' => '2023-11-01',
        'data_fine' => '2024-10-31',
        'stato' => 'chiuso',
    ]);

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $c->id],
    ]);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    expect($letto['canonici'])->toHaveKey('condominio')
        ->and($letto['canonici'])->not->toHaveKey('esercizi');
});

it('si ferma prima, se il condominio scelto non ha un esercizio aperto', function () {
    // ⚠️ Il livello «Esercizi» è il **secondo**, e `ImportRunner::esegui()` fa `return` al primo
    // che non passa: cadendo lì, soggetti, unità, titolarità, tabelle e saldi non vengono nemmeno
    // tentati. Misurato: zero unità scritte, e la schermata prometteva il contrario.
    //
    // Il livello un messaggio ce l'ha già — `esercizi.dati_assenti` — ma dice «nessuno dei file
    // dichiara il periodo» e manda a cercare una stampa con la testata: qui non è colpa dei file,
    // e quella stampa non risolverebbe niente.
    $c = condominioDiDestinazione();

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $c->id],
    ]);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    expect($letto['canonici']['condominio']->nome)->toBe('CONDOMINIO DESTINAZIONE')
        ->and($letto['canonici'])->not->toHaveKey('esercizi');

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->toContain('esercizio.condominio_senza_esercizio_aperto');

    // E non si passa: è un errore, non un avviso.
    expect(collect($letto['esiti'])->every(fn ($e) => $e->errori() === 0))->toBeFalse();
});

it('non lo dice quando il condominio arriva dai file: quello è un altro problema, con un altro rimedio', function () {
    // Il `BannerParser` ha già `esercizio.periodo_assente`, che parla della testata. Aggiungere
    // anche il nostro darebbe due errori per la stessa cosa, con due rimedi che si contraddicono.
    $letto = app(ImportVerificaService::class)->verifica(ImportBatch::create(['sorgente' => 'danea']));

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->not->toContain('esercizio.condominio_senza_esercizio_aperto');
});

it('quando i file dichiarano il condominio, la scelta non porta nemmeno il suo esercizio', function () {
    // ⚠️ **Il difetto peggiore introdotto insieme alla funzione, e chiuso lo stesso giorno.**
    //
    // Il condominio era protetto dalla sua guardia, l'esercizio no. Con una testata che porta il
    // condominio **senza** la riga «Periodo:» — e una scelta di destinazione rimasta sul lotto da
    // prima di caricare quel file — uscivano canonici **misti**: condominio dal file, esercizio di
    // un altro stabile. Misurato prima della correzione: condominio «A DAL FILE», esercizio
    // «ESERCIZIO-DI-B».
    //
    // Nessun errore, nessun avviso, nessuna quadratura fuori posto: ogni titolarità di A sarebbe
    // nata con la `data_inizio` dell'esercizio di B, e ci si accorge al primo riparto.
    $b = condominioDiDestinazione();
    Esercizio::create([
        'condominio_id' => $b->id,
        'nome' => 'ESERCIZIO-DI-B',
        'data_inizio' => '2020-01-01',
        'data_fine' => '2020-12-31',
        'stato' => 'aperto',
    ]);

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $b->id],
    ]);

    $servizio = app(ImportVerificaService::class);
    $metodo = new ReflectionMethod($servizio, 'applicaDestinazione');

    $dopo = $metodo->invoke($servizio, $batch, [
        'condominio' => new App\Services\Import\Canonical\CanonicalCondominio(
            nome: 'A DAL FILE',
            codiceFiscale: '97000000001',
        ),
    ]);

    expect($dopo['condominio']->nome)->toBe('A DAL FILE')
        ->and($dopo)->not->toHaveKey('esercizi');
});

it('un condominio scelto e poi cancellato non lascia un canonico inventato', function () {
    $c = condominioDiDestinazione();
    $id = $c->id;
    $c->delete();

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $id],
    ]);

    $letto = app(ImportVerificaService::class)->verifica($batch);

    expect($letto['canonici'])->not->toHaveKey('condominio');

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->toContain('condominio.nessun_file_lo_dichiara');
});

it('quello che i file dichiarano vince sulla scelta manuale', function () {
    // La destinazione è un ripiego per quando i file non dicono niente, non un modo di
    // sovrascriverli: se una stampa porta la testata, quella è la verità del documento.
    $c = condominioDiDestinazione();

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $c->id],
    ]);

    $servizio = app(ImportVerificaService::class);
    $metodo = new ReflectionMethod($servizio, 'applicaDestinazione');
    $giaLetto = ['condominio' => new App\Services\Import\Canonical\CanonicalCondominio(
        nome: 'QUELLO DEL FILE',
        codiceFiscale: '97000000000',
    )];

    $dopo = $metodo->invoke($servizio, $batch, $giaLetto);

    expect($dopo['condominio']->nome)->toBe('QUELLO DEL FILE');
});
