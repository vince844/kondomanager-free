<?php

namespace App\Enums\Fiscale;

/**
 * Come si accumula la soglia sotto la quale il versamento può essere rinviato.
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum PlafondRitenuta: string
{
    /**
     * Soglia 500€ — art. 25-ter, come riformato dal 2024.
     *
     * Il versamento è dovuto entro il 16 del mese successivo a quello in cui il **cumulo**
     * raggiunge 500 €; a prescindere dalla soglia si versa comunque entro il **16 giugno** e
     * il **16 dicembre**, e le ritenute operate a **dicembre** vanno entro il **16 gennaio**
     * successivo. Le finestre che ne discendono sono quindi tre: `gen–mag`, `giu–nov`, `dic`.
     *
     * ⚠️ **Corretto il 03/08/2026.** Qui c'era scritto «tre finestre annuali (gen-mag /
     * giu-set / ott-dic)»: il numero era giusto, i confini no. Implementate alla lettera,
     * quelle finestre avrebbero chiuso la seconda al 16 ottobre — una data che il dossier
     * normativo non prevede, e che avrebbe prodotto un versamento anticipato rispetto al
     * termine di legge. Fonte: circolare 9/E del 02/05/2024, ripresa in
     * `docs/design/f24_dossier_normativo.md`, che avverte anche di **non** implementare le
     * date 30/6 e 20/12.
     */
    case SOGLIA_500_TRE_FINESTRE = 'soglia_500_tre_finestre';

    /**
     * Soglia 100€ (D.Lgs. 1/2024) — artt. 25 e 25-bis.
     *
     * Sotto soglia il versamento si rinvia al mese successivo, ma si versa comunque entro il
     * **16 dicembre**: una sola data-limite annuale, non due semestrali come per il 4%.
     * Anche qui le ritenute di dicembre vanno entro il 16 gennaio.
     */
    case SOGLIA_100_ANNUALE = 'soglia_100_annuale';

    /** Nessun rinvio possibile: si versa ogni mese a prescindere dall'importo. */
    case MENSILE_SEMPRE = 'mensile_sempre';
}
