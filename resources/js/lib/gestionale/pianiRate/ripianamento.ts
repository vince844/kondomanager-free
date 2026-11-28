// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 743 (decisioni 655 e 676 (1)): il debito ereditato da ripianare nel nuovo piano, come lo propone il server (`RipianamentoDelDeficit::proposta()`), in
 * centesimi. Il ripianamento si scrive solo quando si salva il piano: qui si decide che cosa la pagina mostra e che cosa manda. Il massimo è il minore fra lo
 * scoperto di oggi (i debiti ereditati meno i pregressi chiesti ai condòmini, letti ora) e la parte della pregressa ancora a carico delle passate gestioni:
 * oltre lo scoperto i condòmini pagherebbero due volte. In un esercizio che non è quello della pregressa la pagina si ferma e dice dove (`altrove`).
 */
import { centesimiDaImporto } from '@/lib/gestionale/pianiRate/totaliPerTipo';

export interface RipianamentoProposto {
    compito_id: number;
    fornitore: string;
    /** Il nome della voce nuova: «Ripianamento del debito verso Idraulica Bianchi». */
    voce: string;
    /** Lo scoperto di oggi: i debiti ereditati meno i pregressi chiesti ai condòmini e meno quanto è già a rate o coperto altrimenti. */
    scoperto: number;
    /** I debiti ereditati verso i fornitori dell'esercizio, i pregressi chiesti ai condòmini, quanto è già a rate o coperto altrimenti. */
    debiti?: number;
    pregressi?: number;
    coperto?: number;
    /** Più debiti ereditati: lo scoperto è uno per tutti, e quanto su ogni debito lo decide l'assemblea. */
    piu_debiti?: boolean;
    /** Quanto altri piani ne mettono già a rate. */
    ripianato?: number;
    /** Il minore fra lo scoperto e la parte della pregressa ancora a carico delle passate gestioni. */
    massimo: number;
    proposto: number;
    /** Che cosa fa il massimo: lo scoperto, o la parte della fattura se è minore. */
    limite?: 'scoperto' | 'fattura';
    /** La frase del massimo del server, con i numeri (`RipianamentoDelDeficit::fraseDelMassimo`). */
    frase_del_massimo?: string;
    gestione_id: number;
    gestione: string;
    /** Perché in questo esercizio il ripianamento non si prepara (l'esercizio della pregressa è un altro, o è chiuso); nullo se si prepara qui. */
    altrove?: string | null;
    fatture: { id: number; numero: string; rata_0: number }[];
    tabelle: { id: number; nome: string }[];
}

export interface RipianamentoDaInviare {
    compito_id: number;
    importo: string;
    tabella_id: number | null;
}

/** «€ 1.200,00»: il simbolo prima, la virgola per i decimali. */
export function euroDaCentesimi(centesimi: number): string {
    const [interi, decimali] = (Math.abs(centesimi) / 100).toFixed(2).split('.');
    return `${centesimi < 0 ? '−' : ''}€ ${interi.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimali}`;
}

/** Il riquadro si vede sulla scheda «Spese fuori preventivo» e sulla gestione della pregressa: su un'altra gestione il ripianamento non si fa. */
export function ripianamentoVisibile(r: RipianamentoProposto | null | undefined, tipo: string, gestioneId: number | null): boolean {
    return !!r && !r.altrove && tipo === 'straordinario' && gestioneId === r.gestione_id;
}

/** In un esercizio che non è quello della pregressa (o con quell'esercizio chiuso) la pagina si ferma e dice dove si prepara: la frase, o null. */
export function ripianamentoAltrove(r: RipianamentoProposto | null | undefined, tipo: string): string | null {
    return r && r.altrove && tipo === 'straordinario' ? r.altrove : null;
}

/** Il valore iniziale del campo della cifra, in notazione del campo («1200.00»). */
export function cifraIniziale(r: RipianamentoProposto): string {
    return (r.proposto / 100).toFixed(2);
}

