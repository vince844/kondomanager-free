<?php

/**
 * Cosa resta scoperto: il testo esatto dell'avviso per le perdite vere (il controllo è sul livello, sulla cifra del `delta_non_ripartito` e
 * sull'assenza dell'invito a guardare le tabelle).
 *
 * Il registro del motore: «delta non ripartito».
 *
 * `CalcoloQuoteService::calcolaPerGestione` confronta la somma degli importi forzati dal piano (la pivot, positiva e senza il segno) con
 * ciò che ha distribuito (con il segno, e al netto del già versato), e se differiscono scrive un avviso che invita a «verificare le tabelle
 * millesimali». Lo scrive anche quando il piano è giusto: con un'entrata, con un già versato (anche senza entrate), con un capitolo padre
 * che nella pivot conta due volte (il suo importo e quello dei figli). L'avviso non ha effetti per l'amministratore (finisce nel registro),
 * ma un avviso che scatta su un piano giusto non avvisa più di niente: chi lo legge cerca tabelle che non hanno colpa.
 *
 * Il confronto giusto: gli importi forzati delle sole voci del piano, col segno del tipo, meno il già versato che il motore assorbe. Un padre
 * con figli di entrata conta con il segno dei figli (−€ 1.000,00), come lo distribuisce il motore; un padre con figli di tipo diverso conta
 * la parte di spesa in più e quella di entrata in meno, divise come le divide il motore (`Conto::importoConSegno()`). Il messaggio non nomina
 * più le tabelle: la causa può essere un'altra.
 */

use App\Models\Gestionale\Conto;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il registro del motore sul riparto: gli avvisi e i riepiloghi, riempiti dall'ascoltatore mentre il test costruisce lo scenario. */
function b52tRegistroMotore(): stdClass
{
    $registro = (object) ['avvisi' => [], 'riepilogo' => []];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use ($registro) {
        if ($m->level === 'warning' && str_starts_with($m->message, 'calcolaPerGestione:') && str_contains($m->message, 'ripartit')) {
            $registro->avvisi[] = $m->message;
        }
        if ($m->message === 'Riepilogo Ripartizione') {
            $registro->riepilogo[] = $m->context;
        }
    });

    return $registro;
}

it('T1.9a un piano giusto con un\'entrata non scrive nessun avviso, e il riepilogo torna', function () {
    $registro = b52tRegistroMotore();
    $s = b52tScenario($this);
    b52tPiano($this, $s);

    expect($registro->avvisi)->toBe([]);
    $r = end($registro->riepilogo);
    expect([$r['importo_override_pianificato'], $r['importo_effettivamente_distribuito'], $r['delta_non_ripartito']])->toBe([96000, 96000, 0]);
});

it('T1.9b con un già versato di € 200,00 e un\'entrata: nessun avviso, il già versato è detto a parte', function () {
    $registro = b52tRegistroMotore();
    $s = b52tScenario($this);
    b52tGiaVersato($this, $s, $s['spesa'], 'A', 20_000);
    b52tPiano($this, $s);

    expect($registro->avvisi)->toBe([]);
    $r = end($registro->riepilogo);
    expect([$r['importo_override_pianificato'], $r['gia_versato_assorbito'] ?? null, $r['importo_effettivamente_distribuito'], $r['delta_non_ripartito']])->toBe([96000, 20000, 76000, 0]);
});

it('T1.9c con un già versato e senza entrate: nessun avviso (basta il già versato, l\'entrata non serve)', function () {
    $registro = b52tRegistroMotore();
    $s = b52tScenario($this, entrata: '0,00');
    b52tGiaVersato($this, $s, $s['spesa'], 'A', 20_000);
    b52tPiano($this, $s);

    expect($registro->avvisi)->toBe([]);
});

it('T1.9d un padre di sole spese nella pivot non conta due volte: nessun avviso', function () {
    $registro = b52tRegistroMotore();
    b52tScenarioConPadre($this, 'spesa');

    expect($registro->avvisi)->toBe([]);
    $r = end($registro->riepilogo);
    expect([$r['importo_override_pianificato'], $r['importo_effettivamente_distribuito'], $r['delta_non_ripartito']])->toBe([220000, 220000, 0]);
});

it('T1.9e la perdita vera resta segnalata: una voce con importo e senza tabella, con la cifra e senza invitare a guardare le tabelle', function () {
    $registro = b52tRegistroMotore();
    $s = b52tScenario($this, entrata: '0,00');
    Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Giardino', 'tipo' => 'spesa', 'importo' => 50000]); // nessuna tabella millesimale
    b52tPiano($this, $s, ['accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova']);

    expect($registro->avvisi)->toHaveCount(1);
    expect($registro->avvisi[0])->not->toContain('Verificare tabelle millesimali');
    $r = end($registro->riepilogo);
    expect($r['delta_non_ripartito'])->toBe(50000);
});

it('T1.9f un padre con figli di entrata nella pivot conta con il segno dei figli: riepilogo 20000 / 20000 / 0 e nessun avviso', function () {
    $registro = b52tRegistroMotore();
    b52tScenarioConPadre($this, 'entrata');

    expect($registro->avvisi)->toBe([]);
    $r = end($registro->riepilogo);
    expect([$r['importo_override_pianificato'], $r['importo_effettivamente_distribuito'], $r['delta_non_ripartito']])->toBe([20000, 20000, 0]);
});

it('T1.9g un padre con figli di tipo diverso nella pivot: la spesa conta in più e l\'entrata in meno, riepilogo 140000 / 140000 / 0 e nessun avviso', function () {
    $registro = b52tRegistroMotore();
    b52tScenarioConPadreMisto($this);

    expect($registro->avvisi)->toBe([]);
    $r = end($registro->riepilogo);
    expect([$r['importo_override_pianificato'], $r['importo_effettivamente_distribuito'], $r['delta_non_ripartito']])->toBe([140000, 140000, 0]);
});
