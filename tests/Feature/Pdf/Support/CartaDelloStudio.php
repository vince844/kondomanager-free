<?php

/**
 * Gli strumenti delle prove della carta dello studio sulle stampe: lo studio con tutti i dati e il logo, le pagine di un PDF lette dai flussi
 * di mPDF (senza poppler: la suite non ne dipende), una spia di `PdfService` che si ricorda ogni stampa.
 *
 * Le pagine si leggono così: ogni pagina ha il suo flusso del contenuto (compresso con Flate); il testo con i caratteri del progetto è scritto in
 * UTF-16 fra parentesi, dopo `BT x y Td`. La y è misurata dal bordo alto del foglio, in millimetri. Il piede è scritto sotto una traslazione
 * (`1 0 0 1 0 y cm`): la sua y non è quella del foglio, il suo testo sì.
 */

use App\Services\PDF\PdfService;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Storage;

/** Un nome di condominio di 60 caratteri: il caso lungo delle misure. */
const CDS_CONDOMINIO_LUNGO = 'Condominio Residenziale Parco dei Pini Marittimi Scala A e B';

/** I dati dello studio delle prove. */
const CDS_STUDIO = [
    'studio_nome' => 'Studio Bianchi Amministrazioni',
    'studio_indirizzo' => 'Via Garibaldi 10, 20121 Milano',
    'studio_partita_iva' => '01234567890',
    'studio_codice_fiscale' => 'BNCLRA80A01F205X',
    'studio_telefono' => '02 1234567',
    'studio_email' => 'studio@bianchi.it',
    'studio_pec' => 'studio@pec.bianchi.it',
    'studio_sito' => 'www.studiobianchi.it',
];

