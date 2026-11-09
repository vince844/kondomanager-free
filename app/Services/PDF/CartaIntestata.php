<?php

namespace App\Services\PDF;

use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\Filter\Ascii85;
use setasign\Fpdi\PdfParser\Filter\AsciiHex;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfReader\PdfReader;

/**
 * La carta intestata di una carta dello studio: il file sul disco (un PDF di una o due pagine, la prima e quella delle pagine seguenti, o
 * un'immagine A4) e i margini dove la carta lascia lo spazio al testo, in millimetri. Con questa carta le stampe verticali hanno la carta sotto ogni
 * pagina ({@see MpdfConCarta}); il programma non scrive lo studio, che è stampato sulla carta.
 *
 * I controlli del file, al caricamento e in stampa:
 *
 * - **Il PDF** fa la strada della stampa: FPDI (il lettore libero che mPDF porta con sé) apre il file, importa ogni pagina dal TrimBox (la carta di una
 *   tipografia ha spesso l'abbondanza: una pagina più grande con l'A4 dentro), la disegna in un mPDF di prova e lo scrive; si salva il PDF di prova, che
 *   ha solo le pagine, con i loro caratteri e le loro immagini come sono nel file arrivato (anche i dati che un'immagine porta con sé): non il
 *   JavaScript, gli allegati, le azioni, i collegamenti ({@see MpdfDellaCarta}). Prima, i rifiuti che FPDI non dà:
 *   - un flusso che, espanso, supera i 20 MB, letto a pezzi senza tenere in memoria l'espanso; si misurano i flussi che FPDI può espandere, non le
 *     immagini né i caratteri, che copia come sono; i filtri ASCII (ASCII85, esadecimale), che restringono, si tolgono prima di misurare;
 *   - il contenuto di una pagina oltre i 20 MB tutto insieme, letto con il lettore di FPDI: in più flussi FPDI li espande e li unisce in memoria, e un
 *     PDF di poche centinaia di KB costruito per far male fermerebbe la richiesta con un errore fatale;
 *   - un file «ibrido» (il trailer con /XRefStm: la tabella dei riferimenti e un flusso con gli oggetti in più, come lo scrive Word) dove un oggetto che
 *     la pagina usa sta solo nel flusso: FPDI legge la tabella e non lo troverebbe, e la carta uscirebbe senza (un carattere, un'immagine);
 *   - una pagina con un contenuto che FPDI non legge e sostituisce con un contenuto vuoto senza dirlo (un predittore in un secondo flusso).
 *   Una o due pagine A4 verticali, con 2 mm di tolleranza.
 * - **L'immagine**: solo JPG, A4 verticale (l'altezza 1,41 volte la larghezza, con il 2 % di tolleranza), larga almeno 1.240 pixel (un A4 a 150 punti
 *   per pollice), intera. Si salva senza aprirla né riscriverla, tolti i dati della foto ({@see senzaDatiDellaFoto()}: l'EXIF, i commenti, la
 *   miniatura; i pixel restano byte per byte): mPDF mette un JPG nel PDF senza decodificarlo, e la memoria di una stampa è quella del file
 *   (anche a 600 punti per pollice). Un PNG si rifiuta (mPDF lo decodifica, e una scansione in PNG pesa quattro volte); una foto con l'orientamento nei
 *   dati della foto diverso dal normale si rifiuta, perché mPDF non lo legge e la stamperebbe di traverso. In stampa si controlla che ci sia tutta: un
 *   file rovinato sul disco fa uscire la stampa a campi.
 *
 * Dentro un processo PHP non si mette un tetto di memoria a un pezzo di codice: un PDF che passa i controlli e chiede comunque troppo ferma la
 * richiesta, e il file non si salva.
 */
final class CartaIntestata
{
    public const PDF = 'pdf';

    public const IMMAGINE = 'immagine';

    /** Il rifiuto di un PDF che FPDI non legge, o che non passa i controlli prima di FPDI. */
    public const RIFIUTO = 'Questo PDF non si può usare come sfondo: è protetto, o è salvato in un formato che il programma non legge. Caricalo come immagine JPG, A4 verticale, larga almeno 1.240 pixel.';

    /** L'A4 verticale, in mm, e la tolleranza di una carta in PDF. */
    public const A4 = [210.0, 297.0];

