<?php

/**
 * Il badge «Disallineato» di un acconto con del già versato: l'atteso toglie il già versato con la quota del motore.
 *
 * Il motore (`CalcoloQuoteService::nettingGiaVersato`) toglie il già versato in proporzione alla parte della voce che il piano chiede: un acconto di € 400,00 su una voce
 * di € 1.200,00 toglie un terzo del già versato, e il saldo i due terzi, perché la copertura storica è dell'intera voce e non si sottrae più volte (acconto e
 * saldo sono due chiamate). L'atteso di `PianoRateQuoteService::totaleAttesoCents` toglieva il già versato per intero: il piano giusto risultava «Disallineato» e
 * «Ricalcola» non lo spegneva. Ora toglie `floor(già versato × quota)` per ogni parte (di persona o dell'unità) di ogni unità, con la stessa quota che il conguaglio
 * di un passaggio calcola (`QuotaDellaChiamata`, che prima era un metodo privato di `ConguaglioPassaggio`).
 *
 * Non si legge il netting registrato in `righe_riparto`: un già versato scritto dopo la generazione non ci sta, e il badge acceso è proprio quello che invita a ricalcolare.
 *
 * Fonte dell'atteso: i conti a mano di ogni test e il comportamento del motore, che qui non cambia.
 *
 * Cosa resta scoperto: il tetto del lordo (un'unità che ha versato più della sua parte: ha la sua segnalazione nell'Inbox); la schermata del badge.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use App\Services\PianoRateQuoteService;
use App\Services\Riparto\QuotaDellaChiamata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Le tre misure del piano che il badge confronta: le quote generate, l'atteso e il verdetto. */
function be52AccRiga($piano): array
{
    $svc = app(PianoRateQuoteService::class);

    return ['generato' => $svc->totalePuroGeneratoCents($piano->fresh()), 'atteso' => $svc->totaleAttesoCents($piano->fresh()), 'disallineato' => $svc->eDisallineato($piano->fresh())];
}

/** Una voce da `$spese` con il già versato richiesto, e il già versato di Ugo sull'unità A. */
function be52AccScenario($test, string $spese, int $giaVersatoDiA): array
{
    $s = b52tScenario($test, $spese, '0,00');
    DB::table('conti')->where('id', $s['spesa']->id)->update(['richiede_gia_versato' => true]);
    if ($giaVersatoDiA > 0) {
        b52tGiaVersato($test, $s, $s['spesa'], 'A', $giaVersatoDiA);
    }

    return $s;
}

function be52AccPiano($test, array $s, string $importo, string $nome): \App\Models\Gestionale\PianoRate
{
    return b52tPiano($test, $s, ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => $importo, 'note' => '']]], $nome);
}

// ─── Un acconto con del già versato ─────────────────────────────────────────────────────────────────────────────

it('un acconto di metà della voce con € 200,00 di già versato: il piano è giusto e il badge è spento', function () {
    $s = be52AccScenario($this, '1.000,00', 20000);

    $piano = be52AccPiano($this, $s, '500,00', 'Acconto 2026');

    // a mano: il piano chiede 50000, il già versato in proporzione alla metà è 20000 × 50 % = 10000 → 40000
    expect(be52AccRiga($piano))->toBe(['generato' => 40000, 'atteso' => 40000, 'disallineato' => false]);
});

it('acconto di un terzo e saldo dei due terzi con € 300,00 di già versato: tutti e due i piani sono allineati, e «Ricalcola» non cambia il verdetto', function () {
    $s = be52AccScenario($this, '1.200,00', 30000);

    $acconto = be52AccPiano($this, $s, '400,00', 'Acconto 2026');
    $prima = be52AccRiga($acconto);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $acconto]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
    $dopo = be52AccRiga($acconto);
    $saldo = be52AccPiano($this, $s, '800,00', 'Saldo 2026');

    // a mano: A e C a metà. Acconto 200 + 200, il già versato di A per un terzo (100) → 100 + 200 = 300. Saldo 400 + 400, i due terzi (200) → 200 + 400 = 600.
    // Insieme 900: la voce di 1.200,00 meno i 300,00 già versati.
    expect($prima)->toBe(['generato' => 30000, 'atteso' => 30000, 'disallineato' => false])
        ->and($dopo)->toBe($prima)
        ->and(be52AccRiga($saldo))->toBe(['generato' => 60000, 'atteso' => 60000, 'disallineato' => false]);
});

