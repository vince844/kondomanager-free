<?php

namespace App\Exceptions\Gestionale;

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\SpeseNegativeNelPiano;
use Exception;

/**
 * La generazione e il ricalcolo di un piano con una voce di spesa negativa si fermano (`SpeseNegativeNelPiano`).
 *
 * Il motore distribuisce una voce di spesa con il suo valore assoluto: a −€ 240,00 il piano chiede € 240,00. Ricalcolare senza dirlo
 * ripeterebbe la cifra sbagliata a ogni rigenerazione; si ferma prima di scrivere, nomina la voce e dice che cosa fare e che cosa costa. Il
 * messaggio è un capoverso solo: la pagina del piano lo mostra così com'è.
 */
class SpesaNegativaNelPianoException extends Exception
{
    /**
     * @param  list<array<string, mixed>>  $voci  le voci di `SpeseNegativeNelPiano::trova()`
     * @param  bool  $allaCreazione  il piano non è ancora nato: il modulo si rimanda dopo la correzione
     */
    public function __construct(public readonly PianoRate $pianoRate, public readonly array $voci, bool $allaCreazione = false)
    {
        $messaggio = ($allaCreazione ? 'Il piano non si crea' : 'Il piano non si ricalcola') . ': ' . SpeseNegativeNelPiano::testa($voci) . '.';

        if (($senzaVoci = SpeseNegativeNelPiano::spiegaIlPianoSenzaVoci($voci)) !== null) {
            $messaggio .= ' ' . $senzaVoci;
        }

        if (! $allaCreazione && $pianoRate->stato === StatoPianoRate::APPROVATO) {
            $messaggio .= ' Il piano è approvato: riportalo prima in bozza (' . SpeseNegativeNelPiano::COSTO_DELLA_BOZZA . ').';
        }

        foreach (SpeseNegativeNelPiano::frasiDiCorrezione($voci, $allaCreazione) as $frase) {
            $messaggio .= ' ' . $frase;
        }

        if ($allaCreazione) {
            $messaggio .= ' Poi rimanda il modulo.';
        }

        parent::__construct($messaggio);
    }

    public function report(): bool
    {
        return false;
    }
}
