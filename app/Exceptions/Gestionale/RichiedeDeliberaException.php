<?php

namespace App\Exceptions\Gestionale;

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use Exception;

/**
 * Lo straordinario senza data della delibera **si ferma** (decisione 12 del progetto
 * `docs/subentro_e_competenza_temporale.md`).
 *
 * L'obbligazione per le opere straordinarie sorge con la delibera che approva lavori e prezzo
 * (Cass. 24654/2010, 11199/2021, 24236/2025): è di chi era titolare **quel giorno**. Senza quella data,
 * e senza una competenza dichiarata sulla fattura, il motore non scende al periodo della gestione — che
 * darebbe un pro rata per giorni, l'opposto della funzione a gradino — e non tira a indovinare. Chi la
 * riceve rimanda al campo della data del piano, o alla competenza della fattura.
 */
class RichiedeDeliberaException extends Exception
{
    public function __construct(public readonly PianoRate $pianoRate, public readonly ?FatturaPassiva $fattura = null)
    {
        parent::__construct(match (true) {
            // Una pregressa non si modifica mai (`FatturaPassivaService::motivoBloccoModifica`): mandarla a «modifica» era
            // un vicolo cieco, e il cancello della decisione 26 diceva già un'altra cosa (R20 della Fase 1-bis, 1.11.0-beta.35).
            $pianoRate->tipo_autorizzazione === 'urgenza' && $fattura !== null && $fattura->is_pregresso => sprintf(
                'Il piano «%s» è un intervento d\'urgenza (nessuna delibera da registrare) e la pregressa %s non dichiara il periodo in cui il costo è maturato: il programma non sceglie al posto tuo a chi spetta. Una pregressa non si modifica: si storna. Se il piano esiste già, riportalo in bozza ed eliminalo; poi storna la fattura, registrala di nuovo con il periodo e crea il piano.',
                $pianoRate->nome,
                $fattura->numero_documento ?? ('#' . $fattura->id),
            ),
            // Con «Urgenza» non c'è una delibera da scrivere nel piano: la porta giusta è la competenza sulla fattura (S8-32).
            $pianoRate->tipo_autorizzazione === 'urgenza' && $fattura !== null => sprintf(
                'Il piano «%s» è un intervento d\'urgenza (nessuna delibera da registrare) e la fattura %s non dichiara un periodo di competenza. Dichiara il periodo di competenza sulla fattura (Movimenti → fatture → modifica, «Costo maturato» o «Spesa deliberata il»): decide a chi spetta la spesa, e il programma non la sceglie al posto tuo. Se il piano esiste già, riportalo prima in bozza; se la fattura è già pagata, la competenza si dichiara con storno e nuova registrazione.',
                $pianoRate->nome,
                $fattura->numero_documento ?? ('#' . $fattura->id),
            ),
            // Senza data della delibera la via è scriverla; «la competenza sulla fattura» non vale per una pregressa, che
            // non si modifica (verifica delle correzioni della Fase 1-bis, 1.11.0-beta.35).
            $fattura !== null && $fattura->is_pregresso => sprintf(
                'Il piano straordinario «%s» non ha la data della delibera che ha approvato lavori e prezzo, e la pregressa %s non dichiara il periodo in cui il costo è maturato. Scrivi la data della delibera nel piano: decide a chi spetta la spesa, e il programma non la sceglie al posto tuo. Una pregressa non si modifica: per ripartirla sul periodo in cui il costo è maturato si storna e si registra di nuovo.',
                $pianoRate->nome,
                $fattura->numero_documento ?? ('#' . $fattura->id),
            ),
            $fattura !== null => sprintf(
                'Il piano straordinario «%s» non ha la data della delibera che ha approvato lavori e prezzo, e la fattura %s non dichiara un periodo di competenza. Scrivi la data della delibera nel piano (o la competenza sulla fattura): decide a chi spetta la spesa, e il programma non la sceglie al posto tuo.',
                $pianoRate->nome,
                $fattura->numero_documento ?? ('#' . $fattura->id),
            ),
            default => sprintf(
                'Il piano «%s» ripartisce una gestione straordinaria e non ha la data della delibera che ha approvato lavori e prezzo. Scrivi la data della delibera nel piano: decide a chi spetta la spesa, e il programma non la sceglie al posto tuo.',
                $pianoRate->nome,
            ),
        });
    }

    public function report(): bool
    {
        return false;
    }
}
