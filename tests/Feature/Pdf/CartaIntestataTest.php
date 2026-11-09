<?php

/**
 * La carta intestata delle carte dello studio: lo studio stampato sulla carta, caricata come PDF di una o due pagine (la prima e quella delle pagine
 * seguenti) o come immagine A4, con i margini dove la carta lascia lo spazio al testo.
 *
 * - Il caricamento del PDF fa la strada della stampa (FPDI apre il file, importa ogni pagina dal TrimBox, la disegna in un mPDF di prova); prima rifiuta
 *   un trailer con /XRefStm e un flusso che si espande oltre 20 MB. Una o due pagine A4 verticali, con 2 mm di tolleranza. Un PDF che non si legge si
 *   rifiuta con la strada: caricarlo come immagine JPG.
 * - L'immagine: solo JPG, A4 verticale (2 % di tolleranza), larga almeno 1.240 pixel, intera; si salva senza i dati della foto e senza riscriverla
 *   (i pixel restano byte per byte), con un nome casuale (mPDF la mette nel PDF senza aprirla: anche a 600 punti per pollice una stampa sta in 128 MB).
 *   Un PNG si rifiuta con la frase che dice di caricarla in JPG; una foto girata (l'orientamento nei dati della foto) con la frase che dice di girarla.
 * - In stampa, sulle stampe verticali: la carta sotto ogni pagina (la prima pagina di ogni documento ha la prima della carta, le altre la seconda); il
 *   programma non scrive lo studio, scrive il condominio in cima alla parte libera (nelle lettere, ricevute e avvisi a sinistra); il piede subito
 *   sopra il margine basso; il destinatario delle lettere con le righe allineate al margine destro della carta. Nel fascicolo la prima pagina
 *   della carta va al solo frontespizio. Le stampe orizzontali hanno la testata a campi della stessa carta.
 * - Una carta che in stampa non si legge: la stampa esce con la stessa carta a campi, con una riga nel registro, e la pagina lo dice.
 */

use App\Enums\Permission as Permesso;
use App\Models\CartaStampa;
use App\Services\PDF\CartaDiStampa;
use App\Settings\PrintSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__.'/../Gestionale/LettereScenario.php';
require_once __DIR__.'/../Gestionale/RicevutaIncassoScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

beforeEach(function () {
    $this->user = be52Utente();
    Role::findByName('admin', 'web')->givePermissionTo(Permission::firstOrCreate(['name' => Permesso::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    Storage::fake('local');
    Storage::fake('public');
});

const CIT_GRUPPI = ['lettere', 'ricevute_e_avvisi', 'rendiconto_e_assemblea', 'per_la_persona', 'registri_e_elenchi'];

/** I margini delle prove, in mm: dove la carta lascia lo spazio al testo. */
const CIT_MARGINI = ['margine_alto' => 45, 'margine_basso' => 25, 'margine_sinistro' => 20, 'margine_destro' => 18];

/** La frase della pagina per una carta intestata che non si legge. */
const CIT_ILLEGGIBILE = 'Il file della carta intestata non si legge (o il disco non risponde): le stampe escono a campi, con lo studio scritto dal programma. Se resta così, caricalo di nuovo.';

/** La stessa frase quando lo studio non ha il nome: a campi la stampa esce senza lo studio. */
const CIT_ILLEGGIBILE_SENZA_NOME = 'Il file della carta intestata non si legge (o il disco non risponde): le stampe escono a campi, senza lo studio: il suo nome, qui sopra, è vuoto. Scrivilo, o carica di nuovo il file.';

/** La frase della carta in immagine che arriva in PNG. */
const CIT_SOLO_JPG = 'Carica la carta come JPG, A4 verticale, larga almeno 1.240 pixel.';

/** La frase di una foto girata (l'orientamento scritto nei dati della foto non è quello normale). */
const CIT_GIRATA = 'La foto è girata: ruotala e salvala di nuovo, poi caricala.';

/** La frase di un file che non è un PDF né un JPG. */
const CIT_NON_JPG = 'La carta intestata va caricata come PDF, o come immagine JPG.';

const CIT_RIFIUTO = 'Questo PDF non si può usare come sfondo: è protetto, o è salvato in un formato che il programma non legge. Caricalo come immagine JPG, A4 verticale, larga almeno 1.240 pixel.';

/** Un flusso compresso con Flate: il testo, e dopo tanti spazi da arrivare a `$mb` MB espansi; scritto a pezzi, senza tenere in memoria l'espanso. */
function citFlussoCompresso(string $testo, int $mb): string
{
    $flusso = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $compresso = deflate_add($flusso, $testo."\n", ZLIB_NO_FLUSH);
    $mega = str_repeat(' ', 1048576);
    for ($i = 0; $i < $mb; $i++) {
        $compresso .= deflate_add($flusso, $mega, ZLIB_NO_FLUSH);
    }

    return $compresso.deflate_add($flusso, '', ZLIB_FINISH);
}

/**
 * Un PDF scritto a mano, come lo scrivono i programmi: una pagina per voce di `$pagine` ([larghezza, altezza] in punti, il TrimBox [x0, y0, x1, y1] o
 * null, il testo in Helvetica in alto a sinistra). `$forma`: «tabella» (i riferimenti in una tabella), «flusso» (i riferimenti compressi in un
 * flusso, PDF 1.5), «ibrido» (la tabella e, indicato dal trailer con /XRefStm, un flusso dei riferimenti), «cifrato»; e per il contenuto della prima
 * pagina «lzw» (dichiarato in LZW), «rotto» (dichiarato in Flate, e non lo è), «senza_fine» (il flusso senza «endstream»). Con `$espansoMb` il contenuto
 * della prima pagina è compresso e si espande a tanti MB.
 */
function citPdf(?array $pagine = null, string $forma = 'tabella', int $espansoMb = 0): string
{
    $pagine ??= [[595.28, 841.89, null, 'CARTA PRIMA PAGINA'], [595.28, 841.89, null, 'CARTA PAGINE SEGUENTI']];
    $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $figli = [];
    foreach (array_values($pagine) as $i => $p) {
        [$larghezza, $altezza, $trim, $testo] = $p + [null, null, null, 'CARTA'];
        $n = 4 + 2 * $i;
        $figli[] = "{$n} 0 R";
        $box = $trim === null ? '' : ' /TrimBox ['.implode(' ', $trim).']';
        $o[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$larghezza} {$altezza}]{$box} /Resources << /Font << /F1 3 0 R >> >> /Contents ".($n + 1).' 0 R >>';
        $contenuto = 'BT /F1 18 Tf 72 '.($altezza - 80)." Td ({$testo}) Tj ET";
        if ($i === 0 && $espansoMb > 0) {
            $compresso = citFlussoCompresso($contenuto, $espansoMb);
            $o[$n + 1] = '<< /Length '.strlen($compresso)." /Filter /FlateDecode >>\nstream\n{$compresso}\nendstream";
        } elseif ($i === 0 && in_array($forma, ['lzw', 'rotto'], true)) {
            $o[$n + 1] = '<< /Length '.strlen($contenuto).' /Filter /'.($forma === 'lzw' ? 'LZWDecode' : 'FlateDecode')." >>\nstream\n{$contenuto}\nendstream";
        } elseif ($i === 0 && $forma === 'senza_fine') {
            $o[$n + 1] = '<< /Length '.strlen($contenuto).">>\nstream\n{$contenuto}\n";
        } else {
            $o[$n + 1] = '<< /Length '.strlen($contenuto).">>\nstream\n{$contenuto}\nendstream";
        }
    }
    $o[2] = '<< /Type /Pages /Kids ['.implode(' ', $figli).'] /Count '.count($figli).' >>';
    ksort($o);
    $pdf = '%PDF-1.'.(in_array($forma, ['flusso', 'ibrido', 'cifrato'], true) ? 5 : 4)."\n%\xE2\xE3\xCF\xD3\n";
    $posti = [];
    foreach ($o as $n => $c) {
        $posti[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$c}\nendobj\n";
    }
    $dimensione = max(array_keys($o)) + 1;
    $righe = function (int $fino) use (&$posti): string {
        return pack('CNn', 0, 0, 65535).implode('', array_map(fn (int $k) => pack('CNn', 1, $posti[$k], 0), range(1, $fino - 1)));
    };
    if ($forma === 'flusso') {
        $x = strlen($pdf);
        $posti[$dimensione] = $x;
        $z = gzcompress($righe($dimensione + 1));

        return $pdf."{$dimensione} 0 obj\n<< /Type /XRef /Size ".($dimensione + 1)." /W [1 4 2] /Root 1 0 R /Filter /FlateDecode /Length ".strlen($z)." >>\nstream\n{$z}\nendstream\nendobj\nstartxref\n{$x}\n%%EOF\n";
    }
    $altro = '';
    if ($forma === 'ibrido') {
        $xs = strlen($pdf);
        $tabella = $righe($dimensione);
        $pdf .= "{$dimensione} 0 obj\n<< /Type /XRef /Size {$dimensione} /W [1 4 2] /Length ".strlen($tabella)." >>\nstream\n{$tabella}\nendstream\nendobj\n";
        $posti[$dimensione] = $xs;
        $dimensione++;
        $altro = " /XRefStm {$xs}";
    }
    if ($forma === 'cifrato') {
        $altro = ' /Encrypt << /Filter /Standard /V 1 /R 2 /O <00> /U <00> /P -4 >>';
    }
    $x = strlen($pdf);
    $pdf .= "xref\n0 {$dimensione}\n0000000000 65535 f \n";
    for ($k = 1; $k < $dimensione; $k++) {
        $pdf .= sprintf("%010d 00000 n \n", $posti[$k]);
    }

    return $pdf."trailer\n<< /Size {$dimensione} /Root 1 0 R{$altro} >>\nstartxref\n{$x}\n%%EOF\n";
}

/** Un'immagine larga e alta quanto si chiede: «jpg», «png», o «png_trasparente» (un PNG con l'alfa, il fondo trasparente). */
function citImmagine(int $larghezza, int $altezza, string $tipo = 'jpg'): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    if ($tipo === 'png_trasparente') {
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    }
    imagefilledrectangle($im, 0, 0, $larghezza - 1, (int) ($altezza / 12), imagecolorallocatealpha($im, 122, 31, 31, 0));
    ob_start();
    $tipo === 'jpg' ? imagejpeg($im, null, 85) : imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/** Un JPG con l'orientamento scritto nei dati della foto (il blocco Exif, come lo scrive il telefono). */
function citJpgOrientato(string $jpg, int $orientamento): string
{
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientamento)."\x00\x00"."\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00".$tiff;

    return substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
}

/** Un JPG A4 di 1.240 × 1.754 tutto di un colore: una carta intestata stampata su un fondo colorato. */
function citJpgColorato(int $rosso, int $verde, int $blu): string
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefilledrectangle($im, 0, 0, 1239, 1753, imagecolorallocate($im, $rosso, $verde, $blu));
    ob_start();
    imagejpeg($im, null, 90);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/** Una firma in PNG con lo sfondo trasparente: un tratto scuro al centro, il resto trasparente. */
function citFirmaTrasparente(int $larghezza = 400, int $altezza = 150): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefilledrectangle($im, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, (int) ($larghezza / 4), (int) ($altezza / 3), (int) ($larghezza * 3 / 4), (int) ($altezza * 2 / 3), imagecolorallocatealpha($im, 20, 20, 60, 0));
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/**
 * Le immagini del PDF larghe e alte quanto si chiede, con la loro maschera della trasparenza (`/SMask`) espansa: per ognuna [ha la maschera, i byte
 * della maschera]. Una riga della maschera è di `$larghezza` byte: 0 trasparente, 255 pieno.
 *
 * @return list<array{0: bool, 1: ?string}>
 */
function citImmaginiConMaschera(string $pdf, int $larghezza, int $altezza): array
{
    $oggetti = cdsOggetti($pdf);
    $trovate = [];
    foreach ($oggetti as $corpo) {
        if (! preg_match('#/Subtype\s*/Image\b#', $corpo) || ! preg_match('#/Width\s+'.$larghezza.'\b#', $corpo) || ! preg_match('#/Height\s+'.$altezza.'\b#', $corpo)
            || preg_match('#/ColorSpace\s*/DeviceGray#', $corpo)) {
            continue;
        }
        if (! preg_match('#/SMask\s+(\d+)\s+0\s+R#', $corpo, $m)) {
            $trovate[] = [false, null];

            continue;
        }
        [$dizionario, $dati] = cdsFlussoDi($oggetti[(int) $m[1]] ?? '');
        $trovate[] = [true, preg_match('#/Predictor\s+1[0-5]\b#', $dizionario) ? citSenzaPredittore($dati, $larghezza) : $dati];
    }

    return $trovate;
}

/** I byte di un'immagine di un byte per pixel scritta con i predittori del PNG (un byte del filtro in testa a ogni riga), tolti i predittori. */
function citSenzaPredittore(string $dati, int $larghezza): string
{
    $righe = '';
    $sopra = str_repeat("\0", $larghezza);
    for ($p = 0; $p + $larghezza < strlen($dati) + 1; $p += $larghezza + 1) {
        $filtro = ord($dati[$p]);
        $riga = '';
        for ($x = 0; $x < $larghezza; $x++) {
            $a = $x > 0 ? ord($riga[$x - 1]) : 0;
            $b = ord($sopra[$x]);
            $c = $x > 0 ? ord($sopra[$x - 1]) : 0;
            $paeth = function () use ($a, $b, $c) {
                $q = $a + $b - $c;
                [$pa, $pb, $pc] = [abs($q - $a), abs($q - $b), abs($q - $c)];

                return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
            };
            $piu = match ($filtro) { 0 => 0, 1 => $a, 2 => $b, 3 => intdiv($a + $b, 2), 4 => $paeth(), default => 0 };
            $riga .= chr((ord($dati[$p + 1 + $x]) + $piu) & 0xFF);
        }
        $righe .= $riga;
        $sopra = $riga;
    }

    return $righe;
}

/** L'intestazione di un PNG largo e alto quanto si chiede, senza i pixel: la misura si legge, l'immagine non si apre. */
function citPngSoloIntestazione(int $larghezza, int $altezza): string
{
    $pezzo = fn (string $tipo, string $dati) => pack('N', strlen($dati)).$tipo.$dati.pack('N', crc32($tipo.$dati));

    return "\x89PNG\r\n\x1a\n".$pezzo('IHDR', pack('NNCCCCC', $larghezza, $altezza, 8, 2, 0, 0, 0)).$pezzo('IDAT', gzcompress("\x00\x00\x00\x00")).$pezzo('IEND', '');
}

function citFile(string $contenuto, string $nome, string $tipo): UploadedFile
{
    $percorso = tempnam(sys_get_temp_dir(), 'cit');
    file_put_contents($percorso, $contenuto);

    return new UploadedFile($percorso, $nome, $tipo, null, true);
}

/** «Salva la carta» di una carta nuova con la carta intestata: il nome, il file, i margini. */
function citCarica($test, ?UploadedFile $file, array $altro = [])
{
    return $test->actingAs($test->user)->post(route('impostazioni.stampe.carte.store'), array_merge(
        ['nome' => 'Carta della tipografia', 'mostra_logo' => true, 'colore' => null, 'predefinita' => false, 'aspetto' => 'carta'],
        CIT_MARGINI,
        $file === null ? [] : ['carta' => $file],
        $altro,
    ));
}

/** I file della cartella delle carte intestate, sul disco dei file pubblici. */
function citFileSalvati(): array
{
    return Storage::disk('public')->allFiles('settings/carte');
}

/** Una carta con la carta intestata già salvata (un PDF di due pagine o un'immagine A4 sul disco dei file pubblici), scelta per tutti e cinque i gruppi. */
function citCartaIntestata(string $tipo = 'pdf', array $campi = [], ?string $contenuto = null): CartaStampa
{
    $estensione = $tipo === 'pdf' ? 'pdf' : 'png';
    $percorso = "settings/carte/carta-delle-prove.{$estensione}";
    Storage::disk('public')->put($percorso, $contenuto ?? ($tipo === 'pdf' ? citPdf() : citImmagine(1240, 1754, 'png')));
    $carta = CartaStampa::create(array_merge(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => $percorso, 'carta_tipo' => $tipo === 'pdf' ? 'pdf' : 'immagine', 'carta_pagine' => $tipo === 'pdf' ? 2 : 1, 'predefinita' => false],
        CIT_MARGINI, $campi));
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(CIT_GRUPPI, $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);

    return $carta;
}

