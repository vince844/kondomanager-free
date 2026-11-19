<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le foto di prova con i dati della foto (decisioni 531 e 535): un JPG, un PNG e un WebP con la posizione N 45° 28' nell'EXIF, un XMP con un indirizzo,
 * e nel JPG un IPTC e un commento; con il verso, se lo si chiede. E le funzioni per leggere i pixel, il corpo di uno scaricamento e il verso. Lo usano
 * `FotoAllegatiSenzaDatiTest` e `FotoInOgniCaricamentoTest`. Non è un test: non prova niente da solo.
 */

use Illuminate\Testing\TestResponse;

/** I tre numeri della latitudine «N 45° 28' 0"», come li scrive un telefono: da cercare nei byte salvati. */
const FSD_LATITUDINE = "\x2D\x00\x00\x00\x01\x00\x00\x00\x1C\x00\x00\x00\x01\x00\x00\x00";

/** Un TIFF dell'EXIF in little endian: l'IFD0 con il verso (se dato) e il puntatore al GPS, poi l'IFD del GPS con la latitudine N 45° 28' 0". */
function fsdTiff(?int $verso = null): string
{
    $voci = $verso === null ? 1 : 2;
    $gps = 8 + 2 + 12 * $voci + 4;
    $razionali = $gps + 2 + 2 * 12 + 4;
    $t = 'II' . pack('v', 42) . pack('V', 8) . pack('v', $voci);
    if ($verso !== null) {
        $t .= pack('vvV', 0x0112, 3, 1) . pack('vv', $verso, 0);
    }
    $t .= pack('vvVV', 0x8825, 4, 1, $gps) . pack('V', 0);
    $t .= pack('v', 2) . pack('vvV', 0x0001, 2, 2) . "N\0\0\0" . pack('vvVV', 0x0002, 5, 3, $razionali) . pack('V', 0);

    return $t . pack('VV', 45, 1) . pack('VV', 28, 1) . pack('VV', 0, 1);
}

/** Un XMP con la posizione e un indirizzo, come lo scrivono i programmi delle foto. */
const FSD_XMP = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description exif:GPSLatitude="45,28N" photoshop:City="Via Roma 1, Milano"/></rdf:RDF></x:xmpmeta>';

/** L'immagine di prova, 16 × 16 a due colori, nel formato dato, come la scrive GD. */
function fsdImmagine(string $formato): string
{
    $img = imagecreatetruecolor(16, 16);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
    imagefilledrectangle($img, 0, 0, 7, 7, imagecolorallocate($img, 220, 40, 40));
    ob_start();
    match ($formato) {
        'jpg' => imagejpeg($img, null, 90),
        'png' => imagepng($img),
        'webp' => imagewebp($img, null, 90),
    };

    return (string) ob_get_clean();
}

/** Un JPG con l'EXIF della posizione (e il verso, se dato), un XMP, un IPTC e un commento, come quelli che arrivano da un telefono. */
function fsdJpg(?int $verso = null): string
{
    $jpg = fsdImmagine('jpg');
    $segmento = fn (string $marca, string $dati) => "\xFF" . $marca . pack('n', strlen($dati) + 2) . $dati;
    $dati = $segmento("\xE1", "Exif\0\0" . fsdTiff($verso))
        . $segmento("\xE1", "http://ns.adobe.com/xap/1.0/\0" . FSD_XMP)
        . $segmento("\xED", "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\x14\x1C\x02\x5A\0\x10Via Roma 1 Milano")
        . $segmento("\xFE", 'Scattata in Via Roma 1, Milano');

    return substr($jpg, 0, 2) . $dati . substr($jpg, 2);
}

/** Un blocco di un PNG, con il suo controllo. */
function fsdBloccoPng(string $tipo, string $dati): string
{
    return pack('N', strlen($dati)) . $tipo . $dati . pack('N', crc32($tipo . $dati));
}