/** Un PNG senza trasparenza, largo e alto quanto si chiede, blu con una striscia bianca. */
function cdsPng(int $larghezza = 600, int $altezza = 300): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 58, 95));
    imagefilledrectangle($im, 0, (int) ($altezza / 2) - 4, $larghezza - 1, (int) ($altezza / 2) + 4, imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/**
 * Lo studio con tutti i dati, e con il logo (un PNG 600 × 300 sul disco dei file pubblici, finto) se si chiede. Il disco finto lo prepara chi
 * chiama, una volta per prova: farlo qui due volte cancellerebbe i file di prima.
 */
function cdsStudioPieno(bool $conLogo = true, array $altro = []): void
{
    $s = app(PrintSettings::class);
    foreach (array_merge(CDS_STUDIO, $altro) as $campo => $valore) {
        $s->{$campo} = $valore;
    }
    if ($conLogo) {
        Storage::disk('public')->put('settings/logo/logo-delle-prove.png', cdsPng());
        $s->logo_stampe_path = 'settings/logo/logo-delle-prove.png';
    }
    $s->save();
}

/** Lo studio senza nome (e quindi senza la carta dello studio); il logo, se si chiede, resta caricato. */
function cdsSenzaStudio(bool $conLogo = false): void
{
    $s = app(PrintSettings::class);
    foreach (array_keys(CDS_STUDIO) as $campo) {
        $s->{$campo} = null;
    }
    if ($conLogo) {
        Storage::disk('public')->put('settings/logo/logo-delle-prove.png', cdsPng());
        $s->logo_stampe_path = 'settings/logo/logo-delle-prove.png';
    }
    $s->save();
}

/** Gli oggetti di un PDF, per numero: il dizionario e quel che segue fino a `endobj`. */
function cdsOggetti(string $pdf): array
{
    $oggetti = [];
    foreach (preg_split('/\bendobj\b/', $pdf) as $pezzo) {
        if (preg_match('/(\d+) 0 obj\s*(.*)$/s', $pezzo, $m)) {
            $oggetti[(int) $m[1]] = $m[2];
        }
    }

    return $oggetti;
}

/** Il flusso di un oggetto, sciolto (Flate) se è compresso; il dizionario a parte. */
function cdsFlussoDi(string $oggetto): array
{
    if (! preg_match('/^<<(.*?)>>\s*stream\r?\n(.*)\r?\nendstream/s', $oggetto, $f)) {
        return ['', ''];
    }

    return [$f[1], str_contains($f[1], 'FlateDecode') ? (string) gzuncompress($f[2]) : $f[2]];
}

/** I nomi di un dizionario delle risorse (`/Font` o `/XObject`), scritto lì o in un oggetto a parte: [nome => numero dell'oggetto]. */
function cdsNomi(array $oggetti, string $dizionario, string $chiave): array
{
    if (preg_match('#/Resources\s+(\d+) 0 R#', $dizionario, $r)) {
        $dizionario = $oggetti[(int) $r[1]] ?? '';
    }
    $dentro = null;
    if (preg_match('#/'.$chiave.'\s*<<(.*?)>>#s', $dizionario, $x)) {
        $dentro = $x[1];
    } elseif (preg_match('#/'.$chiave.'\s+(\d+) 0 R#', $dizionario, $x) && preg_match('#<<(.*?)>>#s', $oggetti[(int) $x[1]] ?? '', $y)) {
        $dentro = $y[1];
    }
    $nomi = [];
    preg_match_all('#/([A-Za-z0-9_.+\-]+)\s+(\d+) 0 R#', (string) $dentro, $n, PREG_SET_ORDER);
    foreach ($n as $m) {
        $nomi[$m[1]] = (int) $m[2];
    }

    return $nomi;
}

/**
 * Come si legge il testo scritto con un carattere: i caratteri del progetto in un pezzo intero (`Type0`, Identity-H) hanno due byte per lettera, il
 * codice Unicode; quelli in un sottoinsieme di 255 lettere un byte, e la tabella `ToUnicode` del carattere dice quale lettera è.
 */
function cdsCarattere(array $oggetti, int $numero): array
{
    $font = $oggetti[$numero] ?? '';
    $mappa = [];
    if (preg_match('#/ToUnicode\s+(\d+) 0 R#', $font, $t)) {
        [, $cmap] = cdsFlussoDi($oggetti[(int) $t[1]] ?? '');
        $u = fn (string $hex) => mb_convert_encoding((string) hex2bin(str_pad($hex, (int) (ceil(strlen($hex) / 4) * 4), '0', STR_PAD_LEFT)), 'UTF-8', 'UTF-16BE');
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocchi);
        foreach ($blocchi[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $b, $c, PREG_SET_ORDER);
            foreach ($c as $m) {
                $mappa[hexdec($m[1])] = $u($m[2]);
            }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocchi);
        foreach ($blocchi[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $b, $c, PREG_SET_ORDER);
            foreach ($c as $m) {
                for ($k = hexdec($m[1]), $v = hexdec($m[3]); $k <= hexdec($m[2]); $k++, $v++) {
                    $mappa[$k] = mb_chr($v, 'UTF-8');
                }
            }
        }
    }

    $larghezze = [];
    $predefinita = 1000;
    if (preg_match('#/DescendantFonts\s*\[\s*(\d+) 0 R#', $font, $d)) {
        $cid = $oggetti[(int) $d[1]] ?? '';
        $predefinita = preg_match('#/DW\s+(\d+)#', $cid, $dw) ? (int) $dw[1] : 1000;
        $larghezze = cdsLarghezze($cid);
    }

    return ['due' => str_contains($font, '/Subtype /Type0'), 'mappa' => $mappa, 'larghezze' => $larghezze, 'predefinita' => $predefinita,
        'grassetto' => (bool) preg_match('#/BaseFont\s*/\S*Bold#', $font)];
}

/**
 * Le larghezze delle lettere di un carattere `CIDFontType2`, dal suo `/W`, in millesimi della misura: [codice => larghezza]. L'array ha due forme,
 * `c [w1 w2 …]` (una larghezza per codice da c in poi) e `c1 c2 w` (la stessa larghezza da c1 a c2). Con Identity-H il codice è la lettera Unicode.
 */