    public const TOLLERANZA_MM = 2.0;

    /** Il contenuto espanso più grande di un flusso del PDF, e del contenuto di una pagina in più flussi tutti insieme. */
    public const TETTO_FLUSSO = 20 * 1024 * 1024;

    /** L'immagine: la larghezza minima (un A4 a 150 punti per pollice), la tolleranza sul rapporto dei lati. */
    public const LARGHEZZA_MINIMA = 1240;

    public const TOLLERANZA_RAPPORTO = 0.02;

    /** I pezzi in cui si legge un flusso compresso: un pezzo di 8 KB si espande al massimo di circa 8 MB. */
    private const PEZZO = 8192;

    public function __construct(
        public readonly string $percorso,
        public readonly string $tipo,
        public readonly int $pagine,
        public readonly float $alto,
        public readonly float $basso,
        public readonly float $sinistro,
        public readonly float $destro,
    ) {}

    /** Il rifiuto di un PDF come carta intestata, o null se si può usare. `$cartellaTemporanea`: dove mPDF scrive la sua cache. */
    public static function rifiutoDelPdf(string $percorso, string $cartellaTemporanea): ?string
    {
        return self::controllaPdf($percorso, $cartellaTemporanea)['rifiuto'];
    }

    /**
     * I controlli di un PDF: il rifiuto (o null), le pagine e, se si può usare, il PDF rifatto con le sole pagine (quello da salvare).
     *
     * @return array{rifiuto: ?string, pagine: int, pdf: ?string}
     */
    public static function controllaPdf(string $percorso, string $cartellaTemporanea): array
    {
        $rifiuto = ['rifiuto' => self::RIFIUTO, 'pagine' => 0, 'pdf' => null];
        $contenuto = @file_get_contents($percorso);
        if ($contenuto === false || ! str_starts_with(ltrim(substr($contenuto, 0, 1024)), '%PDF-') || ! self::flussiNelTetto($contenuto)) {
            return $rifiuto;
        }
        $ibrido = preg_match('/\/XRefStm\b/', $contenuto) === 1;
        unset($contenuto);

        try {
            $contenutiDelFile = self::contenutiDellePagine($percorso, $ibrido);
            if ($contenutiDelFile === null) {
                return $rifiuto;
            }
            $prova = new MpdfDellaCarta(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $cartellaTemporanea]);
            $pagine = $prova->setSourceFile(StreamReader::createByFile($percorso));
            if ($pagine < 1 || $pagine > 2) {
                return ['rifiuto' => "La carta intestata in PDF ha una pagina o due (la prima e quella delle pagine seguenti): questo PDF ne ha {$pagine}.", 'pagine' => $pagine, 'pdf' => null];
            }
            for ($i = 1; $i <= $pagine; $i++) {
                $pagina = $prova->importaPaginaDellaCarta($i);
                // Un contenuto che FPDI non legge lo sostituisce con uno vuoto senza dirlo: una pagina che nel file ha un contenuto e importata no.
                $importato = $prova->getImportedPages()[$pagina]['stream'] ?? null;
                if (($contenutiDelFile[$i - 1] ?? 0) > 0 && (! $importato instanceof PdfStream || $importato->getStream() === '')) {
                    return $rifiuto;
                }
                $misura = $prova->getTemplateSize($pagina);
                if (abs($misura['width'] - self::A4[0]) > self::TOLLERANZA_MM || abs($misura['height'] - self::A4[1]) > self::TOLLERANZA_MM) {
                    return ['rifiuto' => sprintf('La carta intestata va in A4 verticale (210 × 297 mm): la pagina %d di questo PDF è di %d × %d mm.',
                        $i, (int) round($misura['width']), (int) round($misura['height'])), 'pagine' => $pagine, 'pdf' => null];
                }
                $prova->AddPage();
                $prova->useTemplate($pagina);
            }
            $rifatto = $prova->Output('', Destination::STRING_RETURN);
        } catch (\Throwable) {
            return $rifiuto;
        }

