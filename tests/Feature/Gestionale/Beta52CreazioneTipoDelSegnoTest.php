<?php

/**
 * Nella creazione del piano il totale («Totale richiesto», o «Da ripartire» con le entrate) conta le voci scelte con il tipo scritto sulla voce
 * (`FetchCapitoliPerGestioneController` manda `$c->tipo`, la pagina lo somma con `totaliPerTipo`), e dice ciò che il piano creato chiede.
 *
 * Il dubbio (decisione 92.3): un capitolo senza tabelle proprie lasciato «Spesa (uscita)» con dentro solo entrate il motore lo accredita
 * (`Conto::segno()`), e un capitolo con spese ed entrate lo divide fra le due (`Conto::ripartoPerSegno()`); contato con il tipo scritto, il totale
 * della creazione sarebbe sbagliato. Ma la creazione non lascia scegliere un capitolo con sottoconti: non è una foglia (`BudgetCoverageService`,
 * `$isLeaf`), quindi non è finanziabile e arriva con `disabled` vero, e il selettore accetta solo le voci abilitate (`PianiRateNew.vue`,
 * `:selectable`). Le voci che si scelgono sono foglie, e per una foglia il segno del motore è quello del suo tipo. Il totale è quindi giusto.
 *
 * Questi test tengono ferme le due condizioni su cui il totale poggia: se un giorno un capitolo con sottoconti diventasse sceglibile, il primo
 * diventa rosso, e la pagina dovrà contarlo come il motore.
 *
 * Fonte dell'atteso: i conti a mano e i totali della pagina del piano creato dalla rotta (`PianoRateQuoteService::totaliPerSegnoCents`).
 *
 * Cosa resta scoperto: la schermata (il selettore che rifiuta una voce disabilitata è di `vue-select`); la preselezione dal cruscotto, che chiede
 * anche lei una voce abilitata.
 */

use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BudgetMovementService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Le voci come le manda la creazione del piano, per nome. */
function b52cVoci($test, array $s): array
{
    $r = $test->actingAs($test->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', $s['c']->id).'?gestione_id='.$s['g']->id.'&esercizio_id='.$s['e']->id);
    $r->assertOk();

    return collect($r->json())->keyBy('nome')->all();
}

/** «Spese generali» € 1.200,00 e il capitolo «Proventi», lasciato «Spesa (uscita)», con dentro solo «Affitto antenna» € 240,00 (entrata, tabella «Box»). */
function b52cProventi($test): array
{
    $s = b52tScenario($test, '1.200,00', '0,00');
    $s['entrata']->delete();
    $padre = b52tVoce($test, $s, 'Proventi', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($test, $s, 'Affitto antenna', 'entrata', '240,00', $s['box'], $padre->id, ['isSottoConto' => true]);

    return $s + ['padre' => $padre];
}

it('un capitolo con sottoconti arriva alla creazione disabilitato: il capitolo «Proventi» lasciato «Spesa» e il capitolo misto non si possono scegliere', function () {
    $proventi = b52cVoci($this, b52cProventi($this))['[CAPITOLO] Proventi'];
    $antenna = b52cVoci($this, b52tScenarioCapitoloMisto($this))['[CAPITOLO] Antenna'];

    expect($proventi['disabled'])->toBeTrue()
        ->and($antenna['disabled'])->toBeTrue();
});

it('ogni voce che la creazione lascia scegliere è una foglia, e il suo tipo è quello con cui il motore la distribuisce', function () {
    foreach ([b52cProventi($this), b52tScenarioCapitoloMisto($this)] as $s) {
        $sceglibili = collect(b52cVoci($this, $s))->reject(fn (array $v) => $v['disabled']);
        expect($sceglibili)->not->toBeEmpty();

        foreach ($sceglibili as $voce) {
            $conto = Conto::with(['tabelleMillesimali', 'sottoconti'])->findOrFail($voce['id']);
            expect($conto->sottoconti)->toBeEmpty("{$voce['nome']} si sceglie e ha sottoconti")
                ->and(BudgetMovementService::tipoDelSegno($conto, null))->toBe($voce['tipo'], $voce['nome']);
        }
    }
});

it('controprova — con «Spese generali» e il sottoconto «Affitto antenna» scelti la creazione e il piano creato dicono lo stesso: da ripartire € 960,00', function () {
    $s = b52cProventi($this);
    $voci = b52cVoci($this, $s);
    $spese = $voci['[CAPITOLO] Spese generali'];
    $affitto = $voci['Proventi > Affitto antenna'];

    // a mano, come la pagina: la spesa con il più, l'entrata con il meno
    expect([$spese['tipo'], $affitto['tipo']])->toBe(['spesa', 'entrata']);

    $piano = b52tPiano($this, $s, [
        'capitoli_ids' => [$spese['id'], $affitto['id']],
        'capitoli_config' => [['id' => $spese['id'], 'importo' => '1200,00', 'note' => ''], ['id' => $affitto['id'], 'importo' => '240,00', 'note' => '']],
    ]);
    $t = app(PianoRateQuoteService::class)->totaliPerSegnoCents($piano->fresh());

    expect(['spese' => $t['spese'], 'entrate' => $t['entrate']])->toBe(['spese' => 120000, 'entrate' => 24000]);
});
