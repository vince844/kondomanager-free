<?php

/**
 * Che cosa dicono la copertura, la riga del piano dei conti e il cruscotto di una voce di spesa scritta con il meno (dato delle versioni fino alla
 * 1.11.0-beta.51).
 *
 * La copertura (`BudgetCoverageService`) conta ogni voce per il valore assoluto, come il motore (`CalcoloQuoteService::processaConti`): la spesa a
 * −€ 240,00 ha un fabbisogno di € 240,00 e la copia −24000 di un piano la copre per € 240,00. La riga della voce nell'albero del piano dei conti
 * (`ContoResource`) legge la stessa regola per le righe dei piani: senza, la copertura in valore assoluto finiva nel ripiego «Piano rate
 * straordinario», un piano che non esiste, e la barra restava vuota con «€ 240,00 / € 0,00». Lo stesso ripiego compariva già per un'entrata con la
 * copia scritta col meno.
 *
 * Il messaggio della copertura («Surplus di € 240,00.») non lo legge nessuna pagina: i test guardano anche ciò che la pagina mostra, cioè la riga
 * dell'albero e il cruscotto.
 *
 * Una voce di spesa negativa fuori da ogni piano resta fra le voci scoperte del cruscotto, con la sua nota invece di «Voce senza copertura attiva»: la
 * creazione non la propone (è spenta nel selettore), quindi la scheda dice di correggerla e non porta alla creazione.
 *
 * Fonte dell'atteso: i conti a mano (spese 120000 + |−24000| = 144000, entrate 24000, da ripartire 120000; con il secondo piano la voce è coperta per
 * 24000 + 24000 = 48000, il pianificato del cruscotto è 120000 − 24000 + 48000 = 144000, cioè il 120 % di 120000).
 *
 * Cosa resta scoperto: il disegno della barra e dei colori (si guarda a video; i dati li prova questo file); la scheda del cruscotto montata (la prova
 * `DashboardSpesaNegativa.test.ts`); il capitolo con sottoconti di tipo diverso; i piani straordinari da fatture; i database diversi da sqlite.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

// ─── La riga della voce nell'albero del piano dei conti ─────────────────────────────────────────────────────────

it('T297.17 la riga di «Rimborso vecchio» con il piano di prima della correzione: coperta per € 240,00 su € 240,00 da «Ordinario 2026», senza un piano straordinario che non esiste', function () {
    $s = b53snV2($this);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect(collect($riga)->only(['impegnato', 'importo', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
        'impegnato' => 24000, 'importo' => '€ 240,00', 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
    ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $s['piano']->id, 24000]])
        ->and($riga['piani_collegati'])->toBe(['Ordinario 2026']);
});

it('T297.18 la riga della voce addebitata da due piani: € 480,00 su € 240,00, «Ordinario 2026» e «Integrativo 2026» per € 240,00 ciascuno, oltre il fabbisogno', function () {
    $s = b53snV3($this);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect(collect($riga)->only(['impegnato', 'importo', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
        'impegnato' => 48000, 'importo' => '€ 240,00', 'stato_copertura' => 'over', 'percentuale_copertura' => 200,
    ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $s['piano']->id, 24000], ['Integrativo 2026', $s['secondo']->id, 24000]])
        ->and(collect($riga['piani_collegati'])->sort()->values()->all())->toBe(['Integrativo 2026', 'Ordinario 2026']);
});

it('T297.19 un\'entrata con la copia scritta col meno: la riga dice «Ordinario 2026» per € 240,00, e nessun «Piano rate straordinario»', function () {
    $s = b53snBase($this);
    $piano = b52tPiano($this, $s, [], 'Ordinario 2026');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $s['antenna']->id)->update(['importo' => -24000]);

    $riga = b53snRiga($this, $s, 'Affitto antenna');

    expect(collect($riga)->only(['impegnato', 'importo', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
        'impegnato' => 24000, 'importo' => '€ 240,00', 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
    ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]])
        ->and($riga['piani_collegati'])->toBe(['Ordinario 2026']);
});

it('T297.19 un\'entrata con voce e copia scritte col meno: la riga dice € 240,00 su € 240,00 da «Ordinario 2026», non € 0,00', function () {
    $s = b53snBase($this);
    $piano = b52tPiano($this, $s, [], 'Ordinario 2026');
    b53snRiscrivi($s['antenna'], -24000, [$piano]);

    $riga = b53snRiga($this, $s, 'Affitto antenna');

    expect(collect($riga)->only(['impegnato', 'importo', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
        'impegnato' => 24000, 'importo' => '€ 240,00', 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
    ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]])
        ->and($riga['piani_collegati'])->toBe(['Ordinario 2026']);
});

it('controprova — un\'entrata con la copia positiva: una riga «Ordinario 2026» per € 240,00, coperta per intero, come prima', function () {
    $s = b53snBase($this);
    $piano = b52tPiano($this, $s, [], 'Ordinario 2026');

    $riga = b53snRiga($this, $s, 'Affitto antenna');

    expect(collect($riga)->only(['impegnato', 'importo', 'stato_copertura', 'percentuale_copertura'])->all())->toEqual([
        'impegnato' => 24000, 'importo' => '€ 240,00', 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
    ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]])
        ->and($riga['piani_collegati'])->toBe(['Ordinario 2026']);
});

it('controprova — la riga tiene la voce com\'è scritta in `budget_originale_raw`, da cui il modale di modifica legge l\'importo: aperto e salvato senza toccarlo, la voce resta −€ 240,00', function (string $caso) {
    $s = $caso === 'V0' ? b53snV0($this) : b53snV2($this);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');
    $this->actingAs($this->user)->from('/pagina')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['rimborso']]), [
        'nome' => 'Rimborso vecchio', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '-240,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ]);

    expect($riga['budget_originale_raw'])->toBe(-24000)
        ->and(b53snErrori())->toBe([])
        ->and((int) DB::table('conti')->where('id', $s['rimborso']->id)->value('importo'))->toBe(-24000);
})->with(['V0', 'V2']);

// ─── La voce addebitata da due piani ────────────────────────────────────────────────────────────────────────────

it('T297.14 la voce addebitata da due piani si vede: la copertura dice «Surplus di € 240,00.» e il cruscotto pianifica il 120 % del da ripartire', function () {
    $s = b53snV3($this);

    $voce = b53snCopertura($s, 'Rimborso vecchio');
    $c = b53snCruscotto($this, $s);

    // le quote: il primo piano 120000, il secondo 24000 (Ugo e Bice per metà), cioè la voce € 240,00 chiesta due volte
    expect([b52tQuoteDelMotore($s['piano']), b52tQuoteDelMotore($s['secondo'])])->toBe([120000, 24000])
        ->and(b52tSommeQuote($s['secondo']))->toBe(['Bice' => 12000, 'Ugo' => 12000])
        ->and([$voce['budget'], $voce['pianificato'], $voce['delta'], $voce['status'], $voce['message']])->toBe([24000, 48000, 24000, 'surplus', 'Surplus di € 240,00.'])
        ->and(collect($c)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all())->toEqual([
            'spese' => 144000, 'entrate' => 24000, 'da_ripartire' => 120000, 'pianificato' => 144000, 'delta' => -24000, 'percentuale' => 120,
        ]);
});

// ─── Il cruscotto, le voci scoperte ─────────────────────────────────────────────────────────────────────────────

it('T297.10-bis senza piano la spesa negativa resta fra le voci scoperte del cruscotto con la sua nota, che dice di correggerla', function () {
    $s = b53snV0($this);

    $orfani = collect(b53snCruscotto($this, $s)['orfani'])->keyBy('nome');

    expect($orfani->keys()->sort()->values()->all())->toBe(['Affitto antenna', 'Rimborso vecchio', 'Spese generali'])
        ->and($orfani['Rimborso vecchio']['importo'])->toBe(24000)
        ->and($orfani['Rimborso vecchio']['spesa_negativa'] ?? null)->toBeTrue()
        ->and($orfani['Rimborso vecchio']['nota'] ?? null)->toBe(B53SN_NOTA_FUORI_DAI_PIANI)
        ->and($orfani['Spese generali']['spesa_negativa'] ?? false)->toBeFalse()
        ->and($orfani['Affitto antenna']['spesa_negativa'] ?? false)->toBeFalse();
});

it('controprova — con il piano di prima della correzione nessuna voce del cruscotto è scoperta, e nessuna porta la nota della spesa negativa', function () {
    $s = b53snV2($this);

    $c = b53snCruscotto($this, $s);

    expect(collect($c['orfani'])->where('spesa_negativa', true)->all())->toBe([]);
});