function cdsLarghezze(string $cid): array
{
    if (! preg_match('#/W\s*\[#', $cid, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    preg_match_all('/\[|\]|-?[\d.]+/', substr($cid, $m[0][1] + strlen($m[0][0])), $t);
    $larghezze = [];
    $numeri = [];
    $dentro = null;
    foreach ($t[0] as $x) {
        if ($x === '[') {
            $dentro = (int) array_pop($numeri);
        } elseif ($x === ']') {
            if ($dentro === null) {
                break;
            }
            $dentro = null;
        } elseif ($dentro !== null) {
            $larghezze[$dentro++] = (float) $x;
        } else {
            $numeri[] = $x;
            if (count($numeri) === 3) {
                for ($c = (int) $numeri[0]; $c <= (int) $numeri[1]; $c++) {
                    $larghezze[$c] = (float) $numeri[2];
                }
                $numeri = [];
            }
        }
    }

    return $larghezze;
}

/** La larghezza in punti dei byte di una stringa del flusso, scritti con un carattere a due byte alla misura data; null con un carattere a un byte. */
function cdsLarghezzaByte(string $byte, ?array $carattere, float $misura): ?float
{
    if ($carattere === null || ! $carattere['due']) {
        return null;
    }
    $millesimi = 0.0;
    foreach (str_split($byte, 2) as $coppia) {
        $codice = strlen($coppia) === 2 ? (ord($coppia[0]) << 8) | ord($coppia[1]) : 0;
        $millesimi += $carattere['larghezze'][$codice] ?? $carattere['predefinita'];
    }

    return $millesimi * $misura / 1000;
}

/** I byte di una stringa del flusso letti con il carattere in uso; le legature («ﬁ», «ﬀ», …) sciolte nelle loro lettere. */
function cdsLeggiByte(string $byte, ?array $carattere): string
{
    if ($carattere === null || $carattere['due']) {
        $testo = (string) mb_convert_encoding($byte, 'UTF-8', 'UTF-16BE');
    } else {
        $testo = '';
        foreach (str_split($byte) as $b) {
            $testo .= $carattere['mappa'][ord($b)] ?? chr(ord($b));
        }
    }

    return strtr($testo, ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl']);
}

/**
 * Quello che un flusso disegna: i pezzi di testo [testo, x, y in punti dal basso, colore del testo `rrggbb`, larghezza in punti (null con un carattere
 * a un byte), misura del carattere in punti, grassetto] e quante immagini. I moduli (`/Subtype /Form`, le pagine che FPDI importa nel fascicolo) si
 * leggono con le loro risorse.
 */
function cdsDisegnato(array $oggetti, string $flusso, string $dizionario, int $profondita = 0): array
{
    $font = cdsNomi($oggetti, $dizionario, 'Font');
    $xobject = cdsNomi($oggetti, $dizionario, 'XObject');
    $caratteri = [];
    $pezzi = [];
    $immagini = 0;
    $corrente = null;
    $misura = 0.0;
    $pezzo = null;
    $colore = '000000';
    preg_match_all('/\bBT\b|\bET\b|\/([A-Za-z0-9_.+\-]+)\s+(-?[\d.]+)\s+Tf|(-?[\d.]+)\s+(-?[\d.]+)\s+Td|\((?:\\\\.|[^\\\\)])*\)|<([0-9A-Fa-f\s]*)>|\/([A-Za-z0-9_.+\-]+)\s+Do\b|(?<![\d.])([\d.]+) ([\d.]+) ([\d.]+) rg\b|(?<![\d.] )(?<![\d.])([\d.]+) g\b/s', $flusso, $t, PREG_SET_ORDER);
    foreach ($t as $m) {
        $tutto = $m[0];
        if (($m[7] ?? '') !== '') {
            $colore = sprintf('%02x%02x%02x', (int) round((float) $m[7] * 255), (int) round((float) $m[8] * 255), (int) round((float) $m[9] * 255));
        } elseif (($m[10] ?? '') !== '') {
            $colore = str_repeat(sprintf('%02x', (int) round((float) $m[10] * 255)), 3);
        } elseif ($tutto === 'BT') {
            $pezzo = ['', null, null, $colore, 0.0, null, null];
        } elseif ($tutto === 'ET') {
            if ($pezzo !== null && $pezzo[0] !== '') {
                $pezzi[] = $pezzo;
            }
            $pezzo = null;
        } elseif (($m[1] ?? '') !== '') {
            $caratteri[$m[1]] ??= isset($font[$m[1]]) ? cdsCarattere($oggetti, $font[$m[1]]) : null;
            $corrente = $caratteri[$m[1]];
            $misura = (float) $m[2];
        } elseif (($m[3] ?? '') !== '') {
            if ($pezzo !== null && $pezzo[1] === null) {
                $pezzo[1] = (float) $m[3];
                $pezzo[2] = (float) $m[4];
            }
        } elseif ($tutto[0] === '(' || $tutto[0] === '<') {
            if ($pezzo !== null) {
                $byte = $tutto[0] === '(' ? stripcslashes(substr($tutto, 1, -1)) : (string) hex2bin((string) preg_replace('/\s+/', '', $m[5]));
                $pezzo[0] .= cdsLeggiByte($byte, $corrente);
                $larghezza = cdsLarghezzaByte($byte, $corrente, $misura);
                $pezzo[4] = $larghezza === null || $pezzo[4] === null ? null : $pezzo[4] + $larghezza;
                $pezzo[5] ??= $misura;
                $pezzo[6] ??= $corrente['grassetto'] ?? false;
            }
        } elseif (($m[6] ?? '') !== '') {
            $oggetto = $oggetti[$xobject[$m[6]] ?? -1] ?? '';
            if (str_contains($oggetto, '/Subtype /Image')) {
                $immagini++;
            } elseif ($profondita < 3 && str_contains($oggetto, '/Subtype /Form')) {
                [$diz, $dentro] = cdsFlussoDi($oggetto);
                $d = cdsDisegnato($oggetti, $dentro, $diz, $profondita + 1);
                array_push($pezzi, ...$d['pezzi']);
                $immagini += $d['immagini'];
            }
        }
    }

    return ['pezzi' => $pezzi, 'immagini' => $immagini];
}

/** Le pagine di un PDF: per ognuna, i pezzi di testo disegnati, le immagini e l'altezza del foglio in punti. */
function cdsFlussi(string $pdf): array
{
    $oggetti = cdsOggetti($pdf);
    $pagine = [];
    foreach ($oggetti as $corpo) {
        if (! preg_match('#^<<\s*/Type /Page\b(?!s)#', $corpo)) {
            continue;
        }
        preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $corpo, $box);
        preg_match('#/Contents (\d+) 0 R#', $corpo, $c);
        [, $flusso] = cdsFlussoDi($oggetti[(int) $c[1]] ?? '');
        $disegnato = cdsDisegnato($oggetti, $flusso, $corpo);
        $pagine[] = ['pezzi' => $disegnato['pezzi'], 'immagini' => $disegnato['immagini'], 'altezza' => (float) ($box[2] ?? 841.89)];
    }

    return $pagine;
}

/**
 * Le pagine di un PDF di mPDF: per ognuna, i pezzi di testo con la x e la y in millimetri (la y dal bordo alto del foglio).
 *
 * @return list<list<array{0: string, 1: float, 2: float}>>
 */
function cdsPagine(string $pdf): array
{
    return array_map(fn (array $p) => array_map(fn (array $x) => [$x[0], round((float) $x[1] / 72 * 25.4, 2), round(($p['altezza'] - (float) $x[2]) / 72 * 25.4, 2)], $p['pezzi']), cdsFlussi($pdf));
}

/** Il testo di una pagina (dalla 1), i pezzi uniti da uno spazio e gli spazi in fila uno solo. */
function cdsTesto(string $pdf, int $pagina = 1): string
{
    $pezzi = cdsPagine($pdf)[$pagina - 1] ?? [];

    return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_column($pezzi, 0))));
}

