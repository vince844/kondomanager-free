<?php

namespace App\Services\PDF;

use Mpdf\Mpdf;
use setasign\Fpdi\PdfReader\PageBoundaries;

/**
 * Il mPDF che importa le pagine di una carta intestata in PDF: il controllo del caricamento ({@see CartaIntestata::controllaPdf()}) e la stampa
 * rifatta sopra la carta ({@see MpdfConCarta}). Le pagine della carta si importano senza i loro collegamenti: mPDF di suo legge le annotazioni con un
 * indirizzo (`/URI`, anche `javascript:`) di ogni pagina importata e le riscrive come collegamenti veri, e un collegamento invisibile su tutta la
 * pagina della carta finirebbe in ogni lettera, ricevuta ed estratto conto. Le pagine importate in altro modo (quelle della stampa) tengono i loro.
 */
final class MpdfDellaCarta extends Mpdf
{
    private bool $senzaCollegamenti = false;

    /** Una pagina della carta, dal TrimBox, senza i suoi collegamenti. */
    public function importaPaginaDellaCarta(int $numero): string
    {
        $this->senzaCollegamenti = true;
        try {
            return $this->importPage($numero, PageBoundaries::TRIM_BOX);
        } finally {
            $this->senzaCollegamenti = false;
        }
    }

    public function getImportedExternalPageLinks($pageNumber)
    {
        return $this->senzaCollegamenti ? [] : parent::getImportedExternalPageLinks($pageNumber);
    }
}
