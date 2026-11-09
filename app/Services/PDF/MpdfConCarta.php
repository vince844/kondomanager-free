<?php

namespace App\Services\PDF;

use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Una stampa verticale con la carta intestata: quando si scrive, il PDF si rifà con FPDI sopra la carta, pagina per pagina, e la carta resta sotto il
 * testo. La prima pagina di ogni documento del PDF (la prima, e quelle dove il numero delle pagine ricomincia: la lettera, la ricevuta, l'avviso dopo
 * il primo) prende la prima pagina della carta, le altre la seconda (o la prima, se la carta ne ha una); una carta in immagine va sotto ogni pagina,
 * su tutto il foglio. Una stampa che comincia con la testatina (le parti del fascicolo) prende la seconda pagina della carta già dalla prima: la prima
 * pagina della carta è del frontespizio. Il titolo del documento si ricopia. Le pagine della carta arrivano senza i loro collegamenti, quelle della
 * stampa con i loro ({@see MpdfDellaCarta}).
 *
 * Il PDF si rifà in ogni modo in cui si scrive (una stringa, un file, la risposta al browser), così le impronte dei documenti conservati si
 * calcolano sul PDF che esce. Una carta che in quel momento non si disegna (il file cambiato sul disco dopo il controllo della stampa, un'immagine che
 * non si apre) non ferma la stampa: una riga nel registro, ed esce la stessa stampa con la carta a campi (lo studio lo scrive il programma, se ha il
 * nome).
 */
class MpdfConCarta extends Mpdf
{
    /**
     * @param  array<string, mixed>  $config  la configurazione di mPDF
     * @param  bool  $primaPaginaDellaCarta  falso: ogni pagina prende la pagina delle seguenti (le parti del fascicolo)
     * @param  \Closure(): Mpdf  $aCampi  la stessa stampa con la carta a campi, scritta da capo
     */
    public function __construct(array $config, private readonly CartaIntestata $carta, private readonly bool $primaPaginaDellaCarta,
        private readonly \Closure $aCampi)
    {
        parent::__construct($config);
    }

    public function cartaIntestata(): CartaIntestata
    {
        return $this->carta;
    }

    public function Output($name = '', $dest = '')
    {
        $contenuto = parent::Output('', Destination::STRING_RETURN);
        // Le pagine scritte (il loro contenuto, non compresso) non servono più: il PDF è in `$contenuto`. Su una stampa lunga è la memoria più
        // grande, e il PDF rifatto ne chiede altrettanta.
        $this->pages = [];

        // Le prime pagine dei documenti: la prima del PDF, e quelle da cui il numero ricomincia.
        $prime = [1];
        foreach ($this->PageNumSubstitutions as $cambio) {
            if ((string) ($cambio['reset'] ?? '') !== '') {
                $prime[] = (int) $cambio['from'];
            }
        }
        if (! $this->primaPaginaDellaCarta) {
            $prime = [];
        }

        try {
            // Un'immagine che non si apre è un errore, non un riquadro vuoto: la pagina uscirebbe senza lo studio.
            $rifatto = new MpdfDellaCarta(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
                'margin_header' => 0, 'margin_footer' => 0, 'showImageErrors' => true, 'tempDir' => CartaDiStampa::cartellaTemporanea()]);
            $rifatto->SetTitle((string) $this->title);
            $sfondo = $this->sfondo($rifatto);
            $pagine = $rifatto->setSourceFile(StreamReader::createByString($contenuto));
            unset($contenuto);
            for ($i = 1; $i <= $pagine; $i++) {
                $pagina = $rifatto->importPage($i);
                $rifatto->AddPage();
                $sfondo(in_array($i, $prime, true));
                $rifatto->useTemplate($pagina);
            }

            return $rifatto->Output($name, $dest);
        } catch (\Throwable $e) {
            Log::warning('La carta intestata non si disegna in stampa: la stampa esce a campi (lo studio lo scrive il programma, se ha il nome).',
                ['percorso' => $this->carta->percorso, 'errore' => mb_substr($e->getMessage(), 0, 500)]);
            unset($rifatto, $contenuto);

            return ($this->aCampi)()->Output($name, $dest);
        }
    }

    /** Come si disegna la carta sotto una pagina: la prima pagina della carta (`true`) o quella delle seguenti. */
    private function sfondo(MpdfDellaCarta $rifatto): \Closure
    {
        $carta = $this->carta;
        if ($carta->tipo === CartaIntestata::IMMAGINE) {
            return fn (bool $prima) => $rifatto->Image($carta->percorso, 0, 0, CartaIntestata::A4[0], CartaIntestata::A4[1], '', '', true, false);
        }
        $pagine = $rifatto->setSourceFile(StreamReader::createByFile($carta->percorso));
        $primaPagina = $rifatto->importaPaginaDellaCarta(1);
        $seguenti = $pagine >= 2 ? $rifatto->importaPaginaDellaCarta(2) : $primaPagina;

        return fn (bool $prima) => $rifatto->useTemplate($prima ? $primaPagina : $seguenti, 0, 0, CartaIntestata::A4[0], CartaIntestata::A4[1]);
    }
}