/** Via Roma al 15/01/2027, con il condominio che ha nome, indirizzo e codice fiscale noti. */
function citScenario($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-15');
    $s['c']->update(['nome' => 'Condominio Via Roma 23', 'indirizzo' => 'Via Roma 23, 20100 Milano', 'codice_fiscale' => '90012345678']);
    $s['c'] = $s['c']->fresh();

    return $s;
}

function citPdfDallaRotta($test, string $indirizzo): string
{
    $risposta = $test->actingAs($test->user)->get($indirizzo);
    $risposta->assertOk();

    return (string) ($risposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $risposta->streamedContent() : $risposta->getContent());
}

/** Il verso di ogni pagina del PDF, nell'ordine: «verticale» o «orizzontale». */
function citVersi(string $pdf): array
{
    $versi = [];
    foreach (cdsOggetti($pdf) as $corpo) {
        if (preg_match('#^<<\s*/Type /Page\b(?!s)#', $corpo) && preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $corpo, $m)) {
            $versi[] = (float) $m[1] < (float) $m[2] ? 'verticale' : 'orizzontale';
        }
    }

    return $versi;
}

/** Il titolo del documento PDF (`/Title` del dizionario delle informazioni), letto com'è scritto. */
function citTitolo(string $pdf): ?string
{
    return preg_match('#/Title\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f]*>)#', $pdf, $m) === 1 ? $m[1] : null;
}

/** Un flusso di un PDF scritto a mano: il dizionario (senza /Length), i dati. */
function citFlusso(string $dizionario, string $dati): string
{
    return '<< '.$dizionario.' /Length '.strlen($dati)." >>\nstream\n{$dati}\nendstream";
}

/**
 * Gli oggetti di una carta di una pagina A4 con un testo in Helvetica: 1 il catalogo (con `$piuCatalogo`), 2 le pagine, 3 il carattere, 4 la pagina
 * (con `$piuPagina`), 5 il suo contenuto.
 */
function citOggetti(string $testo = 'CARTA', string $piuPagina = '', string $piuCatalogo = ''): array
{
    return [
        1 => '<< /Type /Catalog /Pages 2 0 R'.$piuCatalogo.' >>',
        2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R'.$piuPagina.' >>',
        5 => citFlusso('', "BT /F1 18 Tf 72 760 Td ({$testo}) Tj ET"),
    ];
}

/** Un PDF scritto a mano dagli oggetti dati (il numero e il corpo, senza «obj» ed «endobj»), con la tabella dei riferimenti; il trailer con /Root 1 0 R. */
function citScritto(array $oggetti): string
{
    ksort($oggetti);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $posti = [];
    foreach ($oggetti as $n => $corpo) {
        $posti[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$corpo}\nendobj\n";
    }
    $dimensione = max(array_keys($oggetti)) + 1;
    $x = strlen($pdf);
    $pdf .= "xref\n0 {$dimensione}\n0000000000 65535 f \n";
    for ($k = 1; $k < $dimensione; $k++) {
        $pdf .= isset($posti[$k]) ? sprintf("%010d 00000 n \n", $posti[$k]) : "0000000000 65535 f \n";
    }

    return $pdf."trailer\n<< /Size {$dimensione} /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF\n";
}

/**
 * Un file «ibrido» come quelli che scrive Word: la tabella dei riferimenti e, detto dal trailer con /XRefStm, un flusso dei riferimenti con gli
 * oggetti in più, che stanno in un flusso di oggetti (qui la struttura per l'accessibilità). Con `$carattereNelFlusso` anche il carattere della
 * pagina sta solo lì: la tabella lo dà per libero.
 */
function citIbrido(bool $carattereNelFlusso, string $testo = 'CARTA DI WORD'): string
{
    $o = citOggetti($testo, ' /StructParents 0', ' /StructTreeRoot 7 0 R /MarkInfo << /Marked true >>');
    $nelFlusso = [7 => '<< /Type /StructTreeRoot >>'];
    if ($carattereNelFlusso) {
        $nelFlusso[3] = $o[3];
        unset($o[3]);
    }
    $testa = '';
    $corpo = '';
    foreach ($nelFlusso as $n => $c) {
        $testa .= $n.' '.strlen($corpo).' ';
        $corpo .= $c."\n";
    }
    $o[8] = citFlusso('/Type /ObjStm /N '.count($nelFlusso).' /First '.strlen($testa), $testa.$corpo);
    ksort($o);
    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $posti = [];
    foreach ($o as $n => $c) {
        $posti[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$c}\nendobj\n";
    }
    // Il flusso dei riferimenti dice tutti gli oggetti: quelli del flusso di oggetti con il tipo 2.
    $xs = strlen($pdf);
    $indice = array_keys($nelFlusso);
    $righe = pack('CNn', 0, 0, 65535);
    for ($k = 1; $k <= 9; $k++) {
        $righe .= match (true) {
            isset($nelFlusso[$k]) => pack('CNn', 2, 8, array_search($k, $indice, true)),
            $k === 9 => pack('CNn', 1, $xs, 0),
            isset($posti[$k]) => pack('CNn', 1, $posti[$k], 0),
            default => pack('CNn', 0, 0, 0),
        };
    }
    $pdf .= "9 0 obj\n".citFlusso('/Type /XRef /Size 10 /W [1 4 2]', $righe)."\nendobj\n";
    // La tabella: gli oggetti del flusso di oggetti sono liberi, come in un file ibrido.
    $x = strlen($pdf);
    $pdf .= "xref\n0 10\n0000000000 65535 f \n";
    for ($k = 1; $k <= 9; $k++) {
        $pdf .= match (true) {
            $k === 9 => sprintf("%010d 00000 n \n", $xs),
            isset($posti[$k]) => sprintf("%010d 00000 n \n", $posti[$k]),
            default => "0000000000 00000 f \n",
        };
    }

    return $pdf."trailer\n<< /Size 10 /Root 1 0 R /XRefStm {$xs} >>\nstartxref\n{$x}\n%%EOF\n";
}

/** Una pagina con più flussi del contenuto (FPDI li espande e li unisce): il primo è quello di `citOggetti()`, gli altri si danno qui, dal 6. */
function citConPiuFlussi(array $altri, string $testo = 'CARTA'): array
{
    $o = citOggetti($testo);
    $numeri = range(6, 5 + count($altri));
    $o[4] = str_replace('/Contents 5 0 R', '/Contents [5 0 R '.implode(' ', array_map(fn (int $n) => "{$n} 0 R", $numeri)).']', $o[4]);

    return $o + array_combine($numeri, array_values($altri));
}

/** Una carta che disegna un modulo (`/Fm1`), l'oggetto 6 scritto com'è. */
function citPdfConModulo(string $modulo): string
{
    $o = citOggetti();
    $o[4] = str_replace('/Resources << /Font << /F1 3 0 R >> >>', '/Resources << /Font << /F1 3 0 R >> /XObject << /Fm1 6 0 R >> >>', $o[4]);
    $o[5] = citFlusso('', 'q /Fm1 Do Q BT /F1 18 Tf 72 760 Td (CARTA) Tj ET');
    $o[6] = $modulo;

    return citScritto($o);
}

