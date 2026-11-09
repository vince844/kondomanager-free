<?php

use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__ . '/RicevutaIncassoScenario.php';
require_once __DIR__ . '/../Pdf/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

/**
 * Il tetto delle ricevute per archivio (200, decisione 208) vale anche per la memoria: lo .zip con un PDF per persona è per chi ha un hosting
 * condiviso, dove PHP ha di solito 128 MB. mPDF tiene dei riferimenti circolari: senza una raccolta dopo ogni PDF la memoria di una persona non
 * torna libera prima della successiva. Lo .zip si scrive a pezzi: ogni PDF va sul disco appena è fatto, e la memoria tiene una persona alla volta,
 * non la somma dei PDF. Con la carta intestata ogni PDF porta la sua copia della carta: con una carta di peso vero (una scansione in PDF di 1,6 MB)
 * venti ricevute chiedevano circa 90 MB oltre la partenza quando lo .zip si faceva in memoria; a pezzi la memoria non cresce con le persone.
 *
 * Che cosa NON copre: la memoria di una richiesta vera, che parte da meno del processo dei test. Per una prova a 128 MB, il file si lancia da solo:
 * `php -d memory_limit=128M vendor/bin/pest tests/Feature/Gestionale/RicevutaIncassoMemoriaTest.php`. Le carte della prova delle 200 ricevute pesano
 * pochi KB (un PDF con due righe di testo, un PNG a tinta piatta); quella delle 20 è di peso vero.
 */
function memoriaPersonaConIncasso(object $s, int $n): ScritturaContabile
{
    $p = Anagrafica::create(['nome' => sprintf('Persona %03d', $n), 'email' => "p{$n}@prova.it", 'indirizzo' => 'Via ' . (100 + $n) . ', Milano',
        'codice_fiscale' => 'CF' . strtoupper(Str::random(14))]);
    $p->condomini()->attach($s->condominio->id);
    $u = Immobile::create(['condominio_id' => $s->condominio->id, 'nome' => 'Appartamento ' . (100 + $n), 'descrizione' => 'Appartamento', 'interno' => (string) (100 + $n)]);
    $u->anagrafiche()->attach($p->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $q = RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'importo' => 1000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);

    return incassaPerRicevuta($s, $p, [[$q, 10.00]], '2026-02-01', 10.00);
}

/**
 * La carta delle ricevute con la carta intestata, scritta dal test: un PDF di due pagine A4 fatto con mPDF, o un'immagine A4 di 1.240 × 1.754 pixel. Ogni
 * PDF dello .zip si rifà sopra la carta.
 */
function memoriaCartaIntestata(string $tipo): void
{
    \Illuminate\Support\Facades\Storage::fake('public');
    if ($tipo === 'pdf') {
        $m = new \Mpdf\Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
        $m->WriteHTML('<div style="font-size: 20pt; color: #7a1f1f;">STUDIO DELLA CARTA</div><div>Via della Carta 1</div><pagebreak /><div>STUDIO DELLA CARTA</div>');
        $contenuto = $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } else {
        $im = imagecreatetruecolor(1240, 1754);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 0, 0, 1239, 150, imagecolorallocate($im, 122, 31, 31));
        ob_start();
        imagepng($im);
        $contenuto = (string) ob_get_clean();
    }
    $percorso = 'settings/carte/memoria.'.($tipo === 'pdf' ? 'pdf' : 'png');
    \Illuminate\Support\Facades\Storage::disk('public')->put($percorso, $contenuto);
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della memoria', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null, 'carta_path' => $percorso,
        'carta_tipo' => $tipo === 'pdf' ? 'pdf' : 'immagine', 'carta_pagine' => $tipo === 'pdf' ? 2 : 1, 'margine_alto' => 40, 'margine_basso' => 20,
        'margine_sinistro' => 15, 'margine_destro' => 15, 'predefinita' => false]);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->carta_per_gruppo = ['lettere' => null, 'ricevute_e_avvisi' => $carta->id, 'rendiconto_e_assemblea' => null, 'per_la_persona' => null, 'registri_e_elenchi' => null];
    $impostazioni->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

