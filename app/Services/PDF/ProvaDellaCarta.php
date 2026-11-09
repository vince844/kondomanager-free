<?php

namespace App\Services\PDF;

use App\Models\CartaStampa;
use App\Models\Condominio;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * «Prova la carta», dalle impostazioni delle stampe: un PDF con dati d'esempio, una lettera verticale e una pagina orizzontale con la testata a
 * campi della carta, il suo colore e il logo se la carta lo vuole. Il condominio, il destinatario e le cifre sono inventati, e il condominio ha
 * il nome lungo (60 caratteri) che mette alla prova la testata: nel PDF non entrano mai i dati di un condominio. Lo studio è quello delle
 * impostazioni, come nelle stampe.
 *
 * Le due pagine sono due stampe vere (la lettera, con i margini delle lettere, e una pagina della testata comune), fatte con la carta da provare e
 * unite con FPDI, come il fascicolo del rendiconto.
 */
final class ProvaDellaCarta
{
    /** Il condominio d'esempio: 60 caratteri, il nome lungo delle prove dello spazio della testata. */
    public const CONDOMINIO = 'Condominio di Prova Residenziale Parco delle Magnolie, Sud B';

    public const DESTINATARIO = 'Maria Esempio';

    public function __construct(private readonly PdfService $pdf) {}

    public function pdf(CartaStampa $carta): string
    {
        $condominio = new Condominio(['nome' => self::CONDOMINIO, 'indirizzo' => 'Via delle Magnolie 1', 'cap' => '00100', 'comune' => 'Roma',
            'provincia' => 'RM', 'codice_fiscale' => '99999999999']);

        $lettera = $this->pdf->generate('pdf.gestionale.lettera', [
            'gruppo_carta' => GruppoCarta::LETTERE,
            'carta_da_provare' => $carta,
            'condominio' => $condominio,
            'lettere' => [self::lettera($condominio)],
        ], ['margin_top' => 14])->Output('', Destination::STRING_RETURN);

        $orizzontale = $this->pdf->generate('pdf.prova_carta', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'carta_da_provare' => $carta,
            'condominio' => $condominio,
            'nome_della_carta' => $carta->nome,
            'titolo_stampa' => 'Prova della carta, con dati d\'esempio',
            'senza_firma' => true,
        ], ['orientation' => 'L', 'margin_top' => 32])->Output('', Destination::STRING_RETURN);

        $unito = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('framework/cache/mpdf')]);
        $unito->SetTitle('Prova della carta «'.$carta->nome.'»');
        foreach ([$lettera, $orizzontale] as $contenuto) {
            $pagine = $unito->setSourceFile(StreamReader::createByString($contenuto));
            for ($i = 1; $i <= $pagine; $i++) {
                $pagina = $unito->importPage($i);
                $misura = $unito->getTemplateSize($pagina);
                // Il foglio in verticale e l'orientamento a parte, come nel fascicolo: con «L» mPDF scambia larghezza e altezza del formato.
                $unito->AddPageByArray(['orientation' => $misura['orientation'],
                    'sheet-size' => [min($misura['width'], $misura['height']), max($misura['width'], $misura['height'])]]);
                $unito->useTemplate($pagina);
            }
        }

        return $unito->Output('', Destination::STRING_RETURN);
    }

    /**
     * La lettera d'esempio: un sollecito a una persona inventata, con due rate inventate, nella forma di `EmissioneLettere::voceDallaRiga()`. Il luogo
     * con la regola delle lettere vere: il comune dello studio, se c'è, altrimenti quello del condominio d'esempio.
     */
    private static function lettera(Condominio $condominio): array
    {
        return [
            'tipo' => 'sollecito',
            'titolo' => 'Sollecito',
            'etichetta' => 'PROVA',
            'persona' => ['nome' => self::DESTINATARIO, 'indirizzo' => 'Via dell\'Esempio 12, 00100 Roma'],
            'testo' => "Gentile ".self::DESTINATARIO.",\nrisultano da versare € 360,00. Le chiediamo di versare entro 15 giorni dal ricevimento di questa lettera.\n\nQuesta è una lettera d'esempio: il condominio, la persona e le cifre sono inventati.",
            'righe' => [
                ['unita' => 'Int. 1', 'numero_rata' => 2, 'piano' => 'Preventivo d\'esempio', 'scadenza' => '2026-03-31', 'da_versare' => 18000, 'ritardo' => '30 giorni'],
                ['unita' => 'Int. 1', 'numero_rata' => 3, 'piano' => 'Preventivo d\'esempio', 'scadenza' => '2026-04-30', 'da_versare' => 18000, 'ritardo' => '1 giorno'],
            ],
            'totale' => 36000,
            'alla' => '2026-05-01',
            'riga_del_termine' => null,
            'iban' => 'IT00X0000000000000000000000',
            'intestatario' => self::CONDOMINIO,
            'luogo' => EmissioneLettere::luogo($condominio),
            'emessa_il' => '01/05/2026',
        ];
    }
}
