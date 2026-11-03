<?php

/**
 * I totali con una voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51) contano come il calcolo delle quote.
 *
 * Il motore addebita una voce di spesa per il valore assoluto del suo importo, e una voce di ogni altro tipo la accredita per il valore assoluto
 * (`CalcoloQuoteService::processaConti`). Il piano dei conti, l'elenco dei piani dei conti, la distinta e la copertura (da cui leggono il cruscotto e
 * le voci non coperte del piano) sommavano invece la spesa negativa con il suo segno: con «Rimborso vecchio» a −€ 240,00 dicevano un preventivo di
 * € 960,00 mentre le quote chiedono € 1.200,00 da ripartire. La regola è una sola, `Conto::partiDelTipo`: ogni voce conta per il valore assoluto,
 * dal lato del suo tipo, come il ramo senza sottoconti di `Conto::ripartoPerSegno`.
 *
 * Nella distinta la riga della voce porta il valore del calcolo (€ 240,00), dice «(scritta con il meno)» e una nota in fondo la nomina: così righe,
 * totale del gruppo e totale del documento tornano fra loro.
 *
 * Fonte dell'atteso: i conti a mano (spese 120000 + |−24000| = 144000, entrate 24000, da ripartire 120000; il capitolo 60000 + |−10000| = 70000) e il
 * motore, che non cambia (le quote del piano di prima della correzione sono 120000).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Le sopravvenienze (le voci tecniche, `is_tecnico`) l'elenco le somma nel budget, per il valore assoluto come ogni altra voce, mentre la pagina le
 * tiene fuori dal preventivo, nel loro totale: è così dalla v1.9.0, che sommava nell'elenco tutte le voci (T297.28 lo fissa accanto a una spesa
 * negativa). La pagina conta la sopravvenienza al più grande fra l'importo e lo speso: una scritta con il meno e senza fatture vale zero.
 *
 * Cosa resta scoperto: il capitolo con sottoconti di spesa e di entrata (si divide come `ripartoPerSegno`, non cambia qui); la riga della voce nella
 * pagina del piano rate, che mostra la copia com'è scritta; il «Totale
 * richiesto» della creazione (`totaliPerTipo`, che toglie già il segno); il residuo che la fattura mostra per la voce; i database diversi da sqlite; il
 * disegno delle pagine (si guarda a video).
 */

use App\Models\Gestionale\Conto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

// ─── Senza piano rate (V0) ──────────────────────────────────────────────────────────────────────────────────────

it('T297.1 la pagina del piano dei conti con una spesa a −€ 240,00 dice preventivo € 1.440,00, entrate € 240,00 e da ripartire € 1.200,00', function () {
    $s = b53snV0($this);

    $p = b53snPianoDeiConti($this, $s);

    expect(collect($p)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire', 'totaleSopravvenienze'])->all())->toEqual([
        'totalePreventivo' => 144000, 'totaleEntrate' => 24000, 'totaleDaRipartire' => 120000, 'totaleSopravvenienze' => 0,
    ]);
});

it('T297.2 l\'elenco dei piani dei conti conta la spesa negativa come il calcolo: budget € 1.440,00, entrate € 240,00', function () {
    $s = b53snV0($this);

    $riga = b53snElenco($this, $s);

    expect(collect($riga)->only(['importo_totale', 'importo_entrate', 'capitoli_count', 'capitoli_entrate_count'])->all())->toEqual([
        'importo_totale' => 144000, 'importo_entrate' => 24000, 'capitoli_count' => 3, 'capitoli_entrate_count' => 1,
    ]);
});

it('T297.3 la distinta porta la riga al valore del calcolo, la dice scritta con il meno, la nomina in una nota e chiude a € 1.440,00 e € 1.200,00', function () {
    $s = b53snV0($this);

    [$testo, $dati] = b53snDistinta($this, $s);

    expect($testo)->toContain('Rimborso vecchio (scritta con il meno) 240,00')
        ->toContain('Totale Rimborso vecchio 240,00')
        ->toContain('TOTALE PREVENTIVO € 1.440,00')
        ->toContain('ENTRATE € 240,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 1.200,00')
        ->toContain(B53SN_NOTA_DISTINTA)
        ->not->toContain('Rimborso vecchio -240,00')
        ->not->toContain('960,00')
        ->not->toContain('720,00');
    expect([$dati['totalePreventivo'], $dati['totaleEntrate'], $dati['totaleDaRipartire']])->toBe([144000, 24000, 120000]);
});

