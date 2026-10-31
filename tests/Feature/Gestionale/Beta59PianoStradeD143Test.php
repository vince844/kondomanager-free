<?php

/**
 * D143 — le strade che il riquadro delle quote non assegnate indica su un piano con le rate emesse esistono, e le rotte le accettano.
 *
 * Cosa resta scoperto: il piano preso nel conguaglio di un passaggio (la strada passa dall'annullamento del passaggio, che il pulsante «Ricalcola»
 * elenca: `PianoRate::rimediDelFermo`, provato dai test dei passaggi); il censimento del proprietario dalla pagina dell'anagrafe (qui la riga di
 * titolarità si scrive come la scrive l'anagrafe).
 *
 * Il riquadro (`PianiRateShow.vue`) diceva che la quota «dovrà essere recuperata con un addebito manuale o in sede di conguaglio di fine anno»: nessuna
 * delle due cose esiste. Ora dice: riaprire il piano con i passi del pulsante «Ricalcola» (annullare le emissioni, e prima stornare gli incassi), poi
 * sistemare la causa e premere «Ricalcola»; il programma non ha un addebito individuale. Questi test fanno la strada dalle rotte, con il caso del v34
 * (Cantina 1 senza proprietario, € 75,00), e alla fine il piano è allineato, senza quote non assegnate.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta59PianoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il piano del v34 approvato, con la prima rata emessa. */
function b59dEmesso($test): array
{
    $s = b59pScenarioV34($test);
    $piano = b59pPianoV34($test, $s);
    b52tApprova($test, $s, $piano);
    b52tEmetti($test, $s, $piano, 1, false);

    return [$s, $piano];
}

function b59dAnnullaEmissione($test, array $s, PianoRate $piano, int $numeroRata): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $piano, b52tRata($piano, $numeroRata)]));
}

it('D143 — controprova: le rate emesse senza incassi; annullata l\'emissione, censita la Cantina 1 e premuto «Ricalcola», il piano è allineato', function () {
    [$s, $piano] = b59dEmesso($this);

    // il pulsante «Ricalcola» elenca la strada: annullare le emissioni
    expect(b59pPagina($this, $s, $piano)['fermo_del_piano']['rimedi'])->toContain('annulla le emissioni dalla pagina del piano, se non hanno incassi');

    b59dAnnullaEmissione($this, $s, $piano, 1)->assertSessionHasNoErrors();
    expect(b59pPagina($this, $s, $piano)['fermo_del_piano'])->toBeNull();

    b59pCensisci($s, 'Cantina', 'ugo');
    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors()->assertSessionMissing('scoperti_warning');

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
});

it('D143 — controprova: le rate emesse con un incasso: l\'emissione non si annulla finché c\'è l\'incasso; stornato l\'incasso, la strada è la stessa e il piano torna allineato', function () {
    [$s, $piano] = b59dEmesso($this);
    b52tIncasso($this, $s, 'ugo', [b52tQuotaId($s, $piano, 'ugo', 'A', 1) => 10_000], 10_000);

    expect(b59pPagina($this, $s, $piano)['fermo_del_piano']['rimedi'][0])->toStartWith('annulla quel movimento');

    b59dAnnullaEmissione($this, $s, $piano, 1);
    expect(session('message')['message'] ?? null)->toBe('Impossibile annullare: ci sono già incassi registrati, o crediti già usati o rimborsati su questa rata.');

    $incasso = ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', TipoMovimentoContabile::INCASSO_RATA)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso->id]))->assertSessionHasNoErrors();

    b59dAnnullaEmissione($this, $s, $piano, 1)->assertSessionHasNoErrors();
    b59pCensisci($s, 'Cantina', 'ugo');
    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors()->assertSessionMissing('scoperti_warning');

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);
    expect(b59pColonna($piano))->toBeNull();
});