test('lo .zip di 200 ricevute di 200 persone non chiede più di 90 MB oltre la partenza, anche con una carta intestata di prova di pochi KB, che ogni PDF porta; lo .zip è un file sul disco', function (?string $carta) {
    $s = scenarioRicevutaIncasso();
    if ($carta !== null) {
        memoriaCartaIntestata($carta);
    }
    foreach (range(1, 200) as $n) {
        memoriaPersonaConIncasso($s, $n);
    }
    $incassi = ScritturaContabile::where('condominio_id', $s->condominio->id)->orderBy('id')->get();
    $servizio = app(EmissioneRicevute::class);
    $scelte = $servizio->scelte(['bollo' => 'non_dovuta']);
    expect($incassi)->toHaveCount(200);

    gc_collect_cycles();
    $partenza = memory_get_usage();
    memory_reset_peak_usage();
    $esito = $servizio->archivioPerPersona($s->condominio, $incassi, $scelte, null);
    $oltre = (memory_get_peak_usage() - $partenza) / 1048576;

    $archivio = new ZipArchive;
    $percorso = $esito['zip'];
    expect($archivio->open($percorso))->toBeTrue();
    expect($archivio->numFiles)->toBe(201);
    // La carta c'è davvero: il primo PDF dello .zip la porta sotto la ricevuta (il testo della carta in PDF, l'immagine su tutto il foglio).
    $primo = null;
    for ($i = 0; $i < $archivio->numFiles && $primo === null; $i++) {
        $primo = str_ends_with((string) $archivio->getNameIndex($i), '.pdf') ? (string) $archivio->getFromIndex($i) : null;
    }
    $archivio->close();
    $immagini = cdsImmagini((string) $primo, 1);
    expect($primo)->not->toBeNull()
        ->and(str_contains(cdsTesto($primo, 1), 'STUDIO DELLA CARTA'))->toBe($carta === 'pdf')
        ->and(($immagini[0] ?? null) !== null && abs($immagini[0][0] - 210.0) < 0.2 && abs($immagini[0][1] - 297.0) < 0.2)->toBe($carta === 'immagine');
    unlink($percorso);
    expect(RegistroInvio::count())->toBe(200)
        ->and($oltre)->toBeLessThan(90.0, 'l\'archivio ha chiesto ' . round($oltre) . ' MB oltre la partenza');
})->with(['senza la carta intestata' => [null], 'con la carta intestata in PDF' => ['pdf'], 'con la carta intestata in immagine' => ['immagine']]);

/**
 * Una carta intestata di peso vero, scritta dal test: una scansione A4 a 300 punti per pollice con la grana della carta, in JPG, dentro un PDF di due pagine
 * fatto con mPDF (circa 1,6 MB, come esce da uno scanner). Scelta per le ricevute.
 */
function memoriaCartaDiPesoVero(): int
{
    // La scansione si disegna in un processo a parte: la tela di 2.480 × 3.508 pixel chiede da sola più di 40 MB, che il processo dei test, lanciato
    // a 128 MB, non ha.
    $jpg = tempnam(sys_get_temp_dir(), 'memoria').'.jpg';
    $codice = '$t = imagecreatetruecolor(496, 501); imagefilledrectangle($t, 0, 0, 495, 500, imagecolorallocate($t, 255, 255, 255)); mt_srand(570);'
        .' for ($i = 0; $i < 496 * 501 / 2; $i++) { $g = 250 - mt_rand(0, 12); imagesetpixel($t, mt_rand(0, 495), mt_rand(0, 500), imagecolorallocate($t, $g, $g - mt_rand(0, 3), $g)); }'
        .' $im = imagecreatetruecolor(2480, 3508); for ($x = 0; $x < 2480; $x += 496) { for ($y = 0; $y < 3508; $y += 501) { imagecopy($im, $t, $x, $y, 0, 0, 496, 501); } }'
        .' imagefilledrectangle($im, 0, 0, 2479, 290, imagecolorallocate($im, 122, 31, 31)); imagejpeg($im, $argv[1], 85);';
    (new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $codice, $jpg]))->mustRun();
    $m = new \Mpdf\Mpdf(['tempDir' => storage_path('framework/cache/mpdf'), 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0]);
    $m->WriteHTML('<div style="font-size: 20pt; color: #7a1f1f;">STUDIO DELLA CARTA</div>');
    $m->Image($jpg, 0, 0, 210, 297, 'jpg', '', true, false);
    $m->AddPage();
    $m->Image($jpg, 0, 0, 210, 297, 'jpg', '', true, false);
    $contenuto = $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    @unlink($jpg);

    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::disk('public')->put('settings/carte/scansione.pdf', $contenuto);
    $carta = \App\Models\CartaStampa::create(['nome' => 'La scansione', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null, 'carta_path' => 'settings/carte/scansione.pdf',
        'carta_tipo' => 'pdf', 'carta_pagine' => 2, 'margine_alto' => 40, 'margine_basso' => 20, 'margine_sinistro' => 15, 'margine_destro' => 15, 'predefinita' => false]);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->carta_per_gruppo = ['lettere' => null, 'ricevute_e_avvisi' => $carta->id, 'rendiconto_e_assemblea' => null, 'per_la_persona' => null, 'registri_e_elenchi' => null];
    $impostazioni->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);

    return strlen($contenuto);
}