/** I dati in ASCII85, come li scrivono i programmi che vengono da PostScript. */
function citAscii85(string $dati): string
{
    $uscita = '';
    for ($i = 0, $n = strlen($dati); $i < $n; $i += 4) {
        $pezzo = substr($dati, $i, 4);
        $quanti = strlen($pezzo);
        $valore = unpack('N', str_pad($pezzo, 4, "\0"))[1];
        if ($valore === 0 && $quanti === 4) {
            $uscita .= 'z';

            continue;
        }
        $cifre = '';
        for ($j = 0; $j < 5; $j++) {
            $cifre = chr($valore % 85 + 33).$cifre;
            $valore = intdiv($valore, 85);
        }
        $uscita .= substr($cifre, 0, $quanti + 1);
    }

    return $uscita.'~>';
}

/** Una carta con un'immagine A4 a 300 punti per pollice senza perdita (Flate, come ImageMagick): 2.480 × 3.508 RGB, 26 MB espansa. */
function citPdfConImmagine(): string
{
    $flusso = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);
    $riga = str_repeat("\xFF\xFF\xFF", 2480);
    $compressa = '';
    for ($i = 0; $i < 3508; $i++) {
        $compressa .= deflate_add($flusso, $riga, ZLIB_NO_FLUSH);
    }
    $compressa .= deflate_add($flusso, '', ZLIB_FINISH);
    $o = citOggetti('CARTA CON LA FOTO');
    $o[4] = str_replace('/Resources << /Font << /F1 3 0 R >> >>', '/Resources << /Font << /F1 3 0 R >> /XObject << /Im1 6 0 R >> >>', $o[4]);
    $o[5] = citFlusso('', 'q 595.28 0 0 841.89 0 0 cm /Im1 Do Q BT /F1 18 Tf 72 760 Td (CARTA CON LA FOTO) Tj ET');
    $o[6] = citFlusso('/Type /XObject /Subtype /Image /Width 2480 /Height 3508 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode', $compressa);

    return citScritto($o);
}

/** Una carta con il JavaScript all'apertura, un allegato, un'azione della pagina e un collegamento che lancia un programma. */
function citPdfConJavaScript(): string
{
    $o = citOggetti('CARTA CON GLI SCRIPT', ' /AA << /O 8 0 R >> /Annots [9 0 R]',
        ' /OpenAction 6 0 R /Names << /JavaScript << /Names [(a) 6 0 R] >> /EmbeddedFiles << /Names [(x.exe) 7 0 R] >> >>');
    $o[6] = '<< /S /JavaScript /JS (app.alert\(1\)) >>';
    $o[7] = '<< /Type /Filespec /F (x.exe) /EF << /F 10 0 R >> >>';
    $o[8] = '<< /S /JavaScript /JS (app.alert\(2\)) >>';
    $o[9] = '<< /Type /Annot /Subtype /Link /Rect [0 0 100 100] /A << /S /Launch /F (calc.exe) >> >>';
    $o[10] = citFlusso('/Type /EmbeddedFile', 'MZ finto eseguibile');

    return citScritto($o);
}

/** Una carta con due collegamenti: uno invisibile (senza bordo) su tutta la pagina, verso un indirizzo, e uno `javascript:` in un angolo. */
function citPdfConCollegamenti(): string
{
    $o = citOggetti('CARTA CON I COLLEGAMENTI', ' /Annots [6 0 R 7 0 R]');
    $o[6] = '<< /Type /Annot /Subtype /Link /Rect [0 0 595.28 841.89] /Border [0 0 0] /A << /S /URI /URI (http://127.0.0.1:9/tutta-la-pagina) >> >>';
    $o[7] = '<< /Type /Annot /Subtype /Link /Rect [0 0 50 50] /Border [0 0 0] /A << /S /URI /URI (javascript:app.alert\(3\)) >> >>';

    return citScritto($o);
}

/** I collegamenti di un PDF: gli indirizzi delle annotazioni `/URI`, com'è scritto. */
function citCollegamenti(string $pdf): array
{
    preg_match_all('#/URI\s*\(((?:\\\\.|[^\\\\)])*)\)#', $pdf, $m);

    return $m[1];
}

/** La carta caricata dalla pagina, scelta per tutti e cinque i gruppi. */
function citScegliPerTutti(CartaStampa $carta): void
{
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(CIT_GRUPPI, $carta->id);
    $s->save();
    app()->forgetInstance(CartaDiStampa::class);
}

// —— Il caricamento

