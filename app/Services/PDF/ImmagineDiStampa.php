<?php

namespace App\Services\PDF;

/**
 * Le immagini che vanno su ogni stampa (il logo dello studio, la firma), riscritte al caricamento.
 *
 * mPDF mette un'immagine nel PDF una volta sola, ma un PNG con la trasparenza lo decodifica tutto in memoria a ogni documento: un logo di
 * 4.000 × 4.000 pixel con la trasparenza costa circa 200 MB e più di un secondo a ogni PDF, e con 128 MB di memoria la stampa si ferma con un
 * errore che nessun `try` prende. Per questo l'immagine si riscrive una volta, al caricamento, in PNG con il lato lungo al massimo 1.000 pixel (a
 * 14 mm di altezza sono più di 1.800 punti per pollice): così ridotta, anche con la trasparenza costa a ogni PDF qualche centesimo di secondo e
 * pochi MB. Un PNG con la trasparenza (il tipo di colore con l'alfa, o un colore trasparente nella tavolozza o in un PNG RGB) la tiene: una firma
 * o un logo senza sfondo, su una carta intestata colorata, non fanno un riquadro bianco. Gli altri escono in PNG RGB, senza. Un JPG si gira come
 * dice il suo orientamento (le foto del telefono).
 *
 * Prima di aprirla si legge la misura dall'intestazione del file, e un'immagine di più di 4 milioni di pixel si rifiuta con la frase del campo,
 * con qualunque memoria del server. Aprire un'immagine chiede a GD qualche byte per ogni pixel (i pixel letti e la sua tela, una riga alla volta):
 * con 4 milioni di pixel e le righe più care sono circa 46 MB per un PNG con l'alfa, e circa 61 MB per un PNG RGB con un colore trasparente, che
 * passa da una seconda tela della sua misura; la riscrittura sta in 128 MB con quelli che una richiesta ha già in uso (35 MB nella prova). Il logo
 * e la firma escono comunque a 1.000 pixel: 4 milioni (per esempio 2.000 × 2.000) bastano.
 */
final class ImmagineDiStampa
{
    /** I pixel più che si aprono: oltre, l'immagine si rifiuta prima di aprirla. */
    public const PIXEL_MASSIMI = 4_000_000;

    public const LATO_MASSIMO = 1000;

    /** La misura scritta nell'intestazione del file, senza aprire l'immagine: [larghezza, altezza], o null se il file non la dice. */
    public static function misura(string $percorso): ?array
    {
        $misura = @getimagesize($percorso);

        return $misura === false ? null : [(int) $misura[0], (int) $misura[1]];
    }

    /** Il motivo per cui l'immagine non si può usare prima di aprirla, o null se si può aprire. */
    public static function rifiuto(string $percorso): ?string
    {
        $misura = self::misura($percorso);
        if ($misura === null) {
            return 'Il file non è un\'immagine che il programma sappia leggere: caricala come PNG o JPG.';
        }
        if ($misura[0] * $misura[1] > self::PIXEL_MASSIMI) {
            return sprintf('L\'immagine è di %s × %s pixel, più di 4 milioni: è troppo grande. Riducila (bastano 1.000 pixel sul lato lungo) e caricala di nuovo.',
                number_format($misura[0], 0, ',', '.'), number_format($misura[1], 0, ',', '.'));
        }

        return null;
    }

    /**
     * Perché un'immagine già salvata pesa su ogni stampa, letto dall'intestazione del file senza aprirla: pesa se il lato lungo supera i 1.000
     * pixel (la riscritta di oggi non li supera); la trasparenza da sola no, perché così ridotta costa poco, e la riscritta la tiene. [la misura, se
     * ha la trasparenza, se supera i 4 milioni di pixel che il caricamento prende]. Null se il file non si legge come immagine, o se non pesa.
     *
     * @return array{misura: array{0: int, 1: int}, trasparente: bool, oltre_il_tetto: bool}|null
     */
    public static function pesante(string $percorso): ?array
    {
        $misura = self::misura($percorso);
        if ($misura === null || max($misura) <= self::LATO_MASSIMO) {
            return null;
        }

        return ['misura' => $misura, 'trasparente' => self::pngTrasparente($percorso), 'oltre_il_tetto' => $misura[0] * $misura[1] > self::PIXEL_MASSIMI];
    }