/**
 * Il testo sta in cima alla pagina (dalla 1)? Il primo pezzo che comincia con il testo ha la riga di base a non più di `$tolleranza` mm da
 * quella del pezzo più in alto della pagina (il piede, scritto sotto una traslazione, cade in fondo e non conta).
 */
function cdsInCima(string $pdf, int $pagina, string $testo, float $tolleranza = 6.0): bool
{
    $pezzi = array_values(array_filter(cdsPagine($pdf)[$pagina - 1] ?? [], fn ($x) => trim($x[0]) !== ''));
    $dove = cdsDove($pdf, $pagina, $testo);
    if ($pezzi === [] || $dove === null) {
        return false;
    }

    return $dove[1] - min(array_column($pezzi, 2)) <= $tolleranza;
}

/** Quante pagine ha il PDF. */
function cdsNumeroPagine(string $pdf): int
{
    return count(cdsFlussi($pdf));
}

/** La pagina (dalla 1) disegna un'immagine? Anche dentro una pagina importata (il fascicolo). */
function cdsHaImmagine(string $pdf, int $pagina = 1): bool
{
    return (cdsFlussi($pdf)[$pagina - 1]['immagini'] ?? 0) > 0;
}

/**
 * Le immagini disegnate nel flusso della pagina (dalla 1): per ognuna [larghezza, altezza, x, y dal bordo alto] in millimetri, dall'operatore
 * `cm` che mPDF scrive prima di `/I… Do`.
 *
 * @return list<array{0: float, 1: float, 2: float, 3: float}>
 */
