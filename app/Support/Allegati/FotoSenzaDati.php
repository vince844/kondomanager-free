<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Allegati;

use App\Services\PDF\CartaIntestata;

/**
 * Decisione 531: il server toglie i dati della foto da ogni immagine degli allegati, **senza decodificarla** (i pixel restano byte per byte, e la memoria
 * che serve è quella del file, non quella dell'immagine aperta: il rischio della decisione 293 (4) per gli hosting condivisi non c'è). Il browser continua
 * a rimpicciolire la foto; quando non ci riesce, o quando la foto è piccola e parte com'è, i dati li toglie il server prima di salvarla. Dalla decisione
 * 535 vale per ogni caricamento del programma: la chiama l'ingresso delle richieste (`App\Http\Middleware\TogliIDatiDelleFoto`), e `AllegatoService`
 * per chi carica senza una richiesta.
 *
 * - **JPG**: il modo della carta intestata (`CartaIntestata::senzaDatiDellaFoto()`, decisione 254): via EXIF (la posizione, la data, il modello, la
 *   miniatura), XMP, IPTC, i commenti e quello che segue la fine dell'immagine.
 * - **PNG**: restano i blocchi che servono a disegnare l'immagine (e quelli critici, che un lettore deve capire); vanno `eXIf`, `tEXt`, `zTXt`, `iTXt`
 *   (dove sta l'XMP), `tIME` e i blocchi privati dei programmi.
 * - **WebP**: restano i blocchi dell'immagine (`VP8 `, `VP8L`, `VP8X`, `ALPH`, `ANIM`, `ANMF`, `ICCP`); vanno `EXIF`, `XMP ` e gli altri, e il `VP8X` non
 *   li promette più.
 *
 * In tutti e tre resta **solo il verso** della foto (il valore «Orientation» dell'EXIF, da 2 a 8), in un EXIF nuovo con quel valore soltanto: senza, una
 * foto scattata col telefono in verticale si vedrebbe coricata. Null se il file non ha la forma del suo tipo: chi chiama lo rifiuta, perché una foto che
 * non si sa pulire non si salva con i suoi dati.
 */
final class FotoSenzaDati
{
    public const TIPI = ['image/jpeg', 'image/png', 'image/webp'];

    /** I blocchi del PNG che non sono critici e servono a disegnare l'immagine (colori, trasparenza, densità, animazione). */
    private const PNG_DA_TENERE = ['tRNS', 'cHRM', 'gAMA', 'iCCP', 'sBIT', 'sRGB', 'cICP', 'mDCV', 'cLLI', 'cLLi', 'bKGD', 'hIST', 'pHYs', 'sPLT',
        'acTL', 'fcTL', 'fdAT', 'oFFs', 'pCAL', 'sCAL', 'sTER'];

