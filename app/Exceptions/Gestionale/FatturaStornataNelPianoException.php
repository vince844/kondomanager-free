<?php

namespace App\Exceptions\Gestionale;

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use Exception;
use Illuminate\Support\Collection;

/**
 * Un piano da fatture che contiene una fattura stornata **non si ricalcola** (1.11.0-beta.35, R1 della Fase 1-bis).
 *
 * Fino alla beta.34 lo storno non guardava i piani: la fattura annullata restava in `piano_rate_fatture`, e il motore
 * legge le fatture del piano senza escludere le stornate. Ricalcolare avrebbe chiesto di nuovo ai condòmini una spesa
 * annullata. Da questa beta lo storno si rifiuta finché la fattura sta in un piano che non ha incassato
 * (`FatturaPassiva::motivoBloccoStorno()`); questa è la rete per i dati che esistono già.
 *
 * Il motore non la salta in silenzio: cambierebbe gli importi di un piano, magari approvato, senza che nessuno lo decida.
 * Si ferma, la nomina e dice la via.
 */
class FatturaStornataNelPianoException extends Exception
{
    /** @param Collection<int, FatturaPassiva> $stornate */
    public function __construct(public readonly PianoRate $pianoRate, public readonly Collection $stornate)
    {
        $nomi = $stornate->map(fn (FatturaPassiva $f) => 'n. ' . ($f->numero_documento ?? ('#' . $f->id)))->values();
        $quali = $nomi->count() === 1
            ? "la fattura {$nomi->first()}, che è stata stornata"
            : 'le fatture ' . $nomi->slice(0, -1)->implode(', ') . ' e ' . $nomi->last() . ', che sono state stornate';

        parent::__construct(sprintf(
            'Il piano «%s» contiene %s: ricalcolandolo, le rate %s chiederebbero ancora ai condòmini. '
            . 'Riporta il piano in bozza ed eliminalo, poi crealo di nuovo: nel carrello le fatture stornate non compaiono più.',
            $pianoRate->nome,
            $quali,
            $nomi->count() === 1 ? 'la' : 'le',
        ));
    }

    public function report(): bool
    {
        return false;
    }
}
