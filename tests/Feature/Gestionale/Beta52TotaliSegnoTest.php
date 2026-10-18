<?php

/**
 * Cosa resta scoperto: le voci `uscita` come righe del piano dei conti (la colonna `conti.tipo` è un enum `spesa`/`entrata`: `uscita` si prova
 * solo su `segnoDelTipo`); MySQL.
 *
 * Il segno di una voce, in un posto solo: `Conto::segno()`. Per dividere un importo fra spese ed entrate, `Conto::ripartoPerSegno()`.
 *
 * Il motore distribuisce una voce di spesa o di uscita con il segno più e ogni altra voce con il meno (`CalcoloQuoteService.php:1500-1502` e
 * `:1586-1589`). Chi deve contare le stesse voci (l'atteso del piano, i totali delle pagine, la copertura del cruscotto) legge il segno da
 * `Conto::segno()`, che segue l'ordine del motore: una voce con tabelle proprie, o una foglia, ha il segno del proprio tipo; un padre senza
 * tabelle ha il segno dei suoi sottoconti nati fino alla data del piano, e se nessuno è nato prima (sottoconti arrivati dopo il piano) di
 * tutti i sottoconti attuali, con la stessa regola a ogni livello; se i sottoconti sono di segno diverso vale il tipo del padre.
 *
 * Un padre con sottoconti di segno diverso ha un solo segno ma un importo che il motore divide: `ripartoPerSegno()` lo divide allo stesso modo
 * (in proporzione all'importo dei sottoconti, l'ultimo prende il resto, lo stesso arrotondamento), e dà le spese e le entrate che ne vengono.
 *
 * Un tipo che non è stato caricato (una `select` parziale) non si indovina: `segno()` solleva `LogicException`, perché un segno uguale per
 * tutte le voci accenderebbe il badge su ogni piano.
 *
 * Le date dei sottoconti si scrivono a mano nel database, per scegliere da che parte della data del piano cadono senza aspettare.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

/** Un piano dei conti vuoto, per costruirci sopra le voci. */
function b52tPianoDeiContiVuoto(): array
{
    $s = b52tCondominio();
    $e = App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(App\Services\CondominioService::class)->createDefaultGestione($s['c'], $e);

    return $s + ['e' => $e, 'g' => $g, 'pc' => PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
}

/** Una voce con il tipo, il padre e la data di nascita dati; con `$tabella` la voce porta una tabella millesimale propria. */
function b52tVoceDiProva(array $s, string $nome, string $tipo, ?Conto $padre = null, string $nata = '2026-01-01 10:00:00', bool $tabella = false): Conto
{
    $voce = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $padre?->id, 'nome' => $nome, 'tipo' => $tipo, 'importo' => $padre === null && ! $tabella ? 0 : 10000]);
    DB::table('conti')->where('id', $voce->id)->update(['created_at' => $nata, 'updated_at' => $nata]);
    if ($tabella) {
        DB::table('conto_tabella_millesimale')->insert(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    return Conto::find($voce->id);
}

const B52T_DATA_DEL_PIANO = '2026-03-01 12:00:00';

it('il segno del tipo: spesa e uscita si addebitano (+1), tutto il resto si accredita (−1), e la lista è una sola', function () {
    expect(Conto::TIPI_DI_SPESA)->toBe(['spesa', 'uscita']);
    expect([Conto::segnoDelTipo('spesa'), Conto::segnoDelTipo('uscita'), Conto::segnoDelTipo('entrata'), Conto::segnoDelTipo('altro')])->toBe([1, 1, -1, -1]);
});

it('una foglia ha il segno del suo tipo, e l\'importo con il segno è sempre il valore assoluto col segno del tipo (anche su un importo scritto negativo)', function () {
    $s = b52tPianoDeiContiVuoto();
    $spesa = b52tVoceDiProva($s, 'Pulizia', 'spesa');
    $entrata = b52tVoceDiProva($s, 'Antenna', 'entrata');

    expect([$spesa->segno(), $entrata->segno()])->toBe([1, -1]);
    expect([$spesa->importoConSegno(24000), $spesa->importoConSegno(-24000), $entrata->importoConSegno(24000), $entrata->importoConSegno(-24000)])->toBe([24000, 24000, -24000, -24000]);
});

it('un padre senza tabelle ha il segno dei sottoconti nati fino alla data del piano: figli di entrata, padre di tipo spesa → −1', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa');
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-01-10 10:00:00');
    b52tVoceDiProva($s, 'Caldaia', 'entrata', $padre, '2026-01-11 10:00:00');

    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
});

