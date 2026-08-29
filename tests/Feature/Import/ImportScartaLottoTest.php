<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Chiudere un'importazione lasciata a metà.
 *
 * Senza, la schermata d'ingresso mostra **per sempre** l'ultimo lotto in corso: ogni volta che
 * si torna a importare, la prima cosa che si vede è un lavoro che si è deciso di non finire.
 *
 * ## Cosa questi test NON coprono
 *
 * - **La conferma in linea**: è due righe di stato in Vue, si guarda con gli occhi.
 * - **L'annullamento vero** (togliere dall'archivio ciò che è entrato): è un'altra cosa, ha una
 *   condizione da valutare, e arriva con la 1.10.1.
 */
function utenteScarto(): User
{
    Permission::firstOrCreate(['name' => 'Crea condomini', 'guard_name' => 'web']);

    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');

    return $u;
}

function lottoDaScartare(string $stato = ImportBatch::STATO_IN_CORSO): ImportBatch
{
    $file = new UploadedFile(
        base_path('tests/Fixtures/import/danea/elenco_unita.xls'),
        'elenco_unita.xls', 'application/vnd.ms-excel', test: true,
    );

    $batch = app(App\Services\Import\ImportUploadService::class)->creaLotto([$file]);
    $batch->update(['stato' => $stato]);

    return $batch->fresh();
}

beforeEach(fn () => Storage::fake('local'));

it('toglie dalla schermata d\'ingresso il lotto abbandonato', function () {
    $utente = utenteScarto();
    $batch = lottoDaScartare();

    $this->actingAs($utente)->get(route('import.index'))
        ->assertInertia(fn ($p) => $p->where('interrotto.uuid', $batch->uuid));

    $this->actingAs($utente)->delete(route('import.scarta', $batch->uuid))
        ->assertRedirect(route('import.index'));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_ANNULLATO)
        ->and($batch->fresh()->annullato_at)->not->toBeNull();

    $this->actingAs($utente)->get(route('import.index'))
        ->assertInertia(fn ($p) => $p->where('interrotto', null));
});

it('cancella i file caricati, che sono dati altrui su un disco privato', function () {
    $utente = utenteScarto();
    $batch = lottoDaScartare();

    expect(Storage::disk('local')->exists('import/'.$batch->uuid))->toBeTrue();

    $this->actingAs($utente)->delete(route('import.scarta', $batch->uuid));

    expect(Storage::disk('local')->exists('import/'.$batch->uuid))->toBeFalse();
});

it('rifiuta di scartare un\'importazione già arrivata in fondo', function () {
    // Su un lotto completato «scarta» sarebbe una parola sbagliata per «annulla»: farebbe
    // credere che l'archivio torni indietro, e non torna.
    $utente = utenteScarto();
    $batch = lottoDaScartare(ImportBatch::STATO_COMPLETATO);

    $this->actingAs($utente)->delete(route('import.scarta', $batch->uuid))->assertStatus(422);

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

it('dice a che punto era arrivata, in numero e non solo per nome', function () {
    // «Arrivata a Tabelle» da solo non dice se manca poco o molto, ed è l'unica informazione
    // che serve per decidere se riprendere o ricominciare.
    $utente = utenteScarto();
    $batch = lottoDaScartare();
    $batch->update(['livello_corrente' => 'tabelle']);

    $this->actingAs($utente)->get(route('import.index'))
        ->assertInertia(fn ($p) => $p
            // Dalla 1.11.0-beta.4 i livelli sono otto: «Capitoli di spesa» sta terzo, subito
            // dopo gli esercizi, quindi «tabelle» è scivolato dal sesto al settimo posto.
            ->where('interrotto.posizione', 7)
            ->where('interrotto.livelli_totali', 8)
            ->where('interrotto.ha_scritto', false)
        );
});
