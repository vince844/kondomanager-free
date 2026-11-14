<?php

/**
 * Il consuntivo per persona e le spese per singole unità, con la fattura, la nota di credito e il piano da fatture fatti dalle pagine
 * (gli scenari della spesa ad personam): Interno 1 di Mario Rossi e Interno 2 di Bice Bianchi, 500 millesimi ciascuno sulla tabella
 * «Proprietà», la gestione straordinaria «Lavori 2026» con la delibera del 01/02/2026.
 *
 * Le cifre sono rifatte a mano dalla regola, in centesimi; positivo = debito. Una spesa personale chiesta da un piano non è fra le
 * personali, ed esce da B delle spese comuni (la parte personale delle quote, con il suo segno); una nota di credito fra le personali
 * vale con il suo segno, e una nota collegata a una riga già chiesta conta come chiesta (il piano ha chiesto il netto).
 */

use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** Il consuntivo al 31/12/2026 e la riga di una persona su un interno. */
function cpprRiga($test, array $s, string $chi, string $interno): ?array
{
    $test->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());

    return $c['persone'][$s['g']->id][$s['persone'][$chi]->id][$s['unita'][$interno]->id] ?? null;
}

/** La nota di credito collegata dal modulo della fattura, sulla riga di Interno 1. */
function cpprNota($test, array $s, $fattura, float $euro): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'tipo_documento' => 'nota_credito', 'fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true,
        'numero_documento' => 'NC-CPPR', 'data_documento' => '2026-03-15', 'data_scadenza' => '2026-04-15', 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'righe' => [b59apRigaAdPersonam($s, $euro, 0, '1', 'Sconto')],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
    ]))->assertSessionHasNoErrors();
}

it('una spesa personale chiesta con le rate non è fra le personali ed esce da B delle comuni: conguaglio zero', function () {
    // Facciata € 500,00 a millesimi (250,00 e 250,00) e il balcone di Interno 1 € 100,00; il piano chiede € 600,00 in due rate, emesse.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '600,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    $mario = cpprRiga($this, $s, 'mario', '1');
    $bice = cpprRiga($this, $s, 'bice', '2');

    // Mario: le quote 350,00, di cui 100,00 personali: B delle comuni 250,00; A 250,00; personali non chieste 0; conguaglio 0; posizione 350,00.
    expect($mario['personali']['non_chieste'])->toBe(0)->and($mario['b']['quote'])->toBe(25000)->and($mario['a'])->toBe(25000)
        ->and($mario['conguaglio'])->toBe(0)->and($mario['posizione'])->toBe(35000)->and($mario['saldo'])->toBe(35000)
        ->and($mario['saldo'])->toBe(b59apEstratto($this, $s, $s['persone']['mario']))
        ->and([$bice['a'], $bice['b']['quote'], $bice['conguaglio']])->toBe([25000, 25000, 0]);
});

it('una parte personale negativa (un rimborso sulla riga dell\'unità) chiesta con le rate esce da B delle comuni con il suo segno', function () {
    // Facciata € 500,00 e un rimborso di −€ 30,00 su Interno 1: il piano chiede € 470,00. Mario: quote 220,00 = 250,00 − 30,00; B delle comuni
    // 220,00 − (−30,00) = 250,00 = A; conguaglio 0; saldo 220,00. Con la parte personale mai sotto zero B sarebbe 220,00 e il conguaglio +30,00.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, -30.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '470,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    $mario = cpprRiga($this, $s, 'mario', '1');

    expect($mario['personali']['non_chieste'])->toBe(0)->and($mario['b']['quote'])->toBe(25000)->and($mario['a'])->toBe(25000)
        ->and($mario['conguaglio'])->toBe(0)->and($mario['saldo'])->toBe(22000);
});

