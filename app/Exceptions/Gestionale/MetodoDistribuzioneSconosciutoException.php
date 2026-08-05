<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il piano rate dichiara un metodo di distribuzione del pregresso che il generatore non sa
 * trattare.
 *
 * Nasce nella beta.43 per chiudere un ramo che non c'era. La quota di saldo su ogni rata
 * usciva da un `if/elseif` senza terza via: con un valore fuori vocabolario nessuno dei due
 * rami scattava, la quota restava a zero su **ogni** rata e il pregresso spariva — senza
 * eccezione e senza log. Il lucchetto si chiudeva lo stesso e `gestioni.nota_saldo`
 * registrava un importo «processato» che non esisteva in nessuna quota: il saldo risultava
 * assorbito da un piano che non lo aveva addebitato a nessuno.
 *
 * **Non è un difetto attivo**: i valori ammessi sono tre e sono tutti gestiti. È la trappola
 * armata sotto la prossima modifica di quel file — e la politica del pregresso a due segni,
 * prevista per la 1.11, tocca esattamente lì. Meglio che chi aggiungerà un metodo lo scopra
 * da un'eccezione con scritto cosa fare, invece che da un saldo scomparso tre schermate dopo.
 *
 * Il posto in cui aggiungere il caso nuovo è uno solo, ed è nominato nel messaggio: chi legge
 * l'errore non deve andare a cercarlo.
 */
class MetodoDistribuzioneSconosciutoException extends Exception
{
    public function __construct(
        protected ?string $metodo,
        protected int $pianoRateId,
    ) {
        parent::__construct(sprintf(
            'Il piano rate #%d dichiara il metodo di distribuzione del pregresso «%s», che il '
            . 'generatore non sa trattare: i valori previsti sono prima_rata, tutte_rate e '
            . 'rata_zero. La generazione si ferma qui invece di produrre rate senza il '
            . 'pregresso — che è ciò che accadeva prima, in silenzio. Se il metodo è nuovo, va '
            . 'aggiunto al match in GenerateRateQuotesAction::execute().',
            $this->pianoRateId,
            $this->metodo ?? 'assente'
        ));
    }

    public function getMetodo(): ?string
    {
        return $this->metodo;
    }

    public function report(): bool
    {
        return false;
    }
}