it('una carta intestata in PDF di due pagine si carica: si salva rifatta dal programma, con le sue due pagine e i margini, e la pagina chiede di provarla', function () {
    $pdf = citPdf();
    citCarica($this, citFile($pdf, 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success' && str_contains($m['message'], 'Prova la carta'));

    $carta = CartaStampa::where('nome', 'Carta della tipografia')->sole();
    $salvato = (string) Storage::disk('public')->get($carta->carta_path);
    expect($carta->only(['aspetto', 'carta_tipo', 'carta_pagine', 'margine_alto', 'margine_basso', 'margine_sinistro', 'margine_destro']))
        ->toBe(['aspetto' => 'carta', 'carta_tipo' => 'pdf', 'carta_pagine' => 2, 'margine_alto' => 45.0, 'margine_basso' => 25.0, 'margine_sinistro' => 20.0, 'margine_destro' => 18.0])
        ->and($carta->carta_path)->toMatch('#^settings/carte/[A-Za-z0-9]{40}\.pdf$#')
        ->and($salvato)->toStartWith('%PDF-')->not->toBe($pdf)
        ->and(cdsNumeroPagine($salvato))->toBe(2)
        ->and(cdsTesto($salvato, 1))->toContain('CARTA PRIMA PAGINA')
        ->and(cdsTesto($salvato, 2))->toContain('CARTA PAGINE SEGUENTI');
});

it('un PDF che non si può usare come sfondo si rifiuta con la sua frase, e non si salva niente', function (string $contenuto, string $frase) {
    citCarica($this, citFile($contenuto, 'carta.pdf', 'application/pdf'))->assertSessionHasErrors(['carta' => $frase]);

    expect(CartaStampa::where('nome', 'Carta della tipografia')->exists())->toBeFalse()
        ->and(citFileSalvati())->toBe([]);
})->with(fn () => [
    'i riferimenti compressi' => [citPdf(null, 'flusso'), CIT_RIFIUTO],
    'cifrato' => [citPdf(null, 'cifrato'), CIT_RIFIUTO],
    'con /XRefStm, e il carattere della pagina solo nel flusso degli oggetti (FPDI non lo trova)' => [citIbrido(true), CIT_RIFIUTO],
    'un flusso che si espande oltre 20 MB' => [citPdf(null, 'tabella', 21), CIT_RIFIUTO],
    'un modulo della pagina con «(obj)» nel dizionario, che si espande oltre 20 MB' => [citPdfConModulo('<< /Type /XObject /Subtype /Form /BBox [0 0 595.28 841.89] /Filter /FlateDecode /Nota (obj) /Length '
        .strlen($z = citFlussoCompresso('BT /F1 18 Tf 72 700 Td (MODULO) Tj ET', 30))." >>\nstream\n{$z}\nendstream"), CIT_RIFIUTO],
    'un flusso del contenuto solo, che si espande oltre 20 MB (lo espande chi apre la stampa)' => [citScritto([5 => '<< /Filter /FlateDecode /Nota (1 0 obj) /Length '
        .strlen($z = citFlussoCompresso('BT /F1 18 Tf 72 760 Td (CARTA) Tj ET', 30))." >>\nstream\n{$z}\nendstream"] + citOggetti()), CIT_RIFIUTO],
    'tre flussi del contenuto da 19 MB l\'uno (FPDI li espande e li unisce)' => [citScritto(citConPiuFlussi(array_fill(0, 3, citFlusso('/Filter /FlateDecode', citFlussoCompresso('q', 19))))), CIT_RIFIUTO],
    'un secondo flusso del contenuto con un predittore (FPDI lo perde: la carta uscirebbe vuota)' => [citScritto(citConPiuFlussi([citFlusso('/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 >>', gzcompress(str_repeat("\x00AAAA", 10)))])), CIT_RIFIUTO],
    'compresso due volte con Flate (il secondo strato non si misura)' => [citScritto([5 => citFlusso('/Filter [/FlateDecode /FlateDecode]', gzcompress(citFlussoCompresso('BT /F1 18 Tf 72 760 Td (CARTA) Tj ET', 30)))] + citOggetti()), CIT_RIFIUTO],
    'in ASCII85 e Flate, che si espande oltre 20 MB' => [citScritto([5 => citFlusso('/Filter [/ASCII85Decode /FlateDecode]', citAscii85(citFlussoCompresso('BT /F1 18 Tf 72 760 Td (CARTA) Tj ET', 30)))] + citOggetti()), CIT_RIFIUTO],
    'un A4 largo 214 mm (oltre i 2 mm di tolleranza)' => [citPdf([[606.61, 841.89, null, 'LARGA']]), 'La carta intestata va in A4 verticale (210 × 297 mm): la pagina 1 di questo PDF è di 214 × 297 mm.'],
    'A3' => [citPdf([[841.89, 1190.55, null, 'A3']]), 'La carta intestata va in A4 verticale (210 × 297 mm): la pagina 1 di questo PDF è di 297 × 420 mm.'],
    'A4 orizzontale' => [citPdf([[841.89, 595.28, null, 'A4']]), 'La carta intestata va in A4 verticale (210 × 297 mm): la pagina 1 di questo PDF è di 297 × 210 mm.'],
    'la seconda pagina A5' => [citPdf([[595.28, 841.89, null, 'UNO'], [419.53, 595.28, null, 'DUE']]), 'La carta intestata va in A4 verticale (210 × 297 mm): la pagina 2 di questo PDF è di 148 × 210 mm.'],
    'tre pagine' => [citPdf([[595.28, 841.89, null, 'UNO'], [595.28, 841.89, null, 'DUE'], [595.28, 841.89, null, 'TRE']]),
        'La carta intestata in PDF ha una pagina o due (la prima e quella delle pagine seguenti): questo PDF ne ha 3.'],
    'un file di testo chiamato .pdf' => ['non sono un PDF', CIT_NON_JPG],
    'senza pagine' => [citPdf([]), 'La carta intestata in PDF ha una pagina o due (la prima e quella delle pagine seguenti): questo PDF ne ha 0.'],
    'un flusso in LZW (non si misura quanto diventa)' => [citPdf(null, 'lzw'), CIT_RIFIUTO],
    'un flusso compresso rotto' => [citPdf(null, 'rotto'), CIT_RIFIUTO],
    'l\'ultimo flusso senza la fine' => [citPdf([[595.28, 841.89, null, 'UNA']], 'senza_fine'), CIT_RIFIUTO],
]);

it('si caricano: un PDF di Word (con /XRefStm, e ogni oggetto che la pagina usa nella tabella), un\'immagine senza perdita di 26 MB espansa, i flussi in ASCII85 di PostScript, una seconda pagina bianca', function (string $contenuto, int $pagine) {
    citCarica($this, citFile($contenuto, 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors();

    expect(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_pagine)->toBe($pagine);
})->with(fn () => [
    'un PDF di Word: /XRefStm, e nel flusso solo la struttura per l\'accessibilità' => [citIbrido(false), 1],
    'con /XRefStm, e tutti gli oggetti anche nella tabella' => [citPdf(null, 'ibrido'), 2],
    'un\'immagine A4 a 300 punti per pollice senza perdita (FPDI non espande le immagini)' => [citPdfConImmagine(), 1],
    'il contenuto in ASCII85 e Flate (da PostScript)' => [citScritto([5 => citFlusso('/Filter [/ASCII85Decode /FlateDecode]', citAscii85(gzcompress('BT /F1 18 Tf 72 760 Td (CARTA) Tj ET')))] + citOggetti()), 1],
    'il contenuto in esadecimale e Flate' => [citScritto([5 => citFlusso('/Filter [/ASCIIHexDecode /FlateDecode]', bin2hex(gzcompress('BT /F1 18 Tf 72 760 Td (CARTA) Tj ET')).'>')] + citOggetti()), 1],
    'la seconda pagina bianca: la carta solo sulla prima' => [citScritto([2 => '<< /Type /Pages /Kids [4 0 R 6 0 R] /Count 2 >>',
        6 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << >> /Contents 7 0 R >>', 7 => citFlusso('', '')] + citOggetti()), 2],
]);

it('un PDF di Word con /XRefStm, caricato dalla pagina: in stampa la carta ha il suo testo', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCarica($this, citFile(citIbrido(false), 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors();
    citScegliPerTutti(CartaStampa::where('nome', 'Carta della tipografia')->sole());

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('CARTA DI WORD')->toContain('Condominio Via Roma 23')->not->toContain('Studio Bianchi');
});

it('il PDF della carta si salva rifatto dal programma, con le sole pagine: il JavaScript, gli allegati, le azioni e i collegamenti non restano', function () {
    citCarica($this, citFile(citPdfConJavaScript(), 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors();
    $salvato = (string) Storage::disk('public')->get(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_path);

    expect(array_values(array_filter(['/JavaScript', '/EmbeddedFile', '/Launch', '/AA', 'app.alert', 'calc.exe', 'x.exe', 'MZ finto'], fn (string $p) => str_contains($salvato, $p))))->toBe([])
        ->and(cdsNumeroPagine($salvato))->toBe(1)
        ->and(cdsTesto($salvato, 1))->toContain('CARTA CON GLI SCRIPT');
});

it('i collegamenti della carta in PDF (uno invisibile su tutta la pagina, uno javascript:) non restano nel file salvato e non arrivano nelle stampe; i collegamenti della stampa restano', function () {
    citCarica($this, citFile(citPdfConCollegamenti(), 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors();
    $salvato = (string) Storage::disk('public')->get(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_path);
    expect(citCollegamenti($salvato))->toBe([])
        ->and($salvato)->not->toContain('tutta-la-pagina')->not->toContain('javascript:')
        ->and(cdsTesto($salvato, 1))->toContain('CARTA CON I COLLEGAMENTI');

    // In stampa, con il file com'è arrivato (sul disco senza passare dal caricamento): la carta c'è, i suoi collegamenti no.
    $s = citScenario($this);
    citCartaIntestata('pdf', ['carta_pagine' => 1], citPdfConCollegamenti());
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('CARTA CON I COLLEGAMENTI')->toContain('Condominio Via Roma 23')
        ->and(citCollegamenti($pdf))->toBe([])
        ->and($pdf)->not->toContain('tutta-la-pagina')->not->toContain('javascript:');

    // Una pagina della stampa con un suo collegamento lo tiene, sopra la stessa carta.
    $carta = new \App\Services\PDF\CartaIntestata(Storage::disk('public')->path('settings/carte/carta-delle-prove.pdf'), 'pdf', 1, 45, 25, 20, 18);
    $stampa = new \App\Services\PDF\MpdfConCarta(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => CartaDiStampa::cartellaTemporanea()], $carta, true,
        fn () => throw new \LogicException('la stampa a campi'));
    $stampa->WriteHTML('<p>Il testo della stampa, con <a href="https://www.esempio.it/della-stampa">un collegamento suo</a>.</p>');
    $conCarta = $stampa->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    expect(citCollegamenti($conCarta))->toBe(['https://www.esempio.it/della-stampa'])
        ->and(cdsTesto($conCarta, 1))->toContain('CARTA CON I COLLEGAMENTI')->toContain('Il testo della stampa');
});

it('una carta della tipografia con l\'abbondanza (una pagina più grande con il TrimBox A4) si carica, e in stampa conta il TrimBox', function () {
    // 216 × 303 mm, con il TrimBox A4 a 3 mm dal bordo: il testo della carta, a 72 punti dal bordo della pagina, sta a 22,4 mm da quello del TrimBox.
    $abbondanza = citPdf([[612.28, 858.9, [8.5, 8.5, 603.78, 850.39], 'CARTA PRIMA PAGINA']]);
    citCarica($this, citFile($abbondanza, 'carta.pdf', 'application/pdf'))->assertSessionHasNoErrors();
    $carta = CartaStampa::where('nome', 'Carta della tipografia')->sole();
    expect($carta->carta_pagine)->toBe(1);

    $s = citScenario($this);
    citCartaIntestata('pdf', [], $abbondanza);
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    // La pagina della carta è un modulo ritagliato sul TrimBox, spostato in modo che l'angolo del TrimBox stia nell'angolo del foglio.
    expect(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')
        ->and($pdf)->toMatch('#/BBox \[8\.5 8\.5 603\.78 850\.39 ?\]#')->toMatch('#/Matrix \[1 0 0 1 -8\.5 -8\.5 ?\]#');
});

it('un PDF con due flussi del contenuto, uno che si espande a 300 MB (FPDI li espanderebbe e li unirebbe), si rifiuta prima di aprirlo, anche con 128 MB di memoria: la frase, mai un errore fatale', function () {
    $file = tempnam(sys_get_temp_dir(), 'cit').'.pdf';
    file_put_contents($file, citScritto(citConPiuFlussi([citFlusso('/Filter /FlateDecode', citFlussoCompresso('q', 300))])));
    $codice = 'require getcwd()."/vendor/autoload.php"; echo \App\Services\PDF\CartaIntestata::rifiutoDelPdf($argv[1], sys_get_temp_dir()) ?? "passa";';
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $codice, $file], base_path());
    $processo->setTimeout(120)->run();
    @unlink($file);

    expect(trim($processo->getOutput().$processo->getErrorOutput()))->toBe(CIT_RIFIUTO)
        ->and($processo->getExitCode())->toBe(0);
});

it('un PDF di 10 MB fatto di «1 0 obj» ripetuto (più di un milione di oggetti) e un flusso in fondo si rifiuta con la frase anche con 128 MB di memoria, mai con un errore fatale', function () {
    $file = tempnam(sys_get_temp_dir(), 'cit').'.pdf';
    file_put_contents($file, "%PDF-1.4\n".str_repeat("1 0 obj\n", 10 * 1024 * 1024 / 8)."2 0 obj\n".citFlusso('', 'q Q')."\nendobj\n");
    $codice = 'require getcwd()."/vendor/autoload.php"; echo \App\Services\PDF\CartaIntestata::rifiutoDelPdf($argv[1], sys_get_temp_dir()) ?? "passa";';
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $codice, $file], base_path());
    $processo->setTimeout(120)->run();
    @unlink($file);

    expect(trim($processo->getOutput().$processo->getErrorOutput()))->toBe(CIT_RIFIUTO)
        ->and($processo->getExitCode())->toBe(0);
});

it('un\'immagine A4 in JPG si carica senza riscriverla, con un nome casuale: i byte dalla prima tabella alla fine sono quelli arrivati, il commento di chi l\'ha scritta e l\'EXIF non restano, anche a 600 punti per pollice', function (string $contenuto, array $misura) {
    citCarica($this, citFile($contenuto, 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    $carta = CartaStampa::where('nome', 'Carta della tipografia')->sole();
    $salvata = Storage::disk('public')->get($carta->carta_path);
    $dallaPrimaTabella = fn (string $jpg) => substr($jpg, strpos($jpg, "\xFF\xDB"));

    // GD scrive un commento («CREATOR: gd-jpeg…») prima delle tabelle: è un dato del file, e si toglie.
    expect($contenuto)->toContain('CREATOR')
        ->and($carta->only(['aspetto', 'carta_tipo', 'carta_pagine']))->toBe(['aspetto' => 'carta', 'carta_tipo' => 'immagine', 'carta_pagine' => 1])
        ->and($carta->carta_path)->toMatch('#^settings/carte/[A-Za-z0-9]{40}\.jpg$#')
        ->and($salvata)->not->toContain('CREATOR')->not->toContain("Exif\x00\x00")->toStartWith("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00")
        ->and($dallaPrimaTabella($salvata) === $dallaPrimaTabella($contenuto))->toBeTrue()
        ->and(array_slice(getimagesizefromstring($salvata), 0, 2))->toBe($misura);
})->with(fn () => [
    'a 150 punti per pollice' => [citImmagine(1240, 1754), [1240, 1754]],
    'a 300 punti per pollice' => [citImmagine(2480, 3508), [2480, 3508]],
    'a 600 punti per pollice' => [citImmagine(4960, 7016), [4960, 7016]],
    'con l\'orientamento normale scritto nei dati della foto' => [citJpgOrientato(citImmagine(1240, 1754), 1), [1240, 1754]],
]);

it('un\'immagine che non è un A4 verticale in JPG, largo almeno 1.240 pixel e intero, si rifiuta con la sua frase; il PNG, l\'SVG e il GIF non passano', function (string $contenuto, string $nome, string $tipo, string $frase) {
    citCarica($this, citFile($contenuto, $nome, $tipo))->assertSessionHasErrors(['carta' => $frase]);
    expect(CartaStampa::where('nome', 'Carta della tipografia')->exists())->toBeFalse()->and(citFileSalvati())->toBe([]);
})->with(fn () => [
    'quadrata' => [citImmagine(1240, 1240), 'carta.jpg', 'image/jpeg', 'L\'immagine della carta intestata va in A4 verticale (alta 1,41 volte la larghezza): questa è di 1.240 × 1.240 pixel.'],
    'orizzontale' => [citImmagine(1754, 1240), 'carta.jpg', 'image/jpeg', 'L\'immagine della carta intestata va in A4 verticale (alta 1,41 volte la larghezza): questa è di 1.754 × 1.240 pixel.'],
    'troppo stretta' => [citImmagine(1000, 1414), 'carta.jpg', 'image/jpeg', 'L\'immagine della carta intestata va larga almeno 1.240 pixel (un A4 a 150 punti per pollice): questa è larga 1.000.'],
    'larga 1.239 pixel, un pixel meno del minimo' => [citImmagine(1239, 1752), 'carta.jpg', 'image/jpeg', 'L\'immagine della carta intestata va larga almeno 1.240 pixel (un A4 a 150 punti per pollice): questa è larga 1.239.'],
    'quasi un A4 (1.240 × 1.600: alta 1,29 volte la larghezza)' => [citImmagine(1240, 1600), 'carta.jpg', 'image/jpeg', 'L\'immagine della carta intestata va in A4 verticale (alta 1,41 volte la larghezza): questa è di 1.240 × 1.600 pixel.'],
    'un PNG A4' => [citImmagine(1240, 1754, 'png'), 'carta.png', 'image/png', CIT_SOLO_JPG],
    'un PNG A4 trasparente' => [citImmagine(1240, 1754, 'png_trasparente'), 'carta.png', 'image/png', CIT_SOLO_JPG],
    'un PNG che dice la misura e non ha i pixel' => [citPngSoloIntestazione(1240, 1754), 'carta.png', 'image/png', CIT_SOLO_JPG],
    'un JPG troncato (ne restano i primi 2.000 byte)' => [substr(citImmagine(1240, 1754), 0, 2000), 'carta.jpg', 'image/jpeg', 'L\'immagine non si apre: caricala di nuovo come JPG.'],
    'un SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" width="210" height="297"><rect width="210" height="297"/></svg>', 'carta.svg', 'image/svg+xml', CIT_NON_JPG],
    'un GIF' => [base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='), 'carta.gif', 'image/gif', CIT_NON_JPG],
]);

it('una foto girata (l\'orientamento scritto nei dati della foto non è il normale) si rifiuta con la frase che dice di girarla: anche una che, letta com\'è, sembra un A4 verticale', function (int $orientamento, int $larghezza, int $altezza) {
    citCarica($this, citFile(citJpgOrientato(citImmagine($larghezza, $altezza), $orientamento), 'foto.jpg', 'image/jpeg'))->assertSessionHasErrors(['carta' => CIT_GIRATA]);
    expect(CartaStampa::where('nome', 'Carta della tipografia')->exists())->toBeFalse()->and(citFileSalvati())->toBe([]);
})->with([
    // 6: da girare di 90 gradi; la foto del telefono che si vede verticale ha i pixel orizzontali, e quella che si vede orizzontale i pixel verticali.
    'girata di 90 gradi, che si vede verticale' => [6, 1754, 1240],
    'girata di 90 gradi, che si vede orizzontale' => [6, 1240, 1754],
    'girata di 90 gradi dall\'altra parte' => [8, 1754, 1240],
    'capovolta' => [3, 1240, 1754],
    'specchiata' => [2, 1240, 1754],
    'specchiata e capovolta' => [4, 1240, 1754],
    'specchiata e girata di 90 gradi, che si vede verticale' => [5, 1754, 1240],
    'specchiata e girata di 90 gradi dall\'altra parte, che si vede verticale' => [7, 1754, 1240],
]);

/** Un segmento di un JPG: la marca e il contenuto, con la lunghezza. */
function citSegmento(int $marca, string $contenuto): string
{
    return "\xFF".chr($marca).pack('n', strlen($contenuto) + 2).$contenuto;
}

/** Un JPG con i segmenti dati subito dopo il primo segmento (il JFIF di GD). */
function citConSegmenti(string $jpg, string $segmenti): string
{
    $dopoJfif = 4 + unpack('n', substr($jpg, 4, 2))[1];

    return substr($jpg, 0, $dopoJfif).$segmenti.substr($jpg, $dopoJfif);
}

/**
 * Un JPG A4 (1.240 × 1.754) con i dati di una foto: l'EXIF (l'orientamento normale, l'autore, il luogo, la miniatura), l'XMP, l'IPTC, un commento, la
 * miniatura nel JFIF e quella JFXX, l'MPF; e il profilo dei colori e il segmento «Adobe», che restano. Normale o progressivo.
 */
function citJpgConIDatiDellaFoto(bool $progressivo = false): string
{
    $im = imagecreatetruecolor(1240, 1754);
    imagefilledrectangle($im, 0, 0, 1239, 1753, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 1239, 140, imagecolorallocate($im, 122, 31, 31));
    imagefilledellipse($im, 620, 900, 500, 300, imagecolorallocate($im, 20, 120, 60));
    imageinterlace($im, $progressivo);
    ob_start();
    imagejpeg($im, null, 85);
    $jpg = (string) ob_get_clean();
    imagedestroy($im);
    // Il JFIF di GD con una miniatura di 2 × 1 pixel (i sei byte «JFMINI»), dopo la densità.
    $jpg = "\xFF\xD8".citSegmento(0xE0, substr($jpg, 6, 12)."\x02\x01JFMINI").substr($jpg, 4 + unpack('n', substr($jpg, 4, 2))[1]);

    $miniatura = "\xFF\xD8\xFF\xDB\x00\x04MINIATURA DELLA FOTO\xFF\xD9";
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x02"
        ."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00"
        ."\x01\x3B\x00\x02\x00\x00\x00\x0C\x00\x00\x00\x26"
        ."\x00\x00\x00\x00"
        ."Mario Rossi\x00"."GPSLatitude 45.0703N 7.6869E".$miniatura;

    return citConSegmenti($jpg, citSegmento(0xE1, "Exif\x00\x00".$tiff)
        .citSegmento(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta><exif:GPSLatitude>45,4.218N</exif:GPSLatitude><dc:creator>Mario Rossi</dc:creator></x:xmpmeta>")
        .citSegmento(0xED, "Photoshop 3.0\x008BIM\x04\x04\x00\x00\x00\x00\x00\x10\x1C\x02\x5C\x00\x0AVia Roma 1")
        .citSegmento(0xFE, 'Scansione fatta da Mario Rossi')
        .citSegmento(0xE0, "JFXX\x00\x10".$miniatura)
        .citSegmento(0xE2, "MPF\x00II*\x00\x08\x00\x00\x00")
        .citSegmento(0xE2, "ICC_PROFILE\x00\x01\x01".str_repeat("\x00", 16).'RGB XYZ '.str_repeat("\x00", 8).'acsp'.str_repeat("\x00", 88))
        .citSegmento(0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x01"));
}

/** I pixel di un JPG come li legge GD: l'impronta di un PNG di quello che si vede. */
function citPixel(string $jpg): string
{
    $im = imagecreatefromstring($jpg);
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return md5((string) ob_get_clean());
}

it('la carta in immagine si salva senza i dati della foto (l\'autore, il luogo, la miniatura, i commenti): gli stessi pixel, i byte uguali dalla prima tabella, il profilo dei colori e «Adobe» restano; nella prova della carta i dati non arrivano', function (bool $progressivo) {
    $jpg = citJpgConIDatiDellaFoto($progressivo);
    citCarica($this, citFile($jpg, 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    $carta = CartaStampa::where('nome', 'Carta della tipografia')->sole();
    $salvato = Storage::disk('public')->get($carta->carta_path);

    expect($salvato)->not->toContain('Mario Rossi')->not->toContain('GPSLatitude')->not->toContain('MINIATURA')->not->toContain('Photoshop')
        ->not->toContain('Via Roma 1')->not->toContain('ns.adobe.com')->not->toContain('CREATOR')->not->toContain('JFXX')->not->toContain("MPF\x00")
        ->not->toContain('JFMINI')->and($jpg)->toContain('JFMINI')
        ->and(substr_count($salvato, "ICC_PROFILE\x00"))->toBe(1)
        ->and($salvato)->toContain("Adobe\x00")->toContain("JFIF\x00")
        ->and(citPixel($salvato))->toBe(citPixel($jpg))
        ->and(array_slice(getimagesizefromstring($salvato), 0, 3))->toBe(array_slice(getimagesizefromstring($jpg), 0, 3))
        ->and(str_ends_with($salvato, "\xFF\xD9"))->toBeTrue()
        ->and(substr($salvato, strpos($salvato, "\xFF\xDB\x00\x43")) === substr($jpg, strpos($jpg, "\xFF\xDB\x00\x43")))->toBeTrue();

    $pdf = (string) $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', $carta->id))->assertOk()->getContent();
    expect(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and($pdf)->not->toContain('Mario Rossi')->not->toContain('GPSLatitude')->not->toContain('MINIATURA');
})->with(['un JPG normale' => [false], 'un JPG progressivo' => [true]]);

it('un JPG con dei dati dopo la fine dell\'immagine (le immagini in più di un MPF, il video di una foto in movimento, la coda di certi telefoni) si carica: quello che segue la fine si toglie', function () {
    $jpg = citImmagine(1240, 1754);
    $coda = "\xFF\xD8\xFF\xDB\x00\x04SECONDA IMMAGINE\xFF\xD9".'SEFH'.str_repeat("\x00", 40).'SEFT';
    citCarica($this, citFile($jpg.$coda, 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    $salvato = Storage::disk('public')->get(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_path);

    expect($salvato)->not->toContain('SECONDA IMMAGINE')->not->toContain('SEFT')
        ->and(str_ends_with($salvato, "\xFF\xD9"))->toBeTrue()
        ->and(substr($salvato, strpos($salvato, "\xFF\xDB")) === substr($jpg, strpos($jpg, "\xFF\xDB")))->toBeTrue();
});

/**
 * Un JPG A4 grigio (1.240 × 1.754) scritto a mano con le marche di ripresa nei dati dei pixel, una per ogni riga di blocchi (DRI e RST0–RST7, come li
 * scrivono molte fotocamere e molti scanner): ogni blocco è il grigio medio, con due tabelle di Huffman di un codice solo.
 */
function citJpgConRiprese(): string
{
    [$larghezza, $altezza] = [1240, 1754];
    [$colonne, $righe] = [intdiv($larghezza + 7, 8), intdiv($altezza + 7, 8)];
    $huffman = fn (int $classe) => citSegmento(0xC4, chr($classe)."\x01".str_repeat("\x00", 15)."\x00");
    // Una riga di blocchi: due bit a zero per blocco (la differenza del DC e la fine del blocco), completati con bit a uno fino al byte.
    $bit = 2 * $colonne;
    $riga = str_repeat("\x00", intdiv($bit, 8)).($bit % 8 ? chr((1 << (8 - $bit % 8)) - 1) : '');
    $dati = '';
    for ($r = 0; $r < $righe; $r++) {
        $dati .= ($r > 0 ? "\xFF".chr(0xD0 + ($r - 1) % 8) : '').$riga;
    }

    return "\xFF\xD8".citSegmento(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
        .citSegmento(0xDB, "\x00".str_repeat("\x01", 64))
        .citSegmento(0xC0, "\x08".pack('n', $altezza).pack('n', $larghezza)."\x01\x01\x11\x00")
        .$huffman(0x00).$huffman(0x10)
        .citSegmento(0xDD, pack('n', $colonne))
        .citSegmento(0xDA, "\x01\x01\x00\x00\x3F\x00")
        .$dati."\xFF\xD9";
}

it('un JPG con le marche di ripresa nei dati dei pixel si carica e si salva byte per byte; un JFIF o un profilo dei colori troppo corti per la loro forma si tolgono, e il resto resta com\'era', function (string $caso) {
    $jpg = $caso === 'riprese' ? citJpgConRiprese() : citImmagine(1240, 1754);
    $arrivato = match ($caso) {
        // Dopo il JFIF, i dati di una foto: l'EXIF si toglie anche qui.
        'riprese' => citConSegmenti($jpg, citSegmento(0xE1, "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x00Mario Rossi")),
        // Un secondo JFIF di 10 byte, più corto della densità: riscritto con la lunghezza del JFIF intero, il file salvato sarebbe rotto.
        'jfif' => citConSegmenti($jpg, citSegmento(0xE0, "JFIF\x00\x01\x02\x00\x00\x01")),
        // Il nome del profilo con un byte solo dopo: non è un profilo.
        'profilo' => citConSegmenti($jpg, citSegmento(0xE2, "ICC_PROFILE\x00\x01")),
    };

    citCarica($this, citFile($arrivato, 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    $salvato = Storage::disk('public')->get(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_path);

    expect($salvato)->toBe(\App\Services\PDF\CartaIntestata::senzaDatiDellaFoto($jpg))
        ->and(citPixel($salvato))->toBe(citPixel($jpg))
        ->and($salvato)->not->toContain('Mario Rossi')->not->toContain('ICC_PROFILE')
        ->and(substr_count($salvato, "JFIF\x00"))->toBe(1);
    if ($caso === 'riprese') {
        // Il file pulito è quello scritto a mano, con le sue 219 riprese.
        expect($salvato)->toBe($jpg)->and(preg_match_all('/\xFF[\xD0-\xD7]/', $salvato))->toBe(219);
    }
})->with(['le marche di ripresa' => ['riprese'], 'un JFIF corto' => ['jfif'], 'un profilo dei colori corto' => ['profilo']]);

/**
 * Disegna con mPDF il JPG salvato di una carta, in un processo a parte con 5 secondi di tempo: «disegnata», o l'errore. mPDF cerca il nome del profilo
 * dei colori in tutto il file: preceduto da due byte zero, torna indietro e gira senza fine.
 */
function citDisegnaInUnProcesso(string $jpg): string
{
    $file = tempnam(sys_get_temp_dir(), 'cit').'.jpg';
    file_put_contents($file, $jpg);
    $codice = 'require getcwd()."/vendor/autoload.php"; set_time_limit(5);'
        .' $m = new \Mpdf\Mpdf(["tempDir" => sys_get_temp_dir()]); $m->Image($argv[1], 0, 0, 210, 297, "", "", true, false); echo "disegnata";';
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $codice, $file], base_path());
    $processo->setTimeout(60)->run();
    @unlink($file);

    return trim($processo->getOutput().$processo->getErrorOutput());
}

it('il nome del profilo dei colori fuori dal suo segmento: in un commento si toglie con il commento, e la carta si disegna in tempo; in un segmento che resta, il JPG si rifiuta', function () {
    $im = imagecreatetruecolor(1240, 1754);
    imagefilledrectangle($im, 0, 0, 1239, 1753, imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagejpeg($im, null, 80);
    $jpg = (string) ob_get_clean();
    $nelCommento = substr($jpg, 0, 2).citSegmento(0xFE, "\x00\x00ICC_PROFILE\x00\x01\x01").substr($jpg, 2);
    $inUnSegmentoCheResta = substr($jpg, 0, 2).citSegmento(0xF0, "\x00\x00ICC_PROFILE\x00\x01\x01").substr($jpg, 2);

    citCarica($this, citFile($nelCommento, 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    $salvato = Storage::disk('public')->get(CartaStampa::where('nome', 'Carta della tipografia')->sole()->carta_path);
    expect($salvato)->not->toContain('ICC_PROFILE')
        ->and(citDisegnaInUnProcesso($salvato))->toBe('disegnata');

    citCarica($this, citFile($inUnSegmentoCheResta, 'carta.jpg', 'image/jpeg'), ['nome' => 'Seconda carta'])
        ->assertSessionHasErrors(['carta' => 'L\'immagine non si apre: caricala di nuovo come JPG.']);
    expect(CartaStampa::where('nome', 'Seconda carta')->exists())->toBeFalse();
});

it('una carta in JPG a 600 punti per pollice: il controllo del caricamento e una stampa di tre pagine con la carta stanno in 128 MB con 35 già usati', function () {
    $file = tempnam(sys_get_temp_dir(), 'cit').'.jpg';
    file_put_contents($file, citImmagine(4960, 7016));
    // `storage_path` dell'applicazione, che il processo non avvia: la cartella temporanea di mPDF.
    $codice = 'function storage_path($p = "") { return sys_get_temp_dir()."/cit_storage/".$p; } require getcwd()."/vendor/autoload.php";'
        .' $zavorra = str_repeat("x", 35 * 1048576); $rifiuto = \App\Services\PDF\CartaIntestata::rifiutoDellImmagine($argv[1]);'
        .' if ($rifiuto !== null) { echo $rifiuto; exit; }'
        .' $carta = new \App\Services\PDF\CartaIntestata($argv[1], "immagine", 1, 45, 25, 20, 18);'
        .' $m = new \App\Services\PDF\MpdfConCarta(["mode" => "utf-8", "format" => "A4", "tempDir" => sys_get_temp_dir()], $carta, true, fn () => throw new \RuntimeException("a campi"));'
        .' for ($i = 1; $i <= 3; $i++) { if ($i > 1) { $m->AddPage(); } $m->WriteHTML("<p>Pagina {$i}</p>"); }'
        .' $pdf = $m->Output("", "S"); echo "fatto ".substr_count($pdf, "/Subtype /Image");';
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $codice, $file], base_path());
    $processo->setTimeout(120)->run();
    @unlink($file);

    // La carta è un'immagine sola nel PDF, usata da tutte e tre le pagine.
    expect(trim($processo->getOutput().$processo->getErrorOutput()))->toBe('fatto 1')
        ->and($processo->getExitCode())->toBe(0);
});

it('i margini della carta intestata: quattro, in millimetri; l\'alto e il basso insieme al massimo 150 mm; il sinistro e il destro al massimo 50', function (array $margini, string $campo, string $frase) {
    citCarica($this, citFile(citPdf(), 'carta.pdf', 'application/pdf'), $margini)->assertSessionHasErrors([$campo => $frase]);
    expect(CartaStampa::where('nome', 'Carta della tipografia')->exists())->toBeFalse()->and(citFileSalvati())->toBe([]);
})->with([
    'l\'alto e il basso oltre 150' => [['margine_alto' => 100, 'margine_basso' => 60], 'margine_basso', 'Il margine alto e quello basso insieme sono al massimo 150 mm: sotto, il testo non avrebbe spazio.'],
    'il sinistro oltre 50' => [['margine_sinistro' => 51], 'margine_sinistro', 'Il margine sinistro va da 0 a 50 mm.'],
    'il destro sotto zero' => [['margine_destro' => -1], 'margine_destro', 'Il margine destro va da 0 a 50 mm.'],
    'l\'alto che manca' => [['margine_alto' => null], 'margine_alto', 'Scrivi il margine alto della carta, in millimetri.'],
]);

it('una carta nuova con la carta intestata e senza il file si rifiuta: carica la carta', function () {
    citCarica($this, null)->assertSessionHasErrors(['carta' => 'Carica la carta intestata: un PDF di una o due pagine, o un\'immagine A4 in JPG.']);
    expect(CartaStampa::where('nome', 'Carta della tipografia')->exists())->toBeFalse();
});

it('una carta cambiata senza un file nuovo tiene la sua carta intestata; con un file nuovo, il vecchio si cancella dopo il salvataggio; tornata a campi, il file resta', function () {
    $carta = citCartaIntestata();
    $vecchio = $carta->carta_path;
    $dati = ['nome' => $carta->nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => false, 'aspetto' => 'carta'] + CIT_MARGINI;

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['margine_alto' => 50] + $dati)->assertSessionHasNoErrors();
    expect($carta->fresh()->only(['carta_path', 'margine_alto']))->toBe(['carta_path' => $vecchio, 'margine_alto' => 50.0]);

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['carta' => citFile(citImmagine(1240, 1754), 'carta.jpg', 'image/jpeg')] + $dati)
        ->assertSessionHasNoErrors();
    $nuova = $carta->fresh();
    expect($nuova->carta_tipo)->toBe('immagine')->and($nuova->carta_pagine)->toBe(1)
        ->and(Storage::disk('public')->exists($vecchio))->toBeFalse()
        ->and(Storage::disk('public')->exists($nuova->carta_path))->toBeTrue();

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['aspetto' => 'campi'] + $dati)->assertSessionHasNoErrors();
    expect($carta->fresh()->aspetto)->toBe('campi')->and(Storage::disk('public')->exists($nuova->carta_path))->toBeTrue();
});

it('«Togli la carta intestata»: una carta che va a campi con la richiesta di togliere il file lo perde, e il file si cancella dopo il salvataggio; con la carta intestata la richiesta non conta', function () {
    $carta = citCartaIntestata('pdf', ['predefinita' => true]);
    $vecchio = $carta->carta_path;
    $dati = ['nome' => $carta->nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => true, 'togli_carta_intestata' => true];

    // Con la carta intestata il file resta.
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['aspetto' => 'carta'] + CIT_MARGINI + $dati)->assertSessionHasNoErrors();
    expect($carta->fresh()->carta_path)->toBe($vecchio)->and(Storage::disk('public')->exists($vecchio))->toBeTrue();

    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['aspetto' => 'campi'] + $dati)
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success'
            && ($m['message'] ?? null) === 'Carta «Carta della tipografia» salvata a campi: il file della carta intestata è tolto. Le stampe che la usano escono così.');
    expect($carta->fresh()->only(['aspetto', 'carta_path', 'carta_tipo', 'carta_pagine', 'margine_alto']))
        ->toBe(['aspetto' => 'campi', 'carta_path' => null, 'carta_tipo' => null, 'carta_pagine' => null, 'margine_alto' => null])
        ->and(citFileSalvati())->toBe([]);
    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect(collect(collect($p['carte'])->firstWhere('id', $carta->id))->only(['carta_tipo', 'carta_pagine', 'margini'])->all())
        ->toBe(['carta_tipo' => null, 'carta_pagine' => null, 'margini' => null]);
});

it('«Togli la carta intestata» con una carta che non si salva (la predefinita che perde il segno): la carta e il file restano', function () {
    $carta = citCartaIntestata('pdf', ['predefinita' => true]);
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['nome' => $carta->nome, 'mostra_logo' => true, 'colore' => null,
        'predefinita' => false, 'aspetto' => 'campi', 'togli_carta_intestata' => true])->assertSessionHasErrors('predefinita');

    expect($carta->fresh()->only(['aspetto', 'carta_path']))->toBe(['aspetto' => 'carta', 'carta_path' => 'settings/carte/carta-delle-prove.pdf'])
        ->and(citFileSalvati())->toBe(['settings/carte/carta-delle-prove.pdf']);
});

it('un file nuovo che non si legge lascia la carta com\'era, con il suo file', function () {
    $carta = citCartaIntestata();
    $dati = ['nome' => $carta->nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => false, 'aspetto' => 'carta'] + CIT_MARGINI;
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['carta' => citFile(citPdf(null, 'flusso'), 'carta.pdf', 'application/pdf')] + $dati)
        ->assertSessionHasErrors(['carta' => CIT_RIFIUTO]);

    expect($carta->fresh()->carta_path)->toBe('settings/carte/carta-delle-prove.pdf')->and(citFileSalvati())->toBe(['settings/carte/carta-delle-prove.pdf']);
});

it('una carta eliminata si porta via il file della sua carta intestata', function () {
    $carta = citCartaIntestata();
    $s = app(PrintSettings::class);
    $s->carta_per_gruppo = array_fill_keys(CIT_GRUPPI, null);
    $s->save();
    $this->actingAs($this->user)->delete(route('impostazioni.stampe.carte.destroy', $carta->id))->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success');

    expect(CartaStampa::find($carta->id))->toBeNull()->and(citFileSalvati())->toBe([]);
});

// —— In stampa

it('due lettere in un PDF: la prima pagina di ognuna ha la prima pagina della carta; lo studio non si scrive, il condominio sì, a sinistra in cima alla parte libera; il destinatario allineato al margine destro della carta; il titolo del PDF resta', function () {
    cdsStudioPieno(true);
    $l = lttScenario($this);
    $l['c']->update(['nome' => 'Le Rose', 'indirizzo' => 'Via delle Rose 4', 'cap' => '20100', 'comune' => 'Milano', 'codice_fiscale' => '90012345678']);
    $senzaCarta = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l))->assertOk()->getContent();
    citCartaIntestata();
    $pdf = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l))->assertOk()->getContent();

    expect(cdsNumeroPagine($pdf))->toBe(2)->and(citTitolo($pdf))->not->toBeNull()->toBe(citTitolo($senzaCarta));
    foreach ([1, 2] as $p) {
        $testo = cdsTesto($pdf, $p);
        expect($testo)->toContain('CARTA PRIMA PAGINA')->not->toContain('CARTA PAGINE SEGUENTI')
            ->not->toContain('Studio Bianchi')->not->toContain('P.IVA')
            ->toContain('Amministratore del Condominio Le Rose')->toContain('C.F. 90012345678')
            ->and(cdsHaImmagine($pdf, $p))->toBeFalse("pagina {$p}: il logo, che è sulla carta");
        // In cima alla parte libera l'etichetta dell'anteprima, e sotto «Amministratore del …», al margine sinistro della carta.
        [$x, $y] = cdsDove($pdf, $p, 'Amministratore del');
        expect(cdsDove($pdf, $p, 'ANTEPRIMA')[1] ?? null)->toBeGreaterThan(45.0)->toBeLessThan(52.0)
            ->and($x)->toEqualWithDelta(20.0, 0.5)->and($y)->toBeGreaterThan(45.0)->toBeLessThan(70.0);
    }
    // Il nome finisce al margine destro della carta, 18 mm prima del bordo del foglio.
    $fine = fn (string $nome) => cdsRiga($pdf, cdsDove($pdf, 1, $nome) !== null ? 1 : 2, $nome)[2];
    expect($fine('Ugo'))->toEqualWithDelta(210.0 - CIT_MARGINI['margine_destro'], 0.3)->and($fine('Bice'))->toEqualWithDelta(210.0 - CIT_MARGINI['margine_destro'], 0.3);
});

it('la ricevuta e l\'avviso con la carta intestata: in cima alla parte libera, al margine sinistro della carta, «Amministratore del Condominio …» con l\'indirizzo e il C.F., non il condominio come un titolo sotto la carta; nella ricevuta il conto corrente del condominio; il destinatario allineato al margine destro della carta', function () {
    cdsStudioPieno(true);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $compensazione = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    citCartaIntestata();
    $ricevuta = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id]])
        ->assertOk()->getContent();
    $avviso = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $s->condominio->id]),
        ['avvisi' => [$compensazione->id.':'.$s->anna->id], 'bollo' => 'non_dovuta'])->assertOk()->getContent();

    foreach (['la ricevuta' => $ricevuta, 'l\'avviso' => $avviso] as $quale => $pdf) {
        expect(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->toContain('Amministratore del Condominio Via dei Pini')->toContain('Via dei Pini 12')
            ->toContain('10100 Torino (TO)')->toContain('C.F. 90012345678')->not->toContain('Studio Bianchi');
        [$x, $y] = cdsDove($pdf, 1, 'Amministratore del');
        expect($x)->toEqualWithDelta(20.0, 0.5, $quale)->and($y)->toBeGreaterThan(45.0)->toBeLessThan(70.0)
            ->and(cdsRiga($pdf, 1, 'Gentile')[2])->toEqualWithDelta(210.0 - CIT_MARGINI['margine_destro'], 0.3);
    }
    expect(cdsTesto($ricevuta, 1))->toContain('Conto BPER (conto corrente del condominio)');
});

it('una lettera di due pagine: la prima ha la prima pagina della carta, la seconda la seconda; il piede subito sopra il margine basso', function () {
    cdsStudioPieno(true);
    $l = lttScenario($this);
    citCartaIntestata();
    $spia = cdsSpia();
    $lungo = "Gentile [nome],\nrisultano da versare [totale].\n".implode("\n", array_map(fn ($i) => "Riga {$i} della lettera, che la fa andare su due pagine.", range(1, 50)));
    $pdf = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l, ['persone' => [$l['persone']['ugo']->id], 'testo' => $lungo]))
        ->assertOk()->getContent();

    expect(cdsNumeroPagine($pdf))->toBe(2)
        ->and(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->not->toContain('CARTA PAGINE SEGUENTI')
        ->and(cdsTesto($pdf, 2))->toContain('CARTA PAGINE SEGUENTI')->not->toContain('CARTA PRIMA PAGINA');
    // Il piede finisce al margine basso della carta (25 mm dal bordo del foglio), e il corpo sopra di lui: il margine basso si allunga sul piede.
    $mpdf = $spia->stampe[0]['mpdf'];
    expect((float) $mpdf->margin_footer)->toBe(25.0)
        ->and($spia->stampe[0]['margine_basso'])->toBeGreaterThan(25.0)->toBeLessThan(40.0)
        ->and(cdsTesto($pdf, 1))->toContain('Pagina 1 di 2');
});

it('la distinta, verticale, con la carta intestata: la carta sotto; il condominio in una riga in cima alla parte libera; lo studio no; i margini della carta; il piede subito sopra il margine basso', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata();
    $spia = cdsSpia();
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    $testo = cdsTesto($pdf, 1);
    [$xCondominio, $yCondominio] = cdsDove($pdf, 1, 'Condominio Via Roma 23');
    [$xTitolo, $yTitolo] = cdsDove($pdf, 1, 'DISTINTA DELLE SPESE');

    expect($testo)->toContain('CARTA PRIMA PAGINA')->not->toContain('Studio Bianchi')->not->toContain('P.IVA')
        ->toContain('Condominio Via Roma 23 · Via Roma 23, 20100 Milano · C.F. 90012345678')
        ->and(cdsHaImmagine($pdf, 1))->toBeFalse()
        ->and($xCondominio)->toEqualWithDelta(20.0, 0.5)
        ->and($yCondominio)->toBeGreaterThan(45.0)->toBeLessThan(50.0)
        ->and($xTitolo)->toEqualWithDelta(20.0, 0.5)
        ->and($yTitolo)->toBeGreaterThan($yCondominio + 3.0)
        // Il piede finisce al margine basso della carta (25 mm), e il corpo sopra di lui; i lati sono quelli della carta.
        ->and(cdsTesto($pdf, 1))->toContain('Documento emesso il')
        ->and((float) $spia->stampe[0]['mpdf']->margin_footer)->toBe(25.0)
        ->and($spia->stampe[0]['margine_basso'])->toBeGreaterThan(25.0)->toBeLessThan(45.0)
        ->and([(float) $spia->stampe[0]['mpdf']->lMargin, (float) $spia->stampe[0]['mpdf']->rMargin])->toBe([20.0, 18.0]);
});

it('una stampa verticale di due pagine (l\'estratto conto): la prima pagina ha la prima della carta, la seconda la seconda, e il condominio in cima a tutte e due', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata();
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['bice']]));

    expect(cdsNumeroPagine($pdf))->toBeGreaterThanOrEqual(2)
        ->and(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->not->toContain('CARTA PAGINE SEGUENTI')
        ->and(cdsTesto($pdf, 2))->toContain('CARTA PAGINE SEGUENTI')->not->toContain('CARTA PRIMA PAGINA')
        ->and(cdsTesto($pdf, 2))->toContain('Condominio Via Roma 23');
});

