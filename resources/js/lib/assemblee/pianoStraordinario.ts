// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Crea il piano straordinario» (decisione 622 (1)): le frasi della finestra. L'importo scritto si legge con la regola delle pagine dei piani
 * (`centesimiDaImporto`) solo per dirlo nel riepilogo: la conversione che conta è quella del server, una volta sola, al confine.
 */
import { euro } from '@/lib/assemblee/assemblee';
import { centesimiDaImporto } from '@/lib/gestionale/pianiRate/totaliPerTipo';

/** La proposta della finestra, come la manda il server (`PianoStraordinario::proposta()`). */
export interface PropostaDelPianoStraordinario {
    cosa: { id: number; testo: string; da_fare: boolean };
    /** «Che cosa ha cambiato l'assemblea», con l'esito «approvata con modifiche»: allora gli importi sono null, e l'importo si scrive. */
    modifiche: string | null;
    delibera: { id: number; frase: string; importo: number | null };
    gestione_esistente: { id: number; nome: string; url: string } | null;
    gestione: { nome: string; esercizio_id: number | null; data_inizio: string | null; data_fine: string | null };
    esercizi: { id: number; nome: string; data_inizio: string | null; data_fine: string | null }[];
    voce: { nome: string; importo: number | null; tabella_id: number | null; fornitore_id: number | null };
    tabelle: { id: number; nome: string }[];
    fornitori: { id: number; nome: string }[];
    a_carico: string;
}

/**
 * Il riepilogo del piede: «Nascono la gestione, il suo piano dei conti e 1 voce da € 14.500,00.», con la differenza dalla delibera quando l'importo
 * scritto è un altro; con la gestione già legata, «Si apre il piano della gestione «…»».
 */
export function riepilogoDelPianoStraordinario(importoScritto: string | number | null | undefined, deliberati: number, esistente: string | null): string {
    if (esistente !== null) {
        return `Si apre il piano della gestione «${esistente}», già legata alla delibera: niente nasce di nuovo.`;
    }
    const scritto = centesimiDaImporto(importoScritto);
    if (scritto <= 0) {
        return 'Scrivi l\'importo della voce.';
    }
    const base = `Nascono la gestione, il suo piano dei conti e 1 voce da ${euro(scritto)}.`;
    if (deliberati > 0 && scritto !== deliberati) {
        const differenza = scritto - deliberati;
        return `${base} ${euro(Math.abs(differenza))} ${differenza > 0 ? 'in più' : 'in meno'} della delibera (${euro(deliberati)}).`;
    }
    return base;
}

/** Il nome della gestione con l'anno dell'esercizio scelto: «Portone 2026» diventa «Portone 2027»; un nome scritto a mano senza l'anno resta com'è. */
export function nomeConLAnno(nome: string, annoPrima: string | null, annoDopo: string | null): string {
    if (!annoPrima || !annoDopo || annoPrima === annoDopo) return nome;
    return nome.endsWith(` ${annoPrima}`) ? `${nome.slice(0, -annoPrima.length)}${annoDopo}` : nome;
}

/** Il nome del piano che si apre «Preparato dalla delibera»: se è vuoto prende il nome della gestione (che porta già il nome del punto e l'anno), se è già scritto resta com'è. */
export function nomeDelPianoPreparato(nomeAttuale: string, nomeDellaGestione: string | null | undefined): string {
    return nomeAttuale.trim() !== '' ? nomeAttuale : (nomeDellaGestione ?? '');
}