it('T297.10 il cruscotto senza piano dice spese € 1.440,00, entrate € 240,00, da ripartire € 1.200,00, e non segnala uno sforo che non c\'è', function () {
    $s = b53snV0($this);

    $c = b53snCruscotto($this, $s);

    expect(collect($c)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale', 'has_sforo'])->all())->toEqual([
        'spese' => 144000, 'entrate' => 24000, 'da_ripartire' => 120000, 'pianificato' => 0, 'delta' => 120000, 'percentuale' => 0, 'has_sforo' => false,
    ]);
});

// ─── Il piano di prima della correzione (V2) ────────────────────────────────────────────────────────────────────

it('T297.4 con il piano di prima della correzione ogni totale dice quello che chiedono le quote: spese € 1.440,00, entrate € 240,00, da ripartire € 1.200,00', function () {
    $s = b53snV2($this);
    $attesi = ['spese' => 144000, 'entrate' => 24000, 'da_ripartire' => 120000];

    $conti = b53snPianoDeiConti($this, $s);
    $elenco = b53snElenco($this, $s);
    [$testo, $dati] = b53snDistinta($this, $s);
    $pagina = b53snPaginaPiano($this, $s, $s['piano'])['pianoRate'];
    $elencoPiani = json_decode(json_encode(b53snProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate'][0]), true);
    $cruscotto = b53snCruscotto($this, $s);
    $copertura = b53snCopertura($s, 'Rimborso vecchio');

    // le quote del motore: 120000, come prima e dopo
    expect(b52tQuoteDelMotore($s['piano']))->toBe(120000)
        ->and([$conti['totalePreventivo'], $conti['totaleEntrate'], $conti['totaleDaRipartire']])->toBe(array_values($attesi))
        ->and([$elenco['importo_totale'], $elenco['importo_entrate']])->toBe([144000, 24000])
        ->and([$dati['totalePreventivo'], $dati['totaleEntrate'], $dati['totaleDaRipartire']])->toBe(array_values($attesi))
        ->and([$pagina['totale_spese'], $pagina['totale_entrate'], $pagina['totale_da_ripartire']])->toBe(array_values($attesi))
        ->and([$elencoPiani['totale_spese'], $elencoPiani['totale_entrate'], $elencoPiani['totale_da_ripartire']])->toBe(array_values($attesi))
        ->and(collect($cruscotto)->only(['spese', 'entrate', 'da_ripartire', 'pianificato', 'delta', 'percentuale'])->all())->toEqual($attesi + ['pianificato' => 120000, 'delta' => 0, 'percentuale' => 100])
        ->and($cruscotto['orfani'])->toBe([])
        ->and([$copertura['budget'], $copertura['pianificato'], $copertura['delta'], $copertura['message']])->toBe([24000, 24000, 0, 'Copertura perfetta.']);
    expect($testo)->toContain('TOTALE PREVENTIVO € 1.440,00')->toContain('DA RIPARTIRE (spese meno entrate) € 1.200,00');
});

it('T297.5 la pagina del piano di prima della correzione non elenca la spesa negativa fra le voci non coperte', function () {
    $s = b53snV2($this);

    $copertura = b53snPaginaPiano($this, $s, $s['piano'])['copertura'];

    expect($copertura['orfani'])->toBe([])
        ->and($copertura['scoperto_count'])->toBe(0);
});

// ─── La regola, in un punto ─────────────────────────────────────────────────────────────────────────────────────

it('T297.12 la regola è una: `Conto::partiDelTipo` dà il valore assoluto dal lato del tipo, e per una voce senza sottoconti `ripartoPerSegno` dice lo stesso', function () {
    expect(method_exists(Conto::class, 'partiDelTipo'))->toBeTrue('Conto::partiDelTipo non esiste: la regola dei totali non ha ancora il suo punto');

    expect(Conto::partiDelTipo('spesa', -24000))->toBe(['spese' => 24000, 'entrate' => 0])
        ->and(Conto::partiDelTipo('spesa', 24000))->toBe(['spese' => 24000, 'entrate' => 0])
        ->and(Conto::partiDelTipo('uscita', -24000))->toBe(['spese' => 24000, 'entrate' => 0])
        ->and(Conto::partiDelTipo('entrata', -24000))->toBe(['spese' => 0, 'entrate' => 24000])
        ->and(Conto::partiDelTipo('entrata', 24000))->toBe(['spese' => 0, 'entrate' => 24000])
        ->and(Conto::partiDelTipo('spesa', 0))->toBe(['spese' => 0, 'entrate' => 0]);

    $s = b53snV0($this);
    foreach ([$s['generali'], $s['antenna'], $s['rimborso']] as $voce) {
        foreach ([24000, -24000, 0] as $cents) {
            expect($voce->fresh()->ripartoPerSegno($cents))->toBe(Conto::partiDelTipo((string) $voce->tipo, $cents));
        }
    }
});

// ─── Le sopravvenienze ──────────────────────────────────────────────────────────────────────────────────────────

it('T297.28 una sopravvenienza accanto alla spesa negativa: l\'elenco la somma nel budget per il valore assoluto, la pagina la tiene fuori dal preventivo', function (int $cents, int $sopravvenienzeDellaPagina) {
    $s = b53snV0($this);
    Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Sopravvenienza', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $cents, 'is_tecnico' => true]);

    $p = b53snPianoDeiConti($this, $s);
    $elenco = b53snElenco($this, $s);

    // elenco: 120000 + |−24000| + |±5000| = 149000; pagina: 120000 + |−24000| = 144000, e la sopravvenienza a parte
    expect([$elenco['importo_totale'], $elenco['importo_entrate']])->toBe([149000, 24000])
        ->and(collect($p)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire', 'totaleSopravvenienze'])->all())->toEqual([
            'totalePreventivo' => 144000, 'totaleEntrate' => 24000, 'totaleDaRipartire' => 120000, 'totaleSopravvenienze' => $sopravvenienzeDellaPagina,
        ]);
})->with([
    'di € 50,00' => [5000, 5000],
    'scritta con il meno, senza fatture' => [-5000, 0],
]);

// ─── Un sottoconto di spesa negativo ────────────────────────────────────────────────────────────────────────────

it('T297.13 un sottoconto di spesa a −€ 100,00 sotto un capitolo: pagina, elenco e distinta dicono € 700,00 come il calcolo (€ 600,00 + € 100,00)', function () {
    $s = b53snCapitolo($this);

    $p = b53snPianoDeiConti($this, $s);
    $elenco = b53snElenco($this, $s);
    [$testo, $dati] = b53snDistinta($this, $s);

    expect([$p['totalePreventivo'], $p['totaleEntrate'], $p['totaleDaRipartire']])->toBe([70000, 0, 70000])
        ->and($elenco['importo_totale'])->toBe(70000)
        ->and($dati['totalePreventivo'])->toBe(70000);
    expect($testo)->toContain('Ascensore 600,00')
        ->toContain('Rimborso (scritta con il meno) 100,00')
        ->toContain('Totale Manutenzione 700,00')
        ->toContain('TOTALE PREVENTIVO € 700,00')
        ->toContain('La voce «Rimborso» ha nel piano dei conti un importo negativo (meno € 100,00): il calcolo delle quote la addebita in positivo, e così la conta questa distinta.')
        ->not->toContain('Rimborso -100,00')
        ->not->toContain('500,00');
});

// ─── Controprova ────────────────────────────────────────────────────────────────────────────────────────────────

it('T297.11 controprova — una spesa positiva e un\'entrata scritta con il meno dicono quello di prima: preventivo € 1.200,00, entrate € 240,00, da ripartire € 960,00', function () {
    $s = b52tScenario($this);
    DB::table('conti')->where('id', $s['entrata']->id)->update(['importo' => -24000]);

    $p = b53snPianoDeiConti($this, $s);
    $elenco = b53snElenco($this, $s);
    [$testo, $dati] = b53snDistinta($this, $s);
    $c = b53snCruscotto($this, $s);

    expect([$p['totalePreventivo'], $p['totaleEntrate'], $p['totaleDaRipartire']])->toBe([120000, 24000, 96000])
        ->and([$elenco['importo_totale'], $elenco['importo_entrate']])->toBe([120000, 24000])
        ->and([$dati['totalePreventivo'], $dati['totaleEntrate'], $dati['totaleDaRipartire']])->toBe([120000, 24000, 96000])
        ->and(collect($c)->only(['spese', 'entrate', 'da_ripartire'])->all())->toEqual(['spese' => 120000, 'entrate' => 24000, 'da_ripartire' => 96000]);
    expect($testo)->toContain('Proventi antenna (entrata) -240,00')
        ->toContain('TOTALE PREVENTIVO € 1.200,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 960,00')
        ->not->toContain('scritta con il meno');
});