it('la situazione patrimoniale, verticale in Inter, con la carta intestata: la carta sotto, il condominio, lo studio no', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata();
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=situazione');

    expect(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->toContain('Condominio Via Roma 23')->not->toContain('Studio Bianchi')->not->toContain('P.IVA')
        // Il condominio in una riga, al margine sinistro della carta: non la colonna di destra della testata a campi.
        ->toContain('Condominio Via Roma 23 · Via Roma 23, 20100 Milano · C.F. 90012345678')
        ->and(cdsDove($pdf, 1, 'Condominio Via Roma 23')[0] ?? null)->toEqualWithDelta(20.0, 0.5)
        ->and(cdsDove($pdf, 1, 'Condominio Via Roma 23')[1] ?? null)->toBeGreaterThan(45.0);
});

it('una stampa orizzontale con la carta intestata esce con la testata a campi della stessa carta: lo studio, il logo, il condominio, il colore; niente carta sotto', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata('pdf', ['colore' => '#7a1f1f']);
    $spia = cdsSpia();
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-rate.print-riparto-tabelle', [$s['c'], $s['e'], $s['piano']]));

    expect(citVersi($pdf)[0])->toBe('orizzontale')
        ->and(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->toContain('Condominio Via Roma 23')
        ->not->toContain('CARTA PRIMA PAGINA')
        ->and(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and(cdsColoreDi($pdf, 1, 'Studio Bianchi'))->toBe('7a1f1f')
        ->and($spia->stampe[0]['mpdf'])->not->toBeInstanceOf(\App\Services\PDF\MpdfConCarta::class);
});

it('il fascicolo: la prima pagina della carta al solo frontespizio; le parti verticali hanno la seconda; le orizzontali la testata a campi', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata();
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.rendiconto.fascicolo', [$s['c'], $s['e']]));
    $versi = citVersi($pdf);
    $male = [];
    foreach ($versi as $i => $verso) {
        $testo = cdsTesto($pdf, $i + 1);
        $pagina = $i + 1;
        if ($i === 0) {
            if (! str_contains($testo, 'CARTA PRIMA PAGINA') || str_contains($testo, 'Studio Bianchi')) {
                $male[] = "la pagina 1 (il frontespizio): la prima pagina della carta, senza lo studio";
            }
        } elseif ($verso === 'verticale') {
            if (! str_contains($testo, 'CARTA PAGINE SEGUENTI') || str_contains($testo, 'CARTA PRIMA PAGINA') || str_contains($testo, 'Studio Bianchi')) {
                $male[] = "la pagina {$pagina} (verticale): la seconda pagina della carta, senza lo studio";
            }
        } elseif (str_contains($testo, 'CARTA PRIMA PAGINA') || str_contains($testo, 'CARTA PAGINE SEGUENTI') || ! str_contains($testo, 'Studio Bianchi')) {
            $male[] = "la pagina {$pagina} (orizzontale): la testata a campi, con lo studio, senza la carta";
        }
    }

    // La prima pagina della situazione (la prima parte verticale): la riga della stampa sotto la testatina del condominio, non sopra.
    $situazione = array_search('verticale', array_slice($versi, 1, null, true), true) + 1;
    $testatina = cdsDove($pdf, $situazione, 'Condominio Via Roma 23')[1] ?? null;
    $riga = cdsDove($pdf, $situazione, 'SITUAZIONE PATRIMONIALE')[1] ?? null;

    expect($versi)->toContain('verticale')->toContain('orizzontale')
        ->and($male)->toBe([])
        ->and($testatina)->not->toBeNull()->and($riga)->not->toBeNull()
        ->and($riga)->toBeGreaterThan($testatina + 3.0);
});

