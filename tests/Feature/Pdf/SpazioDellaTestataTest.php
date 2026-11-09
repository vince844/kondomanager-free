<?php

/**
 * Lo spazio della testata dello studio, come soglie per famiglia, misurato con mPDF (il margine alto del documento con la rete dello spazio, e la
 * posizione dei testi nei flussi delle pagine), con il nome del condominio di 60 caratteri e lo studio con tutti i dati e il logo.
 *
 * - T0 (la testata di `pdf.base`): la testata della prima pagina finisce entro una soglia; quella delle pagine seguenti, una riga, sta nella fascia
 *   di oggi (il margine alto resta quello scelto dalla stampa).
 * - TI (le viste Inter) e Tc (la lettera): quanti millimetri perde la prima pagina, al massimo.
 * - Il frontespizio del fascicolo resta in una pagina; i riparti restano in due pagine con la firma.
 * - Il registro di contabilità e il libro giornale con 500 movimenti: le pagine dopo la prima hanno le stesse righe con e senza lo studio, e la
 *   prima ne perde poche.
 */

use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Database\Seeders\CondominioStampeSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/../Gestionale/LettereScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('local');
    Storage::fake('public');
});

/** Via Roma al 15/01/2027, con il nome del condominio di 60 caratteri. */
function sdtScenario($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $s['c']->update(['nome' => CDS_CONDOMINIO_LUNGO, 'indirizzo' => 'Via Roma 23, 20100 Milano', 'codice_fiscale' => '90012345678']);
    $s['c'] = $s['c']->fresh();

    return $s;
}

/** La stampa dalla rotta, con la spia: [PDF, stampa]. */
function sdtStampa($test, $spia, string $indirizzo): array
{
    $prima = count($spia->stampe);
    $pdf = (string) $test->actingAs($test->user)->get($indirizzo)->assertOk()->getContent();

    return [$pdf, $spia->stampe[$prima]];
}

/**
 * Dove finisce la testata di una stampa: la stessa vista, con gli stessi dati, rifatta con il margine alto al margine della testata e la rete
 * dello spazio: dopo la scrittura il margine alto è il fondo della testata (della prima pagina, per un documento di una pagina).
 */
function sdtFondoDellaTestata(array $stampa, array $altro = []): float
{
    $opzioni = ['margin_top' => $stampa['opzioni']['margin_header'] ?? 10, 'setAutoTopMargin' => 'stretch', 'autoMarginPadding' => 0] + $stampa['opzioni'];

    return (float) app(PdfService::class)->generate($stampa['vista'], $altro + $stampa['dati'], $opzioni)->tMargin;
}

it('T0: con lo studio e il nome di 60 caratteri la testata della prima pagina finisce entro 31,5 mm, in verticale e in orizzontale, ed entro 37 con la riga del titolo; quella delle pagine seguenti entro 15 mm; con il margine della stampa le pagine seguenti cominciano dove cominciano oggi', function () {
    $s = sdtScenario($this);
    cdsStudioPieno(true);
    $spia = cdsSpia();

    foreach ([
        'verticale' => [route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]), 32, 31.5],
        'orizzontale' => [route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$s['c'], $s['e']]), 32, 31.5],
        'con la riga del titolo' => [route('admin.gestionale.rate-scadute.stampa', [$s['c']]), 38, 37.0],
    ] as $verso => [$indirizzo, $margine, $soglia]) {
        [$pdf, $stampa] = sdtStampa($this, $spia, $indirizzo);
        expect(cdsNumeroPagine($pdf))->toBe(1, $verso)
            ->and(sdtFondoDellaTestata($stampa))->toBeLessThanOrEqual($soglia, $verso)
            ->and(sdtFondoDellaTestata($stampa, ['comincia_con_la_testatina' => true]))->toBeLessThanOrEqual(15.0, $verso);

        $seguenti = app(PdfService::class)->generate($stampa['vista'], ['comincia_con_la_testatina' => true] + $stampa['dati'], $stampa['opzioni']);
        expect($seguenti->tMargin)->toEqual($margine, $verso);
    }
});