test('lo .zip di 20 ricevute di 20 persone con una carta intestata di peso vero (una scansione in PDF di 1,6 MB, che ogni PDF porta) non chiede più di 25 MB oltre la partenza (erano circa 90 con lo .zip in memoria): ogni PDF va sul disco appena è fatto', function () {
    $s = scenarioRicevutaIncasso();
    $peso = memoriaCartaDiPesoVero();
    expect($peso)->toBeGreaterThan(1_500_000)->toBeLessThan(1_800_000);
    foreach (range(1, 20) as $n) {
        memoriaPersonaConIncasso($s, $n);
    }
    $incassi = ScritturaContabile::where('condominio_id', $s->condominio->id)->orderBy('id')->get();
    $servizio = app(EmissioneRicevute::class);
    $scelte = $servizio->scelte(['bollo' => 'non_dovuta']);

    $pezzi = fn () => glob(sys_get_temp_dir() . '/km_pezzo_*') ?: [];
    $primaDeiPezzi = $pezzi();
    gc_collect_cycles();
    $partenza = memory_get_usage();
    memory_reset_peak_usage();
    $esito = $servizio->archivioPerPersona($s->condominio, $incassi, $scelte, null);
    $oltre = (memory_get_peak_usage() - $partenza) / 1048576;
    expect($oltre)->toBeLessThan(25.0, 'l\'archivio ha chiesto ' . round($oltre) . ' MB oltre la partenza');
    // I PDF scritti sul disco uno alla volta non restano: resta lo .zip.
    expect(array_values(array_diff($pezzi(), $primaDeiPezzi)))->toBe([]);

    $archivio = new ZipArchive;
    expect($archivio->open($esito['zip']))->toBeTrue();
    $pesi = [];
    for ($i = 0; $i < $archivio->numFiles; $i++) {
        if (str_ends_with((string) $archivio->getNameIndex($i), '.pdf')) {
            $pesi[] = (int) $archivio->statIndex($i)['size'];
        }
    }
    $archivio->close();
    @unlink($esito['zip']);
    // Venti PDF, ognuno con la sua copia della carta.
    expect($pesi)->toHaveCount(20)->and(min($pesi))->toBeGreaterThan(1_500_000);
});

test('se un PDF dello .zip non si fa, i file temporanei già scritti si tolgono, lo .zip non resta sul disco e il registro non ha niente', function () {
    $s = scenarioRicevutaIncasso();
    foreach (range(1, 5) as $n) {
        memoriaPersonaConIncasso($s, $n);
    }
    $incassi = ScritturaContabile::where('condominio_id', $s->condominio->id)->orderBy('id')->get();
    // Il terzo PDF non si fa: il server lo rifiuta a metà.
    app()->instance(\App\Services\PDF\PdfService::class, new class extends \App\Services\PDF\PdfService
    {
        private int $fatti = 0;

        public function __construct() {}

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            if (++$this->fatti === 3) {
                throw new \RuntimeException('il terzo PDF non si fa');
            }

            return parent::generate($view, $data, $config);
        }
    });
    $servizio = app(EmissioneRicevute::class);
    $temporanei = fn () => array_merge(glob(sys_get_temp_dir() . '/km_pezzo_*') ?: [], glob(sys_get_temp_dir() . '/km_archivio_*') ?: []);
    $prima = $temporanei();

    expect(fn () => $servizio->archivioPerPersona($s->condominio, $incassi, $servizio->scelte(['bollo' => 'non_dovuta']), null))
        ->toThrow(\RuntimeException::class, 'il terzo PDF non si fa');
    expect(array_values(array_diff($temporanei(), $prima)))->toBe([])
        ->and(RegistroInvio::count())->toBe(0);
});

test('se qualcosa non riesce dopo che lo .zip è scritto (la transazione del registro), lo .zip non resta sul disco', function () {
    $s = scenarioRicevutaIncasso();
    foreach (range(1, 2) as $n) {
        memoriaPersonaConIncasso($s, $n);
    }
    $incassi = ScritturaContabile::where('condominio_id', $s->condominio->id)->orderBy('id')->get();
    $servizio = app(EmissioneRicevute::class);
    $archivi = fn () => glob(sys_get_temp_dir() . '/km_archivio_*') ?: [];
    $prima = $archivi();
    // La chiusura della transazione non riesce, quando lo .zip c'è già.
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, function () use ($archivi, $prima) {
        if (array_diff($archivi(), $prima) !== []) {
            throw new \RuntimeException('la transazione non si chiude');
        }
    });

    expect(fn () => $servizio->archivioPerPersona($s->condominio, $incassi, $servizio->scelte(['bollo' => 'non_dovuta']), null))
        ->toThrow(\RuntimeException::class, 'la transazione non si chiude');
    expect(array_values(array_diff($archivi(), $prima)))->toBe([]);
});

