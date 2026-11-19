// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 686 (e), decisioni 506 e 510: il piano che verrebbe a € 0,00 lo dice prima di salvare.
 *
 * Come nasce oggi a zero (la prova della domanda del forum del 18/11/2026, caso «A bis» con «conguaglio di fine anno»): la voce della gestione è sforata e lo sforo è
 * rimandato al conguaglio, il menu delle voci la spegne («Interamente finanziato/Esaurito»), la pagina manda il piano senza voci, e il server prende «tutte
 * le voci libere» — cioè la voce esaurita — e ripartisce € 0,00 a ciascuno, senza dire niente. Lo stesso con le voci scelte e gli importi a zero, o con
 * le fatture scelte e gli importi a zero.
 *
 * Non scatta quando la gestione ha saldi pregressi da mettere nel piano (la rata dei saldi non è a zero), mentre la pagina carica, o senza una scelta da
 * giudicare (nessuna gestione; nessuna fattura scelta: lì il pulsante dice già «Scegli almeno una fattura per salvare»). I conti in centesimi, con la stessa
 * conversione del totale della pagina (`centesimiDaImporto`, `totaliDaImportiInEuro`).
 */

import { centesimiDaImporto, totaliDaImportiInEuro, type VoceDiUnCampo } from '@/lib/gestionale/pianiRate/totaliPerTipo';

export interface StatoDelPianoNuovo {
    tipo: string;
    /** Il nome della gestione scelta; senza, non c'è niente da giudicare. */
    gestione: string | null;
    caricamento: boolean;
    /** La gestione ha saldi pregressi che il piano prende. */
    conSaldi: boolean;
    /** Le voci del menu «Voci di bilancio incluse», come le manda il server. */
    vociDisponibili: { disabled: boolean; residuo?: number | null }[];
    /** Le voci scelte con l'importo da richiedere (vuoto: il piano prende tutte le voci libere). */
    vociScelte: VoceDiUnCampo[];
    /** Le fatture scelte nel carrello, con l'importo da mettere a rate. */
    fattureScelte: { importo_suggerito: string | number | null | undefined }[];
}

/** La frase che dice perché il piano verrebbe a € 0,00, o `null` se non ci verrebbe. */
export function motivoPianoAZero(stato: StatoDelPianoNuovo): string | null {
    if (!stato.gestione || stato.caricamento || stato.conSaldi) return null;

    if (stato.tipo === 'straordinario') {
        if (stato.fattureScelte.length === 0) return null;
        const totale = stato.fattureScelte.reduce((somma, f) => somma + centesimiDaImporto(f.importo_suggerito), 0);

        return totale > 0 ? null : 'Il piano verrebbe a € 0,00: le fatture scelte hanno l\'importo da mettere a rate a zero. Scrivi quanto chiedere per ognuna.';
    }

    if (stato.vociScelte.length > 0) {
        return totaliDaImportiInEuro(stato.vociScelte).daRipartire > 0 ? null
            : 'Il piano verrebbe a € 0,00: le voci scelte non chiedono niente. Scrivi l\'importo da richiedere per ognuna, o scegli altre voci.';
    }

    const qualcosaDaChiedere = stato.vociDisponibili.some((v) => !v.disabled && (v.residuo ?? 0) > 0);

    return qualcosaDaChiedere ? null
        : `Il piano verrebbe a € 0,00: nessuna voce di «${stato.gestione}» ha ancora qualcosa da mettere a rate. Le voci esaurite, o già in un altro piano, `
            + 'sono spente nel menu «Voci di bilancio incluse».';
}