function cdsImmagini(string $pdf, int $pagina = 1): array
{
    $oggetti = cdsOggetti($pdf);
    $n = 0;
    foreach ($oggetti as $corpo) {
        if (! preg_match('#^<<\s*/Type /Page\b(?!s)#', $corpo) || ++$n !== $pagina) {
            continue;
        }
        preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $corpo, $box);
        preg_match('#/Contents (\d+) 0 R#', $corpo, $c);
        [, $flusso] = cdsFlussoDi($oggetti[(int) $c[1]] ?? '');
        preg_match_all('#(-?[\d.]+) 0 0 (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) cm\s*/I\d+ Do#', $flusso, $m, PREG_SET_ORDER);
        $mm = fn ($punti) => round((float) $punti / 72 * 25.4, 2);

        return array_map(fn ($x) => [$mm($x[1]), $mm($x[2]), $mm($x[3]), $mm((float) ($box[2] ?? 841.89) - (float) $x[4] - (float) $x[2])], $m);
    }

    return [];
}

/** Il colore (`rrggbb`) del primo pezzo della pagina (dalla 1) che comincia con il testo, o null. */
function cdsColoreDi(string $pdf, int $pagina, string $testo): ?string
{
    foreach (cdsFlussi($pdf)[$pagina - 1]['pezzi'] ?? [] as $pezzo) {
        if (str_starts_with($pezzo[0], $testo)) {
            return $pezzo[3];
        }
    }

    return null;
}

/**
 * I filetti orizzontali della pagina (dalla 1) lunghi almeno `$minimo` mm, dall'alto in basso: per ognuno [y dal bordo alto, lunghezza, colore
 * `rrggbb`], in millimetri. mPDF scrive un bordo come una linea (`x y m x y l S`) con il colore del tratto (`r g b RG`) prima. Le pagine
 * importate con FPDI (il fascicolo, la prova della carta) sono moduli: si leggono dentro, nelle coordinate della pagina.
 *
 * @return list<array{0: float, 1: float, 2: string}>
 */
function cdsFiletti(string $pdf, int $pagina = 1, float $minimo = 100.0): array
{
    $oggetti = cdsOggetti($pdf);
    $n = 0;
    foreach ($oggetti as $corpo) {
        if (! preg_match('#^<<\s*/Type /Page\b(?!s)#', $corpo) || ++$n !== $pagina) {
            continue;
        }
        preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $corpo, $box);
        preg_match('#/Contents (\d+) 0 R#', $corpo, $c);
        [, $flusso] = cdsFlussoDi($oggetti[(int) $c[1]] ?? '');
        $filetti = cdsLinee($oggetti, $flusso, $corpo, (float) ($box[2] ?? 841.89), $minimo);
        usort($filetti, fn ($a, $b) => $a[0] <=> $b[0]);

        return $filetti;
    }

    return [];
}

/** Le linee orizzontali di un flusso e dei moduli che disegna. */
function cdsLinee(array $oggetti, string $flusso, string $dizionario, float $altezza, float $minimo, int $profondita = 0): array
{
    $mm = fn ($punti) => round((float) $punti / 72 * 25.4, 2);
    $moduli = cdsNomi($oggetti, $dizionario, 'XObject');
    $filetti = [];
    $colore = '000000';
    preg_match_all('#([\d.]+) ([\d.]+) ([\d.]+) RG\b|(-?[\d.]+) (-?[\d.]+) m\s+(-?[\d.]+) (-?[\d.]+) l\s+S\b|/([A-Za-z0-9_.+\-]+)\s+Do\b#', $flusso, $t, PREG_SET_ORDER);
    foreach ($t as $m) {
        if (($m[1] ?? '') !== '') {
            $colore = sprintf('%02x%02x%02x', (int) round((float) $m[1] * 255), (int) round((float) $m[2] * 255), (int) round((float) $m[3] * 255));
        } elseif (($m[8] ?? '') !== '') {
            $oggetto = $oggetti[$moduli[$m[8]] ?? -1] ?? '';
            if ($profondita < 3 && str_contains($oggetto, '/Subtype /Form')) {
                [$diz, $dentro] = cdsFlussoDi($oggetto);
                array_push($filetti, ...cdsLinee($oggetti, $dentro, $diz, $altezza, $minimo, $profondita + 1));
            }
        } elseif (abs((float) $m[5] - (float) $m[7]) < 0.01 && $mm(abs((float) $m[6] - (float) $m[4])) >= $minimo) {
            $filetti[] = [$mm($altezza - (float) $m[5]), $mm(abs((float) $m[6] - (float) $m[4])), $colore];
        }
    }

    return $filetti;
}