it('se nessun sottoconto è nato fino alla data del piano, il padre ripiega su tutti i sottoconti attuali, come il motore', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa');
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-05-10 10:00:00');
    b52tVoceDiProva($s, 'Caldaia', 'entrata', $padre, '2026-05-11 10:00:00');

    // i sottoconti sono nati dopo il piano: lo snapshot è vuoto e valgono tutti quelli che ci sono
    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
});

it('con lo snapshot a metà contano solo i sottoconti nati fino alla data del piano; senza data contano tutti, e se sono di segno diverso vale il tipo del padre', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa');
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-01-10 10:00:00');   // nato prima del piano
    b52tVoceDiProva($s, 'Caldaia', 'spesa', $padre, '2026-05-11 10:00:00');       // nato dopo: non conta nello snapshot

    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
    expect(Conto::find($padre->id)->segno())->toBe(1); // senza data: un'entrata e una spesa, vale il padre
});

it('un padre con tabelle proprie ha il segno del proprio tipo, anche con i figli di entrata', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa', tabella: true);
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-01-10 10:00:00');

    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(1);
});

it('un padre con sottoconti di tipo misto e senza tabelle ha il segno del suo tipo; la divisione dell\'importo è di ripartoPerSegno', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Antenna', 'spesa');
    b52tVoceDiProva($s, 'Manutenzione antenna', 'spesa', $padre, '2026-01-10 10:00:00');
    b52tVoceDiProva($s, 'Affitto antenna', 'entrata', $padre, '2026-01-10 10:00:00');

    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(1);
});

it('il confine dello snapshot: un sottoconto nato nello stesso secondo del piano conta, e con uno di spesa e uno di entrata il padre di tipo entrata ha il segno meno', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Antenna', 'entrata');
    b52tVoceDiProva($s, 'Manutenzione antenna', 'spesa', $padre, '2026-01-10 10:00:00');   // nato prima del piano
    b52tVoceDiProva($s, 'Affitto antenna', 'entrata', $padre, B52T_DATA_DEL_PIANO);       // nato nello stesso secondo del piano

    // contano tutti e due: i segni sono diversi e vale il tipo del padre. Con il confine escluso conterebbe la sola spesa (+1)
    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
});

it('ripartoPerSegno: una foglia porta tutto l\'importo nel suo tipo, in valore assoluto, anche se scritto negativo', function () {
    $s = b52tPianoDeiContiVuoto();
    $spesa = b52tVoceDiProva($s, 'Pulizia', 'spesa');
    $entrata = b52tVoceDiProva($s, 'Antenna', 'entrata');

    expect([$spesa->ripartoPerSegno(24000), $spesa->ripartoPerSegno(-24000), $entrata->ripartoPerSegno(24000), $entrata->ripartoPerSegno(-24000)])->toBe([
        ['spese' => 24000, 'entrate' => 0], ['spese' => 24000, 'entrate' => 0], ['spese' => 0, 'entrate' => 24000], ['spese' => 0, 'entrate' => 24000],
    ]);
});

it('ripartoPerSegno: un padre senza tabelle con una spesa e un\'entrata divide l\'importo in parti uguali (importi uguali) e l\'ultimo sottoconto prende il resto', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Antenna', 'spesa');
    b52tVoceDiProva($s, 'Manutenzione antenna', 'spesa', $padre, '2026-01-10 10:00:00');   // importo 10000
    b52tVoceDiProva($s, 'Affitto antenna', 'entrata', $padre, '2026-01-10 10:00:00');      // importo 10000
    $padre = Conto::find($padre->id);
    $data = Carbon::parse(B52T_DATA_DEL_PIANO);

    expect($padre->ripartoPerSegno(30000, $data))->toBe(['spese' => 15000, 'entrate' => 15000]);
    // 15000,5 arrotonda per eccesso al primo (la spesa), il secondo (l'entrata) prende il resto: il centesimo in più è della spesa
    expect($padre->ripartoPerSegno(30001, $data))->toBe(['spese' => 15001, 'entrate' => 15000]);
    expect($padre->importoConSegno(30001, $data))->toBe(1);
});

