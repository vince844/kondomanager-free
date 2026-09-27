<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\PianoRate;
use Exception;

/**
 * Un piano da fatture che chiede per una fattura **più di quanto ne resta al netto delle note di credito del fornitore
 * collegate** non si ricalcola (Coda 165, 1.11.0-beta.36; decisione 26, punto 6).
 *
 * Il criterio è numerico, non «la fattura ha una nota»: un piano creato dal carrello dopo la nota chiede già il netto e si
 * ricalcola come sempre. Si ferma il piano nato prima della nota — i dati di prima di questa versione, o una nota
 * collegata a un piano che aveva incassato — perché ricalcolarlo chiederebbe di nuovo ai condòmini la parte annullata.
 *
 * Il motore non lo corregge da solo: cambierebbe gli importi di un piano, magari approvato, senza che nessuno lo decida.
 * Come `FatturaStornataNelPianoException`: si ferma, nomina fattura e nota, dice di quanto e la via.
 */
class FatturaRettificataNelPianoException extends Exception
{
    /**
     * @param  list<array{numero: string, note: string, netto: int, chiesto: int, piani: int}>  $eccedenze
     */
    public function __construct(public readonly PianoRate $pianoRate, public readonly array $eccedenze)
    {
        $frasi = array_map(fn (array $e) => sprintf(
            'per la fattura n. %s %s %s, ma al netto %s la fattura vale %s: ricalcolando, le rate chiederebbero %s più di quanto ne resta',
            $e['numero'],
            $e['piani'] > 1 ? 'i piani che la contengono chiedono' : 'il piano chiede',
            MoneyHelper::format($e['chiesto']),
            $e['note'],
            MoneyHelper::format($e['netto']),
            MoneyHelper::format($e['chiesto'] - $e['netto']),
        ), $eccedenze);

        // La via dipende da dove sta il piano, come nella scala dello storno (`FatturaPassiva::viaDalPiano`): a un piano in
        // bozza non si dice di riportarlo in bozza (testuale della Fase 1-bis).
        $stato = is_object($pianoRate->stato) ? $pianoRate->stato->value : $pianoRate->stato;
        $via = match (true) {
            $pianoRate->haRateEmesse() => 'Annulla le emissioni, riporta il piano in bozza ed eliminalo, poi crealo di nuovo',
            $stato === 'approvato' => 'Riporta il piano in bozza ed eliminalo, poi crealo di nuovo',
            default => 'Elimina il piano e crealo di nuovo',
        };

        parent::__construct(sprintf(
            'Il piano «%s» non si ricalcola: %s. %s: nel carrello la fattura compare al netto della nota.',
            $pianoRate->nome,
            implode('; ', $frasi),
            $via,
        ));
    }

    public function report(): bool
    {
        return false;
    }
}