it('la carta in immagine: ogni pagina ha l\'immagine sotto, su tutto il foglio', function () {
    $s = citScenario($this);
    cdsStudioPieno(false);
    citCartaIntestata('immagine');
    $pdf = citPdfDallaRotta($this, route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone']['bice']]));

    foreach (range(1, cdsNumeroPagine($pdf)) as $p) {
        [$larghezza, $altezza, $x, $y] = cdsImmagini($pdf, $p)[0] ?? [null, null, null, null];
        expect([$larghezza, $altezza])->toEqualWithDelta([210.0, 297.0], 0.2)
            ->and([$x, $y])->toEqualWithDelta([0.0, 0.0], 0.2);
    }
});

it('una firma con lo sfondo trasparente, caricata dalla pagina, sopra una carta intestata colorata: nella lettera la firma tiene la sua trasparenza, e dove è trasparente si vede la carta, senza un riquadro bianco', function () {
    cdsStudioPieno(true);
    $l = lttScenario($this);
    $this->actingAs($this->user)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota',
        'firma_stampe' => citFile(citFirmaTrasparente(), 'firma.png', 'image/png')])->assertSessionHasNoErrors();
    citCarica($this, citFile(citJpgColorato(250, 236, 170), 'carta.jpg', 'image/jpeg'))->assertSessionHasNoErrors();
    citScegliPerTutti(CartaStampa::where('nome', 'Carta della tipografia')->sole());

    $pdf = (string) $this->actingAs($this->user)->post(lttRotta($l, 'pdf-anteprima'), lttScelte($l, ['persone' => [$l['persone']['ugo']->id]]))->assertOk()->getContent();
    expect(cdsHaImmagine($pdf, 1))->toBeTrue('la carta in immagine sotto la pagina');
    $firme = citImmaginiConMaschera($pdf, 400, 150);
    expect($firme)->toHaveCount(1)->and($firme[0][0])->toBeTrue('la firma senza la maschera della trasparenza: il suo fondo è pieno');
    $maschera = $firme[0][1];
    $punto = fn (int $x, int $y) => ord($maschera[$y * 400 + $x]);
    // In alto a sinistra la firma è trasparente (si vede la carta), al centro il tratto è pieno.
    expect(strlen($maschera))->toBe(400 * 150)->and($punto(2, 2))->toBe(0)->and($punto(200, 75))->toBe(255);
});

