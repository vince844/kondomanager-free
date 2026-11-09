<?php

/**
 * Le stampe del rendiconto con molte righe: la situazione patrimoniale con le fatture aperte, le posizioni dei condòmini e i crediti per anzianità.
 * Ognuna ha il suo tetto, misurato sul suo modello con richieste vere e non preso dal Libro Giornale: la base (con la lettura dei debiti per le due
 * stampe dei condòmini) e il costo per riga, con il margine dell'85% delle altre stampe. Oltre il tetto la stampa non parte e la pagina lo dice.
 *
 * Le misure stanno accanto ai tetti, nei controller.
 */

use App\Http\Controllers\Gestionale\Movimenti\RendicontoCondominiController;
use App\Http\Controllers\Gestionale\Movimenti\StatoPatrimonialeController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function rsmeTetto(string $classe, string $metodo, string $limite): int
{
    return (new ReflectionMethod($classe, $metodo))->invoke(null, $limite);
}

/** Il `memory_limit` che il controller legge per il tetto: il processo non si abbassa sotto quello che usa. */
function rsmeLimite(string $classe, ?string $limite): void
{
    (new ReflectionProperty($classe, 'limiteMemoria'))->setValue(null, $limite);
}

it('i tetti sono misurati su questi modelli: posizioni 4.518 righe con 128M e 14.592 con 256M; crediti per anzianità 4.226 e 14.490; fatture aperte 7.815 e 24.553 (con l\'esito dei controlli in testa); il riepilogo 7.542 e 23.085', function () {
    $libroGiornale = (new ReflectionMethod(\App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController::class, 'righeStampabiliCon'))->invoke(null, '128M');

    expect(rsmeTetto(RendicontoCondominiController::class, 'posizioniStampabiliCon', '128M'))->toBe(4518)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'posizioniStampabiliCon', '256M'))->toBe(14592)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiStampabiliCon', '128M'))->toBe(4226)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiStampabiliCon', '256M'))->toBe(14490)
        ->and(rsmeTetto(StatoPatrimonialeController::class, 'fattureStampabiliCon', '128M'))->toBe(7815)
        ->and(rsmeTetto(StatoPatrimonialeController::class, 'fattureStampabiliCon', '256M'))->toBe(24553)
        ->and(rsmeTetto(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::class, 'righeStampabiliDelRiepilogo', '128M'))->toBe(7542)
        ->and(rsmeTetto(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::class, 'righeStampabiliDelRiepilogo', '256M'))->toBe(23085)
        ->and(rsmeTetto(\App\Http\Controllers\Gestionale\Movimenti\RiepilogoPerCompetenzaController::class, 'righeStampabiliDelRiepilogo', '128M'))->not->toBe($libroGiornale)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiStampabiliCon', '-1'))->toBe(PHP_INT_MAX)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiStampabiliCon', '64M'))->toBe(0)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiStampabiliCon', '128M'))->not->toBe($libroGiornale);
});

it('la pagina dei crediti per anzianità ha il suo tetto, misurato con la lettura: 16.266 righe con 128M, 52.533 con 256M', function () {
    expect(rsmeTetto(RendicontoCondominiController::class, 'creditiMostrabiliCon', '128M'))->toBe(16266)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiMostrabiliCon', '256M'))->toBe(52533)
        ->and(rsmeTetto(RendicontoCondominiController::class, 'creditiMostrabiliCon', '-1'))->toBe(PHP_INT_MAX);
});

it('oltre il tetto le tre stampe non partono: tornano alla loro pagina con il numero delle righe', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    rsmeLimite(RendicontoCondominiController::class, '56M');
    rsmeLimite(StatoPatrimonialeController::class, '56M');
    try {
        $nove = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.posizioni.stampa', [$s['c'], $s['e']]));
        $dieci = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.condomini.crediti.stampa', [$s['c'], $s['e']]));
        $situazione = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]));
    } finally {
        rsmeLimite(RendicontoCondominiController::class, null);
        rsmeLimite(StatoPatrimonialeController::class, null);
    }

    $nove->assertRedirect(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c']->id, $s['e']->id]));
    // Il § 10 torna al suo elenco: la lettura si è fermata appena oltre il tetto, e la frase dice «più di».
    $dieci->assertRedirect(route('admin.gestionale.esercizi.rendiconto.condomini', [$s['c']->id, $s['e']->id, 'elenco' => 'crediti']));
    $situazione->assertRedirect(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c']->id, $s['e']->id]));
    expect(session('message')['message'] ?? '')->toContain('2 fatture aperte')->toContain('più di quante il server riesce a stampare');
});

it('le tabelle delle stampe sono a blocchi di 100 righe, con un salto di pagina fra un blocco e l\'altro e le celle a classi', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $riga = fn (int $i) => ['anagrafica_id' => $i, 'nome' => "Persona {$i}", 'immobile_id' => $i, 'unita' => "Int. {$i}", 'rata_id' => $i, 'numero_rata' => 1, 'piano' => 'Preventivo 2026',
        'gestione' => 'Ordinaria', 'importo' => 10000, 'scadenza' => '2026-03-05', 'scaduta' => true, 'giorni' => 301, 'fascia' => 'oltre_90', 'origine' => null, 'pregresso_nella_rata' => false, 'annata_scadenza' => '2026',
        'annata_competenza' => '2026', 'versato_dopo' => 0, 'resta_oggi' => 10000, 'lettera' => null];
    $crediti = app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->crediti($s['c'], $s['e']);
    $crediti['righe'] = array_map($riga, range(1, 250));
    $html = View::make('pdf.gestionale.rendiconto_crediti', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'crediti' => $crediti])->render();

    expect(substr_count($html, '<thead>'))->toBe(3)
        ->and(substr_count($html, '<pagebreak'))->toBe(2)
        ->and(substr_count($html, 'Persona '))->toBe(250)
        ->and(substr_count($html, '<td class="c'))->toBe(250 * 11);
});

it('la stampa della situazione, fatta davvero con 1.000 fatture aperte, sta sotto la memoria che la sua formula le dà (58 MB di base e 0,0065 MB a fattura): la formula non è solo un numero scritto', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $pagina = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']);
    $c = app(\App\Services\Gestionale\ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e'], null, [], $pagina);
    $una = $pagina['debiti']['fatture'][0];
    $pagina['debiti']['fatture'] = array_map(fn ($i) => ['numero_documento' => 'FT-'.$i] + $una, range(1, 1000));
    $formula = (new ReflectionClassConstant(StatoPatrimonialeController::class, 'BASE_MB_STAMPA'))->getValue() + 1000 * StatoPatrimonialeController::MB_PER_FATTURA;

    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();
    $mpdf = app(\App\Services\PDF\PdfService::class)->generate('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $pagina,
        'parte' => 'situazione', 'controlli' => $c['controlli'], 'esito' => $c['esito'], 'anno_a_cavallo' => $c['anno_a_cavallo'], 'senza_firma' => true,
        'gruppo_carta' => \App\Services\PDF\GruppoCarta::RENDICONTO_E_ASSEMBLEA], StatoPatrimonialeController::OPZIONI_STAMPA);
    $picco = (memory_get_peak_usage() - $base) / 1048576;

    // Misurato in questo processo, dove Laravel e i dati sono già in memoria: circa 20 MB oltre la base; la formula, che conta tutto il processo, 64,5 MB.
    expect(count($mpdf->pages))->toBeGreaterThan(10)
        ->and($formula)->toBe(64.5)
        ->and($picco)->toBeLessThan($formula);
});
