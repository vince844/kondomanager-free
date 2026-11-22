<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Models\Documento;
use App\Services\Assemblee\Convocazione\UnioneDiPdf;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\PdfService;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * La copia sottoscritta come PDF, per unirla alla lettera della trasmissione (V10) e per mostrarla nel browser: il PDF com'è; dal `.p7m` il PDF dentro la
 * busta (come la lettura delle fatture elettroniche, senza controllare la catena dei certificati: la firma la verifica chi riceve, sul file originale);
 * dall'immagine (la scansione in JPG o PNG) una pagina A4 con l'immagine. La PEC manda invece il file com'è, `.p7m` compreso.
 *
 * La busta `.p7m` si apre com'è nei modi in cui la fanno i programmi di firma: binaria (DER), in testo con le righe di inizio e fine (PEM), o in testo
 * base64 senza quelle righe; e una busta firmata due volte (la controfirma: `verbale.pdf.p7m.p7m`) si apre fino al PDF, al più {@see self::BUSTE} volte.
 */
final class PdfDellaCopia
{
    /** Quante buste una dentro l'altra si aprono al più. */
    public const BUSTE = 3;

    /** Quando il PDF della copia non si legge per unirlo alle lettere. */
    public const NON_SI_UNISCE = 'Questo PDF non si unisce alle lettere: caricalo come immagine (JPG o PNG) o salvalo di nuovo come PDF semplice.';

    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    /** Il PDF della copia, o null se il file non si legge. */
    public function daDocumento(Documento $documento): ?string
    {
        $contenuto = $this->archivio->leggi((string) $documento->path);
        if ($contenuto === null) {
            return null;
        }
        $nome = mb_strtolower((string) $documento->name . ' ' . (string) $documento->path);
        if (str_starts_with(ltrim($contenuto), '%PDF')) {
            return $contenuto;
        }
        if (str_contains($nome, '.p7m') || str_contains((string) $documento->mime_type, 'pkcs7')) {
            return self::pdfDalP7m($contenuto);
        }
        if (str_starts_with((string) $documento->mime_type, 'image/') || preg_match('/\.(jpe?g|png)$/', $nome) === 1) {
            return self::dallImmagine($contenuto, (string) $documento->mime_type);
        }

        return null;
    }

    /**
     * La copia caricata si apre come PDF (alla registrazione, prima che diventi la copia firmata): un PDF, una busta `.p7m` con un PDF dentro, o
     * un'immagine JPG o PNG che si legge. Un `.docx.p7m`, un file rovinato o un'immagine che non si apre no: l'errore arriverebbe solo alla stampa delle
     * lettere.
     */
    public static function siApreComePdf(string $contenuto, string $nome, string $mime): bool
    {
        $nome = mb_strtolower($nome);
        if (str_starts_with(ltrim($contenuto), '%PDF')) {
            return true;
        }
        if (str_ends_with($nome, '.p7m') || str_contains($mime, 'pkcs7')) {
            return self::pdfDalP7m($contenuto) !== null;
        }
        if (preg_match('/\.(jpe?g|png)$/', $nome) === 1 || str_starts_with($mime, 'image/')) {
            $info = @getimagesizefromstring($contenuto);

            return $info !== false && in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true);
        }

        return false;
    }

    /**
     * La copia caricata si unisce alle lettere (alla registrazione e alla sostituzione): le pagine del PDF, o del PDF dentro la busta `.p7m`, si leggono
     * con la libreria che le unisce. Un'immagine si converte in una pagina da sé e si unisce sempre. Un PDF compresso o rovinato che la libreria non legge
     * no: l'errore arriverebbe solo alla stampa delle lettere.
     */
    public static function siUnisce(string $contenuto, string $nome, string $mime): bool
    {
        $nome = mb_strtolower($nome);
        if (str_starts_with(ltrim($contenuto), '%PDF')) {
            $pdf = $contenuto;
        } elseif (str_ends_with($nome, '.p7m') || str_contains($mime, 'pkcs7')) {
            $pdf = self::pdfDalP7m($contenuto);
        } else {
            return true;
        }

        return $pdf !== null && self::siLegge($pdf);
    }

    /** Il PDF si legge con la libreria che unisce le pagine. */
    public static function siLegge(string $pdf): bool
    {
        try {
            return UnioneDiPdf::pagine($pdf) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Il PDF dentro una busta `.p7m`, aprendo le buste una dentro l'altra (al più {@see self::BUSTE}); null se non si apre o se dentro non c'è un PDF.
     */
    public static function pdfDalP7m(string $busta): ?string
    {
        $contenuto = $busta;
        for ($i = 0; $i < self::BUSTE; $i++) {
            $dentro = self::dalP7m($contenuto);
            if ($dentro === null) {
                return null;
            }
            if (str_starts_with(ltrim($dentro), '%PDF')) {
                return $dentro;
            }
            $contenuto = $dentro;
        }

        return null;
    }

    /**
     * Il contenuto dentro una busta CMS (`.p7m`), senza controllare i certificati; null se non si apre. La busta può essere binaria (DER), in PEM, o in
     * base64 senza le righe di inizio e fine.
     */
    public static function dalP7m(string $busta): ?string
    {
        $dentro = tempnam(sys_get_temp_dir(), 'p7m_in_');
        $fuori = tempnam(sys_get_temp_dir(), 'p7m_out_');
        try {
            $prove = [[$busta, OPENSSL_ENCODING_DER], [$busta, OPENSSL_ENCODING_PEM]];
            $base64 = preg_replace('/\s+/', '', $busta);
            if ($base64 !== null && $base64 !== '' && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64) === 1) {
                $decodificata = base64_decode($base64, true);
                if ($decodificata !== false && $decodificata !== '') {
                    $prove[] = [$decodificata, OPENSSL_ENCODING_DER];
                }
            }
            foreach ($prove as [$contenuto, $codifica]) {
                file_put_contents($dentro, $contenuto);
                @file_put_contents($fuori, '');
                $ok = @openssl_cms_verify($dentro, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $fuori, null, null, $codifica);
                if ($ok) {
                    $estratto = file_get_contents($fuori);

                    return $estratto === false || $estratto === '' ? null : $estratto;
                }
            }

            return null;
        } finally {
            @unlink($dentro);
            @unlink($fuori);
        }
    }

    /** Una pagina A4 con l'immagine della scansione, larga quanto la pagina. */
    private static function dallImmagine(string $contenuto, string $mime): string
    {
        PdfService::alzaIlLimiteDiTempo();
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8]
            + PdfService::configurazioneFont());
        $tipo = str_contains($mime, 'png') ? 'png' : 'jpeg';
        $pdf->WriteHTML('<img src="data:image/' . $tipo . ';base64,' . base64_encode($contenuto) . '" style="width: 100%;" />');

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