it('ripartoPerSegno: l\'ordine dei sottoconti conta come per il motore: se l\'entrata viene per prima, il centesimo in più è suo', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Antenna', 'spesa');
    b52tVoceDiProva($s, 'Affitto antenna', 'entrata', $padre, '2026-01-10 10:00:00');
    b52tVoceDiProva($s, 'Manutenzione antenna', 'spesa', $padre, '2026-01-10 10:00:00');

    expect(Conto::find($padre->id)->ripartoPerSegno(30001, Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(['spese' => 15000, 'entrate' => 15001]);
});

it('ripartoPerSegno: la divisione è in proporzione all\'importo dei sottoconti (600 e 400 su una pivot di 1.000 danno 600 e 400), e scende di livello', function () {
    $s = b52tPianoDeiContiVuoto();
    $nonno = b52tVoceDiProva($s, 'Edificio', 'spesa');
    $padre = b52tVoceDiProva($s, 'Antenna', 'spesa', $nonno, '2026-01-05 10:00:00');
    $manutenzione = b52tVoceDiProva($s, 'Manutenzione antenna', 'spesa', $padre, '2026-01-10 10:00:00');
    $affitto = b52tVoceDiProva($s, 'Affitto antenna', 'entrata', $padre, '2026-01-10 10:00:00');
    DB::table('conti')->where('id', $manutenzione->id)->update(['importo' => 60000]);
    DB::table('conti')->where('id', $affitto->id)->update(['importo' => 40000]);

    expect(Conto::find($padre->id)->ripartoPerSegno(100000, Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(['spese' => 60000, 'entrate' => 40000]);
    // il nonno ha un solo figlio, il padre, che divide a sua volta: la regola è la stessa a ogni livello
    expect(Conto::find($nonno->id)->ripartoPerSegno(100000, Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(['spese' => 60000, 'entrate' => 40000]);
});

it('ripartoPerSegno: un padre con tabelle proprie non divide niente, porta tutto nel proprio tipo; un tipo non caricato si rifiuta', function () {
    $s = b52tPianoDeiContiVuoto();
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa', tabella: true);
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-01-10 10:00:00');

    expect(Conto::find($padre->id)->ripartoPerSegno(50000, Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(['spese' => 50000, 'entrate' => 0]);

    $errore = null;
    try {
        Conto::query()->select('conti.id', 'conti.importo')->find($padre->id)->ripartoPerSegno(50000);
    } catch (Throwable $e) {
        $errore = $e;
    }
    expect($errore)->toBeInstanceOf(LogicException::class)->not->toBeInstanceOf(BadMethodCallException::class);
});

it('il segno scende ricorsivamente: un nonno senza tabelle con un padre senza tabelle con figli di entrata ha il segno −1', function () {
    $s = b52tPianoDeiContiVuoto();
    $nonno = b52tVoceDiProva($s, 'Edificio', 'spesa');
    $padre = b52tVoceDiProva($s, 'Manutenzione', 'spesa', $nonno, '2026-01-05 10:00:00');
    b52tVoceDiProva($s, 'Ascensore', 'entrata', $padre, '2026-01-10 10:00:00');

    expect(Conto::find($nonno->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
    expect(Conto::find($padre->id)->segno(Carbon::parse(B52T_DATA_DEL_PIANO)))->toBe(-1);
});

it('un tipo non caricato si rifiuta: il segno non si indovina e non si ripiega su un tipo qualunque', function () {
    $s = b52tPianoDeiContiVuoto();
    $voce = b52tVoceDiProva($s, 'Pulizia', 'spesa');
    $senzaTipo = Conto::query()->select('conti.id', 'conti.importo')->find($voce->id);
    expect($senzaTipo->tipo)->toBeNull();

    // `BadMethodCallException` è un `LogicException`: un metodo che non esiste non vale come rifiuto del tipo mancante
    $errore = null;
    try {
        $senzaTipo->segno();
    } catch (Throwable $e) {
        $errore = $e;
    }
    expect($errore)->toBeInstanceOf(LogicException::class)->not->toBeInstanceOf(BadMethodCallException::class);
});