/** La tabella proposta: solo se ce n'è una sola. Con più tabelle la sceglie l'amministratore. */
export function tabellaIniziale(r: RipianamentoProposto): number | null {
    return r.tabelle.length === 1 ? r.tabelle[0].id : null;
}

/** Le righe a schema del riquadro: che cosa c'è, come si legge lo scoperto di oggi, che cosa diventa, quando si scrive. */
export function righeDelRipianamento(r: RipianamentoProposto): string[] {
    const fatture = r.fatture.map((f) => `n. ${f.numero}`).join(', ');
    const righe = [
        `Debito ereditato verso ${r.fornitore}: lo scoperto di oggi è ${euroDaCentesimi(r.scoperto)}; al massimo ${euroDaCentesimi(r.massimo)} (fattura ${fatture}).`,
    ];
    if (r.debiti !== undefined && r.pregressi !== undefined) {
        const coperto = (r.coperto ?? 0) > 0 ? `, meno quanto è già a rate o coperto altrimenti (${euroDaCentesimi(r.coperto ?? 0)})` : '';
        righe.push(`Lo scoperto di oggi: ${r.piu_debiti ? 'i debiti ereditati' : 'il debito'} (${euroDaCentesimi(r.debiti)}) meno i pregressi chiesti ai condòmini `
            + `(${euroDaCentesimi(r.pregressi)})${coperto}. Il programma non vede il denaro ereditato in banca: se c'è, lo scoperto vero è minore.`);
    }
    if (r.piu_debiti) {
        righe.push('Con più debiti ereditati il programma non sa quale coprono i pregressi: quanto mettere a rate su ogni debito lo decide l\'assemblea.');
    }
    if ((r.ripianato ?? 0) > 0) {
        righe.push(`Altri piani ne mettono già a rate ${euroDaCentesimi(r.ripianato ?? 0)}: lo scoperto è quello che resta.`);
    }
    if (r.scoperto > r.massimo) {
        righe.push(`Lo scoperto supera la fattura ancora a carico delle passate gestioni: si mette a rate al massimo ${euroDaCentesimi(r.massimo)}.`);
    }
    righe.push(
        `Con il piano la cifra diventa una spesa dell'esercizio sulla voce «${r.voce}», a carico dei proprietari.`,
        'Si scrive solo quando salvi il piano. Se elimini il piano, si toglie.',
    );

    return righe;
}

/** Una cifra fuori dai limiti, con la frase; null se va bene. */
export function cifraFuoriDaiLimiti(r: RipianamentoProposto, importo: string | number | null | undefined): string | null {
    const centesimi = centesimiDaImporto(importo);
    if (centesimi <= 0) return 'Scrivi quanto del debito ereditato mettere a rate.';
    if (centesimi > r.massimo) {
        // La frase del server, con i numeri di oggi; senza, la frase corta.
        if (r.frase_del_massimo) return r.frase_del_massimo;
        const limite = r.limite ?? (r.massimo < r.scoperto ? 'fattura' : 'scoperto');
        return limite === 'fattura'
            ? `Al massimo ${euroDaCentesimi(r.massimo)}: è la parte della fattura pregressa ancora a carico delle passate gestioni.`
            : `Al massimo ${euroDaCentesimi(r.massimo)}: è lo scoperto di oggi, la parte dei debiti ereditati che i pregressi chiesti ai condòmini non coprono.`;
    }

    return null;
}

/** Il dato che viaggia con il piano; null se il riquadro non si vede o l'amministratore l'ha tolto. */
export function ripianamentoDaInviare(r: RipianamentoProposto | null | undefined, visibile: boolean, attivo: boolean, importo: string, tabellaId: number | null): RipianamentoDaInviare | null {
    if (!r || r.altrove || !visibile || !attivo) return null;

    return { compito_id: r.compito_id, importo, tabella_id: tabellaId };
}
