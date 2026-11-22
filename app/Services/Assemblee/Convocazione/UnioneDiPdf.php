<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Services\PDF\PdfService;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Più PDF in uno, pagina per pagina, ognuna con il suo formato e il suo orientamento (come il fascicolo del rendiconto, `FascicoloDelRendiconto`): gli avvisi
 * di ognuno in un PDF unico da stampare, e il fascicolo dell'assemblea con la copertina, l'avviso e i documenti dei punti. I PDF non si rifanno: si
 * copiano le pagine di quelli già fatti, così l'impronta di ognuno resta quella conservata.
 */
final class UnioneDiPdf
{
    /** @param list<string> $pdf i contenuti */
    public static function unisci(array $pdf): string
    {
        if (count($pdf) === 1) {
            return $pdf[0];
        }
        PdfService::alzaIlLimiteDiTempo();
        $unito = new Mpdf(['mode' => 'utf-8', 'format' => 'A4'] + PdfService::configurazioneFont());
        foreach ($pdf as $uno) {
            $n = $unito->setSourceFile(StreamReader::createByString($uno));
            for ($i = 1; $i <= $n; $i++) {
                $pagina = $unito->importPage($i);
                $misura = $unito->getTemplateSize($pagina);
                $unito->AddPageByArray(['orientation' => $misura['orientation'],
                    'sheet-size' => [min($misura['width'], $misura['height']), max($misura['width'], $misura['height'])]]);
                $unito->useTemplate($pagina);
            }
        }

        return $unito->Output('', Destination::STRING_RETURN);
    }

    /** Le pagine di un PDF. */
    public static function pagine(string $pdf): int
    {
        $m = new Mpdf(['mode' => 'utf-8', 'format' => 'A4'] + PdfService::configurazioneFont());

        return $m->setSourceFile(StreamReader::createByString($pdf));
    }
}
