<?php

/**
 * Cosa resta scoperto: l'impaginazione del PDF (si guarda a video e nel PDF vero); la distinta a tre livelli (il motore e la pagina scendono a
 * qualsiasi profondità, la distinta a due: non è unificata in questa versione).
 *
 * La distinta delle spese, il documento per l'assemblea, con le voci di entrata.
 *
 * Oggi la distinta somma l'entrata come una riga di spesa («TOTALE PREVENTIVO € 1.440,00» per spese € 1.200,00 ed entrata € 240,00) mentre
 * le stampe del riparto, che leggono le righe con il segno, dicono € 960,00: l'assemblea riceve due documenti che si contraddicono.
 *
 * La forma (decisione 87.3): senza entrate il documento è identico a oggi. Con un'entrata: la riga della voce porta «(entrata)» e il
 * meno, «TOTALE PREVENTIVO» resta sulle sole spese, poi «ENTRATE» e «DA RIPARTIRE (spese meno entrate)», più una nota sulla regola.
 * Il testo si legge dalla vista resa (`View::composer` sui dati, poi `view()->render()`): il PDF vero è binario.
 *
 * La frase della nota («Le entrate si sottraggono alle spese: il preventivo da ripartire fra i condòmini è la differenza.») detta una regola e si firma
 * con la clausola che la applica, letta alla lettera: `in_array($conto->tipo, ['spesa', 'uscita']) ? abs($importo) : -abs($importo)`
 * (`CalcoloQuoteService.php:1500-1502` per l'importo del piano, `:1586-1589` per quello della voce): una voce di un tipo diverso da spesa e uscita
 * si distribuisce con il meno. La nota dice «preventivo da ripartire», non «somma delle rate»: il già versato riduce le rate, non il preventivo.
 *
 * Con le sopravvenienze il «TOTALE GENERALE» somma le sopravvenienze al netto da ripartire, e non al preventivo: spese € 1.200,00, entrata
 * € 240,00 e una sopravvenienza di € 300,00 dicono € 1.260,00 (T1.8h); senza entrate il totale generale resta quello di prima, preventivo più
 * sopravvenienze (T1.8i).
 *
 * I test che cominciano con «controprova» o che dicono «identica a oggi» sono verdi prima e dopo la correzione.
 */

use App\Models\Gestionale\Conto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il testo reso di una vista di stampa, e i dati con cui è stata resa: spazi normalizzati, senza gli stili. */
function b52tTestoVista(string $vista, $test, string $rotta, array $parametri): array
{
    $dati = null;
    View::composer($vista, function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $test->actingAs($test->user)->get(route($rotta, $parametri))->assertOk();
    $html = preg_replace('#<style.*?</style>#si', '', view($vista, $dati)->render());

    return [preg_replace('/\s+/u', ' ', trim(strip_tags($html))), $dati];
}

it('T1.8 la distinta con un\'entrata: «TOTALE PREVENTIVO» sulle spese, «ENTRATE» a parte, «DA RIPARTIRE» il netto', function () {
    $s = b52tScenario($this);

    [$testo, $dati] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('TOTALE PREVENTIVO € 1.200,00')
        ->toContain('ENTRATE € 240,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 960,00')
        ->not->toContain('1.440,00');
    expect($dati['totalePreventivo'])->toBe(120000);
});

it('T1.8b la riga dell\'entrata dice «(entrata)» e porta il meno, come le stampe del riparto, e il totale del gruppo ha il segno', function () {
    $s = b52tScenario($this);

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('Proventi antenna (entrata) -240,00')
        ->toContain('Totale Proventi antenna -240,00')
        ->toContain('Spese generali 1.200,00')
        ->toContain('Voce Importo (€)')                                  // l'intestazione non dice più «Voce di spesa»
        ->toContain('Le entrate si sottraggono alle spese: il preventivo da ripartire fra i condòmini è la differenza.');
});

it('T1.8c controprova: la distinta senza entrate è identica a oggi: stessa intestazione, un solo totale, nessuna nota nuova', function () {
    $s = b52tScenario($this, entrata: '0,00');   // la voce a zero non entra nel conto: il documento è di sole spese

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('Voce di spesa Importo (€)')
        ->toContain('TOTALE PREVENTIVO € 1.200,00')
        ->not->toContain('ENTRATE')
        ->not->toContain('DA RIPARTIRE')
        ->not->toContain('(entrata)')
        ->not->toContain('si sottraggono');
});

it('T1.8d la distinta e il riparto dicono lo stesso numero sullo stesso piano: € 960,00', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);

    [$distinta] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);
    [$riparto] = b52tTestoVista('pdf.gestionale.riparto_capitoli', $this, 'admin.gestionale.esercizi.piani-rate.print-riparto-capitoli', [$s['c'], $s['e'], $piano]);

    // il riparto per capitolo ha tre colonne di totale: spese, entrate (col meno), netto
    expect($riparto)->toContain('Totali generali € 1.200,00 € -240,00 € 960,00');
    expect($distinta)->toContain('DA RIPARTIRE (spese meno entrate) € 960,00');
});

