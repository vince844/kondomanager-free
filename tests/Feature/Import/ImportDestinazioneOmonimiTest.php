<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportVerificaService;
use App\Services\Import\Livelli\LivelloCondominio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * **Due condomìni con lo stesso nome e senza codice fiscale.**
 *
 * La destinazione si sceglie per **id** — è una riga precisa dell'archivio, indicata col dito.
 * Ma il canonico che se ne ricava porta solo nome e codice fiscale, e al commit
 * `RicercaEsistenti::condominio()` lo ricerca: prima per codice fiscale, e **se manca, per nome
 * minuscolo**. Con due omonimi senza codice fiscale la ricerca ritrova il primo, non quello
 * scelto — e l'id, che era l'unica informazione non ambigua, si è perso per strada.
 *
 * Non è un caso di laboratorio: il codice fiscale del condominio è facoltativo in Kondomanager
 * (nell'archivio di sviluppo cinque condomìni su dodici non ce l'hanno), e chi gestisce più
 * stabili della stessa proprietà si ritrova nomi ripetuti — «Residenza Aurora», «Palazzina B».
 */
function omonimoSenzaCf(string $indirizzo): Condominio
{
    $c = Condominio::create([
        'nome' => 'RESIDENZA OMONIMA',
        'codice_fiscale' => null,
        'indirizzo' => $indirizzo,
        'cap' => '00100',
        'comune' => 'Roma',
        'provincia' => 'RM',
    ]);

    Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => 'ES-'.$c->id,
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    return $c;
}

it('scrive nel condominio scelto, non nel primo che porta quel nome', function () {
    $primo = omonimoSenzaCf('Via Uno 1');
    $secondo = omonimoSenzaCf('Via Due 2');

    $batch = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => [ImportVerificaService::DESTINAZIONE_CONDOMINIO => $secondo->id],
    ]);
    $batch->update(['decisioni' => app(ImportVerificaService::class)->decisioniImplicite($batch)]);

    $letto = app(ImportVerificaService::class)->verifica($batch->fresh());

    $ctx = new ImportContext($batch);

    foreach ($letto['canonici'] as $livello => $dati) {
        $ctx->conCanonico($livello, $dati);
    }

    app(ImportRunner::class)->esegui($ctx, $batch->fresh()->decisioni ?? [], [LivelloCondominio::CHIAVE]);

    // È l'unica domanda che conta: su quale delle due righe si è posato il livello.
    expect($ctx->risolto(LivelloCondominio::CHIAVE)->id)->toBe($secondo->id);
});