/** Un PNG con `eXIf` (la posizione, e il verso se dato), il testo, l'XMP in `iTXt` e la data in `tIME`. */
function fsdPng(?int $verso = null): string
{
    $png = fsdImmagine('png');
    $dati = fsdBloccoPng('eXIf', fsdTiff($verso))
        . fsdBloccoPng('tEXt', "Comment\0Scattata in Via Roma 1, Milano")
        . fsdBloccoPng('zTXt', "Author\0\0" . gzcompress('Mario Rossi'))
        . fsdBloccoPng('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . FSD_XMP)
        . fsdBloccoPng('tIME', pack('nCCCCC', 2026, 11, 18, 10, 15, 0));

    // Dopo la firma (8 byte) e l'intestazione IHDR (25 byte).
    return substr($png, 0, 33) . $dati . substr($png, 33);
}

/** Un blocco di un WebP, con il byte di riempimento se la lunghezza è dispari. */
function fsdBloccoWebp(string $tipo, string $dati): string
{
    return $tipo . pack('V', strlen($dati)) . $dati . (strlen($dati) % 2 === 1 ? "\0" : '');
}

/** Un WebP esteso (VP8X) con i blocchi EXIF (la posizione, e il verso se dato) e XMP, come quelli di un telefono Android. */
function fsdWebp(?int $verso = null): string
{
    $semplice = fsdImmagine('webp');
    $vp8x = fsdBloccoWebp('VP8X', chr(0x08 | 0x04) . "\0\0\0" . substr(pack('V', 15), 0, 3) . substr(pack('V', 15), 0, 3));
    $corpo = 'WEBP' . $vp8x . substr($semplice, 12) . fsdBloccoWebp('EXIF', fsdTiff($verso)) . fsdBloccoWebp('XMP ', FSD_XMP);

    return 'RIFF' . pack('V', strlen($corpo)) . $corpo;
}

/** I pixel di un'immagine, letti da GD: due file con gli stessi pixel danno la stessa impronta. */
function fsdPixel(string $byte): string
{
    $img = imagecreatefromstring($byte);
    expect($img)->not->toBeFalse('L\'immagine non si apre');
    $impronta = '';
    for ($y = 0; $y < imagesy($img); $y++) {
        for ($x = 0; $x < imagesx($img); $x++) {
            $impronta .= pack('N', imagecolorat($img, $x, $y));
        }
    }

    return md5($impronta);
}

/** Il corpo di una risposta di scaricamento, che sia uno stream o un file. */
function fsdCorpo(TestResponse $r): string
{
    $base = $r->baseResponse;
    if ($base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        return (string) file_get_contents($base->getFile()->getPathname());
    }

    return $base instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? (string) $r->streamedContent() : (string) $r->getContent();
}

/** Il verso scritto nell'EXIF di un TIFF (1 se non c'è). */
function fsdVersoDelTiff(string $tiff): int
{
    $tiff = str_starts_with($tiff, "Exif\0\0") ? substr($tiff, 6) : $tiff;
    [$n16, $n32] = str_starts_with($tiff, 'MM') ? ['n', 'N'] : ['v', 'V'];
    $ifd = unpack($n32, substr($tiff, 4, 4))[1];
    $voci = unpack($n16, substr($tiff, $ifd, 2))[1];
    for ($i = 0; $i < $voci; $i++) {
        $voce = substr($tiff, $ifd + 2 + 12 * $i, 12);
        if (unpack($n16, substr($voce, 0, 2))[1] === 0x0112) {
            return unpack($n16, substr($voce, 8, 2))[1];
        }
    }

    return 1;
}

/** Un file caricato vero, con il nome e il contenuto dati. */
function fsdFile(string $nome, string $contenuto): \Illuminate\Http\UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'fsd');
    file_put_contents($tmp, $contenuto);

    return new \Illuminate\Http\UploadedFile($tmp, $nome, null, null, true);
}

/** I byte non hanno più i dati della foto di prova: né la latitudine, né l'indirizzo, né l'XMP. */
function fsdSenzaDati(string $byte): bool
{
    return ! str_contains($byte, FSD_LATITUDINE) && ! str_contains($byte, 'Via Roma') && ! str_contains($byte, 'GPSLatitude');
}