        return ['rifiuto' => null, 'pagine' => $pagine, 'pdf' => $rifatto];
    }

    /**
     * Le pagine (le prime due: un PDF con di più si rifiuta dopo) lette con il lettore di FPDI, senza espandere niente: per ognuna quanti byte ha il
     * suo contenuto nel file. Null se una pagina non si usa: il suo contenuto, misurato a pezzi, oltre i 20 MB tutti insieme (in più flussi FPDI li
     * espande e li unisce in memoria; uno solo lo espande chi apre la stampa), o non misurabile; in un file ibrido, un oggetto che la pagina usa (le
     * risorse, il contenuto, e quello che citano) che non sta nella tabella dei riferimenti.
     *
     * @return list<int>|null
     */
    private static function contenutiDellePagine(string $percorso, bool $ibrido): ?array
    {
        $parser = new PdfParser(StreamReader::createByFile($percorso));
        $lettore = new PdfReader($parser);
        $contenuti = [];
        for ($i = 1, $quante = min(2, $lettore->getPageCount()); $i <= $quante; $i++) {
            $pagina = $lettore->getPage($i);
            $contenuto = PdfDictionary::get($pagina->getPageDictionary(), 'Contents');
            if ($ibrido && ! self::nellaTabella($parser, [$pagina->getAttribute('Resources'), $contenuto])) {
                return null;
            }
            $flussi = PdfType::resolve($contenuto, $parser);
            $flussi = $flussi instanceof PdfArray ? $flussi->value : [$flussi];
            $byte = 0;
            $espanso = 0;
            foreach ($flussi as $flusso) {
                $flusso = PdfType::resolve($flusso, $parser);
                if (! $flusso instanceof PdfStream) {
                    continue;
                }
                $dati = $flusso->getStream();
                $byte += strlen($dati);
                $filtri = array_values(array_map(fn (PdfName $f) => $f->value, array_filter($flusso->getFilters(), fn ($f) => $f instanceof PdfName)));
                $misura = self::espanso($dati, $filtri);
                if ($misura === null || ($espanso += $misura) > self::TETTO_FLUSSO) {
                    return null;
                }
                unset($dati);
            }
            $contenuti[] = $byte;
        }

        return $contenuti;
    }

    /** Ogni oggetto che i valori citano, e quelli che citano loro, sta nella tabella dei riferimenti (dove FPDI lo trova)? */
    private static function nellaTabella(PdfParser $parser, array $valori): bool
    {
        $tabella = $parser->getCrossReference();
        $visti = [];
        while ($valori !== []) {
            $valore = array_pop($valori);
            if ($valore instanceof PdfIndirectObjectReference) {
                if (isset($visti[$valore->value])) {
                    continue;
                }
                $visti[$valore->value] = true;
                if ($tabella->getOffsetFor($valore->value) === false) {
                    return false;
                }
                $valori[] = $parser->getIndirectObject($valore->value)->value;
            } elseif ($valore instanceof PdfIndirectObject || $valore instanceof PdfStream) {
                $valori[] = $valore->value;
            } elseif ($valore instanceof PdfDictionary || $valore instanceof PdfArray) {
                array_push($valori, ...array_values($valore->value));
            }
        }

        return true;
    }

    /**
     * Ogni flusso che FPDI può espandere, espanso, sta nei 20 MB? Si legge a pezzi, senza tenere in memoria l'espanso. Le immagini e i caratteri (i
     * flussi con /Length1) non si misurano: FPDI li copia come sono. Un flusso con un filtro che espande e che qui non si misura (LZW, o Flate dopo un
     * altro filtro che espande) non passa: non si sa quanto diventa.
     */
    private static function flussiNelTetto(string $pdf): bool
    {
        // Dove comincia ogni oggetto, «N G obj» con le cifre: il dizionario di un flusso comincia dopo l'ultimo prima di «stream». Gli oggetti si
        // cercano in avanti insieme ai flussi, senza tenerli tutti in una lista: un file di 10 MB fatto di «1 0 obj» ne ha più di un milione.
        $oggetti = ['ultimo' => null, 'prossimo' => self::fineDelProssimoOggetto($pdf, 0)];
        $da = 0;
        while (($posto = strpos($pdf, 'stream', $da)) !== false) {
            $da = $posto + 6;
            // «endstream» e le parole che finiscono con «stream» non sono l'inizio di un flusso: lo è «stream» dopo la fine di un dizionario.
            $prima = rtrim(substr($pdf, max(0, $posto - 200), min(200, $posto)));
            if (! str_ends_with($prima, '>>')) {
                continue;
            }
            $dati = $posto + 6 + (substr($pdf, $posto + 6, 2) === "\r\n" ? 2 : 1);
            $fine = strpos($pdf, 'endstream', $dati);
            if ($fine === false) {
                // Un flusso senza la fine: non c'è più niente da misurare, e il file lo rifiuta FPDI.
                break;
            }
            // Il flusso si salta tutto: nei suoi byte la parola «stream» non conta.
            $da = $fine + 9;
            $dizionario = self::dizionarioPrima($pdf, $posto, $oggetti);
            if (preg_match('#/Subtype\s*/Image\b|/Length1\b#', $dizionario) === 1) {
                continue;
            }
            $filtri = preg_match('#/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)#', $dizionario, $f) === 1 ? (preg_match_all('#/([A-Za-z0-9]+)#', $f[1], $n) ? $n[1] : []) : [];
            $misura = self::espanso(substr($pdf, $dati, $fine - $dati), $filtri);
            if ($misura === null || $misura > self::TETTO_FLUSSO) {
                return false;
            }
        }

        return true;
    }

    /**
     * Il dizionario del flusso che comincia a `$posto`: il testo fra «N G obj» dell'oggetto (un flusso è sempre un oggetto a sé), l'ultimo prima di
     * «stream», e «stream». `$oggetti`: dove finisce l'ultimo «N G obj» già passato e il prossimo; i flussi arrivano in ordine, e si va avanti.
     *
     * @param  array{ultimo: ?int, prossimo: ?int}  $oggetti
     */
    private static function dizionarioPrima(string $pdf, int $posto, array &$oggetti): string
    {
        while ($oggetti['prossimo'] !== null && $oggetti['prossimo'] <= $posto) {
            $oggetti['ultimo'] = $oggetti['prossimo'];
            $oggetti['prossimo'] = self::fineDelProssimoOggetto($pdf, $oggetti['prossimo']);
        }
        $inizio = $oggetti['ultimo'];

        return $inizio === null ? '' : substr($pdf, $inizio, $posto - $inizio);
    }

    /** Dove finisce il primo «N G obj» da `$da` in poi, o null se non ce n'è più. */
    private static function fineDelProssimoOggetto(string $pdf, int $da): ?int
    {
        return preg_match('/\b\d+\s+\d+\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE, $da) === 1 ? $m[0][1] + strlen($m[0][0]) : null;
    }

    /**
     * Quanto diventa un flusso con i suoi filtri, misurato a pezzi: i byte espansi (fermandosi oltre i 20 MB), o null se non si misura (un filtro che
     * espande e non si misura, un flusso rotto). Prima i filtri ASCII, che restringono, si tolgono.
     *
     * @param  list<string>  $filtri
     */
    private static function espanso(string $dati, array $filtri): ?int
    {
        while ($filtri !== [] && in_array($filtri[0], ['ASCII85Decode', 'A85', 'ASCIIHexDecode', 'AHx'], true)) {
            try {
                $dati = in_array(array_shift($filtri), ['ASCII85Decode', 'A85'], true) ? (new Ascii85())->decode($dati) : (new AsciiHex())->decode($dati);
            } catch (\Throwable) {
                return null;
            }
        }
        $espandono = array_values(array_intersect($filtri, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW']));
        if ($espandono === []) {
            return strlen($dati);
        }
        if (! in_array($filtri[0], ['FlateDecode', 'Fl'], true) || count($espandono) > 1) {
            return null;
        }

        return self::espansoAPezzi($dati);
    }

    /**
     * Il flusso Flate espanso a pezzi: i suoi byte (al più uno oltre i 20 MB: lì ci si ferma), o null se è rotto. Uno che finisce prima dei suoi byte
     * (un a capo prima di «endstream») si misura fino alla fine.
     */
    private static function espansoAPezzi(string $compresso): ?int
    {
        $flusso = inflate_init(ZLIB_ENCODING_DEFLATE);
        $espanso = 0;
        $lunghezza = strlen($compresso);
        for ($i = 0; $i < $lunghezza; $i += self::PEZZO) {
            $pezzo = @inflate_add($flusso, substr($compresso, $i, self::PEZZO), ZLIB_SYNC_FLUSH);
            if ($pezzo === false) {
                return null;
            }
            $espanso += strlen($pezzo);
            unset($pezzo);
            if ($espanso > self::TETTO_FLUSSO || inflate_get_status($flusso) === ZLIB_STREAM_END) {
                return $espanso;
            }
        }

        return $espanso;
    }

    /** Il rifiuto di un'immagine come carta intestata, o null se si può usare. L'immagine non si apre: si legge l'intestazione del file. */
    public static function rifiutoDellImmagine(string $percorso): ?string
    {
        $informazioni = @getimagesize($percorso);
        if ($informazioni === false) {
            return 'La carta intestata va caricata come PDF, o come immagine JPG.';
        }
        if ((int) $informazioni[2] !== IMAGETYPE_JPEG) {
            return 'Carica la carta come JPG, A4 verticale, larga almeno 1.240 pixel.';
        }
        // 1 è il verso normale; 2–8 una foto da girare o da specchiare (i valori fuori da 1–8 i programmi li leggono come il normale).
        if (in_array(ImmagineDiStampa::orientamento($percorso), [2, 3, 4, 5, 6, 7, 8], true)) {
            return 'La foto è girata: ruotala e salvala di nuovo, poi caricala.';
        }
        [$larghezza, $altezza] = [(int) $informazioni[0], (int) $informazioni[1]];
        $rapporto = self::A4[1] / self::A4[0];
        if ($larghezza <= 0 || abs($altezza / $larghezza - $rapporto) > $rapporto * self::TOLLERANZA_RAPPORTO) {
            return sprintf('L\'immagine della carta intestata va in A4 verticale (alta 1,41 volte la larghezza): questa è di %s × %s pixel.',
                number_format($larghezza, 0, ',', '.'), number_format($altezza, 0, ',', '.'));
        }
        if ($larghezza < self::LARGHEZZA_MINIMA) {
            return sprintf('L\'immagine della carta intestata va larga almeno 1.240 pixel (un A4 a 150 punti per pollice): questa è larga %s.', number_format($larghezza, 0, ',', '.'));
        }

        // Intera è la forma del JPG fino alla fine dell'immagine: quello che segue (le immagini in più di un MPF, un video) si toglie al salvataggio.
        return self::senzaDatiDellaFoto((string) file_get_contents($percorso)) !== null ? null : 'L\'immagine non si apre: caricala di nuovo come JPG.';
    }

    /**
     * Il JPG senza i dati della foto, senza riscriverlo: i segmenti con i dati (APP1: EXIF, con il luogo, la data, il modello e la miniatura, e XMP;
     * APP13: IPTC e Photoshop; COM: i commenti; APP0 «JFXX» e la miniatura del JFIF; APP2 diversi dal profilo dei colori, come MPF; gli altri APP3–APP15
     * tranne APP14 «Adobe») si tolgono, le tabelle e i dati dei pixel restano byte per byte, con il profilo dei colori (APP2 «ICC_PROFILE») e il segmento
     * «Adobe» (come leggere i colori). Quello che segue la fine dell'immagine (le immagini in più di un MPF, un video) si toglie. Null se il file non ha la
     * forma di un JPG, o se ha il nome del profilo dei colori fuori dal suo segmento: mPDF lo cerca in tutto il file, e lì girerebbe senza fine.
     */
    public static function senzaDatiDellaFoto(string $jpg): ?string
    {
        if (! str_starts_with($jpg, "\xFF\xD8")) {
            return null;
        }
        $fuori = "\xFF\xD8";
        $profili = [];
        $lunghezza = strlen($jpg);
        $posto = 2;
        $immagine = false;
        while ($posto + 1 < $lunghezza) {
            if ($jpg[$posto] !== "\xFF") {
                return null;
            }
            $marca = ord($jpg[$posto + 1]);
            if ($marca === 0xFF) {
                $posto++;

                continue;
            }
            if ($marca === 0xD9) {
                return $immagine ? self::conIProfiliAlLoroPosto($fuori."\xFF\xD9", $profili) : null;
            }
            if ($marca === 0x01 || ($marca >= 0xD0 && $marca <= 0xD7)) {
                $fuori .= substr($jpg, $posto, 2);
                $posto += 2;

                continue;
            }
            if ($posto + 4 > $lunghezza) {
                return null;
            }
            $dati = unpack('n', substr($jpg, $posto + 2, 2))[1];
            if ($dati < 2 || $posto + 2 + $dati > $lunghezza) {
                return null;
            }
            $segmento = substr($jpg, $posto, 2 + $dati);
            $contenuto = substr($segmento, 4);
            $posto += 2 + $dati;
            $tieni = match (true) {
                $marca === 0xE0 => str_starts_with($contenuto, "JFIF\x00") && strlen($contenuto) >= 14,
                $marca === 0xE2 => str_starts_with($contenuto, "ICC_PROFILE\x00") && strlen($contenuto) >= 14,
                $marca === 0xEE => str_starts_with($contenuto, 'Adobe'),
                $marca >= 0xE0 && $marca <= 0xEF, $marca === 0xFE => false,
                default => true,
            };
            if (! $tieni) {
                continue;
            }
            if ($marca === 0xE0) {
                // Il JFIF senza la sua miniatura: la densità resta, la miniatura è di 0 × 0.
                $segmento = "\xFF\xE0\x00\x10".substr($contenuto, 0, 12)."\x00\x00";
            }
            if ($marca === 0xE2) {
                $profili[] = strlen($fuori) + 4;
            }
            $fuori .= $segmento;
            if ($marca === 0xDA) {
                // I dati della scansione, fino alla prossima marca che non è un byte 0xFF scritto due volte né una marca di ripresa.
                $inizio = $posto;
                while (($posto = strpos($jpg, "\xFF", $posto)) !== false && $posto + 1 < $lunghezza) {
                    $dopo = ord($jpg[$posto + 1]);
                    if ($dopo === 0x00 || ($dopo >= 0xD0 && $dopo <= 0xD7)) {
                        $posto += 2;

                        continue;
                    }
                    if ($dopo === 0xFF) {
                        $posto++;

                        continue;
                    }
                    break;
                }
                if ($posto === false || $posto + 1 >= $lunghezza) {
                    return null;
                }
                $fuori .= substr($jpg, $inizio, $posto - $inizio);
                $immagine = true;
            }
        }

        return null;
    }

    /** Il JPG pulito, se il nome del profilo dei colori sta solo all'inizio dei segmenti del profilo. */
    private static function conIProfiliAlLoroPosto(string $jpg, array $profili): ?string
    {
        for ($da = 0; ($posto = strpos($jpg, "ICC_PROFILE\x00", $da)) !== false; $da = $posto + 1) {
            if (! in_array($posto, $profili, true)) {
                return null;
            }
        }

        return $jpg;
    }

    /**
     * Il file della carta in stampa si legge? Per il PDF lo stesso controllo del caricamento; per l'immagine la misura e il file intero. Un file cambiato
     * sul disco dopo il caricamento, o rovinato, non passa.
     */
    public static function siLegge(string $percorso, string $tipo, string $cartellaTemporanea): bool
    {
        if ($tipo === self::PDF) {
            return self::controllaPdf($percorso, $cartellaTemporanea)['rifiuto'] === null;
        }

        return $tipo === self::IMMAGINE && ImmagineDiStampa::misura($percorso) !== null && self::immagineIntera($percorso);
    }

    /**
     * L'immagine c'è tutta, senza aprirla? Un PNG ha i suoi pezzi fino a IEND, ognuno con il suo CRC; un JPG finisce con la marca di fine (FFD9). Un
     * file troncato (una copia interrotta, un disco rovinato) non passa.
     */
    private static function immagineIntera(string $percorso): bool
    {
        $byte = @file_get_contents($percorso);
        if ($byte === false) {
            return false;
        }
        if (str_starts_with($byte, "\xFF\xD8")) {
            return str_ends_with(rtrim($byte, "\x00\r\n\t "), "\xFF\xD9");
        }
        if (! str_starts_with($byte, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $lunghezza = strlen($byte);
        for ($posto = 8; $posto + 12 <= $lunghezza;) {
            $dati = unpack('N', substr($byte, $posto, 4))[1];
            if ($posto + 12 + $dati > $lunghezza || unpack('N', substr($byte, $posto + 8 + $dati, 4))[1] !== crc32(substr($byte, $posto + 4, 4 + $dati))) {
                return false;
            }
            if (substr($byte, $posto + 4, 4) === 'IEND') {
                return true;
            }
            $posto += 12 + $dati;
        }

        return false;
    }
}