it('TI e Tc: la prima pagina perde al massimo 5,5 mm nel registro e niente nella situazione patrimoniale; la lettera 28 mm', function () {
    $s = sdtScenario($this);
    $spia = cdsSpia();
    $misure = [
        'registro' => [route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]), 'Movimenti in entrata e in uscita', 5.5],
        'situazione' => [route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e'], 'parte' => 'situazione']), 'Situazione al', 0.0],
    ];
    $y = [];
    foreach ([false, true] as $conStudio) {
        $conStudio ? cdsStudioPieno(true) : cdsSenzaStudio();
        foreach ($misure as $nome => [$indirizzo, $testo]) {
            [$pdf] = sdtStampa($this, $spia, $indirizzo);
            $y[$nome][$conStudio] = cdsDoveObbligatorio($pdf, 1, $testo)[1];
        }
    }
    // Quanti millimetri perde la prima pagina: dove comincia il corpo con lo studio, meno dove comincia senza (negativo: ne guadagna).
    foreach ($misure as $nome => [, , $soglia]) {
        expect($y[$nome][true] - $y[$nome][false])->toBeLessThanOrEqual($soglia, $nome);
    }

    // La lettera: lo scenario delle lettere, con lo stesso nome del condominio.
    $l = lttScenario($this);
    $l['c']->update(['nome' => CDS_CONDOMINIO_LUNGO]);
    $yLettera = [];
    foreach ([false, true] as $conStudio) {
        $conStudio ? cdsStudioPieno(true) : cdsSenzaStudio();
        $pdf = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l, ['persone' => [$l['persone']['ugo']->id]]))->assertOk()->getContent();
        $yLettera[$conStudio] = cdsDoveObbligatorio($pdf, 1, 'Gentile Ugo')[1];
    }
    // Con lo studio: il blocco dello studio e i 6 px prima del condominio; al più 28 mm (decisione 248 D1: 25–28). Senza lo studio il nome del
    // condominio a 11 punti (decisione 260) fa cominciare la lettera 0,85 mm più in alto: la differenza è 27,4 mm, la lettera con lo studio non si muove.
    expect($yLettera[true] - $yLettera[false])->toBeLessThanOrEqual(28.0, json_encode($yLettera));
});

it('il frontespizio del fascicolo, con lo studio e il nome di 60 caratteri, resta in una pagina', function () {
    $s = sdtScenario($this);
    cdsStudioPieno(true);
    $spia = cdsSpia();

    sdtStampa($this, $spia, route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));

    $frontespizi = array_values(array_filter($spia->stampe, fn ($x) => $x['vista'] === 'pdf.gestionale.rendiconto_frontespizio'));
    expect($frontespizi)->toHaveCount(1)->and($frontespizi[0]['pagine'])->toBe(1);
});

it('i riparti del condominio delle stampe (48 righe, sei tabelle), con la firma, lo studio e il nome di 60 caratteri, restano in due pagine', function () {
    $seeder = new CondominioStampeSeeder;
    $seeder->run();
    $condominio = $seeder->condominio();
    $condominio->update(['nome' => CDS_CONDOMINIO_LUNGO]);
    $piano = $seeder->pianoRate();
    $esercizio = $condominio->esercizi()->firstOrFail();
    cdsStudioPieno(true);
    Storage::disk('public')->put('firme/prova.png', cdsPng(300, 100));
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->firma_stampe_path = 'firme/prova.png';
    $impostazioni->save();

    foreach (['tabelle', 'capitoli'] as $tipo) {
        $pdf = (string) $this->actingAs($this->user)->get(route("admin.gestionale.esercizi.piani-rate.print-riparto-{$tipo}", [$condominio, $esercizio, $piano]))->assertOk()->getContent();
        expect(cdsNumeroPagine($pdf))->toBeLessThanOrEqual(2, $tipo)
            ->and(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni');
    }
});

/**
 * Le righe per pagina di una stampa con 500 movimenti: i dati presi dalla rotta (la spia), le righe moltiplicate con un segno contato pagina per
 * pagina. La stampa si rifà con le stesse opzioni.
 *
 * @return list<int>
 */
function sdtRighePerPagina(array $stampa, string $chiave, string $segno): array
{
    $dati = $stampa['dati'];
    $una = $dati['righe'][0];
    $dati['righe'] = array_map(fn (int $i) => array_merge($una, [$chiave => sprintf('%s%03d', $segno, $i)]), range(1, 500));
    $pdf = app(PdfService::class)->generate($stampa['vista'], $dati, $stampa['opzioni'])->Output('', 'S');

    return array_map(fn (array $pagina) => count(array_filter($pagina, fn ($x) => str_starts_with($x[0], $segno))), cdsPagine($pdf));
}

it('il registro di contabilità e il libro giornale con 500 movimenti: dalla seconda pagina le stesse righe con e senza lo studio; la prima ne perde al massimo 2', function () {
    $s = sdtScenario($this);
    $spia = cdsSpia();
    [, $registro] = sdtStampa($this, $spia, route('admin.gestionale.esercizi.registro-contabilita.print', [$s['c'], $s['e']]));
    [, $giornale] = sdtStampa($this, $spia, route('admin.gestionale.esercizi.scritture.print', [$s['c'], $s['e']]));

    foreach (['registro' => [$registro, 'protocollo', 'MOV-'], 'giornale' => [$giornale, 'causale', 'Movimento ']] as $nome => [$stampa, $chiave, $segno]) {
        cdsSenzaStudio();
        $senza = sdtRighePerPagina($stampa, $chiave, $segno);
        cdsStudioPieno(true);
        $con = sdtRighePerPagina($stampa, $chiave, $segno);

        expect(array_sum($senza))->toBe(500, $nome)->and(array_sum($con))->toBe(500, $nome)
            ->and($con[1])->toBe($senza[1], $nome)
            ->and($senza[0] - $con[0])->toBeGreaterThanOrEqual(0, $nome)->toBeLessThanOrEqual(2, $nome);
    }
});
