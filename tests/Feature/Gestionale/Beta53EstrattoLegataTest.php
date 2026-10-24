<?php

/**
 * 1.11.0-beta.53 (Coda 244) — l'estratto conto conta il credito di un saldo del Wallet legato a un passaggio come credito dal passaggio.
 *
 * Il riquadro del rimborso dice quanto credito dal passaggio di titolarità è ancora nei saldi della gestione, non assorbito: diventa una quota
 * rimborsabile quando il prossimo piano lo assorbe. Lo diceva per la coppia (`subentro_id`); una riga legata a un passaggio (`passaggio_id`) è lo
 * stesso credito, e si conta al netto per gestione e unità come la coppia. Con la successione del defunto con l'arretrato agli eredi la sua
 * riga legata pareggia la quota che il piano calcola ancora per lui, e non è un credito da rimborsare, come la coppia.
 *
 * Fonte dell'atteso: il conto a mano scritto in ogni test.
 *
 * Che cosa NON copre: la data e il filtro per conto dell'estratto; MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Support/ScenariFineLocazione.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    sl53Amministratore($this);
});

it('Ines esce e Dora resta: il credito di Ines legato alla fine (€ 90,74) è credito dal passaggio ancora nei saldi', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $this->travel(1)->minutes();
    // Coda 245 (1.11.0-beta.55): con un coinquilino che resta la scelta c'è, e qui si registra con «Non scriverlo»: nessuna coppia, come la fine
    // con la fermata del coinquilino delle beta .50–.53 da cui viene il caso (le righe legate e il credito non cambiano).
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    $this->travel(1)->minutes();
    foreach ([['Ines', -9074], ['Dora', 9074]] as [$chi, $cents]) {
        expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'passaggio_id' => $fine->id])))->toBe([]);
    }

    $rimborso = fn (string $chi) => $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))->assertOk()
        ->viewData('page')['props']['rimborso']['credito_in_saldi'];

    // La fine con «Non scriverlo» non ha coppia: il credito di Ines è la sola riga legata. Dora ha un debito: niente.
    expect($rimborso('Ines'))->toBe(9074)
        ->and($rimborso('Dora'))->toBe(0);
});

it('guardia — la riga legata del defunto, con l\'arretrato agli eredi, non è un credito da rimborsare: pareggia la quota che il piano calcola per lui', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    foreach ([['ugo', -39452], ['anna', 23671], ['bruno', 15781]] as [$chi, $cents]) {
        expect(sl53Errori($this->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
            'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id,
            'saldo_iniziale' => $cents, 'passaggio_id' => $succ->id])))->toBe([]);
    }

    $credito = $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->c, $s->persona('ugo')]))->assertOk()
        ->viewData('page')['props']['rimborso']['credito_in_saldi'];

    // Nessuna rata emessa: l'arretrato non ha scritto righe, ma la scelta è «agli eredi».
    expect($credito)->toBe(0)->and(Subentro::find($succ->id)->registro['arretrato']['scelta'] ?? null)->toBe(Subentro::ARRETRATO_AGLI_EREDI);
});