it('una carta intestata che in stampa non si legge (il file non c\'è più, o non è più un PDF che si legge): la stampa esce con la stessa carta a campi, con una riga nel registro; la pagina lo dice', function (?string $contenuto) {
    $s = citScenario($this);
    cdsStudioPieno(true);
    $carta = citCartaIntestata('pdf', ['colore' => '#7a1f1f']);
    $contenuto === null ? Storage::disk('public')->delete($carta->carta_path) : Storage::disk('public')->put($carta->carta_path, $contenuto);
    app()->forgetInstance(CartaDiStampa::class);
    Log::spy();

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->not->toContain('CARTA PRIMA PAGINA')
        ->and(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and(cdsColoreDi($pdf, 1, 'Studio Bianchi'))->toBe('7a1f1f');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $frase, array $contesto = []) => str_contains($frase, 'carta intestata') && ($contesto['carta'] ?? null) === $carta->id)->atLeast()->once();

    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect(collect($p['carte'])->firstWhere('id', $carta->id)['carta_illeggibile'] ?? null)
        ->toBe(CIT_ILLEGGIBILE);
})->with([
    'il file non c\'è più' => [null],
    'il file non è più un PDF che si legge' => [citPdf(null, 'flusso')],
    'il file non è più un PDF' => ['non sono un PDF'],
]);