it('T1.8e la sola entrata: «TOTALE PREVENTIVO» è zero e «DA RIPARTIRE» è −240,00, non una cifra positiva', function () {
    $s = b52tScenario($this, soloEntrata: true);

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('TOTALE PREVENTIVO € 0,00')
        ->toContain('ENTRATE € 240,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € -240,00');
});

it('T1.8f un capitolo con sottoconti di tipo diverso: ogni riga col suo segno e il totale del gruppo è il netto (−400,00, non 600,00)', function () {
    $s = b52tScenarioCapitoloMisto($this);

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('Affitto antenna (entrata) -500,00')
        ->toContain('Manutenzione antenna 100,00')
        ->toContain('Totale Antenna -400,00')
        ->toContain('TOTALE PREVENTIVO € 1.300,00')
        ->toContain('ENTRATE € 500,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 800,00');
});

it('T1.8g un\'entrata con l\'importo scritto negativo (dato di una versione vecchia) si stampa come le altre: in valore assoluto col meno', function () {
    $s = b52tScenario($this);
    Illuminate\Support\Facades\DB::table('conti')->where('id', $s['entrata']->id)->update(['importo' => -24000]);

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('TOTALE PREVENTIVO € 1.200,00')
        ->toContain('ENTRATE € 240,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 960,00');
});

it('T1.8h le sopravvenienze con un\'entrata: «TOTALE GENERALE» somma le sopravvenienze al netto da ripartire (€ 960,00 + € 300,00 = € 1.260,00), non al preventivo', function () {
    $s = b52tScenario($this);
    $sopravvenienza = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Sopravvenienza passiva', 'tipo' => 'spesa', 'importo' => 30000]);
    $sopravvenienza->forceFill(['is_tecnico' => true])->save();

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('TOTALE PREVENTIVO € 1.200,00')
        ->toContain('DA RIPARTIRE (spese meno entrate) € 960,00')
        ->toContain('SOPRAVVENIENZE € 300,00')
        ->toContain('TOTALE GENERALE (da ripartire + sopravvenienze) € 1.260,00')
        ->not->toContain('€ 1.500,00');
});

it('T1.8i controprova: le sopravvenienze senza entrate, «TOTALE GENERALE (preventivo + sopravvenienze)» è € 1.500,00, come prima', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $sopravvenienza = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Sopravvenienza passiva', 'tipo' => 'spesa', 'importo' => 30000]);
    $sopravvenienza->forceFill(['is_tecnico' => true])->save();

    [$testo] = b52tTestoVista('pdf.gestionale.distinta_spese', $this, 'admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]);

    expect($testo)->toContain('TOTALE GENERALE (preventivo + sopravvenienze) € 1.500,00')->not->toContain('DA RIPARTIRE');
});
