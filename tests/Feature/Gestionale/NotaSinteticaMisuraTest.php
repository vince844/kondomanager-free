<?php

/**
 * La misura della nota sintetica nel condominio grande delle misure del conguaglio (3.000 unità, 3.300 persone, 30 voci, 300 passaggi): la lettura dei
 * fatti che le altre parti del fascicolo non leggono (i crediti per anzianità, le lettere, i passaggi, i piani, i sei mesi) e la stampa della nota più lunga
 * che la pagina permette: ogni campo al suo massimo (cinque testi da 5.000 caratteri; ogni fatto tenuto e riscritto, ogni causa, ogni spiegazione e ogni
 * risposta della dichiarazione da 2.000; 30 contratti con le note, 30 debiti certi, 30 cose da decidere da 500; i dipendenti da 500). È il costo fisso della
 * parte nel tetto del fascicolo ({@see \App\Services\Gestionale\FascicoloDelRendiconto::NOTA_SECONDI}, {@see \App\Services\Gestionale\FascicoloDelRendiconto::NOTA_MB}):
 * le letture che il fascicolo fa già (i controlli, la Sintesi, il conguaglio) restano fuori dalla misura.
 */

use App\Models\User;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2027-03-01 10:00:00');
    Storage::fake('local');
});

it('nel condominio grande la nota più lunga che la pagina permette sta nel costo fisso che il fascicolo le conta', function () {
    $s = cgmCondominio(false);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $letture = $nota->letture($s['c'], $s['e']);
    $proposti = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture);
    $fatti = $proposti['fatti'];
    $testo = trim(str_repeat('Una frase di prova della nota sintetica, con parole di ogni giorno. ', 80));
    $lungo = fn (int $n) => mb_substr($testo, 0, $n);
    $frase = $lungo(NotaSinteticaDelRendiconto::LUNGHEZZA_FRASE);
    $riga = $lungo(NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA);
    // Le cause delle voci scostate e le spiegazioni dei controlli si stampano con la frase del programma: quei fatti restano con la loro frase, gli altri
    // sono riscritti al massimo.
    $conLaFrase = collect($fatti)->filter(fn ($f) => $f['cause'] || $f['spiegazione'])->pluck('chiave')->all();
    $stati = collect($fatti)->mapWithKeys(fn ($f) => [$f['chiave'] => in_array($f['chiave'], $conLaFrase, true) ? ['stato' => 'tenuto']
        : ['stato' => 'tenuto', 'riscritto' => $frase, 'proposto' => 'x', 'il' => '2027-03-01']])->all();
    $cause = collect($fatti)->flatMap(fn ($f) => array_column($f['righe'], 'voce_id'))->mapWithKeys(fn ($id) => [(string) $id => $frase])->all();
    $spiegazioni = collect($fatti)->filter(fn ($f) => $f['spiegazione'])->mapWithKeys(fn ($f) => [$f['controllo'] => $frase])->all();
    $tutte = fn (string $stato) => array_fill_keys(array_keys(NotaSinteticaDelRendiconto::DICHIARAZIONE), ['stato' => $stato, 'testo' => $frase]);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza([
        'fatti' => $stati,
        'parti' => array_fill_keys(array_keys(NotaSinteticaDelRendiconto::PARTI), ['testo' => $lungo(NotaSinteticaDelRendiconto::LUNGHEZZA)]),
        'cause' => $cause,
        'spiegazioni' => $spiegazioni,
        'contratti' => array_fill(0, NotaSinteticaDelRendiconto::RIGHE_ELENCO, ['cosa' => $lungo(255), 'con_chi' => $lungo(255), 'importo' => 123456,
            'scadenza' => '2027-12-31', 'rinnovo' => $lungo(255), 'note' => $riga]),
        'debiti_certi' => array_fill(0, NotaSinteticaDelRendiconto::RIGHE_ELENCO, ['creditore' => $lungo(255), 'importo' => 350000, 'fonte' => $lungo(255),
            'scadenza' => '2027-03-31']),
        'da_decidere' => array_fill(0, NotaSinteticaDelRendiconto::RIGHE_ELENCO, $riga),
        'dichiarazione' => $tutte('ci_sono'),
        'dipendenti' => ['stato' => 'ci_sono', 'testo' => $riga],
    ]), User::factory()->create());

    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $stampa = $nota->perLaStampa($s['c'], $s['e'], $letture);
    $pdf = app(PdfService::class)->generate('pdf.gestionale.rendiconto_nota', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'nota' => $stampa,
        'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], \App\Http\Controllers\Gestionale\Movimenti\StatoPatrimonialeController::OPZIONI_STAMPA)->Output('', 'S');
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    $caratteri = mb_strlen(collect($stampa['parti'])->flatMap(fn ($p) => [...array_column($p['fatti'], 'testo'), $p['testo'], ...($p['da_decidere'] ?? []),
        ...($p['dichiarazione'] ?? [])])->implode(' '));
    fwrite(STDERR, sprintf("\n[misura] la nota più lunga nel condominio grande: %.2f s, %.1f MB in più, %d pagine, %d KB, %d caratteri (senza gli elenchi)\n", $secondi,
        $mega, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'), intdiv(strlen($pdf), 1024), $caratteri));

    // Le pagine, per guardarle (i titoli degli elenchi non restano soli in fondo alla pagina): nella cartella data dall'ambiente.
    if (($cartella = getenv('NOTA_IMMAGINI') ?: null) !== null && trim((string) shell_exec('command -v pdftoppm')) !== '') {
        @mkdir($cartella, 0777, true);
        file_put_contents("{$cartella}/nota-piu-lunga.pdf", $pdf);
        shell_exec('pdftoppm -r 50 -png '.escapeshellarg("{$cartella}/nota-piu-lunga.pdf").' '.escapeshellarg("{$cartella}/nota-piu-lunga"));
    }

    expect($stampa['vuota'])->toBeFalse()
        // Ogni fatto c'è, con la frase riscritta o con la causa e la spiegazione accanto.
        ->and(collect($stampa['parti'])->sum(fn ($p) => count($p['fatti'])))->toBe(count($fatti))
        ->and($secondi)->toBeLessThan(FascicoloDelRendiconto::NOTA_SECONDI)
        ->and($mega)->toBeLessThan(FascicoloDelRendiconto::NOTA_MB);
});