it('una carta intestata che non si legge, con lo studio senza il nome: la stampa esce a campi senza lo studio, e la pagina dice questo e chiede di scrivere il nome', function () {
    $s = citScenario($this);
    cdsStudioPieno(true, ['studio_nome' => '   ']);
    $carta = citCartaIntestata();
    Storage::disk('public')->delete($carta->carta_path);
    app()->forgetInstance(CartaDiStampa::class);

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('Condominio Via Roma 23')->toContain('DISTINTA DELLE SPESE')->not->toContain('CARTA PRIMA PAGINA')
        ->not->toContain('Via Garibaldi 10')->not->toContain('01234567890');

    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect(collect($p['carte'])->firstWhere('id', $carta->id)['carta_illeggibile'] ?? null)->toBe(CIT_ILLEGGIBILE_SENZA_NOME);
});

it('una carta in immagine rovinata sul disco dopo il caricamento (ne restano i primi 200 byte): la stampa esce con la stessa carta a campi, con una riga nel registro; la pagina lo dice', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    $carta = citCartaIntestata('immagine', ['colore' => '#7a1f1f']);
    Storage::disk('public')->put($carta->carta_path, substr((string) Storage::disk('public')->get($carta->carta_path), 0, 200));
    app()->forgetInstance(CartaDiStampa::class);
    Log::spy();

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->toContain('Condominio Via Roma 23')
        ->and(cdsColoreDi($pdf, 1, 'Studio Bianchi'))->toBe('7a1f1f');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $frase, array $contesto = []) => str_contains($frase, 'carta intestata') && ($contesto['carta'] ?? null) === $carta->id)->atLeast()->once();

    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect(collect($p['carte'])->firstWhere('id', $carta->id)['carta_illeggibile'] ?? null)->toBe(CIT_ILLEGGIBILE);
});

it('una carta intestata che passa il controllo e poi non si disegna (il file cambiato prima della scrittura): la stampa esce con la stessa carta a campi, con una riga nel registro, mai un errore', function (string $tipo, string $come) {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata($tipo);
    // Il file della carta cambia dopo il controllo della stampa, prima che il PDF si scriva: troncato, o un file che non è un'immagine.
    app()->instance(\App\Services\PDF\PdfService::class, new class($come) extends \App\Services\PDF\PdfService
    {
        public function __construct(private string $come) {}

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            $mpdf = parent::generate($view, $data, $config);
            if ($mpdf instanceof \App\Services\PDF\MpdfConCarta) {
                $percorso = $mpdf->cartaIntestata()->percorso;
                file_put_contents($percorso, $this->come === 'troncato' ? substr((string) file_get_contents($percorso), 0, 200) : 'non sono un\'immagine');
            }

            return $mpdf;
        }
    });
    Log::spy();

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('Condominio Via Roma 23')->not->toContain('CARTA PRIMA PAGINA')
        ->and(cdsHaImmagine($pdf, 1))->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $frase) => str_contains($frase, 'carta intestata'))->atLeast()->once();
})->with([
    'la carta in PDF, troncata' => ['pdf', 'troncato'],
    'la carta in immagine, troncata' => ['immagine', 'troncato'],
    'la carta in immagine, diventata un file che non è un\'immagine' => ['immagine', 'non_immagine'],
]);

it('una carta segnata con la carta intestata e senza il file (una riga scritta nel database) stampa a campi, senza errori', function () {
    $s = citScenario($this);
    cdsStudioPieno(true);
    citCartaIntestata('pdf', ['carta_path' => null]);

    $pdf = citPdfDallaRotta($this, route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]));
    expect(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->not->toContain('CARTA PRIMA PAGINA');
});

it('se la carta non si salva, il file nuovo già scritto si toglie, e restano la carta e il file di prima', function () {
    $carta = citCartaIntestata('pdf', ['predefinita' => true]);
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $carta->id), ['nome' => $carta->nome, 'mostra_logo' => true, 'colore' => null,
        'predefinita' => false, 'aspetto' => 'carta', 'carta' => citFile(citImmagine(1240, 1754), 'carta.jpg', 'image/jpeg')] + CIT_MARGINI)
        ->assertSessionHasErrors('predefinita');

    expect($carta->fresh()->carta_path)->toBe('settings/carte/carta-delle-prove.pdf')->and(citFileSalvati())->toBe(['settings/carte/carta-delle-prove.pdf']);
});

it('«Prova la carta» con la carta intestata: la lettera con la carta sotto, la pagina orizzontale con la testata a campi della carta', function () {
    cdsStudioPieno(true);
    $carta = citCartaIntestata();
    $pdf = (string) $this->actingAs($this->user)->get(route('impostazioni.stampe.carte.prova', $carta->id))->assertOk()->getContent();

    expect(citVersi($pdf))->toBe(['verticale', 'orizzontale'])
        ->and(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->toContain('PROVA — non è una lettera mandata')->not->toContain('Studio Bianchi')
        ->and(cdsTesto($pdf, 2))->toContain('Studio Bianchi Amministrazioni')->not->toContain('CARTA PRIMA PAGINA')->not->toContain('CARTA PAGINE SEGUENTI');
});

it('la pagina riceve il limite del file della carta intestata e, per ogni carta, l\'aspetto, la carta intestata (il tipo, le pagine) e i margini; una carta a campi senza il file non ne ha', function () {
    $carta = citCartaIntestata();
    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    $intestata = collect($p['carte'])->firstWhere('id', $carta->id);
    $campi = collect($p['carte'])->firstWhere('predefinita', true);

    expect($p['limiteCarta'])->toBe(\App\Support\LimiteCaricamento::etichetta(\App\Services\PDF\CarteDelloStudio::MB_CARTA))->not->toBe('');
    expect(collect($intestata)->only(['aspetto', 'carta_tipo', 'carta_pagine', 'margini', 'carta_illeggibile'])->all())->toBe(['aspetto' => 'carta', 'carta_tipo' => 'pdf', 'carta_pagine' => 2,
        'margini' => ['alto' => 45.0, 'basso' => 25.0, 'sinistro' => 20.0, 'destro' => 18.0], 'carta_illeggibile' => null])
        ->and(collect($campi)->only(['aspetto', 'carta_tipo', 'carta_pagine', 'margini', 'carta_illeggibile'])->all())->toBe(['aspetto' => 'campi', 'carta_tipo' => null, 'carta_pagine' => null,
            'margini' => null, 'carta_illeggibile' => null]);
});

it('una carta tornata a campi tiene il file: la pagina riceve il tipo, le pagine e i suoi margini, per tornare alla carta intestata senza caricarla di nuovo', function () {
    $carta = citCartaIntestata('pdf', ['aspetto' => 'campi']);
    $p = $this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];

    expect(collect(collect($p['carte'])->firstWhere('id', $carta->id))->only(['aspetto', 'carta_tipo', 'carta_pagine', 'margini', 'carta_illeggibile'])->all())
        ->toBe(['aspetto' => 'campi', 'carta_tipo' => 'pdf', 'carta_pagine' => 2, 'margini' => ['alto' => 45.0, 'basso' => 25.0, 'sinistro' => 20.0, 'destro' => 18.0],
            'carta_illeggibile' => null]);
});

it('dopo il caricamento la frase chiede la prova: «Prima di usarla» per una carta che nessuna stampa usa ancora; per una carta già usata (la predefinita, o scelta per un gruppo) le stampe escono già così', function () {
    $prima = fn ($m) => str_contains($m['message'] ?? '', 'Prima di usarla, aprila con «Prova la carta»') && ! str_contains($m['message'], 'escono già così');
    $giaCosi = fn ($m) => str_contains($m['message'] ?? '', 'Le stampe che la usano escono già così: aprila con «Prova la carta» e guarda dove cadono il condominio, il testo e il piede.')
        && ! str_contains($m['message'], 'Prima di usarla');
    $dati = fn (CartaStampa $c) => ['nome' => $c->nome, 'mostra_logo' => true, 'colore' => null, 'predefinita' => (bool) $c->predefinita, 'aspetto' => 'carta'] + CIT_MARGINI;

    citCarica($this, citFile(citPdf(), 'carta.pdf', 'application/pdf'), ['nome' => 'Carta nuova'])->assertSessionHas('message', $prima);
    citCarica($this, citFile(citPdf(), 'carta.pdf', 'application/pdf'), ['nome' => 'Carta nuova predefinita', 'predefinita' => true])->assertSessionHas('message', $giaCosi);
    $scelta = citCartaIntestata();
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $scelta->id), ['carta' => citFile(citPdf(), 'carta.pdf', 'application/pdf')] + $dati($scelta))
        ->assertSessionHas('message', $giaCosi);
    $nuova = CartaStampa::where('nome', 'Carta nuova')->sole();
    $this->actingAs($this->user)->put(route('impostazioni.stampe.carte.update', $nuova->id), ['carta' => citFile(citPdf(), 'carta.pdf', 'application/pdf')] + $dati($nuova))
        ->assertSessionHas('message', $prima);
});

it('la diffida stampata con la carta intestata: il PDF conservato ha la carta sotto, e l\'impronta del registro è la sua', function () {
    \Illuminate\Support\Facades\Queue::fake();
    cdsStudioPieno(true);
    $l = lttScenario($this);
    citCartaIntestata();
    $scelte = lttScelte($l, ['tipo' => 'diffida', 'persone' => [$l['persone']['ugo']->id], 'testo' => 'Il testo della diffida dello studio.']);

    $pdf = (string) $this->actingAs($this->user)->post(lttRotta($l, 'stampa'), $scelte)->assertOk()->getContent();
    $riga = lttRighe($l, 'ugo')->sole();
    $conservato = Storage::disk('local')->get(lttDocumentiDellaRiga($riga)[0]->path);

    // La lettera conservata e quella della risposta sono due stampe della stessa lettera: uguali al byte salvo la data del file e il suo
    // identificativo, che dicono il secondo in cui è stata scritta.
    $senzaOrologio = fn (string $p) => preg_replace(['#/(CreationDate|ModDate) \(D:[^)]*\)#', '#/ID \[<[0-9a-f]+> ?<[0-9a-f]+>\]#'], ['/$1 (D:)', '/ID []'], $p);
    expect(cdsTesto($pdf, 1))->toContain('CARTA PRIMA PAGINA')->toContain('Amministratore del Condominio')
        ->and(cdsTesto($conservato, 1))->toContain('CARTA PRIMA PAGINA')->toContain('Amministratore del Condominio')
        ->and($senzaOrologio($conservato))->toBe($senzaOrologio($pdf))
        ->and($riga->impronta)->toBe(hash('sha256', $conservato));
});