    /** I blocchi del WebP che fanno l'immagine. */
    private const WEBP_DA_TENERE = ['VP8X', 'VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    private const FIRMA_PNG = "\x89PNG\r\n\x1A\n";

    public static function eUnaFoto(string $tipo): bool
    {
        return in_array($tipo, self::TIPI, true);
    }

    /** La frase del rifiuto di una foto che non si sa pulire. */
    public static function frase(string $nome): string
    {
        return "La foto «{$nome}» non si apre: il file è rovinato o incompleto. Salvala di nuovo dal telefono o dal computer, e caricala.";
    }

    /** I byte della foto senza i dati, con il solo verso; null se il file non ha la forma del tipo dato. Un tipo che non è una foto resta com'è. */
    public static function pulisci(string $byte, string $tipo): ?string
    {
        return match ($tipo) {
            'image/jpeg' => self::jpg($byte),
            'image/png' => self::png($byte),
            'image/webp' => self::webp($byte),
            default => $byte,
        };
    }

    private static function jpg(string $jpg): ?string
    {
        $verso = self::versoDelJpg($jpg);
        $pulito = CartaIntestata::senzaDatiDellaFoto($jpg);
        if ($pulito === null || $verso === 1) {
            return $pulito;
        }
        // L'EXIF con il solo verso subito dopo l'inizio dell'immagine, o dopo il JFIF se c'è (il JFIF vuole essere il primo).
        $dopo = 2;
        if (substr($pulito, 2, 2) === "\xFF\xE0") {
            $dopo = 4 + unpack('n', substr($pulito, 4, 2))[1];
        }
        $exif = "Exif\0\0" . self::tiffConIlVerso($verso);

        return substr($pulito, 0, $dopo) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($pulito, $dopo);
    }

    /** Il verso scritto nel primo EXIF del JPG, cercato nei segmenti prima dei dati dell'immagine; 1 se non c'è. */
    private static function versoDelJpg(string $jpg): int
    {
        $lunghezza = strlen($jpg);
        $posto = 2;
        while ($posto + 4 <= $lunghezza && $jpg[$posto] === "\xFF") {
            $marca = ord($jpg[$posto + 1]);
            if ($marca === 0xDA || $marca === 0xD9) {
                return 1;
            }
            $dati = unpack('n', substr($jpg, $posto + 2, 2))[1];
            if ($marca === 0xE1 && substr($jpg, $posto + 4, 6) === "Exif\0\0") {
                return self::versoDelTiff(substr($jpg, $posto + 10, max(0, $dati - 8)));
            }
            $posto += 2 + $dati;
        }

        return 1;
    }

    private static function png(string $png): ?string
    {
        if (! str_starts_with($png, self::FIRMA_PNG)) {
            return null;
        }
        $lunghezza = strlen($png);
        $posto = 8;
        $tenuti = [];
        $verso = 1;
        $fine = false;
        while ($posto + 12 <= $lunghezza) {
            $dati = unpack('N', substr($png, $posto, 4))[1];
            $tipo = substr($png, $posto + 4, 4);
            if ($dati > $lunghezza - $posto - 12 || ! preg_match('/^[A-Za-z]{4}$/', $tipo) || ($tenuti === [] && $tipo !== 'IHDR')) {
                return null;
            }
            $blocco = substr($png, $posto, 12 + $dati);
            $posto += 12 + $dati;
            if ($tipo === 'eXIf') {
                $verso = self::versoDelTiff(substr($blocco, 8, $dati));

                continue;
            }
            // Critici (la prima lettera maiuscola) o fra quelli che servono a disegnare l'immagine: restano; gli altri sono dati.
            if (ctype_upper($tipo[0]) || in_array($tipo, self::PNG_DA_TENERE, true)) {
                $tenuti[] = [$tipo, $blocco];
            }
            if ($tipo === 'IEND') {
                $fine = true;
                break;
            }
        }
        if (! $fine) {
            return null;
        }

        $fuori = self::FIRMA_PNG;
        $versoScritto = $verso === 1;
        foreach ($tenuti as [$tipo, $blocco]) {
            // L'EXIF con il solo verso prima dei dati dell'immagine, dove i lettori lo cercano.
            if (! $versoScritto && ($tipo === 'IDAT' || $tipo === 'IEND')) {
                $tiff = self::tiffConIlVerso($verso);
                $fuori .= pack('N', strlen($tiff)) . 'eXIf' . $tiff . pack('N', crc32('eXIf' . $tiff));
                $versoScritto = true;
            }
            $fuori .= $blocco;
        }

        return $fuori;
    }

    private static function webp(string $webp): ?string
    {
        if (strlen($webp) < 20 || ! str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
            return null;
        }
        $lunghezza = min(strlen($webp), 8 + unpack('V', substr($webp, 4, 4))[1]);
        $posto = 12;
        $tenuti = '';
        $vp8x = null;
        $verso = 1;
        $immagine = false;
        while ($posto + 8 <= $lunghezza) {
            $tipo = substr($webp, $posto, 4);
            $dati = unpack('V', substr($webp, $posto + 4, 4))[1];
            $occupa = 8 + $dati + ($dati % 2);
            if ($dati > $lunghezza - $posto - 8) {
                return null;
            }
            $blocco = substr($webp, $posto, min($occupa, $lunghezza - $posto));
            $posto += $occupa;
            if ($tipo === 'EXIF') {
                $verso = self::versoDelTiff(substr($blocco, 8, $dati));

                continue;
            }
            if (! in_array($tipo, self::WEBP_DA_TENERE, true)) {
                continue;
            }
            if ($tipo === 'VP8X') {
                if ($dati < 10 || $vp8x !== null) {
                    return null;
                }
                $vp8x = strlen($tenuti);
            }
            $immagine = $immagine || in_array($tipo, ['VP8 ', 'VP8L', 'ANMF'], true);
            $tenuti .= $blocco;
        }
        if (! $immagine) {
            return null;
        }
        if ($vp8x !== null) {
            // Il VP8X dice quali blocchi ci sono: via XMP (0x04) ed EXIF (0x08), che torna solo con il verso.
            $bandiere = (ord($tenuti[$vp8x + 8]) & ~0x0C) | ($verso !== 1 ? 0x08 : 0);
            $tenuti[$vp8x + 8] = chr($bandiere);
            if ($verso !== 1) {
                $tiff = self::tiffConIlVerso($verso);
                $tenuti .= 'EXIF' . pack('V', strlen($tiff)) . $tiff . (strlen($tiff) % 2 === 1 ? "\0" : '');
            }
        }
        // Un WebP semplice (senza VP8X) non può avere un EXIF: il verso, se c'era, non lo leggeva nessuno.

        return 'RIFF' . pack('V', 4 + strlen($tenuti)) . 'WEBP' . $tenuti;
    }

    /** Il verso (il valore «Orientation», tag 0x0112) nell'IFD0 di un TIFF dell'EXIF, anche con «Exif\0\0» davanti; 1 se non c'è o non si legge. */
    private static function versoDelTiff(string $tiff): int
    {
        if (str_starts_with($tiff, "Exif\0\0")) {
            $tiff = substr($tiff, 6);
        }
        $ordine = substr($tiff, 0, 2);
        if ($ordine !== 'II' && $ordine !== 'MM') {
            return 1;
        }
        [$n16, $n32] = $ordine === 'II' ? ['v', 'V'] : ['n', 'N'];
        if (strlen($tiff) < 8) {
            return 1;
        }
        $ifd = unpack($n32, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $voci = unpack($n16, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $voci; $i++) {
            $voce = $ifd + 2 + 12 * $i;
            if ($voce + 12 > strlen($tiff)) {
                return 1;
            }
            if (unpack($n16, substr($tiff, $voce, 2))[1] !== 0x0112) {
                continue;
            }
            $valore = unpack($n16, substr($tiff, $voce + 8, 2))[1];

            return unpack($n16, substr($tiff, $voce + 2, 2))[1] === 3 && $valore >= 1 && $valore <= 8 ? $valore : 1;
        }

        return 1;
    }

    /** Un TIFF dell'EXIF con una voce sola, il verso: 26 byte, senza la posizione, la data o il modello. */
    private static function tiffConIlVerso(int $verso): string
    {
        return 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('nn', $verso, 0) . pack('N', 0);
    }
}
