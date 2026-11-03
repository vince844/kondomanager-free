<?php

namespace App\Services\Riparto;

/**
 * Il gradino della cascata di D3 che ha deciso il periodo di competenza di una spesa.
 *
 * Il valore è la stringa che finirà in `righe_riparto.gradino_competenza` (decisione 15) e in
 * legenda sulle stampe: si mostra e si congela, non si ricalcola. L'ordine dei casi è l'ordine
 * della cascata.
 */
enum GradinoCompetenza: string
{
    /** Competenza dichiarata sulla fattura o sulla copertura — il primo gradino, comune alle due nature. */
    case Dichiarata = 'dichiarata';

    /** `piani_rate.data_delibera_assemblea` come periodo lungo un giorno — solo straordinaria. */
    case Delibera = 'delibera';

    /** I tratti di `competenze_capitolo` — solo ordinaria (decisione 20). */
    case Capitolo = 'capitolo';

    /** `gestioni.data_inizio`/`data_fine`, se la gestione è chiusa — solo ordinaria. */
    case Gestione = 'gestione';

    /** `esercizi.data_inizio`/`data_fine` — il fondo della cascata ordinaria. */
    case Esercizio = 'esercizio';
}