/** Dove sta, nella pagina (dalla 1), il primo pezzo che comincia con il testo: [x, y] in millimetri, o null. */
function cdsDove(string $pdf, int $pagina, string $testo): ?array
{
    foreach (cdsPagine($pdf)[$pagina - 1] ?? [] as $pezzo) {
        if (str_starts_with($pezzo[0], $testo)) {
            return [$pezzo[1], $pezzo[2]];
        }
    }

    return null;
}

/** Come `cdsDove()`, per una misura: il pezzo deve esserci, e se manca il test cade dicendo quale testo cercava (una differenza fra due null è zero). */
function cdsDoveObbligatorio(string $pdf, int $pagina, string $testo): array
{
    $dove = cdsDove($pdf, $pagina, $testo);
    \PHPUnit\Framework\Assert::assertNotNull($dove, "Il testo «{$testo}» non è nella pagina {$pagina}: la misura non si fa.");

    return $dove;
}

/**
 * Le righe della pagina (dalla 1), con dove finiscono: per ognuna [testo, x, fine, y dal bordo alto] in millimetri, la misura del carattere in punti
 * e se è in grassetto. La fine è la x più la larghezza del testo, sommata dalle larghezze delle lettere che il PDF scrive per il suo carattere
 * (`/W`); null per un carattere a un byte, che le stampe non usano.
 *
 * @return list<array{0: string, 1: float, 2: ?float, 3: float, 4: float, 5: bool}>
 */
function cdsRighe(string $pdf, int $pagina = 1): array
{
    $p = cdsFlussi($pdf)[$pagina - 1] ?? ['pezzi' => [], 'altezza' => 841.89];
    $mm = fn (float $punti) => round($punti / 72 * 25.4, 2);

    return array_map(fn (array $x) => [$x[0], $mm((float) $x[1]), $x[4] === null ? null : $mm((float) $x[1] + $x[4]), $mm($p['altezza'] - (float) $x[2]),
        (float) $x[5], (bool) $x[6]], $p['pezzi']);
}

/** La prima riga della pagina (dalla 1) che comincia con il testo, come in `cdsRighe()`; se manca, o la sua fine non si legge, il test cade. */
function cdsRiga(string $pdf, int $pagina, string $testo): array
{
    foreach (cdsRighe($pdf, $pagina) as $riga) {
        if (str_starts_with($riga[0], $testo)) {
            \PHPUnit\Framework\Assert::assertNotNull($riga[2], "La riga «{$riga[0]}» è scritta con un carattere a un byte: la sua fine non si legge.");

            return $riga;
        }
    }
    \PHPUnit\Framework\Assert::fail("Il testo «{$testo}» non è nella pagina {$pagina}: la misura non si fa.");
}

/**
 * Una spia di `PdfService` messa nel contenitore: si ricorda, per ogni stampa, la vista, i dati del chiamante, le opzioni, il documento mPDF, il
 * margine alto, quello basso e le pagine subito dopo la scrittura. Con `$conLaRete` ogni stampa si fa con il margine alto che si allunga sulla testata
 * (`setAutoTopMargin` a `stretch`, `autoMarginPadding` a 0): dopo `WriteHTML()` il margine alto del documento dice quanto è alta la testata
 * dell'ultima pagina, se è più alta del margine scelto.
 */
function cdsSpia(bool $conLaRete = false): PdfService
{
    $spia = new class($conLaRete) extends PdfService
    {
        /** @var list<array{vista: string, dati: array, opzioni: array, mpdf: \Mpdf\Mpdf, margine_alto: float, margine_basso: float, pagine: int}> */
        public array $stampe = [];

        public function __construct(private bool $conLaRete) {}

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            if ($this->conLaRete) {
                $config += ['setAutoTopMargin' => 'stretch', 'autoMarginPadding' => 0];
            }
            $mpdf = parent::generate($view, $data, $config);
            // Il margine alto e le pagine si leggono qui, prima che il chiamante chiuda il documento: chiuso, mPDF rimette il margine a quello della testata vuota.
            $this->stampe[] = ['vista' => $view, 'dati' => $data, 'opzioni' => $config, 'mpdf' => $mpdf, 'margine_alto' => $mpdf->tMargin, 'margine_basso' => $mpdf->bMargin,
                'pagine' => count($mpdf->pages)];

            return $mpdf;
        }
    };
    app()->instance(PdfService::class, $spia);

    return $spia;
}