/**
 * Un processo PHP a parte, con la sua cartella temporanea, che scrive due pezzi di uno .zip e poi `$dopo`. Rende l'uscita e i pezzi rimasti nella
 * cartella (tolti dal test). `$limiteFile` (in blocchi della shell) simula il disco pieno: il file non cresce oltre.
 *
 * @return array{0: string, 1: list<string>}
 */
function memoriaPezziInUnProcesso(string $dopo, ?int $limiteFile = null): array
{
    $dir = sys_get_temp_dir().'/km_prova_pezzi_'.bin2hex(random_bytes(4));
    mkdir($dir);
    $codice = 'require getcwd()."/vendor/autoload.php"; $a = new \App\Services\Gestionale\Invii\ArchivioAPezzi(\RuntimeException::class);'
        .' try { foreach (["Rossi Mario.pdf", "Bianchi Anna.pdf"] as $n) { $a->aggiungi($n, "%PDF dati di ".$n.str_repeat(" ", 65536)); } }'
        .' catch (\RuntimeException $e) { $a->togli(); echo "RIFIUTO: ".$e->getMessage(); }'.$dopo;
    $comando = $limiteFile === null
        ? [PHP_BINARY, '-d', 'sys_temp_dir='.$dir, '-d', 'memory_limit=64M', '-r', $codice]
        : ['/bin/sh', '-c', 'trap "" XFSZ; ulimit -f '.$limiteFile.'; exec "$0" -d sys_temp_dir="$1" -d memory_limit=64M -r "$2"', PHP_BINARY, $dir, $codice];
    $p = new \Symfony\Component\Process\Process($comando, base_path());
    $p->setTimeout(60)->run();
    $rimasti = glob($dir.'/km_pezzo_*') ?: [];
    array_map('unlink', $rimasti);
    rmdir($dir);

    return [$p->getOutput().$p->getErrorOutput(), $rimasti];
}

test('dopo un errore fatale a metà dello .zip, che non passa dai catch (il tempo della richiesta finito, la memoria finita), i file temporanei già scritti, con i dati dei condòmini, si tolgono alla fine della richiesta', function (string $dopo, string $errore) {
    [$uscita, $rimasti] = memoriaPezziInUnProcesso($dopo);

    expect($uscita)->toContain($errore)
        ->and($rimasti)->toBe([]);
})->with([
    'il tempo della richiesta' => [' set_time_limit(1); while (true) { $x = sqrt(2); }', 'Maximum execution time'],
    'la memoria' => [' $z = []; while (true) { $z[] = str_repeat("x", 1048576); }', 'Allowed memory size'],
]);

test('dopo un errore fatale con lo .zip già scritto e non ancora mandato (il tempo della richiesta finito mentre lo .zip si chiude, la memoria), nemmeno lo .zip resta nella cartella temporanea', function (string $dopo, string $errore) {
    $dir = sys_get_temp_dir().'/km_prova_zip_'.bin2hex(random_bytes(4));
    mkdir($dir);
    $codice = 'require getcwd()."/vendor/autoload.php"; $a = new \App\Services\Gestionale\Invii\ArchivioAPezzi(\RuntimeException::class);'
        .' foreach (["Rossi Mario.pdf", "Bianchi Anna.pdf"] as $n) { $a->aggiungi($n, "%PDF dati di ".$n); } $zip = $a->scrivi(); echo "ZIP ".filesize($zip)." ";'.$dopo;
    $p = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'sys_temp_dir='.$dir, '-d', 'memory_limit=64M', '-r', $codice], base_path());
    $p->setTimeout(60)->run();
    $rimasti = glob($dir.'/km_*') ?: [];
    array_map('unlink', $rimasti);
    rmdir($dir);

    expect($p->getOutput().$p->getErrorOutput())->toContain('ZIP ')->toContain($errore)
        ->and(array_map('basename', $rimasti))->toBe([]);
})->with([
    'il tempo della richiesta' => [' set_time_limit(1); while (true) { $x = sqrt(2); }', 'Maximum execution time'],
    'la memoria' => [' $z = []; while (true) { $z[] = str_repeat("x", 1048576); }', 'Allowed memory size'],
]);

test('un pezzo dello .zip che il disco scrive solo a metà (il disco pieno) non passa: la frase di sempre, e i pezzi si tolgono', function () {
    [$uscita, $rimasti] = memoriaPezziInUnProcesso('', 4);

    expect($uscita)->toContain('RIFIUTO: '.\App\Services\Gestionale\Invii\InviiPerPersona::ARCHIVIO_NON_FATTO)
        ->and($rimasti)->toBe([]);
});