    /** Un PNG con la trasparenza: il tipo di colore con l'alfa (4, 6), o un blocco `tRNS` prima dei pixel. */
    private static function pngTrasparente(string $percorso): bool
    {
        $file = @fopen($percorso, 'rb');
        if ($file === false) {
            return false;
        }
        try {
            if (fread($file, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }
            while (strlen($testa = (string) fread($file, 8)) === 8) {
                ['lunghezza' => $lunghezza] = unpack('Nlunghezza', substr($testa, 0, 4));
                $tipo = substr($testa, 4, 4);
                if ($tipo === 'IHDR') {
                    $dati = (string) fread($file, $lunghezza);
                    if (in_array(ord($dati[9] ?? "\0"), [4, 6], true)) {
                        return true;
                    }
                    fseek($file, 4, SEEK_CUR);

                    continue;
                }
                if ($tipo === 'tRNS') {
                    return true;
                }
                if ($tipo === 'IDAT' || $tipo === 'IEND') {
                    return false;
                }
                fseek($file, $lunghezza + 4, SEEK_CUR);
            }

            return false;
        } finally {
            fclose($file);
        }
    }

    /**
     * L'immagine riscritta in PNG, con il lato lungo al massimo 1.000 pixel (una più piccola resta della sua misura). Un PNG con la trasparenza la
     * tiene (il PNG riscritto ha l'alfa: dove era trasparente resta trasparente); gli altri escono in PNG RGB, senza. Un JPG con l'orientamento nei
     * dati della foto si gira (e si specchia, per le foto specchiate) come lo mostra il telefono; si gira la riscritta, già ridotta, che chiede meno
     * memoria della foto intera.
     *
     * @throws \RuntimeException se l'immagine non si apre
     */
    public static function riscritta(string $percorso): string
    {
        $contenuto = @file_get_contents($percorso);
        $sorgente = $contenuto === false ? false : @imagecreatefromstring($contenuto);
        if ($sorgente === false) {
            throw new \RuntimeException('L\'immagine non si apre.');
        }
        unset($contenuto);
        $larghezza = imagesx($sorgente);
        $altezza = imagesy($sorgente);
        $scala = min(1, self::LATO_MASSIMO / max($larghezza, $altezza));
        $nuovaLarghezza = max(1, (int) round($larghezza * $scala));
        $nuovaAltezza = max(1, (int) round($altezza * $scala));

        // La tela si riempie con un rettangolo (il riempimento a macchia, `imagefill`, chiede una pila grande quanto la tela). Con la trasparenza la
        // tela è trasparente e i pixel si copiano con la loro alfa, senza fonderli con il fondo; senza, la tela è bianca.
        $trasparente = self::pngTrasparente($percorso);
        // Un PNG RGB con un colore trasparente (`tRNS`): GD lo apre con quel colore come «trasparente», che la riduzione non porta. Prima si copia su
        // una tela trasparente, che `imagecopy` riempie saltando i pixel di quel colore.
        if ($trasparente && imageistruecolor($sorgente) && imagecolortransparent($sorgente) !== -1) {
            $conAlfa = imagecreatetruecolor($larghezza, $altezza);
            imagealphablending($conAlfa, false);
            imagesavealpha($conAlfa, true);
            imagefilledrectangle($conAlfa, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocatealpha($conAlfa, 255, 255, 255, 127));
            imagecopy($conAlfa, $sorgente, 0, 0, 0, 0, $larghezza, $altezza);
            imagedestroy($sorgente);
            $sorgente = $conAlfa;
        }
        $riscritta = imagecreatetruecolor($nuovaLarghezza, $nuovaAltezza);
        imagealphablending($riscritta, ! $trasparente);
        imagesavealpha($riscritta, $trasparente);
        imagefilledrectangle($riscritta, 0, 0, $nuovaLarghezza - 1, $nuovaAltezza - 1,
            $trasparente ? imagecolorallocatealpha($riscritta, 255, 255, 255, 127) : imagecolorallocate($riscritta, 255, 255, 255));
        imagecopyresampled($riscritta, $sorgente, 0, 0, 0, 0, $nuovaLarghezza, $nuovaAltezza, $larghezza, $altezza);
        imagedestroy($sorgente);

        // L'orientamento della foto: 2 specchiata, 3 capovolta, 4 specchiata e capovolta (cioè specchiata dall'alto in basso), 5 specchiata e da
        // girare di 90 gradi in senso antiorario, 6 da girare di 90 gradi in senso orario, 7 specchiata e da girare in senso orario, 8 da girare in
        // senso antiorario. Prima si specchia, poi si gira (`imagerotate` gira in senso antiorario).
        $orientamento = self::orientamento($percorso);
        if (in_array($orientamento, [2, 4, 5, 7], true)) {
            imageflip($riscritta, $orientamento === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angolo = [3 => 180, 5 => 90, 6 => 270, 7 => 270, 8 => 90][$orientamento] ?? 0;
        if ($angolo !== 0) {
            $girata = imagerotate($riscritta, $angolo, 0);
            if ($girata !== false) {
                imagedestroy($riscritta);
                $riscritta = $girata;
            }
        }

        ob_start();
        imagepng($riscritta, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($riscritta);

        return $png;
    }

    /** L'orientamento scritto nei dati di un JPG (1 se non c'è, o se il file non è un JPG). */
    public static function orientamento(string $percorso): int
    {
        if (! function_exists('exif_read_data') || (@getimagesize($percorso)[2] ?? null) !== IMAGETYPE_JPEG) {
            return 1;
        }
        $dati = @exif_read_data($percorso);

        return is_array($dati) ? (int) ($dati['Orientation'] ?? 1) : 1;
    }
}
