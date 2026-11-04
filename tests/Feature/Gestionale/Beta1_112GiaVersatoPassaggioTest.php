<?php

/**
 * Coda 438 — il già versato e un passaggio di proprietà sull'unità dopo la generazione del piano.
 *
 * Il conguaglio del passaggio confronta la sua cifra con quella di un piano generato dopo il passaggio (Coda 160, `ConguaglioPassaggio::confrontoConIlPiano`):
 * per farlo legge il già versato della voce. Dalla Coda 438 legge quello che vale nell'esercizio del piano (decisione 183.1): un già versato indicato poi per
 * un altro esercizio un piano generato dopo non lo toglierebbe, e il confronto con le cifre del già versato non si fa.
 *
 * Lo scenario è quello della Coda 160 (`Beta51PassaggiFrasiTest`): Ugo proprietario per intero, € 300,00 versati da Ugo e € 200,00 dall'unità su «Spese
 * generali» (€ 1.200,00), la vendita a Elsa dall'1/5/2026, il piano del 2026 generato ed emesso prima.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/** Lo scenario della Coda 160 con il piano del 2026 generato ed emesso; il già versato senza esercizio. Restituisce lo scenario e la voce. */
function g112vScenario($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['ripartizione' => ['proprietario' => 100], 'inquilini' => []]);
    b51aPersone($s, ['Elsa']);
    $conto = Conto::where('nome', 'Spese generali')->where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    foreach ([[30000, $s['persone']['Ugo']->id], [20000, null]] as [$importo, $chi]) {
        DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $conto->id,
            'immobile_id' => $s['unita']->id, 'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione',
            'created_at' => now(), 'updated_at' => now()]);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-12-31');

    return [$s, $conto];
}

/** Le frasi del conguaglio dell'anteprima della vendita di Ugo a Elsa dall'1/5/2026. */
function g112vFrasi($test, array $s): array
{
    return fclAnteprima($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-05-01'))['rate']['conguaglio']['frasi'];
}

it('controprova — il già versato senza esercizio: la frase del conguaglio confronta ancora con il piano generato dopo (la differenza di € 44,75)', function () {
    [$s] = g112vScenario($this);

    // La cifra è quella della Coda 160 (`Beta51PassaggiFrasiTest`, O-160): il già versato vale nel piano del 2026, come prima.
    expect(collect(g112vFrasi($this, $s))->contains(fn ($t) => str_contains($t, '44,75')))->toBeTrue();
});

it('Coda 438 — il già versato indicato dopo la generazione per un altro esercizio: il confronto del conguaglio con le sue cifre non si fa', function () {
    [$s, $conto] = g112vScenario($this);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e27, $s['g']->id);
    // Come lo scrive la pagina del già versato con «Vale per: Esercizio 2027».
    DB::table('contributi_versati')->where('target_id', $conto->id)->update(['esercizio_id' => $e27->id]);

    // Dalla regola: un piano del 2026 generato dopo il passaggio non toglie il già versato del 2027, e la frase che lo confronta con le due cifre tace.
    $frasi = g112vFrasi($this, $s);
    expect(collect($frasi)->contains(fn ($t) => str_contains($t, '44,75') || str_contains($t, 'differenza')))->toBeFalse(implode(' | ', $frasi));
});

it('Coda 438 — il già versato indicato per l\'esercizio del piano: il confronto del conguaglio è quello di prima (la differenza di € 44,75)', function () {
    [$s, $conto] = g112vScenario($this);
    DB::table('contributi_versati')->where('target_id', $conto->id)->update(['esercizio_id' => $s['e']->id]);

    expect(collect(g112vFrasi($this, $s))->contains(fn ($t) => str_contains($t, '44,75')))->toBeTrue();
});