it('una nota di credito collegata a una riga personale già chiesta dal piano conta come chiesta: conguaglio zero, saldo come l\'estratto conto', function () {
    // Balcone € 1.000,00 su Interno 1, nota collegata −€ 200,00 sulla stessa riga, il piano chiede il netto € 800,00 in due rate, emesse.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 0)]);
    cpprNota($this, $s, $fattura, 200.00);
    $piano = b59apPiano($this, $s, $fattura, 2, '800,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    $mario = cpprRiga($this, $s, 'mario', '1');

    expect($mario['personali']['non_chieste'])->toBe(0)->and($mario['b']['quote'])->toBe(0)->and($mario['conguaglio'])->toBe(0)
        ->and($mario['posizione'])->toBe(80000)->and($mario['saldo'])->toBe(80000)
        ->and($mario['saldo'])->toBe(b59apEstratto($this, $s, $s['persone']['mario']));
});

it('una nota di credito collegata a una riga personale che nessun piano ha chiesto vale con il suo segno: personali 800,00, come le anticipazioni aperte', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 0)]);
    cpprNota($this, $s, $fattura, 200.00);
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $mario = $c['persone'][$s['g']->id][$s['persone']['mario']->id][$s['unita']['1']->id];

    expect($mario['personali']['non_chieste'])->toBe(80000)->and($mario['conguaglio'])->toBe(80000)
        ->and($c['gestioni'][$s['g']->id]['personali_aperte'])->toBe([$s['unita']['1']->id => 80000]);
});

it('un piano senza il registro del riparto (di prima della 1.11.0-beta.29): la spesa personale che ha chiesto non è fra le personali', function () {
    // Balcone € 1.000,00 su Interno 1 chiesto dal piano in due rate emesse; poi il piano perde le righe del riparto e la chiave della parte personale
    // delle quote, come un piano della 1.9 o della 1.10. B toglie la parte personale ricostruita; le personali non la contano una seconda volta.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '1000,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    b59apSenzaChiave($piano);
    $mario = cpprRiga($this, $s, 'mario', '1');

    expect($mario['personali']['non_chieste'])->toBe(0)->and($mario['b']['quote'])->toBe(0)->and($mario['conguaglio'])->toBe(0)
        ->and($mario['saldo'])->toBe(100000);
});

it('una nota collegata con una riga in meno e una «in diminuzione» sulla stessa unità conta come chiesta sul suo netto', function () {
    // Balcone € 1.000,00 su Interno 1; la nota collegata −€ 300,00 e +€ 100,00 sulla stessa riga (netto −200,00); il piano chiede € 800,00.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 0)]);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'tipo_documento' => 'nota_credito', 'fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true,
        'numero_documento' => 'NC-CPPR2', 'data_documento' => '2026-03-15', 'data_scadenza' => '2026-04-15', 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'righe' => [b59apRigaAdPersonam($s, 300.00, 0, '1', 'Sconto'), b59apRigaAdPersonam($s, -100.00, 0, '1', 'Ripresa')],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
    ]))->assertSessionHasNoErrors();
    expect(DB::table('righe_fattura')->where('fattura_passiva_id', '!=', $fattura->id)->orderBy('id')->pluck('importo_imponibile')->map(fn ($v) => (int) $v)->all())->toBe([-30000, 10000]);
    $piano = b59apPiano($this, $s, $fattura, 2, '800,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    $mario = cpprRiga($this, $s, 'mario', '1');

    expect($mario['personali']['non_chieste'])->toBe(0)->and($mario['personali']['righe'])->toBe([])->and($mario['conguaglio'])->toBe(0)
        ->and($mario['saldo'])->toBe(80000)->and($mario['saldo'])->toBe(b59apEstratto($this, $s, $s['persone']['mario']));
});

it('la parte personale chiesta da un piano dell\'esercizio e non ancora emessa si legge sulla riga della persona', function () {
    // Facciata € 500,00 e balcone € 100,00, piano in due rate, emessa la prima: della parte personale 50,00 emessi e 50,00 no.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '600,00');
    b59apEmetti($this, $s, $piano, [1]);
    $mario = cpprRiga($this, $s, 'mario', '1');

    // B delle comuni 250,00, di cui non emessi 175,00 − 50,00 = 125,00; le personali chieste e non emesse 50,00.
    expect($mario['b']['quote'])->toBe(25000)->and($mario['b']['di_cui_non_emesse'])->toBe(12500)
        ->and($mario['personali']['chieste_non_emesse'])->toBe(5000)->and($mario['personali']['non_chieste'])->toBe(0);
});