it('il già versato di una persona e quello dell\'unità si tolgono ciascuno con il suo arrotondamento, come fa il motore', function () {
    $s = be52AccScenario($this, '1.200,00', 0);
    // Ugo ha versato € 1,01 a nome suo e l'unità € 2,00: un terzo di 101 è 33 e un terzo di 200 è 66 (99 in tutto), mentre un terzo di 301 sarebbe 100
    foreach ([[101, $s['persone']['ugo']->id], [200, null]] as [$importo, $chi]) {
        DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita']['A']->id,
            'anagrafica_id' => $chi, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = be52AccPiano($this, $s, '400,00', 'Acconto 2026');

    // a mano: 40000 − 33 − 66 = 39901
    expect(be52AccRiga($piano))->toBe(['generato' => 39901, 'atteso' => 39901, 'disallineato' => false]);
});

it('controprova — un piano sull\'intera voce con lo stesso già versato è allineato', function () {
    $s = be52AccScenario($this, '1.200,00', 30000);

    $piano = b52tPiano($this, $s);

    expect(be52AccRiga($piano))->toBe(['generato' => 90000, 'atteso' => 90000, 'disallineato' => false]);
});

it('controprova — un già versato registrato dopo la generazione accende il badge: il piano chiede ancora la cifra di prima', function () {
    $s = be52AccScenario($this, '1.200,00', 0);
    $piano = b52tPiano($this, $s);
    b52tGiaVersato($this, $s, $s['spesa'], 'A', 30000);

    $nettingRegistrato = (int) DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'netting')->sum('importo');

    // il piano ha generato 120000; ricalcolato toglierebbe i 30000 versati: l'atteso è 90000 e il badge dice di ricalcolare
    expect(be52AccRiga($piano))->toBe(['generato' => 120000, 'atteso' => 90000, 'disallineato' => true])
        ->and($nettingRegistrato)->toBe(0);
});

it('controprova — senza già versato l\'acconto con un\'entrata resta allineato', function () {
    $s = b52tScenario($this);

    $piano = b52tPiano($this, $s, ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '600,00', 'note' => ''], ['id' => $s['entrata']->id, 'importo' => '120,00', 'note' => '']]], 'Acconto 2026');

    // a mano: metà della spesa (60000) meno metà dell'entrata (12000) = 48000
    expect(be52AccRiga($piano))->toBe(['generato' => 48000, 'atteso' => 48000, 'disallineato' => false]);
});

// ─── La quota della chiamata, spostata dal conguaglio ───────────────────────────────────────────────────────────

it('la quota della chiamata: 1 per l\'intera voce, la frazione che il piano chiede, nessuna risposta dove non si può dire', function () {
    $s = be52AccScenario($this, '1.200,00', 30000);
    $voce = (int) $s['spesa']->id;

    $piano = be52AccPiano($this, $s, '400,00', 'Acconto 2026');
    $intero = b52tPiano($this, $s, [], 'Intero 2026');

    // a mano: 40000 distribuiti su una voce di 120000 → un terzo; l'intera voce → 1
    expect(round((float) QuotaDellaChiamata::perConto((int) $piano->id, $voce), 6))->toBe(round(1 / 3, 6))
        ->and(QuotaDellaChiamata::perConto((int) $intero->id, $voce))->toBe(1.0)
        ->and(QuotaDellaChiamata::perConto((int) $intero->id, 0))->toBeNull();
});

it('la quota della chiamata applicata al già versato è il pavimento: mai un centesimo in più di quello versato', function () {
    expect(QuotaDellaChiamata::applica(30000, 1.0))->toBe(30000)
        ->and(QuotaDellaChiamata::applica(30000, 1 / 3))->toBe(10000)
        ->and(QuotaDellaChiamata::applica(101, 1 / 3))->toBe(33)
        ->and(QuotaDellaChiamata::applica(200, 1 / 3))->toBe(66);
});
