<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

    /**
     * Se il cumulo delle ritenute fa scattare il versamento, con il confronto che la legge dà a questo regime (Coda 717).
     *
     * - **Soglia € 100,00**: si rinvia finché il cumulo «non supera» il limite (art. 9, c. 4, D.Lgs. 1/2024; dal 1° gennaio 2027
     *   art. 32, c. 1, lett. a), D.Lgs. 33/2025): € 100,00 esatti si rinviano ancora, il versamento scatta **oltre**.
     * - **Soglia € 500,00**: si versa quando il cumulo «raggiunga» il limite (art. 25-ter, c. 2-bis, DPR 600/1973; dal 1° gennaio
     *   2027 art. 40, c. 3, D.Lgs. 33/2025): € 500,00 esatti fanno già scattare il versamento.
     * - **Mensile**: nessuna soglia, il versamento scatta sempre.
     *
     * Il confronto sta qui e non nel calcolo: fino alla 1.12.0-beta.13 il calcolo usava `>=` per tutti e due i regimi, e € 100,00
     * esatti di un professionista andavano al 16 del mese dopo invece di rinviarsi.
     *
     * @param  int  $cumulo  le ritenute accumulate, in centesimi
     * @param  int  $soglia  la soglia del regime, in centesimi (`config('fiscale.plafond')`)
     */
    public function superaLaSoglia(int $cumulo, int $soglia): bool
    {
        return match ($this) {
            self::SOGLIA_100_ANNUALE => $cumulo > $soglia,
            self::SOGLIA_500_TRE_FINESTRE => $cumulo >= $soglia,
            self::MENSILE_SEMPRE => true,
        };
    }
}
